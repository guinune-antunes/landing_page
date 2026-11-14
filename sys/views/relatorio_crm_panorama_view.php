<?php
/**
 * View do Panorama Geral no Relatório CRM 360° (incluída por sys/relatorio_crm.php quando nenhum cliente específico é selecionado).
 */
$vg = $panoramaDetalhes['vendas_gerais'] ?? [];
$promosAtivas = $panoramaDetalhes['promocoes_ativas'] ?? [];
$aq = $panoramaDetalhes['aquisicao_resumo'] ?? [];
$listaPri = $panoramaDetalhes['primeiras_compras_lista'] ?? [];
$listaCad = $panoramaDetalhes['novos_cadastros_lista'] ?? [];
$serie = $panoramaDetalhes['serie_temporal'] ?? ['labels' => [], 'fat_geral' => [], 'fat_promo' => [], 'qtd_primeira_compra' => [], 'qtd_novos_cadastros' => []];

$perIni = $panoramaPeriodo['inicio'] ?? date('Y-m-d', strtotime('-29 days'));
$perFim = $panoramaPeriodo['fim'] ?? date('Y-m-d');
$perDias = (int)($panoramaPeriodo['dias'] ?? 30);
$perIsDef = !empty($panoramaPeriodo['is_default']);
$multiUnidade = count($ids_clientes) > 1;

$hojeYmd = date('Y-m-d');
$urlPresetHoje = "relatorio_crm.php?data_inicio={$hojeYmd}&data_fim={$hojeYmd}";
$urlPreset7d = "relatorio_crm.php?data_inicio=" . date('Y-m-d', strtotime('-6 days')) . "&data_fim={$hojeYmd}";
$urlPresetMes = "relatorio_crm.php?data_inicio=" . date('Y-m-01') . "&data_fim={$hojeYmd}";
$urlPreset30d = "relatorio_crm.php";

$liftTicketPct = ($vg['ticket_medio_sem_promo'] ?? 0) > 0 && ($vg['qtd_com_promo'] ?? 0) > 0
    ? ((($vg['ticket_medio_promo'] / $vg['ticket_medio_sem_promo']) - 1.0) * 100.0)
    : 0.0;

$promoChartPayload = [
    'promoIds' => array_map(static fn($p) => (int)$p['id'], $promosAtivas),
    'labels' => array_map(static function ($p) {
        $t = (string)$p['titulo'];
        return mb_strlen($t) > 32 ? (mb_substr($t, 0, 29) . '...') : $t;
    }, $promosAtivas),
    'fullTitles' => array_map(static fn($p) => (string)$p['titulo'], $promosAtivas),
    'fatVendasPromo' => array_map(static fn($p) => round((float)$p['faturamento_vendas_promo'], 2), $promosAtivas),
    'receitaItensPromo' => array_map(static fn($p) => round((float)$p['receita_itens_promo'], 2), $promosAtivas),
    'descontoPromo' => array_map(static fn($p) => round((float)$p['desconto_concedido'], 2), $promosAtivas),
    'shareGeralPct' => array_map(static fn($p) => round((float)$p['share_vendas_geral_pct'], 2), $promosAtivas),
    'qtdUsos' => array_map(static fn($p) => (int)$p['qtd_usos'], $promosAtivas),
    'clientesUnicos' => array_map(static fn($p) => (int)$p['clientes_unicos'], $promosAtivas),
    'ticketMedioPromo' => array_map(static fn($p) => round((float)$p['ticket_medio_venda'], 2), $promosAtivas),
    'ticketMedioGeral' => round((float)($vg['ticket_medio_geral'] ?? 0), 2),
    'fatGeralTotal' => round((float)($vg['fat_geral'] ?? 0), 2),
    'perIni' => $perIni,
    'perFim' => $perFim,
];
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- BANNER DE PERÍODO ANALISADO + ATALHOS -->
<div class="crm-period-banner no-print">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <span class="crm-badge" style="background:rgba(26,188,156,0.16);color:#1abc9c;padding:5px 11px;">
            <i class="fas fa-calendar-alt"></i>
            <?php echo date('d/m/Y', strtotime($perIni)); ?> até <?php echo date('d/m/Y', strtotime($perFim)); ?>
            (<?php echo $perDias; ?> dia<?php echo $perDias > 1 ? 's' : ''; ?>)
        </span>
        <small style="color:var(--text-muted);font-weight:600;">
            <?php echo $perIsDef ? 'Janela padrão dos gráficos e aquisição: últimos 30 dias' : 'Filtro personalizado aplicado aos gráficos e listas'; ?>
        </small>
    </div>
    <div class="crm-period-presets">
        <a href="<?php echo $urlPresetHoje; ?>" class="crm-preset-link"><i class="fas fa-clock"></i> Hoje</a>
        <a href="<?php echo $urlPreset7d; ?>" class="crm-preset-link">7 dias</a>
        <a href="<?php echo $urlPresetMes; ?>" class="crm-preset-link">Este mês</a>
        <a href="<?php echo $urlPreset30d; ?>" class="crm-preset-link">30 dias</a>
    </div>
</div>

<!-- LINHA 1: KPIs DA CARTEIRA CRM -->
<div class="kpi-widgets" style="margin-bottom:16px;">
    <div class="widget-card kpi-card">
        <div class="card-icon blue"><i class="fas fa-users"></i></div>
        <div class="card-content">
            <span class="card-value"><?php echo number_format((int)$panoramaKpis['clientes_ativos'], 0, ',', '.'); ?></span>
            <span class="card-label">Clientes na Carteira CRM</span>
        </div>
    </div>
    <div class="widget-card kpi-card">
        <div class="card-icon green"><i class="fas fa-gift"></i></div>
        <div class="card-content">
            <span class="card-value"><?php echo number_format((int)$panoramaKpis['regras_prontas'], 0, ',', '.'); ?></span>
            <span class="card-label">Recompensas Modulares Prontas</span>
        </div>
    </div>
    <div class="widget-card kpi-card">
        <div class="card-icon purple"><i class="fas fa-wallet"></i></div>
        <div class="card-content">
            <span class="card-value">R$ <?php echo number_format((float)$panoramaKpis['cashback_circulante'], 2, ',', '.'); ?></span>
            <span class="card-label">Cashback Ativo nas Carteiras</span>
        </div>
    </div>
    <div class="widget-card kpi-card">
        <div class="card-icon" style="background:#fce7f3;color:#db2777;"><i class="fas fa-bolt"></i></div>
        <div class="card-content">
            <span class="card-value"><?php echo number_format((int)$panoramaKpis['promos_usadas_total'], 0, ',', '.'); ?></span>
            <span class="card-label">Usos de Promoções Relâmpago</span>
        </div>
    </div>
</div>

<!-- LINHA 2: KPIs CRUZADOS DO PERÍODO (VENDA GERAL × PROMOÇÕES × 1ª COMPRA × CADASTROS) -->
<div class="kpi-widgets" style="margin-bottom:22px;">
    <div class="widget-card kpi-card">
        <div class="card-icon green"><i class="fas fa-cash-register"></i></div>
        <div class="card-content">
            <span class="card-value">R$ <?php echo number_format((float)$vg['fat_geral'], 2, ',', '.'); ?></span>
            <span class="card-label">
                Venda Geral (<?php echo number_format((int)$vg['qtd_vendas'], 0, ',', '.'); ?> vendas · Ticket R$ <?php echo number_format((float)$vg['ticket_medio_geral'], 2, ',', '.'); ?>)
            </span>
        </div>
    </div>
    <div class="widget-card kpi-card">
        <div class="card-icon" style="background:#ffe4e6;color:#be123c;"><i class="fas fa-tags"></i></div>
        <div class="card-content">
            <span class="card-value">R$ <?php echo number_format((float)$vg['fat_com_promo'], 2, ',', '.'); ?></span>
            <span class="card-label">
                Vendas c/ Promoções (<?php echo number_format((float)$vg['share_promo_pct'], 1, ',', '.'); ?>% da Venda Geral · <?php echo (int)$vg['qtd_com_promo']; ?> pedidos)
            </span>
        </div>
    </div>
    <div class="widget-card kpi-card">
        <div class="card-icon blue"><i class="fas fa-shopping-bag"></i></div>
        <div class="card-content">
            <span class="card-value"><?php echo number_format((int)$aq['total_primeira_compra'], 0, ',', '.'); ?> clientes</span>
            <span class="card-label">
                Na 1ª Compra (<?php echo (int)$aq['cad_periodo_e_primeira_compra']; ?> c/ cadastro no período · Ticket R$ <?php echo number_format((float)$aq['ticket_medio_primeira'], 2, ',', '.'); ?>)
            </span>
        </div>
    </div>
    <div class="widget-card kpi-card">
        <div class="card-icon" style="background:#fef3c7;color:#d97706;"><i class="fas fa-user-plus"></i></div>
        <div class="card-content">
            <span class="card-value"><?php echo number_format((int)$aq['total_novos_cadastros'], 0, ',', '.'); ?> cadastros</span>
            <span class="card-label">
                Novos Cadastros (<?php echo (int)$aq['cadastros_que_compraram']; ?> compraram · <?php echo number_format((float)$aq['taxa_conversao_cad_pct'], 1, ',', '.'); ?>% conversão)
            </span>
        </div>
    </div>
</div>

<!-- SEÇÃO GRÁFICA: CADA PROMOÇÃO ATIVA × VENDA GERAL -->
<div class="crm-section-card">
    <div class="crm-section-title">
        <h3><i class="fas fa-chart-bar" style="color:#1abc9c;"></i> Promoções Ativas × Dados de Venda Geral</h3>
        <small style="color:var(--text-muted);">
            <?php echo count($promosAtivas); ?> promoção(ões) ativa(s) cruzadas contra R$ <?php echo number_format((float)$vg['fat_geral'], 2, ',', '.'); ?> em vendas gerais no período
        </small>
    </div>

    <div class="crm-cross-strip">
        <div class="crm-cross-mini">
            <small>Faturamento Geral (Base 100%)</small>
            <strong>R$ <?php echo number_format((float)$vg['fat_geral'], 2, ',', '.'); ?></strong>
            <div style="font-size:0.76rem;color:var(--text-muted);margin-top:2px;">
                <?php echo number_format((int)$vg['qtd_vendas'], 0, ',', '.'); ?> vendas · <?php echo number_format((int)$vg['clientes_compradores'], 0, ',', '.'); ?> clientes únicos
            </div>
        </div>
        <div class="crm-cross-mini">
            <small>Vendas Influenciadas por Promoções</small>
            <strong style="color:#ec4899;">R$ <?php echo number_format((float)$vg['fat_com_promo'], 2, ',', '.'); ?></strong>
            <div style="font-size:0.76rem;color:var(--text-muted);margin-top:2px;">
                <?php echo number_format((float)$vg['share_promo_pct'], 2, ',', '.'); ?>% da receita · Descontos: R$ <?php echo number_format((float)$vg['desc_promo_total'], 2, ',', '.'); ?>
            </div>
        </div>
        <div class="crm-cross-mini">
            <small>Demais Vendas (Sem Promoção)</small>
            <strong>R$ <?php echo number_format((float)$vg['fat_sem_promo'], 2, ',', '.'); ?></strong>
            <div style="font-size:0.76rem;color:var(--text-muted);margin-top:2px;">
                <?php echo number_format((int)$vg['qtd_sem_promo'], 0, ',', '.'); ?> vendas · Ticket R$ <?php echo number_format((float)$vg['ticket_medio_sem_promo'], 2, ',', '.'); ?>
            </div>
        </div>
        <div class="crm-cross-mini">
            <small>Ticket Médio: Com Promo vs Sem Promo</small>
            <strong style="color:#10b981;">R$ <?php echo number_format((float)$vg['ticket_medio_promo'], 2, ',', '.'); ?></strong>
            <div style="font-size:0.76rem;color:var(--text-muted);margin-top:2px;">
                <?php if ($vg['qtd_com_promo'] > 0 && $vg['ticket_medio_sem_promo'] > 0): ?>
                    <?php echo ($liftTicketPct >= 0 ? '+' : '') . number_format($liftTicketPct, 1, ',', '.'); ?>% no ticket médio da cesta
                <?php else: ?>
                    Ticket Geral: R$ <?php echo number_format((float)$vg['ticket_medio_geral'], 2, ',', '.'); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="crm-charts-grid">
        <div class="crm-chart-box">
            <div class="crm-chart-header">
                <div>
                    <h4>Cada Promoção Ativa × Venda Geral</h4>
                    <small style="color:var(--text-muted);">Receita gerada na cesta, itens em promo, desconto e % sobre vendas gerais</small>
                </div>
                <?php if (!empty($promosAtivas)): ?>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;" class="no-print">
                        <button type="button" class="crm-pill-filter active" data-promo-chart-mode="faturamento">R$ &amp; % Venda Geral</button>
                        <button type="button" class="crm-pill-filter" data-promo-chart-mode="ticket">Ticket × Usos</button>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (!empty($promosAtivas)): ?>
                <div class="crm-chart-wrap">
                    <canvas id="chartPromosVsVendas"></canvas>
                </div>
            <?php else: ?>
                <div style="padding:50px 16px;text-align:center;color:var(--text-muted);">
                    <i class="fas fa-percentage" style="font-size:2rem;opacity:0.45;margin-bottom:8px;"></i>
                    <p style="margin:0;">Nenhuma promoção ativa cadastrada nesta unidade.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="crm-chart-box">
            <div class="crm-chart-header">
                <div>
                    <h4>Evolução: Venda Geral × Promoções × 1ª Compra</h4>
                    <small style="color:var(--text-muted);">
                        Cruzamento <?php echo ($panoramaPeriodo['granularity'] === 'month') ? 'mensal' : 'diário'; ?> entre receita geral, vendas com promo e aquisição
                    </small>
                </div>
            </div>
            <div class="crm-chart-wrap">
                <canvas id="chartSerieCrmVendas"></canvas>
            </div>
        </div>
    </div>

    <!-- TABELA CRUZADA DE CADA PROMOÇÃO ATIVA -->
    <?php if (!empty($promosAtivas)): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:6px;" class="no-print">
            <small style="color:var(--text-muted);font-weight:600;">
                <i class="fas fa-mouse-pointer" style="color:#1abc9c;"></i> Clique em qualquer promoção abaixo (ou na barra do gráfico) para abrir as estatísticas individuais
            </small>
        </div>
        <div class="table-container" style="overflow-x:auto;">
            <table class="data-table" style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr>
                        <th>Promoção Ativa &amp; Política</th>
                        <th>Usos / Clientes</th>
                        <th>Itens Promo</th>
                        <th>Desconto Concedido</th>
                        <th>Vendas c/ a Promo (Total)</th>
                        <th>Ticket Médio (vs Geral)</th>
                        <th>ROI Desc.</th>
                        <th>% da Venda Geral</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($promosAtivas as $pa):
                        $polLabel = $labelsPoliticaPromo[$pa['politica_reentrada'] ?? 'ilimitada'] ?? $pa['politica_reentrada'];
                        $sharePct = (float)$pa['share_vendas_geral_pct'];
                        $barWidth = min(100, max($pa['faturamento_vendas_promo'] > 0 ? 3 : 0, $sharePct * 4));
                    ?>
                        <tr class="crm-promo-clickable"
                            data-promo-id="<?php echo (int)$pa['id']; ?>"
                            tabindex="0"
                            role="button"
                            title="Clique para ver estatísticas individuais desta promoção">
                            <td>
                                <strong><?php echo htmlspecialchars($pa['titulo']); ?></strong>
                                <span class="crm-promo-inspect-hint no-print"><i class="fas fa-chart-line"></i> Ver estatísticas</span>
                                <div style="font-size:0.76rem;color:var(--text-muted);margin-top:2px;">
                                    <span class="crm-badge crm-badge-oldcad"><?php echo htmlspecialchars($polLabel); ?></span>
                                    <?php if (!empty($pa['nome_produto'])): ?>
                                        · <?php echo htmlspecialchars($pa['nome_produto']); ?>
                                    <?php endif; ?>
                                    <?php if (!empty($pa['data_fim'])): ?>
                                        · Até <?php echo date('d/m/Y', strtotime($pa['data_fim'])); ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <strong><?php echo (int)$pa['qtd_usos']; ?> uso(s)</strong>
                                <div style="font-size:0.76rem;color:var(--text-muted);"><?php echo (int)$pa['clientes_unicos']; ?> cliente(s) únicos</div>
                            </td>
                            <td>
                                R$ <?php echo number_format((float)$pa['receita_itens_promo'], 2, ',', '.'); ?>
                                <div style="font-size:0.75rem;color:var(--text-muted);"><?php echo (int)$pa['itens_vendidos']; ?> item(ns)</div>
                            </td>
                            <td style="color:#ec4899;font-weight:700;">
                                -R$ <?php echo number_format((float)$pa['desconto_concedido'], 2, ',', '.'); ?>
                            </td>
                            <td>
                                <strong style="color:#10b981;">R$ <?php echo number_format((float)$pa['faturamento_vendas_promo'], 2, ',', '.'); ?></strong>
                                <div style="font-size:0.75rem;color:var(--text-muted);"><?php echo (int)$pa['vendas_com_promo']; ?> venda(s) PDV</div>
                            </td>
                            <td>
                                <?php if ($pa['vendas_com_promo'] > 0): ?>
                                    <strong>R$ <?php echo number_format((float)$pa['ticket_medio_venda'], 2, ',', '.'); ?></strong>
                                    <div style="font-size:0.75rem;color:var(--text-muted);">Geral: R$ <?php echo number_format((float)$vg['ticket_medio_geral'], 2, ',', '.'); ?></div>
                                <?php else: ?>
                                    <span style="color:var(--text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($pa['roi_desconto'] > 0): ?>
                                    <strong><?php echo number_format((float)$pa['roi_desconto'], 1, ',', '.'); ?>x</strong>
                                <?php else: ?>
                                    <span style="color:var(--text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo number_format($sharePct, 2, ',', '.'); ?>%</strong>
                                <div class="crm-share-track" title="<?php echo number_format($sharePct, 2, ',', '.'); ?>% do faturamento geral">
                                    <div class="crm-share-fill" style="width:<?php echo number_format($barWidth, 1, '.', ''); ?>%;"></div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- SEÇÃO AQUISIÇÃO: CLIENTES COMPRANDO PELA 1ª VEZ & NOVOS CADASTROS -->
<div class="crm-section-card" id="crm-secao-aquisicao">
    <div class="crm-section-title">
        <h3><i class="fas fa-user-check" style="color:#0ea5e9;"></i> Aquisição: Clientes na 1ª Compra &amp; Fizeram Cadastro</h3>
        <small style="color:var(--text-muted);">
            Exibindo até 40 registros mais relevantes por aba · Período <?php echo date('d/m/Y', strtotime($perIni)); ?> a <?php echo date('d/m/Y', strtotime($perFim)); ?>
        </small>
    </div>

    <div class="crm-cross-strip" style="margin-bottom:16px;">
        <div class="crm-cross-mini">
            <small>1ª Compra na Unidade (Total)</small>
            <strong><?php echo number_format((int)$aq['total_primeira_compra'], 0, ',', '.'); ?> clientes</strong>
            <div style="font-size:0.76rem;color:var(--text-muted);margin-top:2px;">
                R$ <?php echo number_format((float)$aq['faturamento_primeiras_compras'], 2, ',', '.'); ?> na 1ª compra
            </div>
        </div>
        <div class="crm-cross-mini">
            <small>Cadastro no Período + 1ª Compra</small>
            <strong style="color:#10b981;"><?php echo number_format((int)$aq['cad_periodo_e_primeira_compra'], 0, ',', '.'); ?> clientes</strong>
            <div style="font-size:0.76rem;color:var(--text-muted);margin-top:2px;">
                <?php echo (int)$aq['cad_anterior_e_primeira_compra']; ?> vieram de cadastros anteriores
            </div>
        </div>
        <div class="crm-cross-mini">
            <small>Fizeram Cadastro no Período</small>
            <strong style="color:#0ea5e9;"><?php echo number_format((int)$aq['total_novos_cadastros'], 0, ',', '.'); ?> cadastros</strong>
            <div style="font-size:0.76rem;color:var(--text-muted);margin-top:2px;">
                <?php echo (int)$aq['cadastros_que_compraram']; ?> já compraram · <?php echo (int)$aq['cadastros_sem_compra']; ?> aguardando 1ª compra
            </div>
        </div>
        <div class="crm-cross-mini">
            <small>Retenção após 1ª Compra</small>
            <strong><?php echo number_format((int)$aq['recorrentes_pos_primeira'], 0, ',', '.'); ?> já voltaram</strong>
            <div style="font-size:0.76rem;color:var(--text-muted);margin-top:2px;">
                <?php echo (int)$aq['somente_uma_compra']; ?> só na 1ª compra · <?php echo (int)$aq['com_promo_na_primeira']; ?> usaram promo
            </div>
        </div>
    </div>

    <!-- ABAS DE AQUISIÇÃO -->
    <div class="crm-acq-tabs no-print">
        <button type="button" class="crm-tab-btn active" data-acq-tab="primeira-compra">
            <i class="fas fa-shopping-bag"></i> Comprando pela 1ª Vez
            <span class="crm-tab-count"><?php echo number_format((int)$aq['total_primeira_compra'], 0, ',', '.'); ?></span>
        </button>
        <button type="button" class="crm-tab-btn" data-acq-tab="novos-cadastros">
            <i class="fas fa-user-plus"></i> Fizeram Cadastro no Período
            <span class="crm-tab-count"><?php echo number_format((int)$aq['total_novos_cadastros'], 0, ',', '.'); ?></span>
        </button>
    </div>

    <!-- PAINEL ABA 1: PRIMEIRA COMPRA -->
    <div id="crm-acq-panel-primeira-compra">
        <div class="crm-acq-tabs no-print" style="margin-bottom:12px;">
            <small style="color:var(--text-muted);font-weight:600;margin-right:4px;">Filtrar lista:</small>
            <button type="button" class="crm-pill-filter active" data-pri-filter="todos">Todos (<?php echo count($listaPri); ?>)</button>
            <button type="button" class="crm-pill-filter" data-pri-filter="novo_cad">Cadastro no Período + 1ª Compra (<?php echo (int)$aq['cad_periodo_e_primeira_compra']; ?>)</button>
            <button type="button" class="crm-pill-filter" data-pri-filter="antigo_cad">Cadastro Anterior + 1ª Compra</button>
            <button type="button" class="crm-pill-filter" data-pri-filter="so_uma">Somente 1 Compra (Sem Retorno)</button>
        </div>

        <div class="table-container" style="overflow-x:auto;">
            <table class="data-table" style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr>
                        <th>Cliente &amp; Contato</th>
                        <th>Pet(s)</th>
                        <th>Origem do Cadastro</th>
                        <th>1ª Compra na Unidade</th>
                        <th>Retorno &amp; CRM</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody id="tbody-primeira-compra">
                    <?php if (!empty($listaPri)): ?>
                        <?php foreach ($listaPri as $lp):
                            $isNovoCad = ((int)$lp['cadastro_no_periodo'] === 1);
                            $isSoUma = ((int)$lp['qtd_vendas'] === 1);
                            $dtCad = !empty($lp['data_cadastro']) ? strtotime($lp['data_cadastro']) : false;
                            $dtPri = !empty($lp['primeira_compra']) ? strtotime($lp['primeira_compra']) : false;
                            $cadTempoLabel = '';
                            if ($dtCad && $dtPri) {
                                if (date('Y-m-d', $dtCad) === date('Y-m-d', $dtPri)) {
                                    $cadTempoLabel = 'Mesmo dia';
                                } elseif ($dtPri > $dtCad) {
                                    $diasDiff = (int)floor(($dtPri - $dtCad) / 86400);
                                    $cadTempoLabel = '+' . $diasDiff . ' dia(s) após cadastro';
                                } else {
                                    $cadTempoLabel = 'Cadastro no período';
                                }
                            }
                            $lojaRotuloZap = trim((string)($_SESSION['instituicao_nome'] ?? '')) ?: 'nossa loja';
                            $msgZap = "Olá " . explode(' ', trim($lp['nome_completo']))[0] . "! 🐾 Seja bem-vindo(a) à {$lojaRotuloZap}! Obrigado pela sua primeira compra conosco.";
                            $zapUrl = crmFormatarWhatsappUrl($lp['telefone'] ?? '', $msgZap);
                        ?>
                            <tr class="crm-pri-row"
                                data-novo-cad="<?php echo $isNovoCad ? '1' : '0'; ?>"
                                data-so-uma="<?php echo $isSoUma ? '1' : '0'; ?>">
                                <td>
                                    <strong><?php echo htmlspecialchars($lp['nome_completo']); ?></strong>
                                    <div style="font-size:0.78rem;color:var(--text-muted);">
                                        <?php echo htmlspecialchars($lp['telefone'] ?: 'Sem telefone'); ?>
                                        <?php if ($multiUnidade && !empty($lp['unidade_nome'])): ?>
                                            · <?php echo htmlspecialchars($lp['unidade_nome']); ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <small style="color:var(--text-dark);"><?php echo htmlspecialchars($lp['pets_nomes'] ?: 'Sem pet'); ?></small>
                                </td>
                                <td>
                                    <?php if ($isNovoCad): ?>
                                        <span class="crm-badge crm-badge-newcad"><i class="fas fa-sparkles"></i> Novo Cadastro + 1ª Compra</span>
                                    <?php else: ?>
                                        <span class="crm-badge crm-badge-oldcad">Cadastro Anterior</span>
                                    <?php endif; ?>
                                    <div style="font-size:0.76rem;color:var(--text-muted);margin-top:3px;">
                                        Cadastro: <?php echo $dtCad ? date('d/m/Y', $dtCad) : '-'; ?>
                                        <?php if ($cadTempoLabel !== ''): ?> · <?php echo htmlspecialchars($cadTempoLabel); ?><?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <strong style="color:#10b981;">R$ <?php echo number_format((float)$lp['primeira_venda_valor'], 2, ',', '.'); ?></strong>
                                    <div style="font-size:0.76rem;color:var(--text-muted);">
                                        <?php echo $dtPri ? date('d/m/Y H:i', $dtPri) : '-'; ?>
                                        <?php if ((int)$lp['usou_promo_qtd'] > 0): ?>
                                            · <span style="color:#ec4899;font-weight:700;">Usou Promo</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <strong><?php echo (int)$lp['qtd_vendas']; ?> compra(s)</strong>
                                    <div style="font-size:0.76rem;color:var(--text-muted);">
                                        Total: R$ <?php echo number_format((float)$lp['total_gasto'], 2, ',', '.'); ?> · <?php echo htmlspecialchars($lp['tier_atual']); ?>
                                    </div>
                                </td>
                                <td>
                                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                        <a href="relatorio_crm.php?cliente_id=<?php echo (int)$lp['id']; ?>" class="btn btn-sm btn-primary" style="text-decoration:none;white-space:nowrap;">
                                            <i class="fas fa-chart-pie"></i> Dossiê
                                        </a>
                                        <?php if ($zapUrl !== ''): ?>
                                            <a href="<?php echo htmlspecialchars($zapUrl); ?>" target="_blank" class="btn btn-sm" style="background:#22c55e;color:#fff;text-decoration:none;" title="WhatsApp">
                                                <i class="fab fa-whatsapp"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr id="row-pri-empty-filter" style="display:none;"><td colspan="6" style="text-align:center;color:var(--text-muted);">Nenhum cliente nesta subcategoria dentro dos registros exibidos.</td></tr>
                    <?php else: ?>
                        <tr><td colspan="6" style="text-align:center;color:var(--text-muted);">Nenhuma primeira compra registrada neste período.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- PAINEL ABA 2: FIZERAM CADASTRO NO PERÍODO -->
    <div id="crm-acq-panel-novos-cadastros" style="display:none;">
        <div class="crm-acq-tabs no-print" style="margin-bottom:12px;">
            <small style="color:var(--text-muted);font-weight:600;margin-right:4px;">Filtrar cadastros:</small>
            <button type="button" class="crm-pill-filter active" data-cad-filter="todos">Todos (<?php echo count($listaCad); ?>)</button>
            <button type="button" class="crm-pill-filter" data-cad-filter="comprou">Já Compraram (<?php echo (int)$aq['cadastros_que_compraram']; ?>)</button>
            <button type="button" class="crm-pill-filter" data-cad-filter="sem_compra">Aguardando 1ª Compra (<?php echo (int)$aq['cadastros_sem_compra']; ?>)</button>
        </div>

        <div class="table-container" style="overflow-x:auto;">
            <table class="data-table" style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr>
                        <th>Cliente &amp; Contato</th>
                        <th>Pet(s)</th>
                        <th>Data Cadastro</th>
                        <th>Status da 1ª Compra</th>
                        <th>Gasto &amp; Tier</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($listaCad)): ?>
                        <?php foreach ($listaCad as $lc):
                            $comprou = ((int)$lc['qtd_vendas'] > 0);
                            $lojaRotuloCad = trim((string)($_SESSION['instituicao_nome'] ?? '')) ?: 'nossa loja';
                            $msgZapCad = "Olá " . explode(' ', trim($lc['nome_completo']))[0] . "! 🐾 Que bom ter você na {$lojaRotuloCad}. Temos benefícios especiais esperando por você e seu pet!";
                            $zapCadUrl = crmFormatarWhatsappUrl($lc['telefone'] ?? '', $msgZapCad);
                        ?>
                            <tr class="crm-cad-row" data-comprou="<?php echo $comprou ? '1' : '0'; ?>">
                                <td>
                                    <strong><?php echo htmlspecialchars($lc['nome_completo']); ?></strong>
                                    <div style="font-size:0.78rem;color:var(--text-muted);">
                                        <?php echo htmlspecialchars($lc['telefone'] ?: 'Sem telefone'); ?>
                                        <?php if ($multiUnidade && !empty($lc['unidade_nome'])): ?>
                                            · <?php echo htmlspecialchars($lc['unidade_nome']); ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <small style="color:var(--text-dark);"><?php echo htmlspecialchars($lc['pets_nomes'] ?: 'Sem pet'); ?></small>
                                </td>
                                <td>
                                    <strong><?php echo !empty($lc['data_cadastro']) ? date('d/m/Y H:i', strtotime($lc['data_cadastro'])) : '-'; ?></strong>
                                </td>
                                <td>
                                    <?php if ($comprou): ?>
                                        <span class="crm-badge crm-badge-newcad"><i class="fas fa-check-circle"></i> Já Comprou (<?php echo (int)$lc['qtd_vendas']; ?>x)</span>
                                        <div style="font-size:0.76rem;color:var(--text-muted);margin-top:3px;">
                                            1ª compra: <?php echo !empty($lc['primeira_compra']) ? date('d/m/Y', strtotime($lc['primeira_compra'])) : '-'; ?>
                                            (R$ <?php echo number_format((float)$lc['primeira_venda_valor'], 2, ',', '.'); ?>)
                                        </div>
                                    <?php else: ?>
                                        <span class="crm-badge crm-badge-wait"><i class="fas fa-hourglass-half"></i> Aguardando 1ª Compra</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong>R$ <?php echo number_format((float)$lc['total_gasto'], 2, ',', '.'); ?></strong>
                                    <div style="font-size:0.76rem;color:var(--text-muted);">
                                        <?php echo htmlspecialchars($lc['tier_atual']); ?> · Cashback R$ <?php echo number_format((float)$lc['saldo_cashback'], 2, ',', '.'); ?>
                                    </div>
                                </td>
                                <td>
                                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                        <a href="relatorio_crm.php?cliente_id=<?php echo (int)$lc['id']; ?>" class="btn btn-sm btn-primary" style="text-decoration:none;white-space:nowrap;">
                                            <i class="fas fa-chart-pie"></i> Dossiê
                                        </a>
                                        <a href="vendas.php?cliente_id=<?php echo (int)$lc['id']; ?>" class="btn btn-sm btn-secondary" style="text-decoration:none;" title="Iniciar venda no PDV">
                                            <i class="fas fa-cash-register"></i>
                                        </a>
                                        <?php if ($zapCadUrl !== ''): ?>
                                            <a href="<?php echo htmlspecialchars($zapCadUrl); ?>" target="_blank" class="btn btn-sm" style="background:#22c55e;color:#fff;text-decoration:none;" title="WhatsApp">
                                                <i class="fab fa-whatsapp"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr id="row-cad-empty-filter" style="display:none;"><td colspan="6" style="text-align:center;color:var(--text-muted);">Nenhum cadastro nesta subcategoria.</td></tr>
                    <?php else: ?>
                        <tr><td colspan="6" style="text-align:center;color:var(--text-muted);">Nenhum novo cadastro neste período.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- RANKING EXISTENTE DE CLIENTES NO CRM -->
<div class="crm-section-card">
    <div class="crm-section-title">
        <h3><i class="fas fa-trophy" style="color:#f59e0b;"></i> Ranking de Clientes no CRM (Clique para abrir o Dossiê 360°)</h3>
        <small style="color:var(--text-muted);">Top <?php echo count($rankingClientes); ?> clientes por prêmios prontos e histórico</small>
    </div>
    <div class="table-container" style="overflow-x:auto;">
        <table class="data-table" style="width:100%;border-collapse:collapse;">
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th>Tier / Selos Base</th>
                    <th>Pontos / Cashback</th>
                    <th>Ciclos Modulares</th>
                    <th>Promoções Usadas</th>
                    <th>Total Gasto</th>
                    <th>Ação</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($rankingClientes)): ?>
                    <?php foreach ($rankingClientes as $rk): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($rk['nome_completo']); ?></strong>
                                <div style="font-size:0.78rem;color:var(--text-muted);"><?php echo htmlspecialchars($rk['telefone'] ?: 'Sem telefone'); ?></div>
                            </td>
                            <td>
                                <span class="crm-badge" style="background:#fef3c7;color:#b45309;"><?php echo htmlspecialchars($rk['tier_atual']); ?></span>
                                <small style="margin-left:6px;"><?php echo (int)$rk['selos_atuais']; ?>/<?php echo (int)$cfg['compras_para_recompensa']; ?> selos</small>
                            </td>
                            <td>
                                <strong><?php echo (int)$rk['pontos_acumulados']; ?> pts</strong>
                                <div style="font-size:0.78rem;color:#10b981;">R$ <?php echo number_format((float)$rk['saldo_cashback'], 2, ',', '.'); ?></div>
                            </td>
                            <td>
                                <strong><?php echo (int)$rk['ciclos_modulares']; ?></strong> ciclo(s)
                                <?php if ((int)$rk['premios_prontos'] > 0): ?>
                                    <span class="crm-badge" style="background:#fef3c7;color:#b45309;margin-left:4px;"><?php echo (int)$rk['premios_prontos']; ?> pronto(s)</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo (int)$rk['qtd_promos']; ?>x</td>
                            <td><strong>R$ <?php echo number_format((float)$rk['total_gasto_historico'], 2, ',', '.'); ?></strong></td>
                            <td>
                                <a href="relatorio_crm.php?cliente_id=<?php echo (int)$rk['id']; ?>" class="btn btn-sm btn-primary" style="text-decoration:none;white-space:nowrap;">
                                    <i class="fas fa-chart-pie"></i> Abrir Relatório
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7">Nenhum cliente cadastrado nesta unidade.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL INDIVIDUAL: ESTATÍSTICAS DA PROMOÇÃO ATIVA -->
<div id="modalDetalhePromocao" class="crm-modal-backdrop no-print" aria-hidden="true">
    <div class="crm-modal-box" role="dialog" aria-modal="true" aria-labelledby="modalPromoTitle">
        <div class="crm-modal-head">
            <div style="flex:1;min-width:220px;">
                <div id="modalPromoBadges" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:6px;"></div>
                <h3 id="modalPromoTitle" style="margin:0 0 4px 0;font-size:1.22rem;color:var(--text-dark);">Estatísticas da Promoção</h3>
                <div id="modalPromoSub" style="font-size:0.82rem;color:var(--text-muted);"></div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <a href="fidelizacao.php" class="btn btn-sm btn-secondary" style="text-decoration:none;">
                    <i class="fas fa-sliders-h"></i> Editar Promoções
                </a>
                <button type="button" id="btnFecharModalPromo" class="crm-modal-close" title="Fechar (Esc)" aria-label="Fechar">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <div class="crm-modal-body">
            <div id="modalPromoLoading" style="text-align:center;padding:42px 16px;color:var(--text-muted);">
                <i class="fas fa-spinner fa-spin" style="font-size:1.8rem;color:#1abc9c;margin-bottom:10px;"></i>
                <p style="margin:0;">Carregando estatísticas individuais da promoção...</p>
            </div>
            <div id="modalPromoError" style="display:none;text-align:center;padding:30px 16px;color:#ef4444;"></div>
            <div id="modalPromoContent" style="display:none;">
                <div class="crm-modal-kpis" id="modalPromoKpis"></div>

                <div class="crm-modal-grid">
                    <div class="crm-chart-box">
                        <div class="crm-chart-header">
                            <div>
                                <h4>Evolução Individual no Período</h4>
                                <small style="color:var(--text-muted);">Faturamento das cestas com esta promoção, descontos e quantidade de usos</small>
                            </div>
                        </div>
                        <div class="crm-chart-wrap" style="height:260px;">
                            <canvas id="chartModalPromoSerie"></canvas>
                        </div>
                    </div>

                    <div style="display:flex;flex-direction:column;gap:14px;">
                        <div class="crm-chart-box">
                            <h4 style="margin:0 0 8px 0;font-size:0.92rem;color:var(--text-dark);">
                                <i class="fas fa-shopping-basket" style="color:#1abc9c;"></i> Itens Mais Comprados Junto (Cross-sell)
                            </h4>
                            <div id="modalPromoCrossSell" style="font-size:0.82rem;"></div>
                        </div>
                        <div class="crm-chart-box">
                            <h4 style="margin:0 0 8px 0;font-size:0.92rem;color:var(--text-dark);">
                                <i class="fas fa-credit-card" style="color:#3b82f6;"></i> Formas de Pagamento
                            </h4>
                            <div id="modalPromoPagamentos" style="font-size:0.82rem;"></div>
                        </div>
                    </div>
                </div>

                <div class="crm-chart-box">
                    <div class="crm-chart-header">
                        <div>
                            <h4>Últimos Clientes &amp; Vendas com esta Promoção</h4>
                            <small style="color:var(--text-muted);" id="modalPromoUsosSub">Até 50 usos mais recentes no período selecionado</small>
                        </div>
                    </div>
                    <div class="table-container" style="overflow-x:auto;">
                        <table class="data-table" style="width:100%;border-collapse:collapse;">
                            <thead>
                                <tr>
                                    <th>Cliente / Pet</th>
                                    <th>Data &amp; Venda</th>
                                    <th>Perfil na Compra</th>
                                    <th>Itens &amp; Desconto</th>
                                    <th>Total da Venda</th>
                                    <th>Ação</th>
                                </tr>
                            </thead>
                            <tbody id="modalPromoUsosBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Alternância de abas & filtros na seção Aquisição (1ª Compra & Novos Cadastros)
    const tabBtns = document.querySelectorAll('[data-acq-tab]');
    const panelPri = document.getElementById('crm-acq-panel-primeira-compra');
    const panelCad = document.getElementById('crm-acq-panel-novos-cadastros');

    tabBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            tabBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            const target = this.getAttribute('data-acq-tab');
            if (panelPri) panelPri.style.display = (target === 'primeira-compra') ? '' : 'none';
            if (panelCad) panelCad.style.display = (target === 'novos-cadastros') ? '' : 'none';
        });
    });

    const priFilterBtns = document.querySelectorAll('[data-pri-filter]');
    const priRows = document.querySelectorAll('.crm-pri-row');
    const priEmptyRow = document.getElementById('row-pri-empty-filter');
    priFilterBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            priFilterBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            const mode = this.getAttribute('data-pri-filter');
            let visible = 0;
            priRows.forEach(tr => {
                const novo = tr.dataset.novoCad === '1';
                const soUma = tr.dataset.soUma === '1';
                let show = true;
                if (mode === 'novo_cad') show = novo;
                else if (mode === 'antigo_cad') show = !novo;
                else if (mode === 'so_uma') show = soUma;
                tr.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            if (priEmptyRow) priEmptyRow.style.display = (visible === 0 && priRows.length > 0) ? '' : 'none';
        });
    });

    const cadFilterBtns = document.querySelectorAll('[data-cad-filter]');
    const cadRows = document.querySelectorAll('.crm-cad-row');
    const cadEmptyRow = document.getElementById('row-cad-empty-filter');
    cadFilterBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            cadFilterBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            const mode = this.getAttribute('data-cad-filter');
            let visible = 0;
            cadRows.forEach(tr => {
                const comprou = tr.dataset.comprou === '1';
                let show = true;
                if (mode === 'comprou') show = comprou;
                else if (mode === 'sem_compra') show = !comprou;
                tr.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            if (cadEmptyRow) cadEmptyRow.style.display = (visible === 0 && cadRows.length > 0) ? '' : 'none';
        });
    });

    // 2. Gráficos Chart.js: Promoções Ativas × Venda Geral + Evolução Temporal
    if (typeof Chart === 'undefined') return;

    const promoPayload = <?php echo json_encode($promoChartPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    const seriePayload = <?php echo json_encode($serie, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;

    const fmtBRL = val => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(val || 0));
    const getChartPalette = () => {
        const dark = document.documentElement.classList.contains('dark-theme');
        return {
            text: dark ? '#cbd5e1' : '#475569',
            grid: dark ? 'rgba(148, 163, 184, 0.14)' : 'rgba(15, 23, 42, 0.08)',
        };
    };

    let chartPromos = null;
    let chartSerie = null;
    let currentPromoMode = 'faturamento';

    function renderPromoChart(mode) {
        const canvas = document.getElementById('chartPromosVsVendas');
        if (!canvas || !promoPayload.labels || promoPayload.labels.length === 0) return;
        currentPromoMode = mode || 'faturamento';
        if (chartPromos) chartPromos.destroy();
        const pal = getChartPalette();

        const isTicketMode = (currentPromoMode === 'ticket');
        const ticketRefArray = promoPayload.labels.map(() => promoPayload.ticketMedioGeral);

        const datasets = isTicketMode ? [
            {
                type: 'bar',
                label: 'Ticket Médio Venda c/ Promo (R$)',
                data: promoPayload.ticketMedioPromo,
                backgroundColor: 'rgba(99, 102, 241, 0.78)',
                borderColor: '#6366f1',
                borderWidth: 1,
                borderRadius: 6,
                yAxisID: 'y',
                order: 2
            },
            {
                type: 'line',
                label: 'Ticket Médio Venda Geral (R$)',
                data: ticketRefArray,
                borderColor: '#10b981',
                borderWidth: 2,
                borderDash: [6, 4],
                pointRadius: 0,
                fill: false,
                yAxisID: 'y',
                order: 1
            },
            {
                type: 'line',
                label: 'Qtd. Usos da Promoção',
                data: promoPayload.qtdUsos,
                borderColor: '#f59e0b',
                backgroundColor: 'rgba(245, 158, 11, 0.25)',
                borderWidth: 2.5,
                tension: 0.25,
                pointRadius: 4,
                yAxisID: 'y1',
                order: 0
            }
        ] : [
            {
                type: 'bar',
                label: 'Vendas Totais c/ Promo (R$)',
                data: promoPayload.fatVendasPromo,
                backgroundColor: 'rgba(26, 188, 156, 0.80)',
                borderColor: '#1abc9c',
                borderWidth: 1,
                borderRadius: 6,
                yAxisID: 'y',
                order: 3
            },
            {
                type: 'bar',
                label: 'Itens em Promoção (R$)',
                data: promoPayload.receitaItensPromo,
                backgroundColor: 'rgba(59, 130, 246, 0.72)',
                borderColor: '#3b82f6',
                borderWidth: 1,
                borderRadius: 6,
                yAxisID: 'y',
                order: 2
            },
            {
                type: 'bar',
                label: 'Desconto Concedido (R$)',
                data: promoPayload.descontoPromo,
                backgroundColor: 'rgba(236, 72, 153, 0.75)',
                borderColor: '#ec4899',
                borderWidth: 1,
                borderRadius: 6,
                yAxisID: 'y',
                order: 1
            },
            {
                type: 'line',
                label: '% sobre Venda Geral',
                data: promoPayload.shareGeralPct,
                borderColor: '#f59e0b',
                backgroundColor: 'rgba(245, 158, 11, 0.2)',
                borderWidth: 2.5,
                tension: 0.25,
                pointRadius: 4,
                yAxisID: 'y1',
                order: 0
            }
        ];

        chartPromos = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: promoPayload.labels,
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                onHover: (event, elements) => {
                    if (event && event.native && event.native.target) {
                        event.native.target.style.cursor = (elements && elements.length > 0) ? 'pointer' : 'default';
                    }
                },
                onClick: (event, elements) => {
                    if (elements && elements.length > 0 && promoPayload.promoIds) {
                        const idx = elements[0].index;
                        const pid = promoPayload.promoIds[idx];
                        if (pid) abrirDetalhePromocao(pid);
                    }
                },
                plugins: {
                    legend: { labels: { color: pal.text, boxWidth: 12, font: { size: 11 } } },
                    tooltip: {
                        callbacks: {
                            title: items => {
                                const idx = items[0] ? items[0].dataIndex : 0;
                                return promoPayload.fullTitles[idx] || '';
                            },
                            afterBody: items => {
                                const idx = items[0] ? items[0].dataIndex : 0;
                                return [
                                    `Usos no período: ${promoPayload.qtdUsos[idx]} (${promoPayload.clientesUnicos[idx]} cliente(s))`,
                                    `Participação na Venda Geral: ${Number(promoPayload.shareGeralPct[idx] || 0).toFixed(2)}%`
                                ];
                            },
                            label: ctx => {
                                if (ctx.dataset.yAxisID === 'y1') {
                                    return isTicketMode
                                        ? `${ctx.dataset.label}: ${ctx.parsed.y} uso(s)`
                                        : `${ctx.dataset.label}: ${Number(ctx.parsed.y || 0).toFixed(2)}%`;
                                }
                                return `${ctx.dataset.label}: ${fmtBRL(ctx.parsed.y)}`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: { color: pal.text, font: { size: 11 } },
                        grid: { color: pal.grid }
                    },
                    y: {
                        beginAtZero: true,
                        position: 'left',
                        ticks: {
                            color: pal.text,
                            callback: v => 'R$ ' + new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 0 }).format(v)
                        },
                        grid: { color: pal.grid }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        grid: { drawOnChartArea: false },
                        ticks: {
                            color: '#f59e0b',
                            callback: v => isTicketMode ? v : (v + '%')
                        }
                    }
                }
            }
        });
    }

    function renderSerieChart() {
        const canvas = document.getElementById('chartSerieCrmVendas');
        if (!canvas || !seriePayload.labels) return;
        if (chartSerie) chartSerie.destroy();
        const pal = getChartPalette();

        chartSerie = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: seriePayload.labels,
                datasets: [
                    {
                        type: 'bar',
                        label: 'Venda Geral (R$)',
                        data: seriePayload.fat_geral,
                        backgroundColor: 'rgba(59, 130, 246, 0.32)',
                        borderColor: '#3b82f6',
                        borderWidth: 1,
                        borderRadius: 4,
                        yAxisID: 'y',
                        order: 3
                    },
                    {
                        type: 'line',
                        label: 'Vendas c/ Promo Ativa (R$)',
                        data: seriePayload.fat_promo,
                        borderColor: '#ec4899',
                        backgroundColor: 'rgba(236, 72, 153, 0.14)',
                        borderWidth: 2.5,
                        tension: 0.3,
                        fill: true,
                        pointRadius: 2.5,
                        yAxisID: 'y',
                        order: 2
                    },
                    {
                        type: 'line',
                        label: 'Clientes 1ª Compra (Qtd)',
                        data: seriePayload.qtd_primeira_compra,
                        borderColor: '#10b981',
                        borderWidth: 2,
                        tension: 0.3,
                        pointRadius: 2.5,
                        yAxisID: 'y1',
                        order: 1
                    },
                    {
                        type: 'line',
                        label: 'Novos Cadastros (Qtd)',
                        data: seriePayload.qtd_novos_cadastros,
                        borderColor: '#f59e0b',
                        borderWidth: 1.8,
                        borderDash: [4, 4],
                        tension: 0.3,
                        pointRadius: 2,
                        yAxisID: 'y1',
                        order: 0
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { labels: { color: pal.text, boxWidth: 12, font: { size: 11 } } },
                    tooltip: {
                        callbacks: {
                            label: ctx => {
                                if (ctx.dataset.yAxisID === 'y1') {
                                    return `${ctx.dataset.label}: ${ctx.parsed.y}`;
                                }
                                return `${ctx.dataset.label}: ${fmtBRL(ctx.parsed.y)}`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: { color: pal.text, maxTicksLimit: 12, font: { size: 11 } },
                        grid: { color: pal.grid }
                    },
                    y: {
                        beginAtZero: true,
                        position: 'left',
                        ticks: {
                            color: pal.text,
                            callback: v => 'R$ ' + new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 0 }).format(v)
                        },
                        grid: { color: pal.grid }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        grid: { drawOnChartArea: false },
                        ticks: { color: '#10b981', precision: 0 }
                    }
                }
            }
        });
    }

    document.querySelectorAll('[data-promo-chart-mode]').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('[data-promo-chart-mode]').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            renderPromoChart(this.getAttribute('data-promo-chart-mode'));
        });
    });

    // 3. Modal de Estatísticas Individuais da Promoção Ativa
    const modalPromo = document.getElementById('modalDetalhePromocao');
    const modalLoading = document.getElementById('modalPromoLoading');
    const modalError = document.getElementById('modalPromoError');
    const modalContent = document.getElementById('modalPromoContent');
    let chartModalPromo = null;
    let ultimoDetalhePromo = null;

    const escHtml = str => String(str === null || str === undefined ? '' : str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

    function fecharModalPromo() {
        if (!modalPromo) return;
        modalPromo.classList.remove('open');
        modalPromo.setAttribute('aria-hidden', 'true');
    }

    function renderModalPromoSerie(serieData) {
        const canvas = document.getElementById('chartModalPromoSerie');
        if (!canvas || !serieData || !serieData.labels) return;
        if (chartModalPromo) chartModalPromo.destroy();
        const pal = getChartPalette();

        chartModalPromo = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: serieData.labels,
                datasets: [
                    {
                        type: 'bar',
                        label: 'Vendas c/ Promo (R$)',
                        data: serieData.fat_vendas,
                        backgroundColor: 'rgba(26, 188, 156, 0.72)',
                        borderColor: '#1abc9c',
                        borderWidth: 1,
                        borderRadius: 4,
                        yAxisID: 'y',
                        order: 2
                    },
                    {
                        type: 'bar',
                        label: 'Desconto (R$)',
                        data: serieData.desconto,
                        backgroundColor: 'rgba(236, 72, 153, 0.72)',
                        borderColor: '#ec4899',
                        borderWidth: 1,
                        borderRadius: 4,
                        yAxisID: 'y',
                        order: 1
                    },
                    {
                        type: 'line',
                        label: 'Qtd. Usos',
                        data: serieData.qtd_usos,
                        borderColor: '#f59e0b',
                        borderWidth: 2.5,
                        tension: 0.3,
                        pointRadius: 3,
                        yAxisID: 'y1',
                        order: 0
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { labels: { color: pal.text, boxWidth: 12, font: { size: 11 } } },
                    tooltip: {
                        callbacks: {
                            label: ctx => ctx.dataset.yAxisID === 'y1'
                                ? `${ctx.dataset.label}: ${ctx.parsed.y}`
                                : `${ctx.dataset.label}: ${fmtBRL(ctx.parsed.y)}`
                        }
                    }
                },
                scales: {
                    x: { ticks: { color: pal.text, maxTicksLimit: 10, font: { size: 11 } }, grid: { color: pal.grid } },
                    y: {
                        beginAtZero: true,
                        position: 'left',
                        ticks: { color: pal.text, callback: v => 'R$ ' + new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 0 }).format(v) },
                        grid: { color: pal.grid }
                    },
                    y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { color: '#f59e0b', precision: 0 } }
                }
            }
        });
    }

    function preencherModalPromo(dados) {
        ultimoDetalhePromo = dados;
        const p = dados.promocao || {};
        const r = dados.resumo || {};
        const per = dados.periodo || {};

        const badgesEl = document.getElementById('modalPromoBadges');
        const titleEl = document.getElementById('modalPromoTitle');
        const subEl = document.getElementById('modalPromoSub');
        const kpisEl = document.getElementById('modalPromoKpis');
        const csEl = document.getElementById('modalPromoCrossSell');
        const pagEl = document.getElementById('modalPromoPagamentos');
        const bodyEl = document.getElementById('modalPromoUsosBody');
        const usosSubEl = document.getElementById('modalPromoUsosSub');

        if (titleEl) titleEl.textContent = p.titulo || 'Promoção Ativa';
        if (badgesEl) {
            let bHtml = `<span class="crm-badge crm-badge-oldcad">${escHtml(p.politica_label)}</span>`;
            if (p.desconto_percentual > 0) {
                bHtml += `<span class="crm-badge" style="background:rgba(236,72,153,0.16);color:#ec4899;">${Number(p.desconto_percentual).toFixed(0)}% OFF</span>`;
            }
            if (p.aplicar_automatico_pdv) {
                bHtml += `<span class="crm-badge crm-badge-newcad">Automática no PDV</span>`;
            }
            bHtml += `<span class="crm-badge" style="background:rgba(148,163,184,0.16);color:var(--text-muted);">Período: ${escHtml(per.inicio)} a ${escHtml(per.fim)}</span>`;
            badgesEl.innerHTML = bHtml;
        }
        if (subEl) {
            const precoInfo = p.preco_promocional > 0
                ? ` · De ${fmtBRL(p.preco_original)} por ${fmtBRL(p.preco_promocional)}`
                : '';
            const limCli = p.limite_usos_cliente > 0 ? `${p.limite_usos_cliente}x por cliente` : 'Sem limite/cliente';
            const limGlb = p.limite_usos_global > 0 ? ` · Cota global: ${p.usos_globais_atuais}/${p.limite_usos_global}` : '';
            subEl.textContent = `${p.nome_produto ? p.nome_produto + ' · ' : ''}Vigência ${p.data_inicio_fmt} a ${p.data_fim_fmt}${precoInfo} · ${limCli}${limGlb}`;
        }

        if (kpisEl) {
            const varSinal = r.variacao_ticket_pct >= 0 ? '+' : '';
            const varCor = r.variacao_ticket_pct >= 0 ? '#10b981' : '#f59e0b';
            kpisEl.innerHTML = `
                <div class="crm-modal-kpi">
                    <small>Usos no Período (Histórico: ${r.usos_campanha_total})</small>
                    <strong>${r.qtd_usos} uso(s)</strong>
                    <div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px;">
                        ${r.clientes_unicos} cliente(s) únicos · ${r.clientes_multi_uso} reutilizaram
                    </div>
                </div>
                <div class="crm-modal-kpi">
                    <small>Vendas c/ esta Promo (% da Loja)</small>
                    <strong style="color:#10b981;">${fmtBRL(r.faturamento_vendas_promo)}</strong>
                    <div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px;">
                        ${Number(r.share_vendas_geral_pct || 0).toFixed(2)}% de ${fmtBRL(r.faturamento_geral_loja)} (${r.vendas_com_promo} vendas)
                    </div>
                </div>
                <div class="crm-modal-kpi">
                    <small>Itens Promo vs Cross-sell Cesta</small>
                    <strong>${fmtBRL(r.receita_itens_promo)}</strong>
                    <div style="font-size:0.75rem;color:#38bdf8;margin-top:2px;">
                        +${fmtBRL(r.faturamento_cross_sell)} em outros itens (${r.itens_vendidos} un.)
                    </div>
                </div>
                <div class="crm-modal-kpi">
                    <small>Desconto Concedido &amp; ROI</small>
                    <strong style="color:#ec4899;">-${fmtBRL(r.desconto_concedido)}</strong>
                    <div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px;">
                        ROI da cesta: ${Number(r.roi_desconto || 0).toFixed(1)}x sobre o desconto
                    </div>
                </div>
                <div class="crm-modal-kpi">
                    <small>Ticket Médio (vs Geral ${fmtBRL(r.ticket_medio_geral)})</small>
                    <strong>${fmtBRL(r.ticket_medio_promo)}</strong>
                    <div style="font-size:0.75rem;color:${varCor};font-weight:700;margin-top:2px;">
                        ${r.vendas_com_promo > 0 ? `${varSinal}${Number(r.variacao_ticket_pct || 0).toFixed(1)}% vs Ticket Geral` : 'Sem vendas no período'}
                    </div>
                </div>
                <div class="crm-modal-kpi">
                    <small>Atração de Novos Clientes</small>
                    <strong style="color:#0ea5e9;">${r.clientes_primeira_compra} em 1ª compra</strong>
                    <div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px;">
                        ${r.clientes_novos_cadastros} cadastrado(s) no período
                    </div>
                </div>
            `;
        }

        if (csEl) {
            const csList = dados.cross_sell || [];
            if (csList.length === 0) {
                csEl.innerHTML = '<div style="color:var(--text-muted);">Nenhum outro item agregado encontrado nas vendas desta promoção.</div>';
            } else {
                csEl.innerHTML = csList.map(item => `
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--border-color-light);">
                        <div style="min-width:0;">
                            <div style="font-weight:600;color:var(--text-dark);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escHtml(item.nome_produto)}</div>
                            <small style="color:var(--text-muted);">${item.vendas} venda(s) · ${item.qtd_itens} un.</small>
                        </div>
                        <strong style="color:#10b981;white-space:nowrap;">${fmtBRL(item.receita)}</strong>
                    </div>
                `).join('');
            }
        }

        if (pagEl) {
            const pgList = dados.pagamentos || [];
            if (pgList.length === 0) {
                pagEl.innerHTML = '<div style="color:var(--text-muted);">Sem vendas registradas no período.</div>';
            } else {
                pagEl.innerHTML = pgList.map(pg => `
                    <div class="crm-pay-row">
                        <div style="display:flex;justify-content:space-between;gap:8px;">
                            <span style="font-weight:600;color:var(--text-dark);">${escHtml(pg.forma_pagamento)} (${pg.qtd}x)</span>
                            <span style="color:var(--text-muted);">${fmtBRL(pg.total)} · <strong>${Number(pg.pct).toFixed(1)}%</strong></span>
                        </div>
                        <div class="crm-pay-bar-track"><div class="crm-pay-bar-fill" style="width:${Math.min(100, Number(pg.pct))}%"></div></div>
                    </div>
                `).join('');
            }
        }

        const usos = dados.usos_lista || [];
        if (usosSubEl) {
            usosSubEl.textContent = `Exibindo ${usos.length} uso(s) mais recente(s) no período`;
        }
        if (bodyEl) {
            if (usos.length === 0) {
                bodyEl.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--text-muted);">Nenhum cliente utilizou esta promoção no período selecionado.</td></tr>';
            } else {
                bodyEl.innerHTML = usos.map(u => {
                    let badgesUso = '';
                    if (u.is_primeira_compra) badgesUso += '<span class="crm-badge crm-badge-newcad">1ª Compra na Loja</span> ';
                    if (u.is_novo_cadastro) badgesUso += '<span class="crm-badge crm-badge-oldcad">Novo Cadastro</span> ';
                    if (u.usos_cliente_promo > 1) badgesUso += `<span class="crm-badge crm-badge-wait">${u.usos_cliente_promo}x nesta promo</span> `;
                    if (!badgesUso) badgesUso = '<span style="color:var(--text-muted);font-size:0.78rem;">Cliente Recorrente</span>';

                    const acaoHtml = u.cliente_id > 0
                        ? `<a href="relatorio_crm.php?cliente_id=${u.cliente_id}" class="btn btn-sm btn-primary" style="text-decoration:none;white-space:nowrap;"><i class="fas fa-chart-pie"></i> Dossiê</a>`
                        : '<span style="color:var(--text-muted);font-size:0.76rem;">Anônimo</span>';

                    return `
                        <tr>
                            <td>
                                <strong>${escHtml(u.cliente_nome)}</strong>
                                <div style="font-size:0.76rem;color:var(--text-muted);">
                                    ${escHtml(u.telefone || 'Sem telefone')}${u.pets_nomes ? ' · ' + escHtml(u.pets_nomes) : ''}
                                </div>
                            </td>
                            <td>
                                <strong>${escHtml(u.data_uso_fmt)}</strong>
                                <div style="font-size:0.76rem;color:var(--text-muted);">${u.venda_id > 0 ? 'Venda #' + u.venda_id : '-'} · ${escHtml(u.forma_pagamento)}</div>
                            </td>
                            <td>${badgesUso}</td>
                            <td>
                                <strong>${fmtBRL(u.valor_final_itens)}</strong> (${u.quantidade_itens} un.)
                                <div style="font-size:0.76rem;color:#ec4899;font-weight:700;">-${fmtBRL(u.desconto_aplicado)}</div>
                            </td>
                            <td><strong style="color:#10b981;">${fmtBRL(u.venda_valor_total)}</strong></td>
                            <td>${acaoHtml}</td>
                        </tr>
                    `;
                }).join('');
            }
        }

        renderModalPromoSerie(dados.serie);
    }

    function abrirDetalhePromocao(promoId) {
        if (!modalPromo || !promoId) return;
        modalPromo.classList.add('open');
        modalPromo.setAttribute('aria-hidden', 'false');
        if (modalLoading) modalLoading.style.display = 'block';
        if (modalError) modalError.style.display = 'none';
        if (modalContent) modalContent.style.display = 'none';

        const url = `relatorio_crm.php?acao=detalhar_promocao&promocao_id=${encodeURIComponent(promoId)}&data_inicio=${encodeURIComponent(promoPayload.perIni || '')}&data_fim=${encodeURIComponent(promoPayload.perFim || '')}`;
        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(res => {
                if (modalLoading) modalLoading.style.display = 'none';
                if (!res || !res.success || !res.dados) {
                    if (modalError) {
                        modalError.textContent = (res && res.message) ? res.message : 'Não foi possível carregar os detalhes desta promoção.';
                        modalError.style.display = 'block';
                    }
                    return;
                }
                if (modalContent) modalContent.style.display = 'block';
                preencherModalPromo(res.dados);
            })
            .catch(() => {
                if (modalLoading) modalLoading.style.display = 'none';
                if (modalError) {
                    modalError.textContent = 'Erro de comunicação ao buscar estatísticas individuais da promoção.';
                    modalError.style.display = 'block';
                }
            });
    }

    document.querySelectorAll('.crm-promo-clickable').forEach(row => {
        row.addEventListener('click', function() {
            const pid = parseInt(this.dataset.promoId || '0', 10);
            if (pid > 0) abrirDetalhePromocao(pid);
        });
        row.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                const pid = parseInt(this.dataset.promoId || '0', 10);
                if (pid > 0) abrirDetalhePromocao(pid);
            }
        });
    });

    document.getElementById('btnFecharModalPromo')?.addEventListener('click', fecharModalPromo);
    modalPromo?.addEventListener('click', function(e) {
        if (e.target === modalPromo) fecharModalPromo();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modalPromo && modalPromo.classList.contains('open')) {
            fecharModalPromo();
        }
    });

    renderPromoChart('faturamento');
    renderSerieChart();

    window.addEventListener('petcrm:theme-change', function() {
        renderPromoChart(currentPromoMode);
        renderSerieChart();
        if (ultimoDetalhePromo && modalPromo && modalPromo.classList.contains('open')) {
            renderModalPromoSerie(ultimoDetalhePromo.serie);
        }
    });
});
</script>
