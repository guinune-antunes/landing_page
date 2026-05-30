<?php
session_start();
header('Content-Type: application/json');
require 'conexao.php';

$data = json_decode(file_get_contents('php://input'), true);
$id_tarefa = $data['id'] ?? null;
$observacao = $data['observacao'] ?? '';
$id_usuario = $_SESSION['usuario_id'] ?? null;
$id_loja = $_SESSION['instituicao_id'] ?? null;

if (!$id_tarefa || !$id_usuario) {
    echo json_encode(['success' => false, 'message' => 'Dados inválidos.']); exit;
}

try {
    $agora = date('Y-m-d H:i:s');
    $sql = "UPDATE tarefas_estoque SET status = 'Resolvido', resolvido_em = ?, resolvido_por = ?, observacao_resolucao = ? WHERE id = ? AND instituicao_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$agora, $id_usuario, $observacao, $id_tarefa, $id_loja]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro interno no servidor.']);
}
?>