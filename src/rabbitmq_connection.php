<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use PhpAmqpLib\Connection\AMQPStreamConnection;

// Carregar as variáveis de ambiente do arquivo .env
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Obter as variáveis de ambiente
$host_rmq = $_ENV['RABBITMQ_HOST'];
$port_rmq = $_ENV['RABBITMQ_PORT'];
$user_rmq = $_ENV['RABBITMQ_USER'];
$password_rmq = $_ENV['RABBITMQ_PASSWORD'];
$vhost_rmq = $_ENV['RABBITMQ_VHOST'];

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