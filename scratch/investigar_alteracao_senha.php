<?php
require 'c:/xampp/htdocs/landing_page/sys/conexao.php';

echo "=== INVESTIGANDO ALTERAÇÃO DE SENHA ===\n\n";

// 1. Verificar ENUM da coluna cargo
$st = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'cargo'");
$col = $st->fetch(PDO::FETCH_ASSOC);
echo "Tipo da coluna cargo: " . $col['Type'] . "\n\n";

// 2. Verificar usuários existentes
$stUsers = $pdo->query("SELECT id, nome, email, cargo, instituicao_id, precisa_mudar_senha FROM usuarios LIMIT 10");
$users = $stUsers->fetchAll(PDO::FETCH_ASSOC);
echo "Usuários encontrados:\n";
foreach ($users as $u) {
    echo "ID: {$u['id']} | Nome: {$u['nome']} | Cargo: {$u['cargo']} | Inst: {$u['instituicao_id']} | PrecisaMudar: {$u['precisa_mudar_senha']}\n";
}

// 3. Testar update com 'gerente' minúsculo vs 'Gerente' maiúsculo
$pdo->beginTransaction();
try {
    $testeId = $users[0]['id'] ?? 1;
    $cargoOriginal = $users[0]['cargo'] ?? 'Gerente';

    echo "\nTentando UPDATE com cargo='gerente' (minúsculo)... ";
    try {
        $up = $pdo->prepare("UPDATE usuarios SET cargo = 'gerente' WHERE id = ?");
        $up->execute([$testeId]);
        echo "Sucesso!\n";
    } catch (Exception $e) {
        echo "ERRO: " . $e->getMessage() . "\n";
    }

    echo "Tentando UPDATE com cargo='banho_tosa' ... ";
    try {
        $up = $pdo->prepare("UPDATE usuarios SET cargo = 'banho_tosa' WHERE id = ?");
        $up->execute([$testeId]);
        echo "Sucesso!\n";
    } catch (Exception $e) {
        echo "ERRO: " . $e->getMessage() . "\n";
    }
} finally {
    $pdo->rollBack();
}
