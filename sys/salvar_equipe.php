<?php
ini_set('display_errors', 0); 
error_reporting(E_ALL);

header('Content-Type: application/json');
session_start();

function exception_handler($e) {
    echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
    exit;
}
set_exception_handler('exception_handler');

try {
    require 'conexao.php';

    // 1. Verifica Sessão
    if (!isset($_SESSION['usuario_cargo']) || !isset($_SESSION['instituicao_id'])) {
        throw new Exception('Sessão expirada.');
    }

    // 2. Apenas Gerente/Admin da Loja
    if ($_SESSION['usuario_cargo'] !== 'gerente' && $_SESSION['usuario_cargo'] !== 'admin') {
        throw new Exception('Sem permissão.');
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $id_loja = $_SESSION['instituicao_id'];

    // === SEGURANÇA CRÍTICA (ANTI-GOLPE) ===
    // Impede que o gerente tente criar ou promover alguém a Super Admin
    if ($data['cargo'] === 'super_admin') {
        throw new Exception('Ação proibida: Você não pode criar Super Administradores.');
    }
    // =======================================

    if (empty($data['nome']) || empty($data['email']) || empty($data['cargo'])) {
        throw new Exception('Preencha os campos obrigatórios.');
    }

    if (empty($data['id'])) {
        // --- NOVO USUÁRIO ---
        if (empty($data['senha'])) throw new Exception('Senha obrigatória.');

        $senhaHash = password_hash($data['senha'], PASSWORD_DEFAULT);
        
        $sql = "INSERT INTO usuarios (nome, email, senha, cargo, instituicao_id, ativo) 
                VALUES (?, ?, ?, ?, ?, 1)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$data['nome'], $data['email'], $senhaHash, $data['cargo'], $id_loja]);
        
        $msg = "Funcionário cadastrado!";

    } else {
        // --- EDITAR USUÁRIO ---
        
        // Verificação Extra: Garante que o alvo NÃO é um Super Admin
        // (Caso o gerente tente editar um ID que ele descobriu ser do Super Admin)
        $check = $pdo->prepare("SELECT cargo FROM usuarios WHERE id = ? AND instituicao_id = ?");
        $check->execute([$data['id'], $id_loja]);
        $alvo = $check->fetch();

        if (!$alvo) {
            throw new Exception("Usuário não encontrado.");
        }
        if ($alvo['cargo'] === 'super_admin') {
            throw new Exception("Ação proibida: Alvo protegido.");
        }

        if (!empty($data['senha'])) {
            $senhaHash = password_hash($data['senha'], PASSWORD_DEFAULT);
            $sql = "UPDATE usuarios SET nome=?, email=?, cargo=?, senha=? WHERE id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$data['nome'], $data['email'], $data['cargo'], $senhaHash, $data['id']]);
            $msg = "Dados e senha atualizados!";
        } else {
            $sql = "UPDATE usuarios SET nome=?, email=?, cargo=? WHERE id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$data['nome'], $data['email'], $data['cargo'], $data['id']]);
            $msg = "Dados atualizados!";
        }
    }

    echo json_encode(['success' => true, 'message' => $msg]);

} catch (Throwable $e) {
    $erro = $e->getMessage();
    if (strpos($erro, 'Duplicate entry') !== false) $erro = "E-mail já cadastrado.";
    echo json_encode(['success' => false, 'message' => $erro]);
}
?>