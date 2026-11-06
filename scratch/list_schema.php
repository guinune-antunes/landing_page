<?php
require_once __DIR__ . '/../sys/conexao.php';
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "=== TABELAS NO BANCO ===\n";
foreach ($tables as $t) {
    echo "- $t\n";
}

