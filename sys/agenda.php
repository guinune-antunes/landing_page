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

// Busca Clientes
$stmt_clientes = $pdo->prepare("SELECT id, nome_completo FROM clientes WHERE instituicao_id = ? ORDER BY nome_completo");
$stmt_clientes->execute([$id_loja]);
$clientes = $stmt_clientes->fetchAll();

// Busca Profissionais (Da tabela de usuários que criamos)
$stmt_pro = $pdo->prepare("SELECT id, nome, cargo FROM usuarios WHERE instituicao_id = ? AND ativo = 1 AND cargo != 'Admin' ORDER BY nome");
$stmt_pro->execute([$id_loja]);
$profissionais = $stmt_pro->fetchAll();

// Busca Agendamentos
$sql_ag = "SELECT a.*, p.nome AS pet_nome, c.nome_completo AS cliente_nome 
           FROM agendamentos a
           JOIN pets p ON a.pet_id = p.id
           JOIN clientes c ON a.cliente_id = c.id
           WHERE a.instituicao_id = ? 
           AND a.data_hora_inicio BETWEEN ? AND ?
           ORDER BY a.data_hora_inicio";

$sql_ag = "SELECT a.*, p.nome AS pet_nome, c.nome_completo AS cliente_nome 
           FROM agendamentos a
           JOIN pets p ON a.pet_id = p.id
           JOIN clientes c ON p.cliente_id = c.id 
           WHERE a.instituicao_id = ? 
           AND a.data_hora_inicio BETWEEN ? AND ?
           ORDER BY a.data_hora_inicio";

$stmt_ag = $pdo->prepare($sql_ag);

$agendamentosPorDia = [1 => [], 2 => [], 3 => [], 4 => [], 5 => [], 6 => [], 7 => []];
$sql_ag = "SELECT a.*, p.nome AS pet_nome, c.nome_completo AS cliente_nome 
           FROM agendamentos a
           JOIN pets p ON a.pet_id = p.id
           JOIN clientes c ON p.cliente_id = c.id 
           WHERE a.instituicao_id = ? 
           AND a.data_hora_inicio BETWEEN ? AND ?
           ORDER BY a.data_hora_inicio";

$stmt_ag = $pdo->prepare($sql_ag);
// AS DUAS LINHAS ABAIXO FALTARAM NA MINHA ÚLTIMA RESPOSTA:
$stmt_ag->execute([$id_loja, $inicioSemana, $fimSemana]); 
$agendamentos = $stmt_ag->fetchAll();

// Funções de Layout do Calendário
function calcularPosicao($datetime) {
    $hora = (int)date('H', strtotime($datetime));
    $minuto = (int)date('i', strtotime($datetime));
    $offsetInicio = 8; 
    $alturaHora = 60; 
    return max(0, (($hora - $offsetInicio) * $alturaHora) + $minuto);
}

function calcularAltura($inicio, $fim) {
    return (strtotime($fim) - strtotime($inicio)) / 60; 
}

function getServiceClass($agendamento) {
    if ($agendamento['status'] == 'Remarcado') return 'status-remarcado';
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
                            ?>
                                <button type="button" 
                                    class="appointment-card <?php echo $classeServico; ?>" 
                                    style="top: <?php echo $top; ?>px; height: <?php echo $height; ?>px;"
                                    title="<?php echo htmlspecialchars($ag['observacoes']); ?>"
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
            <button class="close-modal-btn" style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
        </div>
        <div class="modal-body">
            <form id="appointment-form"> 
                <div class="form-group">
                    <label>Cliente</label>
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
                    <label>Pet</label>
                    <select id="app-pet" disabled required>
                        <option value="">Selecione um cliente primeiro</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Serviço</label>
                    <select id="app-service" required>
                        <option value="">Selecione um serviço...</option>
                        <option value="Banho">Banho (1h)</option>
                        <option value="Banho e Tosa">Banho e Tosa (2h)</option>
                        <option value="Tosa Higiênica">Tosa Higiênica (1h)</option>
                        <option value="Consulta Veterinária">Consulta Veterinária (1h)</option>
                        <option value="Vacina">Vacina (30min)</option>
                    </select>
                </div>
                <div class="form-group-row">
                    <div class="form-group">
                        <label>Data</label>
                        <input type="date" id="app-date" min="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Horário Inicial</label>
                        <input type="time" id="app-time" step="900" min="08:00" max="18:00" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Profissional</label>
                    <select id="app-professional">
                        <option value="0">Qualquer um / Não especificado</option>
                        <?php foreach ($profissionais as $prof): ?>
                            <option value="<?php echo $prof['id']; ?>">
                                <?php echo htmlspecialchars($prof['nome'] . ' (' . $prof['cargo'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Observações</label>
                    <textarea id="app-notes" rows="3" placeholder="Ex: Pet alérgico a shampoo comum"></textarea>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary close-modal-btn">Cancelar</button>
            <button class="btn btn-primary" id="save-app-btn">Salvar Agendamento</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('appointment-modal');
    const btnAdd = document.getElementById('add-appointment-btn');
    const btnSave = document.getElementById('save-app-btn');
    const selectClient = document.getElementById('app-client');
    const selectPet = document.getElementById('app-pet');
    
    // Abrir e fechar modal
    btnAdd.onclick = () => modal.style.display = 'flex';
    document.querySelectorAll('.close-modal-btn').forEach(btn => {
        btn.onclick = () => modal.style.display = 'none';
    });

    // Buscar Pets Dinamicamente quando seleciona o Cliente
    selectClient.addEventListener('change', function() {
        const clienteId = this.value;
        
        selectPet.innerHTML = '<option value="">Carregando pets...</option>';
        selectPet.disabled = true;

        if(!clienteId) {
            selectPet.innerHTML = '<option value="">Selecione um cliente primeiro</option>';
            return;
        }

        // Faremos essa API no próximo passo!
        fetch(`api_buscar_pets.php?cliente_id=${clienteId}`)
            .then(res => res.json())
            .then(data => {
                selectPet.innerHTML = '<option value="">Selecione o pet...</option>';
                data.forEach(pet => {
                    selectPet.innerHTML += `<option value="${pet.id}">${pet.nome}</option>`;
                });
                selectPet.disabled = false;
            })
            .catch(err => {
                selectPet.innerHTML = '<option value="">Erro ao carregar pets</option>';
            });
    });

    // Salvar Agendamento
    btnSave.addEventListener('click', function() {
        const data = {
            cliente_id: selectClient.value,
            pet_id: selectPet.value,
            servico: document.getElementById('app-service').value,
            data: document.getElementById('app-date').value,
            hora: document.getElementById('app-time').value,
            profissional_id: document.getElementById('app-professional').value,
            observacoes: document.getElementById('app-notes').value
        };

        if(!data.cliente_id || !data.pet_id || !data.servico || !data.data || !data.hora) {
            alert("Preencha todos os campos obrigatórios.");
            return;
        }

        btnSave.innerText = "Salvando...";
        btnSave.disabled = true;

        // Faremos essa API de salvar no próximo passo!
        fetch('api_salvar_agendamento.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        })
        .then(res => res.json())
        .then(res => {
            alert(res.message);
            if(res.success) location.reload();
            else {
                btnSave.innerText = "Salvar Agendamento";
                btnSave.disabled = false;
            }
        });
    });
});
</script>

<?php include 'includes/_footer.php'; ?>