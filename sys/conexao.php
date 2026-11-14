<?php
date_default_timezone_set('America/Sao_Paulo');

// Configurações do Banco
// Suporte a Docker / Produção via variáveis de ambiente com fallback para XAMPP local
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'petcrm');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_PORT', getenv('DB_PORT') ?: '3306');

// Configurações de charset e DSN
$charset = 'utf8mb4';
$dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // Se der erro, para tudo e mostra mensagem limpa
    // (Em produção, não mostraria o getMessage() para o usuário)
    die("ERRO CRÍTICO DE CONEXÃO: " . $e->getMessage());
}

// IMPORTANTE: Não feche a tag PHP aqui.
// Deixar o arquivo "aberto" evita envio acidental de espaços em branco.