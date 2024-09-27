<?php
require_once 'connection.php';       // Conexão com o banco de dados
require_once 'rabbitmq_connection.php'; // Conexão com o RabbitMQ

$queue_rmq = 'gkaizen.send.message';

echo " [*] Aguardando mensagens da fila '$queue_rmq'. Para sair pressione CTRL+C\n";

$callback2 = function ($msg) use ($pdo) {
    $data = json_decode($msg->body, true);
    $processadoId = $data['data']['key']['id'] ?? null;

    if (!$processadoId) {
        echo "Erro: ID não encontrado na mensagem.\n";
        $msg->ack();
        return;
    }

    try {
        // Buscar o id_instancia na tabela processadofila_pf
        $queryProcessadoFila = 'SELECT instancia_id, clu_id FROM "processadofila" WHERE processado_id = :processado_id';
        $stmtProcessadoFila = $pdo->prepare($queryProcessadoFila);
        $stmtProcessadoFila->bindParam(':processado_id', $processadoId);

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
            echo "Erro: ID {$processadoId} não encontrado na tabela processadofila após várias tentativas.\n";
            $msg->ack();
            return;
        }

        // Extrai o id_instancia da tabela processadofila
        $idInstancia = $result['instancia_id'];
        $cluId = $result['clu_id'];

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

        // Verifica se o evento já foi inserido
        $checkQuery = 'SELECT COUNT(*) FROM "processadofila_pf" WHERE processado_id = :processadoId';
        $checkStmt = $pdoInstancia->prepare($checkQuery);
        $checkStmt->bindParam(':processadoId', $processadoId);
        $tentativas = 5;
        $encontrado = false;
        while ($tentativas > 0) {
            $checkStmt->execute();
            $count = $checkStmt->fetchColumn();

            if ($count > 0) {
                $encontrado = true;
                break;
            }

            // Espera 1 segundo antes de tentar de novo
            sleep(1);
            $tentativas--;
        }

        if (!$encontrado) {
            echo "ID $processadoId não encontrado na tabela processadofila da instância após várias tentativas. Nenhuma inserção realizada.\n";
            $msg->ack();
            return;
        }


            // Extrai os campos 'event' e 'date_time' da mensagem
            $event = isset($data['event']) ? 'MENSAGEM ENVIADA' : null;
            $date_time = $data['date_time'] ?? null;

            if (!$event || !$date_time) {
                echo "Erro: Dados incompletos na mensagem.\n";
                $msg->ack();
                return;
            }

            // Inserir os dados na tabela eventos_fila da instância
            $insertQuery = 'INSERT INTO "eventos_fila" (processado_id, evento, date_time, clu_id) VALUES (:processado_id, :evento, :date_time, :clu_id)';
            $stmtInsert = $pdoInstancia->prepare($insertQuery);                    
            $stmtInsert->bindParam(':processado_id', $processadoId);
            $stmtInsert->bindParam(':evento', $event);
            $stmtInsert->bindParam(':date_time', $date_time);
            $stmtInsert->bindParam(':clu_id', $cluId);

            if ($stmtInsert->execute()) {
                echo "Dados salvos no banco da instância com sucesso.\n";
            } else {
                echo "Erro ao salvar dados no banco da instância.\n";
                $errorInfo = $stmtInsert->errorInfo();
                echo "Detalhes do erro SQL: " . print_r($errorInfo, true) . "\n";
            }

    } catch (PDOException $e) {
        echo "Erro no banco de dados: " . $e->getMessage() . "\n";
    }

    // Confirma o processamento da mensagem
    $msg->ack();
};

$secondChannel->basic_qos(null, 1, null); // Processa uma mensagem por vez
$secondChannel->basic_consume($queue_rmq, '', false, false, false, false, $callback2);

while ($secondChannel->is_open()) {
    $secondChannel->wait();
}

$secondChannel->close();
$secondConnection->close();
