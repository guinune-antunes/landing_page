<?php
// ══════════════════════════════════════════════════════════════
// buscar_pets_cliente.php — Retorna lista de pets de um cliente
// ══════════════════════════════════════════════════════════════
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');
require 'conexao.php';

$response = ['success' => false, 'pets' => []];

if (!isset($_GET['cliente_id']) || empty($_GET['cliente_id'])) {
    $response['message'] = 'ID do cliente não fornecido.';
    echo json_encode($response);
    exit;
}

$cliente_id = (int)$_GET['cliente_id'];
$id_loja = $_SESSION['instituicao_id'] ?? null;

try {
    if ($id_loja) {
        $sql = "SELECT id, nome, especie, raca FROM pets WHERE cliente_id = ? AND instituicao_id = ? ORDER BY nome ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$cliente_id, $id_loja]);
    } else {
        $sql = "SELECT id, nome, especie, raca FROM pets WHERE cliente_id = ? ORDER BY nome ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$cliente_id]);
    }
    
    $pets = $stmt->fetchAll();
    $response['success'] = true;
    $response['pets'] = $pets;

} catch (PDOException $e) {
    $response['message'] = 'Erro interno no servidor: ' . $e->getMessage();
}

echo json_encode($response);