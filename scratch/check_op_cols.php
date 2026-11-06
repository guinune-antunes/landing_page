<?php
require_once __DIR__ . '/../sys/conexao.php';
$st = $pdo->query("DESCRIBE oportunidades_venda");
print_r($st->fetchAll(PDO::FETCH_ASSOC));

