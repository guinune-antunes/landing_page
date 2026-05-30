<?php
session_start();
header('Content-Type: application/json');
require 'conexao.php';

// Proteção 1: Somente Gerente
if (!isset($_SESSION['usuario_cargo']) || $_SESSION['usuario_cargo'] !== 'Gerente') {
    echo json_encode(['success' => false, 'message' => 'Sem permissão.']); exit;
}

// Proteção 2: Valida a sessão da loja
$id_loja = $_SESSION['instituicao_id'] ?? null;
if (!$id_loja) {
    echo json_encode(['success' => false, 'message' => 'Sessão expirada.']); exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['id']) || !isset($data['cargo']) || !isset($data['permissoes'])) {
    echo json_encode(['success' => false, 'message' => 'Dados incompletos.']); exit;
}

try {
    $permissoesJson = json_encode($data['permissoes']);
    
    // Segurança Máxima: O UPDATE exige que o usuario editado pertença à mesma instituicao_id do gerente logado!
    $sql = "UPDATE usuarios SET cargo = ?, permissoes = ? WHERE id = ? AND instituicao_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $data['cargo'],
        $permissoesJson,
        $data['id'],
        $id_loja
    ]);

    // Verifica se alguma linha foi realmente alterada
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Permissões atualizadas com sucesso!']);
    } else {
        // Se rowCount é 0, ou os dados são os mesmos, ou tentou hackear editando ID de outra loja
        echo json_encode(['success' => true, 'message' => 'Nenhuma alteração detectada ou usuário não pertence a esta loja.']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro interno no servidor.']);
}
?>