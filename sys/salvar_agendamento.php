<?php
session_start(); // 1. Inicia sessão
header('Content-Type: application/json');
require 'conexao.php';

// 2. Segurança: Verifica loja
if (!isset($_SESSION['instituicao_id'])) {
    echo json_encode(['success' => false, 'message' => 'Sessão inválida.']); exit;
}
$id_loja = $_SESSION['instituicao_id'];

$data = json_decode(file_get_contents('php://input'), true);

try {
    // Validação simples
    if (empty($data['cliente_id']) || empty($data['pet_id']) || empty($data['date']) || empty($data['time'])) {
        throw new Exception('Preencha os campos obrigatórios.');
    }

    // Constrói datas
    $data_hora_inicio = $data['date'] . ' ' . $data['time'] . ':00';
    
    // Calcula fim (ex: +1 hora padrão)
    $data_hora_fim_obj = new DateTime($data_hora_inicio);
    $data_hora_fim_obj->modify('+1 hour'); 
    $data_hora_fim = $data_hora_fim_obj->format('Y-m-d H:i:s');

    // 3. SQL Atualizado com instituicao_id
    $sql = "INSERT INTO agendamentos 
                (instituicao_id, cliente_id, pet_id, servico, profissional, data_hora_inicio, data_hora_fim, observacoes, status) 
            VALUES 
                (?, ?, ?, ?, ?, ?, ?, ?, 'Agendado')";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $id_loja, // <-- O PULO DO GATO ESTÁ AQUI
        $data['cliente_id'], 
        $data['pet_id'], 
        $data['servico'], 
        $data['profissional'], 
        $data_hora_inicio, 
        $data_hora_fim, 
        $data['observacoes']
    ]);

    echo json_encode(['success' => true, 'message' => 'Agendamento salvo com sucesso!']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
}
?>