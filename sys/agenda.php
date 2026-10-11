<?php
$pageTitle = 'Agenda';
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
    
    // Se a permissão de agenda não existir ou for falsa, bloqueia o acesso
    if (empty($permissoes['agenda'])) {
        header("Location: dashboard.php");
        exit;
    }
}

// --- 2. LÓGICA DE NAVEGAÇÃO DE DATAS ---
$dataBase = !empty($_GET['data_base']) ? $_GET['data_base'] : date('Y-m-d'); 
$timestampBase = strtotime($dataBase);
$diaDaSemanaBase = date('N', $timestampBase); 

$inicioSemana = date('Y-m-d 00:00:00', strtotime('-' . ($diaDaSemanaBase - 1) . ' days', $timestampBase));
$fimSemana = date('Y-m-d 23:59:59', strtotime('+' . (7 - $diaDaSemanaBase) . ' days', $timestampBase));

$linkSemanaAnterior = date('Y-m-d', strtotime('-7 days', $timestampBase));
$linkSemanaSeguinte = date('Y-m-d', strtotime('+7 days', $timestampBase));
$displayRange = date('d M', strtotime($inicioSemana)) . ' - ' . date('d M, Y', strtotime($fimSemana));

// --- 3. BUSCAS NO BANCO DE DADOS (FILTRADAS PELA LOJA) ---

// Busca Clientes (inclui clientes da rede se compartilhado)
require_once __DIR__ . '/includes/unidades.php';
$ids_clientes = obterInstituicoesClientes($pdo, (int)$id_loja);
$in_cli = implode(',', array_fill(0, count($ids_clientes), '?'));
$stmt_clientes = $pdo->prepare("SELECT id, nome_completo FROM clientes WHERE instituicao_id IN ($in_cli) ORDER BY nome_completo");
$stmt_clientes->execute($ids_clientes);
$clientes = $stmt_clientes->fetchAll();

// Busca Profissionais
$stmt_pro = $pdo->prepare("SELECT id, nome, cargo FROM usuarios WHERE instituicao_id = ? AND ativo = 1 AND cargo != 'Admin' ORDER BY nome");
$stmt_pro->execute([$id_loja]);
$profissionais = $stmt_pro->fetchAll();

// Busca Agendamentos Blindada (Garante que vai achar o ID do Cliente através do Pet)
$sql_ag = "SELECT a.*, 
                  p.id AS real_pet_id, p.nome AS pet_nome, 
                  c.id AS real_cliente_id, c.nome_completo AS cliente_nome 
           FROM agendamentos a
           JOIN pets p ON a.pet_id = p.id
           JOIN clientes c ON p.cliente_id = c.id
           WHERE a.instituicao_id = ? 
           AND a.status != 'Cancelado'
           AND a.data_hora_inicio BETWEEN ? AND ?
           ORDER BY a.data_hora_inicio";

$stmt_ag = $pdo->prepare($sql_ag);
$stmt_ag->execute([$id_loja, $inicioSemana, $fimSemana]); 
$agendamentos = $stmt_ag->fetchAll();

// Prepara as gavetas e distribui os agendamentos nos dias da semana
$agendamentosPorDia = [1 => [], 2 => [], 3 => [], 4 => [], 5 => [], 6 => [], 7 => []];
foreach ($agendamentos as $ag) {
    $diaSemanaAgendamento = date('N', strtotime($ag['data_hora_inicio']));
    $agendamentosPorDia[$diaSemanaAgendamento][] = $ag;
}

// Funções de Layout do Calendário
function calcularPosicao($datetime) {
    $hora = (int)date('H', strtotime($datetime));
    $minuto = (int)date('i', strtotime($datetime));
    $offsetInicio = 8; 
    $alturaHora = 60; 
    return max(0, (($hora - $offsetInicio) * $alturaHora) + $minuto);
}

function calcularAltura($inicio, $fim) {
    return max(30, (strtotime($fim) - strtotime($inicio)) / 60); 
}

function getServiceClass($agendamento) {
    if ($agendamento['status'] == 'Remarcado') return 'status-remarcado';
    if ($agendamento['status'] == 'Concluído') return 'status-concluido';
    switch ($agendamento['servico']) {
        case 'Banho': return 'service-banho';
        case 'Banho e Tosa': return 'service-banho-e-tosa';
        case 'Tosa Higiênica': return 'service-tosa-higiênica';
        case 'Consulta Veterinária': return 'service-consulta-veterinária';
        case 'Vacina': return 'service-vacina';
        default: return 'service-default';
    }
}

include 'includes/_head.php';
include 'includes/_sidebar.php'; 
?>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1 class="main-title">Agenda Semanal</h1>
                <div class="calendar-controls">
                    <div class="date-nav">
                        <a href="agenda.php?data_base=<?php echo $linkSemanaAnterior; ?>" class="btn btn-icon" title="Semana Anterior">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                        <a href="agenda.php" class="btn btn-secondary">Hoje</a>
                        <a href="agenda.php?data_base=<?php echo $linkSemanaSeguinte; ?>" class="btn btn-icon" title="Próxima Semana">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                    <span class="current-date-range"><?php echo $displayRange; ?></span>
                    
                    <button class="btn btn-primary" id="add-appointment-btn"><i class="fas fa-plus"></i> Novo Agendamento</button>
                </div>
            </div>

            <div class="calendar-grid-container">
                <div class="time-column">
                    <?php for($h=8; $h<=18; $h++): ?>
                        <div class="time-slot"><?php echo str_pad($h, 2, '0', STR_PAD_LEFT); ?>:00</div>
                    <?php endfor; ?>
                </div>
                <div class="calendar-grid">
                    <?php for ($i = 0; $i < 7; $i++): 
                        $dataDoDiaTimestamp = strtotime($inicioSemana . ' +' . $i . ' days');
                        $dataDoDia = date('Y-m-d', $dataDoDiaTimestamp);
                        $dias = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
                        $diaFormatado = $dias[date('w', $dataDoDiaTimestamp)] . ' ' . date('d', $dataDoDiaTimestamp);
                        $isHoje = (date('Y-m-d') == $dataDoDia) ? 'current' : '';
                        $diaDaSemana = date('N', $dataDoDiaTimestamp); 
                    ?>
                    <div class="day-column">
                        <div class="day-header <?php echo $isHoje; ?>"><?php echo $diaFormatado; ?></div>
                        <div class="appointments">
                            
                            <?php foreach ($agendamentosPorDia[$diaDaSemana] as $ag): 
                                $top = calcularPosicao($ag['data_hora_inicio']);
                                $height = calcularAltura($ag['data_hora_inicio'], $ag['data_hora_fim']);
                                $classeServico = getServiceClass($ag); 
                                
                                // Formatação de Data e Hora
                                $dataInicio = $ag['data_hora_inicio'];
                                $dataApp = $dataInicio ? date('Y-m-d', strtotime($dataInicio)) : '';
                                $horaApp = $dataInicio ? date('H:i', strtotime($dataInicio)) : '';
                                $profId = !empty($ag['profissional_id']) ? $ag['profissional_id'] : (!empty($ag['profissional']) && is_numeric($ag['profissional']) ? $ag['profissional'] : '0');
                            ?>
                                <button type="button" 
                                    class="appointment-card <?php echo $classeServico; ?>" 
                                    style="top: <?php echo $top; ?>px; height: <?php echo $height; ?>px;"
                                    title="<?php echo htmlspecialchars($ag['observacoes'] ?? ''); ?>"
                                    
                                    data-id="<?php echo $ag['id']; ?>"
                                    data-cliente-id="<?php echo $ag['real_cliente_id']; ?>"
                                    data-pet-id="<?php echo $ag['real_pet_id']; ?>"
                                    data-servico="<?php echo htmlspecialchars($ag['servico'] ?? ''); ?>"
                                    data-date="<?php echo $dataApp; ?>"
                                    data-time="<?php echo $horaApp; ?>"
                                    data-profissional-id="<?php echo $profId; ?>"
                                    data-obs="<?php echo htmlspecialchars($ag['observacoes'] ?? ''); ?>"
                                    data-status="<?php echo htmlspecialchars($ag['status']); ?>"
                                >
                                    <p class="pet-name"><i class="fas fa-paw"></i> <?php echo htmlspecialchars($ag['pet_nome']); ?></p>
                                    <p class="service-name"><?php echo htmlspecialchars($ag['servico']); ?></p>
                                    <small>Tutor: <?php echo htmlspecialchars($ag['cliente_nome']); ?></small>
                                    <?php if (!empty($ag['profissional']) && !is_numeric($ag['profissional'])): ?>
                                        <small style="opacity: 0.85;"><i class="fas fa-user-md"></i> <?php echo htmlspecialchars($ag['profissional']); ?></small>
                                    <?php endif; ?>
                                </button>
                            <?php endforeach; ?>

                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<div class="modal-overlay" id="appointment-modal" style="display: none;">
    <div class="modal-content" style="max-width: 520px; width: 100%;">
        <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center;">
            <h2 id="modal-app-title">Novo Agendamento</h2>
            <button type="button" class="close-modal-btn" style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
        </div>
        <div class="modal-body">
            <div id="app-status-container" style="display:none; margin-bottom: 12px; padding: 6px 10px; background: #f8f9fa; border-radius: 6px; border: 1px solid #e9ecef;">
                <span style="font-size: 0.85rem; font-weight: 600; color: #555;">Status atual:</span>
                <span id="app-status-pill" style="display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 0.8rem; font-weight: 600; margin-left: 6px;"></span>
            </div>

            <form id="appointment-form"> 
                <input type="hidden" id="app-id" value="">

                <div class="form-group">
                    <label for="app-client">Cliente *</label>
                    <select id="app-client" required style="width:100%;">
                        <option value="">Selecione um cliente...</option>
                        <?php foreach ($clientes as $cliente): ?>
                            <option value="<?php echo $cliente['id']; ?>">
                                <?php echo htmlspecialchars($cliente['nome_completo']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="app-pet">Pet *</label>
                    <select id="app-pet" disabled required style="width:100%;">
                        <option value="">Selecione um cliente primeiro</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="app-service">Serviço *</label>
                    <select id="app-service" required style="width:100%;">
                        <option value="">Selecione um serviço...</option>
                        <option value="Banho">Banho (1h)</option>
                        <option value="Banho e Tosa">Banho e Tosa (2h)</option>
                        <option value="Tosa Higiênica">Tosa Higiênica (1h)</option>
                        <option value="Consulta Veterinária">Consulta Veterinária (1h)</option>
                        <option value="Vacina">Vacina (30min)</option>
                    </select>
                </div>
                <div class="form-group-row" style="display:flex; gap:12px;">
                    <div class="form-group" style="flex:1;">
                        <label for="app-date">Data *</label>
                        <input type="date" id="app-date" required style="width:100%;">
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label for="app-time">Horário Inicial *</label>
                        <input type="time" id="app-time" step="900" min="08:00" max="18:00" required style="width:100%;">
                    </div>
                </div>
                <div class="form-group">
                    <label for="app-professional">Profissional</label>
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
                    <label for="app-notes">Observações</label>
                    <textarea id="app-notes" rows="3" placeholder="Ex: Pet alérgico a shampoo comum" style="width:100%;"></textarea>
                </div>
            </form>
        </div>
        <div class="modal-footer" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
            <div id="modal-app-actions-left" style="display:none; gap:6px;">
                <button type="button" class="btn btn-sm btn-success" id="btn-concluir-app" title="Marcar atendimento como Concluído">
                    <i class="fas fa-check"></i> Concluir
                </button>
                <button type="button" class="btn btn-sm btn-danger" id="btn-cancelar-app" title="Cancelar este agendamento">
                    <i class="fas fa-times"></i> Cancelar Agendamento
                </button>
            </div>
            <div style="display:flex; gap:8px; margin-left:auto;">
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