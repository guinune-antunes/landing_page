<?php
require_once 'includes/verifica_login.php';
verificarPermissao(['Gerente']);

$pageTitle = 'Dashboard do Gerente';
$paginaAtiva = 'dashboard'; 
include 'includes/_head.php';
require_once 'conexao.php';

// --- 1. CARDS (CORRIGIDOS) ---

$instituicao_id = $_SESSION['instituicao_id'];

// Faturamento Mês
$sqlFat = "SELECT SUM(valor_total) FROM vendas WHERE instituicao_id = ? AND MONTH(data_venda) = MONTH(CURRENT_DATE())";
$stmtFat = $pdo->prepare($sqlFat);
$stmtFat->execute([$instituicao_id]);
$faturamento = $stmtFat->fetchColumn() ?: 0;

// Agendamentos Hoje
$sqlAg = "SELECT COUNT(*) FROM agendamentos WHERE instituicao_id = ? AND DATE(data_hora_inicio) = CURRENT_DATE() AND status != 'Cancelado'";
$stmtAg = $pdo->prepare($sqlAg);
$stmtAg->execute([$instituicao_id]);
$agendamentosHoje = $stmtAg->fetchColumn();

// Total Clientes
$sqlCli = "SELECT COUNT(*) FROM clientes WHERE instituicao_id = ?";
$stmtCli = $pdo->prepare($sqlCli);
$stmtCli->execute([$instituicao_id]);
$totalClientes = $stmtCli->fetchColumn();


// --- 2. DADOS PARA O GRÁFICO DE LINHA (Últimos 7 dias) ---
$labelsSemana = [];
$dadosSemana = [];

for ($i = 6; $i >= 0; $i--) {
    $data = date('Y-m-d', strtotime("-$i days"));
    $labelsSemana[] = date('d/m', strtotime($data)); 
    
    // Filtro de instituição adicionado
    $sql = "SELECT COUNT(*) FROM agendamentos WHERE instituicao_id = ? AND DATE(data_hora_inicio) = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$instituicao_id, $data]);
    $dadosSemana[] = $stmt->fetchColumn();
}

// --- 3. DADOS PARA O GRÁFICO DE BARRAS (Hoje por Hora/Serviço) ---
// CORRIGIDO: Feito um JOIN para buscar o 'nome_servico' correto
$sqlHoje = "SELECT HOUR(a.data_hora_inicio) as hora, s.nome_servico as servico, COUNT(*) as qtd 
            FROM agendamentos a 
            JOIN servicos s ON a.servico_id = s.id
            WHERE a.instituicao_id = ? AND DATE(a.data_hora_inicio) = CURRENT_DATE()
            GROUP BY hora, s.nome_servico 
            ORDER BY hora ASC";
$stmtHoje = $pdo->prepare($sqlHoje);
$stmtHoje->execute([$instituicao_id]);
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

// Renderiza a View separada
require_once 'views/dashboard_gerente_view.php';
?>