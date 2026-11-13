<?php
$pageTitle = 'Agenda & Atendimentos';
$paginaAtiva = 'agenda';

require 'includes/verifica_login.php'; 
require 'conexao.php'; 

// --- 1. PROTEÇÃO SAAS E PERMISSÕES MODULARES ---
$id_loja = $_SESSION['instituicao_id'] ?? null;
$cargo = $_SESSION['usuario_cargo'] ?? '';
$usuario_id = $_SESSION['usuario_id'] ?? 0;

if (!$id_loja) {
    header("Location: login.php"); 
    exit;
}

// Verifica a permissão no JSON se não for Gerente
if ($cargo !== 'Gerente' && $cargo !== 'Admin') {
    $stmtPerm = $pdo->prepare("SELECT permissoes FROM usuarios WHERE id = ?");
    $stmtPerm->execute([$usuario_id]);
    $jsonPerm = $stmtPerm->fetchColumn();
    $permissoes = $jsonPerm ? json_decode($jsonPerm, true) : [];
    
    if (empty($permissoes['agenda'])) {
        header("Location: dashboard.php");
        exit;
    }
}

// --- 2. LÓGICA DE NAVEGAÇÃO DE DATAS ---
$dataBase = !empty($_GET['data_base']) ? $_GET['data_base'] : date('Y-m-d'); 
$timestampBase = strtotime($dataBase);
$diaDaSemanaBase = date('N', $timestampBase); // 1 (Seg) a 7 (Dom)

$inicioSemana = date('Y-m-d 00:00:00', strtotime('-' . ($diaDaSemanaBase - 1) . ' days', $timestampBase));
$fimSemana = date('Y-m-d 23:59:59', strtotime('+' . (7 - $diaDaSemanaBase) . ' days', $timestampBase));

$linkSemanaAnterior = date('Y-m-d', strtotime('-7 days', $timestampBase));
$linkSemanaSeguinte = date('Y-m-d', strtotime('+7 days', $timestampBase));
$displayRange = date('d M', strtotime($inicioSemana)) . ' – ' . date('d M, Y', strtotime($fimSemana));

$hojeYmd = date('Y-m-d');
$hojeInicio = $hojeYmd . ' 00:00:00';
$hojeFim = $hojeYmd . ' 23:59:59';

// --- 3. BUSCAS NO BANCO DE DADOS (FILTRADAS PELA LOJA) ---

// Busca Clientes (inclui clientes da rede se compartilhado)
require_once __DIR__ . '/includes/unidades.php';
$ids_clientes = obterInstituicoesClientes($pdo, (int)$id_loja);
$in_cli = implode(',', array_fill(0, count($ids_clientes), '?'));
$stmt_clientes = $pdo->prepare("SELECT id, nome_completo, telefone FROM clientes WHERE instituicao_id IN ($in_cli) ORDER BY nome_completo");
$stmt_clientes->execute($ids_clientes);
$clientes = $stmt_clientes->fetchAll(PDO::FETCH_ASSOC);

// Busca Profissionais
$stmt_pro = $pdo->prepare("SELECT id, nome, cargo FROM usuarios WHERE instituicao_id = ? AND ativo = 1 AND cargo != 'Admin' ORDER BY nome");
$stmt_pro->execute([$id_loja]);
$profissionais = $stmt_pro->fetchAll(PDO::FETCH_ASSOC);

// Serviços pré-cadastrados ou padrão
$servicosPadrao = [
    'Banho Completo & Hidratação',
    'Banho e Tosa',
    'Tosa Higiênica',
    'Tosa da Raça / Tosa Bebê',
    'Consulta Veterinária Geral',
    'Vacina',
    'Corte de Unhas & Limpeza',
    'Cirurgia Geral'
];
try {
    $stmt_serv = $pdo->prepare("SELECT nome_servico FROM servicos WHERE instituicao_id = ? OR instituicao_id IS NULL ORDER BY nome_servico");
    $stmt_serv->execute([$id_loja]);
    $db_serv = $stmt_serv->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($db_serv)) {
        $servicosPadrao = array_unique(array_merge($servicosPadrao, $db_serv));
    }
} catch (Exception $e) {}

// Busca Agendamentos da Semana Enriquecidos
$sql_ag = "SELECT a.*, 
                  p.id AS real_pet_id, p.nome AS pet_nome, p.especie AS pet_especie, p.raca AS pet_raca, p.porte AS pet_porte,
                  c.id AS real_cliente_id, c.nome_completo AS cliente_nome, c.telefone AS cliente_telefone,
                  u.nome AS profissional_nome_real
           FROM agendamentos a
           JOIN pets p ON a.pet_id = p.id
           JOIN clientes c ON p.cliente_id = c.id
           LEFT JOIN usuarios u ON a.profissional_id = u.id
           WHERE a.instituicao_id = ? 
           AND a.status != 'Cancelado'
           AND a.data_hora_inicio BETWEEN ? AND ?
           ORDER BY a.data_hora_inicio ASC";

$stmt_ag = $pdo->prepare($sql_ag);
$stmt_ag->execute([$id_loja, $inicioSemana, $fimSemana]); 
$agendamentos = $stmt_ag->fetchAll(PDO::FETCH_ASSOC);

// Distribuição por dia da semana
$agendamentosPorDia = [1 => [], 2 => [], 3 => [], 4 => [], 5 => [], 6 => [], 7 => []];
foreach ($agendamentos as $ag) {
    $diaSemanaAgendamento = (int)date('N', strtotime($ag['data_hora_inicio']));
    $agendamentosPorDia[$diaSemanaAgendamento][] = $ag;
}

// Agendamentos da Data Selecionada (Fila do Dia / Atendimentos)
$agendamentosDataSelecionada = [];
foreach ($agendamentos as $ag) {
    if (date('Y-m-d', strtotime($ag['data_hora_inicio'])) === $dataBase) {
        $agendamentosDataSelecionada[] = $ag;
    }
}

// Se não houver nenhum na semana para a dataBase, busca isolada do dia para garantir a lista
if (empty($agendamentosDataSelecionada)) {
    $sql_dia = "SELECT a.*, 
                      p.id AS real_pet_id, p.nome AS pet_nome, p.especie AS pet_especie, p.raca AS pet_raca, p.porte AS pet_porte,
                      c.id AS real_cliente_id, c.nome_completo AS cliente_nome, c.telefone AS cliente_telefone,
                      u.nome AS profissional_nome_real
               FROM agendamentos a
               JOIN pets p ON a.pet_id = p.id
               JOIN clientes c ON p.cliente_id = c.id
               LEFT JOIN usuarios u ON a.profissional_id = u.id
               WHERE a.instituicao_id = ? 
               AND a.status != 'Cancelado'
               AND a.data_hora_inicio BETWEEN ? AND ?
               ORDER BY a.data_hora_inicio ASC";
    $stmt_dia = $pdo->prepare($sql_dia);
    $stmt_dia->execute([$id_loja, $dataBase . ' 00:00:00', $dataBase . ' 23:59:59']);
    $agendamentosDataSelecionada = $stmt_dia->fetchAll(PDO::FETCH_ASSOC);
}

// Métricas dos Atendimentos do Dia Selecionado
$kpi_total = count($agendamentosDataSelecionada);
$kpi_andamento = 0;
$kpi_concluido = 0;
$kpi_agendado = 0;

foreach ($agendamentosDataSelecionada as $ag) {
    $st = $ag['status'] ?? 'Agendado';
    if ($st === 'Em Andamento' || $st === 'Aguardando') {
        $kpi_andamento++;
    } elseif ($st === 'Concluído') {
        $kpi_concluido++;
    } else {
        $kpi_agendado++;
    }
}

// Funções de Cálculo e Temas Visuais
function getServiceTheme($servico) {
    $s = mb_strtolower($servico ?? '', 'UTF-8');
    if (strpos($s, 'tosa') !== false) {
        return ['theme' => 'service-tosa', 'color' => '#8b5cf6', 'icon' => 'fa-scissors', 'label' => 'Tosa'];
    } elseif (strpos($s, 'banho') !== false) {
        return ['theme' => 'service-banho', 'color' => '#0284c7', 'icon' => 'fa-bath', 'label' => 'Banho'];
    } elseif (strpos($s, 'consulta') !== false || strpos($s, 'veterin') !== false) {
        return ['theme' => 'service-consulta', 'color' => '#10b981', 'icon' => 'fa-stethoscope', 'label' => 'Consulta'];
    } elseif (strpos($s, 'vacina') !== false) {
        return ['theme' => 'service-vacina', 'color' => '#f59e0b', 'icon' => 'fa-syringe', 'label' => 'Vacina'];
    } elseif (strpos($s, 'cirurg') !== false) {
        return ['theme' => 'service-cirurgia', 'color' => '#ef4444', 'icon' => 'fa-heartbeat', 'label' => 'Cirurgia'];
    }
    return ['theme' => 'service-default', 'color' => '#64748b', 'icon' => 'fa-paw', 'label' => 'Serviço'];
}

function calcularPosicao($datetime) {
    $hora = (int)date('H', strtotime($datetime));
    $minuto = (int)date('i', strtotime($datetime));
    $offsetInicio = 8; // Começa às 08:00
    $alturaHora = 64;  // 64px por hora
    return max(0, (($hora - $offsetInicio) * $alturaHora) + (($minuto / 60) * $alturaHora));
}

function calcularAltura($inicio, $fim) {
    $duracaoMinutos = (strtotime($fim) - strtotime($inicio)) / 60;
    if ($duracaoMinutos <= 0) $duracaoMinutos = 45;
    $alturaHora = 64;
    return max(48, ($duracaoMinutos / 60) * $alturaHora);
}

// Linha do Tempo Atual (Indicador Vermelho "AGORA")
$horaAtual = (int)date('H');
$minutoAtual = (int)date('i');
$topLinhaAgora = max(0, (($horaAtual - 8) * 64) + (($minutoAtual / 60) * 64));
$estaNoHorarioAtendimento = ($horaAtual >= 8 && $horaAtual <= 18);

include 'includes/_head.php';
include 'includes/_sidebar.php'; 
?>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; ?>

        <main class="main-content">
            
            <!-- ==============================================
                 BARRA DE CABEÇALHO & VISÕES DA AGENDA (ERP LEVEL)
                 ============================================== -->
            <div class="agenda-top-header">
                <div>
                    <h1 class="main-title" style="margin-bottom: 2px;">Agenda & Atendimentos</h1>
                    <p class="cad-header-sub">Gestão visual do fluxo de banho e tosa, consultas e procedimentos.</p>
                </div>

                <div class="agenda-header-actions">
                    <!-- Alternador de Visões: Grade Semanal vs Fila do Dia -->
                    <div class="view-switcher-tabs">
                        <button type="button" class="view-tab-btn active" id="tab-view-semana" data-view="semana">
                            <i class="fas fa-calendar-week"></i> Visão Semanal
                        </button>
                        <button type="button" class="view-tab-btn" id="tab-view-fila" data-view="fila">
                            <i class="fas fa-stream"></i> Fila do Dia
                            <?php if ($kpi_total > 0): ?>
                                <span class="badge-count"><?php echo $kpi_total; ?></span>
                            <?php endif; ?>
                        </button>
                    </div>

                    <button class="btn btn-primary btn-novo-agendamento-top" id="add-appointment-btn">
                        <i class="fas fa-plus-circle"></i> Novo Agendamento
                    </button>
                </div>
            </div>

            <!-- ==============================================
                 MINI-DASHBOARD DE ATENDIMENTOS (KPIs DO DIA)
                 ============================================== -->
            <div class="agenda-kpi-grid">
                <div class="agenda-kpi-card">
                    <div class="kpi-icon-box kpi-icon-blue">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="kpi-info">
                        <span class="kpi-label">Agendados Hoje</span>
                        <strong class="kpi-val"><?php echo $kpi_total; ?></strong>
                    </div>
                </div>

                <div class="agenda-kpi-card">
                    <div class="kpi-icon-box kpi-icon-amber">
                        <i class="fas fa-hourglass-half"></i>
                    </div>
                    <div class="kpi-info">
                        <span class="kpi-label">Aguardando / Na Fila</span>
                        <strong class="kpi-val" style="color: #f59e0b;"><?php echo $kpi_agendado; ?></strong>
                    </div>
                </div>

                <div class="agenda-kpi-card">
                    <div class="kpi-icon-box kpi-icon-purple">
                        <i class="fas fa-bath"></i>
                    </div>
                    <div class="kpi-info">
                        <span class="kpi-label">Em Atendimento</span>
                        <strong class="kpi-val" style="color: #8b5cf6;"><?php echo $kpi_andamento; ?></strong>
                    </div>
                </div>

                <div class="agenda-kpi-card">
                    <div class="kpi-icon-box kpi-icon-green">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="kpi-info">
                        <span class="kpi-label">Concluídos Hoje</span>
                        <strong class="kpi-val" style="color: #10b981;"><?php echo $kpi_concluido; ?></strong>
                    </div>
                </div>
            </div>

            <!-- ==============================================
                 BARRA DE FILTROS & NAVEGAÇÃO DE DATAS
                 ============================================== -->
            <div class="agenda-control-toolbar">
                <div class="date-navigator-cluster">
                    <a href="agenda.php?data_base=<?php echo $linkSemanaAnterior; ?>" class="btn-nav-arrow" title="Semana Anterior">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    <a href="agenda.php?data_base=<?php echo $hojeYmd; ?>" class="btn-nav-hoje <?php echo ($dataBase === $hojeYmd) ? 'active' : ''; ?>">
                        Hoje
                    </a>
                    <a href="agenda.php?data_base=<?php echo $linkSemanaSeguinte; ?>" class="btn-nav-arrow" title="Próxima Semana">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                    <div class="date-range-badge">
                        <i class="far fa-calendar-alt"></i>
                        <span><?php echo $displayRange; ?></span>
                    </div>
                </div>

                <!-- Filtros Rápidos Dinâmicos -->
                <div class="agenda-filters-cluster">
                    <div class="filter-item" style="min-width:190px;">
                        <select id="filtro-agenda-profissional" class="agenda-filter-select" data-s2-allow-clear="1" data-placeholder="Todos os Profissionais">
                            <option value="">Todos os Profissionais</option>
                            <?php foreach ($profissionais as $prof): ?>
                                <option value="<?php echo $prof['id']; ?>"><?php echo htmlspecialchars($prof['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-item" style="min-width:170px;">
                        <select id="filtro-agenda-servico" class="agenda-filter-select" data-s2-allow-clear="1" data-placeholder="Todos os Serviços">
                            <option value="">Todos os Serviços</option>
                            <option value="Banho">Banho</option>
                            <option value="Tosa">Tosa</option>
                            <option value="Consulta">Consulta Veterinária</option>
                            <option value="Vacina">Vacina</option>
                            <option value="Cirurgia">Cirurgia</option>
                        </select>
                    </div>

                    <div class="filter-item" style="min-width:165px;">
                        <select id="filtro-agenda-status" class="agenda-filter-select" data-s2-allow-clear="1" data-placeholder="Todos os Status">
                            <option value="">Todos os Status</option>
                            <option value="Agendado">Agendados</option>
                            <option value="Em Andamento">Em Atendimento</option>
                            <option value="Concluído">Concluídos</option>
                        </select>
                    </div>

                    <div class="filter-search-box" style="min-width:220px;">
                        <select id="filtro-agenda-busca" class="agenda-filter-select" data-placeholder="Buscar pet ou tutor..." data-s2-allow-clear="1" data-s2-tags="1" style="width:100%;">
                            <option value=""></option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- ==============================================
                 VISÃO 1: GRADE SEMANAL (CALENDAR TIMELINE GRID)
                 ============================================== -->
            <div id="container-visao-semana" class="agenda-view-section active">
                <div class="calendar-grid-container modern-calendar-wrapper">
                    
                    <!-- Coluna das Horas (Timeline) -->
                    <div class="time-column modern-time-column">
                        <div class="time-col-header"><i class="far fa-clock"></i></div>
                        <?php for($h=8; $h<=18; $h++): ?>
                            <div class="time-slot-item">
                                <span class="time-label"><?php echo str_pad($h, 2, '0', STR_PAD_LEFT); ?>:00</span>
                                <div class="time-sub-line"></div>
                            </div>
                        <?php endfor; ?>
                    </div>

                    <!-- Grade dos 7 Dias da Semana -->
                    <div class="calendar-grid modern-calendar-grid">
                        <?php for ($i = 0; $i < 7; $i++): 
                            $dataDoDiaTimestamp = strtotime($inicioSemana . ' +' . $i . ' days');
                            $dataDoDiaYmd = date('Y-m-d', $dataDoDiaTimestamp);
                            $diasSemanaNomes = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
                            $nomeDiaSemana = $diasSemanaNomes[date('w', $dataDoDiaTimestamp)];
                            $numeroDia = date('d', $dataDoDiaTimestamp);
                            $isHoje = ($hojeYmd === $dataDoDiaYmd);
                            $diaDaSemanaIndice = (int)date('N', $dataDoDiaTimestamp);
                            $agendamentosNesteDia = $agendamentosPorDia[$diaDaSemanaIndice] ?? [];
                            $totalNesteDia = count($agendamentosNesteDia);
                        ?>
                            <div class="day-column <?php echo $isHoje ? 'current-day-col' : ''; ?>" data-date="<?php echo $dataDoDiaYmd; ?>">
                                
                                <!-- Cabeçalho do Dia -->
                                <div class="day-header-modern <?php echo $isHoje ? 'is-today' : ''; ?>">
                                    <div class="day-title-row">
                                        <span class="day-weekday"><?php echo $nomeDiaSemana; ?></span>
                                        <span class="day-number-circle"><?php echo $numeroDia; ?></span>
                                    </div>
                                    <div class="day-meta-row">
                                        <?php if ($isHoje): ?>
                                            <span class="badge-hoje-pill">HOJE</span>
                                        <?php endif; ?>
                                        <span class="day-pets-counter"><?php echo $totalNesteDia; ?> <?php echo ($totalNesteDia === 1) ? 'pet' : 'pets'; ?></span>
                                    </div>
                                </div>

                                <!-- Área dos Slots e Atendimentos do Dia -->
                                <div class="appointments-stage" data-date="<?php echo $dataDoDiaYmd; ?>" title="Dê um clique para agendar neste horário">
                                    
                                    <!-- Linha do Tempo "AGORA" (Apenas na coluna de Hoje) -->
                                    <?php if ($isHoje && $estaNoHorarioAtendimento): ?>
                                        <div class="current-time-marker" style="top: <?php echo $topLinhaAgora; ?>px;">
                                            <div class="marker-dot"></div>
                                            <div class="marker-line"></div>
                                            <span class="marker-label"><?php echo date('H:i'); ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Cards dos Agendamentos -->
                                    <?php foreach ($agendamentosNesteDia as $ag): 
                                        $top = calcularPosicao($ag['data_hora_inicio']);
                                        $height = calcularAltura($ag['data_hora_inicio'], $ag['data_hora_fim']);
                                        $srvTheme = getServiceTheme($ag['servico']);
                                        
                                        $dataInicio = $ag['data_hora_inicio'];
                                        $dataApp = $dataInicio ? date('Y-m-d', strtotime($dataInicio)) : '';
                                        $horaInicioFormatada = $dataInicio ? date('H:i', strtotime($dataInicio)) : '';
                                        $horaFimFormatada = $ag['data_hora_fim'] ? date('H:i', strtotime($ag['data_hora_fim'])) : '';
                                        $profId = !empty($ag['profissional_id']) ? $ag['profissional_id'] : '0';
                                        $status = $ag['status'] ?? 'Agendado';

                                        // Telefone limpo para WhatsApp
                                        $telLimpo = preg_replace('/\D/', '', $ag['cliente_telefone'] ?? '');
                                    ?>
                                        <div class="appointment-card modern-appointment-card <?php echo $srvTheme['theme']; ?> status-card-<?php echo strtolower(str_replace(' ', '-', $status)); ?>"
                                             style="top: <?php echo $top; ?>px; height: <?php echo $height; ?>px;"
                                             data-id="<?php echo $ag['id']; ?>"
                                             data-cliente-id="<?php echo $ag['real_cliente_id']; ?>"
                                             data-cliente-nome="<?php echo htmlspecialchars($ag['cliente_nome']); ?>"
                                             data-cliente-tel="<?php echo htmlspecialchars($ag['cliente_telefone'] ?? ''); ?>"
                                             data-pet-id="<?php echo $ag['real_pet_id']; ?>"
                                             data-pet-nome="<?php echo htmlspecialchars($ag['pet_nome']); ?>"
                                             data-pet-raca="<?php echo htmlspecialchars($ag['pet_raca'] ?? ''); ?>"
                                             data-pet-especie="<?php echo htmlspecialchars($ag['pet_especie'] ?? ''); ?>"
                                             data-servico="<?php echo htmlspecialchars($ag['servico'] ?? ''); ?>"
                                             data-date="<?php echo $dataApp; ?>"
                                             data-time="<?php echo $horaInicioFormatada; ?>"
                                             data-profissional-id="<?php echo $profId; ?>"
                                             data-profissional-nome="<?php echo htmlspecialchars($ag['profissional_nome_real'] ?: ($ag['profissional'] ?? 'Não especificado')); ?>"
                                             data-obs="<?php echo htmlspecialchars($ag['observacoes'] ?? ''); ?>"
                                             data-status="<?php echo htmlspecialchars($status); ?>"
                                        >
                                            <div class="card-accent-bar" style="background-color: <?php echo $srvTheme['color']; ?>;"></div>
                                            
                                            <div class="card-content-inner">
                                                <!-- Linha 1: Horário + Status -->
                                                <div class="card-header-time-row">
                                                    <span class="card-time-badge">
                                                        <i class="far fa-clock"></i> <?php echo $horaInicioFormatada; ?> - <?php echo $horaFimFormatada; ?>
                                                    </span>
                                                    <span class="card-status-pill status-pill-<?php echo strtolower(str_replace(' ', '-', $status)); ?>">
                                                        <?php echo $status; ?>
                                                    </span>
                                                </div>

                                                <!-- Linha 2 Padrão: Nome do Pet / Nome do Dono -->
                                                <div class="card-summary-row">
                                                    <span class="card-pet-tutor-text" title="<?php echo htmlspecialchars($ag['pet_nome'] . ' / ' . $ag['cliente_nome']); ?>">
                                                        <i class="fas <?php echo ($ag['pet_especie'] === 'Gato') ? 'fa-cat' : 'fa-paw'; ?>"></i> 
                                                        <strong class="card-pet-highlight"><?php echo htmlspecialchars($ag['pet_nome']); ?></strong>
                                                        <span class="tutor-divider">/</span>
                                                        <span class="card-tutor-inline"><?php echo htmlspecialchars($ag['cliente_nome']); ?></span>
                                                    </span>
                                                </div>

                                                <!-- Bloco Expandido ao Passar o Mouse (Hover) -->
                                                <div class="card-hover-details">
                                                    <div class="hover-detail-item hover-service-item">
                                                        <i class="fas <?php echo $srvTheme['icon']; ?>" style="color: <?php echo $srvTheme['color']; ?>;"></i> 
                                                        <strong><?php echo htmlspecialchars($ag['servico']); ?></strong>
                                                    </div>

                                                    <?php if (!empty($ag['pet_raca']) || !empty($ag['pet_porte'])): ?>
                                                        <div class="hover-detail-item">
                                                            <i class="fas fa-tag"></i>
                                                            <span><?php echo htmlspecialchars(trim(($ag['pet_raca'] ?? '') . ($ag['pet_porte'] ? ' (' . $ag['pet_porte'] . ')' : ''))); ?></span>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if (!empty($ag['cliente_telefone'])): ?>
                                                        <div class="hover-detail-item">
                                                            <i class="fab fa-whatsapp" style="color: #4ade80;"></i>
                                                            <span><?php echo htmlspecialchars($ag['cliente_telefone']); ?></span>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if (!empty($ag['profissional_nome_real'] ?: $ag['profissional'])): ?>
                                                        <div class="hover-detail-item">
                                                            <i class="fas fa-user-md"></i>
                                                            <span>Prof: <?php echo htmlspecialchars($ag['profissional_nome_real'] ?: $ag['profissional']); ?></span>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if (!empty($ag['observacoes'])): ?>
                                                        <div class="hover-detail-item hover-obs-item">
                                                            <i class="fas fa-comment-alt"></i>
                                                            <em>"<?php echo htmlspecialchars($ag['observacoes']); ?>"</em>
                                                        </div>
                                                    <?php endif; ?>

                                                    <div class="hover-action-hint">
                                                        <i class="fas fa-external-link-alt"></i> Clique para gerenciar ou concluir
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>

                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <!-- ==============================================
                 VISÃO 2: FILA DO DIA / ATENDIMENTOS OPERACIONAIS (ESTILO ERP DE PONTA)
                 ============================================== -->
            <div id="container-visao-fila" class="agenda-view-section" style="display: none;">
                <div class="fila-dia-container">
                    
                    <div class="fila-dia-header">
                        <div>
                            <h3><i class="fas fa-stream"></i> Fila de Atendimentos — <?php echo date('d/m/Y', strtotime($dataBase)); ?></h3>
                            <p>Controle o fluxo operacional, avise tutores no WhatsApp com 1 clique e envie direto para o Caixa.</p>
                        </div>
                        <div class="fila-header-badge">
                            <span><?php echo count($agendamentosDataSelecionada); ?> agendamentos nesta data</span>
                        </div>
                    </div>

                    <?php if (empty($agendamentosDataSelecionada)): ?>
                        <div class="fila-empty-state">
                            <i class="fas fa-calendar-day"></i>
                            <h4>Nenhum atendimento agendado para esta data</h4>
                            <p>Clique no botão abaixo para adicionar o primeiro atendimento deste dia.</p>
                            <button type="button" class="btn btn-primary" onclick="document.getElementById('add-appointment-btn').click();">
                                <i class="fas fa-plus"></i> Agendar Atendimento
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="fila-cards-list">
                            <?php foreach ($agendamentosDataSelecionada as $ag): 
                                $srvTheme = getServiceTheme($ag['servico']);
                                $horaIni = date('H:i', strtotime($ag['data_hora_inicio']));
                                $horaFim = date('H:i', strtotime($ag['data_hora_fim']));
                                $status = $ag['status'] ?? 'Agendado';
                                $telLimpo = preg_replace('/\D/', '', $ag['cliente_telefone'] ?? '');
                                
                                // Mensagem humanizada para WhatsApp
                                $msgWhats = urlencode("Olá " . $ag['cliente_nome'] . "! 🐾 Passando para avisar que o(a) " . $ag['pet_nome'] . " já está pronto(a) e cheiroso(a) para ser buscado(a) aqui na " . ($_SESSION['instituicao_nome'] ?? 'loja') . "! Aguardamos você!");
                                $linkWhats = !empty($telLimpo) ? "https://api.whatsapp.com/send?phone=55{$telLimpo}&text={$msgWhats}" : "#";
                            ?>
                                <div class="fila-card-item status-border-<?php echo strtolower(str_replace(' ', '-', $status)); ?>">
                                    
                                    <!-- Bloco de Horário -->
                                    <div class="fila-col-time">
                                        <div class="fila-time-box">
                                            <strong><?php echo $horaIni; ?></strong>
                                            <small><?php echo $horaFim; ?></small>
                                        </div>
                                        <span class="card-status-pill status-pill-<?php echo strtolower(str_replace(' ', '-', $status)); ?>">
                                            <?php echo $status; ?>
                                        </span>
                                    </div>

                                    <!-- Bloco do Pet & Tutor -->
                                    <div class="fila-col-pet">
                                        <div class="pet-avatar-circle" style="background: rgba(26, 188, 156, 0.15); color: var(--color-primary);">
                                            <i class="fas <?php echo ($ag['pet_especie'] === 'Gato') ? 'fa-cat' : 'fa-paw'; ?>"></i>
                                        </div>
                                        <div>
                                            <h4 class="fila-pet-title"><?php echo htmlspecialchars($ag['pet_nome']); ?></h4>
                                            <span class="fila-pet-meta">
                                                <?php echo htmlspecialchars($ag['pet_especie'] ?? 'Pet'); ?> 
                                                <?php echo !empty($ag['pet_raca']) ? '· ' . htmlspecialchars($ag['pet_raca']) : ''; ?>
                                                <?php echo !empty($ag['pet_porte']) ? '· ' . htmlspecialchars($ag['pet_porte']) : ''; ?>
                                            </span>
                                            <div class="fila-tutor-meta">
                                                <i class="far fa-user"></i> Tutor: <strong><?php echo htmlspecialchars($ag['cliente_nome']); ?></strong>
                                                <?php if (!empty($ag['cliente_telefone'])): ?>
                                                    <span style="color: var(--text-muted); margin-left: 6px;">(<?php echo htmlspecialchars($ag['cliente_telefone']); ?>)</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Bloco do Serviço & Profissional -->
                                    <div class="fila-col-service">
                                        <div class="fila-service-badge" style="border-left: 3px solid <?php echo $srvTheme['color']; ?>;">
                                            <i class="fas <?php echo $srvTheme['icon']; ?>" style="color: <?php echo $srvTheme['color']; ?>;"></i>
                                            <strong><?php echo htmlspecialchars($ag['servico']); ?></strong>
                                        </div>
                                        <div class="fila-prof-info">
                                            <i class="fas fa-user-md"></i> Profissional: 
                                            <span><?php echo htmlspecialchars($ag['profissional_nome_real'] ?: ($ag['profissional'] ?? 'Qualquer profissional')); ?></span>
                                        </div>
                                        <?php if (!empty($ag['observacoes'])): ?>
                                            <div class="fila-obs-info" title="<?php echo htmlspecialchars($ag['observacoes']); ?>">
                                                <i class="far fa-comment-dots"></i> <?php echo htmlspecialchars($ag['observacoes']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Bloco de Ações Rápidas de ERP -->
                                    <div class="fila-col-actions">
                                        
                                        <!-- Botão Notificar Tutor via WhatsApp -->
                                        <?php if (!empty($telLimpo)): ?>
                                            <a href="<?php echo $linkWhats; ?>" target="_blank" class="btn-fila-action btn-fila-whatsapp" title="Avisar no WhatsApp que o pet está pronto">
                                                <i class="fab fa-whatsapp"></i> Avisar Tutor
                                            </a>
                                        <?php endif; ?>

                                        <!-- Botão Cobrar no PDV -->
                                        <a href="vendas.php?cliente_id=<?php echo $ag['real_cliente_id']; ?>" class="btn-fila-action btn-fila-pdv" title="Cobrar este atendimento no Caixa (PDV)">
                                            <i class="fas fa-cash-register"></i> Cobrar no PDV
                                        </a>

                                        <!-- Botões de Ciclo de Vida -->
                                        <div class="fila-status-actions-group">
                                            <?php if ($status === 'Agendado'): ?>
                                                <button type="button" class="btn-fila-action btn-fila-start btn-mudar-status-rapido" data-id="<?php echo $ag['id']; ?>" data-status="Em Andamento" title="Iniciar Atendimento">
                                                    <i class="fas fa-play"></i> Iniciar
                                                </button>
                                            <?php elseif ($status === 'Em Andamento'): ?>
                                                <button type="button" class="btn-fila-action btn-fila-finish btn-mudar-status-rapido" data-id="<?php echo $ag['id']; ?>" data-status="Concluído" title="Marcar como Concluído">
                                                    <i class="fas fa-check-double"></i> Concluir
                                                </button>
                                            <?php endif; ?>
                                            
                                            <button type="button" class="btn-fila-action btn-fila-edit btn-abrir-modal-editar" 
                                                    data-id="<?php echo $ag['id']; ?>"
                                                    data-cliente-id="<?php echo $ag['real_cliente_id']; ?>"
                                                    data-pet-id="<?php echo $ag['real_pet_id']; ?>"
                                                    data-servico="<?php echo htmlspecialchars($ag['servico'] ?? ''); ?>"
                                                    data-date="<?php echo date('Y-m-d', strtotime($ag['data_hora_inicio'])); ?>"
                                                    data-time="<?php echo $horaIni; ?>"
                                                    data-profissional-id="<?php echo $ag['profissional_id'] ?? '0'; ?>"
                                                    data-obs="<?php echo htmlspecialchars($ag['observacoes'] ?? ''); ?>"
                                                    data-status="<?php echo htmlspecialchars($status); ?>"
                                                    data-cliente-tel="<?php echo htmlspecialchars($ag['cliente_telefone'] ?? ''); ?>"
                                                    data-pet-nome="<?php echo htmlspecialchars($ag['pet_nome']); ?>"
                                            >
                                                <i class="fas fa-edit"></i> Detalhes
                                            </button>
                                        </div>

                                    </div>

                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

        </main>
    </div>
</div>

<!-- ==============================================
     MODAL DE AGENDAMENTO APRIMORADO (PRO LEVEL)
     ============================================== -->
<div class="modal-overlay" id="appointment-modal" style="display: none;">
    <div class="modal-content" style="max-width: 560px; width: 100%;">
        <div class="modal-header">
            <h2 id="modal-app-title">Novo Agendamento</h2>
            <button type="button" class="close-modal-btn" aria-label="Fechar">&times;</button>
        </div>
        <div class="modal-body">
            
            <!-- Barra de Status Atual & Ação WhatsApp -->
            <div id="app-status-container" style="display:none; margin-bottom: 14px; padding: 10px 14px; background: rgba(0,0,0,0.03); border-radius: 8px; border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between;">
                <div>
                    <span style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted);">Status atual:</span>
                    <span id="app-status-pill" style="display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 0.8rem; font-weight: 600; margin-left: 6px;"></span>
                </div>
                <a id="btn-modal-whats-direct" href="#" target="_blank" style="display:none; color: #22c55e; font-size: 0.85rem; font-weight: 600; text-decoration: none; align-items: center; gap: 6px;">
                    <i class="fab fa-whatsapp" style="font-size: 1.1rem;"></i> Avisar Tutor
                </a>
            </div>

            <form id="appointment-form"> 
                <input type="hidden" id="app-id" value="">

                <div class="form-group">
                    <label for="app-client">Cliente / Tutor *</label>
                    <select id="app-client" required style="width:100%;">
                        <option value="">Selecione um cliente...</option>
                        <?php foreach ($clientes as $cliente): ?>
                            <option value="<?php echo $cliente['id']; ?>" data-tel="<?php echo htmlspecialchars($cliente['telefone'] ?? ''); ?>">
                                <?php echo htmlspecialchars($cliente['nome_completo']); ?>
                                <?php echo !empty($cliente['telefone']) ? ' (' . htmlspecialchars($cliente['telefone']) . ')' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="app-pet">Pet do Cliente *</label>
                    <select id="app-pet" disabled required style="width:100%;">
                        <option value="">Selecione um cliente primeiro</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="app-service">Serviço Solicitado *</label>
                    <select id="app-service" required style="width:100%;">
                        <option value="">Selecione o serviço...</option>
                        <?php foreach ($servicosPadrao as $srvNome): ?>
                            <option value="<?php echo htmlspecialchars($srvNome); ?>"><?php echo htmlspecialchars($srvNome); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group-row">
                    <div class="form-group">
                        <label for="app-date">Data do Atendimento *</label>
                        <input type="date" id="app-date" required style="width:100%;">
                    </div>
                    <div class="form-group">
                        <label for="app-time">Horário Inicial *</label>
                        <input type="time" id="app-time" step="900" min="08:00" max="18:00" required style="width:100%;">
                    </div>
                </div>

                <div class="form-group">
                    <label for="app-professional">Profissional / Responsável</label>
                    <select id="app-professional" style="width:100%;">
                        <option value="0">Qualquer um / Não especificado</option>
                        <?php foreach ($profissionais as $prof): ?>
                            <option value="<?php echo $prof['id']; ?>">
                                <?php echo htmlspecialchars($prof['nome'] . ' (' . $prof['cargo'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="app-notes">Observações do Atendimento</label>
                    <textarea id="app-notes" rows="2" placeholder="Ex: Alergia a shampoo perfumado, corte padrão da raça..." style="width:100%;"></textarea>
                </div>
            </form>
        </div>

        <div class="modal-footer" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
            <div id="modal-app-actions-left" style="display:none; gap:6px;">
                <button type="button" class="btn btn-sm btn-success" id="btn-concluir-app" title="Marcar atendimento como Concluído">
                    <i class="fas fa-check-double"></i> Concluir
                </button>
                <button type="button" class="btn btn-sm btn-danger" id="btn-cancelar-app" title="Cancelar este agendamento">
                    <i class="fas fa-times"></i> Cancelar
                </button>
            </div>
            <div style="display:flex; gap:8px; margin-left:auto; flex-wrap:wrap;">
                <button type="button" class="btn btn-secondary close-modal-btn" id="cancel-app-btn">Fechar</button>
                <button type="button" class="btn btn-sm btn-warning" id="btn-remarcar-app" style="display:none;">
                    <i class="fas fa-calendar-alt"></i> Remarcar
                </button>
                <button type="button" class="btn btn-primary" id="save-app-btn">Salvar Agendamento</button>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/_footer.php'; ?>
