<?php
// ══════════════════════════════════════════════════════════════
// cancelar_agendamento.php — Altera status de agendamento (Cancelar/Concluir)
// ══════════════════════════════════════════════════════════════
require 'seguranca_api.php';
exigirMetodo('POST');

$data = json_decode(file_get_contents('php://input'), true);

try {
    if (empty($data['id'])) {
        throw new Exception('ID do agendamento não informado.');
    }

    $id = (int)$data['id'];
    $novo_status = trim($data['status'] ?? 'Cancelado');

    $status_validos = ['Cancelado', 'Concluído', 'Agendado', 'Confirmado', 'Aguardando', 'Em Andamento', 'Não Compareceu', 'Marcado', 'Remarcado'];
    if (!in_array($novo_status, $status_validos, true)) {
        throw new Exception('Status inválido informado.');
    }

    $stmt = $pdo->prepare("UPDATE agendamentos SET status = ? WHERE id = ? AND instituicao_id = ?");
    $stmt->execute([$novo_status, $id, $id_loja]);

    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare("SELECT id FROM agendamentos WHERE id = ? AND instituicao_id = ?");
        $check->execute([$id, $id_loja]);
        if (!$check->fetch()) {
            throw new Exception('Agendamento não encontrado.');
        }
    }

    $msg = ($novo_status === 'Cancelado') ? 'Agendamento cancelado com sucesso!' : 
          (($novo_status === 'Concluído') ? 'Atendimento concluído com sucesso!' : "Status atualizado para '$novo_status'.");

    echo json_encode([
        'success' => true,
        'message' => $msg,
        'novo_status' => $novo_status
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Erro: ' . $e->getMessage()
    ]);
}
