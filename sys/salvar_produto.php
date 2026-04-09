<?php
header('Content-Type: application/json');
require 'conexao.php'; // Usa seu $pdo

$data = json_decode(file_get_contents('php://input'), true);

// Validação
if (empty($data['nome']) || empty($data['quantidade']) || empty($data['unidade']) || empty($data['data_chegada'])) {
    echo json_encode(['success' => false, 'message' => 'Por favor, preencha todos os campos obrigatórios.']);
    exit;
}

// Trata campos opcionais
$data_vencimento = !empty($data['data_vencimento']) ? $data['data_vencimento'] : null;
$descricao = !empty($data['descricao']) ? $data['descricao'] : null; // <-- ADICIONADO
$marca = !empty($data['marca']) ? $data['marca'] : null;

try {
    if (empty($data['id'])) {
        // --- NOVO PRODUTO (INSERT) ---
        $sql = "INSERT INTO produtos (nome, marca, descricao, quantidade, unidade, data_vencimento, data_chegada) 
                VALUES (?, ?, ?, ?, ?, ?, ?)"; // <-- SQL ATUALIZADO
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['nome'],
            $marca,
            $descricao, // <-- CAMPO ADICIONADO
            $data['quantidade'],
            $data['unidade'],
            $data_vencimento,
            $data['data_chegada']
        ]);
        $message = 'Produto salvo com sucesso!';
    } else {
        // --- PRODUTO EXISTENTE (UPDATE) ---
        $sql = "UPDATE produtos SET 
                    nome = ?, 
                    marca = ?, 
                    descricao = ?, -- <-- ATUALIZADO
                    quantidade = ?, 
                    unidade = ?, 
                    data_vencimento = ?, 
                    data_chegada = ? 
                WHERE 
                    id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['nome'],
            $marca,
            $descricao, // <-- CAMPO ADICIONADO
            $data['quantidade'],
            $data['unidade'],
            $data_vencimento,
            $data['data_chegada'],
            $data['id'] // ID para o WHERE
        ]);
        $message = 'Produto atualizado com sucesso!';
    }
    
    echo json_encode(['success' => true, 'message' => $message]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erro ao salvar no banco: ' . $e->getMessage()]);
}
?>