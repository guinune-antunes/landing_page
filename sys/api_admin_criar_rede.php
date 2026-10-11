<?php
// Admin SaaS: agrupa uma loja já existente em uma NOVA rede (ela vira a matriz).
// Usado quando um cliente que começou com 1 loja abre uma segunda unidade.
session_start();
header('Content-Type: application/json');
require 'conexao.php';

if (($_SESSION['usuario_cargo'] ?? '') !== 'Admin') {
    echo json_encode(['success' => false, 'message' => 'Sem permissão.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$id   = (int)($data['id'] ?? 0);
$nome = trim($data['nome_rede'] ?? '');

try {
    $st = $pdo->prepare("SELECT nome_fantasia, rede_id FROM instituicoes WHERE id = ?");
    $st->execute([$id]);
    $inst = $st->fetch();

    if (!$inst) {
        echo json_encode(['success' => false, 'message' => 'Instituição não encontrada.']);
        exit;
    }
    if (!empty($inst['rede_id'])) {
        echo json_encode(['success' => false, 'message' => 'Esta instituição já pertence a uma rede.']);
        exit;
    }

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO redes (nome) VALUES (?)")->execute([$nome !== '' ? $nome : $inst['nome_fantasia']]);
    $redeId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE instituicoes SET rede_id = ?, matriz = 1 WHERE id = ?")->execute([$redeId, $id]);
    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Rede criada. Agora você pode adicionar novas unidades a ela.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Erro do Banco: ' . $e->getMessage()]);
}
