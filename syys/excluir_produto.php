<?php
header('Content-Type: application/json');
require 'conexao.php'; // Usa seu $pdo

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'ID do produto não fornecido.']);
    exit;
}

$produto_id = (int)$_GET['id'];

try {
    $sql = "DELETE FROM produtos WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$produto_id]);
    
    echo json_encode(['success' => true, 'message' => 'Produto excluído com sucesso!']);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erro ao excluir: ' . $e->getMessage()]);
}
?>