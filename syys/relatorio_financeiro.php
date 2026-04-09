<?php
require 'includes/verifica_login.php';
// Apenas Gerente/Admin
if ($_SESSION['usuario_cargo'] !== 'gerente' && $_SESSION['usuario_cargo'] !== 'admin') {
    header("Location: dashboard.php"); exit;
}

$pageTitle = 'Relatório Financeiro';
$paginaAtiva = 'relatorios';
include 'includes/_head.php';
require 'conexao.php';

$id_loja = $_SESSION['instituicao_id'];
$mes_atual = date('m');
$ano_atual = date('Y');

// --- 1. DADOS PARA O GRÁFICO (Dia a Dia) ---
// Buscamos: Data, Faturamento Total e Custo Estimado dos produtos vendidos
$sqlChart = "SELECT 
                DATE(v.data_venda) as dia,
                SUM(v.total_venda) as faturamento,
                SUM(
                    (SELECT SUM(iv.quantidade * p.preco_custo) 
                     FROM itens_venda iv 
                     JOIN produtos p ON iv.produto_id = p.id 
                     WHERE iv.venda_id = v.id)
                ) as custo_estimado
             FROM vendas v
             WHERE v.instituicao_id = ? 
             AND MONTH(v.data_venda) = ? AND YEAR(v.data_venda) = ?
             GROUP BY dia
             ORDER BY dia ASC";

$stmt = $pdo->prepare($sqlChart);
$stmt->execute([$id_loja, $mes_atual, $ano_atual]);
$dadosDiarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepara arrays para o JavaScript
$labels = [];
$dataFat = [];
$dataLucro = [];
$totalFatMes = 0;
$totalLucroMes = 0;

foreach ($dadosDiarios as $d) {
    $diaFormatado = date('d/m', strtotime($d['dia']));
    $fat = (float)$d['faturamento'];
    $custo = (float)$d['custo_estimado'];
    $lucro = $fat - $custo;

    $labels[] = $diaFormatado;
    $dataFat[] = $fat;
    $dataLucro[] = $lucro;

    $totalFatMes += $fat;
    $totalLucroMes += $lucro;
}

$margemLucro = ($totalFatMes > 0) ? ($totalLucroMes / $totalFatMes) * 100 : 0;
?>

<?php include 'includes/_sidebar.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; ?>

        <main class="main-content">
            
            <div class="page-header">
                <h1 class="main-title">Performance Financeira <small style="font-size:0.6em; color:#777;">(<?php echo date('F/Y'); ?>)</small></h1>
                <div class="page-controls">
                    <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Voltar</a>
                </div>
            </div>

            <div class="kpi-widgets">
                <div class="widget-card kpi-card">
                    <div class="card-icon green"><i class="fas fa-coins"></i></div>
                    <div class="card-content">
                        <span class="card-value">R$ <?php echo number_format($totalFatMes, 2, ',', '.'); ?></span>
                        <span class="card-label">Faturamento Bruto</span>
                    </div>
                </div>
                
                <div class="widget-card kpi-card">
                    <div class="card-icon blue"><i class="fas fa-chart-line"></i></div>
                    <div class="card-content">
                        <span class="card-value">R$ <?php echo number_format($totalLucroMes, 2, ',', '.'); ?></span>
                        <span class="card-label">Lucro Estimado</span>
                    </div>
                </div>

                <div class="widget-card kpi-card">
                    <div class="card-icon purple"><i class="fas fa-percentage"></i></div>
                    <div class="card-content">
                        <span class="card-value"><?php echo number_format($margemLucro, 1, ',', '.'); ?>%</span>
                        <span class="card-label">Margem de Lucro</span>
                    </div>
                </div>
            </div>

            <div class="widget-card">
                <h3 class="card-title">Evolução Diária (Faturamento x Lucro)</h3>
                <div style="height: 400px; width: 100%;">
                    <canvas id="financeChart"></canvas>
                </div>
            </div>

            <div class="alert info" style="background: #e3f2fd; color: #0d47a1; padding: 15px; border-radius: 6px; margin-top: 20px; font-size: 0.9rem;">
                <i class="fas fa-info-circle"></i> <strong>Atenção:</strong> O cálculo do lucro depende do "Preço de Custo" cadastrado no estoque. Mantenha o cadastro de produtos atualizado para métricas precisas.
            </div>

        </main>
    </div>
</div>

<script>
// Configuração do Gráfico
const ctx = document.getElementById('financeChart').getContext('2d');
const financeChart = new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($labels); ?>,
        datasets: [
            {
                label: 'Faturamento (R$)',
                data: <?php echo json_encode($dataFat); ?>,
                backgroundColor: 'rgba(46, 204, 113, 0.6)', // Verde
                borderColor: 'rgba(46, 204, 113, 1)',
                borderWidth: 1,
                order: 2
            },
            {
                label: 'Lucro (R$)',
                data: <?php echo json_encode($dataLucro); ?>,
                backgroundColor: 'rgba(52, 152, 219, 0.8)', // Azul
                borderColor: 'rgba(52, 152, 219, 1)',
                borderWidth: 1,
                type: 'line', // Linha passando por cima das barras
                tension: 0.3,
                order: 1
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            tooltip: {
                callbacks: {
                    label: function(context) {
                        let label = context.dataset.label || '';
                        if (label) {
                            label += ': ';
                        }
                        if (context.parsed.y !== null) {
                            label += new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(context.parsed.y);
                        }
                        return label;
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return 'R$ ' + value;
                    }
                }
            }
        }
    }
});
</script>

<?php include 'includes/_footer.php'; ?>