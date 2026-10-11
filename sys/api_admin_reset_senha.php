<?php
session_start();
header('Content-Type: application/json');
require 'conexao.php';

// Segurança Máxima: Apenas Super Admin do SaaS
if (!isset($_SESSION['usuario_cargo']) || $_SESSION['usuario_cargo'] !== 'Admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acesso negado. Apenas Administrador.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$usuario_id = intval($data['id'] ?? 0);
$nova_senha = trim((string)($data['nova_senha'] ?? $data['senha'] ?? ''));
$forcar_troca = isset($data['forcar_troca']) ? (int)$data['forcar_troca'] : (empty($nova_senha) ? 1 : 0);

if ($usuario_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID de usuário inválido.']);
    exit;
}

try {
    // 1. Verifica se o usuário alvo existe
    $stCheck = $pdo->prepare("SELECT id, nome, email, cargo, instituicao_id FROM usuarios WHERE id = ?");
    $stCheck->execute([$usuario_id]);
    $usuario = $stCheck->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        echo json_encode(['success' => false, 'message' => 'Usuário não encontrado no sistema.']);
        exit;
    }

    // 2. Define a senha (manual ou gerada automaticamente)
    $senhaFinal = '';
    if ($nova_senha !== '') {
        if (strlen($nova_senha) < 4) {
            echo json_encode(['success' => false, 'message' => 'A nova senha deve ter pelo menos 4 caracteres.']);
            exit;
        }
        $senhaFinal = $nova_senha;
    } else {
        // Gera senha provisória aleatória de 8 caracteres
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $senhaFinal = substr(str_shuffle($chars), 0, 8);
        $forcar_troca = 1; // Se foi gerada aleatória, força a troca
    }

    $senhaHash = password_hash($senhaFinal, PASSWORD_DEFAULT);

    // 3. Atualiza no banco
    $sql = "UPDATE usuarios SET senha_hash = ?, precisa_mudar_senha = ? WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$senhaHash, $forcar_troca, $usuario_id]);

    echo json_encode([
        'success' => true,
        'message' => 'Senha alterada com sucesso!',
        'senha' => $senhaFinal,
        'senha_temp' => $senhaFinal,
        'foi_gerada' => empty($nova_senha),
        'precisa_mudar_senha' => $forcar_troca,
        'usuario' => [
            'id' => $usuario['id'],
            'nome' => $usuario['nome'],
            'email' => $usuario['email'],
            'cargo' => $usuario['cargo']
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro interno ao alterar senha: ' . $e->getMessage()]);
}