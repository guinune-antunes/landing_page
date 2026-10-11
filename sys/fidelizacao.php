<?php
require 'includes/verifica_login.php';
require 'conexao.php';

$cargo = $_SESSION['usuario_cargo'] ?? '';
$id_loja = $_SESSION['instituicao_id'] ?? 0;

// Se for Admin geral, permite visualizar/gerenciar uma loja ativa
if ($id_loja == 0 && $cargo === 'Admin') {
    $id_loja = intval($_GET['loja_id'] ?? 0);
    if ($id_loja <= 0) {
        $id_loja = (int)$pdo->query("SELECT id FROM instituicoes ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1;
    }
}

// 1. Busca Configuração Atual do Programa
$stCfg = $pdo->prepare("SELECT * FROM fidelidade_config WHERE instituicao_id = ?");
$stCfg->execute([$id_loja]);
$cfg = $stCfg->fetch(PDO::FETCH_ASSOC);

if (!$cfg) {
    // Defaults inteligentes
    $cfg = [
        'tipo_programa' => 'selos',
        'compras_para_recompensa' => 10,
        'recompensa_tipo' => 'desconto_percentual',
        'recompensa_valor' => 15.00,
        'limite_desconto_recompensa' => 50.00,
        'reais_por_ponto' => 1.00,
        'cashback_percentual' => 5.00,
        'validade_meses' => 6,
        'notificar_expiracao' => 1,
        'dias_aviso_expiracao' => 15,
        'notificar_whatsapp' => 1,
        'notificar_email' => 0,
        'cor_cartao_hex' => '#1abc9c',
        'cor_cartao_secundaria' => '#2c3e50',
        'estilo_cartao' => 'modern_gradient',
        'logo_cartao' => null,
        'texto_cartao' => 'Cartão Fidelidade Pet VIP',
        'regras_aceleradores' => json_encode(['terca_dobro' => true, 'aniversario' => true, 'servicos_bonus' => true]),
        'tiers_config' => json_encode([
            ['nome' => 'Bronze', 'meta' => 0, 'beneficio' => '1x pontos + Selos normais'],
            ['nome' => 'Prata', 'meta' => 500, 'beneficio' => '1.2x pontos + 5% desc. no aniversário'],
            ['nome' => 'Ouro VIP', 'meta' => 1500, 'beneficio' => '1.5x pontos + Brinde na tosa + Fila VIP']
        ])
    ];
}

$aceleradores = json_decode($cfg['regras_aceleradores'] ?? '{}', true) ?: [];
$tiers = json_decode($cfg['tiers_config'] ?? '[]', true) ?: [];

// 2. Busca Promoções Ativas e Históricas
$stPromos = $pdo->prepare("SELECT p.*, prod.nome_produto, prod.codigo_barras 
                          FROM promocoes p 
                          LEFT JOIN produtos prod ON p.produto_id = prod.id 
                          WHERE p.instituicao_id = ? 
                          ORDER BY p.id DESC");
$stPromos->execute([$id_loja]);
$promocoes = $stPromos->fetchAll(PDO::FETCH_ASSOC);

// 3. Busca Produtos para o dropdown do criador de promoções
$stProds = $pdo->prepare("SELECT id, nome_produto, preco_venda, quantidade_estoque FROM produtos WHERE instituicao_id = ? ORDER BY nome_produto ASC LIMIT 200");
$stProds->execute([$id_loja]);
$produtos_loja = $stProds->fetchAll(PDO::FETCH_ASSOC);

// 4. Busca Serviços para o catálogo de resgate
$stServ = $pdo->prepare("SELECT id, nome_servico, preco_padrao FROM servicos WHERE instituicao_id = ? ORDER BY nome_servico ASC");
$stServ->execute([$id_loja]);
$servicos_loja = $stServ->fetchAll(PDO::FETCH_ASSOC);

// 5. KPIs de Auditoria e Desempenho
$stKpiTotalCli = $pdo->prepare("SELECT COUNT(*) FROM cliente_fidelidade WHERE instituicao_id = ?");
$stKpiTotalCli->execute([$id_loja]);
$total_clientes_fidelizados = (int)$stKpiTotalCli->fetchColumn();

$stKpiSelos = $pdo->prepare("SELECT SUM(selos_atuais) FROM cliente_fidelidade WHERE instituicao_id = ?");
$stKpiSelos->execute([$id_loja]);
$total_selos_circulando = (int)($stKpiSelos->fetchColumn() ?: 0);

$stKpiCiclos = $pdo->prepare("SELECT SUM(ciclos_completados) FROM cliente_fidelidade WHERE instituicao_id = ?");
$stKpiCiclos->execute([$id_loja]);
$total_ciclos_resgatados = (int)($stKpiCiclos->fetchColumn() ?: 0);

$stKpiDesc = $pdo->prepare("SELECT SUM(valor_recompensa) FROM fidelidade_transacoes WHERE instituicao_id = ? AND tipo = 'resgate_recompensa' AND MONTH(data_transacao) = MONTH(CURRENT_DATE()) AND YEAR(data_transacao) = YEAR(CURRENT_DATE())");
$stKpiDesc->execute([$id_loja]);
$total_descontos_mes = (float)($stKpiDesc->fetchColumn() ?: 0.0);

// Histórico de Transações de Auditoria
$stLogs = $pdo->prepare("SELECT t.*, c.nome_completo 
                        FROM fidelidade_transacoes t 
                        JOIN clientes c ON t.cliente_id = c.id 
                        WHERE t.instituicao_id = ? 
                        ORDER BY t.data_transacao DESC LIMIT 15");
$stLogs->execute([$id_loja]);
$transacoes_recentes = $stLogs->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Fidelização & Promoções';
$paginaAtiva = 'fidelizacao';
include 'includes/_head.php';
?>

<!-- Biblioteca QRCode.js via CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<style>
    /* ── DESIGN SYSTEM & ESTILOS DA TELA DE FIDELIZAÇÃO ── */
    .fid-top-nav {
        display: flex;
        gap: 12px;
        margin-bottom: 25px;
        border-bottom: 2px solid #e2e8f0;
        padding-bottom: 12px;
        flex-wrap: wrap;
    }
    .fid-tab-btn {
        padding: 10px 20px;
        border-radius: 10px;
        font-weight: 600;
        font-size: 0.95rem;
        cursor: pointer;
        border: 1px solid #cbd5e1;
        background: #ffffff;
        color: #475569;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.25s ease;
        text-decoration: none;
    }
    .fid-tab-btn:hover {
        background: #f8fafc;
        border-color: #94a3b8;
    }
    .fid-tab-btn.active {
        background: #1abc9c;
        color: #ffffff;
        border-color: #1abc9c;
        box-shadow: 0 4px 12px rgba(26, 188, 156, 0.3);
    }

    .tab-content-panel {
        display: none;
        animation: fadeInTab 0.3s ease;
    }
    .tab-content-panel.active {
        display: block;
    }
    @keyframes fadeInTab {
        from { opacity: 0; transform: translateY(6px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* ── GRID E CARDS ── */
    .fid-grid-2col {
        display: grid;
        grid-template-columns: 1fr 1.1fr;
        gap: 25px;
        align-items: start;
    }
    @media(max-width: 1024px) {
        .fid-grid-2col { grid-template-columns: 1fr; }
    }

    /* ── CARTÃO VIP PREVIEW INTERATIVO ── */
    .vip-card-preview-box {
        position: sticky;
        top: 20px;
    }
    .vip-card-display {
        border-radius: 24px;
        padding: 24px 22px;
        color: #ffffff;
        box-shadow: 0 20px 35px rgba(0,0,0,0.25), 0 0 0 1px rgba(255,255,255,0.15) inset;
        position: relative;
        overflow: hidden;
        transition: background 0.4s ease, transform 0.3s;
    }
    .vip-card-display:hover {
        transform: translateY(-3px);
    }
    .vip-card-display::after {
        content: '';
        position: absolute;
        top: -40%;
        right: -25%;
        width: 260px;
        height: 260px;
        background: radial-gradient(circle, rgba(255,255,255,0.2) 0%, rgba(255,255,255,0) 70%);
        border-radius: 50%;
        pointer-events: none;
    }

    .vip-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 18px;
    }
    .vip-brand {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .vip-logo-box {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        background: rgba(255,255,255,0.2);
        backdrop-filter: blur(8px);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.3rem;
        overflow: hidden;
    }
    .vip-logo-box img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .vip-title-text h4 {
        font-size: 1.05rem;
        font-weight: 700;
        margin: 0;
        line-height: 1.2;
    }
    .vip-title-text small {
        font-size: 0.72rem;
        opacity: 0.85;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .vip-tier-badge {
        background: rgba(255,255,255,0.25);
        backdrop-filter: blur(10px);
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 0.78rem;
        font-weight: 700;
        border: 1px solid rgba(255,255,255,0.3);
        display: flex;
        align-items: center;
        gap: 6px;
    }

    /* Selos no cartão */
    .vip-stamps-tray {
        background: rgba(0,0,0,0.18);
        backdrop-filter: blur(10px);
        border-radius: 16px;
        padding: 16px 12px;
        margin-bottom: 16px;
        border: 1px solid rgba(255,255,255,0.1);
    }
    .vip-stamps-header {
        display: flex;
        justify-content: space-between;
        font-size: 0.8rem;
        font-weight: 600;
        margin-bottom: 10px;
    }
    .vip-stamps-slots {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 8px;
    }
    .vip-slot {
        aspect-ratio: 1/1;
        border-radius: 50%;
        background: rgba(255,255,255,0.15);
        border: 2px dashed rgba(255,255,255,0.4);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
        font-weight: 700;
        color: rgba(255,255,255,0.8);
        transition: all 0.3s;
    }
    .vip-slot.filled {
        background: #ffffff;
        border: 2px solid #f1c40f;
        color: #d35400;
        box-shadow: 0 4px 10px rgba(241, 196, 15, 0.4);
    }
    .vip-slot.reward-slot {
        background: rgba(241, 196, 15, 0.25);
        border-color: #f1c40f;
        color: #f1c40f;
    }
    .vip-slot.reward-slot.filled {
        background: linear-gradient(135deg, #f39c12, #f1c40f);
        color: #fff;
        border-color: #fff;
    }

    .vip-footer {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
    }
    .vip-client-info small {
        font-size: 0.68rem;
        opacity: 0.8;
        display: block;
        text-transform: uppercase;
    }
    .vip-client-info strong {
        font-size: 0.95rem;
    }
    .vip-qr-holder {
        background: white;
        padding: 5px;
        border-radius: 8px;
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    }
    #preview-qr canvas, #preview-qr img {
        width: 50px;
        height: 50px;
        display: block;
    }

    /* ── CARDS DE PROMOÇÃO ── */
    .promos-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 22px;
        margin-top: 20px;
    }
    .promo-card {
        background: #ffffff;
        border-radius: 16px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 4px 16px rgba(0,0,0,0.05);
        overflow: hidden;
        display: flex;
        flex-direction: column;
        transition: transform 0.25s, box-shadow 0.25s;
        position: relative;
    }
    .promo-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
    }
    .promo-banner {
        height: 170px;
        background: #f1f5f9;
        position: relative;
        overflow: hidden;
    }
    .promo-banner img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .promo-banner-placeholder {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #94a3b8;
        font-size: 3rem;
        background: linear-gradient(135deg, #e2e8f0, #cbd5e1);
    }
    .promo-badge-off {
        position: absolute;
        top: 12px;
        left: 12px;
        background: #e11d48;
        color: white;
        font-weight: 800;
        font-size: 0.85rem;
        padding: 5px 12px;
        border-radius: 20px;
        box-shadow: 0 4px 10px rgba(225, 29, 72, 0.4);
    }
    .promo-status-badge {
        position: absolute;
        top: 12px;
        right: 12px;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 700;
    }
    .promo-status-badge.active { background: #2ecc71; color: white; }
    .promo-status-badge.expired { background: #94a3b8; color: white; }

    /* CONTADOR REGRESSIVO EM TEMPO REAL */
    .promo-countdown-strip {
        background: #0f172a;
        color: #f8fafc;
        padding: 8px 14px;
        font-size: 0.8rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .promo-countdown-strip i { color: #f1c40f; }

    .promo-body {
        padding: 18px;
        display: flex;
        flex-direction: column;
        flex: 1;
    }
    .promo-title {
        font-size: 1.05rem;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 6px;
    }
    .promo-desc {
        font-size: 0.85rem;
        color: #64748b;
        margin-bottom: 12px;
        line-height: 1.4;
    }
    .promo-price-row {
        display: flex;
        align-items: baseline;
        gap: 10px;
        margin-top: auto;
        padding-top: 10px;
        border-top: 1px solid #f1f5f9;
    }
    .promo-old-price {
        font-size: 0.85rem;
        text-decoration: line-through;
        color: #94a3b8;
    }
    .promo-new-price {
        font-size: 1.25rem;
        font-weight: 800;
        color: #27ae60;
    }
    .promo-actions {
        display: flex;
        gap: 8px;
        margin-top: 15px;
    }

    /* ── FORMULÁRIOS ESTILIZADOS ── */
    .form-section-title {
        font-size: 1.05rem;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 2px solid #f1f5f9;
        padding-bottom: 8px;
    }
    .toggle-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        padding: 12px 16px;
        border-radius: 10px;
        margin-bottom: 10px;
    }
    .color-picker-box {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .color-picker-box input[type="color"] {
        width: 44px;
        height: 40px;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        padding: 0;
        background: none;
    }

    /* Terminal de Balcão */
    .balcao-terminal {
        background: linear-gradient(135deg, #1e293b, #0f172a);
        color: white;
        border-radius: 20px;
        padding: 25px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.25);
    }
</style>

<?php include 'includes/_sidebar.php'; ?>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; ?>

        <main class="main-content">

            <!-- CABEÇALHO DO CRM -->
            <div class="page-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 15px;">
                <div>
                    <h1 class="main-title"><i class="fas fa-award" style="color: #f1c40f;"></i> Programa de Fidelização & Ofertas</h1>
                    <p style="color: #777;">Configure o motor de fidelidade, cartões virtuais com QR Code e promoções com limitador de tempo.</p>
                </div>
                <div style="display: flex; gap: 10px;">
                    <a href="cartao_cliente.php?loja_id=<?php echo $id_loja; ?>" target="_blank" class="btn" style="background: #34495e; color: white; display: inline-flex; align-items: center; gap: 8px; border-radius: 8px; padding: 10px 18px; text-decoration: none; font-weight: 600;">
                        <i class="fas fa-mobile-alt"></i> Ver Cartão como Cliente
                    </a>
                    <button class="btn btn-primary" id="btn-abrir-nova-promo" style="display: inline-flex; align-items: center; gap: 8px; border-radius: 8px; padding: 10px 18px;">
                        <i class="fas fa-plus-circle"></i> Criar Nova Promoção
                    </button>
                </div>
            </div>

            <!-- TABS DE NAVEGAÇÃO INTERNA DO CRM -->
            <div class="fid-top-nav">
                <a href="crm_vendas.php" class="fid-tab-btn">
                    <i class="fas fa-calculator"></i> CRM Reposição Nutricional
                </a>
                <button class="fid-tab-btn active" data-tab="tab-cartao">
                    <i class="fas fa-layer-group"></i> 1. Hub de Regras & Cartões (Modular)
                </button>
                <button class="fid-tab-btn" data-tab="tab-promos">
                    <i class="fas fa-tags"></i> 2. Promoções Relâmpago (<?php echo count($promocoes); ?>)
                </button>
                <button class="fid-tab-btn" data-tab="tab-catalogo">
                    <i class="fas fa-gift"></i> 3. Catálogo & Tiers
                </button>
                <button class="fid-tab-btn" data-tab="tab-auditoria">
                    <i class="fas fa-chart-line"></i> 4. Auditoria & Balcão
                </button>
            </div>

            <!-- ════════════════════════════════════════════════════════════ -->
            <!-- ════════════════════════════════════════════════════════════ -->
            <!-- TAB 1: HUB DE REGRAS MODULARES & CARTÕES FIDELIDADE -->
            <!-- ════════════════════════════════════════════════════════════ -->
            <div class="tab-content-panel active" id="tab-cartao">
                
                <!-- TOP BAR DO HUB MODULAR -->
                <div class="widget-card" style="margin-bottom: 22px; background: #ffffff; border-radius: 16px; border: 1px solid #e2e8f0; padding: 22px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 18px;">
                        <div>
                            <h2 style="font-size: 1.25rem; color: #0f172a; margin-bottom: 4px; display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-layer-group" style="color: #0d9488;"></i> Hub de Regras Modulares de Fidelidade & Descontos
                            </h2>
                            <p style="color: #64748b; font-size: 0.88rem; margin: 0;">
                                Crie múltiplos cartões e regras simultâneas: selos, cashback, pontos ou descontos por produto/serviço.
                            </p>
                        </div>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <button type="button" class="btn btn-primary" id="btn-nova-regra-hub" style="display: inline-flex; align-items: center; gap: 8px; border-radius: 10px; padding: 12px 20px; font-weight: 700; background: #0d9488; border-color: #0d9488;">
                                <i class="fas fa-plus-circle"></i> Criar Nova Regra / Cartão
                            </button>
                        </div>
                    </div>

                    <!-- PRESETS RÁPIDOS EM 1 CLIQUE -->
                    <div style="background: #f8fafc; border-radius: 12px; padding: 14px 16px; border: 1px dashed #cbd5e1;">
                        <strong style="font-size: 0.8rem; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 10px;">
                            <i class="fas fa-magic" style="color: #f59e0b;"></i> Modelos Prontos (Clique para criar ou pré-configurar):
                        </strong>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;" id="presets-hub-bar">
                            <button type="button" class="btn-preset-regra" data-preset="selos_classico" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 14px; font-size: 0.82rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; color: #1e293b;">
                                <i class="fas fa-paw" style="color: #0d9488;"></i> Cartão 10 Selos (15% OFF)
                            </button>
                            <button type="button" class="btn-preset-regra" data-preset="clube_banho" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 14px; font-size: 0.82rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; color: #1e293b;">
                                <i class="fas fa-bath" style="color: #3b82f6;"></i> Clube do Banho (Compre 5 Ganhe 1)
                            </button>
                            <button type="button" class="btn-preset-regra" data-preset="terca_dobro" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 14px; font-size: 0.82rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; color: #1e293b;">
                                <i class="fas fa-calendar-day" style="color: #f59e0b;"></i> Terça Turbo (2x Selos)
                            </button>
                            <button type="button" class="btn-preset-regra" data-preset="cashback_5" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 14px; font-size: 0.82rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; color: #1e293b;">
                                <i class="fas fa-coins" style="color: #10b981;"></i> Cashback VIP 5%
                            </button>
                            <button type="button" class="btn-preset-regra" data-preset="aniversario" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 14px; font-size: 0.82rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; color: #1e293b;">
                                <i class="fas fa-birthday-cake" style="color: #ec4899;"></i> Mês Aniversariante (20% OFF)
                            </button>
                        </div>
                    </div>
                </div>

                <!-- GRADE DINÂMICA DE REGRAS CADASTRADAS -->
                <div id="grid-regras-hub" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 22px; margin-bottom: 30px;">
                    <div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: #64748b;">
                        <i class="fas fa-spinner fa-spin" style="font-size: 2rem;"></i> Carregando regras de fidelidade...
                    </div>
                </div>

                <!-- SEÇÃO COLAPSÁVEL: CONFIGURAÇÕES GLOBAIS LEGADAS & ESTILO PADRÃO -->
                <details style="background: #ffffff; border-radius: 14px; border: 1px solid #e2e8f0; padding: 18px; margin-top: 20px;">
                    <summary style="font-weight: 700; color: #334155; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-sliders-h" style="color: #64748b;"></i> Configurações Globais & Parâmetros Base do Cartão
                    </summary>
                    <div style="margin-top: 18px; border-top: 1px solid #f1f5f9; padding-top: 18px;">
                        <form id="form-fidelidade-config" enctype="multipart/form-data">
                            <input type="hidden" name="acao" value="salvar_config">
                            <input type="hidden" name="instituicao_id" value="<?php echo $id_loja; ?>">

                            <div class="form-group-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                                <div class="form-group">
                                    <label style="font-weight: 600;">Meta Padrão de Compras (Selos Base)</label>
                                    <input type="number" name="compras_para_recompensa" id="input-meta-selos" value="<?php echo htmlspecialchars($cfg['compras_para_recompensa']); ?>" min="2" max="30" class="form-control" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;">
                                </div>
                                <div class="form-group">
                                    <label style="font-weight: 600;">Desconto Padrão Desbloqueado (%)</label>
                                    <input type="number" step="0.5" name="recompensa_valor" id="input-desc-valor" value="<?php echo htmlspecialchars($cfg['recompensa_valor']); ?>" class="form-control" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;">
                                </div>
                            </div>

                            <div class="form-group-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                                <div class="form-group">
                                    <label style="font-weight: 600;">Validade dos Selos/Pontos</label>
                                    <select name="validade_meses" class="form-control" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;">
                                        <option value="3" <?php echo ($cfg['validade_meses'] == 3) ? 'selected' : ''; ?>>3 Meses</option>
                                        <option value="6" <?php echo ($cfg['validade_meses'] == 6) ? 'selected' : ''; ?>>6 Meses (Padrão)</option>
                                        <option value="12" <?php echo ($cfg['validade_meses'] == 12) ? 'selected' : ''; ?>>12 Meses (1 Ano)</option>
                                        <option value="0" <?php echo ($cfg['validade_meses'] == 0) ? 'selected' : ''; ?>>Nunca Expiram</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label style="font-weight: 600;">Teto Máximo de Desconto (R$)</label>
                                    <input type="number" step="1" name="limite_desconto_recompensa" value="<?php echo htmlspecialchars($cfg['limite_desconto_recompensa']); ?>" class="form-control" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;">
                                </div>
                            </div>

                            <div style="display: flex; justify-content: flex-end; margin-top: 15px;">
                                <button type="submit" class="btn btn-primary" id="btn-salvar-config" style="padding: 10px 20px; font-weight: 700; border-radius: 8px;">
                                    <i class="fas fa-save"></i> Salvar Parâmetros Base
                                </button>
                            </div>
                        </form>
                    </div>
                </details>

            </div>

            <!-- ════════════════════════════════════════════════════════════ -->
<!-- TAB 2: PROMOÇÕES COM TEMPORIZADOR, FOTO E DESCONTO -->
            <!-- ════════════════════════════════════════════════════════════ -->
            <div class="tab-content-panel" id="tab-promos">
                
                <div class="widget-card" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 20px;">
                    <div>
                        <h3 style="margin-bottom: 4px; color: #1e293b;">
                            <i class="fas fa-stopwatch" style="color: #e11d48;"></i> Promoções Relâmpago com Temporizador
                        </h3>
                        <p style="color: #64748b; font-size: 0.9rem;">
                            Cadastre campanhas com contagem regressiva em tempo real, foto do produto e desconto percentual.
                        </p>
                    </div>
                    <button class="btn btn-primary" id="btn-criar-promo-tab" style="display: inline-flex; align-items: center; gap: 8px;">
                        <i class="fas fa-plus"></i> Nova Promoção
                    </button>
                </div>

                <!-- GRID DE CARDS DAS PROMOÇÕES -->
                <div class="promos-grid" id="promos-container">
                    <?php if (count($promocoes) > 0): ?>
                        <?php foreach ($promocoes as $pr): 
                            $agora = time();
                            $fimTs = strtotime($pr['data_fim']);
                            $expirada = ($fimTs < $agora);
                        ?>
                            <div class="promo-card" data-fim="<?php echo date('c', $fimTs); ?>">
                                <div class="promo-banner">
                                    <?php if (!empty($pr['imagem_url'])): ?>
                                        <img src="<?php echo htmlspecialchars($pr['imagem_url']); ?>" alt="Foto Promoção">
                                    <?php else: ?>
                                        <div class="promo-banner-placeholder"><i class="fas fa-paw"></i></div>
                                    <?php endif; ?>
                                    
                                    <div class="promo-badge-off">-<?php echo (int)$pr['desconto_percentual']; ?>% OFF</div>
                                    
                                    <?php if ($expirada): ?>
                                        <div class="promo-status-badge expired"><i class="fas fa-clock"></i> Expirada</div>
                                    <?php else: ?>
                                        <div class="promo-status-badge active"><i class="fas fa-bolt"></i> Ativa</div>
                                    <?php endif; ?>
                                </div>

                                <!-- STRIP COM CONTADOR REGRESSIVO EM TEMPO REAL -->
                                <div class="promo-countdown-strip">
                                    <span><i class="fas fa-hourglass-start"></i> Tempo Restante:</span>
                                    <strong class="countdown-timer" data-timestamp="<?php echo $fimTs; ?>">Calculando...</strong>
                                </div>

                                <div class="promo-body">
                                    <h4 class="promo-title"><?php echo htmlspecialchars($pr['titulo']); ?></h4>
                                    <?php if (!empty($pr['nome_produto'])): ?>
                                        <div style="font-size: 0.8rem; color: #2563eb; font-weight: 600; margin-bottom: 6px;">
                                            <i class="fas fa-box"></i> <?php echo htmlspecialchars($pr['nome_produto']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <p class="promo-desc"><?php echo htmlspecialchars($pr['descricao'] ?: 'Sem descrição detalhada.'); ?></p>

                                    <div class="promo-price-row">
                                        <?php if ($pr['preco_promocional'] > 0): ?>
                                            <span class="promo-old-price">De R$ <?php echo number_format($pr['preco_original'], 2, ',', '.'); ?></span>
                                            <span class="promo-new-price">Por R$ <?php echo number_format($pr['preco_promocional'], 2, ',', '.'); ?></span>
                                        <?php else: ?>
                                            <span class="promo-new-price"><?php echo (int)$pr['desconto_percentual']; ?>% de Desconto</span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="promo-actions">
                                        <?php 
                                            // Link WhatsApp para compartilhar
                                            $msgPromo = "🔥 *OFERTA RELÂMPAGO DO PET SHOP!* 🔥\n\n*{$pr['titulo']}*\n" . ($pr['preco_promocional'] > 0 ? "De ~R$ " . number_format($pr['preco_original'], 2, ',', '.') . "~ por apenas *R$ " . number_format($pr['preco_promocional'], 2, ',', '.') . "* (" . (int)$pr['desconto_percentual'] . "% OFF)!\n\n" : "") . "⏰ Promoção por tempo limitado até " . date('d/m/Y H:i', $fimTs) . ".\n\nGaranta a sua antes que acabe!";
                                            $linkZapPromo = "https://api.whatsapp.com/send?text=" . urlencode($msgPromo);
                                        ?>
                                        <a href="<?php echo $linkZapPromo; ?>" target="_blank" class="btn" style="flex: 1.2; text-align: center; background: #25D366; color: white; padding: 8px; border-radius: 6px; font-size: 0.82rem; font-weight: 600; text-decoration: none;">
                                            <i class="fab fa-whatsapp"></i> Divulgar WhatsApp
                                        </a>
                                        <button class="btn btn-sm btn-delete-promo" data-id="<?php echo $pr['id']; ?>" style="background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; padding: 8px 12px; border-radius: 6px; cursor: pointer;">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="grid-column: 1 / -1; text-align: center; padding: 60px 20px; background: white; border-radius: 16px; border: 2px dashed #cbd5e1;">
                            <i class="fas fa-bullhorn" style="font-size: 3.5rem; color: #cbd5e1; margin-bottom: 15px;"></i>
                            <h3 style="color: #475569; margin-bottom: 8px;">Nenhuma promoção cadastrada ainda</h3>
                            <p style="color: #94a3b8; max-width: 480px; margin: 0 auto 20px;">
                                Crie sua primeira oferta relâmpago com foto de produto, porcentagem de desconto e contador de tempo para atrair clientes.
                            </p>
                            <button class="btn btn-primary" onclick="abrirModalPromo()">
                                <i class="fas fa-plus"></i> Criar Primeira Promoção
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

            <!-- ════════════════════════════════════════════════════════════ -->
            <!-- TAB 3: CATÁLOGO DE RESGATE & GAMIFICAÇÃO (TIERS) -->
            <!-- ════════════════════════════════════════════════════════════ -->
            <div class="tab-content-panel" id="tab-catalogo">
                <div class="fid-grid-2col">
                    
                    <!-- 2. CATÁLOGO DE RESGATE (O QUE O CLIENTE GANHA) -->
                    <div class="widget-card">
                        <div class="form-section-title">
                            <i class="fas fa-gift" style="color: #e67e22;"></i> 2. Catálogo de Resgate (O que o cliente ganha)
                        </div>
                        <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 20px;">
                            Configure os prêmios que os pontos ou selos virtuais desbloqueiam no caixa.
                        </p>

                        <div style="background: #f8fafc; border-radius: 12px; padding: 18px; border: 1px solid #e2e8f0; margin-bottom: 18px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                <strong style="font-size: 0.95rem; color: #1e293b;">
                                    <i class="fas fa-ticket-alt" style="color: #1abc9c;"></i> Desconto no Ciclo (Padrão)
                                </strong>
                                <span class="badge" style="background: #dcfce7; color: #15803d; font-weight: 700; padding: 4px 10px; border-radius: 20px;">Ativo</span>
                            </div>
                            <div style="font-size: 0.88rem; color: #475569;">
                                Ao completar <strong><?php echo $cfg['compras_para_recompensa']; ?> compras/selos</strong>, o cliente desbloqueia <strong><?php echo $cfg['recompensa_valor']; ?>% de desconto</strong> com limite máximo de <strong>R$ <?php echo number_format($cfg['limite_desconto_recompensa'], 2, ',', '.'); ?></strong>.
                            </div>
                        </div>

                        <div style="background: #f8fafc; border-radius: 12px; padding: 18px; border: 1px solid #e2e8f0; margin-bottom: 18px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                <strong style="font-size: 0.95rem; color: #1e293b;">
                                    <i class="fas fa-coins" style="color: #f1c40f;"></i> Cashback na Carteira Virtual
                                </strong>
                                <span class="badge" style="background: #fef3c7; color: #b45309; font-weight: 700; padding: 4px 10px; border-radius: 20px;">5% Ativo</span>
                            </div>
                            <div style="font-size: 0.88rem; color: #475569;">
                                5% de cada compra fica acumulado como crédito para o cliente abater no próximo banho, consulta ou ração.
                            </div>
                        </div>

                        <!-- PRODUTOS / SERVIÇOS DO ESTOQUE COMO PRÊMIO -->
                        <h4 style="font-size: 0.95rem; margin-top: 25px; margin-bottom: 10px; color: #334155;">
                            <i class="fas fa-boxes" style="color: #3b82f6;"></i> Itens do Estoque Resgatáveis com Pontos
                        </h4>
                        <table class="data-table" style="width: 100%; border-collapse: collapse; font-size: 0.88rem;">
                            <thead>
                                <tr style="border-bottom: 2px solid #e2e8f0; text-align: left;">
                                    <th style="padding: 8px;">Item / Serviço</th>
                                    <th style="padding: 8px;">Pontos p/ Trocar</th>
                                    <th style="padding: 8px;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td style="padding: 10px;"><strong>Banho Higiênico Completo</strong></td>
                                    <td style="padding: 10px;"><span style="background: #fef08a; padding: 3px 8px; border-radius: 12px; font-weight: bold; color: #854d0e;">150 pts</span></td>
                                    <td style="padding: 10px; color: #22c55e; font-weight: 600;">Disponível</td>
                                </tr>
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td style="padding: 10px;"><strong>Petisco Sachê Premium</strong></td>
                                    <td style="padding: 10px;"><span style="background: #fef08a; padding: 3px 8px; border-radius: 12px; font-weight: bold; color: #854d0e;">40 pts</span></td>
                                    <td style="padding: 10px; color: #22c55e; font-weight: 600;">Disponível</td>
                                </tr>
                                <tr>
                                    <td style="padding: 10px;"><strong>Hidratação de Pelagem</strong></td>
                                    <td style="padding: 10px;"><span style="background: #fef08a; padding: 3px 8px; border-radius: 12px; font-weight: bold; color: #854d0e;">90 pts</span></td>
                                    <td style="padding: 10px; color: #22c55e; font-weight: 600;">Disponível</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- 3. GAMIFICAÇÃO & TIERS -->
                    <div class="widget-card">
                        <div class="form-section-title">
                            <i class="fas fa-trophy" style="color: #f1c40f;"></i> 3. Gamificação por Níveis (Tiers)
                        </div>
                        <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 20px;">
                            Estimule os clientes a subirem de nível através de metas de gastos e benefícios progressivos.
                        </p>

                        <!-- TIERS CARDS -->
                        <div style="display: flex; flex-direction: column; gap: 15px;">
                            
                            <!-- BRONZE -->
                            <div style="border-left: 5px solid #cd7f32; background: #fffaf0; padding: 16px; border-radius: 10px; border-top: 1px solid #fde68a; border-right: 1px solid #fde68a; border-bottom: 1px solid #fde68a;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                    <strong style="color: #78350f; font-size: 1rem;"><i class="fas fa-shield-alt"></i> Nível Bronze</strong>
                                    <span style="font-size: 0.8rem; font-weight: 700; color: #78350f;">Meta: R$ 0 a R$ 499</span>
                                </div>
                                <div style="font-size: 0.85rem; color: #92400e;">
                                    <strong>Benefícios:</strong> 1x pontos em todas as compras + Cartão de Selos com 15% OFF a cada 10 compras.
                                </div>
                            </div>

                            <!-- PRATA -->
                            <div style="border-left: 5px solid #94a3b8; background: #f8fafc; padding: 16px; border-radius: 10px; border-top: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                    <strong style="color: #334155; font-size: 1rem;"><i class="fas fa-medal"></i> Nível Prata</strong>
                                    <span style="font-size: 0.8rem; font-weight: 700; color: #475569;">Meta: R$ 500 acumulados</span>
                                </div>
                                <div style="font-size: 0.85rem; color: #475569;">
                                    <strong>Benefícios:</strong> 1.2x multiplicador de pontos + 5% de desconto extra no mês de aniversário do pet.
                                </div>
                            </div>

                            <!-- OURO VIP -->
                            <div style="border-left: 5px solid #f59e0b; background: #fefce8; padding: 16px; border-radius: 10px; border-top: 1px solid #fef08a; border-right: 1px solid #fef08a; border-bottom: 1px solid #fef08a;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                    <strong style="color: #854d0e; font-size: 1rem;"><i class="fas fa-crown"></i> Nível Ouro VIP</strong>
                                    <span style="font-size: 0.8rem; font-weight: 700; color: #854d0e;">Meta: R$ 1.500 acumulados</span>
                                </div>
                                <div style="font-size: 0.85rem; color: #854d0e;">
                                    <strong>Benefícios:</strong> 1.5x pontos + Brinde surpresa a cada banho + Fila prioritária no atendimento do balcão.
                                </div>
                            </div>

                        </div>
                    </div>

                </div>
            </div>

            <!-- ════════════════════════════════════════════════════════════ -->
            <!-- TAB 4: AUDITORIA, KPIS & OPERAÇÃO DE BALCÃO -->
            <!-- ════════════════════════════════════════════════════════════ -->
            <div class="tab-content-panel" id="tab-auditoria">
                
                <!-- KPIS PRINCIPAIS -->
                <div class="kpi-widgets" style="margin-bottom: 25px;">
                    <div class="widget-card kpi-card">
                        <div class="card-icon blue"><i class="fas fa-users"></i></div>
                        <div class="card-content">
                            <span class="card-value"><?php echo $total_clientes_fidelizados; ?></span>
                            <span class="card-label">Clientes Fidelizados</span>
                        </div>
                    </div>

                    <div class="widget-card kpi-card">
                        <div class="card-icon green"><i class="fas fa-stamp"></i></div>
                        <div class="card-content">
                            <span class="card-value"><?php echo $total_selos_circulando; ?></span>
                            <span class="card-label">Selos Ativos em Circulação</span>
                        </div>
                    </div>

                    <div class="widget-card kpi-card">
                        <div class="card-icon purple"><i class="fas fa-award"></i></div>
                        <div class="card-content">
                            <span class="card-value"><?php echo $total_ciclos_resgatados; ?></span>
                            <span class="card-label">Ciclos Completos (10 Compras)</span>
                        </div>
                    </div>

                    <div class="widget-card kpi-card">
                        <div class="card-icon" style="background:#fee2e2; color:#ef4444;"><i class="fas fa-hand-holding-usd"></i></div>
                        <div class="card-content">
                            <span class="card-value">R$ <?php echo number_format($total_descontos_mes, 2, ',', '.'); ?></span>
                            <span class="card-label">Descontos Cedidos este Mês</span>
                        </div>
                    </div>
                </div>

                <div class="fid-grid-2col">
                    
                    <!-- TERMINAL RÁPIDO DE BALCÃO (ATENDENTE / CAIXA) -->
                    <div class="balcao-terminal">
                        <h3 style="font-size: 1.15rem; margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-cash-register" style="color: #1abc9c;"></i> Terminal Rápido de Balcão
                        </h3>
                        <p style="font-size: 0.85rem; color: #94a3b8; margin-bottom: 18px;">
                            Localize o cliente para adicionar o selo da compra ou resgatar o desconto de 10 compras.
                        </p>

                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="font-size: 0.85rem; color: #cbd5e1;">Buscar Cliente (Nome, CPF ou Telefone):</label>
                            <input type="text" id="balcao-busca" placeholder="Digite 2 ou mais letras..." style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #334155; background: #0f172a; color: white; font-size: 0.95rem;">
                            <div id="balcao-resultados" style="display: none; background: #1e293b; border-radius: 8px; border: 1px solid #334155; margin-top: 6px; max-height: 200px; overflow-y: auto;"></div>
                        </div>

                        <!-- PAINEL DO CLIENTE SELECIONADO -->
                        <div id="balcao-cliente-card" style="display: none; background: #0f172a; border-radius: 12px; padding: 16px; border: 1px solid #334155; margin-bottom: 15px;">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                                <div>
                                    <strong id="balcao-cli-nome" style="font-size: 1.05rem; color: #f8fafc; display: block;">Nome do Cliente</strong>
                                    <small id="balcao-cli-cpf" style="color: #94a3b8;">CPF: 000.000.000-00</small>
                                </div>
                                <span id="balcao-cli-tier" style="background: #f1c40f; color: #000; font-weight: 700; font-size: 0.75rem; padding: 3px 8px; border-radius: 12px;">Bronze</span>
                            </div>

                            <div style="background: #1e293b; padding: 12px; border-radius: 8px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center;">
                                <span>Selos Acumulados:</span>
                                <strong style="font-size: 1.2rem; color: #2ecc71;">
                                    <span id="balcao-cli-selos">0</span> / <?php echo $cfg['compras_para_recompensa']; ?>
                                </strong>
                            </div>

                            <div class="form-group" style="margin-bottom: 15px;">
                                <label style="font-size: 0.8rem; color: #94a3b8;">Valor da Compra (R$):</label>
                                <input type="number" step="0.01" id="balcao-valor-compra" value="50.00" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid #334155; background: #1e293b; color: white;">
                            </div>

                            <div style="display: flex; gap: 10px;">
                                <button type="button" class="btn" id="btn-add-selo" style="flex: 1.2; background: #1abc9c; color: white; padding: 12px; border-radius: 8px; font-weight: 700; border: none; cursor: pointer;">
                                    <i class="fas fa-stamp"></i> Adicionar Selo
                                </button>
                                <button type="button" class="btn" id="btn-resgatar-premio" style="flex: 1; background: #e67e22; color: white; padding: 12px; border-radius: 8px; font-weight: 700; border: none; cursor: pointer; display: none;">
                                    <i class="fas fa-gift"></i> Resgatar 15%
                                </button>
                            </div>
                        </div>

                    </div>

                    <!-- LOGS DE AUDITORIA & HISTÓRICO DE TRANSAÇÕES -->
                    <div class="widget-card">
                        <div class="form-section-title">
                            <i class="fas fa-history" style="color: #64748b;"></i> 5. Auditoria & Log de Transações
                        </div>
                        <p style="color: #64748b; font-size: 0.85rem; margin-bottom: 15px;">
                            Rastreabilidade completa de todas as emissões e resgates para segurança do caixa.
                        </p>

                        <div style="overflow-x: auto;">
                            <table class="data-table" style="width: 100%; font-size: 0.83rem;">
                                <thead>
                                    <tr style="border-bottom: 2px solid #e2e8f0; text-align: left;">
                                        <th style="padding: 8px;">Data/Hora</th>
                                        <th style="padding: 8px;">Cliente</th>
                                        <th style="padding: 8px;">Operação</th>
                                        <th style="padding: 8px;">Operador</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($transacoes_recentes) > 0): ?>
                                        <?php foreach ($transacoes_recentes as $log): ?>
                                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                                <td style="padding: 8px; color: #64748b; white-space: nowrap;">
                                                    <?php echo date('d/m/Y H:i', strtotime($log['data_transacao'])); ?>
                                                </td>
                                                <td style="padding: 8px;">
                                                    <strong><?php echo htmlspecialchars($log['nome_completo']); ?></strong>
                                                </td>
                                                <td style="padding: 8px;">
                                                    <?php if ($log['tipo'] === 'acumulo_selo'): ?>
                                                        <span style="color: #059669; font-weight: 600;"><i class="fas fa-plus-circle"></i> Selo Acumulado</span>
                                                    <?php elseif ($log['tipo'] === 'resgate_recompensa'): ?>
                                                        <span style="color: #d97706; font-weight: 700;"><i class="fas fa-gift"></i> Resgate Desconto (R$ <?php echo number_format($log['valor_recompensa'], 2, ',', '.'); ?>)</span>
                                                    <?php else: ?>
                                                        <span><?php echo htmlspecialchars($log['tipo']); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="padding: 8px; color: #64748b;">
                                                    <?php echo htmlspecialchars($log['usuario_operador'] ?? 'Sistema'); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" style="text-align: center; padding: 20px; color: #94a3b8;">
                                                Nenhuma transação registrada ainda.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>

            </div>

        </main>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════ -->
<!-- MODAL: CADASTRAR / EDITAR PROMOÇÃO -->
<!-- ════════════════════════════════════════════════════════════ -->
<!-- ════════════════════════════════════════════════════════════ -->
<!-- MODAL: CONSTRUTOR MODULAR DE REGRAS DE FIDELIDADE & DESCONTOS -->
<!-- ════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-construtor-regra" style="display: none; align-items: center; justify-content: center; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.75); z-index: 9999; backdrop-filter: blur(4px);">
    <div class="modal-content" style="background: #ffffff; border-radius: 20px; width: 100%; max-width: 880px; max-height: 90vh; overflow-y: auto; padding: 28px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35); position: relative;">
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #f1f5f9; padding-bottom: 14px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 44px; height: 44px; border-radius: 12px; background: #0d9488; color: white; display: flex; align-items: center; justify-content: center; font-size: 1.3rem;">
                    <i class="fas fa-magic"></i>
                </div>
                <div>
                    <h3 style="margin: 0; color: #0f172a; font-size: 1.25rem; font-weight: 800;" id="modal-regra-titulo">Nova Regra de Fidelidade & Desconto</h3>
                    <small style="color: #64748b;">Configure a mecânica, condições, recompensas e visual do cartão.</small>
                </div>
            </div>
            <button type="button" class="close-modal-btn" onclick="fecharModalConstrutor()" style="background: none; border: none; font-size: 1.6rem; cursor: pointer; color: #94a3b8;">&times;</button>
        </div>

        <form id="form-salvar-regra-modular">
            <input type="hidden" name="id" id="regra-id-input" value="0">
            <input type="hidden" name="instituicao_id" value="<?php echo $id_loja; ?>">

            <div style="display: grid; grid-template-columns: 1.1fr 0.9fr; gap: 24px; align-items: start;">
                
                <!-- COLUNA ESQUERDA: PARÂMETROS DA REGRA -->
                <div>
                    <div style="margin-bottom: 16px;">
                        <label style="font-weight: 700; color: #1e293b; display: block; margin-bottom: 6px; font-size: 0.9rem;">
                            1. Nome da Regra / Campanha *
                        </label>
                        <input type="text" name="nome_regra" id="regra-nome-input" required placeholder="Ex: Clube do Banho VIP, Terça Turbo..." class="form-control" style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-weight: 600; font-size: 0.95rem;">
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label style="font-weight: 700; color: #1e293b; display: block; margin-bottom: 6px; font-size: 0.9rem;">
                            2. Mecânica do Programa *
                        </label>
                        <select name="tipo_mecanica" id="regra-mecanica-select" class="form-control" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-weight: 600;">
                            <option value="cartao_selos">🎫 Cartão de Selos (Gamificação por Compras)</option>
                            <option value="pontos">⭐ Pontuação Financeira (R$ 1 gasto = Pontos)</option>
                            <option value="cashback">💰 Cashback (% do valor volta como crédito)</option>
                            <option value="compre_ganhe">🎁 Compre X e Ganhe Y (Ex: 5 banhos ganha 1)</option>
                            <option value="desconto_direto">🏷️ Desconto Direto / Especial</option>
                        </select>
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label style="font-weight: 700; color: #1e293b; display: block; margin-bottom: 6px; font-size: 0.9rem;">
                            3. Onde se Aplica (Itens Elegíveis)
                        </label>
                        <select name="categoria_alvo" id="regra-alvo-select" class="form-control" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;">
                            <option value="todos">🌐 Todos os Produtos e Serviços da Loja</option>
                            <option value="servicos">✂️ Apenas Serviços (Banho, Tosa, Clínica...)</option>
                            <option value="produtos">📦 Apenas Produtos (Rações, Petiscos, Farmácia...)</option>
                            <option value="item_especifico">🎯 Item Específico do Catálogo</option>
                        </select>
                    </div>

                    <div id="box-item-especifico" style="margin-bottom: 16px; display: none;">
                        <label style="font-weight: 600; color: #475569; display: block; margin-bottom: 5px; font-size: 0.85rem;">Selecione o Item Específico:</label>
                        <select name="item_alvo_id" id="regra-item-alvo-select" class="form-control" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;">
                            <optgroup label="Serviços">
                                <?php foreach ($servicos_loja as $sv): ?>
                                    <option value="<?php echo $sv['id']; ?>"><?php echo htmlspecialchars($sv['nome_servico']); ?> (R$ <?php echo number_format($sv['preco_padrao'], 2, ',', '.'); ?>)</option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Produtos">
                                <?php foreach ($produtos_loja as $pr): ?>
                                    <option value="<?php echo $pr['id']; ?>"><?php echo htmlspecialchars($pr['nome_produto']); ?> (R$ <?php echo number_format($pr['preco_venda'], 2, ',', '.'); ?>)</option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label style="font-weight: 700; color: #1e293b; display: block; margin-bottom: 6px; font-size: 0.9rem;">
                            4. Condição / Meta para Desbloquear
                        </label>
                        <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 10px;">
                            <select name="condicao_tipo" id="regra-condicao-tipo" class="form-control" style="padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;">
                                <option value="quantidade_compras">Meta de Compras / Selos</option>
                                <option value="valor_minimo">Valor Mínimo da Compra</option>
                                <option value="dia_semana">Dia Específico da Semana</option>
                                <option value="aniversario">Mês de Aniversário</option>
                                <option value="sem_condicao">Sem Condição Mínima</option>
                            </select>
                            <input type="number" step="0.5" name="condicao_valor" id="regra-condicao-valor" value="10" placeholder="Meta / Valor" class="form-control" style="padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-weight: 700;">
                        </div>
                    </div>

                    <div id="box-dias-semana" style="margin-bottom: 16px; display: none; background: #f8fafc; padding: 12px; border-radius: 10px; border: 1px solid #e2e8f0;">
                        <label style="font-size: 0.8rem; font-weight: 700; color: #475569; display: block; margin-bottom: 6px;">Dias em que a promoção é válida:</label>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <label><input type="checkbox" name="dias[]" value="seg"> Seg</label>
                            <label><input type="checkbox" name="dias[]" value="ter"> Ter</label>
                            <label><input type="checkbox" name="dias[]" value="qua"> Qua</label>
                            <label><input type="checkbox" name="dias[]" value="qui"> Qui</label>
                            <label><input type="checkbox" name="dias[]" value="sex"> Sex</label>
                            <label><input type="checkbox" name="dias[]" value="sab"> Sáb</label>
                            <label><input type="checkbox" name="dias[]" value="dom"> Dom</label>
                        </div>
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label style="font-weight: 700; color: #1e293b; display: block; margin-bottom: 6px; font-size: 0.9rem;">
                            5. Recompensa / Benefício ao Cliente
                        </label>
                        <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 10px; margin-bottom: 8px;">
                            <select name="recompensa_tipo" id="regra-recompensa-tipo" class="form-control" style="padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;">
                                <option value="desconto_percentual">Desconto Percentual (%)</option>
                                <option value="desconto_fixo">Desconto em Reais (R$)</option>
                                <option value="item_gratis">Item / Serviço Grátis</option>
                                <option value="cashback">Cashback (%)</option>
                            </select>
                            <input type="number" step="0.5" name="recompensa_valor" id="regra-recompensa-valor" value="15" placeholder="Valor" class="form-control" style="padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-weight: 700; color: #059669;">
                        </div>
                        <div id="box-recompensa-item" style="display: none; margin-top: 8px;">
                            <select name="recompensa_item_id" id="regra-recompensa-item-select" class="form-control" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;">
                                <optgroup label="Serviços Gratuitos">
                                    <?php foreach ($servicos_loja as $sv): ?>
                                        <option value="<?php echo $sv['id']; ?>"><?php echo htmlspecialchars($sv['nome_servico']); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <optgroup label="Produtos / Brindes">
                                    <?php foreach ($produtos_loja as $pr): ?>
                                        <option value="<?php echo $pr['id']; ?>"><?php echo htmlspecialchars($pr['nome_produto']); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            </select>
                        </div>
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label style="font-weight: 600; color: #475569; display: block; margin-bottom: 5px; font-size: 0.85rem;">
                            Trava de Segurança / Teto Máximo de Desconto (R$)
                        </label>
                        <input type="number" step="1" name="limite_desconto_recompensa" id="regra-limite-desconto" value="50" placeholder="Sem teto" class="form-control" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;">
                    </div>
                </div>

                <!-- COLUNA DIREITA: VISUAL DO CARTÃO & PREVIEW EM TEMPO REAL -->
                <div style="background: #f8fafc; border-radius: 14px; padding: 18px; border: 1px solid #e2e8f0;">
                    <h4 style="font-size: 0.95rem; font-weight: 700; color: #0f172a; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
                        <i class="fas fa-palette" style="color: #9333ea;"></i> 6. Identidade Visual do Cartão
                    </h4>

                    <div style="margin-bottom: 12px;">
                        <label style="font-size: 0.82rem; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Texto no Topo do Cartão:</label>
                        <input type="text" name="texto_cartao" id="regra-texto-cartao" value="Cartão Fidelidade Pet VIP" class="form-control" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px;">
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px;">
                        <div>
                            <label style="font-size: 0.82rem; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Cor Primária:</label>
                            <input type="color" name="cor_hex" id="regra-cor-primaria" value="#0d9488" style="width: 100%; height: 38px; border: none; border-radius: 6px; cursor: pointer;">
                        </div>
                        <div>
                            <label style="font-size: 0.82rem; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Cor Secundária:</label>
                            <input type="color" name="cor_secundaria" id="regra-cor-secundaria" value="#115e59" style="width: 100%; height: 38px; border: none; border-radius: 6px; cursor: pointer;">
                        </div>
                    </div>

                    <div style="margin-bottom: 14px;">
                        <label style="font-size: 0.82rem; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Ícone Temático:</label>
                        <select name="icone" id="regra-icone-select" class="form-control" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 8px;">
                            <option value="fas fa-paw">🐾 Patinha Pet</option>
                            <option value="fas fa-bath">🛁 Banho & Higiene</option>
                            <option value="fas fa-cut">✂️ Tosa & Estilo</option>
                            <option value="fas fa-star">⭐ Estrela VIP</option>
                            <option value="fas fa-crown">👑 Coroa Ouro</option>
                            <option value="fas fa-gift">🎁 Caixa de Presente</option>
                            <option value="fas fa-coins">💰 Moeda / Cashback</option>
                            <option value="fas fa-heart">❤️ Coração Tutor</option>
                        </select>
                    </div>

                    <!-- PREVIEW AO VIVO DO CARTÃO DENTRO DO MODAL -->
                    <div style="margin-top: 15px;">
                        <small style="font-weight: 700; color: #64748b; text-transform: uppercase; font-size: 0.72rem; display: block; margin-bottom: 6px;">
                            Pré-visualização do Cartão do Cliente:
                        </small>
                        
                        <div id="modal-card-live-preview" style="background: linear-gradient(135deg, #115e59, #0d9488); border-radius: 18px; padding: 18px 16px; color: white; box-shadow: 0 10px 25px rgba(0,0,0,0.2); position: relative; overflow: hidden; transition: all 0.3s;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <div id="modal-preview-icon" style="width: 32px; height: 32px; border-radius: 8px; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-size: 1rem;">
                                        <i class="fas fa-paw"></i>
                                    </div>
                                    <div>
                                        <div id="modal-preview-title" style="font-weight: 700; font-size: 0.92rem; line-height: 1.2;">Cartão Fidelidade Pet VIP</div>
                                        <small style="opacity: 0.85; font-size: 0.7rem; text-transform: uppercase;">Cliente VIP</small>
                                    </div>
                                </div>
                                <span style="background: rgba(255,255,255,0.25); font-size: 0.72rem; font-weight: 700; padding: 3px 8px; border-radius: 12px;">Nível VIP</span>
                            </div>

                            <div style="background: rgba(0,0,0,0.18); border-radius: 12px; padding: 10px; margin-bottom: 10px; font-size: 0.78rem;">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 6px; font-weight: 600;">
                                    <span>Progresso do Cartão:</span>
                                    <span id="modal-preview-progress-text">3 de 10 selos</span>
                                </div>
                                <div id="modal-preview-slots" style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 6px;">
                                    <!-- Slots preenchidos via JS -->
                                </div>
                            </div>

                            <div style="display: flex; justify-content: space-between; align-items: flex-end; font-size: 0.75rem;">
                                <div>
                                    <span style="opacity: 0.8; display: block; font-size: 0.65rem;">Benefício:</span>
                                    <strong id="modal-preview-benefit" style="font-size: 0.85rem; color: #fef08a;">15% de Desconto</strong>
                                </div>
                                <div style="background: white; padding: 4px; border-radius: 6px; color: #0f172a; font-size: 0.7rem; font-weight: bold;">
                                    QR CODE
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

            </div>

            <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px; border-top: 1px solid #f1f5f9; padding-top: 18px;">
                <button type="button" class="btn btn-secondary" onclick="fecharModalConstrutor()" style="padding: 10px 18px; border-radius: 8px; font-weight: 600;">
                    Cancelar
                </button>
                <button type="submit" class="btn btn-primary" id="btn-submit-regra" style="padding: 10px 24px; border-radius: 8px; font-weight: 700; background: #0d9488; border-color: #0d9488; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-save"></i> Salvar Regra de Fidelidade
                </button>
            </div>
        </form>

    </div>
</div>

<div class="modal-overlay" id="promo-modal" style="display: none; align-items: center; justify-content: center; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999;">
    <div class="modal-content" style="background: white; border-radius: 16px; width: 100%; max-width: 580px; padding: 26px; box-shadow: 0 20px 40px rgba(0,0,0,0.3); max-height: 90vh; overflow-y: auto;">
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #f1f5f9; padding-bottom: 12px;">
            <h3 style="margin: 0; color: #1e293b; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-tags" style="color: #e11d48;"></i> Nova Promoção com Temporizador
            </h3>
            <button class="close-modal-btn" onclick="fecharModalPromo()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #94a3b8;">&times;</button>
        </div>

        <form id="form-salvar-promo" enctype="multipart/form-data">
            <input type="hidden" name="acao" value="salvar_promocao">
            <input type="hidden" name="instituicao_id" value="<?php echo $id_loja; ?>">
            <input type="hidden" name="promo_id" id="promo-id-input" value="0">

            <div class="form-group" style="margin-bottom: 14px;">
                <label style="font-weight: 600; display: block; margin-bottom: 4px;">Título da Promoção *</label>
                <input type="text" name="titulo" id="promo-titulo-input" required placeholder="Ex: Festival de Rações Premier - 25% OFF" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;">
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label style="font-weight: 600; display: block; margin-bottom: 4px;">Vincular Produto do Estoque (Opcional)</label>
                <select name="produto_id" id="promo-produto-select" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;">
                    <option value="">-- Selecionar Produto do Estoque --</option>
                    <?php foreach ($produtos_loja as $prod): ?>
                        <option value="<?php echo $prod['id']; ?>" data-preco="<?php echo $prod['preco_venda']; ?>">
                            <?php echo htmlspecialchars($prod['nome_produto']); ?> (Preço Normal: R$ <?php echo number_format($prod['preco_venda'], 2, ',', '.'); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                <div class="form-group">
                    <label style="font-weight: 600; display: block; margin-bottom: 4px;">Desconto Percentual (%) *</label>
                    <input type="number" step="1" min="1" max="90" name="desconto_percentual" id="promo-desconto-input" value="20" required style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;">
                </div>
                <div class="form-group">
                    <label style="font-weight: 600; display: block; margin-bottom: 4px;">Preço Promocional Calculado</label>
                    <input type="text" id="promo-preco-calculado" readonly value="R$ 0,00" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; background: #f8fafc; font-weight: 700; color: #27ae60;">
                </div>
            </div>

            <!-- FOTO DO PRODUTO / PROMOÇÃO -->
            <div class="form-group" style="margin-bottom: 16px;">
                <label style="font-weight: 600; display: block; margin-bottom: 4px;">
                    <i class="fas fa-camera"></i> Foto do Produto / Banner Promocional
                </label>
                <input type="file" name="imagem_promocao" id="promo-img-input" accept="image/*" style="width: 100%; padding: 8px; border: 1px dashed #cbd5e1; border-radius: 8px; background: #fdfdfd;">
                <small style="color: #64748b;">Adicione uma foto real do produto para exibir no card da promoção.</small>
            </div>

            <!-- LIMITADOR DE TEMPO (DATA INÍCIO E DATA FIM) -->
            <div class="form-group-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px;">
                <div class="form-group">
                    <label style="font-weight: 600; display: block; margin-bottom: 4px;">Data e Hora de Início *</label>
                    <input type="datetime-local" name="data_inicio" id="promo-inicio-input" required value="<?php echo date('Y-m-d\TH:i'); ?>" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;">
                </div>
                <div class="form-group">
                    <label style="font-weight: 600; display: block; margin-bottom: 4px;">Data e Hora de Término *</label>
                    <input type="datetime-local" name="data_fim" id="promo-fim-input" required value="<?php echo date('Y-m-d\TH:i', strtotime('+3 days')); ?>" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;">
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 20px;">
                <label style="font-weight: 600; display: block; margin-bottom: 4px;">Descrição Comercial</label>
                <textarea name="descricao" id="promo-desc-input" rows="2" placeholder="Ex: Válido até durarem os estoques. Apenas para clientes VIP." style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;"></textarea>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="fecharModalPromo()">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="btn-submit-promo">
                    <i class="fas fa-check"></i> Salvar Promoção Relâmpago
                </button>
            </div>
        </form>

    </div>
</div>

<script>
// ══════════════════════════════════════════════════════════════════
// 1. TABS NAVIGATION INTERNA
// ══════════════════════════════════════════════════════════════════
document.querySelectorAll('.fid-tab-btn[data-tab]').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        document.querySelectorAll('.fid-tab-btn[data-tab]').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content-panel').forEach(p => p.classList.remove('active'));
        
        this.classList.add('active');
        const target = document.getElementById(this.getAttribute('data-tab'));
        if (target) target.classList.add('active');
    });
});

// ══════════════════════════════════════════════════════════════════
// 2. LIVE INTERACTIVE CARD PREVIEW (SE PRESENTE NA TELA)
// ══════════════════════════════════════════════════════════════════
const cardPreview = document.getElementById('card-preview-element');
const pickPrimaria = document.getElementById('picker-cor-primaria');
const textPrimaria = document.getElementById('text-cor-primaria');
const pickSecundaria = document.getElementById('picker-cor-secundaria');
const textSecundaria = document.getElementById('text-cor-secundaria');
const inputCardTitle = document.getElementById('input-card-title');
const previewCardTitle = document.getElementById('preview-card-title');
const inputMetaSelos = document.getElementById('input-meta-selos');
const previewStampsTotal = document.getElementById('preview-stamps-total');
const previewSlotsGrid = document.getElementById('preview-slots-grid');

if (pickPrimaria && cardPreview) {
    function updateCardGradient() {
        const c1 = pickPrimaria.value;
        const c2 = pickSecundaria ? pickSecundaria.value : '#2c3e50';
        cardPreview.style.background = `linear-gradient(135deg, ${c2}, ${c1})`;
    }

    pickPrimaria.addEventListener('input', function() {
        if (textPrimaria) textPrimaria.value = this.value;
        updateCardGradient();
    });
    if (textPrimaria) {
        textPrimaria.addEventListener('input', function() {
            pickPrimaria.value = this.value;
            updateCardGradient();
        });
    }

    if (pickSecundaria) {
        pickSecundaria.addEventListener('input', function() {
            if (textSecundaria) textSecundaria.value = this.value;
            updateCardGradient();
        });
    }
    if (textSecundaria && pickSecundaria) {
        textSecundaria.addEventListener('input', function() {
            pickSecundaria.value = this.value;
            updateCardGradient();
        });
    }
}

if (inputCardTitle && previewCardTitle) {
    inputCardTitle.addEventListener('input', function() {
        previewCardTitle.innerText = this.value || 'Cartão Fidelidade Pet VIP';
    });
}

if (inputMetaSelos && previewSlotsGrid && previewStampsTotal) {
    function renderStampsPreview() {
        const total = parseInt(inputMetaSelos.value) || 10;
        previewStampsTotal.innerText = total;
        previewSlotsGrid.innerHTML = '';
        
        const preenchidos = Math.min(6, total);
        const stampsCountEl = document.getElementById('preview-stamps-count');
        if (stampsCountEl) stampsCountEl.innerText = preenchidos;

        for (let i = 1; i <= total; i++) {
            const slot = document.createElement('div');
            const ehUltimo = (i === total);
            const cheio = (i <= preenchidos);

            slot.className = `vip-slot ${cheio ? 'filled' : ''} ${ehUltimo ? 'reward-slot' : ''}`;
            
            if (cheio) {
                slot.innerHTML = ehUltimo ? '<i class="fas fa-gift"></i>' : '<i class="fas fa-paw"></i>';
            } else {
                slot.innerHTML = ehUltimo ? '<i class="fas fa-gift" style="opacity:0.4;"></i>' : `<span>${i}</span>`;
            }
            previewSlotsGrid.appendChild(slot);
        }
    }
    inputMetaSelos.addEventListener('input', renderStampsPreview);
    renderStampsPreview();
}

// Upload de logo no preview
const inputCardLogo = document.getElementById('input-card-logo');
if (inputCardLogo) {
    inputCardLogo.addEventListener('change', function(e) {
        if (this.files && this.files[0]) {
            const reader = new FileReader();
            reader.onload = function(ev) {
                const box = document.getElementById('preview-logo-box');
                if (box) box.innerHTML = `<img src="${ev.target.result}" style="width:100%;height:100%;object-fit:cover;">`;
            };
            reader.readAsDataURL(this.files[0]);
        }
    });
}

// Renderiza QR Code no preview
const previewQrEl = document.getElementById("preview-qr");
if (previewQrEl && typeof QRCode !== 'undefined') {
    new QRCode(previewQrEl, {
        text: "PETCRM:VIP:PREVIEW:DEMO",
        width: 50,
        height: 50,
        colorDark : "#0f172a",
        colorLight : "#ffffff",
        correctLevel : QRCode.CorrectLevel.M
    });
}

// ══════════════════════════════════════════════════════════════════
// 3. SALVAR CONFIGURAÇÃO DO PROGRAMA DE FIDELIDADE
// ══════════════════════════════════════════════════════════════════
const formFidConfig = document.getElementById('form-fidelidade-config');
if (formFidConfig) {
    formFidConfig.addEventListener('submit', function(e) {
        e.preventDefault();
        const btn = document.getElementById('btn-salvar-config');
        const orig = btn ? btn.innerHTML : 'Salvar';
        if (btn) {
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
            btn.disabled = true;
        }

        const formData = new FormData(this);

        fetch('api_fidelidade.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(res => {
            alert(res.message);
            if (btn) {
                btn.innerHTML = orig;
                btn.disabled = false;
            }
            if(res.success && res.logo_url) {
                const logoBox = document.getElementById('preview-logo-box');
                if (logoBox) logoBox.innerHTML = `<img src="${res.logo_url}" style="width:100%;height:100%;object-fit:cover;">`;
            }
        })
        .catch(err => {
            console.error(err);
            alert('Erro ao salvar configurações.');
            if (btn) {
                btn.innerHTML = orig;
                btn.disabled = false;
            }
        });
    });
}

// ══════════════════════════════════════════════════════════════════
// 4. CONTADOR REGRESSIVO EM TEMPO REAL NAS PROMOÇÕES (COUNTDOWN)
// ══════════════════════════════════════════════════════════════════
function atualizarTimers() {
    const timers = document.querySelectorAll('.countdown-timer');
    const agora = Math.floor(Date.now() / 1000);

    timers.forEach(t => {
        const fim = parseInt(t.getAttribute('data-timestamp'));
        const diff = fim - agora;

        if (diff <= 0) {
            t.innerText = "EXPIRADA";
            t.style.color = "#ef4444";
        } else {
            const dias = Math.floor(diff / 86400);
            const horas = Math.floor((diff % 86400) / 3600);
            const minutos = Math.floor((diff % 3600) / 60);
            const segundos = diff % 60;

            let texto = "";
            if (dias > 0) texto += `${dias}d `;
            texto += `${String(horas).padStart(2, '0')}h ${String(minutos).padStart(2, '0')}m ${String(segundos).padStart(2, '0')}s`;
            t.innerText = texto;
            t.style.color = "#f1c40f";
        }
    });
}
setInterval(atualizarTimers, 1000);
atualizarTimers();

// ══════════════════════════════════════════════════════════════════
// 5. MODAL DE PROMOÇÃO & CÁLCULO DE DESCONTO
// ══════════════════════════════════════════════════════════════════
const modalPromo = document.getElementById('promo-modal');
function abrirModalPromo() {
    if (modalPromo) modalPromo.style.display = 'flex';
}
function fecharModalPromo() {
    if (modalPromo) modalPromo.style.display = 'none';
}

const btnAbrirNovaPromo = document.getElementById('btn-abrir-nova-promo');
if (btnAbrirNovaPromo) btnAbrirNovaPromo.onclick = abrirModalPromo;

const btnCriarPromoTab = document.getElementById('btn-criar-promo-tab');
if (btnCriarPromoTab) btnCriarPromoTab.onclick = abrirModalPromo;

// Cálculo dinâmico do desconto ao escolher produto e %
const prodSelect = document.getElementById('promo-produto-select');
const descInput = document.getElementById('promo-desconto-input');
const precoCalc = document.getElementById('promo-preco-calculado');

function recalcularPrecoPromo() {
    if (!prodSelect || !descInput || !precoCalc) return;
    const opt = (prodSelect.selectedIndex >= 0) ? prodSelect.options[prodSelect.selectedIndex] : null;
    const preco = parseFloat(opt ? opt.getAttribute('data-preco') : 0) || 0;
    const desc = parseFloat(descInput.value) || 0;

    if (preco > 0) {
        const final = Math.max(0, preco * (1 - (desc / 100)));
        precoCalc.value = 'R$ ' + final.toFixed(2).replace('.', ',');
    } else {
        precoCalc.value = desc + '% de Desconto';
    }
}
if (prodSelect) prodSelect.addEventListener('change', recalcularPrecoPromo);
if (descInput) descInput.addEventListener('input', recalcularPrecoPromo);

// Submissão da promoção com foto
const formSalvarPromo = document.getElementById('form-salvar-promo');
if (formSalvarPromo) {
    formSalvarPromo.addEventListener('submit', function(e) {
        e.preventDefault();
        const btn = document.getElementById('btn-submit-promo');
        const orig = btn ? btn.innerHTML : 'Salvar';
        if (btn) {
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
            btn.disabled = true;
        }

        const formData = new FormData(this);

        fetch('api_fidelidade.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(res => {
            alert(res.message);
            if(res.success) {
                location.reload();
            } else if (btn) {
                btn.innerHTML = orig;
                btn.disabled = false;
            }
        })
        .catch(err => {
            alert('Erro ao salvar promoção.');
            if (btn) {
                btn.innerHTML = orig;
                btn.disabled = false;
            }
        });
    });
}

// Excluir promoção
document.querySelectorAll('.btn-delete-promo').forEach(btn => {
    btn.addEventListener('click', function() {
        if (!confirm('Deseja excluir esta promoção?')) return;
        const id = this.getAttribute('data-id');
        
        fetch('api_fidelidade.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `acao=excluir_promocao&id=${id}&instituicao_id=<?php echo $id_loja; ?>`
        })
        .then(r => r.json())
        .then(res => {
            alert(res.message);
            if(res.success) location.reload();
        });
    });
});

// ══════════════════════════════════════════════════════════════════
// 6. TERMINAL RÁPIDO DE BALCÃO (PONTUAR E RESGATAR)
// ══════════════════════════════════════════════════════════════════
const inputBuscaBalcao = document.getElementById('balcao-busca');
const divResultadosBalcao = document.getElementById('balcao-resultados');
const cardCliBalcao = document.getElementById('balcao-cliente-card');
let clienteSelecionadoBalcao = null;

if (inputBuscaBalcao && divResultadosBalcao) {
    inputBuscaBalcao.addEventListener('input', function() {
        const termo = this.value.trim();
        if (termo.length < 2) {
            divResultadosBalcao.style.display = 'none';
            return;
        }

        fetch(`api_fidelidade.php?acao=buscar_cliente_fidelidade&termo=${encodeURIComponent(termo)}&instituicao_id=<?php echo $id_loja; ?>`)
        .then(r => r.json())
        .then(res => {
            if (res.success && res.clientes.length > 0) {
                divResultadosBalcao.innerHTML = '';
                res.clientes.forEach(c => {
                    const item = document.createElement('div');
                    item.style.padding = '10px 14px';
                    item.style.borderBottom = '1px solid #334155';
                    item.style.cursor = 'pointer';
                    item.innerHTML = `<strong>${c.nome_completo}</strong> <small style="color:#94a3b8;">(${c.telefone || 'sem fone'}) - Selos: ${c.selos}</small>`;
                    item.onclick = () => selecionarClienteBalcao(c);
                    divResultadosBalcao.appendChild(item);
                });
                divResultadosBalcao.style.display = 'block';
            } else {
                divResultadosBalcao.innerHTML = '<div style="padding:10px;color:#94a3b8;">Nenhum cliente encontrado.</div>';
                divResultadosBalcao.style.display = 'block';
            }
        });
    });
}

function selecionarClienteBalcao(c) {
    clienteSelecionadoBalcao = c;
    if (divResultadosBalcao) divResultadosBalcao.style.display = 'none';
    if (inputBuscaBalcao) inputBuscaBalcao.value = c.nome_completo;

    const elNome = document.getElementById('balcao-cli-nome');
    if (elNome) elNome.innerText = c.nome_completo;
    const elCpf = document.getElementById('balcao-cli-cpf');
    if (elCpf) elCpf.innerText = 'CPF: ' + (c.cpf || 'Não informado');
    const elTier = document.getElementById('balcao-cli-tier');
    if (elTier) elTier.innerText = c.tier || 'Bronze';
    const elSelos = document.getElementById('balcao-cli-selos');
    if (elSelos) elSelos.innerText = c.selos || '0';

    const max = parseInt(<?php echo (int)($cfg['compras_para_recompensa'] ?? 10); ?>);
    const btnResgate = document.getElementById('btn-resgatar-premio');
    if (btnResgate) {
        if (parseInt(c.selos) >= max) {
            btnResgate.style.display = 'block';
        } else {
            btnResgate.style.display = 'none';
        }
    }

    if (cardCliBalcao) cardCliBalcao.style.display = 'block';
}

// Botão Adicionar Selo no Balcão
const btnAddSelo = document.getElementById('btn-add-selo');
if (btnAddSelo) {
    btnAddSelo.addEventListener('click', function() {
        if (!clienteSelecionadoBalcao) return;
        const valInput = document.getElementById('balcao-valor-compra');
        const valor = valInput ? valInput.value : 0;

        this.innerText = 'Processando...';
        this.disabled = true;

        fetch('api_fidelidade.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `acao=adicionar_selo&cliente_id=${clienteSelecionadoBalcao.id}&valor_compra=${valor}&instituicao_id=<?php echo $id_loja; ?>`
        })
        .then(r => r.json())
        .then(res => {
            alert(res.message);
            this.innerHTML = '<i class="fas fa-stamp"></i> Adicionar Selo';
            this.disabled = false;
            if (res.success) {
                clienteSelecionadoBalcao.selos = res.selos_atuais;
                const elSelos = document.getElementById('balcao-cli-selos');
                if (elSelos) elSelos.innerText = res.selos_atuais;
                const btnResgate = document.getElementById('btn-resgatar-premio');
                if (btnResgate && res.recompensa_disponivel) {
                    btnResgate.style.display = 'block';
                }
            }
        });
    });
}

// Botão Resgatar Prêmio no Balcão
const btnResgatarPremio = document.getElementById('btn-resgatar-premio');
if (btnResgatarPremio) {
    btnResgatarPremio.addEventListener('click', function() {
        if (!clienteSelecionadoBalcao) return;
        if (!confirm('Confirmar resgate do desconto de 10 compras para este cliente?')) return;

        this.innerText = 'Resgatando...';
        this.disabled = true;

        fetch('api_fidelidade.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `acao=resgatar_recompensa&cliente_id=${clienteSelecionadoBalcao.id}&instituicao_id=<?php echo $id_loja; ?>`
        })
        .then(r => r.json())
        .then(res => {
            alert(res.message);
            this.innerHTML = '<i class="fas fa-gift"></i> Resgatar 15%';
            this.disabled = false;
            if (res.success) {
                clienteSelecionadoBalcao.selos = res.selos_restantes;
                const elSelos = document.getElementById('balcao-cli-selos');
                if (elSelos) elSelos.innerText = res.selos_restantes;
                this.style.display = 'none';
            }
        });
    });
}

// ══════════════════════════════════════════════════════════════════
// 7. GESTOR MODULAR DE REGRAS DE FIDELIDADE & PRESETS
// ══════════════════════════════════════════════════════════════════
const modalConstrutor = document.getElementById("modal-construtor-regra");
const gridRegrasHub = document.getElementById("grid-regras-hub");
let regrasModularesCarregadas = [];

function abrirModalConstrutor(dados = null) {
    document.getElementById("form-salvar-regra-modular").reset();
    document.getElementById("regra-id-input").value = "0";
    document.getElementById("modal-regra-titulo").innerText = "Nova Regra de Fidelidade & Desconto";

    if (dados) {
        document.getElementById("modal-regra-titulo").innerText = "Editar Regra de Fidelidade";
        document.getElementById("regra-id-input").value = dados.id || "0";
        document.getElementById("regra-nome-input").value = dados.nome_regra || "";
        document.getElementById("regra-mecanica-select").value = dados.tipo_mecanica || "cartao_selos";
        document.getElementById("regra-alvo-select").value = dados.categoria_alvo || "todos";
        document.getElementById("regra-condicao-tipo").value = dados.condicao_tipo || "quantidade_compras";
        document.getElementById("regra-condicao-valor").value = dados.condicao_valor || "10";
        document.getElementById("regra-recompensa-tipo").value = dados.recompensa_tipo || "desconto_percentual";
        document.getElementById("regra-recompensa-valor").value = dados.recompensa_valor || "15";
        document.getElementById("regra-limite-desconto").value = dados.limite_desconto_recompensa || "50";
        document.getElementById("regra-texto-cartao").value = dados.texto_cartao || dados.nome_regra || "";
        document.getElementById("regra-cor-primaria").value = dados.cor_hex || "#0d9488";
        document.getElementById("regra-cor-secundaria").value = dados.cor_secundaria || "#115e59";
        document.getElementById("regra-icone-select").value = dados.icone || "fas fa-paw";
        if (dados.item_alvo_id) document.getElementById("regra-item-alvo-select").value = dados.item_alvo_id;
        if (dados.recompensa_item_id) document.getElementById("regra-recompensa-item-select").value = dados.recompensa_item_id;
    }

    atualizarCamposDinamicosModal();
    atualizarLivePreviewModal();
    modalConstrutor.style.display = "flex";
}

function fecharModalConstrutor() {
    if (modalConstrutor) modalConstrutor.style.display = "none";
}

const btnNovaRegraHub = document.getElementById("btn-nova-regra-hub");
if (btnNovaRegraHub) btnNovaRegraHub.onclick = () => abrirModalConstrutor();

// Condicionais de exibição nos selects do construtor
function atualizarCamposDinamicosModal() {
    const elAlvo = document.getElementById("regra-alvo-select");
    const boxItem = document.getElementById("box-item-especifico");
    if (elAlvo && boxItem) boxItem.style.display = (elAlvo.value === "item_especifico") ? "block" : "none";

    const elCondTipo = document.getElementById("regra-condicao-tipo");
    const boxDias = document.getElementById("box-dias-semana");
    if (elCondTipo && boxDias) boxDias.style.display = (elCondTipo.value === "dia_semana") ? "block" : "none";

    const elRecTipo = document.getElementById("regra-recompensa-tipo");
    const boxRec = document.getElementById("box-recompensa-item");
    if (elRecTipo && boxRec) boxRec.style.display = (elRecTipo.value === "item_gratis") ? "block" : "none";
}

const selAlvo = document.getElementById("regra-alvo-select");
if (selAlvo) selAlvo.onchange = atualizarCamposDinamicosModal;

const selCondTipo = document.getElementById("regra-condicao-tipo");
if (selCondTipo) selCondTipo.onchange = atualizarCamposDinamicosModal;

const selRecTipo = document.getElementById("regra-recompensa-tipo");
if (selRecTipo) selRecTipo.onchange = atualizarCamposDinamicosModal;

// Live Preview no Construtor
function atualizarLivePreviewModal() {
    const c1 = document.getElementById("regra-cor-primaria").value;
    const c2 = document.getElementById("regra-cor-secundaria").value;
    const icone = document.getElementById("regra-icone-select").value;
    const titulo = document.getElementById("regra-texto-cartao").value || document.getElementById("regra-nome-input").value || "Cartão Fidelidade Pet VIP";
    const meta = parseInt(document.getElementById("regra-condicao-valor").value) || 10;
    const recTipo = document.getElementById("regra-recompensa-tipo").value;
    const recVal = document.getElementById("regra-recompensa-valor").value;

    const card = document.getElementById("modal-card-live-preview");
    card.style.background = `linear-gradient(135deg, ${c2}, ${c1})`;

    document.getElementById("modal-preview-title").innerText = titulo;
    document.getElementById("modal-preview-icon").innerHTML = `<i class="${icone}"></i>`;
    document.getElementById("modal-preview-progress-text").innerText = `3 de ${meta} selos`;

    let benefTxt = "";
    if (recTipo === "desconto_percentual") benefTxt = `${recVal}% de Desconto`;
    else if (recTipo === "desconto_fixo") benefTxt = `R$ ${recVal} de Desconto`;
    else if (recTipo === "item_gratis") benefTxt = `Item / Banho Grátis`;
    else if (recTipo === "cashback") benefTxt = `${recVal}% de Cashback`;
    document.getElementById("modal-preview-benefit").innerText = benefTxt;

    // Slots
    const slotsEl = document.getElementById("modal-preview-slots");
    slotsEl.innerHTML = "";
    const totalSlots = Math.min(10, Math.max(2, meta));
    for (let i = 1; i <= totalSlots; i++) {
        const d = document.createElement("div");
        const cheio = (i <= 3);
        const ehUltimo = (i === totalSlots);
        d.style.cssText = `aspect-ratio: 1/1; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; font-weight: bold; border: 1.5px ${cheio ? "solid #f1c40f" : "dashed rgba(255,255,255,0.4)"}; background: ${cheio ? "#ffffff" : "rgba(255,255,255,0.15)"}; color: ${cheio ? "#d97706" : "rgba(255,255,255,0.8)"};`;
        d.innerHTML = cheio ? (ehUltimo ? `<i class="fas fa-gift"></i>` : `<i class="${icone}"></i>`) : (ehUltimo ? `<i class="fas fa-gift" style="opacity:0.4;"></i>` : `<span>${i}</span>`);
        slotsEl.appendChild(d);
    }
}

["regra-cor-primaria", "regra-cor-secundaria", "regra-icone-select", "regra-texto-cartao", "regra-nome-input", "regra-condicao-valor", "regra-recompensa-valor", "regra-recompensa-tipo"].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
        el.addEventListener("input", atualizarLivePreviewModal);
        el.addEventListener("change", atualizarLivePreviewModal);
    }
});

// Presets Rápidos
document.querySelectorAll(".btn-preset-regra").forEach(btn => {
    btn.onclick = function() {
        const p = this.getAttribute("data-preset");
        let dados = {
            id: 0,
            tipo_mecanica: "cartao_selos",
            categoria_alvo: "todos",
            condicao_tipo: "quantidade_compras",
            condicao_valor: 10,
            recompensa_tipo: "desconto_percentual",
            recompensa_valor: 15,
            limite_desconto_recompensa: 50,
            cor_hex: "#0d9488",
            cor_secundaria: "#115e59",
            icone: "fas fa-paw",
            texto_cartao: "Cartão Fidelidade Pet VIP",
            nome_regra: "Cartão Tradicional 10 Selos"
        };

        if (p === "clube_banho") {
            dados.nome_regra = "Clube do Banho VIP (Compre 5 Ganhe 1)";
            dados.tipo_mecanica = "compre_ganhe";
            dados.categoria_alvo = "servicos";
            dados.condicao_tipo = "quantidade_compras";
            dados.condicao_valor = 5;
            dados.recompensa_tipo = "item_gratis";
            dados.cor_hex = "#2563eb";
            dados.cor_secundaria = "#1e3a8a";
            dados.icone = "fas fa-bath";
            dados.texto_cartao = "Clube do Banho VIP";
        } else if (p === "terca_dobro") {
            dados.nome_regra = "Terça Turbo (2x Selos)";
            dados.tipo_mecanica = "cartao_selos";
            dados.categoria_alvo = "todos";
            dados.condicao_tipo = "dia_semana";
            dados.condicao_valor = 1;
            dados.recompensa_tipo = "desconto_percentual";
            dados.recompensa_valor = 20;
            dados.cor_hex = "#f59e0b";
            dados.cor_secundaria = "#b45309";
            dados.icone = "fas fa-calendar-day";
            dados.texto_cartao = "Terça Turbo Pet";
        } else if (p === "cashback_5") {
            dados.nome_regra = "Cashback VIP 5% em Todas as Compras";
            dados.tipo_mecanica = "cashback";
            dados.categoria_alvo = "todos";
            dados.condicao_tipo = "sem_condicao";
            dados.condicao_valor = 0;
            dados.recompensa_tipo = "cashback";
            dados.recompensa_valor = 5;
            dados.cor_hex = "#10b981";
            dados.cor_secundaria = "#047857";
            dados.icone = "fas fa-coins";
            dados.texto_cartao = "Cartão Cashback VIP";
        } else if (p === "aniversario") {
            dados.nome_regra = "Especial Mês Aniversariante (20% OFF)";
            dados.tipo_mecanica = "desconto_direto";
            dados.categoria_alvo = "todos";
            dados.condicao_tipo = "aniversario";
            dados.condicao_valor = 0;
            dados.recompensa_tipo = "desconto_percentual";
            dados.recompensa_valor = 20;
            dados.cor_hex = "#ec4899";
            dados.cor_secundaria = "#9d174d";
            dados.icone = "fas fa-birthday-cake";
            dados.texto_cartao = "Aniversário VIP Pet";
        }

        abrirModalConstrutor(dados);
    };
});

// Carregar Regras do Backend
function carregarRegrasModulares() {
    fetch("api_fidelidade.php?acao=listar_regras&instituicao_id=<?php echo $id_loja; ?>")
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            gridRegrasHub.innerHTML = `<div style="grid-column:1/-1;color:red;text-align:center;">Erro: ${res.message}</div>`;
            return;
        }

        regrasModularesCarregadas = res.regras || [];

        if (regrasModularesCarregadas.length === 0) {
            gridRegrasHub.innerHTML = `
                <div style="grid-column: 1 / -1; text-align: center; padding: 50px 20px; background: #ffffff; border-radius: 16px; border: 2px dashed #cbd5e1;">
                    <i class="fas fa-layer-group" style="font-size: 3rem; color: #94a3b8; margin-bottom: 15px;"></i>
                    <h3 style="color: #334155; margin-bottom: 6px;">Nenhuma regra personalizada ativa no momento</h3>
                    <p style="color: #64748b; max-width: 460px; margin: 0 auto 20px; font-size: 0.9rem;">
                        Clique em <strong>Criar Nova Regra</strong> ou escolha um dos <strong>Modelos Prontos</strong> acima para ativar seu primeiro cartão de fidelidade modular!
                    </p>
                    <button type="button" class="btn btn-primary" onclick="abrirModalConstrutor()" style="background:#0d9488;border-color:#0d9488;padding:10px 20px;border-radius:8px;font-weight:700;">
                        <i class="fas fa-plus"></i> Criar Primeiro Cartão
                    </button>
                </div>
            `;
            return;
        }

        let html = "";
        regrasModularesCarregadas.forEach(r => {
            const ativa = (parseInt(r.ativo) === 1);
            let mecDesc = "Cartão de Selos";
            if (r.tipo_mecanica === "pontos") mecDesc = "Pontuação por R$";
            else if (r.tipo_mecanica === "cashback") mecDesc = "Cashback";
            else if (r.tipo_mecanica === "compre_ganhe") mecDesc = "Compre & Ganhe";
            else if (r.tipo_mecanica === "desconto_direto") mecDesc = "Desconto Direto";

            let benefDesc = "";
            if (r.recompensa_tipo === "desconto_percentual") benefDesc = `${parseFloat(r.recompensa_valor)}% OFF`;
            else if (r.recompensa_tipo === "desconto_fixo") benefDesc = `R$ ${parseFloat(r.recompensa_valor).toFixed(2)} OFF`;
            else if (r.recompensa_tipo === "item_gratis") benefDesc = `Brinde / Serviço Grátis`;
            else if (r.recompensa_tipo === "cashback") benefDesc = `${parseFloat(r.recompensa_valor)}% Cashback`;

            let condDesc = `A cada ${parseInt(r.condicao_valor)} compras`;
            if (r.condicao_tipo === "valor_minimo") condDesc = `Compras acima de R$ ${parseFloat(r.condicao_valor).toFixed(2)}`;
            else if (r.condicao_tipo === "dia_semana") condDesc = "Dias especiais da semana";
            else if (r.condicao_tipo === "aniversario") condDesc = "Mês de aniversário";
            else if (r.condicao_tipo === "sem_condicao") condDesc = "Sem valor mínimo";

            html += `
                <div class="widget-card" style="background: #ffffff; border-radius: 16px; border: 1px solid ${ativa ? "#cbd5e1" : "#e2e8f0"}; opacity: ${ativa ? "1" : "0.75"}; box-shadow: 0 4px 16px rgba(0,0,0,0.04); display: flex; flex-direction: column;">
                    
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                        <span style="background: #f1f5f9; color: #475569; font-weight: 700; font-size: 0.75rem; padding: 4px 10px; border-radius: 20px;">
                            ${mecDesc}
                        </span>
                        
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 0.8rem; font-weight: 600; color: ${ativa ? "#059669" : "#94a3b8"};">
                            <input type="checkbox" ${ativa ? "checked" : ""} onchange="alternarStatusRegra(${r.id})" style="width: 18px; height: 18px; cursor: pointer;">
                            ${ativa ? "Ativo" : "Pausado"}
                        </label>
                    </div>

                    <!-- Mini Cartão Estilizado -->
                    <div style="background: linear-gradient(135deg, ${r.cor_secundaria || "#115e59"}, ${r.cor_hex || "#0d9488"}); border-radius: 14px; padding: 14px; color: white; margin-bottom: 14px; box-shadow: 0 6px 14px rgba(0,0,0,0.15);">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                            <i class="${r.icone || "fas fa-paw"}" style="font-size: 1.1rem;"></i>
                            <strong style="font-size: 0.95rem;">${r.texto_cartao || r.nome_regra}</strong>
                        </div>
                        <div style="font-size: 0.82rem; opacity: 0.95; display: flex; justify-content: space-between;">
                            <span>${condDesc}</span>
                            <span style="font-weight: 800; color: #fef08a;">${benefDesc}</span>
                        </div>
                    </div>

                    <!-- Dados e KPIs -->
                    <div style="font-size: 0.85rem; color: #64748b; line-height: 1.5; margin-bottom: 14px; flex: 1;">
                        <div><strong>Alvo:</strong> ${r.categoria_alvo === "todos" ? "Todos os itens" : (r.categoria_alvo === "servicos" ? "Serviços" : (r.categoria_alvo === "produtos" ? "Produtos" : (r.item_alvo_nome || "Item específico")))}</div>
                        <div style="display: flex; justify-content: space-between; margin-top: 6px; padding-top: 6px; border-top: 1px dashed #e2e8f0; font-size: 0.8rem;">
                            <span>👥 <strong>${r.total_participantes || 0}</strong> clientes</span>
                            <span>🏆 <strong>${r.total_ciclos || 0}</strong> ciclos concluídos</span>
                        </div>
                    </div>

                    <!-- Ações -->
                    <div style="display: flex; gap: 8px; border-top: 1px solid #f1f5f9; padding-top: 12px;">
                        <button type="button" class="btn btn-sm btn-secondary" onclick="editarRegraPorId(${r.id})" style="flex: 1; border-radius: 6px; font-weight: 600; font-size: 0.82rem;">
                            <i class="fas fa-edit"></i> Editar
                        </button>
                        <a href="cartao_cliente.php?loja_id=<?php echo $id_loja; ?>" target="_blank" class="btn btn-sm" style="background:#e0f2fe;color:#0369a1;border-radius:6px;font-weight:600;font-size:0.82rem;text-decoration:none;display:inline-flex;align-items:center;padding:6px 10px;">
                            <i class="fas fa-mobile-alt"></i> Cartão
                        </a>
                        <button type="button" class="btn btn-sm" onclick="excluirRegra(${r.id})" style="background:#fee2e2;color:#dc2626;border:none;border-radius:6px;padding:6px 10px;cursor:pointer;">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>

                </div>
            `;
        });

        gridRegrasHub.innerHTML = html;
    });
}

function alternarStatusRegra(id) {
    fetch("api_fidelidade.php", {
        method: "POST",
        headers: {"Content-Type": "application/x-www-form-urlencoded"},
        body: `acao=toggle_regra&id=${id}&instituicao_id=<?php echo $id_loja; ?>`
    })
    .then(r => r.json())
    .then(res => {
        carregarRegrasModulares();
    });
}

function editarRegraPorId(id) {
    const r = regrasModularesCarregadas.find(x => x.id == id);
    if (r) abrirModalConstrutor(r);
}

function excluirRegra(id) {
    if (!confirm("Tem certeza que deseja excluir esta regra de fidelidade? O progresso dos clientes vinculados a este cartão será resetado.")) return;
    
    fetch("api_fidelidade.php", {
        method: "POST",
        headers: {"Content-Type": "application/x-www-form-urlencoded"},
        body: `acao=excluir_regra&id=${id}&instituicao_id=<?php echo $id_loja; ?>`
    })
    .then(r => r.json())
    .then(res => {
        alert(res.message);
        carregarRegrasModulares();
    });
}

// Salvar Regra via AJAX
const formSalvarRegraMod = document.getElementById("form-salvar-regra-modular");
if (formSalvarRegraMod) {
    formSalvarRegraMod.addEventListener("submit", function(e) {
        e.preventDefault();
        const btn = document.getElementById("btn-submit-regra");
        const orig = btn ? btn.innerHTML : 'Salvar';
        if (btn) {
            btn.innerHTML = `<i class="fas fa-spinner fa-spin"></i> Gravando...`;
            btn.disabled = true;
        }

        const formData = new FormData(this);
        formData.append("acao", "salvar_regra");

        fetch("api_fidelidade.php", {
            method: "POST",
            body: formData
        })
        .then(r => r.json())
        .then(res => {
            alert(res.message);
            if (btn) {
                btn.innerHTML = orig;
                btn.disabled = false;
            }
            if (res.success) {
                fecharModalConstrutor();
                carregarRegrasModulares();
            }
        })
        .catch(err => {
            alert("Erro ao gravar regra modular.");
            if (btn) {
                btn.innerHTML = orig;
                btn.disabled = false;
            }
        });
    });
}

// Inicialização automática das regras modulares
carregarRegrasModulares();

</script>

<?php include 'includes/_footer.php'; ?>
