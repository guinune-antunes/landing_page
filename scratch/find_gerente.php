<?php
require_once __DIR__ . '/../sys/conexao.php';

$st = $pdo->query("SELECT u.id, u.nome, u.email, u.cargo, u.instituicao_id, i.nome_fantasia, i.nome_unidade 
                   FROM usuarios u 
                   LEFT JOIN instituicoes i ON i.id = u.instituicao_id 
                   WHERE u.nome LIKE '%Gerente%' OR u.nome LIKE '%Teste%'");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
    echo "ID: {$u['id']} | Nome: {$u['nome']} ({$u['email']}) | Cargo: {$u['cargo']} | Inst ID: {$u['instituicao_id']} ({$u['nome_fantasia']} - {$u['nome_unidade']})\n";
}

