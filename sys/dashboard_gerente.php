<?php
require 'includes/verifica_login.php';

// 1. PROTEÇÃO DE ACESSO CORRIGIDA
// Se NÃO for Gerente, expulsa da página e manda para o lugar certo
if ($_SESSION['usuario_cargo'] !== 'Gerente') {
    if ($_SESSION['usuario_cargo'] === 'Admin') {
        header("Location: dashboard_super.php"); 
    } else {
        header("Location: dashboard.php"); 
    }
    exit;
}

$pageTitle = 'Dashboard do Gerente';
$paginaAtiva = 'dashboard'; 
include 'includes/_head.php';
require 'conexao.php';

// --- 1. CARDS (CORRIGIDOS) ---

// Faturamento Mês (Corrigido para 'valor_total' e removido o filtro de instituição)
$sqlFat = "SELECT SUM(valor_total) FROM vendas WHERE MONTH(data_venda) = MONTH(CURRENT_DATE())";
$stmtFat = $pdo->query($sqlFat);
$faturamento = $stmtFat->fetchColumn() ?: 0;

// Agendamentos Hoje (Removido o filtro de instituição)
$sqlAg = "SELECT COUNT(*) FROM agendamentos WHERE DATE(data_hora_inicio) = CURRENT_DATE() AND status != 'Cancelado'";
$stmtAg = $pdo->query($sqlAg);
$agendamentosHoje = $stmtAg->fetchColumn();

// Total Clientes (Removido o filtro de instituição)
$sqlCli = "SELECT COUNT(*) FROM clientes";
$stmtCli = $pdo->query($sqlCli);
$totalClientes = $stmtCli->fetchColumn();


// --- 2. DADOS PARA O GRÁFICO DE LINHA (Últimos 7 dias) ---
$labelsSemana = [];
$dadosSemana = [];

for ($i = 6; $i >= 0; $i--) {
    $data = date('Y-m-d', strtotime("-$i days"));
    $labelsSemana[] = date('d/m', strtotime($data)); 
    
    // Removido o filtro de instituição
    $sql = "SELECT COUNT(*) FROM agendamentos WHERE DATE(data_hora_inicio) = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$data]);
    $dadosSemana[] = $stmt->fetchColumn();
}

// --- 3. DADOS PARA O GRÁFICO DE BARRAS (Hoje por Hora/Serviço) ---
// CORRIGIDO: Feito um JOIN para buscar o 'nome_servico' correto
$sqlHoje = "SELECT HOUR(a.data_hora_inicio) as hora, s.nome_servico as servico, COUNT(*) as qtd 
            FROM agendamentos a 
            JOIN servicos s ON a.servico_id = s.id
            WHERE DATE(a.data_hora_inicio) = CURRENT_DATE()
            GROUP BY hora, s.nome_servico 
            ORDER BY hora ASC";
$stmtHoje = $pdo->query($sqlHoje);
$resultHoje = $stmtHoje->fetchAll(PDO::FETCH_ASSOC);

// Prepara estrutura de horas (08h as 18h)
$horasDia = [];
for($h=8; $h<=18; $h++) $horasDia[] = str_pad($h, 2, '0', STR_PAD_LEFT) . ':00';

// Inicializa arrays baseados nos serviços reais do seu banco
$servicosMap = [
    'Banho e Tosa' => array_fill(0, 11, 0),
    'Consulta Veterinária' => array_fill(0, 11, 0),
    'Vacina V10 (Cães)' => array_fill(0, 11, 0)
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
        margin-left: auto;
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
            
            <div style="margin-top: 30px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h2 style="font-size: 1.2rem; color: #2c3e50;"><i class="fas fa-bell" style="color: #f39c12;"></i> Central de Alertas</h2>
                    
                    <div>
                        <button id="btn-scroll-left" class="btn-icon" style="background: white; border: 1px solid #ddd; padding: 5px 10px; border-radius: 5px; cursor: pointer; margin-right: 5px;">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <button id="btn-scroll-right" class="btn-icon" style="background: white; border: 1px solid #ddd; padding: 5px 10px; border-radius: 5px; cursor: pointer;">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>

                <div id="alertas-container" style="display: flex; gap: 15px; overflow-x: auto; padding-bottom: 10px; scroll-behavior: smooth; -ms-overflow-style: none; scrollbar-width: none;">
                    
                    <div id="loading-alertas" style="width: 100%; text-align: center; padding: 30px; color: #777;">
                        <i class="fas fa-spinner fa-spin"></i> Analisando incongruências no sistema...
                    </div>

                </div>
            </div>
            
            <style>
                #alertas-container::-webkit-scrollbar { display: none; }
                .alerta-card { 
                    min-width: 280px; 
                    background: white; 
                    border-left: 4px solid #ddd; 
                    border-radius: 8px; 
                    padding: 15px; 
                    box-shadow: 0 2px 5px rgba(0,0,0,0.05); 
                    cursor: pointer;
                    transition: transform 0.2s;
                }
                .alerta-card:hover { transform: translateY(-3px); box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
                .alerta-danger { border-left-color: #e74c3c; } /* Vermelho */
                .alerta-warning { border-left-color: #f39c12; } /* Amarelo */
                .alerta-info { border-left-color: #3498db; } /* Azul */
                .alerta-success { border-left-color: #27ae60; } /* Verde */
            </style>


            <div class="modal-overlay" id="alerta-modal" style="display: none;">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 id="modal-alerta-titulo" style="display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-exclamation-circle"></i> Detalhes do Alerta
                        </h2>
                        <button class="close-modal-btn" style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
                    </div>
                    <div class="modal-body">
                        <p id="modal-alerta-descricao" style="color: #555; margin-bottom: 20px; line-height: 1.5;"></p>
                        
                        <div style="background: #f9f9f9; padding: 15px; border-radius: 5px; border: 1px solid #eee;">
                            <h4 style="margin-bottom: 10px; color: #333; font-size: 0.9rem; text-transform: uppercase;">Itens Afetados / Observações:</h4>
                            <ul id="modal-alerta-lista" style="list-style-type: none; padding: 0; margin: 0; max-height: 200px; overflow-y: auto;">
                                </ul>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="#" id="modal-alerta-acao" class="btn btn-primary" style="text-decoration: none;">Ir para a tela de resolução</a>
                        <button class="btn btn-secondary close-modal-btn">Fechar</button>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
    // --- DADOS ESTÁTICOS (HOJE) ---
    const labelsDia = <?php echo json_encode($horasDia); ?>;
    const dadosBanhoTosa = <?php echo json_encode($servicosMap['Banho e Tosa']); ?>;
    const dadosVet = <?php echo json_encode($servicosMap['Consulta Veterinária']); ?>;
    const dadosVacina = <?php echo json_encode($servicosMap['Vacina V10 (Cães)']); ?>;

    const colors = {
        primary: '#1abc9c', banhoTosa: '#28a745', 
        vet: '#dc3545', vacina: '#ffc107'
    };

    const ctx = document.getElementById('timelineChart').getContext('2d');
    let currentChart = null;
    let semanaOffset = 0;

    function renderWeekChart() {
        document.getElementById('week-nav').style.display = 'flex';
        document.getElementById('chart-title').innerText = 'Fluxo de Agendamentos';
        
        fetch(`api_chart_semana.php?offset=${semanaOffset}`)
            .then(res => res.json())
            .then(data => {
                document.getElementById('periodo-texto').innerText = data.periodo;

                if(currentChart) currentChart.destroy();

                currentChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: 'Agendamentos',
                            data: data.data,
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
                        animation: { duration: 500 },
                        plugins: { legend: { display: false } },
                        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
                    }
                });
            });
    }

    function renderDayChart() {
        document.getElementById('week-nav').style.display = 'none';
        document.getElementById('chart-title').innerText = 'Cronograma de Hoje';

        if(currentChart) currentChart.destroy();

        currentChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labelsDia,
                datasets: [
                    { label: 'Banho e Tosa', data: dadosBanhoTosa, backgroundColor: colors.banhoTosa },
                    { label: 'Consulta Veterinária', data: dadosVet, backgroundColor: colors.vet },
                    { label: 'Vacina V10', data: dadosVacina, backgroundColor: colors.vacina }
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

    renderWeekChart();

    document.addEventListener('DOMContentLoaded', function() {
    
        // --- 1. Lógica do Carrossel Horizontal ---
        const container = document.getElementById('alertas-container');
        const btnLeft = document.getElementById('btn-scroll-left');
        const btnRight = document.getElementById('btn-scroll-right');

        btnLeft.onclick = () => container.scrollBy({ left: -300, behavior: 'smooth' });
        btnRight.onclick = () => container.scrollBy({ left: 300, behavior: 'smooth' });

        // --- 2. Lógica do Modal Universal ---
        const modalAlerta = document.getElementById('alerta-modal');
        document.querySelectorAll('.close-modal-btn').forEach(btn => btn.onclick = () => modalAlerta.style.display = 'none');

        // Função para abrir o modal preenchido com os dados do alerta (Atualizada para suportar delegação)
        window.abrirModalAlerta = function(titulo, descricao, cor, icone, linkAcao, textoBotao, listaItens, isDelegavel, tipoTarefa) {
            document.getElementById('modal-alerta-titulo').innerHTML = `<i class="fas ${icone}" style="color: ${cor};"></i> ${titulo}`;
            document.getElementById('modal-alerta-descricao').innerText = descricao;
            
            const btnAcao = document.getElementById('modal-alerta-acao');
            btnAcao.innerText = textoBotao;
            btnAcao.style.background = cor;
            btnAcao.style.borderColor = cor;

            // Lógica de Inteligência: Se for delegável, cria Ordem de Serviço em vez de redirecionar
            if (isDelegavel) {
                btnAcao.href = "#";
                btnAcao.onclick = function(e) {
                    e.preventDefault();
                    btnAcao.innerText = "Enviando Ordem de Serviço...";
                    btnAcao.style.opacity = "0.7";
                    
                    fetch('api_delegar_estoque.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({
                            tipo: tipoTarefa,
                            titulo: `Revisão Necessária: ${titulo}`,
                            descricao: descricao + "\n\nPor favor, verifiquem os itens listados e tomem as medidas necessárias."
                        })
                    })
                    .then(res => res.json())
                    .then(res => {
                        alert(res.message);
                        if(res.success) modalAlerta.style.display = 'none';
                        btnAcao.innerText = textoBotao;
                        btnAcao.style.opacity = "1";
                    })
                    .catch(err => {
                        alert("Erro de conexão ao delegar tarefa.");
                        btnAcao.innerText = textoBotao;
                        btnAcao.style.opacity = "1";
                    });
                };
            } else {
                // Comportamento normal para botões não-delegáveis
                btnAcao.onclick = null;
                btnAcao.href = linkAcao;
                if (textoBotao === 'Ciente') {
                    btnAcao.onclick = function(e) { e.preventDefault(); modalAlerta.style.display = 'none'; }
                }
            }

            const ulLista = document.getElementById('modal-alerta-lista');
            ulLista.innerHTML = '';
            
            if (listaItens && listaItens.length > 0) {
                listaItens.forEach(item => {
                    ulLista.innerHTML += `<li style="padding: 8px 0; border-bottom: 1px dashed #ccc; font-size: 0.9rem;">${item}</li>`;
                });
            } else {
                ulLista.innerHTML = '<li style="color: #777; font-size: 0.9rem;">Nenhum detalhe específico listado.</li>';
            }

            modalAlerta.style.display = 'flex';
        };

        // --- 3. Busca os Alertas no Backend ---
        fetch('api_buscar_alertas.php')
            .then(res => res.json())
            .then(alertas => {
                container.innerHTML = ''; // Limpa o "Loading..."
                
                if(alertas.length === 0) {
                    container.innerHTML = `
                        <div style="width: 100%; text-align: center; padding: 20px; background: #e0f8e9; color: #27ae60; border-radius: 8px;">
                            <i class="fas fa-check-circle" style="font-size: 2rem; margin-bottom: 10px;"></i><br>
                            Sistema rodando perfeitamente. Nenhuma incongruência encontrada!
                        </div>`;
                    return;
                }

                // Constrói os cards na tela
                alertas.forEach(alerta => {
                    const dadosLista = encodeURIComponent(JSON.stringify(alerta.itens));
                    
                    const card = `
                        <div class="alerta-card alerta-${alerta.tipo}" 
                             onclick="abrirModalAlerta('${alerta.titulo}', '${alerta.descricao}', '${alerta.corHex}', '${alerta.icone}', '${alerta.link}', '${alerta.textoBotao}', JSON.parse(decodeURIComponent('${dadosLista}')), ${alerta.is_delegavel}, '${alerta.tipo_tarefa || ''}')">
                            <div style="display: flex; align-items: center; margin-bottom: 8px;">
                                <div style="background: ${alerta.corFundo}; color: ${alerta.corHex}; width: 35px; height: 35px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 10px;">
                                    <i class="fas ${alerta.icone}"></i>
                                </div>
                                <h3 style="font-size: 1rem; color: #333; margin: 0;">${alerta.titulo}</h3>
                            </div>
                            <p style="color: #777; font-size: 0.85rem; margin: 0;">${alerta.resumo}</p>
                        </div>
                    `;
                    container.innerHTML += card;
                });
            })
            .catch(err => {
                container.innerHTML = '<div style="color: red; padding: 20px;">Erro ao carregar os alertas do sistema.</div>';
            });
    });
</script>

<?php include 'includes/_footer.php'; ?>