<?php
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;

$host_rmq = 'qw.gkaizen.com.br';
$port_rmq = 5672;
$user_rmq = 'gkaizen';
$password_rmq = 'KTmfVZWGdUNJLuiGdijy';
$vhost_rmq = 'homolog';

// Conexão com RabbitMQ
try {
    $connection = new AMQPStreamConnection($host_rmq, $port_rmq, $user_rmq, $password_rmq, $vhost_rmq);
    $channel = $connection->channel();
    $secondConnection = new AMQPStreamConnection($host_rmq, $port_rmq, $user_rmq, $password_rmq);
    $secondChannel = $secondConnection->channel();

    echo "Conectado ao RabbitMQ com sucesso!\n";
} catch (Exception $e) {
    echo "Erro na conexão com RabbitMQ: " . $e->getMessage() . "\n";
    exit;
}
?>