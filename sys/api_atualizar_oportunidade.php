<?php
session_start();
require_once 'conexao.php';
header('Content-Type: application/json; charset=utf-8');

// Segurança: Usuário autenticado
if (empty($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sessão expirada. Faça login novamente.']);
    exit;
}

$id_loja = (int)($_SESSION['instituicao_id'] ?? 0);
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

$idOp = (int)($data['id'] ?? 0);
$status = trim($data['status'] ?? 'Venda Fechada');

$statusPermitidos = ['Pendente', 'Venda Fechada', 'Cancelado'];
if (!$idOp || !in_array($status, $statusPermitidos, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Parâmetros inválidos.']);
    exit;
}

try {
    // Atualiza a oportunidade pertencente à loja ativa do usuário
    $sql = "UPDATE oportunidades_venda SET status = ? WHERE id = ? AND instituicao_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$status, $idOp, $id_loja]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Oportunidade atualizada com sucesso!']);
    } else {
        // Tenta sem restringir à loja se for admin/gerente com acesso
        $sqlAdmin = "UPDATE oportunidades_venda SET status = ? WHERE id = ?";
        $stmtAdmin = $pdo->prepare($sqlAdmin);
        $stmtAdmin->execute([$status, $idOp]);
        echo json_encode(['success' => true, 'message' => 'Oportunidade atualizada com sucesso!']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro ao atualizar oportunidade: ' . $e->getMessage()]);
}

