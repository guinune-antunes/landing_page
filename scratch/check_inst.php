<?php
require_once __DIR__ . '/../sys/conexao.php';

echo "=== INSTITUIÇÕES ===\n";
$st = $pdo->query("SELECT id, nome_fantasia, nome_unidade, rede_id FROM instituicoes");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $inst) {
    echo "ID: {$inst['id']} - {$inst['nome_fantasia']} ({$inst['nome_unidade']}) | Rede: {$inst['rede_id']}\n";
}

echo "\n=== USUÁRIOS ===\n";
$stU = $pdo->query("SELECT id, instituicao_id, nome, email, cargo FROM usuarios LIMIT 10");
foreach ($stU->fetchAll(PDO::FETCH_ASSOC) as $u) {
    echo "ID: {$u['id']} - {$u['nome']} ({$u['email']}) | Cargo: {$u['cargo']} | Inst: {$u['instituicao_id']}\n";
}

