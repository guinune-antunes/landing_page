<?php
header('Content-Type: application/json');
require 'conexao.php';

$termo = isset($_GET['termo']) ? trim($_GET['termo']) : '';

if (empty($termo)) {
    echo json_encode([]);
    exit;
}

try {
    // Busca por Nome OU Código de Barras (apenas com estoque positivo)
    $sql = "SELECT id, nome, preco, codigo_barras 
            FROM produtos 
            WHERE (nome LIKE ? OR codigo_barras = ?) 
            AND quantidade > 0 
            LIMIT 10";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(["%$termo%", $termo]);
    
    echo json_encode($stmt->fetchAll());

} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>