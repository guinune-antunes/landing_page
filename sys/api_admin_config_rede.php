<?php
// ══════════════════════════════════════════════════════════════════
// api_admin_config_rede.php — Altera configurações de uma Rede (SaaS)
// ══════════════════════════════════════════════════════════════════
session_start();
header('Content-Type: application/json');
require 'conexao.php';

if (($_SESSION['usuario_cargo'] ?? '') !== 'Admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Sem permissão. Apenas Admin do SaaS.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$redeId = (int)($data['rede_id'] ?? 0);

if (!$redeId) {
    echo json_encode(['success' => false, 'message' => 'Rede não especificada.']);
    exit;
}

try {
    $st = $pdo->prepare("SELECT * FROM redes WHERE id = ?");
    $st->execute([$redeId]);
    $rede = $st->fetch();

    if (!$rede) {
        echo json_encode(['success' => false, 'message' => 'Rede não encontrada.']);
        exit;
    }

    $nome = isset($data['nome']) && trim($data['nome']) !== '' ? trim($data['nome']) : $rede['nome'];
    $compClientes   = isset($data['compartilhar_clientes']) ? ((int)$data['compartilhar_clientes'] ? 1 : 0) : (int)$rede['compartilhar_clientes'];
    $compFidelidade = isset($data['compartilhar_fidelidade']) ? ((int)$data['compartilhar_fidelidade'] ? 1 : 0) : (int)$rede['compartilhar_fidelidade'];

    $up = $pdo->prepare("UPDATE redes SET nome = ?, compartilhar_clientes = ?, compartilhar_fidelidade = ? WHERE id = ?");
    $up->execute([$nome, $compClientes, $compFidelidade, $redeId]);

    echo json_encode([
        'success' => true,
        'message' => 'Configurações da rede atualizadas com sucesso!',
        'rede' => [
            'id' => $redeId,
            'nome' => $nome,
            'compartilhar_clientes' => $compClientes,
            'compartilhar_fidelidade' => $compFidelidade
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro ao atualizar rede: ' . $e->getMessage()]);
}
