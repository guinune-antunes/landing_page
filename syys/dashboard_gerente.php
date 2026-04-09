<?php
require 'includes/verifica_login.php';

// Proteção de acesso
if ($_SESSION['usuario_cargo'] === 'super_admin') {
    header("Location: dashboard.php"); exit;
}

$pageTitle = 'Dashboard da Loja';
$paginaAtiva = 'dashboard'; 
include 'includes/_head.php';
require 'conexao.php';

$id_loja = $_SESSION['instituicao_id'];

// --- 1. CARDS (MANTIDO) ---
// Faturamento Mês
$sqlFat = "SELECT SUM(total_venda) FROM vendas WHERE instituicao_id = ? AND MONTH(data_venda) = MONTH(CURRENT_DATE())";
$stmtFat = $pdo->prepare($sqlFat);
$stmtFat->execute([$id_loja]);
$faturamento = $stmtFat->fetchColumn() ?: 0;

// Agendamentos Hoje
$sqlAg = "SELECT COUNT(*) FROM agendamentos WHERE instituicao_id = ? AND DATE(data_hora_inicio) = CURRENT_DATE() AND status != 'Cancelado'";
$stmtAg = $pdo->prepare($sqlAg);
$stmtAg->execute([$id_loja]);
$agendamentosHoje = $stmtAg->fetchColumn();

// Total Clientes
$sqlCli = "SELECT COUNT(*) FROM clientes WHERE instituicao_id = ?";
$stmtCli = $pdo->prepare($sqlCli);
$stmtCli->execute([$id_loja]);
$totalClientes = $stmtCli->fetchColumn();


// --- 2. DADOS PARA O GRÁFICO DE LINHA (Últimos 7 dias) ---
$labelsSemana = [];
$dadosSemana = [];

for ($i = 6; $i >= 0; $i--) {
    $data = date('Y-m-d', strtotime("-$i days"));
    $labelsSemana[] = date('d/m', strtotime($data)); // Ex: 18/11
    
    $sql = "SELECT COUNT(*) FROM agendamentos WHERE instituicao_id = ? AND DATE(data_hora_inicio) = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id_loja, $data]);
    $dadosSemana[] = $stmt->fetchColumn();
}

// --- 3. DADOS PARA O GRÁFICO DE BARRAS (Hoje por Hora/Serviço) ---
// Vamos pegar os agendamentos de hoje para montar o gráfico colorido
$sqlHoje = "SELECT HOUR(data_hora_inicio) as hora, servico, COUNT(*) as qtd 
            FROM agendamentos 
            WHERE instituicao_id = ? AND DATE(data_hora_inicio) = CURRENT_DATE()
            GROUP BY hora, servico ORDER BY hora ASC";
$stmtHoje = $pdo->prepare($sqlHoje);
$stmtHoje->execute([$id_loja]);
$resultHoje = $stmtHoje->fetchAll(PDO::FETCH_ASSOC);

// Prepara estrutura de horas (08h as 18h)
$horasDia = [];
for($h=8; $h<=18; $h++) $horasDia[] = str_pad($h, 2, '0', STR_PAD_LEFT) . ':00';

// Inicializa arrays por tipo de serviço (para empilhar no gráfico)
$servicosMap = [
    'Banho' => array_fill(0, 11, 0),
    'Banho e Tosa' => array_fill(0, 11, 0),
    'Tosa Higiênica' => array_fill(0, 11, 0),
    'Consulta Veterinária' => array_fill(0, 11, 0),
    'Vacina' => array_fill(0, 11, 0)
];

// Preenche os dados
foreach ($resultHoje as $row) {
    $horaIndex = $row['hora'] - 8; // 08:00 é o índice 0
    if ($horaIndex >= 0 && $horaIndex <= 10 && isset($servicosMap[$row['servico']])) {
        $servicosMap[$row['servico']][$horaIndex] = $row['qtd'];
    }
}
?>

<?php include 'includes/_sidebar.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    /* Estilo do Switch (Chave) */
    .chart-toggle {
        display: flex;
        background: #e0e0e0;
        border-radius: 20px;
        padding: 3px;
        width: fit-content;
        margin-left: auto; /* Alinha a direita */
    }
    .toggle-btn {
        padding: 5px 15px;
        border-radius: 15px;
        border: none;
        background: transparent;
        cursor: pointer;
        font-size: 0.85rem;
        font-weight: 600;
        color: #666;
        transition: all 0.3s ease;
    }
    .toggle-btn.active {
        background: #fff;
        color: var(--color-primary);
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }
</style>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1 class="main-title">Visão Geral - <?php echo date('d/m/Y'); ?></h1>
            </div>

            <div class="kpi-widgets">
                <a href="relatorio_financeiro.php" style="text-decoration: none; color: inherit;">
                    <div class="widget-card kpi-card" style="cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-5px)'" onmouseout="this.style.transform='translateY(0)'">
                        <div class="card-icon green"><i class="fas fa-dollar-sign"></i></div>
                        <div class="card-content">
                            <span class="card-value">R$ <?php echo number_format($faturamento, 2, ',', '.'); ?></span>
                            <span class="card-label">Faturamento Mês</span>
                        </div>
                    </div>
                </a>
                <a href="agenda.php" style="text-decoration: none; color: inherit;">
                    <div class="widget-card kpi-card" style="cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-5px)'" onmouseout="this.style.transform='translateY(0)'">
                        <div class="card-icon blue"><i class="fas fa-calendar-check"></i></div>
                        <div class="card-content">
                            <span class="card-value"><?php echo $agendamentosHoje; ?></span>
                            <span class="card-label">Agendamentos Hoje</span>
                        </div>
                    </div>
                </a>
                <a href="clientes.php" style="text-decoration: none; color: inherit;">
                    <div class="widget-card kpi-card" style="cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-5px)'" onmouseout="this.style.transform='translateY(0)'">
                        <div class="card-icon purple"><i class="fas fa-users"></i></div>
                        <div class="card-content">
                            <span class="card-value"><?php echo $totalClientes; ?></span>
                            <span class="card-label">Base de Clientes</span>
                        </div>
                    </div>
                </a>
            </div>

            <div class="widget-card" style="margin-top: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <h3 class="card-title" id="chart-title" style="margin:0;">Fluxo de Agendamentos</h3>
                        
                        <div id="week-nav" style="display: flex; align-items: center; gap: 5px; background: #f0f0f0; padding: 5px 10px; border-radius: 20px;">
                            <button class="btn-icon" onclick="mudarSemana(-1)" style="border:none; padding: 2px 8px; cursor: pointer;"><i class="fas fa-chevron-left"></i></button>
                            <span id="periodo-texto" style="font-size: 0.85rem; font-weight: 600; color: #555;">Carregando...</span>
                            <button class="btn-icon" onclick="mudarSemana(1)" style="border:none; padding: 2px 8px; cursor: pointer;"><i class="fas fa-chevron-right"></i></button>
                        </div>
                    </div>
                    
                    <div class="chart-toggle">
                        <button class="toggle-btn active" id="btn-semana" onclick="setChartMode('semana')">Histórico</button>
                        <button class="toggle-btn" id="btn-dia" onclick="setChartMode('dia')">Hoje (Detalhado)</button>
                    </div>
                </div>
                
                <div style="height: 350px; width: 100%;">
                    <canvas id="timelineChart"></canvas>
                </div>
            </div>
                
                <div style="height: 350px; width: 100%;">
                    <canvas id="timelineChart"></canvas>
                </div>
            </div>

        </main>
    </div>
</div>

<script>
    // --- DADOS ESTÁTICOS (HOJE) ---
    // Mantemos o gráfico de "Hoje" carregado no PHP inicial para ser rápido
    const labelsDia = <?php echo json_encode($horasDia); ?>;
    const dadosBanho = <?php echo json_encode($servicosMap['Banho']); ?>;
    const dadosBanhoTosa = <?php echo json_encode($servicosMap['Banho e Tosa']); ?>;
    const dadosTosa = <?php echo json_encode($servicosMap['Tosa Higiênica']); ?>;
    const dadosVet = <?php echo json_encode($servicosMap['Consulta Veterinária']); ?>;
    const dadosVacina = <?php echo json_encode($servicosMap['Vacina']); ?>;

    const colors = {
        primary: '#1abc9c', banho: '#3498db', banhoTosa: '#28a745', 
        tosa: '#17a2b8', vet: '#dc3545', vacina: '#ffc107'
    };

    const ctx = document.getElementById('timelineChart').getContext('2d');
    let currentChart = null;
    let semanaOffset = 0; // 0 = Semana Atual

    // --- FUNÇÃO 1: Carregar Gráfico da Semana (Com Navegação) ---
    function renderWeekChart() {
        // Mostra os botões de navegação
        document.getElementById('week-nav').style.display = 'flex';
        document.getElementById('chart-title').innerText = 'Fluxo de Agendamentos';
        document.getElementById('periodo-texto').innerText = 'Carregando...';

        // Chama a API criada
        fetch(`api_chart_semana.php?offset=${semanaOffset}`)
            .then(res => res.json())
            .then(data => {
                document.getElementById('periodo-texto').innerText = data.periodo;

                if(currentChart) currentChart.destroy();

                currentChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: data.labels, // Labels vindas do PHP (Ex: 18/11, 19/11)
                        datasets: [{
                            label: 'Agendamentos',
                            data: data.data, // Dados vindos do PHP
                            borderColor: colors.primary,
                            backgroundColor: 'rgba(26, 188, 156, 0.1)',
                            tension: 0.4,
                            fill: true,
                            pointRadius: 5,
                            pointHoverRadius: 8
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: { duration: 500 }, // Animação suave na troca
                        plugins: { legend: { display: false } },
                        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
                    }
                });
            });
    }

    // --- FUNÇÃO 2: Carregar Gráfico de Hoje (Detalhado) ---
    function renderDayChart() {
        // Esconde navegação de semana (não faz sentido aqui)
        document.getElementById('week-nav').style.display = 'none';
        document.getElementById('chart-title').innerText = 'Cronograma de Hoje';

        if(currentChart) currentChart.destroy();

        currentChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labelsDia,
                datasets: [
                    { label: 'Banho', data: dadosBanho, backgroundColor: colors.banho },
                    { label: 'Banho e Tosa', data: dadosBanhoTosa, backgroundColor: colors.banhoTosa },
                    { label: 'Tosa Higiênica', data: dadosTosa, backgroundColor: colors.tosa },
                    { label: 'Veterinário', data: dadosVet, backgroundColor: colors.vet },
                    { label: 'Vacina', data: dadosVacina, backgroundColor: colors.vacina }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { tooltip: { mode: 'index', intersect: false } },
                scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { stepSize: 1 } } }
            }
        });
    }

    // --- CONTROLES ---
    function mudarSemana(direcao) {
        semanaOffset += direcao;
        renderWeekChart();
    }

    function setChartMode(mode) {
        const btnSemana = document.getElementById('btn-semana');
        const btnDia = document.getElementById('btn-dia');

        if (mode === 'semana') {
            btnSemana.classList.add('active');
            btnDia.classList.remove('active');
            renderWeekChart();
        } else {
            btnSemana.classList.remove('active');
            btnDia.classList.add('active');
            renderDayChart();
        }
    }

    // Inicia carregando a semana atual
    renderWeekChart();
</script>
<?php include 'includes/_footer.php'; ?>