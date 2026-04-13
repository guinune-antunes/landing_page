<?php
// Configurações do Banco
// Certifique-se de que não há espaços antes da tag <?php acima!
define('DB_HOST', 'localhost');
define('DB_NAME', 'petcrm');
define('DB_USER', 'root');
define('DB_PASS', '');

// Configurações de charset e DSN
$charset = 'utf8mb4';
$dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=$charset";

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