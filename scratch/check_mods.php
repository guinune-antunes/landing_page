<?php
require_once __DIR__ . '/../sys/conexao.php';
$st = $pdo->query("SELECT id, nome_unidade, modulos_contratados FROM instituicoes WHERE id IN (1, 2, 244, 245)");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "ID: {$row['id']} - {$row['nome_unidade']} | Modulos: {$row['modulos_contratados']}\n";
}

