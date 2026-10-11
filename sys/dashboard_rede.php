<?php
// ══════════════════════════════════════════════════════════════════
// dashboard_rede.php — Visão consolidada de todas as unidades da rede
// Acesso: Gerente com 2+ unidades permitidas.
// ══════════════════════════════════════════════════════════════════
require_once 'includes/verifica_login.php';
verificarPermissao(['Gerente']);
require_once 'conexao.php';
require_once 'includes/unidades.php';

// Recarrega do banco (fonte da verdade) as unidades que o gerente pode ver
$unidades = carregarUnidadesUsuario($pdo, (int)$_SESSION['usuario_id']);

if (count($unidades) < 2) {
    header('Location: dashboard_gerente.php');
    exit;
}

$ids = array_keys($unidades);
$in  = implode(',', array_fill(0, count($ids), '?'));

$inicioMes = date('Y-m-01 00:00:00');
$fimMes    = date('Y-m-01 00:00:00', strtotime('first day of next month'));

// Métricas por unidade (3 consultas agrupadas, sem N+1)
$fat = $pdo->prepare("SELECT instituicao_id, COALESCE(SUM(valor_total),0) AS total, COUNT(*) AS qtd
                      FROM vendas
                      WHERE instituicao_id IN ($in) AND data_venda >= ? AND data_venda < ?
                      GROUP BY instituicao_id");
$fat->execute([...$ids, $inicioMes, $fimMes]);
$fatPorUnidade = array_column($fat->fetchAll(), null, 'instituicao_id');

$ag = $pdo->prepare("SELECT instituicao_id, COUNT(*) AS qtd
                     FROM agendamentos
                     WHERE instituicao_id IN ($in) AND DATE(data_hora_inicio) = CURRENT_DATE() AND status != 'Cancelado'
                     GROUP BY instituicao_id");
$ag->execute($ids);
$agPorUnidade = array_column($ag->fetchAll(), 'qtd', 'instituicao_id');

$cl = $pdo->prepare("SELECT instituicao_id, COUNT(*) AS qtd FROM clientes WHERE instituicao_id IN ($in) GROUP BY instituicao_id");
$cl->execute($ids);
$clPorUnidade = array_column($cl->fetchAll(), 'qtd', 'instituicao_id');

$linhas = [];
$totalFat = 0; $totalAg = 0; $totalCli = 0; $totalVendas = 0; $maiorFat = 0;
foreach ($unidades as $id => $u) {
    $f = (float)($fatPorUnidade[$id]['total'] ?? 0);
    $qv = (int)($fatPorUnidade[$id]['qtd'] ?? 0);
    $linhas[$id] = [
        'rotulo'      => $u['rotulo'],
        'faturamento' => $f,
        'vendas'      => $qv,
        'ticket'      => $qv > 0 ? $f / $qv : 0,
        'agendamentos'=> (int)($agPorUnidade[$id] ?? 0),
        'clientes'    => (int)($clPorUnidade[$id] ?? 0),
    ];
    $totalFat += $f; $totalVendas += $qv;
    $totalAg  += $linhas[$id]['agendamentos'];
    $totalCli += $linhas[$id]['clientes'];
    $maiorFat  = max($maiorFat, $f);
}

$unidadeAtiva = (int)$_SESSION['instituicao_id'];
$brl = fn($v) => 'R$ ' . number_format($v, 2, ',', '.');

$pageTitle = 'Visão da Rede';
$paginaAtiva = 'rede';
include 'includes/_head.php';
include 'includes/_sidebar.php';
?>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="main-title">Visão da Rede</h1>
                    <p style="color:#777;">Consolidado de <?php echo count($unidades); ?> unidades — mês de <?php echo date('m/Y'); ?>.</p>
                </div>
            </div>

            <div class="kpi-widgets">
                <div class="widget-card kpi-card">
                    <div class="card-icon green"><i class="fas fa-dollar-sign"></i></div>
                    <div class="card-content">
                        <span class="card-value"><?php echo $brl($totalFat); ?></span>
                        <span class="card-label">Faturamento da Rede (mês)</span>
                    </div>
                </div>
                <div class="widget-card kpi-card">
                    <div class="card-icon blue"><i class="fas fa-calendar-check"></i></div>
                    <div class="card-content">
                        <span class="card-value"><?php echo $totalAg; ?></span>
                        <span class="card-label">Agendamentos Hoje (todas)</span>
                    </div>
                </div>
                <div class="widget-card kpi-card">
                    <div class="card-icon purple"><i class="fas fa-users"></i></div>
                    <div class="card-content">
                        <span class="card-value"><?php echo $totalCli; ?></span>
                        <span class="card-label">Clientes (todas as unidades)</span>
                    </div>
                </div>
            </div>

            <div class="widget-card" style="margin-top:20px;">
                <h3 style="margin-bottom:15px;">Comparativo entre unidades</h3>
                <table class="data-table" style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="text-align:left;border-bottom:2px solid #eee;">
                            <th style="padding:10px;">Unidade</th>
                            <th style="padding:10px;min-width:200px;">Faturamento (mês)</th>
                            <th style="padding:10px;">Vendas</th>
                            <th style="padding:10px;">Ticket médio</th>
                            <th style="padding:10px;">Agend. hoje</th>
                            <th style="padding:10px;">Clientes</th>
                            <th style="padding:10px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($linhas as $id => $l):
                        $pct = $maiorFat > 0 ? round(($l['faturamento'] / $maiorFat) * 100) : 0;
                        $share = $totalFat > 0 ? round(($l['faturamento'] / $totalFat) * 100) : 0;
                    ?>
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:10px;">
                                <strong><?php echo htmlspecialchars($l['rotulo']); ?></strong>
                                <?php if ($id === $unidadeAtiva): ?>
                                    <span style="background:#e0f8e9;color:#28a745;padding:2px 8px;border-radius:12px;font-size:0.7rem;margin-left:6px;">unidade ativa</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px;">
                                <div style="font-weight:600;"><?php echo $brl($l['faturamento']); ?>
                                    <small style="color:#999;font-weight:400;"> · <?php echo $share; ?>% da rede</small></div>
                                <div style="background:#eef2f3;border-radius:6px;height:8px;margin-top:5px;overflow:hidden;">
                                    <div style="width:<?php echo $pct; ?>%;height:100%;background:linear-gradient(90deg,#1abc9c,#16a085);"></div>
                                </div>
                            </td>
                            <td style="padding:10px;"><?php echo $l['vendas']; ?></td>
                            <td style="padding:10px;"><?php echo $brl($l['ticket']); ?></td>
                            <td style="padding:10px;"><?php echo $l['agendamentos']; ?></td>
                            <td style="padding:10px;"><?php echo $l['clientes']; ?></td>
                            <td style="padding:10px;text-align:right;">
                                <?php if ($id !== $unidadeAtiva): ?>
                                    <form method="post" action="trocar_unidade.php" style="margin:0;">
                                        <input type="hidden" name="unidade_id" value="<?php echo (int)$id; ?>">
                                        <button type="submit" class="btn btn-secondary" style="padding:6px 12px;font-size:0.8rem;">
                                            <i class="fas fa-sign-in-alt"></i> Acessar
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="border-top:2px solid #eee;font-weight:700;">
                            <td style="padding:10px;">Total da rede</td>
                            <td style="padding:10px;"><?php echo $brl($totalFat); ?></td>
                            <td style="padding:10px;"><?php echo $totalVendas; ?></td>
                            <td style="padding:10px;"><?php echo $brl($totalVendas > 0 ? $totalFat / $totalVendas : 0); ?></td>
                            <td style="padding:10px;"><?php echo $totalAg; ?></td>
                            <td style="padding:10px;"><?php echo $totalCli; ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </main>
    </div>
</div>

<?php include 'includes/_footer.php'; ?>
