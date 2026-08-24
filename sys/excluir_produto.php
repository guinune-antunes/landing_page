<?php
// ══════════════════════════════════════════════════════════════
// excluir_produto.php — Exclui um produto da loja do usuário
// ══════════════════════════════════════════════════════════════
require 'seguranca_api.php';
exigirCargo(['Gerente', 'Admin']);
exigirMetodo('POST');

// Recebe o ID via JSON (não mais via GET)
$data = json_decode(file_get_contents('php://input'), true);
$produto_id = filter_var($data['id'] ?? 0, FILTER_VALIDATE_INT);

if (!$produto_id || $produto_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID do produto inválido.']);
    exit;
}

try {
    // DELETE com filtro de instituicao_id (isolamento multi-tenant)
    $sql = "DELETE FROM produtos WHERE id = ? AND instituicao_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$produto_id, $id_loja]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Produto excluído com sucesso!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Produto não encontrado ou não pertence à sua loja.']);
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erro interno ao excluir.']);
}