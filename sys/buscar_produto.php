<?php
session_start();
header('Content-Type: application/json');

// Verifica se o usuário está logado e tem uma instituição atrelada
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['instituicao_id'])) {
    echo json_encode(['success' => false, 'message' => 'Acesso negado. Usuário não autenticado.']);
    exit;
}

require 'conexao.php'; // Usa seu $pdo

$response = ['success' => false, 'produto' => null];

if (!isset($_GET['id']) || empty($_GET['id'])) {
    $response['message'] = 'ID do produto não fornecido.';
    echo json_encode($response);
    exit;
}

$produto_id = (int)$_GET['id'];

try {
    $instituicao_id = $_SESSION['instituicao_id'];
    
    $sql = "SELECT * FROM produtos WHERE id = ? AND instituicao_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$produto_id, $instituicao_id]);
    $produto = $stmt->fetch();

    if ($produto) {
        $response['success'] = true;
        $response['produto'] = $produto;
    } else {
        $response['message'] = 'Produto não encontrado.';
    }

} catch (PDOException $e) {
    // Retorna mensagem genérica para não expor estrutura do banco de dados
    $response['message'] = 'Erro interno ao buscar o produto.';
    // error_log($e->getMessage()); // Descomente para logar o erro no servidor
}

echo json_encode($response);
?>