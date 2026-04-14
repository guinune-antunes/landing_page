<?php
session_start();
header('Content-Type: application/json');
require 'conexao.php';

$id_loja = $_SESSION['instituicao_id'] ?? null;
if (!$id_loja || ($_SESSION['usuario_cargo'] !== 'Gerente' && $_SESSION['usuario_cargo'] !== 'Admin')) {
    echo json_encode(['success' => false, 'message' => 'Sessão inválida ou sem permissão.']); exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['nome_produto']) || !isset($data['preco_venda'])) {
    echo json_encode(['success' => false, 'message' => 'Campos obrigatórios ausentes.']); exit;
}

// Tratamento de valores vazios para NULL
$valor_compra = !empty($data['valor_compra']) ? $data['valor_compra'] : 0.00;
$peso = !empty($data['tamanho_embalagem_kg']) ? $data['tamanho_embalagem_kg'] : null;
$fab = !empty($data['data_fabricacao']) ? $data['data_fabricacao'] : null;
$val = !empty($data['data_validade']) ? $data['data_validade'] : null;

try {
    if (empty($data['id'])) {
        // NOVO PRODUTO (INSERT)
        $sql = "INSERT INTO produtos (instituicao_id, nome_produto, descricao, codigo_barras, valor_compra, preco_venda, quantidade_estoque, tamanho_embalagem_kg, fornecedor, data_fabricacao, data_validade) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $id_loja, $data['nome_produto'], $data['descricao'], $data['codigo_barras'],
            $valor_compra, $data['preco_venda'], $data['quantidade_estoque'],
            $peso, $data['fornecedor'], $fab, $val
        ]);
        $msg = 'Produto cadastrado com sucesso!';
    } else {
        // EDITAR PRODUTO (UPDATE)
        $sql = "UPDATE produtos SET 
                nome_produto = ?, descricao = ?, codigo_barras = ?, valor_compra = ?, preco_venda = ?, 
                quantidade_estoque = ?, tamanho_embalagem_kg = ?, fornecedor = ?, data_fabricacao = ?, data_validade = ? 
                WHERE id = ? AND instituicao_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['nome_produto'], $data['descricao'], $data['codigo_barras'], $valor_compra, $data['preco_venda'], 
            $data['quantidade_estoque'], $peso, $data['fornecedor'], $fab, $val,
            $data['id'], $id_loja
        ]);
        $msg = 'Produto atualizado com sucesso!';
    }

    echo json_encode(['success' => true, 'message' => $msg]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
}
?>