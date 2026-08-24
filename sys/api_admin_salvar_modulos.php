<?php
session_start();
header('Content-Type: application/json');
require 'conexao.php';

// PROTEÇÃO: Apenas o Administrador do Sistema (SaaS) pode acessar
if (!isset($_SESSION['usuario_cargo']) || $_SESSION['usuario_cargo'] !== 'Admin') {
    echo json_encode(['success' => false, 'message' => 'Sem permissão. Somente o Super Admin pode alterar módulos.']); 
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['instituicao_id'])) {
    echo json_encode(['success' => false, 'message' => 'ID da instituição não fornecido.']);
    exit;
}

$id_loja = intval($data['instituicao_id']);
$modulosSelecionados = $data['modulos'] ?? [];

// Monta o array associativo com true para os selecionados
$modulosJsonArray = [];
foreach ($modulosSelecionados as $mod) {
    $modulosJsonArray[$mod] = true;
}

$modulosStr = json_encode($modulosJsonArray);

try {
    $sql = "UPDATE instituicoes SET modulos_contratados = ? WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$modulosStr, $id_loja]);

    echo json_encode(['success' => true, 'message' => 'Módulos atualizados com sucesso! As mudanças já refletem no menu da loja.']);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erro interno ao salvar módulos no banco de dados.']);
}
?>
