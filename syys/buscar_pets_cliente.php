<?php
header('Content-Type: application/json');
require 'conexao.php'; // Usa seu $pdo

// Prepara a resposta
$response = ['success' => false, 'pets' => []];

if (!isset($_GET['cliente_id']) || empty($_GET['cliente_id'])) {
    $response['message'] = 'ID do cliente não fornecido.';
    echo json_encode($response);
    exit;
}

$cliente_id = (int)$_GET['cliente_id'];

try {
    $sql = "SELECT id, nome FROM pets WHERE cliente_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$cliente_id]);
    $pets = $stmt->fetchAll();
    
    $response['success'] = true;
    $response['pets'] = $pets;

} catch (PDOException $e) {
    $response['message'] = 'Erro de SQL: ' . $e->getMessage();
}

echo json_encode($response);
?>