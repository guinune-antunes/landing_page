<?php
session_start();
header('Content-Type: application/json');
require 'conexao.php';

$id_loja = $_SESSION['instituicao_id'] ?? null;

// Segurança de backend: Confirma se quem está chamando o arquivo é o Gerente
if (!$id_loja || ($_SESSION['usuario_cargo'] !== 'Gerente' && $_SESSION['usuario_cargo'] !== 'Admin')) {
    echo json_encode(['success' => false, 'message' => 'Você não tem permissão para criar tarefas manuais.']); exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$titulo = $data['titulo'] ?? '';
$tipo = $data['tipo'] ?? 'Outros';
$descricao = $data['descricao'] ?? '';

if (empty($titulo) || empty($descricao)) {
    echo json_encode(['success' => false, 'message' => 'Título e descrição são obrigatórios.']); exit;
}

try {
    // Insere a tarefa manual diretamente no Kanban
    $sql = "INSERT INTO tarefas_estoque (instituicao_id, tipo, titulo, descricao, status, data_criacao) 
            VALUES (?, ?, ?, ?, 'Pendente', NOW())";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id_loja, $tipo, $titulo, $descricao]);

    echo json_encode(['success' => true, 'message' => 'Ordem de serviço criada com sucesso! A equipe já pode visualizá-la.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro no servidor: ' . $e->getMessage()]);
}
?>