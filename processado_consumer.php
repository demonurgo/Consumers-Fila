<?php
require_once 'connection.php';       // Conexão com o banco de dados
require_once 'rabbitmq_connection.php';
$queue_rmq = 'processado';

echo " [*] Aguardando mensagens da fila '$queue_rmq'. Para sair pressione CTRL+C\n";

$callback = function ($msg) use ($pdo) {
    // Verifica se a conexão PDO do banco de licenças está disponível
    if (!$pdo) {
        echo "Erro: Conexão com o banco de licenças não disponível.\n";
        return;
    }

    // Decodifica a mensagem JSON
    $data = json_decode($msg->body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "Erro ao decodificar a mensagem JSON: " . json_last_error_msg() . "\n";
        $msg->ack();
        return;
    }

    // Extrai os campos necessários da mensagem
    $remoteJid = preg_replace('/\D/', '', $data['remoteJid']); 
    $instanciaId = trim($data['id_instancia']); // Remove caracteres não numéricos
    $processadoId = $data['id'] ?? null;
    $clu_id = trim($data['clu_id']);  // Remove espaços e quebras de linha do clu_id

    $contactMessage = isset($data['contactMessage']) ? true : false;

    if (!$processadoId || !$remoteJid || !$clu_id) {
        echo "Erro: Campos obrigatórios ausentes na mensagem.\n";
        $msg->ack();
        return;
    }

    try {
        // Inserir os dados no banco de licenças na tabela "processadofila_pf"
        $queryInsert = 'INSERT INTO "processadofila" ("processado_id", "remoteJid", "clu_id", "instancia_id", "contactMessage") 
                        VALUES (:processado_id, :remoteJid, :clu_id, :instancia_id, :contactMessage)';
        $stmtInsert = $pdo->prepare($queryInsert);
        $stmtInsert->bindParam(':processado_id', $processadoId);
        $stmtInsert->bindParam(':remoteJid', $remoteJid);
        $stmtInsert->bindParam(':clu_id', $clu_id);
        $stmtInsert->bindParam(':instancia_id', $instanciaId);
        $stmtInsert->bindParam(':contactMessage', $contactMessage, PDO::PARAM_BOOL);

        if ($stmtInsert->execute()) {
            echo "Dados salvos no banco de licenças com sucesso.\n";
        } else {
            echo "Erro ao salvar dados no banco de licenças.\n";
            $errorInfo = $stmtInsert->errorInfo();
            echo "Detalhes do erro SQL: " . print_r($errorInfo, true) . "\n";
            return;
        }

        // Buscar as credenciais da instância no banco de licenças
        $queryInstancia = 'SELECT databasename, databaselogin, databasepassword FROM licencas WHERE id = :idInstancia';
        $stmtInstancia = $pdo->prepare($queryInstancia);
        $stmtInstancia->bindParam(':idInstancia', $instanciaId);
        $stmtInstancia->execute();
        $instancia = $stmtInstancia->fetch(PDO::FETCH_ASSOC);

        if (!$instancia) {
            echo "Erro: Instância com ID {$instanciaId} não encontrada no banco de licenças.\n";
            $msg->ack();
            return;
        }

        // Conectar ao banco da instância
        $dsnInstancia = "pgsql:host=postgres;port=5432;dbname={$instancia['databasename']}";
        $pdoInstancia = new PDO($dsnInstancia, $instancia['databaselogin'], $instancia['databasepassword']);
        $pdoInstancia->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Inserir os dados na tabela processadofila_pf do banco da instância
        $queryInsertInstancia = 'INSERT INTO "processadofila_pf" ("processado_id", "remoteJid", "clu_id", "instancia_id", "contactMessage") 
                                 VALUES (:processado_id, :remoteJid, :clu_id, :instancia_id, :contactMessage)';
        $stmtInsertInstancia = $pdoInstancia->prepare($queryInsertInstancia);
        $stmtInsertInstancia->bindParam(':processado_id', $processadoId);
        $stmtInsertInstancia->bindParam(':remoteJid', $remoteJid);
        $stmtInsertInstancia->bindParam(':clu_id', $clu_id);
        $stmtInsertInstancia->bindParam(':instancia_id', $instanciaId);
        $stmtInsertInstancia->bindParam(':contactMessage', $contactMessage, PDO::PARAM_BOOL);

        if ($stmtInsertInstancia->execute()) {
            echo "Dados salvos no banco da instância com sucesso.\n";
        } else {
            echo "Erro ao salvar dados no banco da instância.\n";
            $errorInfo = $stmtInsertInstancia->errorInfo();
            echo "Detalhes do erro SQL: " . print_r($errorInfo, true) . "\n";
        }

    } catch (PDOException $e) {
        echo "Erro no banco de dados: " . $e->getMessage() . "\n";
    }

    // Confirma o processamento da mensagem
    $msg->ack();
};

$channel->basic_qos(null, 1, null); // Processa uma mensagem por vez
$channel->basic_consume($queue_rmq, '', false, false, false, false, $callback);


while ($channel->is_open()) {
    $channel->wait();
}

$channel->close();
$connection->close();
