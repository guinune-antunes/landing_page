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
    // Atualiza para concluída E data de conclusão
    // O WHERE usuario_id garante que um atendente não feche a tarefa do outro
    $sql = "UPDATE tarefas SET status = 'concluida', data_conclusao = NOW() WHERE id = ? AND usuario_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tarefa_id, $usuario_id]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>