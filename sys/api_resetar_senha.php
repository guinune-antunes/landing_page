<?php
session_start();
header('Content-Type: application/json');
require 'conexao.php';

if (!isset($_SESSION['usuario_cargo']) || $_SESSION['usuario_cargo'] !== 'Gerente') {
    echo json_encode(['success' => false, 'message' => 'Sem permissão.']); exit;
}

$id_loja = $_SESSION['instituicao_id'] ?? null;
$data = json_decode(file_get_contents('php://input'), true);
$usuario_id = $data['id'] ?? null;

if (!$id_loja || !$usuario_id) {
    echo json_encode(['success' => false, 'message' => 'Dados inválidos.']); exit;
}

try {
    // 1. Gera uma senha aleatória de 8 caracteres (letras e números)
    $senhaTemporaria = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
    $senhaHash = password_hash($senhaTemporaria, PASSWORD_DEFAULT);

    // 2. Atualiza o banco: Salva a nova senha e ATIVA O ALERTA (precisa_mudar_senha = 1)
    $sql = "UPDATE usuarios SET senha_hash = ?, precisa_mudar_senha = 1 WHERE id = ? AND instituicao_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$senhaHash, $usuario_id, $id_loja]);

    if ($stmt->rowCount() > 0) {
        echo json_encode([
            'success' => true, 
            'senha_temp' => $senhaTemporaria,
            'message' => 'Senha resetada! Copie a senha provisória.'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Usuário não encontrado nesta loja.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro interno do servidor.']);
}
?>