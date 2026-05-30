<?php
session_start();
header('Content-Type: application/json');
require 'conexao.php';

$id_loja = $_SESSION['instituicao_id'] ?? null;
if (!$id_loja || $_SESSION['usuario_cargo'] !== 'Gerente') {
    echo json_encode(['success' => false, 'message' => 'Sem permissão.']); exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$tipo = $data['tipo'] ?? '';
$titulo = $data['titulo'] ?? '';
$descricao = $data['descricao'] ?? '';

if (!$tipo || !$titulo) {
    echo json_encode(['success' => false, 'message' => 'Dados incompletos.']); exit;
}

try {
    // INTELIGÊNCIA: Verifica se já existe uma tarefa pendente para esse mesmo problema
    $stmt_check = $pdo->prepare("SELECT id FROM tarefas_estoque WHERE instituicao_id = ? AND tipo = ? AND status = 'Pendente'");
    $stmt_check->execute([$id_loja, $tipo]);
    
    if ($stmt_check->rowCount() > 0) {
        echo json_encode(['success' => false, 'message' => 'A equipe de estoque já possui uma ordem de serviço pendente para este problema!']);
        exit;
    }

    // Cria a Ordem de Serviço
    $sql = "INSERT INTO tarefas_estoque (instituicao_id, tipo, titulo, descricao) VALUES (?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id_loja, $tipo, $titulo, $descricao]);

    echo json_encode(['success' => true, 'message' => 'Ordem de Serviço enviada para a equipe de estoque com sucesso!']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro interno no servidor.']);
}
?>