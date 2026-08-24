<?php
// ══════════════════════════════════════════════════════════════
// excluir_cliente.php — Exclui um cliente da loja do usuário
// ══════════════════════════════════════════════════════════════
require 'seguranca_api.php';
exigirCargo(['Gerente', 'Admin']);
exigirMetodo('POST');

// Recebe o ID via JSON (não mais via GET)
$data = json_decode(file_get_contents('php://input'), true);
$clienteId = filter_var($data['id'] ?? 0, FILTER_VALIDATE_INT);

if (!$clienteId || $clienteId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID do cliente inválido.']);
    exit;
}

$pdo->beginTransaction();
try {
    // DELETE com filtro de instituicao_id (isolamento multi-tenant)
    // Os pets associados serão deletados automaticamente via ON DELETE CASCADE
    $sql = "DELETE FROM clientes WHERE id = ? AND instituicao_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$clienteId, $id_loja]);

    if ($stmt->rowCount() > 0) {
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Cliente excluído com sucesso!']);
    } else {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Cliente não encontrado ou não pertence à sua loja.']);
    }
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Erro interno ao excluir cliente.']);
}