<?php
// ══════════════════════════════════════════════════════════════
// salvar_produto.php — Endpoint seguro para a tela de estoque
// ══════════════════════════════════════════════════════════════
// Substituição segura do arquivo legado que não tinha autenticação.
// Mantém compatibilidade com os campos enviados pelo main.js.
// ══════════════════════════════════════════════════════════════
require 'seguranca_api.php';
exigirMetodo('POST');

$data = json_decode(file_get_contents('php://input'), true);

// Validação
if (empty($data['nome']) || empty($data['quantidade']) || empty($data['unidade']) || empty($data['data_chegada'])) {
    echo json_encode(['success' => false, 'message' => 'Por favor, preencha todos os campos obrigatórios.']);
    exit;
}

// Trata campos opcionais
$data_vencimento = !empty($data['data_vencimento']) ? $data['data_vencimento'] : null;
$descricao = !empty($data['descricao']) ? $data['descricao'] : null;
$marca = !empty($data['marca']) ? $data['marca'] : null;

try {
    if (empty($data['id'])) {
        // --- NOVO PRODUTO (INSERT) com instituicao_id ---
        $sql = "INSERT INTO produtos (instituicao_id, nome, marca, descricao, quantidade, unidade, data_vencimento, data_chegada) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $id_loja,
            $data['nome'],
            $marca,
            $descricao,
            $data['quantidade'],
            $data['unidade'],
            $data_vencimento,
            $data['data_chegada']
        ]);
        $message = 'Produto salvo com sucesso!';
    } else {
        // --- PRODUTO EXISTENTE (UPDATE) com filtro de instituicao_id ---
        $sql = "UPDATE produtos SET 
                    nome = ?, 
                    marca = ?, 
                    descricao = ?,
                    quantidade = ?, 
                    unidade = ?, 
                    data_vencimento = ?, 
                    data_chegada = ? 
                WHERE 
                    id = ? AND instituicao_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['nome'],
            $marca,
            $descricao,
            $data['quantidade'],
            $data['unidade'],
            $data_vencimento,
            $data['data_chegada'],
            $data['id'],
            $id_loja
        ]);
        $message = 'Produto atualizado com sucesso!';
    }
    
    echo json_encode(['success' => true, 'message' => $message]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erro interno ao salvar produto.']);
}
