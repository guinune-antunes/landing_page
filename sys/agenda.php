<?php
// Define o título da página e qual item do menu deve ficar ativo
$pageTitle = 'Agenda';
$paginaAtiva = 'agenda';

// Inclui o cabeçalho do HTML
include 'includes/_head.php';
require 'conexao.php'; // Usa $pdo
require 'includes/verifica_login.php'; // Garante login

// --- SEGURANÇA SAAS (CRÍTICO) ---
// Pega o ID da loja logada para filtrar tudo
if (!isset($_SESSION['instituicao_id'])) {
    header("Location: login.php"); exit;
}
$id_loja = $_SESSION['instituicao_id']; 

// --- LÓGICA DE NAVEGAÇÃO DE DATAS ---
if (isset($_GET['data_base']) && !empty($_GET['data_base'])) {
    $dataBase = $_GET['data_base'];
} else {
    $dataBase = date('Y-m-d'); 
}

$timestampBase = strtotime($dataBase);
$diaDaSemanaBase = date('N', $timestampBase); 

$inicioSemana = date('Y-m-d 00:00:00', strtotime('-' . ($diaDaSemanaBase - 1) . ' days', $timestampBase));
$fimSemana = date('Y-m-d 23:59:59', strtotime('+' . (7 - $diaDaSemanaBase) . ' days', $timestampBase));

$linkSemanaAnterior = date('Y-m-d', strtotime('-7 days', $timestampBase));
$linkSemanaSeguinte = date('Y-m-d', strtotime('+7 days', $timestampBase));
$displayRange = date('d M', strtotime($inicioSemana)) . ' - ' . date('d M, Y', strtotime($fimSemana));
// --- FIM DATAS ---


// 1. Pega clientes DA MINHA LOJA (Correção SaaS)
$stmt_clientes = $pdo->prepare("SELECT id, nome_completo FROM clientes WHERE instituicao_id = ? ORDER BY nome_completo");
$stmt_clientes->execute([$id_loja]);
$clientes = $stmt_clientes->fetchAll();

// 2. Pega agendamentos DA MINHA LOJA (Correção SaaS)
$sql_ag = "SELECT a.*, p.nome AS pet_nome, c.nome_completo AS cliente_nome 
           FROM agendamentos a
           JOIN pets p ON a.pet_id = p.id
           JOIN clientes c ON a.cliente_id = c.id
           WHERE a.instituicao_id = ? 
           AND a.data_hora_inicio BETWEEN ? AND ?
           ORDER BY a.data_hora_inicio";

$stmt_ag = $pdo->prepare($sql_ag);
// Passamos o ID da loja primeiro, depois as datas do filtro
$stmt_ag->execute([$id_loja, $inicioSemana, $fimSemana]); 
$agendamentos = $stmt_ag->fetchAll();

// Separa os agendamentos por dia da semana
$agendamentosPorDia = [1 => [], 2 => [], 3 => [], 4 => [], 5 => [], 6 => [], 7 => []];
foreach ($agendamentos as $ag) {
    $diaDaSemana = date('N', strtotime($ag['data_hora_inicio']));
    $agendamentosPorDia[$diaDaSemana][] = $ag;
}

// Funções de Layout
function calcularPosicao($datetime) {
    $hora = (int)date('H', strtotime($datetime));
    $minuto = (int)date('i', strtotime($datetime));
    $offsetInicio = 8; 
    $alturaHora = 60; 
    $posicao = (($hora - $offsetInicio) * $alturaHora) + ($minuto);
    return max(0, $posicao);
}

function calcularAltura($inicio, $fim) {
    $timestampInicio = strtotime($inicio);
    $timestampFim = strtotime($fim);
    $diferencaMinutos = ($timestampFim - $timestampInicio) / 60;
    return $diferencaMinutos; 
}

function getServiceClass($agendamento) {
    if ($agendamento['status'] == 'Remarcado') {
        return 'status-remarcado';
    }
    switch ($agendamento['servico']) {
        case 'Banho': return 'service-banho';
        case 'Banho e Tosa': return 'service-banho-e-tosa';
        case 'Tosa Higiênica': return 'service-tosa-higiênica';
        case 'Consulta Veterinária': return 'service-consulta-veterinária';
        case 'Vacina': return 'service-vacina';
        default: return 'service-default';
    }
}
?>

<?php include 'includes/_sidebar.php'; ?>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1 class="main-title">Agenda</h1>
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
                    
                    <button class="btn btn-primary" id="add-appointment-btn"><i class="fas fa-plus"></i> Adicionar Agendamento</button>
                </div>
            </div>

            <div class="calendar-grid-container">
                <div class="time-column">
                    <div class="time-slot">08:00</div>
                    <div class="time-slot">09:00</div>
                    <div class="time-slot">10:00</div>
                    <div class="time-slot">11:00</div>
                    <div class="time-slot">12:00</div>
                    <div class="time-slot">13:00</div>
                    <div class="time-slot">14:00</div>
                    <div class="time-slot">15:00</div>
                    <div class="time-slot">16:00</div>
                    <div class="time-slot">17:00</div>
                    <div class="time-slot">18:00</div>
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
                                
                                $data_iso = date('Y-m-d', strtotime($ag['data_hora_inicio']));
                                $hora_iso = date('H:i', strtotime($ag['data_hora_inicio']));
                            ?>
                                <button type="button" 
                                    class="appointment-card <?php echo $classeServico; ?>" 
                                    style="top: <?php echo $top; ?>px; height: <?php echo $height; ?>px;"
                                    
                                    data-id="<?php echo $ag['id']; ?>"
                                    data-cliente-id="<?php echo $ag['cliente_id']; ?>"
                                    data-pet-id="<?php echo $ag['pet_id']; ?>"
                                    data-servico="<?php echo htmlspecialchars($ag['servico']); ?>"
                                    data-profissional="<?php echo htmlspecialchars($ag['profissional']); ?>"
                                    data-date="<?php echo $data_iso; ?>"
                                    data-time="<?php echo $hora_iso; ?>"
                                    data-obs="<?php echo htmlspecialchars($ag['observacoes']); ?>"
                                >
                                    <p class="pet-name"><?php echo htmlspecialchars($ag['pet_nome']); ?></p>
                                    <p class="service-name"><?php echo htmlspecialchars($ag['servico']); ?></p>
                                    <small>Tutor: <?php echo htmlspecialchars($ag['cliente_nome']); ?></small>
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
    <div class="modal-content">
        <div class="modal-header">
            <h2>Novo Agendamento</h2>
            <button class="close-modal-btn">&times;</button>
        </div>
        <div class="modal-body">
            <form class="appointment-form" id="appointment-form-content"> 
                <div class="form-group">
                    <label for="app-client">Cliente</label>
                    <select id="app-client" required>
                        <option value="">Selecione um cliente...</option>
                        <?php foreach ($clientes as $cliente): ?>
                            <option value="<?php echo $cliente['id']; ?>">
                                <?php echo htmlspecialchars($cliente['nome_completo']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="app-pet">Pet</label>
                    <select id="app-pet" disabled required>
                        <option value="">Selecione um cliente primeiro</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="app-service">Serviço</label>
                    <select id="app-service" required>
                        <option value="">Selecione um serviço...</option>
                        <option value="Banho">Banho</option>
                        <option value="Banho e Tosa">Banho e Tosa</option>
                        <option value="Tosa Higiênica">Tosa Higiênica</option>
                        <option value="Consulta Veterinária">Consulta Veterinária</option>
                        <option value="Vacina">Vacina</option>
                    </select>
                </div>
                    <div class="form-group-row">
                    <div class="form-group">
                        <label for="app-date">Data</label>
                        <input type="date" id="app-date" required>
                    </div>
                    <div class="form-group">
                        <label for="app-time">Horário</label>
                        <input type="time" id="app-time" step="900" min="08:00" max="18:00" required>
                    </div>
                </div>
                    <div class="form-group">
                    <label for="app-professional">Profissional</label>
                    <select id="app-professional">
                        <option value="Qualquer um">Qualquer um</option>
                        <option value="Maria (Tosadora)">Maria (Tosadora)</option>
                        <option value="Dr. Roberto (Veterinário)">Dr. Roberto (Veterinário)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="app-notes">Observações</label>
                    <textarea id="app-notes" rows="3" placeholder="Ex: Pet alérgico a shampoo comum"></textarea>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" id="cancel-app-btn">Cancelar</button>
            <button class="btn btn-primary" id="save-app-btn">Salvar Agendamento</button>
        </div>
    </div>
</div>

<?php include 'includes/_footer.php'; ?>