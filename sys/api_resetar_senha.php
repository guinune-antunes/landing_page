<?php
session_start();
header('Content-Type: application/json');
require 'conexao.php';
require_once __DIR__ . '/includes/unidades.php';

$cargoSessao = $_SESSION['usuario_cargo'] ?? '';
if ($cargoSessao !== 'Gerente' && $cargoSessao !== 'Admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Sem permissão para alterar senhas.']);
    exit;
}

$id_loja = intval($_SESSION['instituicao_id'] ?? 0);
$data = json_decode(file_get_contents('php://input'), true) ?? [];
$usuario_id = intval($data['id'] ?? 0);
$nova_senha = trim((string)($data['nova_senha'] ?? $data['senha'] ?? ''));
$forcar_troca = isset($data['forcar_troca']) ? (int)$data['forcar_troca'] : (empty($nova_senha) ? 1 : 0);

if ($usuario_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID de usuário inválido.']);
    exit;
}

try {
    // 1. Busca o usuário alvo
    $st = $pdo->prepare("SELECT id, nome, email, cargo, instituicao_id FROM usuarios WHERE id = ?");
    $st->execute([$usuario_id]);
    $usuario = $st->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        echo json_encode(['success' => false, 'message' => 'Usuário não encontrado.']);
        exit;
    }

    // 2. Se for Gerente, valida permissão sobre a instituição do usuário alvo
    if ($cargoSessao === 'Gerente') {
        // Gerente não pode resetar senha de Admin
        if ($usuario['cargo'] === 'Admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Não é permitido alterar a senha de administradores.']);
            exit;
        }

        // Verifica se a instituição do usuário bate com a loja ativa ou rede
        $instAlvo = intval($usuario['instituicao_id']);
        $permitido = false;

        if ($instAlvo === $id_loja) {
            $permitido = true;
        } else {
            // Verifica unidades permitidas do gerente
            $unidadesPermitidas = carregarUnidadesUsuario($pdo, (int)$_SESSION['usuario_id']);
            foreach ($unidadesPermitidas as $u) {
                if ($u['id'] === $instAlvo) {
                    $permitido = true;
                    break;
                }
            }
        }

        if (!$permitido) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Usuário não pertence à sua unidade ou rede.']);
            exit;
        }
    }

    // 3. Define a senha
    if ($nova_senha !== '') {
        if (strlen($nova_senha) < 4) {
            echo json_encode(['success' => false, 'message' => 'A senha deve ter no mínimo 4 caracteres.']);
            exit;
        }
        $senhaFinal = $nova_senha;
    } else {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $senhaFinal = substr(str_shuffle($chars), 0, 8);
        $forcar_troca = 1;
    }

    $senhaHash = password_hash($senhaFinal, PASSWORD_DEFAULT);

    // 4. Grava
    $sql = "UPDATE usuarios SET senha_hash = ?, precisa_mudar_senha = ? WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$senhaHash, $forcar_troca, $usuario_id]);

    echo json_encode([
        'success' => true,
        'message' => 'Senha alterada com sucesso!',
        'senha_temp' => $senhaFinal,
        'senha' => $senhaFinal,
        'foi_gerada' => empty($nova_senha),
        'precisa_mudar_senha' => $forcar_troca
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro interno ao processar senha: ' . $e->getMessage()]);
}