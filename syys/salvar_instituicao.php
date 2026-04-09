<?php
session_start();
header('Content-Type: application/json');
require 'conexao.php';

// Apenas Super Admin
if ($_SESSION['usuario_cargo'] !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Sem permissão.']); exit;
}

$data = json_decode(file_get_contents('php://input'), true);

try {
    $pdo->beginTransaction();

    // 1. Criar a Instituição
    $sqlInst = "INSERT INTO instituicoes (nome_fantasia, cnpj, plano) VALUES (?, ?, 'basic')";
    $stmt = $pdo->prepare($sqlInst);
    $stmt->execute([$data['nome_fantasia'], $data['cnpj']]);
    
    $novaInstituicaoId = $pdo->lastInsertId();

    // 2. Criar o Usuário Gerente vinculado a ela
    $senhaHash = password_hash($data['gerente_senha'], PASSWORD_DEFAULT);
    
    $sqlUser = "INSERT INTO usuarios (nome, email, senha, cargo, instituicao_id, ativo) VALUES (?, ?, ?, 'gerente', ?, 1)";
    $stmtUser = $pdo->prepare($sqlUser);
    $stmtUser->execute([
        $data['gerente_nome'],
        $data['gerente_email'],
        $senhaHash,
        $novaInstituicaoId
    ]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Instituição e Gerente criados com sucesso!']);

} catch (Exception $e) {
    $pdo->rollBack();
    // Verifica erro de e-mail duplicado
    if ($e->getCode() == 23000) {
        echo json_encode(['success' => false, 'message' => 'Este e-mail de gerente já existe.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
    }
}
?>