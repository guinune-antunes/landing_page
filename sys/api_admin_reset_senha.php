<?php
session_start();
header('Content-Type: application/json');
require 'conexao.php';

// Segurança Máxima
if (!isset($_SESSION['usuario_cargo']) || $_SESSION['usuario_cargo'] !== 'Admin') {
    echo json_encode(['success' => false, 'message' => 'Acesso negado.']); exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$usuario_id = $data['id'] ?? null;

if (!$usuario_id) {
    echo json_encode(['success' => false, 'message' => 'ID de usuário inválido.']); exit;
}

try {
    $senhaTemporaria = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
    $senhaHash = password_hash($senhaTemporaria, PASSWORD_DEFAULT);

    // Atualiza a senha e OBRIGA a troca no próximo login
    $sql = "UPDATE usuarios SET senha_hash = ?, precisa_mudar_senha = 1 WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$senhaHash, $usuario_id]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'senha_temp' => $senhaTemporaria]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Falha ao atualizar usuário.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro interno no servidor.']);
}
?>