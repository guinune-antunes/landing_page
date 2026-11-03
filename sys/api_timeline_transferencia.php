<?php
// ══════════════════════════════════════════════════════════════════
// api_timeline_transferencia.php — Rastreabilidade & Linha do Tempo (Audit Trail)
// ══════════════════════════════════════════════════════════════════
require 'seguranca_api.php';
require_once __DIR__ . '/includes/unidades.php';
exigirMetodo('GET');
exigirCargo(['Gerente', 'Admin']);

$transfId = (int)($_GET['id'] ?? 0);

if (!$transfId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID da transferência não fornecido.']);
    exit;
}

try {
    // 1. Busca dados da transferência
    $stT = $pdo->prepare("SELECT t.*, uo.nome_fantasia AS o_nome, uo.nome_unidade AS o_un, uo.cidade AS o_cid, uo.uf AS o_uf,
                                 ud.nome_fantasia AS d_nome, ud.nome_unidade AS d_un, ud.cidade AS d_cid, ud.uf AS d_uf,
                                 us.nome AS solicitante_nome, ua.nome AS aprovador_nome
                          FROM transferencias_estoque t
                          JOIN instituicoes uo ON uo.id = t.origem_id
                          JOIN instituicoes ud ON ud.id = t.destino_id
                          LEFT JOIN usuarios us ON us.id = t.usuario_id
                          LEFT JOIN usuarios ua ON ua.id = t.aprovador_id
                          WHERE t.id = ?");
    $stT->execute([$transfId]);
    $tr = $stT->fetch(PDO::FETCH_ASSOC);

    if (!$tr) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Transferência não encontrada.']);
        exit;
    }

    // Valida se o usuário tem acesso a alguma das unidades ou é Admin/Gerente Geral
    $minhaLoja = (int)$id_loja;
    $ehAdmin   = ($usuario_cargo === 'Admin');
    $ehGeral   = usuarioEhGerenteGeral($pdo, (int)$usuario_id, (int)$tr['rede_id']);

    if (!$ehAdmin && !$ehGeral && (int)$tr['origem_id'] !== $minhaLoja && (int)$tr['destino_id'] !== $minhaLoja) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Você não tem permissão para visualizar esta transferência.']);
        exit;
    }

    // 2. Busca eventos da trilha de auditoria
    $stAud = $pdo->prepare("SELECT a.*, DATE_FORMAT(a.data_hora, '%d/%m/%Y às %H:%i:%s') AS data_hora_fmt
                            FROM transferencias_auditoria a
                            WHERE a.transferencia_id = ?
                            ORDER BY a.data_hora ASC, a.id ASC");
    $stAud->execute([$transfId]);
    $eventos = $stAud->fetchAll(PDO::FETCH_ASSOC);

    // Se não houver eventos registrados (ex: registros antigos), sintetiza o evento inicial
    if (empty($eventos)) {
        $eventos[] = [
            'id' => 0,
            'acao' => 'solicitacao_criada',
            'usuario_nome' => $tr['solicitante_nome'] ?: 'Sistema',
            'de_status' => null,
            'para_status' => $tr['status'],
            'detalhes' => "Criação da transferência de {$tr['quantidade']} un. de \"{$tr['nome_produto']}\"",
            'data_hora_fmt' => date('d/m/Y às H:i:s', strtotime($tr['data_transferencia'])),
        ];
        if ($tr['status'] === 'recebida' && !empty($tr['data_resposta'])) {
            $eventos[] = [
                'id' => 1,
                'acao' => 'aprovacao_concluida',
                'usuario_nome' => $tr['aprovador_nome'] ?: 'Gestor',
                'de_status' => 'em_transito',
                'para_status' => 'recebida',
                'detalhes' => 'Envio autorizado e estoque creditado no destino.',
                'data_hora_fmt' => date('d/m/Y às H:i:s', strtotime($tr['data_resposta'])),
            ];
        }
    }

    // Formata os rótulos de unidade
    $tr['origem_rotulo']  = unidadeRotulo(['nome_fantasia' => $tr['o_nome'], 'nome_unidade' => $tr['o_un'], 'cidade' => $tr['o_cid'], 'uf' => $tr['o_uf']]);
    $tr['destino_rotulo'] = unidadeRotulo(['nome_fantasia' => $tr['d_nome'], 'nome_unidade' => $tr['d_un'], 'cidade' => $tr['d_cid'], 'uf' => $tr['d_uf']]);

    echo json_encode([
        'success'       => true,
        'transferencia' => $tr,
        'eventos'       => $eventos,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro ao carregar auditoria: ' . $e->getMessage()]);
}

