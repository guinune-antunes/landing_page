<?php
// ══════════════════════════════════════════════════════════════════
// transferencia_estoque.php — Gestão de Requisições & Transferências com Aprovação (ERP Style)
// ══════════════════════════════════════════════════════════════════
require_once 'includes/verifica_login.php';
verificarPermissao(['Gerente', 'Admin']);
require_once 'conexao.php';
require_once 'includes/unidades.php';

$usuarioId = (int)$_SESSION['usuario_id'];
$origemId  = (int)$_SESSION['instituicao_id'];

$unidades = carregarUnidadesUsuario($pdo, $usuarioId);
$destinos = array_filter($unidades, fn($u, $id) => $id !== $origemId
        && !empty($u['rede_id']) && (int)$u['rede_id'] === (int)($unidades[$origemId]['rede_id'] ?? 0),
        ARRAY_FILTER_USE_BOTH);

if (!$destinos) {
    header('Location: dashboard_gerente.php');
    exit;
}

$redeId = (int)($unidades[$origemId]['rede_id'] ?? 0);
$gestorAtivo   = obterGestorUnidade($pdo, $origemId);
$souGestor     = usuarioEhGestorUnidade($pdo, $usuarioId, $origemId);
$isGerenteGeral= usuarioEhGerenteGeral($pdo, $usuarioId, $redeId);

// 1. Requisições recebidas pendentes de envio (onde a loja atual é a fornecedora/origem)
$stPend = $pdo->prepare("SELECT t.*, uo.nome_fantasia AS o_nome, uo.nome_unidade AS o_un, uo.cidade AS o_cid, uo.uf AS o_uf,
                                ud.nome_fantasia AS d_nome, ud.nome_unidade AS d_un, ud.cidade AS d_cid, ud.uf AS d_uf,
                                us.nome AS solicitante_nome, us.email AS solicitante_email
                         FROM transferencias_estoque t
                         JOIN instituicoes uo ON uo.id = t.origem_id
                         JOIN instituicoes ud ON ud.id = t.destino_id
                         LEFT JOIN usuarios us ON us.id = t.usuario_id
                         WHERE t.origem_id = ? AND t.status IN ('em_transito', 'aguardando_aprovacao_superior')
                         ORDER BY t.data_transferencia ASC");
$stPend->execute([$origemId]);
$requisicoesRecebidas = $stPend->fetchAll();
$totalRecebidasPendentes = count($requisicoesRecebidas);

// Requisições adicionais da rede aguardando Gerente Geral (se o usuário for Gerente Geral)
$requisicoesRedeGeral = [];
if ($isGerenteGeral && $redeId) {
    $stRede = $pdo->prepare("SELECT t.*, uo.nome_fantasia AS o_nome, uo.nome_unidade AS o_un, uo.cidade AS o_cid, uo.uf AS o_uf,
                                    ud.nome_fantasia AS d_nome, ud.nome_unidade AS d_un, ud.cidade AS d_cid, ud.uf AS d_uf,
                                    us.nome AS solicitante_nome, us.email AS solicitante_email
                             FROM transferencias_estoque t
                             JOIN instituicoes uo ON uo.id = t.origem_id
                             JOIN instituicoes ud ON ud.id = t.destino_id
                             LEFT JOIN usuarios us ON us.id = t.usuario_id
                             WHERE t.rede_id = ? AND t.origem_id != ? AND t.status = 'aguardando_aprovacao_superior'
                             ORDER BY t.data_transferencia ASC");
    $stRede->execute([$redeId, $origemId]);
    $requisicoesRedeGeral = $stRede->fetchAll();
}

// 2. Minhas requisições enviadas para outras lojas (onde a loja atual é solicitante/destino)
$stEnv = $pdo->prepare("SELECT t.*, uo.nome_fantasia AS o_nome, uo.nome_unidade AS o_un, uo.cidade AS o_cid, uo.uf AS o_uf,
                               ud.nome_fantasia AS d_nome, ud.nome_unidade AS d_un, ud.cidade AS d_cid, ud.uf AS d_uf,
                               ua.nome AS aprovador_nome
                        FROM transferencias_estoque t
                        JOIN instituicoes uo ON uo.id = t.origem_id
                        JOIN instituicoes ud ON ud.id = t.destino_id
                        LEFT JOIN usuarios ua ON ua.id = t.aprovador_id
                        WHERE t.destino_id = ?
                        ORDER BY t.data_transferencia DESC LIMIT 40");
$stEnv->execute([$origemId]);
$minhasRequisicoes = $stEnv->fetchAll();

// 3. Histórico recente de transferências
$stHist = $pdo->prepare("SELECT t.*, uo.nome_fantasia AS o_nome, uo.nome_unidade AS o_un, uo.cidade AS o_cid, uo.uf AS o_uf,
                                ud.nome_fantasia AS d_nome, ud.nome_unidade AS d_un, ud.cidade AS d_cid, ud.uf AS d_uf,
                                us.nome AS solicitante_nome, ua.nome AS aprovador_nome
                         FROM transferencias_estoque t
                         JOIN instituicoes uo ON uo.id = t.origem_id
                         JOIN instituicoes ud ON ud.id = t.destino_id
                         LEFT JOIN usuarios us ON us.id = t.usuario_id
                         LEFT JOIN usuarios ua ON ua.id = t.aprovador_id
                         WHERE t.origem_id = ? OR t.destino_id = ?
                         ORDER BY t.data_transferencia DESC, t.id DESC LIMIT 50");
$stHist->execute([$origemId, $origemId]);
$historicoGeral = $stHist->fetchAll();

$rot = fn($p, $r) => unidadeRotulo([
    'nome_fantasia' => $r[$p . '_nome'], 'nome_unidade' => $r[$p . '_un'],
    'cidade' => $r[$p . '_cid'], 'uf' => $r[$p . '_uf'],
]);

$pageTitle = 'Transferência & Requisições de Estoque';
$paginaAtiva = 'transferencia';
include 'includes/_head.php';
include 'includes/_sidebar.php';
?>

<style>
/* Abas de navegação interna */
.transfer-tabs {
    display: flex;
    gap: 10px;
    border-bottom: 2px solid var(--border-color);
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.transfer-tab-btn {
    padding: 10px 18px;
    border: none;
    background: transparent;
    font-size: 0.95rem;
    font-weight: 600;
    color: var(--text-muted);
    cursor: pointer;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
}
.transfer-tab-btn:hover {
    color: var(--color-primary);
}
.transfer-tab-btn.active {
    color: var(--color-primary);
    border-bottom-color: var(--color-primary);
}
.badge-count {
    background: #e74c3c;
    color: white;
    padding: 2px 7px;
    border-radius: 10px;
    font-size: 0.72rem;
    font-weight: bold;
}

/* Badges de Status Estilo ERP */
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.78rem;
    font-weight: 600;
    white-space: nowrap;
}
.status-pill.em_transito { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
.status-pill.superior    { background: #ede9fe; color: #6d28d9; border: 1px solid #ddd6fe; }
.status-pill.recebida    { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
.status-pill.negada      { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
.status-pill.cancelada   { background: #e2e3e5; color: #383d41; border: 1px solid #d6d8db; }

.dark-theme .status-pill.em_transito { background: rgba(245,158,11,0.2); color: #fbbf24; border-color: rgba(245,158,11,0.3); }
.dark-theme .status-pill.superior    { background: rgba(147,51,234,0.2); color: #c084fc; border-color: rgba(147,51,234,0.4); }
.dark-theme .status-pill.recebida    { background: rgba(46,204,113,0.2); color: #4ade80; border-color: rgba(46,204,113,0.3); }
.dark-theme .status-pill.negada      { background: rgba(231,76,60,0.2);  color: #f87171; border-color: rgba(231,76,60,0.3); }
.dark-theme .status-pill.cancelada   { background: rgba(148,163,184,0.2);color: #cbd5e1; border-color: rgba(148,163,184,0.3); }

/* Timeline de Auditoria (Audit Trail) */
.audit-timeline {
    position: relative;
    padding-left: 32px;
    margin: 15px 0;
}
.audit-timeline::before {
    content: '';
    position: absolute;
    left: 11px;
    top: 6px;
    bottom: 6px;
    width: 2px;
    background: #cbd5e1;
}
.dark-theme .audit-timeline::before {
    background: #334155;
}
.audit-step {
    position: relative;
    margin-bottom: 22px;
}
.audit-dot {
    position: absolute;
    left: -32px;
    top: 2px;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: #1abc9c;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.72rem;
    box-shadow: 0 0 0 3px white;
}
.dark-theme .audit-dot {
    box-shadow: 0 0 0 3px #131f37;
}
.audit-step.solicitacao_criada .audit-dot { background: #3b82f6; }
.audit-step.aprovacao_superior .audit-dot { background: #7c3aed; }
.audit-step.aprovacao_concluida .audit-dot { background: #10b981; }
.audit-step.requisicao_negada .audit-dot { background: #ef4444; }
.audit-step.requisicao_cancelada .audit-dot { background: #64748b; }

.audit-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 10px 14px;
}
.dark-theme .audit-card {
    background: #0f172a;
    border-color: #1e293b;
}
</style>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; ?>

        <main class="main-content">
            <div class="page-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px;">
                <div>
                    <h1 class="main-title" style="margin:0;">Transferência & Requisições de Estoque</h1>
                    <p style="color:#777; margin-top:4px;">
                        Unidade Ativa: <strong><?php echo htmlspecialchars($unidades[$origemId]['rotulo']); ?></strong>
                        <?php if ($gestorAtivo): ?>
                            · Gestor Responsável: <span style="color:var(--color-primary); font-weight:600;"><i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($gestorAtivo['nome']); ?></span>
                            <?php if (!empty($gestorAtivo['esta_ausente']) && !empty($gestorAtivo['substituto'])): ?>
                                <span style="background:#fef3c7; color:#92400e; padding:2px 8px; border-radius:10px; font-size:0.75rem; margin-left:4px;">
                                    <i class="fas fa-umbrella-beach"></i> Substituto ativo: <?php echo htmlspecialchars($gestorAtivo['substituto']['nome']); ?>
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($isGerenteGeral): ?>
                            <span style="background:#e8f4fd; color:#2980b9; padding:2px 8px; border-radius:12px; font-size:0.75rem; font-weight:600; margin-left:6px;">
                                <i class="fas fa-crown"></i> Gerente Geral
                            </span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <!-- TABS DE NAVEGAÇÃO -->
            <div class="transfer-tabs">
                <button type="button" class="transfer-tab-btn active" data-tab="tab-solicitar">
                    <i class="fas fa-plus-circle"></i> Fazer Requisição (Solicitar)
                </button>
                <button type="button" class="transfer-tab-btn" data-tab="tab-recebidas">
                    <i class="fas fa-inbox"></i> Requisições Recebidas (Para Autorizar)
                    <?php if (($totalRecebidasPendentes + count($requisicoesRedeGeral)) > 0): ?>
                        <span class="badge-count"><?php echo ($totalRecebidasPendentes + count($requisicoesRedeGeral)); ?></span>
                    <?php endif; ?>
                </button>
                <button type="button" class="transfer-tab-btn" data-tab="tab-enviadas">
                    <i class="fas fa-paper-plane"></i> Minhas Requisições Enviadas
                </button>
                <button type="button" class="transfer-tab-btn" data-tab="tab-historico">
                    <i class="fas fa-history"></i> Histórico & Auditoria
                </button>
            </div>

            <!-- ══════════════════════════════════════════════════════════════ -->
            <!-- ABA 1: FAZER REQUISIÇÃO (SOLICITAR ITENS DE OUTRA UNIDADE) -->
            <!-- ══════════════════════════════════════════════════════════════ -->
            <div class="tab-pane active" id="tab-solicitar">
                <div class="widget-card">
                    <h3 style="margin-bottom:8px; display:flex; align-items:center; gap:8px;">
                        <i class="fas fa-cart-plus" style="color:var(--color-primary);"></i> Solicitar Produtos de Outra Unidade
                    </h3>
                    <p style="font-size:0.88rem; color:#777; margin-bottom:20px;">
                        Sua unidade (<strong><?php echo htmlspecialchars($unidades[$origemId]['rotulo']); ?></strong>) receberá os itens.
                        Selecione a unidade fornecedora para consultar os produtos e seus saldos em tempo real.
                    </p>

                    <form id="form-requisicao">
                        <div class="form-group-row">
                            <!-- 1. Seleciona a loja fornecedora -->
                            <div class="form-group" style="flex:1.2;">
                                <label for="req-origem" style="font-weight:600;">1. Unidade Fornecedora (Origem) *</label>
                                <select id="req-origem" required class="form-control" style="width:100%; padding:10px; border-radius:6px;">
                                    <option value="">Selecione de onde virá o produto...</option>
                                    <?php foreach ($destinos as $id => $u): ?>
                                        <option value="<?php echo (int)$id; ?>"><?php echo htmlspecialchars($u['rotulo']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- 2. Seleciona o produto consultado -->
                            <div class="form-group" style="flex:2;">
                                <label for="req-produto" style="font-weight:600;">
                                    2. Produto com Saldo na Loja Fornecedora *
                                </label>
                                <select id="req-produto" required disabled class="form-control" style="width:100%; padding:10px; border-radius:6px;">
                                    <option value="">Selecione primeiro a unidade fornecedora...</option>
                                </select>
                                <small id="req-saldo-info" style="color:var(--color-primary); font-weight:600; display:block; margin-top:4px;"></small>
                            </div>

                            <!-- 3. Quantidade solicitada -->
                            <div class="form-group" style="flex:0.8;">
                                <label for="req-qtd" style="font-weight:600;">3. Qtd a Solicitar *</label>
                                <input type="number" id="req-qtd" min="1" step="1" required disabled class="form-control" style="width:100%; padding:10px; border-radius:6px;">
                            </div>
                        </div>

                        <div class="form-group" style="margin-top:10px;">
                            <label for="req-obs" style="font-weight:600;">Observação / Motivo da Requisição</label>
                            <input type="text" id="req-obs" maxlength="255" placeholder="Ex: Reposição para agendamentos deste fim de semana" class="form-control" style="width:100%; padding:10px; border-radius:6px;">
                        </div>

                        <div style="margin-top:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                            <div style="font-size:0.82rem; color:#777; max-width:65%;">
                                <i class="fas fa-shield-alt" style="color:#1abc9c;"></i> Validação automática de alçadas: pedidos de alto valor ou medicamentos controlados são submetidos para aprovação superior.
                            </div>
                            <button type="submit" class="btn btn-primary" id="btn-enviar-req" disabled style="padding:10px 22px; font-size:0.95rem;">
                                <i class="fas fa-paper-plane"></i> Enviar Requisição
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════════════ -->
            <!-- ABA 2: REQUISIÇÕES RECEBIDAS (PARA AUTORIZAR OU NEGAR) -->
            <!-- ══════════════════════════════════════════════════════════════ -->
            <div class="tab-pane" id="tab-recebidas" style="display:none;">
                
                <!-- SEÇÃO 1: REQUISIÇÕES DA REDE AGUARDANDO LIBERAÇÃO DO GERENTE GERAL -->
                <?php if ($isGerenteGeral && !empty($requisicoesRedeGeral)): ?>
                    <div class="widget-card" style="margin-bottom:20px; border-left:4px solid #7c3aed;">
                        <h3 style="margin-bottom:8px; display:flex; align-items:center; gap:8px; color:#6d28d9;">
                            <i class="fas fa-crown"></i> Requisições da Rede Aguardando Sua Liberação Superior
                        </h3>
                        <p style="font-size:0.88rem; color:#64748b; margin-bottom:15px;">
                            Transferências entre outras lojas da rede que ultrapassaram a alçada de valor da unidade ou envolvem produtos controlados.
                        </p>

                        <div style="overflow-x:auto;">
                            <table class="data-table" style="width:100%; border-collapse:collapse;">
                                <thead>
                                    <tr style="text-align:left; border-bottom:2px solid #eee;">
                                        <th style="padding:10px;">Data/Hora</th>
                                        <th style="padding:10px;">Origem ➔ Destino</th>
                                        <th style="padding:10px;">Produto & Qtd</th>
                                        <th style="padding:10px;">Valor Total</th>
                                        <th style="padding:10px;">Critério de Bloqueio</th>
                                        <th style="padding:10px; text-align:right;">Ação de Alçada</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($requisicoesRedeGeral as $req): ?>
                                        <tr style="border-bottom:1px solid #eee;">
                                            <td style="padding:10px; white-space:nowrap; font-size:0.85rem;">
                                                <?php echo date('d/m/Y H:i', strtotime($req['data_transferencia'])); ?>
                                            </td>
                                            <td style="padding:10px;">
                                                <small style="color:#64748b; display:block;">De: <strong><?php echo htmlspecialchars($rot('o', $req)); ?></strong></small>
                                                <small style="color:#0284c7; display:block;">Para: <strong><?php echo htmlspecialchars($rot('d', $req)); ?></strong></small>
                                            </td>
                                            <td style="padding:10px;">
                                                <strong><?php echo htmlspecialchars($req['nome_produto']); ?></strong><br>
                                                <span style="font-weight:700; color:#e74c3c;"><?php echo (int)$req['quantidade']; ?> un.</span>
                                            </td>
                                            <td style="padding:10px; font-weight:700; color:#1e293b;">
                                                R$ <?php echo number_format((float)($req['valor_total'] ?? 0), 2, ',', '.'); ?>
                                            </td>
                                            <td style="padding:10px;">
                                                <span class="status-pill superior"><i class="fas fa-lock"></i> Alçada Superior</span>
                                                <small style="display:block; color:#7c3aed; font-size:0.75rem; margin-top:2px;">
                                                    <?php echo htmlspecialchars($req['motivo_superior'] ?? 'Alçada de valor ou produto controlado'); ?>
                                                </small>
                                            </td>
                                            <td style="padding:10px; text-align:right; white-space:nowrap;">
                                                <button type="button" class="btn btn-sm btn-primary btn-autorizar-envio"
                                                        data-id="<?php echo (int)$req['id']; ?>"
                                                        data-prod="<?php echo htmlspecialchars($req['nome_produto']); ?>"
                                                        data-qtd="<?php echo (int)$req['quantidade']; ?>"
                                                        data-dest="<?php echo htmlspecialchars($rot('d', $req)); ?>"
                                                        style="background:#7c3aed; border-color:#7c3aed; margin-right:4px;">
                                                    <i class="fas fa-crown"></i> Liberar Alçada
                                                </button>
                                                <button type="button" class="btn btn-sm btn-danger btn-abrir-negar"
                                                        data-id="<?php echo (int)$req['id']; ?>"
                                                        data-prod="<?php echo htmlspecialchars($req['nome_produto']); ?>"
                                                        data-qtd="<?php echo (int)$req['quantidade']; ?>"
                                                        data-dest="<?php echo htmlspecialchars($rot('d', $req)); ?>"
                                                        style="margin-right:4px;">
                                                    <i class="fas fa-times"></i> Negar
                                                </button>
                                                <button type="button" class="btn btn-sm btn-secondary btn-ver-timeline"
                                                        data-id="<?php echo (int)$req['id']; ?>" title="Ver Linha do Tempo e Auditoria">
                                                    <i class="fas fa-route"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- SEÇÃO 2: REQUISIÇÕES SOLICITADAS À SUA UNIDADE -->
                <div class="widget-card">
                    <h3 style="margin-bottom:8px; display:flex; align-items:center; gap:8px;">
                        <i class="fas fa-inbox" style="color:#f39c12;"></i> Requisições Solicitadas à Sua Unidade
                    </h3>
                    <p style="font-size:0.88rem; color:#777; margin-bottom:15px;">
                        Outras lojas da rede solicitaram itens do seu estoque. Você pode autorizar o envio ou negar com justificativa plausível.
                    </p>

                    <?php if (empty($requisicoesRecebidas)): ?>
                        <div style="padding:30px; text-align:center; color:#777; background:#f8fafc; border:1px dashed var(--border-color); border-radius:8px;">
                            <i class="fas fa-check-circle" style="font-size:2rem; color:#2ecc71; margin-bottom:8px; display:block;"></i>
                            Nenhuma requisição pendente no momento para a sua unidade.
                        </div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table class="data-table" style="width:100%; border-collapse:collapse;">
                                <thead>
                                    <tr style="text-align:left; border-bottom:2px solid #eee;">
                                        <th style="padding:10px;">Data/Hora</th>
                                        <th style="padding:10px;">Unidade Solicitante</th>
                                        <th style="padding:10px;">Solicitante</th>
                                        <th style="padding:10px;">Produto & Qtd</th>
                                        <th style="padding:10px;">Status / Alçada</th>
                                        <th style="padding:10px;">Observação</th>
                                        <th style="padding:10px; text-align:right;">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($requisicoesRecebidas as $req): 
                                        $ehSuperior = ($req['status'] === 'aguardando_aprovacao_superior');
                                        $podeAprovar = $souGestor && (!$ehSuperior || $isGerenteGeral);
                                    ?>
                                        <tr style="border-bottom:1px solid #eee;">
                                            <td style="padding:10px; white-space:nowrap; font-size:0.85rem;">
                                                <?php echo date('d/m/Y H:i', strtotime($req['data_transferencia'])); ?>
                                            </td>
                                            <td style="padding:10px;">
                                                <strong><?php echo htmlspecialchars($rot('d', $req)); ?></strong>
                                            </td>
                                            <td style="padding:10px;">
                                                <?php echo htmlspecialchars($req['solicitante_nome'] ?? '—'); ?><br>
                                                <small style="color:#777;"><?php echo htmlspecialchars($req['solicitante_email'] ?? ''); ?></small>
                                            </td>
                                            <td style="padding:10px;">
                                                <strong style="color:var(--text-dark);"><?php echo htmlspecialchars($req['nome_produto']); ?></strong><br>
                                                <span style="font-weight:700; color:#e74c3c;">Qtd retida: <?php echo (int)$req['quantidade']; ?> un.</span>
                                                <?php if (!empty($req['valor_total'])): ?>
                                                    <small style="color:#64748b; display:block;">(R$ <?php echo number_format((float)$req['valor_total'], 2, ',', '.'); ?>)</small>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:10px;">
                                                <?php if ($ehSuperior): ?>
                                                    <span class="status-pill superior"><i class="fas fa-lock"></i> Alçada Superior</span>
                                                    <small style="display:block; color:#7c3aed; font-size:0.75rem; margin-top:2px;">
                                                        <?php echo htmlspecialchars($req['motivo_superior'] ?? 'Aguardando Gerente Geral'); ?>
                                                    </small>
                                                <?php else: ?>
                                                    <span class="status-pill em_transito"><i class="fas fa-clock"></i> Pendente Loja</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:10px; color:#666; font-size:0.85rem;">
                                                <?php echo htmlspecialchars($req['observacao'] ?: 'Sem observação'); ?>
                                            </td>
                                            <td style="padding:10px; text-align:right; white-space:nowrap;">
                                                <?php if ($podeAprovar): ?>
                                                    <button type="button" class="btn btn-sm btn-success btn-autorizar-envio"
                                                            data-id="<?php echo (int)$req['id']; ?>"
                                                            data-prod="<?php echo htmlspecialchars($req['nome_produto']); ?>"
                                                            data-qtd="<?php echo (int)$req['quantidade']; ?>"
                                                            data-dest="<?php echo htmlspecialchars($rot('d', $req)); ?>"
                                                            style="margin-right:4px;">
                                                        <i class="fas fa-check"></i> <?php echo $ehSuperior ? 'Liberar Superior' : 'Autorizar Envio'; ?>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-danger btn-abrir-negar"
                                                            data-id="<?php echo (int)$req['id']; ?>"
                                                            data-prod="<?php echo htmlspecialchars($req['nome_produto']); ?>"
                                                            data-qtd="<?php echo (int)$req['quantidade']; ?>"
                                                            data-dest="<?php echo htmlspecialchars($rot('d', $req)); ?>"
                                                            style="margin-right:4px;">
                                                        <i class="fas fa-times"></i> Negar
                                                    </button>
                                                <?php else: ?>
                                                    <span style="color:#7c3aed; font-size:0.75rem; font-weight:600; margin-right:6px;">
                                                        <i class="fas fa-lock"></i> Requer Gerente Geral
                                                    </span>
                                                <?php endif; ?>

                                                <button type="button" class="btn btn-sm btn-secondary btn-ver-timeline"
                                                        data-id="<?php echo (int)$req['id']; ?>" title="Ver Linha do Tempo e Auditoria">
                                                    <i class="fas fa-route"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════════════ -->
            <!-- ABA 3: MINHAS REQUISIÇÕES ENVIADAS -->
            <!-- ══════════════════════════════════════════════════════════════ -->
            <div class="tab-pane" id="tab-enviadas" style="display:none;">
                <div class="widget-card">
                    <h3 style="margin-bottom:8px; display:flex; align-items:center; gap:8px;">
                        <i class="fas fa-paper-plane" style="color:#3498db;"></i> Requisições Solicitadas por Esta Loja
                    </h3>
                    <p style="font-size:0.88rem; color:#777; margin-bottom:15px;">
                        Acompanhe o andamento dos pedidos que sua loja fez para outras unidades da rede.
                    </p>

                    <?php if (empty($minhasRequisicoes)): ?>
                        <div style="padding:25px; text-align:center; color:#777;">
                            Nenhuma requisição enviada por esta loja ainda.
                        </div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table class="data-table" style="width:100%; border-collapse:collapse;">
                                <thead>
                                    <tr style="text-align:left; border-bottom:2px solid #eee;">
                                        <th style="padding:10px;">Data</th>
                                        <th style="padding:10px;">Fornecedor (Origem)</th>
                                        <th style="padding:10px;">Produto</th>
                                        <th style="padding:10px;">Qtd</th>
                                        <th style="padding:10px;">Status</th>
                                        <th style="padding:10px;">Detalhes / Resposta</th>
                                        <th style="padding:10px; text-align:right;">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($minhasRequisicoes as $env):
                                        $st = $env['status'] ?: 'recebida';
                                    ?>
                                        <tr style="border-bottom:1px solid #eee;">
                                            <td style="padding:10px; white-space:nowrap; font-size:0.85rem;">
                                                <?php echo date('d/m/Y H:i', strtotime($env['data_transferencia'])); ?>
                                            </td>
                                            <td style="padding:10px;">
                                                <strong><?php echo htmlspecialchars($rot('o', $env)); ?></strong>
                                            </td>
                                            <td style="padding:10px;">
                                                <strong><?php echo htmlspecialchars($env['nome_produto']); ?></strong>
                                            </td>
                                            <td style="padding:10px; font-weight:700; color:#2980b9;">
                                                <?php echo (int)$env['quantidade']; ?> un.
                                            </td>
                                            <td style="padding:10px;">
                                                <?php if ($st === 'em_transito'): ?>
                                                    <span class="status-pill em_transito"><i class="fas fa-clock"></i> Em Trânsito</span>
                                                <?php elseif ($st === 'aguardando_aprovacao_superior'): ?>
                                                    <span class="status-pill superior"><i class="fas fa-shield-alt"></i> Liberação Superior</span>
                                                <?php elseif ($st === 'recebida'): ?>
                                                    <span class="status-pill recebida"><i class="fas fa-check-circle"></i> Concluída</span>
                                                <?php elseif ($st === 'negada'): ?>
                                                    <span class="status-pill negada"><i class="fas fa-ban"></i> Negada</span>
                                                <?php elseif ($st === 'cancelada'): ?>
                                                    <span class="status-pill cancelada"><i class="fas fa-times-circle"></i> Cancelada</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:10px; font-size:0.85rem;">
                                                <?php if ($st === 'negada' && !empty($env['justificativa'])): ?>
                                                    <div style="background:#fdf2f0; color:#c0392b; border:1px solid #fadbd8; padding:4px 8px; border-radius:4px;">
                                                        <strong>Recusa:</strong> <?php echo htmlspecialchars($env['justificativa']); ?>
                                                    </div>
                                                <?php elseif ($st === 'aguardando_aprovacao_superior'): ?>
                                                    <span style="color:#7c3aed; font-size:0.75rem;">
                                                        <i class="fas fa-hourglass-half"></i> <?php echo htmlspecialchars($env['motivo_superior'] ?? 'Aguardando Gerência Geral'); ?>
                                                    </span>
                                                <?php elseif ($st === 'recebida' && !empty($env['aprovador_nome'])): ?>
                                                    <span style="color:#27ae60;"><i class="fas fa-check"></i> Autorizado por <?php echo htmlspecialchars($env['aprovador_nome']); ?></span>
                                                <?php elseif ($st === 'cancelada'): ?>
                                                    <span style="color:#777;">Cancelado pelo solicitante</span>
                                                <?php else: ?>
                                                    <span style="color:#777;">Aguardando aprovação</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:10px; text-align:right; white-space:nowrap;">
                                                <?php if (in_array($st, ['em_transito', 'aguardando_aprovacao_superior'])): ?>
                                                    <button type="button" class="btn btn-sm btn-secondary btn-cancelar-req"
                                                            data-id="<?php echo (int)$env['id']; ?>"
                                                            data-prod="<?php echo htmlspecialchars($env['nome_produto']); ?>"
                                                            title="Cancelar requisição e liberar estoque" style="margin-right:4px;">
                                                        <i class="fas fa-times"></i> Cancelar
                                                    </button>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-sm btn-secondary btn-ver-timeline"
                                                        data-id="<?php echo (int)$env['id']; ?>" title="Ver Linha do Tempo e Auditoria">
                                                    <i class="fas fa-route"></i> Timeline
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════════════ -->
            <!-- ABA 4: HISTÓRICO GERAL & AUDITORIA -->
            <!-- ══════════════════════════════════════════════════════════════ -->
            <div class="tab-pane" id="tab-historico" style="display:none;">
                <div class="widget-card">
                    <h3 style="margin-bottom:15px; display:flex; align-items:center; gap:8px;">
                        <i class="fas fa-history" style="color:#1abc9c;"></i> Histórico de Transferências & Rastreabilidade
                    </h3>
                    <div style="overflow-x:auto;">
                        <table class="data-table" style="width:100%; border-collapse:collapse;">
                            <thead>
                                <tr style="text-align:left; border-bottom:2px solid #eee;">
                                    <th style="padding:10px;">Data</th>
                                    <th style="padding:10px;">Produto</th>
                                    <th style="padding:10px;">Qtd</th>
                                    <th style="padding:10px;">Movimento</th>
                                    <th style="padding:10px;">Status</th>
                                    <th style="padding:10px;">Solicitante</th>
                                    <th style="padding:10px;">Obs. / Justificativa</th>
                                    <th style="padding:10px; text-align:right;">Auditoria</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($historicoGeral as $h):
                                $saiu = ((int)$h['origem_id'] === $origemId);
                                $st   = $h['status'] ?: 'recebida';
                            ?>
                                <tr style="border-bottom:1px solid #eee;">
                                    <td style="padding:10px; white-space:nowrap; font-size:0.85rem;">
                                        <?php echo date('d/m/Y H:i', strtotime($h['data_transferencia'])); ?>
                                    </td>
                                    <td style="padding:10px;"><strong><?php echo htmlspecialchars($h['nome_produto']); ?></strong></td>
                                    <td style="padding:10px; font-weight:700; color:<?php echo $saiu ? '#e74c3c' : '#27ae60'; ?>;">
                                        <?php echo ($saiu ? '−' : '+') . (int)$h['quantidade']; ?>
                                    </td>
                                    <td style="padding:10px; font-size:0.85rem;">
                                        <?php if ($saiu): ?>
                                            <i class="fas fa-arrow-right" style="color:#e74c3c;"></i> Enviado para <strong><?php echo htmlspecialchars($rot('d', $h)); ?></strong>
                                        <?php else: ?>
                                            <i class="fas fa-arrow-left" style="color:#27ae60;"></i> Solicitado de <strong><?php echo htmlspecialchars($rot('o', $h)); ?></strong>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:10px;">
                                        <?php if ($st === 'em_transito'): ?>
                                            <span class="status-pill em_transito">Em Trânsito</span>
                                        <?php elseif ($st === 'aguardando_aprovacao_superior'): ?>
                                            <span class="status-pill superior">Alçada Superior</span>
                                        <?php elseif ($st === 'recebida'): ?>
                                            <span class="status-pill recebida">Concluída</span>
                                        <?php elseif ($st === 'negada'): ?>
                                            <span class="status-pill negada">Negada</span>
                                        <?php elseif ($st === 'cancelada'): ?>
                                            <span class="status-pill cancelada">Cancelada</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:10px; font-size:0.85rem;"><?php echo htmlspecialchars($h['solicitante_nome'] ?? '—'); ?></td>
                                    <td style="padding:10px; font-size:0.85rem; color:#777;">
                                        <?php
                                        if ($st === 'negada' && !empty($h['justificativa'])) {
                                            echo '<span style="color:#c0392b;"><strong>Recusa:</strong> ' . htmlspecialchars($h['justificativa']) . '</span>';
                                        } elseif (!empty($h['observacao'])) {
                                            echo htmlspecialchars($h['observacao']);
                                        } else {
                                            echo '—';
                                        }
                                        ?>
                                    </td>
                                    <td style="padding:10px; text-align:right;">
                                        <button type="button" class="btn btn-sm btn-secondary btn-ver-timeline"
                                                data-id="<?php echo (int)$h['id']; ?>" title="Ver Rastreabilidade e Linha do Tempo da Auditoria">
                                            <i class="fas fa-route"></i> Timeline
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$historicoGeral): ?>
                                <tr><td colspan="8" style="text-align:center; padding:20px; color:#777;">Nenhuma transferência registrada.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════ -->
<!-- MODAL PARA NEGAR REQUISIÇÃO (EXIGE JUSTIFICATIVA PLAUSÍVEL) -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-negar" style="display:none;">
    <div class="modal-content" style="max-width:500px;">
        <div class="modal-header">
            <h2 style="font-size:1.15rem; color:#c0392b; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-ban"></i> Negar Requisição de Estoque
            </h2>
            <button type="button" class="close-modal-btn" id="btn-fechar-modal-negar">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size:0.88rem; color:#64748b; margin-bottom:12px;">
                Você está prestes a recusar o envio de <strong id="negar-info-qtd-prod" style="color:#0f172a;"></strong> para <strong id="negar-info-dest" style="color:#0f172a;"></strong>.
            </p>
            <p style="font-size:0.85rem; color:#27ae60; background:#f0faf7; padding:8px 12px; border-radius:6px; margin-bottom:15px;">
                <i class="fas fa-undo"></i> A quantidade retida em trânsito será <strong>estornada imediatamente de volta</strong> ao saldo da loja de origem.
            </p>

            <form id="form-negar-req">
                <input type="hidden" id="negar-transf-id" value="">
                <div class="form-group">
                    <label for="negar-justificativa" style="font-weight:600; font-size:0.85rem; display:block; margin-bottom:6px;">
                        Justificativa Plausível da Recusa *
                    </label>
                    <textarea id="negar-justificativa" rows="3" required maxlength="255" class="form-control"
                              placeholder="Ex: Saldo reservado para clientes já agendados localmente; ou avaria de lote."
                              style="width:100%; padding:10px; border-radius:6px; resize:vertical;"></textarea>
                    <small style="color:#777; font-size:0.75rem;">A justificativa ficará gravada no Audit Trail e visível para o solicitante.</small>
                </div>
            </form>
        </div>
        <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:10px;">
            <button type="button" class="btn btn-secondary" id="btn-cancelar-negar">Voltar</button>
            <button type="button" class="btn btn-danger" id="btn-confirmar-negar">
                <i class="fas fa-ban"></i> Confirmar Recusa e Devolver Estoque
            </button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════ -->
<!-- MODAL DE AUDITORIA & LINHA DO TEMPO (AUDIT TRAIL ERP) -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-timeline" style="display:none;">
    <div class="modal-content" style="max-width:620px;">
        <div class="modal-header">
            <h2 style="font-size:1.15rem; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-route" style="color:#1abc9c;"></i> Rastreabilidade & Linha do Tempo (Audit Trail)
            </h2>
            <button type="button" class="close-modal-btn" id="btn-fechar-timeline">&times;</button>
        </div>
        <div class="modal-body">
            <!-- CABEÇALHO DO DOCUMENTO -->
            <div id="timeline-header-card" style="background:#f1f5f9; padding:12px 16px; border-radius:8px; margin-bottom:15px;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px;">
                    <div>
                        <strong id="tl-produto-nome" style="font-size:1.05rem; color:#0f172a; display:block;">—</strong>
                        <small id="tl-rota" style="color:#475569; display:block; margin-top:2px;">—</small>
                    </div>
                    <div id="tl-status-badge">
                        <span class="status-pill em_transito">—</span>
                    </div>
                </div>
                <div id="tl-motivo-superior" style="display:none; margin-top:10px; font-size:0.78rem; background:#ede9fe; color:#6d28d9; padding:4px 8px; border-radius:6px;">
                    <i class="fas fa-shield-alt"></i> <strong>Critério Superior:</strong> <span id="tl-motivo-texto"></span>
                </div>
            </div>

            <!-- TIMELINE VERTICAL -->
            <div id="timeline-events-container" class="audit-timeline">
                <div style="text-align:center; padding:20px; color:#94a3b8;">
                    <i class="fas fa-spinner fa-spin"></i> Carregando trilha de auditoria...
                </div>
            </div>
        </div>
        <div class="modal-footer" style="display:flex; justify-content:flex-end;">
            <button type="button" class="btn btn-secondary" id="btn-fechar-timeline-footer">Fechar</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ── 1. NAVEGAÇÃO POR ABAS ──
    const tabBtns = document.querySelectorAll('.transfer-tab-btn');
    const tabPanes = document.querySelectorAll('.tab-pane');

    tabBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            tabBtns.forEach(b => b.classList.remove('active'));
            tabPanes.forEach(p => p.style.display = 'none');

            this.classList.add('active');
            const targetId = this.dataset.tab;
            const targetPane = document.getElementById(targetId);
            if (targetPane) targetPane.style.display = 'block';
        });
    });

    if (window.location.hash) {
        const hash = window.location.hash.replace('#', '');
        const targetBtn = document.querySelector(`.transfer-tab-btn[data-tab="${hash}"]`);
        if (targetBtn) targetBtn.click();
    }

    // ── 2. CONSULTA DINÂMICA DE PRODUTOS NA LOJA FORNECEDORA ──
    const selOrigem = document.getElementById('req-origem');
    const selProd   = document.getElementById('req-produto');
    const inpQtd    = document.getElementById('req-qtd');
    const saldoInfo = document.getElementById('req-saldo-info');
    const btnEnviar = document.getElementById('btn-enviar-req');
    const formReq   = document.getElementById('form-requisicao');

    selOrigem?.addEventListener('change', function() {
        const fornecedorId = this.value;
        selProd.innerHTML = '<option value="">Carregando produtos da loja...</option>';
        selProd.disabled = true;
        inpQtd.disabled = true;
        inpQtd.value = '';
        saldoInfo.textContent = '';
        btnEnviar.disabled = true;

        if (!fornecedorId) {
            selProd.innerHTML = '<option value="">Selecione primeiro a unidade fornecedora...</option>';
            return;
        }

        fetch(`api_consultar_estoque_unidade.php?unidade_id=${encodeURIComponent(fornecedorId)}`)
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    alert(data.message || 'Erro ao consultar estoque.');
                    selProd.innerHTML = '<option value="">Erro ao carregar</option>';
                    return;
                }

                if (!data.produtos || data.produtos.length === 0) {
                    selProd.innerHTML = '<option value="">Nenhum produto com saldo disponível nesta loja</option>';
                    return;
                }

                selProd.innerHTML = '<option value="">Selecione o produto desejado...</option>';
                data.produtos.forEach(p => {
                    const opt = document.createElement('option');
                    opt.value = p.id;
                    opt.dataset.saldo = p.quantidade_estoque;
                    opt.dataset.nome = p.nome_produto;
                    opt.dataset.preco = p.preco_venda || 0;
                    opt.dataset.cat = p.categoria || 'Geral';
                    opt.textContent = `${p.nome_produto} (${p.categoria || 'Geral'} · Saldo: ${p.quantidade_estoque} un. · R$ ${parseFloat(p.preco_venda || 0).toFixed(2)})`;
                    selProd.appendChild(opt);
                });

                selProd.disabled = false;
            })
            .catch(() => {
                alert('Erro de comunicação ao consultar estoque da unidade fornecedora.');
                selProd.innerHTML = '<option value="">Erro de conexão</option>';
            });
    });

    selProd?.addEventListener('change', function() {
        const opt = this.selectedOptions[0];
        const saldo = opt?.dataset.saldo ? parseInt(opt.dataset.saldo) : 0;

        if (this.value && saldo > 0) {
            inpQtd.disabled = false;
            inpQtd.max = saldo;
            inpQtd.min = 1;
            if (!inpQtd.value || parseInt(inpQtd.value) > saldo) inpQtd.value = 1;
            saldoInfo.textContent = `✓ Saldo disponível na loja fornecedora: ${saldo} un.`;
            btnEnviar.disabled = false;
        } else {
            inpQtd.disabled = true;
            inpQtd.value = '';
            saldoInfo.textContent = '';
            btnEnviar.disabled = true;
        }
    });

    // ── 3. ENVIO DA REQUISIÇÃO ──
    formReq?.addEventListener('submit', function(e) {
        e.preventDefault();
        const fornecedorNome = selOrigem.selectedOptions[0]?.text || '';
        const prodNome       = selProd.selectedOptions[0]?.dataset.nome || '';
        const qtd            = parseInt(inpQtd.value);

        if (!confirm(`Confirmar envio de requisição de ${qtd} un. de "${prodNome}" para a unidade "${fornecedorNome}"?`)) {
            return;
        }

        btnEnviar.disabled = true;
        btnEnviar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...';

        fetch('api_transferir_estoque.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                acao: 'solicitar',
                origem_id: selOrigem.value,
                produto_id: selProd.value,
                quantidade: qtd,
                observacao: document.getElementById('req-obs').value
            })
        })
        .then(res => res.json())
        .then(res => {
            alert(res.message);
            if (res.success) {
                window.location.hash = 'tab-enviadas';
                location.reload();
            } else {
                btnEnviar.disabled = false;
                btnEnviar.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar Requisição';
            }
        })
        .catch(() => {
            alert('Erro de comunicação com o servidor.');
            btnEnviar.disabled = false;
            btnEnviar.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar Requisição';
        });
    });

    // ── 4. AUTORIZAR ENVIO (APROVAR) ──
    document.querySelectorAll('.btn-autorizar-envio').forEach(btn => {
        btn.addEventListener('click', function() {
            const id   = this.dataset.id;
            const prod = this.dataset.prod;
            const qtd  = this.dataset.qtd;
            const dest = this.dataset.dest;

            if (!confirm(`Autorizar o envio de ${qtd} un. de "${prod}" para a unidade "${dest}"?\n\nO item sairá da retenção e entrará no estoque de destino.`)) {
                return;
            }

            this.disabled = true;
            this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Autorizando...';

            fetch('api_transferir_estoque.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    acao: 'autorizar',
                    transferencia_id: id
                })
            })
            .then(res => res.json())
            .then(res => {
                alert(res.message);
                if (res.success) {
                    window.location.hash = 'tab-recebidas';
                    location.reload();
                } else {
                    this.disabled = false;
                    this.innerHTML = '<i class="fas fa-check"></i> Autorizar Envio';
                }
            })
            .catch(() => {
                alert('Erro de comunicação.');
                this.disabled = false;
                this.innerHTML = '<i class="fas fa-check"></i> Autorizar Envio';
            });
        });
    });

    // ── 5. MODAL NEGAR COM JUSTIFICATIVA ──
    const modalNegar = document.getElementById('modal-negar');
    const inpNegarId = document.getElementById('negar-transf-id');
    const txtJustif  = document.getElementById('negar-justificativa');
    const lblNegarQtd = document.getElementById('negar-info-qtd-prod');
    const lblNegarDest = document.getElementById('negar-info-dest');
    const btnConfNegar = document.getElementById('btn-confirmar-negar');

    const fecharModalNegar = () => { modalNegar.style.display = 'none'; };

    document.getElementById('btn-fechar-modal-negar')?.addEventListener('click', fecharModalNegar);
    document.getElementById('btn-cancelar-negar')?.addEventListener('click', fecharModalNegar);
    modalNegar?.addEventListener('click', (e) => { if (e.target === modalNegar) fecharModalNegar(); });

    document.querySelectorAll('.btn-abrir-negar').forEach(btn => {
        btn.addEventListener('click', function() {
            inpNegarId.value = this.dataset.id;
            lblNegarQtd.textContent = `${this.dataset.qtd} un. de "${this.dataset.prod}"`;
            lblNegarDest.textContent = this.dataset.dest;
            txtJustif.value = '';
            modalNegar.style.display = 'flex';
            txtJustif.focus();
        });
    });

    btnConfNegar?.addEventListener('click', function() {
        const just = txtJustif.value.trim();
        if (just.length < 4) {
            alert('Por favor, informe uma justificativa plausível para a recusa (mínimo de 4 caracteres).');
            txtJustif.focus();
            return;
        }

        btnConfNegar.disabled = true;
        btnConfNegar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Devolvendo estoque...';

        fetch('api_transferir_estoque.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                acao: 'negar',
                transferencia_id: inpNegarId.value,
                justificativa: just
            })
        })
        .then(res => res.json())
        .then(res => {
            alert(res.message);
            if (res.success) {
                window.location.hash = 'tab-recebidas';
                location.reload();
            } else {
                btnConfNegar.disabled = false;
                btnConfNegar.innerHTML = '<i class="fas fa-ban"></i> Confirmar Recusa';
            }
        })
        .catch(() => {
            alert('Erro de comunicação.');
            btnConfNegar.disabled = false;
            btnConfNegar.innerHTML = '<i class="fas fa-ban"></i> Confirmar Recusa';
        });
    });

    // ── 6. CANCELAR REQUISIÇÃO (SOLICITANTE) ──
    document.querySelectorAll('.btn-cancelar-req').forEach(btn => {
        btn.addEventListener('click', function() {
            const id   = this.dataset.id;
            const prod = this.dataset.prod;

            if (!confirm(`Deseja cancelar sua requisição de "${prod}"?\n\nO saldo será devolvido à unidade fornecedora.`)) {
                return;
            }

            this.disabled = true;

            fetch('api_transferir_estoque.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    acao: 'cancelar',
                    transferencia_id: id,
                    motivo: 'Cancelado pelo solicitante'
                })
            })
            .then(res => res.json())
            .then(res => {
                alert(res.message);
                if (res.success) {
                    window.location.hash = 'tab-enviadas';
                    location.reload();
                } else {
                    this.disabled = false;
                }
            })
            .catch(() => {
                alert('Erro de conexão.');
                this.disabled = false;
            });
        });
    });

    // ── 7. MODAL DE AUDITORIA & LINHA DO TEMPO (AUDIT TRAIL ERP) ──
    const modalTl = document.getElementById('modal-timeline');
    const tlContainer = document.getElementById('timeline-events-container');
    const tlProdNome = document.getElementById('tl-produto-nome');
    const tlRota = document.getElementById('tl-rota');
    const tlStatus = document.getElementById('tl-status-badge');
    const tlMotivoBox = document.getElementById('tl-motivo-superior');
    const tlMotivoTxt = document.getElementById('tl-motivo-texto');

    const fecharTimeline = () => { modalTl.style.display = 'none'; };
    document.getElementById('btn-fechar-timeline')?.addEventListener('click', fecharTimeline);
    document.getElementById('btn-fechar-timeline-footer')?.addEventListener('click', fecharTimeline);
    modalTl?.addEventListener('click', (e) => { if (e.target === modalTl) fecharTimeline(); });

    document.querySelectorAll('.btn-ver-timeline').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            modalTl.style.display = 'flex';
            tlContainer.innerHTML = '<div style="text-align:center; padding:20px; color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Carregando trilha de auditoria...</div>';
            tlMotivoBox.style.display = 'none';

            fetch(`api_timeline_transferencia.php?id=${encodeURIComponent(id)}`)
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        tlContainer.innerHTML = `<div style="color:#ef4444; padding:15px; text-align:center;">${data.message}</div>`;
                        return;
                    }

                    const tr = data.transferencia;
                    tlProdNome.textContent = `${tr.quantidade} un. de "${tr.nome_produto}"`;
                    tlRota.textContent = `Origem: ${tr.o_nome || tr.o_un} ➔ Destino: ${tr.d_nome || tr.d_un}`;
                    
                    let badgeClass = 'em_transito';
                    let badgeLabel = 'Em Trânsito';
                    if (tr.status === 'aguardando_aprovacao_superior') { badgeClass = 'superior'; badgeLabel = 'Liberação Superior'; }
                    else if (tr.status === 'recebida') { badgeClass = 'recebida'; badgeLabel = 'Concluída'; }
                    else if (tr.status === 'negada') { badgeClass = 'negada'; badgeLabel = 'Negada'; }
                    else if (tr.status === 'cancelada') { badgeClass = 'cancelada'; badgeLabel = 'Cancelada'; }
                    
                    tlStatus.innerHTML = `<span class="status-pill ${badgeClass}">${badgeLabel}</span>`;

                    if (tr.exige_superior && tr.motivo_superior) {
                        tlMotivoBox.style.display = 'block';
                        tlMotivoTxt.textContent = tr.motivo_superior;
                    }

                    // Renderiza passos
                    if (!data.eventos || data.eventos.length === 0) {
                        tlContainer.innerHTML = '<p style="color:#777; text-align:center;">Nenhum evento registrado.</p>';
                        return;
                    }

                    let html = '';
                    data.eventos.forEach(ev => {
                        let icon = 'fas fa-info';
                        let titulo = 'Ação Registrada';

                        if (ev.acao === 'solicitacao_criada') {
                            icon = 'fas fa-plus';
                            titulo = 'Requisição Criada';
                        } else if (ev.acao === 'aprovacao_superior') {
                            icon = 'fas fa-crown';
                            titulo = 'Aprovação Superior Concedida';
                        } else if (ev.acao === 'aprovacao_concluida') {
                            icon = 'fas fa-check-circle';
                            titulo = 'Envio Autorizado e Concluído';
                        } else if (ev.acao === 'requisicao_negada') {
                            icon = 'fas fa-ban';
                            titulo = 'Requisição Negada (Estorno)';
                        } else if (ev.acao === 'requisicao_cancelada') {
                            icon = 'fas fa-times';
                            titulo = 'Cancelamento Solicitado';
                        }

                        html += `
                            <div class="audit-step ${ev.acao}">
                                <div class="audit-dot"><i class="${icon}"></i></div>
                                <div class="audit-card">
                                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:5px; margin-bottom:4px;">
                                        <strong style="font-size:0.9rem; color:var(--text-dark);">${titulo}</strong>
                                        <small style="color:#64748b; font-size:0.75rem;"><i class="fas fa-clock"></i> ${ev.data_hora_fmt || ''}</small>
                                    </div>
                                    <div style="font-size:0.82rem; color:#475569; margin-bottom:4px;">
                                        Responsável: <strong>${ev.usuario_nome || 'Sistema'}</strong>
                                    </div>
                                    <div style="font-size:0.84rem; color:var(--text-dark); background:rgba(0,0,0,0.02); padding:6px 8px; border-radius:4px;">
                                        ${ev.detalhes || ''}
                                    </div>
                                </div>
                            </div>
                        `;
                    });

                    tlContainer.innerHTML = html;
                })
                .catch(() => {
                    tlContainer.innerHTML = '<div style="color:#ef4444; padding:15px; text-align:center;">Erro ao carregar auditoria.</div>';
                });
        });
    });
});
</script>

<?php include 'includes/_footer.php'; ?>
