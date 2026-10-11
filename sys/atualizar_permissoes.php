<?php
session_start();
header('Content-Type: application/json');
require 'conexao.php';
require_once __DIR__ . '/includes/unidades.php';

$cargo = $_SESSION['usuario_cargo'] ?? '';
// Proteção 1: Somente Gerente ou Admin
if ($cargo !== 'Gerente' && $cargo !== 'Admin') {
    echo json_encode(['success' => false, 'message' => 'Sem permissão.']); exit;
}

// Proteção 2: Valida a sessão da loja
$id_loja = intval($_SESSION['instituicao_id'] ?? 0);
$data = json_decode(file_get_contents('php://input'), true) ?? [];

if (!isset($data['id']) || !isset($data['cargo']) || !isset($data['permissoes'])) {
    echo json_encode(['success' => false, 'message' => 'Dados incompletos.']); exit;
}

$target_id = intval($data['id']);
$nova_senha = trim((string)($data['senha'] ?? ''));

try {
    // 1. Busca usuário alvo
    $stCheck = $pdo->prepare("SELECT id, cargo, instituicao_id FROM usuarios WHERE id = ?");
    $stCheck->execute([$target_id]);
    $alvo = $stCheck->fetch(PDO::FETCH_ASSOC);

    if (!$alvo) {
        echo json_encode(['success' => false, 'message' => 'Usuário não encontrado.']);
        exit;
    }

    // 2. Validação de segurança sobre a loja/rede
    if ($cargo === 'Gerente') {
        if ($alvo['cargo'] === 'Admin') {
            echo json_encode(['success' => false, 'message' => 'Não é permitido alterar dados de administradores.']);
            exit;
        }

        $instAlvo = intval($alvo['instituicao_id']);
        $permitido = ($instAlvo === $id_loja);

        if (!$permitido && !empty($_SESSION['usuario_id'])) {
            $unidadesPermitidas = carregarUnidadesUsuario($pdo, (int)$_SESSION['usuario_id']);
            foreach ($unidadesPermitidas as $u) {
                if ($u['id'] === $instAlvo) {
                    $permitido = true;
                    break;
                }
            }
        }

        if (!$permitido) {
            echo json_encode(['success' => false, 'message' => 'Usuário não pertence à sua unidade ou rede.']);
            exit;
        }
    }

    $permissoesJson = json_encode($data['permissoes']);

    // 3. Atualiza dados (com ou sem alteração de senha)
    if ($nova_senha !== '') {
        if (strlen($nova_senha) < 4) {
            echo json_encode(['success' => false, 'message' => 'A senha deve ter pelo menos 4 caracteres.']);
            exit;
        }
        $senhaHash = password_hash($nova_senha, PASSWORD_DEFAULT);
        $sql = "UPDATE usuarios SET cargo = ?, permissoes = ?, senha_hash = ?, precisa_mudar_senha = 0 WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$data['cargo'], $permissoesJson, $senhaHash, $target_id]);
        $msg = 'Permissões e senha atualizadas com sucesso!';
    } else {
        $sql = "UPDATE usuarios SET cargo = ?, permissoes = ? WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$data['cargo'], $permissoesJson, $target_id]);
        $msg = 'Permissões atualizadas com sucesso!';
    }

    echo json_encode(['success' => true, 'message' => $msg]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro interno no servidor: ' . $e->getMessage()]);
}