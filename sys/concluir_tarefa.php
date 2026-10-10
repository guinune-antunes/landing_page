<?php
session_start();
require 'conexao.php';
header('Content-Type: application/json');

// Segurança: Só aceita se estiver logado
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$tarefa_id = $data['id'];
$usuario_id = $_SESSION['usuario_id'];

try {
    // Atualiza para concluída (Resolvido)
    $sql = "UPDATE tarefas_estoque SET status = 'Resolvido', resolvido_em = NOW(), resolvido_por = ? WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$usuario_id, $tarefa_id]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erro interno no servidor.']);
}
?>