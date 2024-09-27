<?php
$host_db = 'postgres'; // Nome do serviço no docker-compose.yml
$db   = 'dbgestorkaizenlicencas';
$user_db = 'kaisen';
$pass_db = 'Gnr83Sbv2SdM';
$charset = 'utf8';

$dsn = "pgsql:host=$host_db;dbname=$db;options='--client_encoding=$charset'";


try {
    $pdo = new PDO($dsn, $user_db, $pass_db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Conexão com o banco de dados bem-sucedida!\n";
} catch (PDOException $e) {
    echo "Erro na conexão com o banco de dados: " . $e->getMessage() . "\n";
    exit;
}