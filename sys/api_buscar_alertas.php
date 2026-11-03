<?php
session_start();
header('Content-Type: application/json');
require 'conexao.php';

$id_loja = $_SESSION['instituicao_id'] ?? null;
if (!$id_loja) { echo json_encode([]); exit; }

$alertas = [];
$hoje = date('Y-m-d');

try {
    // ========================================================
    // 1. FEEDBACK POSITIVO: TAREFAS RESOLVIDAS PELO ESTOQUE
    // ========================================================
    // Mostra tarefas resolvidas nas últimas 24 horas
    $stmt_resolvidas = $pdo->prepare("
        SELECT t.titulo, t.observacao_resolucao, t.resolvido_em, u.nome as funcionario 
        FROM tarefas_estoque t 
        JOIN usuarios u ON t.resolvido_por = u.id 
        WHERE t.instituicao_id = ? AND t.status = 'Resolvido' AND t.resolvido_em >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ORDER BY t.resolvido_em DESC
    ");
    $stmt_resolvidas->execute([$id_loja]);
    $tarefas_ok = $stmt_resolvidas->fetchAll(PDO::FETCH_ASSOC);

    if (count($tarefas_ok) > 0) {
        $itens = [];
        foreach($tarefas_ok as $ok) {
            $hora = date('H:i', strtotime($ok['resolvido_em']));
            $itens[] = "<strong>" . htmlspecialchars($ok['funcionario']) . "</strong> às $hora: <em>" . htmlspecialchars($ok['observacao_resolucao']) . "</em>";
        }
        $alertas[] = [
            'tipo' => 'success', 'corHex' => '#27ae60', 'corFundo' => '#eafaf1', 'icone' => 'fa-check-double',
            'titulo' => 'Estoque Atualizado',
            'resumo' => count($tarefas_ok) . ' ordem(ns) de serviço resolvida(s) pela equipe.',
            'descricao' => 'Sua equipe de estoque concluiu as tarefas delegadas recentemente. Veja abaixo as observações deixadas por eles.',
            'link' => '#', 'textoBotao' => 'Ciente',
            'is_delegavel' => false, 'itens' => $itens
        ];
    }

    // ========================================================
    // 2. ALERTA DE PRODUTOS VENCIDOS (Agora Delegável)
    // ========================================================
    $limite_vencimento = date('Y-m-d', strtotime('+15 days'));
    $stmt_vencidos = $pdo->prepare("SELECT nome_produto, data_validade FROM produtos WHERE instituicao_id = ? AND data_validade <= ? ORDER BY data_validade ASC");
    $stmt_vencidos->execute([$id_loja, $limite_vencimento]);
    $vencidos = $stmt_vencidos->fetchAll(PDO::FETCH_ASSOC);

    if (count($vencidos) > 0) {
        $itens = [];
        foreach($vencidos as $v) {
            $itens[] = "<strong>" . htmlspecialchars($v['nome_produto']) . "</strong> - " . date('d/m/Y', strtotime($v['data_validade']));
        }
        $alertas[] = [
            'tipo' => 'danger', 'corHex' => '#e74c3c', 'corFundo' => '#fadbd8', 'icone' => 'fa-skull-crossbones',
            'titulo' => 'Atenção Sanitária',
            'resumo' => count($vencidos) . ' produto(s) vencido(s) ou próximos do vencimento.',
            'descricao' => 'Produtos passaram da validade ou vencerão em 15 dias. Delegue a remoção para a equipe de estoque.',
            'textoBotao' => 'Delegar para o Estoque',
            'is_delegavel' => true, 'tipo_tarefa' => 'Vencimento', 'link' => '#', 'itens' => $itens
        ];
    }

    // ========================================================
    // 3. ALERTA DE ESTOQUE BAIXO (Agora Delegável)
    // ========================================================
    $stmt_baixo = $pdo->prepare("SELECT nome_produto, quantidade_estoque FROM produtos WHERE instituicao_id = ? AND quantidade_estoque <= 5 ORDER BY quantidade_estoque ASC");
    $stmt_baixo->execute([$id_loja]);
    $baixo_estoque = $stmt_baixo->fetchAll(PDO::FETCH_ASSOC);

    if (count($baixo_estoque) > 0) {
        $itens = [];
        foreach($baixo_estoque as $b) {
            $itens[] = "<strong>" . htmlspecialchars($b['nome_produto']) . "</strong> - Restam " . $b['quantidade_estoque'];
        }
        $alertas[] = [
            'tipo' => 'warning', 'corHex' => '#f39c12', 'corFundo' => '#fdebd0', 'icone' => 'fa-boxes',
            'titulo' => 'Estoque Crítico',
            'resumo' => count($baixo_estoque) . ' produto(s) com 5 unidades ou menos.',
            'descricao' => 'Estoque acabando. Delegue para a equipe fazer a contagem física e solicitar reposição.',
            'textoBotao' => 'Delegar para o Estoque',
            'is_delegavel' => true, 'tipo_tarefa' => 'Estoque Baixo', 'link' => '#', 'itens' => $itens
        ];
    }

    // ========================================================
    // 4. AGENDA FANTASMA (Mantido não-delegável)
    // ========================================================
    $ontem = date('Y-m-d H:i:s', strtotime('-1 day'));
    $stmt_agenda = $pdo->prepare("SELECT p.nome as pet_nome, c.nome_completo as cliente_nome FROM agendamentos a JOIN pets p ON a.pet_id = p.id JOIN clientes c ON p.cliente_id = c.id WHERE a.instituicao_id = ? AND a.status = 'Marcado' AND a.data_hora_inicio < ?");
    $stmt_agenda->execute([$id_loja, $ontem]);
    $agenda_perdida = $stmt_agenda->fetchAll(PDO::FETCH_ASSOC);

    if (count($agenda_perdida) > 0) {
        $itens = [];
        foreach($agenda_perdida as $ag) { $itens[] = "<strong>Pet: " . htmlspecialchars($ag['pet_nome']) . "</strong> (Tutor: " . htmlspecialchars($ag['cliente_nome']) . ")"; }
        $alertas[] = [
            'tipo' => 'warning', 'corHex' => '#e67e22', 'corFundo' => '#fae5d3', 'icone' => 'fa-ghost',
            'titulo' => 'Agenda Fantasma',
            'resumo' => count($agenda_perdida) . ' serviço(s) passado(s) sem baixa.',
            'descricao' => 'Incongruência: Agendamentos passados com status "Marcado". Conclua ou cancele-os.',
            'link' => 'agenda.php', 'textoBotao' => 'Limpar Agenda', 'is_delegavel' => false, 'itens' => $itens
        ];
    }

    // 5. REQUISIÇÕES DE ESTOQUE AGUARDANDO AUTORIZAÇÃO
    $stReqPend = $pdo->prepare("SELECT t.id, t.nome_produto, t.quantidade, i.nome_unidade, i.nome_fantasia
                                FROM transferencias_estoque t
                                JOIN instituicoes i ON i.id = t.destino_id
                                WHERE t.origem_id = ? AND t.status = 'em_transito'
                                ORDER BY t.data_transferencia ASC");
    $stReqPend->execute([$id_loja]);
    $reqs_pend = $stReqPend->fetchAll(PDO::FETCH_ASSOC);

    if (count($reqs_pend) > 0) {
        $itens = [];
        foreach($reqs_pend as $rp) {
            $lojaNome = htmlspecialchars($rp['nome_unidade'] ?: $rp['nome_fantasia']);
            $itens[] = "<strong>{$rp['quantidade']} un. de " . htmlspecialchars($rp['nome_produto']) . "</strong> solicitadas por $lojaNome";
        }
        $alertas[] = [
            'tipo' => 'info', 'corHex' => '#3498db', 'corFundo' => '#ebf5fb', 'icone' => 'fa-truck-loading',
            'titulo' => 'Requisições de Estoque',
            'resumo' => count($reqs_pend) . ' pedido(s) aguardando sua autorização de envio.',
            'descricao' => 'Outra unidade da rede solicitou produtos do seu estoque. Entre em contato e autorize ou negue o envio.',
            'link' => 'transferencia_estoque.php#tab-recebidas', 'textoBotao' => 'Avaliar Pedidos',
            'is_delegavel' => false, 'itens' => $itens
        ];
    }

    echo json_encode($alertas);
} catch (Exception $e) { echo json_encode([]); }
?>