<?php
require_once 'connection.php';       // Conexão com o banco de dados
require_once 'rabbitmq_connection.php'; // Conexão com o RabbitMQ

$queue_rmq = 'gkaizen.messages.upsert';

echo " [*] Aguardando mensagens da fila '$queue_rmq'. Para sair pressione CTRL+C\n";

$callbackUpdate = function ($msg) use ($pdo) {
    $data = json_decode($msg->body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "Erro ao decodificar a mensagem JSON: " . json_last_error_msg() . "\n";
        $msg->ack();
        return;
    }

    // Extrai os campos necessários da mensagem
    $event = $data['event'] ?? null;
    $remoteJidRaw = $data['data']['key']['remoteJid'] ?? null;
    $conversation = $data['data']['message']['conversation'] ?? null;
    $processadoId = $data['data']['key']['id'] ?? null;
    $senderTimestamp = $data['data']['message']['messageContextInfo']['deviceListMetadata']['senderTimestamp'] ?? null;
    $selectedDisplayText = $data['data']['message']['templateButtonReplyMessage']['selectedDisplayText'] ?? null;

    if (!$event || !$remoteJidRaw || !$senderTimestamp || (!$conversation && !$selectedDisplayText)) {
        echo "Erro: Campos obrigatórios ausentes na mensagem.\n";
        $msg->ack();
        return;
    }

    // Remove caracteres não numéricos do remoteJid
    $remoteJid = preg_replace('/\D/', '', $remoteJidRaw);

    try {
        // Buscar o id_instancia na tabela processadofila usando remoteJid, ordenado por inserted_at
        $queryProcessadoFila = 'SELECT instancia_id, clu_id, "contactMessage" FROM "processadofila" WHERE "remoteJid" = :remoteJid ORDER BY inserted_at DESC LIMIT 1';
        $stmtProcessadoFila = $pdo->prepare($queryProcessadoFila);
        $stmtProcessadoFila->bindParam(':remoteJid', $remoteJid);

        $tentativas = 5;
        $encontrado = false;
        while ($tentativas > 0) {
            $stmtProcessadoFila->execute();
            $result = $stmtProcessadoFila->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                $encontrado = true;
                break;
            }

            // Espera 1 segundo antes de tentar de novo
            sleep(1);
            $tentativas--;
        }

        if (!$encontrado) {
            echo "Erro: remoteJid {$remoteJid} não encontrado na tabela processadofila após várias tentativas.\n";
            $msg->ack();
            return;
        }

        // Extrai os dados da tabela processadofila
        $idInstancia = $result['instancia_id'];
        $cluId = $result['clu_id'];
        $contactMessage = $result['contactMessage'];

        // Converte o valor de contactMessage para booleano, se necessário
        $contactMessage = filter_var($contactMessage, FILTER_VALIDATE_BOOLEAN);

        // Mapear o status baseado em contactMessage
        if ($event === 'messages.upsert') {
            if ($selectedDisplayText) {
                    $status = "Botão '{$selectedDisplayText}' clicado";

        

            } else {

                $status = 'Resposta: ' . $conversation;
            }

            } else{
            echo "Evento não é 'messages.upsert'. Nenhuma ação será realizada para esta mensagem.\n";
            $msg->ack(); // Confirma o processamento da mensagem
            return;
        }

        // Buscar as credenciais da instância no banco de licenças
        $queryInstancia = 'SELECT databasename, databaselogin, databasepassword FROM licencas WHERE id = :idInstancia';
        $stmtInstancia = $pdo->prepare($queryInstancia);
        $stmtInstancia->bindParam(':idInstancia', $idInstancia);
        $stmtInstancia->execute();
        $instancia = $stmtInstancia->fetch(PDO::FETCH_ASSOC);

        if (!$instancia) {
            echo "Erro: Instância com ID {$idInstancia} não encontrada no banco de licenças.\n";
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
        $stmtInsertInstancia->bindParam(':clu_id', $cluId); // Corrigido aqui
        $stmtInsertInstancia->bindParam(':instancia_id', $idInstancia); // Corrigido aqui
        $stmtInsertInstancia->bindParam(':contactMessage', $contactMessage, PDO::PARAM_BOOL);

        // **Executar a inserção na tabela processadofila_pf**
        if ($stmtInsertInstancia->execute()) {
            echo "Dados inseridos na tabela processadofila_pf com sucesso.\n";
        } else {
            echo "Erro ao inserir dados na tabela processadofila_pf.\n";
            $errorInfo = $stmtInsertInstancia->errorInfo();
            echo "Detalhes do erro SQL: " . print_r($errorInfo, true) . "\n";
            $msg->ack();
            return;
        }


        // Inserir os dados na tabela eventos_fila da instância
        $insertQuery = 'INSERT INTO "eventos_fila" (processado_id, evento, date_time, clu_id) VALUES (:processado_id, :evento, TO_TIMESTAMP(:date_time), :clu_id)';
        $stmtInsert = $pdoInstancia->prepare($insertQuery);
        $stmtInsert->bindParam(':processado_id', $processadoId);
        $stmtInsert->bindParam(':evento', $status);
        $stmtInsert->bindParam(':date_time', $senderTimestamp);
        $stmtInsert->bindParam(':clu_id', $cluId);

        if ($stmtInsert->execute()) {
            echo "Dados salvos no banco da instância com sucesso.\n";
        } else {
            echo "Erro ao salvar dados no banco da instância.\n";
            $errorInfo = $stmtInsert->errorInfo();
            echo "Detalhes do erro SQL: " . print_r($errorInfo, true) . "\n";
        }

        // **Atualizar a coluna clu_telefoneverificado se a mensagem for "Contato salvo"**
        if (strtolower(trim($conversation)) === 'contato salvo') {
            $updateQuery = 'UPDATE "clienteusuario_clu" SET "clu_telefoneverificado" = 1 WHERE "clu_id" = :clu_id';
            $stmtUpdate = $pdoInstancia->prepare($updateQuery);
            $stmtUpdate->bindParam(':clu_id', $cluId);

            if ($stmtUpdate->execute()) {
                echo "Atualizado clu_telefoneverificado para 1 no clu_usuario com sucesso.\n";
            } else {
                echo "Erro ao atualizar clu_telefoneverificado no clu_usuario.\n";
                $errorInfo = $stmtUpdate->errorInfo();
                echo "Detalhes do erro SQL: " . print_r($errorInfo, true) . "\n";
            }
        }


    } catch (PDOException $e) {
        echo "Erro no banco de dados: " . $e->getMessage() . "\n";
    }

    // Confirma o processamento da mensagem
    $msg->ack();
};

$secondChannel->basic_qos(null, 1, null); // Processa uma mensagem por vez
$secondChannel->basic_consume($queue_rmq, '', false, false, false, false, $callbackUpdate);

while ($secondChannel->is_open()) {
    $secondChannel->wait();
}

$secondChannel->close();
$secondConnection->close();
