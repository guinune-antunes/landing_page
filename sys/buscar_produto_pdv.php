<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['instituicao_id'])) {
    echo json_encode(['error' => 'Acesso negado.']);
    exit;
}

require 'conexao.php';

$termo = isset($_GET['termo']) ? trim($_GET['termo']) : '';

if (empty($termo)) {
    echo json_encode([]);
    exit;
}

try {
    $instituicao_id = $_SESSION['instituicao_id'];
    // Busca por Nome OU Código de Barras (apenas com estoque positivo) da instituição correta
    // Ajustado nome das colunas de acordo com a tabela: nome_produto, preco_venda, quantidade_estoque
    $sql = "SELECT id, nome_produto as nome, preco_venda as preco, codigo_barras 
            FROM produtos 
            WHERE instituicao_id = ? 
            AND (nome_produto LIKE ? OR codigo_barras = ?) 
            AND quantidade_estoque > 0 
            LIMIT 10";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$instituicao_id, "%$termo%", $termo]);
    
    echo json_encode($stmt->fetchAll());

} catch (PDOException $e) {
    echo json_encode(['error' => 'Erro interno ao buscar produtos.']);
}
?>