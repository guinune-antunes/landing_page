<?php
// ══════════════════════════════════════════════════════════════════
// dashboard_rede.php — Visão consolidada de todas as unidades da rede & Gestores
// Acesso: Gerente com 2+ unidades permitidas.
// ══════════════════════════════════════════════════════════════════
require_once 'includes/verifica_login.php';
verificarPermissao(['Gerente', 'Admin']);
require_once 'conexao.php';
require_once 'includes/unidades.php';

$usuarioIdLogado = (int)$_SESSION['usuario_id'];

// Recarrega do banco (fonte da verdade) as unidades que o gerente pode ver
$unidades = carregarUnidadesUsuario($pdo, $usuarioIdLogado);

if (count($unidades) < 2) {
    header('Location: dashboard_gerente.php');
    exit;
}

// Verifica se o usuário é o Gerente Geral da rede (acesso_rede = 1) ou Admin
$stGeral = $pdo->prepare("SELECT acesso_rede FROM usuarios WHERE id = ?");
$stGeral->execute([$usuarioIdLogado]);
$isGerenteGeral = ((int)$stGeral->fetchColumn() === 1) || (($_SESSION['usuario_cargo'] ?? '') === 'Admin');

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

// Gestor titular e substituto temporário de cada unidade
$stGestores = $pdo->prepare("SELECT i.id, 
                                    u.id AS gestor_id, u.nome AS gestor_nome, u.email AS gestor_email, u.cargo AS gestor_cargo,
                                    s.id AS substituto_id, s.nome AS substituto_nome, s.cargo AS substituto_cargo,
                                    i.ausente_inicio, i.ausente_fim,
                                    (i.substituto_id IS NOT NULL AND CURRENT_DATE() BETWEEN i.ausente_inicio AND i.ausente_fim) AS substituto_ativo
                             FROM instituicoes i
                             LEFT JOIN usuarios u ON u.id = i.gestor_id AND u.ativo = 1
                             LEFT JOIN usuarios s ON s.id = i.substituto_id AND s.ativo = 1
                             WHERE i.id IN ($in)");
$stGestores->execute($ids);
$gestoresPorUnidade = array_column($stGestores->fetchAll(), null, 'id');

// Requisições de estoque pendentes para envio em cada unidade
$stReq = $pdo->prepare("SELECT origem_id, COUNT(*) AS qtd FROM transferencias_estoque 
                        WHERE origem_id IN ($in) AND status IN ('em_transito', 'aguardando_aprovacao_superior') 
                        GROUP BY origem_id");
$stReq->execute($ids);
$requisicoesPorUnidade = array_column($stReq->fetchAll(), 'qtd', 'origem_id');

// Rede e Configurações de Alçada
$redeId = (int)($unidades[$ids[0]]['rede_id'] ?? 0);
$configRede = obterConfigRede($pdo, (int)$ids[0]) ?? ['limite_alcada_loja' => 1000.00, 'exige_aprovacao_medicamentos' => 1];
$candidatosGestor = ($isGerenteGeral && $redeId) ? listarCandidatosGestor($pdo, $redeId) : [];

// Requisições aguardando liberação superior do Gerente Geral
$totalAguardandoSuperior = 0;
if ($redeId) {
    $stSup = $pdo->prepare("SELECT COUNT(*) FROM transferencias_estoque WHERE rede_id = ? AND status = 'aguardando_aprovacao_superior'");
    $stSup->execute([$redeId]);
    $totalAguardandoSuperior = (int)$stSup->fetchColumn();
}

$linhas = [];
$totalFat = 0; $totalAg = 0; $totalCli = 0; $totalVendas = 0; $maiorFat = 0; $totalPendentes = 0;
foreach ($unidades as $id => $u) {
    $f = (float)($fatPorUnidade[$id]['total'] ?? 0);
    $qv = (int)($fatPorUnidade[$id]['qtd'] ?? 0);
    $pend = (int)($requisicoesPorUnidade[$id] ?? 0);
    $totalPendentes += $pend;

    $linhas[$id] = [
        'rotulo'      => $u['rotulo'],
        'nome_unidade'=> $u['nome_unidade'] ?: $u['nome_fantasia'],
        'cidade'      => $u['cidade'] . (!empty($u['uf']) ? '/' . $u['uf'] : ''),
        'faturamento' => $f,
        'vendas'      => $qv,
        'ticket'      => $qv > 0 ? $f / $qv : 0,
        'agendamentos'=> (int)($agPorUnidade[$id] ?? 0),
        'clientes'    => (int)($clPorUnidade[$id] ?? 0),
        'gestor'      => $gestoresPorUnidade[$id] ?? null,
        'pendentes'   => $pend,
    ];
    $totalFat += $f; $totalVendas += $qv;
    $totalAg  += $linhas[$id]['agendamentos'];
    $totalCli += $linhas[$id]['clientes'];
    $maiorFat  = max($maiorFat, $f);
}

$unidadeAtiva = (int)$_SESSION['instituicao_id'];
$brl = fn($v) => 'R$ ' . number_format($v, 2, ',', '.');

$pageTitle = 'Visão da Rede & Gestão de Unidades';
$paginaAtiva = 'rede';
include 'includes/_head.php';
include 'includes/_sidebar.php';
?>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; ?>

        <main class="main-content">
            <div class="page-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px;">
                <div>
                    <h1 class="main-title" style="margin:0;">Visão da Rede & Gestão de Unidades</h1>
                    <p style="color:#777; margin-top:4px;">
                        Consolidado de <strong><?php echo count($unidades); ?> unidades</strong> — Mês de <?php echo date('m/Y'); ?>.
                        <?php if ($isGerenteGeral): ?>
                            <span style="background:#e8f4fd; color:#2980b9; padding:2px 8px; border-radius:12px; font-size:0.75rem; font-weight:600; margin-left:6px;">
                                <i class="fas fa-crown"></i> Gerente Geral da Rede
                            </span>
                        <?php endif; ?>
                    </p>
                </div>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <a href="transferencia_estoque.php" class="btn btn-secondary" style="display:flex; align-items:center; gap:8px;">
                        <i class="fas fa-boxes"></i> Requisições de Estoque
                        <?php if ($totalPendentes > 0): ?>
                            <span style="background:#e74c3c; color:white; padding:2px 6px; border-radius:10px; font-size:0.75rem; font-weight:bold;">
                                <?php echo $totalPendentes; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                </div>
            </div>

            <!-- ALERTA DE ALÇADA SUPERIOR PENDENTE -->
            <?php if ($isGerenteGeral && $totalAguardandoSuperior > 0): ?>
                <div style="background:#fffbeb; border:1px solid #fde68a; border-left:4px solid #f59e0b; border-radius:8px; padding:14px 18px; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                    <div style="display:flex; align-items:center; gap:12px;">
                        <div style="background:#fef3c7; color:#d97706; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:1.1rem;">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <div>
                            <strong style="color:#92400e; font-size:0.95rem;">
                                Existem <?php echo $totalAguardandoSuperior; ?> requisição(ões) aguardando sua Liberação de Alçada Superior!
                            </strong>
                            <p style="margin:2px 0 0 0; color:#b45309; font-size:0.85rem;">
                                Transferências que excederam o limite financeiro da loja ou que contêm medicamentos/vacinas controlados exigem autorização da Gerência Geral.
                            </p>
                        </div>
                    </div>
                    <a href="transferencia_estoque.php?tab=recebidas" class="btn btn-warning btn-sm" style="background:#f59e0b; color:white; border:none; padding:8px 14px; font-weight:600; border-radius:6px;">
                        <i class="fas fa-crown"></i> Avaliar Liberações
                    </a>
                </div>
            <?php endif; ?>

            <!-- CARDS DE METRICAS CONSOLIDADAS -->
            <div class="kpi-widgets">
                <div class="widget-card kpi-card">
                    <div class="card-icon green"><i class="fas fa-dollar-sign"></i></div>
                    <div class="card-content">
                        <span class="card-value"><?php echo $brl($totalFat); ?></span>
                        <span class="card-label">Faturamento da Rede (Mês)</span>
                    </div>
                </div>
                <div class="widget-card kpi-card">
                    <div class="card-icon blue"><i class="fas fa-calendar-check"></i></div>
                    <div class="card-content">
                        <span class="card-value"><?php echo $totalAg; ?></span>
                        <span class="card-label">Agendamentos Hoje (Todas)</span>
                    </div>
                </div>
                <div class="widget-card kpi-card">
                    <div class="card-icon purple"><i class="fas fa-users"></i></div>
                    <div class="card-content">
                        <span class="card-value"><?php echo $totalCli; ?></span>
                        <span class="card-label">Clientes na Base da Rede</span>
                    </div>
                </div>
            </div>

            <!-- CARD DE MATRIZ DE ALÇADAS & REGRAS DE LIBERAÇÃO (ESTILO ERP / SAP) -->
            <?php if ($isGerenteGeral && $redeId): ?>
            <div class="widget-card" style="margin-top:20px; border-top: 3px solid #1abc9c;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:15px;">
                    <div>
                        <h3 style="margin:0; display:flex; align-items:center; gap:8px;">
                            <i class="fas fa-sliders-h" style="color:#1abc9c;"></i> Matriz de Alçadas & Políticas de Liberação da Rede
                        </h3>
                        <p style="font-size:0.85rem; color:#777; margin:4px 0 0 0;">
                            Configure os critérios de controle inspirados em ERP corporativo para autorização automática ou superior das transferências de estoque.
                        </p>
                    </div>
                </div>

                <form id="form-politicas-rede" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:20px; background:#f8fafc; padding:18px; border-radius:8px; border:1px solid var(--border-color);">
                    <input type="hidden" name="rede_id" value="<?php echo (int)$redeId; ?>">

                    <div>
                        <label for="limite_alcada_loja" style="display:block; font-weight:600; font-size:0.85rem; margin-bottom:6px; color:var(--text-dark);">
                            <i class="fas fa-money-bill-wave" style="color:#27ae60;"></i> Limite de Alçada Local da Loja (R$)
                        </label>
                        <div style="position:relative;">
                            <span style="position:absolute; left:12px; top:50%; transform:translateY(-50%); font-weight:bold; color:#777;">R$</span>
                            <input type="number" step="0.01" min="0" id="limite_alcada_loja" name="limite_alcada_loja"
                                   value="<?php echo number_format((float)($configRede['limite_alcada_loja'] ?? 1000.00), 2, '.', ''); ?>"
                                   class="form-control" style="padding-left:38px; width:100%; border-radius:6px; border:1px solid #cbd5e1; height:40px; font-weight:600;">
                        </div>
                        <small style="color:#64748b; display:block; margin-top:4px; font-size:0.75rem;">
                            Transferências até esse valor são aprovadas pelo gestor da loja. Acima disso, exigem liberação da Gerência Geral.
                        </small>
                    </div>

                    <div>
                        <label style="display:block; font-weight:600; font-size:0.85rem; margin-bottom:6px; color:var(--text-dark);">
                            <i class="fas fa-pills" style="color:#e74c3c;"></i> Estratégia de Liberação (Produtos Controlados)
                        </label>
                        <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; background:white; padding:10px 12px; border:1px solid #cbd5e1; border-radius:6px;">
                            <input type="checkbox" id="exige_aprovacao_medicamentos" name="exige_aprovacao_medicamentos" value="1"
                                   <?php echo !empty($configRede['exige_aprovacao_medicamentos']) ? 'checked' : ''; ?>
                                   style="margin-top:3px; cursor:pointer; width:18px; height:18px;">
                            <span style="font-size:0.85rem; color:var(--text-dark);">
                                <strong>Exigir liberação do Gerente Geral para Vacinas & Medicamentos</strong>
                                <small style="display:block; color:#64748b; font-size:0.75rem; margin-top:2px;">
                                    Bloqueia aprovação apenas local para itens de categorias sensíveis, independentemente do valor.
                                </small>
                            </span>
                        </label>
                    </div>

                    <div style="display:flex; align-items:flex-end;">
                        <button type="submit" id="btn-salvar-politicas" class="btn btn-primary" style="height:42px; display:inline-flex; align-items:center; gap:8px; font-weight:600; width:100%; justify-content:center;">
                            <i class="fas fa-save"></i> Salvar Políticas de Alçada
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- TABELA DE UNIDADES & GESTORES -->
            <div class="widget-card" style="margin-top:20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; flex-wrap:wrap; gap:10px;">
                    <div>
                        <h3 style="margin:0;"><i class="fas fa-store" style="color:#1abc9c;"></i> Unidades & Gestores Responsáveis</h3>
                        <p style="font-size:0.85rem; color:#777; margin:4px 0 0 0;">
                            <?php if ($isGerenteGeral): ?>
                                Você pode definir o gestor titular e programar delegações/férias para manter as aprovações sem gargalos.
                            <?php else: ?>
                                Gestores designados para liderar e autorizar requisições em cada unidade.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <div style="overflow-x:auto;">
                    <table class="data-table" style="width:100%; border-collapse:collapse;">
                        <thead>
                            <tr style="text-align:left; border-bottom:2px solid #eee;">
                                <th style="padding:10px;">Unidade</th>
                                <th style="padding:10px;">Gestão da Loja (Titular & Ausência)</th>
                                <th style="padding:10px; min-width:180px;">Faturamento (Mês)</th>
                                <th style="padding:10px;">Vendas</th>
                                <th style="padding:10px;">Ticket Médio</th>
                                <th style="padding:10px;">Agend. Hoje</th>
                                <th style="padding:10px;">Estoque Pendente</th>
                                <th style="padding:10px; text-align:right;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($linhas as $id => $l):
                            $pct = $maiorFat > 0 ? round(($l['faturamento'] / $maiorFat) * 100) : 0;
                            $share = $totalFat > 0 ? round(($l['faturamento'] / $totalFat) * 100) : 0;
                            $gestorInfo = $l['gestor'];
                            $temGestor = !empty($gestorInfo['gestor_id']);
                            $temSubstituto = !empty($gestorInfo['substituto_id']);
                            $subAtivo = !empty($gestorInfo['substituto_ativo']);
                        ?>
                            <tr style="border-bottom:1px solid #eee;">
                                <td style="padding:10px;">
                                    <strong><?php echo htmlspecialchars($l['rotulo']); ?></strong>
                                    <?php if ($id === $unidadeAtiva): ?>
                                        <span style="background:#e0f8e9; color:#28a745; padding:2px 8px; border-radius:12px; font-size:0.7rem; margin-left:6px; font-weight:600;">
                                            unidade ativa
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- COLUNA DO GESTOR DA UNIDADE & SUBSTUTUTO -->
                                <td style="padding:10px;">
                                    <div style="display:flex; flex-direction:column; gap:6px;">
                                        <!-- GESTOR TITULAR -->
                                        <div style="display:flex; align-items:center; gap:8px;">
                                            <?php if ($temGestor): ?>
                                                <div style="width:30px; height:30px; border-radius:50%; background:#2980b9; color:white; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:0.8rem;">
                                                    <?php echo strtoupper(substr($gestorInfo['gestor_nome'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <strong style="font-size:0.88rem; color:var(--text-dark);"><?php echo htmlspecialchars($gestorInfo['gestor_nome']); ?></strong>
                                                    <small style="display:block; color:#777; font-size:0.72rem;"><?php echo htmlspecialchars($gestorInfo['gestor_cargo'] ?? 'Gestor'); ?></small>
                                                </div>
                                            <?php else: ?>
                                                <span style="color:#94a3b8; font-style:italic; font-size:0.85rem;">
                                                    <i class="fas fa-user-slash"></i> Não definido
                                                </span>
                                            <?php endif; ?>

                                            <?php if ($isGerenteGeral): ?>
                                                <button type="button" class="btn-icon btn-alterar-gestor"
                                                        data-id="<?php echo (int)$id; ?>"
                                                        data-nome="<?php echo htmlspecialchars($l['rotulo']); ?>"
                                                        data-gestor-id="<?php echo $temGestor ? (int)$gestorInfo['gestor_id'] : ''; ?>"
                                                        title="Alterar Gestor Titular"
                                                        style="margin-left:4px; padding:3px 6px; font-size:0.75rem; border-radius:4px; cursor:pointer;">
                                                    <i class="fas fa-user-edit"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>

                                        <!-- SUBSTUTUTO TEMPORÁRIO / FÉRIAS -->
                                        <?php if ($temSubstituto): ?>
                                            <div style="display:inline-flex; align-items:center; gap:6px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:6px; padding:3px 8px; font-size:0.74rem; color:#0369a1; width:fit-content;">
                                                <i class="fas fa-umbrella-beach"></i>
                                                <span>Substituto: <strong><?php echo htmlspecialchars($gestorInfo['substituto_nome']); ?></strong></span>
                                                <span style="color:#64748b;">(<?php echo date('d/m', strtotime($gestorInfo['ausente_inicio'])); ?> a <?php echo date('d/m', strtotime($gestorInfo['ausente_fim'])); ?>)</span>
                                                <?php if ($subAtivo): ?>
                                                    <span style="background:#22c55e; color:white; border-radius:3px; padding:1px 5px; font-size:0.65rem; font-weight:bold;">ATIVO</span>
                                                <?php else: ?>
                                                    <span style="background:#94a3b8; color:white; border-radius:3px; padding:1px 5px; font-size:0.65rem; font-weight:bold;">AGENDADO</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($isGerenteGeral): ?>
                                            <div>
                                                <button type="button" class="btn-programar-substituto"
                                                        data-id="<?php echo (int)$id; ?>"
                                                        data-nome="<?php echo htmlspecialchars($l['rotulo']); ?>"
                                                        data-sub-id="<?php echo $temSubstituto ? (int)$gestorInfo['substituto_id'] : ''; ?>"
                                                        data-inicio="<?php echo htmlspecialchars($gestorInfo['ausente_inicio'] ?? ''); ?>"
                                                        data-fim="<?php echo htmlspecialchars($gestorInfo['ausente_fim'] ?? ''); ?>"
                                                        style="background:transparent; border:none; color:#0284c7; font-size:0.73rem; cursor:pointer; padding:0; text-decoration:underline;">
                                                    <i class="fas fa-calendar-alt"></i> <?php echo $temSubstituto ? 'Ajustar ausência/férias' : '+ Programar ausência / substituto'; ?>
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td style="padding:10px;">
                                    <div style="font-weight:600;"><?php echo $brl($l['faturamento']); ?>
                                        <small style="color:#999; font-weight:400;"> · <?php echo $share; ?>%</small>
                                    </div>
                                    <div style="background:#eef2f3; border-radius:6px; height:8px; margin-top:5px; overflow:hidden;">
                                        <div style="width:<?php echo $pct; ?>%; height:100%; background:linear-gradient(90deg,#1abc9c,#16a085);"></div>
                                    </div>
                                </td>
                                <td style="padding:10px;"><?php echo $l['vendas']; ?></td>
                                <td style="padding:10px;"><?php echo $brl($l['ticket']); ?></td>
                                <td style="padding:10px;"><?php echo $l['agendamentos']; ?></td>

                                <!-- ESTOQUE PENDENTE -->
                                <td style="padding:10px;">
                                    <?php if ($l['pendentes'] > 0): ?>
                                        <a href="transferencia_estoque.php" style="text-decoration:none;">
                                            <span style="background:#fff3cd; color:#856404; border:1px solid #ffeeba; padding:4px 8px; border-radius:12px; font-size:0.8rem; font-weight:600; display:inline-flex; align-items:center; gap:5px;">
                                                <i class="fas fa-clock"></i> <?php echo $l['pendentes']; ?> aguardando
                                            </span>
                                        </a>
                                    <?php else: ?>
                                        <span style="color:#27ae60; font-size:0.8rem;"><i class="fas fa-check-circle"></i> Em dia</span>
                                    <?php endif; ?>
                                </td>

                                <td style="padding:10px; text-align:right;">
                                    <?php if ($id !== $unidadeAtiva): ?>
                                        <form method="post" action="trocar_unidade.php" style="margin:0; display:inline-block;">
                                            <input type="hidden" name="unidade_id" value="<?php echo (int)$id; ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm" style="padding:5px 10px; font-size:0.8rem;">
                                                <i class="fas fa-sign-in-alt"></i> Acessar
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="border-top:2px solid #eee; font-weight:700;">
                                <td style="padding:10px;">Total da Rede</td>
                                <td style="padding:10px;">—</td>
                                <td style="padding:10px;"><?php echo $brl($totalFat); ?></td>
                                <td style="padding:10px;"><?php echo $totalVendas; ?></td>
                                <td style="padding:10px;"><?php echo $brl($totalVendas > 0 ? $totalFat / $totalVendas : 0); ?></td>
                                <td style="padding:10px;"><?php echo $totalAg; ?></td>
                                <td style="padding:10px;">
                                    <?php if ($totalPendentes > 0): ?>
                                        <span style="color:#e74c3c; font-weight:bold;"><?php echo $totalPendentes; ?> pendente(s)</span>
                                    <?php else: ?>
                                        <span style="color:#27ae60;">0 pendentes</span>
                                    <?php endif; ?>
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- MODAL DEFINIR GESTOR TITULAR -->
<?php if ($isGerenteGeral): ?>
<div class="modal-overlay" id="modal-gestor" style="display:none;">
    <div class="modal-content" style="max-width:480px;">
        <div class="modal-header">
            <h2 style="font-size:1.15rem; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-user-tie" style="color:#1abc9c;"></i> Definir Gestor Titular da Unidade
            </h2>
            <button type="button" class="close-modal-btn" id="btn-fechar-modal-gestor">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size:0.88rem; color:#64748b; margin-bottom:15px;">
                O gestor titular é a autoridade responsável por aprovar ou negar transferências de estoque para a unidade:
                <strong id="label-unidade-alvo" style="color:#0f172a; display:block; margin-top:4px;"></strong>
            </p>

            <form id="form-definir-gestor">
                <input type="hidden" id="gestor-unidade-id" value="">
                <div class="form-group" style="margin-bottom:15px;">
                    <label for="select-gestor-usuario" style="display:block; font-weight:600; font-size:0.85rem; margin-bottom:6px;">
                        Selecione o Usuário Gestor *
                    </label>
                    <select id="select-gestor-usuario" class="form-control" style="width:100%; padding:10px; border-radius:6px; font-size:0.9rem;">
                        <option value="">— Nenhum (Remover gestor atribuído) —</option>
                        <?php foreach ($candidatosGestor as $cand): ?>
                            <option value="<?php echo (int)$cand['id']; ?>">
                                <?php echo htmlspecialchars($cand['nome']); ?> (<?php echo htmlspecialchars($cand['cargo']); ?> · <?php echo htmlspecialchars($cand['nome_unidade'] ?: $cand['nome_fantasia']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
        <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:10px;">
            <button type="button" class="btn btn-secondary" id="btn-cancelar-modal-gestor">Cancelar</button>
            <button type="button" class="btn btn-primary" id="btn-salvar-gestor">
                <i class="fas fa-check"></i> Salvar Gestor
            </button>
        </div>
    </div>
</div>

<!-- MODAL PROGRAMAR AUSÊNCIA / SUBSTUTUTO TEMPORÁRIO (ERP STYLE) -->
<div class="modal-overlay" id="modal-substituto" style="display:none;">
    <div class="modal-content" style="max-width:500px;">
        <div class="modal-header">
            <h2 style="font-size:1.15rem; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-umbrella-beach" style="color:#0284c7;"></i> Delegação de Ausência & Substituto
            </h2>
            <button type="button" class="close-modal-btn" id="btn-fechar-modal-sub">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size:0.88rem; color:#64748b; margin-bottom:15px;">
                Defina um colaborador substituto durante férias ou viagens para garantir que as requisições de estoque não fiquem travadas:
                <strong id="label-unidade-sub" style="color:#0f172a; display:block; margin-top:4px;"></strong>
            </p>

            <form id="form-definir-substituto">
                <input type="hidden" id="sub-unidade-id" value="">
                
                <div class="form-group" style="margin-bottom:14px;">
                    <label for="select-sub-usuario" style="display:block; font-weight:600; font-size:0.85rem; margin-bottom:6px;">
                        Colaborador Substituto Temporário
                    </label>
                    <select id="select-sub-usuario" class="form-control" style="width:100%; padding:10px; border-radius:6px; font-size:0.9rem;">
                        <option value="">— Nenhum (Sem substituto ativo) —</option>
                        <?php foreach ($candidatosGestor as $cand): ?>
                            <option value="<?php echo (int)$cand['id']; ?>">
                                <?php echo htmlspecialchars($cand['nome']); ?> (<?php echo htmlspecialchars($cand['cargo']); ?> · <?php echo htmlspecialchars($cand['nome_unidade'] ?: $cand['nome_fantasia']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:14px;">
                    <div class="form-group">
                        <label for="sub-data-inicio" style="display:block; font-weight:600; font-size:0.85rem; margin-bottom:6px;">
                            Início da Ausência *
                        </label>
                        <input type="date" id="sub-data-inicio" class="form-control" style="width:100%; padding:8px; border-radius:6px; font-size:0.9rem;">
                    </div>
                    <div class="form-group">
                        <label for="sub-data-fim" style="display:block; font-weight:600; font-size:0.85rem; margin-bottom:6px;">
                            Fim da Ausência *
                        </label>
                        <input type="date" id="sub-data-fim" class="form-control" style="width:100%; padding:8px; border-radius:6px; font-size:0.9rem;">
                    </div>
                </div>

                <p style="font-size:0.75rem; color:#64748b; background:#f1f5f9; padding:8px 12px; border-radius:6px; margin:0;">
                    <i class="fas fa-info-circle" style="color:#0284c7;"></i> Durante o período configurado, o substituto terá automaticamente permissão de autorizar e negar transferências de estoque para esta loja.
                </p>
            </form>
        </div>
        <div class="modal-footer" style="display:flex; justify-content:space-between; gap:10px;">
            <button type="button" class="btn btn-secondary" id="btn-remover-substituto" style="color:#dc2626; border-color:#fca5a5;">
                <i class="fas fa-trash-alt"></i> Remover Delegação
            </button>
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn btn-secondary" id="btn-cancelar-modal-sub">Cancelar</button>
                <button type="button" class="btn btn-primary" id="btn-salvar-substituto" style="background:#0284c7; border-color:#0284c7;">
                    <i class="fas fa-check"></i> Salvar Delegação
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. POLÍTICAS DE ALÇADA DA REDE
    const formPoliticas = document.getElementById('form-politicas-rede');
    if (formPoliticas) {
        formPoliticas.addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('btn-salvar-politicas');
            const redeId = formPoliticas.querySelector('[name="rede_id"]').value;
            const limite = formPoliticas.querySelector('[name="limite_alcada_loja"]').value;
            const med = formPoliticas.querySelector('[name="exige_aprovacao_medicamentos"]').checked ? 1 : 0;

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';

            fetch('api_definir_gestor_unidade.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    tipo: 'politicas_rede',
                    rede_id: parseInt(redeId),
                    limite_alcada_loja: parseFloat(limite) || 0,
                    exige_aprovacao_medicamentos: med
                })
            })
            .then(res => res.json())
            .then(res => {
                alert(res.message);
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save"></i> Salvar Políticas de Alçada';
            })
            .catch(err => {
                alert('Erro de comunicação ao salvar políticas.');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save"></i> Salvar Políticas de Alçada';
            });
        });
    }

    // 2. MODAL GESTOR TITULAR
    const modalGestor = document.getElementById('modal-gestor');
    if (modalGestor) {
        const lblUnidade = document.getElementById('label-unidade-alvo');
        const inpUnidadeId = document.getElementById('gestor-unidade-id');
        const selUsuario = document.getElementById('select-gestor-usuario');
        const btnSalvar = document.getElementById('btn-salvar-gestor');

        const fecharModal = () => { modalGestor.style.display = 'none'; };
        document.getElementById('btn-fechar-modal-gestor')?.addEventListener('click', fecharModal);
        document.getElementById('btn-cancelar-modal-gestor')?.addEventListener('click', fecharModal);
        modalGestor.addEventListener('click', (e) => { if (e.target === modalGestor) fecharModal(); });

        document.querySelectorAll('.btn-alterar-gestor').forEach(btn => {
            btn.addEventListener('click', function() {
                inpUnidadeId.value = this.dataset.id;
                lblUnidade.textContent = this.dataset.nome;
                selUsuario.value = this.dataset.gestorId || '';
                modalGestor.style.display = 'flex';
            });
        });

        btnSalvar?.addEventListener('click', function() {
            const uid = inpUnidadeId.value;
            const gid = selUsuario.value;

            btnSalvar.disabled = true;
            btnSalvar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';

            fetch('api_definir_gestor_unidade.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    tipo: 'gestor',
                    instituicao_id: uid,
                    gestor_id: gid ? parseInt(gid) : null
                })
            })
            .then(res => res.json())
            .then(res => {
                alert(res.message);
                if (res.success) {
                    location.reload();
                } else {
                    btnSalvar.disabled = false;
                    btnSalvar.innerHTML = '<i class="fas fa-check"></i> Salvar Gestor';
                }
            })
            .catch(err => {
                alert('Erro de comunicação com o servidor.');
                btnSalvar.disabled = false;
                btnSalvar.innerHTML = '<i class="fas fa-check"></i> Salvar Gestor';
            });
        });
    }

    // 3. MODAL DELEGAÇÃO / SUBSTUTUTO (FÉRIAS)
    const modalSub = document.getElementById('modal-substituto');
    if (modalSub) {
        const lblSubUnidade = document.getElementById('label-unidade-sub');
        const inpSubUnidadeId = document.getElementById('sub-unidade-id');
        const selSubUsuario = document.getElementById('select-sub-usuario');
        const inpInicio = document.getElementById('sub-data-inicio');
        const inpFim = document.getElementById('sub-data-fim');
        const btnSalvarSub = document.getElementById('btn-salvar-substituto');
        const btnRemoverSub = document.getElementById('btn-remover-substituto');

        const fecharModalSub = () => { modalSub.style.display = 'none'; };
        document.getElementById('btn-fechar-modal-sub')?.addEventListener('click', fecharModalSub);
        document.getElementById('btn-cancelar-modal-sub')?.addEventListener('click', fecharModalSub);
        modalSub.addEventListener('click', (e) => { if (e.target === modalSub) fecharModalSub(); });

        document.querySelectorAll('.btn-programar-substituto').forEach(btn => {
            btn.addEventListener('click', function() {
                inpSubUnidadeId.value = this.dataset.id;
                lblSubUnidade.textContent = this.dataset.nome;
                selSubUsuario.value = this.dataset.subId || '';
                inpInicio.value = this.dataset.inicio || '';
                inpFim.value = this.dataset.fim || '';
                modalSub.style.display = 'flex';
            });
        });

        const salvarDelegação = (limpar = false) => {
            const uid = inpSubUnidadeId.value;
            const subId = limpar ? null : (selSubUsuario.value ? parseInt(selSubUsuario.value) : null);
            const dInicio = limpar ? null : inpInicio.value;
            const dFim = limpar ? null : inpFim.value;

            if (!limpar && subId && (!dInicio || !dFim)) {
                alert('Informe a data de início e de fim para a delegação de ausência.');
                return;
            }

            btnSalvarSub.disabled = true;
            btnSalvarSub.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...';

            fetch('api_definir_gestor_unidade.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    tipo: 'substituto',
                    instituicao_id: uid,
                    substituto_id: subId,
                    ausente_inicio: dInicio,
                    ausente_fim: dFim
                })
            })
            .then(res => res.json())
            .then(res => {
                alert(res.message);
                if (res.success) {
                    location.reload();
                } else {
                    btnSalvarSub.disabled = false;
                    btnSalvarSub.innerHTML = '<i class="fas fa-check"></i> Salvar Delegação';
                }
            })
            .catch(err => {
                alert('Erro de comunicação.');
                btnSalvarSub.disabled = false;
                btnSalvarSub.innerHTML = '<i class="fas fa-check"></i> Salvar Delegação';
            });
        };

        btnSalvarSub?.addEventListener('click', () => salvarDelegação(false));
        btnRemoverSub?.addEventListener('click', () => {
            if (confirm('Deseja realmente encerrar a delegação temporária desta unidade?')) {
                salvarDelegação(true);
            }
        });
    }
});
</script>
<?php endif; ?>

<?php include 'includes/_footer.php'; ?>
