<?php
// remarcar_agendamento.php
header('Content-Type: application/json');
require 'conexao.php'; // Usa seu $pdo

$data = json_decode(file_get_contents('php://input'), true);

try {
    // 1. Validação dos dados recebidos
    if (empty($data['id_antigo']) || empty($data['novos_dados'])) {
        throw new Exception('Dados insuficientes para remarcar.');
    }

    $id_antigo = $data['id_antigo'];
    $novos = $data['novos_dados'];

    // Inicia a transação
    $pdo->beginTransaction();

    // 2. ATUALIZA o agendamento antigo
    $sql_update = "UPDATE agendamentos SET status = 'Remarcado' WHERE id = ?";
    $stmt_update = $pdo->prepare($sql_update);
    $stmt_update->execute([$id_antigo]);
    
    // 3. CRIA o novo agendamento (a cópia)
    
    // Calcula as novas datas
    $data_hora_inicio = $novos['date'] . ' ' . $novos['time'] . ':00';
    $data_hora_fim_obj = new DateTime($data_hora_inicio);
    $data_hora_fim_obj->modify('+1 hour'); // Ajuste a duração se necessário
    $data_hora_fim = $data_hora_fim_obj->format('Y-m-d H:i:s');

    $sql_insert = "INSERT INTO agendamentos 
        (cliente_id, pet_id, servico, profissional, data_hora_inicio, data_hora_fim, observacoes, status) 
        VALUES 
        (?, ?, ?, ?, ?, ?, ?, 'Agendado')";
    
    $stmt_insert = $pdo->prepare($sql_insert);
    $stmt_insert->execute([
        $novos['cliente_id'], 
        $novos['pet_id'], 
        $novos['servico'], 
        $novos['profissional'], 
        $data_hora_inicio, 
        $data_hora_fim, 
        $novos['observacoes']
    ]);

    // 4. Confirma a transação
    $pdo->commit();
    
    echo json_encode(['success' => true, 'message' => 'Agendamento remarcado com sucesso!']);

} catch (Exception $e) {
    // Se algo der errado, desfaz tudo
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Erro ao remarcar: ' . $e->getMessage()]);
}
?>