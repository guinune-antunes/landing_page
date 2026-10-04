<?php
require 'conexao.php';

$cliente_id = intval($_GET['cliente_id'] ?? 0);
$loja_id = intval($_GET['loja_id'] ?? 0);

// Se não passou loja, tenta achar pela associação do cliente ou pega a primeira
if ($loja_id <= 0 && $cliente_id > 0) {
    $stL = $pdo->prepare("SELECT instituicao_id FROM clientes WHERE id = ?");
    $stL->execute([$cliente_id]);
    $loja_id = (int)$stL->fetchColumn();
}
if ($loja_id <= 0) {
    $loja_id = (int)$pdo->query("SELECT id FROM instituicoes ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1;
}

// Busca a loja
$stLoja = $pdo->prepare("SELECT * FROM instituicoes WHERE id = ?");
$stLoja->execute([$loja_id]);
$loja = $stLoja->fetch(PDO::FETCH_ASSOC) ?: ['nome_fantasia' => 'Pet Shop', 'cnpj' => ''];

// Busca configuração da fidelidade
$stCfg = $pdo->prepare("SELECT * FROM fidelidade_config WHERE instituicao_id = ?");
$stCfg->execute([$loja_id]);
$cfg = $stCfg->fetch(PDO::FETCH_ASSOC);

if (!$cfg) {
    $cfg = [
        'tipo_programa' => 'selos',
        'compras_para_recompensa' => 10,
        'recompensa_tipo' => 'desconto_percentual',
        'recompensa_valor' => 15,
        'cor_cartao_hex' => '#1abc9c',
        'cor_cartao_secundaria' => '#2c3e50',
        'estilo_cartao' => 'modern_gradient',
        'texto_cartao' => 'Cartão Fidelidade Pet VIP',
        'logo_cartao' => null
    ];
}

// Busca dados do cliente ou cria fictício para preview
$cliente = null;
$fid = ['selos_atuais' => 4, 'ciclos_completados' => 1, 'pontos_acumulados' => 120, 'tier_atual' => 'Prata'];

if ($cliente_id > 0) {
    $stCli = $pdo->prepare("SELECT * FROM clientes WHERE id = ? AND instituicao_id = ?");
    $stCli->execute([$cliente_id, $loja_id]);
    $cliente = $stCli->fetch(PDO::FETCH_ASSOC);

    if ($cliente) {
        $stFid = $pdo->prepare("SELECT * FROM cliente_fidelidade WHERE cliente_id = ? AND instituicao_id = ?");
        $stFid->execute([$cliente_id, $loja_id]);
        $fidReal = $stFid->fetch(PDO::FETCH_ASSOC);
        if ($fidReal) $fid = $fidReal;
    }
}

if (!$cliente) {
    $cliente = [
        'id' => 1,
        'nome_completo' => 'Mariana Lima (Exemplo)',
        'telefone' => '(41) 98765-4321',
        'cpf' => '123.456.789-00'
    ];
}

$totalSelos = (int)($cfg['compras_para_recompensa'] ?? 10);
$selosAtuais = (int)($fid['selos_atuais'] ?? 0);
$percentualProgresso = min(100, round(($selosAtuais / $totalSelos) * 100));

// Busca promoções ativas da loja para mostrar no cartão virtual
$stPromos = $pdo->prepare("SELECT * FROM promocoes WHERE instituicao_id = ? AND ativo = 1 AND data_fim >= NOW() ORDER BY id DESC LIMIT 4");
$stPromos->execute([$loja_id]);
$promos = $stPromos->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($cfg['texto_cartao']); ?> - <?php echo htmlspecialchars($loja['nome_fantasia']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        :root {
            --primary: <?php echo htmlspecialchars($cfg['cor_cartao_hex'] ?? '#1abc9c'); ?>;
            --secondary: <?php echo htmlspecialchars($cfg['cor_cartao_secundaria'] ?? '#2c3e50'); ?>;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Poppins', sans-serif; }
        body {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 25px 15px;
            color: #333;
        }
        .pass-wrapper {
            width: 100%;
            max-width: 440px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        /* ── CARTÃO FIDELIDADE VIRTUAL ── */
        .vip-card {
            background: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%);
            border-radius: 24px;
            padding: 26px 24px;
            color: #ffffff;
            box-shadow: 0 20px 40px rgba(0,0,0,0.35), 0 0 0 1px rgba(255,255,255,0.15) inset;
            position: relative;
            overflow: hidden;
            transition: transform 0.3s ease;
        }
        .vip-card:hover {
            transform: translateY(-4px);
        }
        .vip-card::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -30%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .card-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .store-badge {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .store-logo-circle {
            width: 44px;
            height: 44px;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(8px);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            color: #fff;
            overflow: hidden;
        }
        .store-logo-circle img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .store-info h3 {
            font-size: 1rem;
            font-weight: 700;
            line-height: 1.2;
        }
        .store-info small {
            font-size: 0.75rem;
            opacity: 0.85;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .tier-tag {
            background: rgba(255,255,255,0.25);
            backdrop-filter: blur(10px);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
            border: 1px solid rgba(255,255,255,0.3);
        }

        /* ── GRID DE SELOS DE COMPRA ── */
        .stamps-section {
            background: rgba(0,0,0,0.18);
            backdrop-filter: blur(12px);
            border-radius: 18px;
            padding: 18px 14px;
            margin-bottom: 20px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .stamps-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.82rem;
            margin-bottom: 12px;
            font-weight: 600;
        }
        .stamps-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 10px;
            justify-items: center;
        }
        .stamp-slot {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: rgba(255,255,255,0.15);
            border: 2px dashed rgba(255,255,255,0.4);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 700;
            color: rgba(255,255,255,0.7);
            position: relative;
            transition: all 0.3s;
        }
        .stamp-slot.stamped {
            background: #ffffff;
            border: 2px solid #f1c40f;
            color: #d35400;
            box-shadow: 0 4px 12px rgba(241, 196, 15, 0.4);
            animation: popIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .stamp-slot.stamped i {
            font-size: 1.2rem;
            color: #e67e22;
        }
        .stamp-slot.reward-slot {
            border-color: #f1c40f;
            background: rgba(241, 196, 15, 0.25);
            color: #f1c40f;
        }
        .stamp-slot.reward-slot.stamped {
            background: linear-gradient(135deg, #f39c12, #f1c40f);
            color: #fff;
            border-color: #fff;
        }
        .stamp-slot.reward-slot.stamped i {
            color: #fff;
        }
        @keyframes popIn {
            0% { transform: scale(0.6); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }

        .reward-alert-box {
            background: #2ecc71;
            color: white;
            padding: 10px 14px;
            border-radius: 12px;
            margin-top: 12px;
            text-align: center;
            font-size: 0.85rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            animation: pulse 1.8s infinite;
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.02); }
        }

        .card-bottom {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 15px;
        }
        .client-meta small {
            font-size: 0.7rem;
            opacity: 0.8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: block;
        }
        .client-meta strong {
            font-size: 1rem;
            font-weight: 600;
        }
        .card-qr-box {
            background: white;
            padding: 6px;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
        #qrcode img, #qrcode canvas {
            display: block;
            width: 65px;
            height: 65px;
        }

        /* ── SEÇÃO DE AUTENTICAÇÃO NO BALCÃO ── */
        .auth-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 22px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            text-align: center;
        }
        .auth-card h4 {
            font-size: 1.05rem;
            margin-bottom: 8px;
            color: #1e293b;
        }
        .auth-card p {
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 16px;
        }
        .barcode-strip {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }
        .barcode-lines {
            font-family: 'Courier New', monospace;
            letter-spacing: 5px;
            font-size: 1.8rem;
            font-weight: 900;
            color: #0f172a;
            user-select: none;
            background: repeating-linear-gradient(90deg, #111 0, #111 2px, transparent 2px, transparent 4px, #111 4px, #111 7px, transparent 7px, transparent 9px);
            height: 48px;
            width: 80%;
            border-radius: 4px;
        }
        .barcode-number {
            font-size: 0.85rem;
            font-weight: 700;
            color: #475569;
            letter-spacing: 2px;
        }

        /* ── PROMOÇÕES DA LOJA ── */
        .promos-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 22px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        .promos-card h4 {
            font-size: 1.05rem;
            margin-bottom: 15px;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .promo-item {
            display: flex;
            gap: 14px;
            padding: 12px 0;
            border-bottom: 1px solid #f1f5f9;
            align-items: center;
        }
        .promo-item:last-child { border-bottom: none; }
        .promo-thumb {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            background: #f1f5f9;
            object-fit: cover;
            flex-shrink: 0;
        }
        .promo-details h5 {
            font-size: 0.9rem;
            font-weight: 600;
            color: #1e293b;
            line-height: 1.3;
        }
        .promo-pricing {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 4px;
        }
        .old-price {
            text-decoration: line-through;
            font-size: 0.8rem;
            color: #94a3b8;
        }
        .new-price {
            font-size: 0.95rem;
            font-weight: 700;
            color: #e11d48;
        }
        .discount-tag {
            background: #ffe4e6;
            color: #e11d48;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 6px;
        }

        .back-nav {
            text-align: center;
            margin-top: 10px;
        }
        .back-nav a {
            color: #94a3b8;
            font-size: 0.85rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .back-nav a:hover { color: #f8fafc; }
    </style>
</head>
<body>

<div class="pass-wrapper">

    <!-- 1. CARTÃO VIP VIRTUAL -->
    <div class="vip-card">
        <div class="card-top">
            <div class="store-badge">
                <div class="store-logo-circle">
                    <?php if (!empty($cfg['logo_cartao'])): ?>
                        <img src="<?php echo htmlspecialchars($cfg['logo_cartao']); ?>" alt="Logo">
                    <?php else: ?>
                        <i class="fas fa-paw"></i>
                    <?php endif; ?>
                </div>
                <div class="store-info">
                    <h3><?php echo htmlspecialchars($loja['nome_fantasia']); ?></h3>
                    <small><?php echo htmlspecialchars($cfg['texto_cartao']); ?></small>
                </div>
            </div>
            <div class="tier-tag">
                <i class="fas fa-crown" style="color: #f1c40f;"></i>
                <span><?php echo htmlspecialchars($fid['tier_atual'] ?? 'Bronze'); ?></span>
            </div>
        </div>

        <!-- STAMPS CONTAINER -->
        <div class="stamps-section">
            <div class="stamps-header">
                <span><i class="fas fa-stamp"></i> Progresso de Compras</span>
                <span><strong><?php echo $selosAtuais; ?></strong> / <?php echo $totalSelos; ?> Selos</span>
            </div>

            <div class="stamps-grid">
                <?php for ($s = 1; $s <= $totalSelos; $s++): 
                    $preenchido = ($s <= $selosAtuais);
                    $ehUltimo = ($s === $totalSelos);
                ?>
                    <div class="stamp-slot <?php echo $preenchido ? 'stamped' : ''; ?> <?php echo $ehUltimo ? 'reward-slot' : ''; ?>">
                        <?php if ($preenchido): ?>
                            <?php if ($ehUltimo): ?>
                                <i class="fas fa-gift"></i>
                            <?php else: ?>
                                <i class="fas fa-paw"></i>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php if ($ehUltimo): ?>
                                <i class="fas fa-gift" style="opacity: 0.4;"></i>
                            <?php else: ?>
                                <span><?php echo $s; ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>

            <?php if ($selosAtuais >= $totalSelos): ?>
                <div class="reward-alert-box">
                    <i class="fas fa-check-circle"></i>
                    <span>RECOMPENSA DISPONÍVEL! Resgate no próximo atendimento.</span>
                </div>
            <?php else: ?>
                <div style="font-size: 0.78rem; opacity: 0.9; text-align: center; margin-top: 10px;">
                    Faltam <strong><?php echo ($totalSelos - $selosAtuais); ?> compras</strong> para desbloquear seu desconto de <?php echo $cfg['recompensa_valor']; ?>%!
                </div>
            <?php endif; ?>
        </div>

        <div class="card-bottom">
            <div class="client-meta">
                <small>Titular do Cartão</small>
                <strong><?php echo htmlspecialchars($cliente['nome_completo']); ?></strong>
                <div style="font-size: 0.75rem; opacity: 0.85; margin-top: 3px;">
                    <?php if (!empty($fid['pontos_acumulados'])): ?>
                        <span>⭐ <?php echo $fid['pontos_acumulados']; ?> Pontos</span> &bull; 
                    <?php endif; ?>
                    <span>ID: #<?php echo str_pad($cliente['id'], 5, '0', STR_PAD_LEFT); ?></span>
                </div>
            </div>

            <div class="card-qr-box">
                <div id="qrcode"></div>
            </div>
        </div>
    </div>

    <!-- 2. IDENTIFICAÇÃO RÁPIDA NO BALCÃO -->
    <div class="auth-card">
        <h4>Identificação no Balcão</h4>
        <p>Apresente o QR Code acima ou o código de barras abaixo no caixa para pontuar suas compras.</p>
        <div class="barcode-strip">
            <div class="barcode-lines"></div>
            <span class="barcode-number">PET-<?php echo str_pad($loja_id, 3, '0', STR_PAD_LEFT); ?>-<?php echo str_pad($cliente['id'], 6, '0', STR_PAD_LEFT); ?></span>
        </div>
    </div>

    <!-- 3. OFERTAS & PROMOÇÕES VIGENTES DA LOJA -->
    <?php if (!empty($promos)): ?>
        <div class="promos-card">
            <h4><i class="fas fa-fire" style="color: #e11d48;"></i> Ofertas Especiais da Semana</h4>
            <?php foreach ($promos as $p): ?>
                <div class="promo-item">
                    <?php if (!empty($p['imagem_url'])): ?>
                        <img src="<?php echo htmlspecialchars($p['imagem_url']); ?>" alt="Foto" class="promo-thumb">
                    <?php else: ?>
                        <div class="promo-thumb" style="display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:1.5rem;">
                            <i class="fas fa-tag"></i>
                        </div>
                    <?php endif; ?>
                    <div class="promo-details" style="flex: 1;">
                        <h5><?php echo htmlspecialchars($p['titulo']); ?></h5>
                        <div class="promo-pricing">
                            <?php if ($p['preco_promocional'] > 0): ?>
                                <span class="old-price">R$ <?php echo number_format($p['preco_original'], 2, ',', '.'); ?></span>
                                <span class="new-price">R$ <?php echo number_format($p['preco_promocional'], 2, ',', '.'); ?></span>
                            <?php endif; ?>
                            <span class="discount-tag">-<?php echo (int)$p['desconto_percentual']; ?>% OFF</span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="back-nav">
        <a href="fidelizacao.php"><i class="fas fa-arrow-left"></i> Voltar ao Painel do CRM</a>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Gera o QR Code vinculado ao ID do cliente
    const qrText = "PETCRM:CLI:<?php echo $cliente['id']; ?>:LOJA:<?php echo $loja_id; ?>";
    new QRCode(document.getElementById("qrcode"), {
        text: qrText,
        width: 65,
        height: 65,
        colorDark : "#0f172a",
        colorLight : "#ffffff",
        correctLevel : QRCode.CorrectLevel.M
    });
});
</script>

</body>
</html>
