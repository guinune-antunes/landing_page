<?php
session_start();
header('Content-Type: application/json');
require 'conexao.php';

// CORREÇÃO 1: O cargo correto do administrador no seu banco é 'Admin'
if (!isset($_SESSION['usuario_cargo']) || $_SESSION['usuario_cargo'] !== 'Admin') {
    echo json_encode(['success' => false, 'message' => 'Sem permissão.']); 
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

try {
    $pdo->beginTransaction();

    $modulosPadrao = json_encode([
        'agenda' => true,
        'clientes' => true,
        'financeiro' => true,
        'estoque' => true,
        'crm_vendas' => true,
        'relatorios' => true,
        'equipe' => true,
        'clinica' => true
    ]);

    $sqlInst = "INSERT INTO instituicoes (nome_fantasia, cnpj, modulos_contratados) VALUES (?, ?, ?)";
    $stmt = $pdo->prepare($sqlInst);
    $stmt->execute([$data['nome_fantasia'], $data['cnpj'], $modulosPadrao]);
    
    $novaInstituicaoId = $pdo->lastInsertId();

    // 2. Criar o Usuário Gerente vinculado a ela
    $senhaHash = password_hash($data['gerente_senha'], PASSWORD_DEFAULT);
    
    // CORREÇÃO 3: Trocado 'senha' por 'senha_hash' e 'gerente' por 'Gerente'
    $sqlUser = "INSERT INTO usuarios (nome, email, senha_hash, cargo, instituicao_id, ativo) VALUES (?, ?, ?, 'Gerente', ?, 1)";
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
        echo json_encode(['success' => false, 'message' => 'Erro do Banco: ' . $e->getMessage()]);
    }
}
?>