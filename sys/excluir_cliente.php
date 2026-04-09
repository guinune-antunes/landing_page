<?php
require_once 'conexao.php';
header('Content-Type: application/json');

// Verifica se o ID foi enviado
if (isset($_GET['id'])) {
    $clienteId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

    if ($clienteId === false || $clienteId <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID do cliente inválido.']);
        exit;
    }

    $pdo->beginTransaction();
    try {
        // Deleta o cliente (e os pets associados, devido ao ON DELETE CASCADE no banco)
        $sql = "DELETE FROM clientes WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$clienteId]);

        // Verifica se alguma linha foi afetada (se o cliente realmente existia)
        if ($stmt->rowCount() > 0) {
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Cliente excluído com sucesso!']);
        } else {
            $pdo->rollBack(); // Desfaz a transação se o cliente não foi encontrado
            echo json_encode(['success' => false, 'message' => 'Cliente não encontrado.']);
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        // Em produção, logar o erro em vez de exibi-lo
        echo json_encode(['success' => false, 'message' => 'Erro ao excluir cliente: ' . $e->getMessage()]);
    }

} else {
    echo json_encode(['success' => false, 'message' => 'ID do cliente não fornecido.']);
}