<?php
require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// Carregar as variáveis de ambiente do arquivo .env
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Obter as variáveis de ambiente
$host_db = $_ENV['DB_HOST'];
$db = $_ENV['DB_NAME'];
$user_db = $_ENV['DB_USER'];
$pass_db = $_ENV['DB_PASS'];
$charset = $_ENV['DB_CHARSET'];

$dsn = "pgsql:host=$host_db;dbname=$db;options='--client_encoding=$charset'";


try {
    $pdo = new PDO($dsn, $user_db, $pass_db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Conexão com o banco de dados bem-sucedida!\n";
} catch (PDOException $e) {
    echo "Erro na conexão com o banco de dados: " . $e->getMessage() . "\n";
    exit;
}