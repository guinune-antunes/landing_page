<?php
require 'c:/xampp/htdocs/landing_page/sys/conexao.php';

$st = $pdo->query('SELECT id, nome, email, cargo, instituicao_id, precisa_mudar_senha FROM usuarios');
$users = $st->fetchAll(PDO::FETCH_ASSOC);

echo "=== USUARIOS CADASTRADOS ===\n";
foreach ($users as $u) {
    echo "ID: {$u['id']} | Nome: {$u['nome']} | Cargo: {$u['cargo']} | Inst: {$u['instituicao_id']} | Email: {$u['email']} | PrecisaMudar: {$u['precisa_mudar_senha']}\n";
}
