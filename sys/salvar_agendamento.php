<?php
// ══════════════════════════════════════════════════════════════
// salvar_agendamento.php — Cria ou atualiza um agendamento
// ══════════════════════════════════════════════════════════════
require 'seguranca_api.php';
exigirMetodo('POST');

$data = json_decode(file_get_contents('php://input'), true);

try {
    if (!$data) {
        throw new Exception('Nenhum dado recebido.');
    }

    $cliente_id = !empty($data['cliente_id']) ? (int)$data['cliente_id'] : null;
    $pet_id     = !empty($data['pet_id']) ? (int)$data['pet_id'] : null;
    $servico    = trim($data['servico'] ?? '');
    $date       = trim($data['date'] ?? $data['data'] ?? '');
    $time       = trim($data['time'] ?? $data['hora'] ?? '');
    $id         = !empty($data['id']) ? (int)$data['id'] : null;
    $observacoes = trim($data['observacoes'] ?? $data['obs'] ?? '');

    if (empty($cliente_id) || empty($pet_id) || empty($servico) || empty($date) || empty($time)) {
        throw new Exception('Preencha os campos obrigatórios (Cliente, Pet, Serviço, Data e Horário).');
    }

    // Valida formato de data e hora
    $data_hora_inicio = $date . ' ' . (strlen($time) === 5 ? $time . ':00' : $time);
    $data_hora_inicio_obj = DateTime::createFromFormat('Y-m-d H:i:s', $data_hora_inicio);
    if (!$data_hora_inicio_obj) {
        throw new Exception('Data ou horário inválidos.');
    }

    // Calcula duração de acordo com o serviço
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
    $prof_input = $data['profissional_id'] ?? $data['profissional'] ?? null;
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

    // Valida se o pet pertence à instituição e ao cliente
    $stmt_pet = $pdo->prepare("SELECT id FROM pets WHERE id = ? AND cliente_id = ? AND instituicao_id = ?");
    $stmt_pet->execute([$pet_id, $cliente_id, $id_loja]);
    if (!$stmt_pet->fetch()) {
        throw new Exception('O pet selecionado não pertence a este cliente ou instituição.');
    }

    if ($id) {
        // --- EDIÇÃO (UPDATE) ---
        $stmt_check = $pdo->prepare("SELECT id FROM agendamentos WHERE id = ? AND instituicao_id = ?");
        $stmt_check->execute([$id, $id_loja]);
        if (!$stmt_check->fetch()) {
            throw new Exception('Agendamento não encontrado para edição.');
        }

        $sql = "UPDATE agendamentos 
                SET cliente_id = ?, 
                    pet_id = ?, 
                    servico = ?, 
                    profissional_id = ?, 
                    profissional = ?, 
                    data_hora_inicio = ?, 
                    data_hora_fim = ?, 
                    observacoes = ? 
                WHERE id = ? AND instituicao_id = ?";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $cliente_id,
            $pet_id,
            $servico,
            $profissional_id,
            $profissional_nome,
            $data_hora_inicio,
            $data_hora_fim,
            $observacoes,
            $id,
            $id_loja
        ]);

        echo json_encode([
            'success' => true, 
            'message' => 'Agendamento atualizado com sucesso!',
            'id' => $id
        ]);
    } else {
        // --- NOVO (INSERT) ---
        $sql = "INSERT INTO agendamentos 
                    (instituicao_id, cliente_id, pet_id, servico, profissional_id, profissional, data_hora_inicio, data_hora_fim, observacoes, status) 
                VALUES 
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Agendado')";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
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

        echo json_encode([
            'success' => true, 
            'message' => 'Agendamento criado com sucesso!',
            'id' => $novo_id
        ]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
}