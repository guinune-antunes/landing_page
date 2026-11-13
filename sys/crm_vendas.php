<?php
require 'includes/verifica_login.php';
require 'conexao.php';
require 'includes/motor_nutricional.php'; // Motor de cálculo de Necessidade Energética (NE)

$cargo = $_SESSION['usuario_cargo'] ?? '';
$id_loja = (int)($_SESSION['instituicao_id'] ?? 0);

$pageTitle = 'CRM - Previsão & Reposição de Ração';
$paginaAtiva = 'crm_vendas'; 
include 'includes/_head.php';

$hoje = new DateTime();
$abaAtiva = $_GET['aba'] ?? 'quentes'; // 'quentes', 'todas', 'historico'

// 1. Busca oportunidades de venda com dados técnicos do pet e do produto
$sql = "SELECT o.id, o.data_compra, o.status, o.cliente_id, o.pet_id, o.produto_id,
               c.nome_completo, c.telefone, c.email,
               p.nome as pet_nome, p.especie, p.raca, p.peso_kg, p.fator_manutencao, p.porte,
               prod.nome_produto, prod.marca, prod.preco_venda, prod.tamanho_embalagem_kg, prod.energia_metabolizavel_kg
        FROM oportunidades_venda o
        JOIN clientes c ON o.cliente_id = c.id
        JOIN pets p ON o.pet_id = p.id
        JOIN produtos prod ON o.produto_id = prod.id 
        WHERE o.instituicao_id = ?
        ORDER BY o.data_compra DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$id_loja]);
$todasOportunidades = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contadores para KPIs
$kpiVencidas = 0;
$kpiCriticas = 0;
$kpiIdeais = 0;
$kpiEmUso = 0;
$kpiReceitaPotencial = 0.0;
$kpiResolvidas = 0;

$listaExibicao = [];

foreach ($todasOportunidades as $v) {
    // Cálculo da duração via Motor Nutricional
    $dias_duracao = calcularDiasRacao(
        $v['peso_kg'], 
        $v['fator_manutencao'], 
        $v['energia_metabolizavel_kg'], 
        $v['tamanho_embalagem_kg']
    );

    // Se faltarem dados matemáticos essenciais, define fallback estimativo de 30 dias
    if (!$dias_duracao || $dias_duracao <= 0) {
        $dias_duracao = 30;
    }

    $data_compra = new DateTime($v['data_compra']);
    $data_fim_prevista = clone $data_compra;
    $data_fim_prevista->modify("+$dias_duracao days");

    // Cálculo exato de dias restantes
    $diff = $hoje->diff($data_fim_prevista);
    $dias_restantes = $diff->invert ? -$diff->days : $diff->days;

    // Cálculo do consumo diário estimado em gramas
    $ner = 70 * pow((float)$v['peso_kg'], 0.75);
    $ned = $ner * (float)$v['fator_manutencao'];
    $consumo_dia_g = $v['energia_metabolizavel_kg'] > 0 ? round(($ned / (float)$v['energia_metabolizavel_kg']) * 1000) : 0;

    // Dias decorridos desde a compra
    $diffCompra = $data_compra->diff($hoje);
    $dias_decorridos = $diffCompra->invert ? 0 : $diffCompra->days;

    // Percentual consumido do pacote
    $percentual_consumido = min(100, max(0, round(($dias_decorridos / $dias_duracao) * 100)));

    $v['dias_duracao'] = $dias_duracao;
    $v['dias_restantes'] = $dias_restantes;
    $v['dias_decorridos'] = $dias_decorridos;
    $v['percentual_consumido'] = $percentual_consumido;
    $v['consumo_dia_g'] = $consumo_dia_g;
    $v['data_fim_formatada'] = $data_fim_prevista->format('d/m/Y');
    $v['data_compra_formatada'] = $data_compra->format('d/m/Y');

    // Categorização dos KPIs
    if ($v['status'] === 'Pendente') {
        if ($dias_restantes < 0) {
            $kpiVencidas++;
            $kpiReceitaPotencial += (float)$v['preco_venda'];
        } elseif ($dias_restantes <= 3) {
            $kpiCriticas++;
            $kpiReceitaPotencial += (float)$v['preco_venda'];
        } elseif ($dias_restantes <= 7) {
            $kpiIdeais++;
            $kpiReceitaPotencial += (float)$v['preco_venda'];
        } else {
            $kpiEmUso++;
        }
    } else {
        $kpiResolvidas++;
    }

    // Filtragem por Aba
    if ($abaAtiva === 'quentes') {
        if ($v['status'] === 'Pendente' && $dias_restantes <= 7) {
            $listaExibicao[] = $v;
        }
    } elseif ($abaAtiva === 'todas') {
        if ($v['status'] === 'Pendente') {
            $listaExibicao[] = $v;
        }
    } elseif ($abaAtiva === 'historico') {
        if ($v['status'] !== 'Pendente') {
            $listaExibicao[] = $v;
        }
    }
}

// Ordenação:
// Em quentes e todas, os que estão mais próximos ou já venceram vêm primeiro (ordem crescente de dias restantes)
if ($abaAtiva === 'quentes' || $abaAtiva === 'todas') {
    usort($listaExibicao, function($a, $b) {
        return $a['dias_restantes'] <=> $b['dias_restantes'];
    });
}
?>

<?php include 'includes/_sidebar.php'; ?>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; ?>

        <main class="main-content">
            
            <!-- HEADER DA PÁGINA -->
            <div class="page-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 15px; margin-bottom: 24px;">
                <div>
                    <h1 class="main-title" style="display: flex; align-items: center; gap: 10px; margin: 0 0 6px 0;">
                        <i class="fas fa-satellite-dish" style="color: #10b981;"></i> CRM Preditivo de Ração & Reposição
                    </h1>
                    <p style="color: #64748b; margin: 0; font-size: 0.95rem;">
                        Inteligência artificial baseada na <strong>Necessidade Energética (NE)</strong> de cada pet para antecipar compras antes que a ração acabe.
                    </p>
                </div>
                
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <a href="relatorio_crm.php" class="btn btn-secondary" style="padding: 10px 16px; border-radius: 10px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; text-decoration: none;">
                        <i class="fas fa-address-card"></i> Relatório CRM 360°
                    </a>
                    <a href="vendas.php" class="btn btn-primary" style="background: #0f172a; border-color: #0f172a; padding: 10px 18px; border-radius: 10px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px;">
                        <i class="fas fa-cash-register"></i> Abrir PDV / Vendas
                    </a>
                </div>
            </div>

            <!-- CARDS DE METRICAS (KPIS) -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 16px; margin-bottom: 28px;">
                
                <!-- Card 1: Já Acabaram -->
                <div class="widget-card" style="padding: 18px; border-left: 5px solid #ef4444; background: #ffffff; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,0.03);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-size: 0.82rem; font-weight: 700; color: #ef4444; text-transform: uppercase; letter-spacing: 0.5px;">Esgotadas</span>
                        <div style="width: 34px; height: 34px; border-radius: 50%; background: #fee2e2; color: #ef4444; display: flex; align-items: center; justify-content: center; font-size: 0.95rem;">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                    </div>
                    <div style="font-size: 1.8rem; font-weight: 800; color: #0f172a; line-height: 1;"><?php echo $kpiVencidas; ?></div>
                    <small style="color: #64748b; display: block; margin-top: 6px;">Pets já sem ração (Urgente)</small>
                </div>

                <!-- Card 2: Críticas (0 a 3 dias) -->
                <div class="widget-card" style="padding: 18px; border-left: 5px solid #f97316; background: #ffffff; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,0.03);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-size: 0.82rem; font-weight: 700; color: #f97316; text-transform: uppercase; letter-spacing: 0.5px;">Acaba em até 72h</span>
                        <div style="width: 34px; height: 34px; border-radius: 50%; background: #ffedd5; color: #f97316; display: flex; align-items: center; justify-content: center; font-size: 0.95rem;">
                            <i class="fas fa-hourglass-half"></i>
                        </div>
                    </div>
                    <div style="font-size: 1.8rem; font-weight: 800; color: #0f172a; line-height: 1;"><?php echo $kpiCriticas; ?></div>
                    <small style="color: #64748b; display: block; margin-top: 6px;">Faltam de 0 a 3 dias</small>
                </div>

                <!-- Card 3: Janela Ideal (4 a 7 dias) -->
                <div class="widget-card" style="padding: 18px; border-left: 5px solid #10b981; background: #ffffff; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,0.03);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-size: 0.82rem; font-weight: 700; color: #10b981; text-transform: uppercase; letter-spacing: 0.5px;">Janela Ideal</span>
                        <div style="width: 34px; height: 34px; border-radius: 50%; background: #d1fae5; color: #10b981; display: flex; align-items: center; justify-content: center; font-size: 0.95rem;">
                            <i class="fas fa-bullseye"></i>
                        </div>
                    </div>
                    <div style="font-size: 1.8rem; font-weight: 800; color: #0f172a; line-height: 1;"><?php echo $kpiIdeais; ?></div>
                    <small style="color: #64748b; display: block; margin-top: 6px;">Momento ouro de contato</small>
                </div>

                <!-- Card 4: Em Consumo (> 7 dias) -->
                <div class="widget-card" style="padding: 18px; border-left: 5px solid #0284c7; background: #ffffff; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,0.03);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-size: 0.82rem; font-weight: 700; color: #0284c7; text-transform: uppercase; letter-spacing: 0.5px;">Em Consumo</span>
                        <div style="width: 34px; height: 34px; border-radius: 50%; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 0.95rem;">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                    </div>
                    <div style="font-size: 1.8rem; font-weight: 800; color: #0f172a; line-height: 1;"><?php echo $kpiEmUso; ?></div>
                    <small style="color: #64748b; display: block; margin-top: 6px;">Pacotes em uso seguro</small>
                </div>

                <!-- Card 5: Receita Estimada -->
                <div class="widget-card" style="padding: 18px; border-left: 5px solid #8b5cf6; background: #ffffff; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,0.03);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-size: 0.82rem; font-weight: 700; color: #8b5cf6; text-transform: uppercase; letter-spacing: 0.5px;">Receita em Reposição</span>
                        <div style="width: 34px; height: 34px; border-radius: 50%; background: #ede9fe; color: #8b5cf6; display: flex; align-items: center; justify-content: center; font-size: 0.95rem;">
                            <i class="fas fa-coins"></i>
                        </div>
                    </div>
                    <div style="font-size: 1.8rem; font-weight: 800; color: #0f172a; line-height: 1;">R$ <?php echo number_format($kpiReceitaPotencial, 2, ',', '.'); ?></div>
                    <small style="color: #64748b; display: block; margin-top: 6px;">Potencial imediato de vendas</small>
                </div>

            </div>

            <!-- BARRA DE NAVEGAÇÃO POR ABAS & BUSCA RÁPIDA -->
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 22px; background: #ffffff; padding: 14px 18px; border-radius: 14px; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
                
                <!-- Abas -->
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <a href="crm_vendas.php?aba=quentes" class="btn" style="background: <?php echo $abaAtiva === 'quentes' ? '#10b981' : '#f1f5f9'; ?>; color: <?php echo $abaAtiva === 'quentes' ? '#ffffff' : '#475569'; ?>; padding: 9px 18px; border-radius: 10px; font-weight: 700; text-decoration: none; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s;">
                        <i class="fas fa-fire"></i> Oportunidades Quentes (≤ 7 dias)
                        <span style="background: <?php echo $abaAtiva === 'quentes' ? 'rgba(255,255,255,0.3)' : '#cbd5e1'; ?>; padding: 2px 8px; border-radius: 20px; font-size: 0.78rem;">
                            <?php echo $kpiVencidas + $kpiCriticas + $kpiIdeais; ?>
                        </span>
                    </a>

                    <a href="crm_vendas.php?aba=todas" class="btn" style="background: <?php echo $abaAtiva === 'todas' ? '#0284c7' : '#f1f5f9'; ?>; color: <?php echo $abaAtiva === 'todas' ? '#ffffff' : '#475569'; ?>; padding: 9px 18px; border-radius: 10px; font-weight: 700; text-decoration: none; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s;">
                        <i class="fas fa-list-ul"></i> Acompanhamento Geral
                        <span style="background: <?php echo $abaAtiva === 'todas' ? 'rgba(255,255,255,0.3)' : '#cbd5e1'; ?>; padding: 2px 8px; border-radius: 20px; font-size: 0.78rem;">
                            <?php echo $kpiVencidas + $kpiCriticas + $kpiIdeais + $kpiEmUso; ?>
                        </span>
                    </a>

                    <a href="crm_vendas.php?aba=historico" class="btn" style="background: <?php echo $abaAtiva === 'historico' ? '#64748b' : '#f1f5f9'; ?>; color: <?php echo $abaAtiva === 'historico' ? '#ffffff' : '#475569'; ?>; padding: 9px 18px; border-radius: 10px; font-weight: 700; text-decoration: none; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s;">
                        <i class="fas fa-check-circle"></i> Histórico de Baixas
                        <span style="background: <?php echo $abaAtiva === 'historico' ? 'rgba(255,255,255,0.3)' : '#cbd5e1'; ?>; padding: 2px 8px; border-radius: 20px; font-size: 0.78rem;">
                            <?php echo $kpiResolvidas; ?>
                        </span>
                    </a>
                </div>

                <!-- Campo de Busca Dinâmica (Select2) -->
                <div style="min-width: 280px; max-width: 380px; width: 100%;">
                    <select id="filtro-rapido" data-placeholder="Filtrar por pet, cliente ou ração..." data-s2-allow-clear="1" data-s2-tags="1" style="width: 100%;">
                        <option value=""></option>
                        <?php
                        $opcoesBuscaCrm = [];
                        foreach ($listaExibicao as $opItem) {
                            $opcoesBuscaCrm[trim($opItem['nome_completo'])] = 'Tutor: ' . $opItem['nome_completo'];
                            $opcoesBuscaCrm[trim($opItem['pet_nome'])] = 'Pet: ' . $opItem['pet_nome'] . ' (' . $opItem['nome_completo'] . ')';
                            $opcoesBuscaCrm[trim($opItem['nome_produto'])] = 'Ração: ' . $opItem['nome_produto'];
                        }
                        foreach ($opcoesBuscaCrm as $valOp => $rotuloOp):
                            if ($valOp === '') continue;
                        ?>
                            <option value="<?php echo htmlspecialchars($valOp); ?>"><?php echo htmlspecialchars($rotuloOp); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- GRID DE CARDS INTELIGENTES -->
            <div id="grid-oportunidades" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(370px, 1fr)); gap: 20px;">
                
                <?php foreach ($listaExibicao as $op): 
                    $dias = $op['dias_restantes'];
                    $isFechada = ($op['status'] === 'Venda Fechada');
                    
                    // Inteligência Visual de Cores e Mensagens
                    if ($isFechada) {
                        $corBadge = '#64748b';
                        $bgBadge = '#f1f5f9';
                        $textoBadge = 'Venda Fechada / Baixada';
                        $corBorda = '#cbd5e1';
                        $corBarra = '#94a3b8';
                    } elseif ($dias < 0) {
                        $corBadge = '#ffffff';
                        $bgBadge = '#ef4444';
                        $textoBadge = 'Acabou há ' . abs($dias) . ' ' . (abs($dias) == 1 ? 'dia' : 'dias');
                        $corBorda = '#ef4444';
                        $corBarra = '#ef4444';
                    } elseif ($dias == 0) {
                        $corBadge = '#ffffff';
                        $bgBadge = '#ea580c';
                        $textoBadge = '🚨 Acaba HOJE!';
                        $corBorda = '#ea580c';
                        $corBarra = '#ea580c';
                    } elseif ($dias <= 3) {
                        $corBadge = '#ffffff';
                        $bgBadge = '#f97316';
                        $textoBadge = 'Acaba em ' . $dias . ' ' . ($dias == 1 ? 'dia' : 'dias');
                        $corBorda = '#f97316';
                        $corBarra = '#f97316';
                    } elseif ($dias <= 7) {
                        $corBadge = '#ffffff';
                        $bgBadge = '#10b981';
                        $textoBadge = 'Acaba em ' . $dias . ' dias (Ideal)';
                        $corBorda = '#10b981';
                        $corBarra = '#10b981';
                    } else {
                        $corBadge = '#0369a1';
                        $bgBadge = '#e0f2fe';
                        $textoBadge = 'Restam ' . $dias . ' dias';
                        $corBorda = '#0284c7';
                        $corBarra = '#0284c7';
                    }

                    // Mensagem Personalizada do WhatsApp
                    $telefoneLimpo = preg_replace('/[^0-9]/', '', $op['telefone'] ?? '');
                    if (!empty($telefoneLimpo) && substr($telefoneLimpo, 0, 2) != '55') {
                        $telefoneLimpo = '55' . $telefoneLimpo;
                    }
                    $primeiroNome = explode(' ', trim($op['nome_completo']))[0];
                    
                    if ($dias < 0) {
                        $msgZap = "Olá $primeiroNome! 🐾 Tudo bem com você e o(a) {$op['pet_nome']}? Notamos pelo nosso controle nutricional que a ração ({$op['nome_produto']}) provavelmente já chegou ao fim. Gostaria que separássemos um novo pacote para retirada ou prefere receber por entrega?";
                    } elseif ($dias == 0) {
                        $msgZap = "Olá $primeiroNome! 🐾 Aqui é da clínica/pet shop. A ração do(a) {$op['pet_nome']} ({$op['nome_produto']}) tem previsão de terminar hoje! Para não faltar o alimento do seu pet, gostaria de pedir um novo pacote agora?";
                    } else {
                        $msgZap = "Olá $primeiroNome! 🐾 Tudo bem? Notamos que o pacote de {$op['nome_produto']} do(a) {$op['pet_nome']} deve acabar por volta do dia {$op['data_fim_formatada']}. Podemos adiantar o pedido e já reservar um novo pacote para você?";
                    }
                    $linkZap = "https://wa.me/$telefoneLimpo?text=" . urlencode($msgZap);
                ?>
                    <div class="widget-card card-oportunidade" data-busca="<?php echo strtolower($op['nome_completo'] . ' ' . $op['pet_nome'] . ' ' . $op['nome_produto'] . ' ' . $op['raca']); ?>" style="border-top: 4px solid <?php echo $corBorda; ?>; background: #ffffff; border-radius: 16px; padding: 20px; box-shadow: 0 4px 14px rgba(0,0,0,0.05); position: relative; display: flex; flex-direction: column; justify-content: space-between; transition: transform 0.2s, box-shadow 0.2s;">
                        
                        <div>
                            <!-- Header do Card: Pet e Badge de Urgência -->
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; gap: 10px;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div style="width: 44px; height: 44px; border-radius: 12px; background: <?php echo strtolower($op['especie'] ?? '') == 'felina' ? '#fdf2f8' : '#eff6ff'; ?>; color: <?php echo strtolower($op['especie'] ?? '') == 'felina' ? '#db2777' : '#2563eb'; ?>; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                                        <i class="fas <?php echo strtolower($op['especie'] ?? '') == 'felina' ? 'fa-cat' : 'fa-dog'; ?>"></i>
                                    </div>
                                    <div>
                                        <h3 style="margin: 0; font-size: 1.12rem; font-weight: 700; color: #0f172a; line-height: 1.2;">
                                            <?php echo htmlspecialchars($op['pet_nome']); ?>
                                        </h3>
                                        <small style="color: #64748b; font-size: 0.82rem;">
                                            <?php echo htmlspecialchars($op['raca'] ?? 'Raça mista'); ?> • <strong><?php echo (float)$op['peso_kg']; ?> kg</strong>
                                        </small>
                                    </div>
                                </div>

                                <span style="font-size: 0.76rem; font-weight: 700; background: <?php echo $bgBadge; ?>; color: <?php echo $corBadge; ?>; padding: 4px 10px; border-radius: 20px; white-space: nowrap; box-shadow: 0 2px 6px rgba(0,0,0,0.08);">
                                    <?php echo $textoBadge; ?>
                                </span>
                            </div>

                            <!-- Dados do Tutor / Cliente -->
                            <div style="background: #f8fafc; border-radius: 10px; padding: 10px 12px; margin-bottom: 14px; font-size: 0.86rem; color: #334155;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span>
                                        <i class="fas fa-user-circle" style="color: #94a3b8; margin-right: 4px;"></i>
                                        <strong><?php echo htmlspecialchars($op['nome_completo']); ?></strong>
                                    </span>
                                    <span style="color: #64748b; font-size: 0.8rem;">
                                        <i class="fas fa-phone-alt" style="margin-right: 3px;"></i> <?php echo htmlspecialchars($op['telefone'] ?? 'Sem telefone'); ?>
                                    </span>
                                </div>
                            </div>

                            <!-- Barra de Progresso do Consumo do Saco -->
                            <div style="margin-bottom: 14px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; font-size: 0.78rem; font-weight: 600; color: #475569;">
                                    <span>Consumo do Pacote</span>
                                    <span><?php echo $op['percentual_consumido']; ?>% consumido</span>
                                </div>
                                <div style="width: 100%; height: 8px; background: #e2e8f0; border-radius: 20px; overflow: hidden;">
                                    <div style="width: <?php echo $op['percentual_consumido']; ?>%; height: 100%; background: <?php echo $corBarra; ?>; border-radius: 20px; transition: width 0.4s ease;"></div>
                                </div>
                            </div>

                            <!-- Informações Nutricionais e do Produto -->
                            <div style="border-top: 1px dashed #e2e8f0; padding-top: 12px; margin-bottom: 16px; font-size: 0.85rem; color: #475569;">
                                <div style="margin-bottom: 6px; font-weight: 600; color: #0f172a; display: flex; align-items: center; justify-content: space-between;">
                                    <span title="<?php echo htmlspecialchars($op['nome_produto']); ?>" style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        <i class="fas fa-bone" style="color: #d97706; margin-right: 4px;"></i> <?php echo htmlspecialchars($op['nome_produto']); ?>
                                    </span>
                                    <span style="color: #10b981; font-weight: 700;">
                                        R$ <?php echo number_format($op['preco_venda'], 2, ',', '.'); ?>
                                    </span>
                                </div>

                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 6px; font-size: 0.8rem; background: #fdfdfd; padding: 6px 8px; border-radius: 8px; border: 1px solid #f1f5f9;">
                                    <div><i class="fas fa-weight-hanging" style="color: #94a3b8;"></i> Pacote: <strong><?php echo (float)$op['tamanho_embalagem_kg']; ?> kg</strong></div>
                                    <div><i class="fas fa-utensils" style="color: #94a3b8;"></i> Consumo: <strong><?php echo $op['consumo_dia_g']; ?>g/dia</strong></div>
                                    <div><i class="fas fa-calendar-alt" style="color: #94a3b8;"></i> Compra: <?php echo $op['data_compra_formatada']; ?></div>
                                    <div><i class="fas fa-hourglass-end" style="color: #94a3b8;"></i> Término: <strong><?php echo $op['data_fim_formatada']; ?></strong></div>
                                </div>
                            </div>
                        </div>

                        <!-- Ações Rápidas (WhatsApp, Repor no PDV, Dar Baixa) -->
                        <div style="border-top: 1px solid #f1f5f9; padding-top: 14px; display: flex; flex-direction: column; gap: 8px;">
                            <div style="display: flex; gap: 8px;">
                                <!-- Botão WhatsApp -->
                                <a href="<?php echo $linkZap; ?>" target="_blank" class="btn" style="flex: 1.2; text-align: center; background: #22c55e; color: white; text-decoration: none; padding: 10px 12px; border-radius: 10px; font-weight: 700; font-size: 0.85rem; display: flex; align-items: center; justify-content: center; gap: 6px; box-shadow: 0 2px 8px rgba(34, 197, 94, 0.3);">
                                    <i class="fab fa-whatsapp" style="font-size: 1.05rem;"></i> Oferecer Ração
                                </a>

                                <!-- Botão Abrir PDV com Cliente & Produto Pré-selecionados -->
                                <a href="vendas.php?cliente_id=<?php echo $op['cliente_id']; ?>&produto_id=<?php echo $op['produto_id']; ?>" class="btn" title="Abrir esta venda diretamente no PDV" style="flex: 1; text-align: center; background: #0f172a; color: white; text-decoration: none; padding: 10px 12px; border-radius: 10px; font-weight: 600; font-size: 0.85rem; display: flex; align-items: center; justify-content: center; gap: 6px;">
                                    <i class="fas fa-cash-register"></i> Repor no PDV
                                </a>
                                <a href="relatorio_crm.php?cliente_id=<?php echo (int)$op['cliente_id']; ?>" class="btn" title="Ver Histórico Completo e Relatório CRM do Cliente" style="text-align: center; background: #0284c7; color: white; text-decoration: none; padding: 10px 12px; border-radius: 10px; font-weight: 600; font-size: 0.85rem; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-address-card"></i>
                                </a>
                            </div>

                            <?php if ($op['status'] === 'Pendente'): ?>
                                <button type="button" class="btn btn-baixa" data-id="<?php echo $op['id']; ?>" style="width: 100%; padding: 8px; font-size: 0.8rem; background: #f8fafc; color: #64748b; border: 1px solid #e2e8f0; border-radius: 8px; cursor: pointer; transition: 0.2s; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 6px;">
                                    <i class="fas fa-check"></i> Marcar como Resolvida / Dar Baixa
                                </button>
                            <?php else: ?>
                                <div style="text-align: center; font-size: 0.78rem; color: #16a34a; font-weight: 700; padding: 4px;">
                                    <i class="fas fa-check-circle"></i> Oportunidade Concluída
                                </div>
                            <?php endif; ?>
                        </div>

                    </div>
                <?php endforeach; ?>

                <?php if(empty($listaExibicao)): ?>
                    <div style="grid-column: 1 / -1; text-align: center; padding: 60px 20px; background: white; border-radius: 16px; border: 2px dashed #e2e8f0;">
                        <div style="width: 70px; height: 70px; border-radius: 50%; background: #f0fdf4; color: #16a34a; font-size: 2rem; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px auto;">
                            <i class="fas fa-check"></i>
                        </div>
                        <h2 style="color: #0f172a; margin-bottom: 6px; font-weight: 800;">Nenhuma oportunidade nesta categoria</h2>
                        <p style="color: #64748b; max-width: 480px; margin: 0 auto 16px auto; font-size: 0.95rem;">
                            <?php if ($abaAtiva === 'quentes'): ?>
                                O motor nutricional não detectou nenhum pet ficando sem ração nos próximos 7 dias. Você pode ver todas as compras em <strong>"Acompanhamento Geral"</strong>.
                            <?php else: ?>
                                Não encontramos registros com os filtros atuais.
                            <?php endif; ?>
                        </p>
                        <a href="vendas.php" class="btn btn-primary" style="background: #10b981; border: none; padding: 10px 22px; border-radius: 10px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 8px;">
                            <i class="fas fa-plus"></i> Realizar Nova Venda no PDV
                        </a>
                    </div>
                <?php endif; ?>

            </div>

        </main>
    </div>
</div>

<script>
// Filtro instantâneo no navegador por Pet, Tutor ou Ração (compatível com Select2)
function aplicarFiltroRapidoCrm(valor) {
    const termo = (valor || '').toLowerCase().trim();
    const cards = document.querySelectorAll('.card-oportunidade');
    cards.forEach(card => {
        const texto = card.getAttribute('data-busca') || '';
        card.style.display = (termo === '' || texto.includes(termo)) ? 'flex' : 'none';
    });
}
const elFiltroRapidoCrm = document.getElementById('filtro-rapido');
if (elFiltroRapidoCrm) {
    elFiltroRapidoCrm.addEventListener('input', e => aplicarFiltroRapidoCrm(e.target.value));
    elFiltroRapidoCrm.addEventListener('change', e => aplicarFiltroRapidoCrm(e.target.value));
}

// Ação de Dar Baixa com chamada à API
document.querySelectorAll('.btn-baixa').forEach(btn => {
    btn.addEventListener('click', function() {
        const idOp = this.getAttribute('data-id');
        if (!confirm("Deseja marcar esta reposição como resolvida/concluída?")) return;

        const card = this.closest('.card-oportunidade');
        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
        this.disabled = true;

        fetch('api_atualizar_oportunidade.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ id: idOp, status: 'Venda Fechada' })
        })
        .then(res => res.json())
        .then(res => {
            if(res.success) {
                card.style.opacity = '0';
                card.style.transform = 'scale(0.95)';
                setTimeout(() => {
                    card.style.display = 'none';
                }, 300);
            } else {
                alert("Erro ao atualizar: " + (res.message || 'Falha no servidor'));
                this.innerHTML = '<i class="fas fa-check"></i> Marcar como Resolvida / Dar Baixa';
                this.disabled = false;
            }
        })
        .catch(err => {
            console.error(err);
            alert("Erro de comunicação com o servidor.");
            this.innerHTML = '<i class="fas fa-check"></i> Marcar como Resolvida / Dar Baixa';
            this.disabled = false;
        });
    });
});
</script>

<?php include 'includes/_footer.php'; ?>
