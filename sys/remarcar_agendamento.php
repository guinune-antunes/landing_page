<?php
// ══════════════════════════════════════════════════════════════
// remarcar_agendamento.php — Remarca um agendamento existente
// ══════════════════════════════════════════════════════════════
require 'seguranca_api.php';
exigirMetodo('POST');

$data = json_decode(file_get_contents('php://input'), true);

try {
    if (empty($data['id_antigo']) || empty($data['novos_dados'])) {
        throw new Exception('Dados insuficientes para remarcar.');
    }

    $id_antigo = (int)$data['id_antigo'];
    $novos     = $data['novos_dados'];

    $cliente_id  = !empty($novos['cliente_id']) ? (int)$novos['cliente_id'] : null;
    $pet_id      = !empty($novos['pet_id']) ? (int)$novos['pet_id'] : null;
    $servico     = trim($novos['servico'] ?? '');
    $date        = trim($novos['date'] ?? $novos['data'] ?? '');
    $time        = trim($novos['time'] ?? $novos['hora'] ?? '');
    $observacoes = trim($novos['observacoes'] ?? $novos['obs'] ?? '');

    if (empty($cliente_id) || empty($pet_id) || empty($servico) || empty($date) || empty($time)) {
        throw new Exception('Preencha os campos obrigatórios (Cliente, Pet, Serviço, Nova Data e Novo Horário).');
    }

    // Calcula nova data e hora
    $data_hora_inicio = $date . ' ' . (strlen($time) === 5 ? $time . ':00' : $time);
    $data_hora_inicio_obj = DateTime::createFromFormat('Y-m-d H:i:s', $data_hora_inicio);
    if (!$data_hora_inicio_obj) {
        throw new Exception('Data ou horário inválidos.');
    }

    $data_hora_fim_obj = clone $data_hora_inicio_obj;
    if ($servico === 'Banho e Tosa') {
        $data_hora_fim_obj->modify('+2 hours');
    } elseif ($servico === 'Vacina') {
        $data_hora_fim_obj->modify('+30 minutes');
    } else {
        $data_hora_fim_obj->modify('+1 hour');
    }
    $data_hora_fim = $data_hora_fim_obj->format('Y-m-d H:i:s');

    // Trata profissional (id e nome)
    $prof_input = $novos['profissional_id'] ?? $novos['profissional'] ?? null;
    $profissional_id = null;
    $profissional_nome = null;

    if (!empty($prof_input) && (int)$prof_input > 0) {
        $profissional_id = (int)$prof_input;
        $stmt_prof = $pdo->prepare("SELECT nome FROM usuarios WHERE id = ? AND instituicao_id = ?");
        $stmt_prof->execute([$profissional_id, $id_loja]);
        $profissional_nome = $stmt_prof->fetchColumn() ?: null;
    } elseif (is_string($prof_input) && !is_numeric($prof_input) && trim($prof_input) !== '') {
        $profissional_nome = trim($prof_input);
    }

    // Valida se o agendamento antigo pertence à loja
    $stmt_check = $pdo->prepare("SELECT id FROM agendamentos WHERE id = ? AND instituicao_id = ?");
    $stmt_check->execute([$id_antigo, $id_loja]);
    if (!$stmt_check->fetch()) {
        throw new Exception('Agendamento original não encontrado.');
    }

    // Inicia a transação
    $pdo->beginTransaction();

    // 1. Marca o agendamento anterior como 'Remarcado'
    $sql_update = "UPDATE agendamentos SET status = 'Remarcado' WHERE id = ? AND instituicao_id = ?";
    $stmt_update = $pdo->prepare($sql_update);
    $stmt_update->execute([$id_antigo, $id_loja]);

    // 2. Cria o novo agendamento com status 'Agendado'
    $sql_insert = "INSERT INTO agendamentos 
        (instituicao_id, cliente_id, pet_id, servico, profissional_id, profissional, data_hora_inicio, data_hora_fim, observacoes, status) 
        VALUES 
        (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Agendado')";

    $stmt_insert = $pdo->prepare($sql_insert);
    $stmt_insert->execute([
        $id_loja,
        $cliente_id,
        $pet_id,
        $servico,
        $profissional_id,
        $profissional_nome,
        $data_hora_inicio,
        $data_hora_fim,
        $observacoes
    ]);

    $novo_id = (int)$pdo->lastInsertId();

    $pdo->commit();

    echo json_encode([
        'success' => true, 
        'message' => 'Agendamento remarcado com sucesso!',
        'id' => $novo_id
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Erro ao remarcar: ' . $e->getMessage()]);
}