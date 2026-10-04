<?php
session_start();
header('Content-Type: application/json');
require 'conexao.php';

// PROTEÇÃO: Apenas o Administrador do Sistema (SaaS) pode bloquear/desbloquear lojas
if (!isset($_SESSION['usuario_cargo']) || $_SESSION['usuario_cargo'] !== 'Admin') {
    echo json_encode(['success' => false, 'message' => 'Sem permissão.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$id = (int)($data['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID da instituição inválido.']);
    exit;
}

try {
    // Inverte o status atual (1 -> 0 / 0 -> 1)
    $stmt = $pdo->prepare("UPDATE instituicoes SET ativo = 1 - ativo WHERE id = ?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Instituição não encontrada.']);
        exit;
    }

    $novoStatus = (int)$pdo->query("SELECT ativo FROM instituicoes WHERE id = " . $id)->fetchColumn();

    echo json_encode([
        'success' => true,
        'ativo'   => $novoStatus,
        'message' => $novoStatus ? 'Instituição desbloqueada com sucesso.' : 'Instituição bloqueada. Os usuários dela não conseguirão mais fazer login.'
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erro ao alterar status da instituição.']);
}
