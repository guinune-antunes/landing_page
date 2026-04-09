<?php
header('Content-Type: application/json');
require 'conexao.php'; // Usa seu $pdo

$response = ['success' => false, 'produto' => null];

if (!isset($_GET['id']) || empty($_GET['id'])) {
    $response['message'] = 'ID do produto não fornecido.';
    echo json_encode($response);
    exit;
}

$produto_id = (int)$_GET['id'];

try {
    $sql = "SELECT * FROM produtos WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$produto_id]);
    $produto = $stmt->fetch();

    if ($produto) {
        $response['success'] = true;
        $response['produto'] = $produto;
    } else {
        $response['message'] = 'Produto não encontrado.';
    }

} catch (PDOException $e) {
    $response['message'] = 'Erro de SQL: ' . $e->getMessage();
}

echo json_encode($response);
?>