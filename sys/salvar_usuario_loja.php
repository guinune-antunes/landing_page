<?php
session_start();
header('Content-Type: application/json');
require 'conexao.php';

// Segurança
if (!isset($_SESSION['usuario_cargo']) || $_SESSION['usuario_cargo'] !== 'Gerente') {
    echo json_encode(['success' => false, 'message' => 'Sem permissão.']); exit;
}

// TRAVA DE SEGURANÇA: Verifica se a loja está na sessão
$id_loja = $_SESSION['instituicao_id'] ?? null;
if (!$id_loja) {
    echo json_encode(['success' => false, 'message' => 'Sessão expirada ou loja não identificada. Faça login novamente.']); 
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

try {
    // Transforma o array de permissões do JavaScript em uma String JSON
    $permissoesJson = json_encode($data['permissoes']);
    $senhaHash = password_hash($data['senha'], PASSWORD_DEFAULT);

    $sql = "INSERT INTO usuarios (nome, email, senha_hash, cargo, instituicao_id, permissoes, ativo) 
            VALUES (?, ?, ?, ?, ?, ?, 1)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $data['nome'],
        $data['email'],
        $senhaHash,
        $data['cargo'],
        $id_loja,
        $permissoesJson // Salva as regras do Discord aqui!
    ]);

    echo json_encode(['success' => true, 'message' => 'Funcionário adicionado com sucesso!']);

} catch (Exception $e) {
    if ($e->getCode() == 23000) {
        echo json_encode(['success' => false, 'message' => 'Este e-mail já está em uso no sistema.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
    }
}
?>