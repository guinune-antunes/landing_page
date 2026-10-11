<?php
// ══════════════════════════════════════════════════════════════════
// transferencia_estoque.php — Transferência de estoque entre unidades da rede
// ══════════════════════════════════════════════════════════════════
require_once 'includes/verifica_login.php';
verificarPermissao(['Gerente']);
require_once 'conexao.php';
require_once 'includes/unidades.php';

$usuarioId = (int)$_SESSION['usuario_id'];
$origemId  = (int)$_SESSION['instituicao_id'];

$unidades = carregarUnidadesUsuario($pdo, $usuarioId);
$destinos = array_filter($unidades, fn($u, $id) => $id !== $origemId
        && !empty($u['rede_id']) && (int)$u['rede_id'] === (int)($unidades[$origemId]['rede_id'] ?? 0),
        ARRAY_FILTER_USE_BOTH);

if (!$destinos) {
    header('Location: dashboard_gerente.php');
    exit;
}

// Produtos disponíveis para transferir (unidade ativa)
$st = $pdo->prepare("SELECT id, nome_produto, codigo_barras, quantidade_estoque, unidade
                     FROM produtos WHERE instituicao_id = ? AND quantidade_estoque > 0
                     ORDER BY nome_produto ASC");
$st->execute([$origemId]);
$produtos = $st->fetchAll();

// Histórico (entradas e saídas da unidade ativa)
$st = $pdo->prepare("SELECT t.*, uo.nome_fantasia AS o_nome, uo.nome_unidade AS o_un, uo.cidade AS o_cid, uo.uf AS o_uf,
                            ud.nome_fantasia AS d_nome, ud.nome_unidade AS d_un, ud.cidade AS d_cid, ud.uf AS d_uf,
                            us.nome AS usuario_nome
                     FROM transferencias_estoque t
                     JOIN instituicoes uo ON uo.id = t.origem_id
                     JOIN instituicoes ud ON ud.id = t.destino_id
                     LEFT JOIN usuarios us ON us.id = t.usuario_id
                     WHERE t.origem_id = ? OR t.destino_id = ?
                     ORDER BY t.data_transferencia DESC, t.id DESC LIMIT 50");
$st->execute([$origemId, $origemId]);
$historico = $st->fetchAll();

$rot = fn($p, $r) => unidadeRotulo([
    'nome_fantasia' => $r[$p . '_nome'], 'nome_unidade' => $r[$p . '_un'],
    'cidade' => $r[$p . '_cid'], 'uf' => $r[$p . '_uf'],
]);

$pageTitle = 'Transferência de Estoque';
$paginaAtiva = 'transferencia';
include 'includes/_head.php';
include 'includes/_sidebar.php';
?>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="main-title">Transferência de Estoque</h1>
                    <p style="color:#777;">
                        Origem: <strong><?php echo htmlspecialchars($unidades[$origemId]['rotulo']); ?></strong>
                        — mova produtos para outra unidade da rede.
                    </p>
                </div>
            </div>

            <div class="widget-card">
                <h3 style="margin-bottom:15px;"><i class="fas fa-exchange-alt" style="color:#1abc9c;"></i> Nova transferência</h3>
                <?php if (!$produtos): ?>
                    <p style="color:#777;">Nenhum produto com saldo nesta unidade.</p>
                <?php else: ?>
                <form id="form-transferencia">
                    <div class="form-group-row">
                        <div class="form-group" style="flex:2;">
                            <label for="tr-produto">Produto *</label>
                            <select id="tr-produto" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($produtos as $p): ?>
                                    <option value="<?php echo (int)$p['id']; ?>" data-saldo="<?php echo (int)$p['quantidade_estoque']; ?>">
                                        <?php echo htmlspecialchars($p['nome_produto']); ?>
                                        (saldo: <?php echo (int)$p['quantidade_estoque']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="flex:1.5;">
                            <label for="tr-destino">Unidade de destino *</label>
                            <select id="tr-destino" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($destinos as $id => $u): ?>
                                    <option value="<?php echo (int)$id; ?>"><?php echo htmlspecialchars($u['rotulo']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="flex:0.7;">
                            <label for="tr-qtd">Quantidade *</label>
                            <input type="number" id="tr-qtd" min="1" step="1" required>
                            <small id="tr-saldo" style="color:#777;"></small>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="tr-obs">Observação</label>
                        <input type="text" id="tr-obs" maxlength="255" placeholder="Ex: reposição da loja de Curitiba">
                    </div>
                    <button type="submit" class="btn btn-primary" id="btn-transferir">
                        <i class="fas fa-paper-plane"></i> Transferir
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <div class="widget-card" style="margin-top:20px;">
                <h3 style="margin-bottom:15px;">Histórico recente</h3>
                <table class="data-table" style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="text-align:left;border-bottom:2px solid #eee;">
                            <th style="padding:10px;">Data</th>
                            <th style="padding:10px;">Produto</th>
                            <th style="padding:10px;">Qtd</th>
                            <th style="padding:10px;">Movimento</th>
                            <th style="padding:10px;">Responsável</th>
                            <th style="padding:10px;">Obs.</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($historico as $h): $saiu = (int)$h['origem_id'] === $origemId; ?>
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:10px;"><?php echo date('d/m/Y H:i', strtotime($h['data_transferencia'])); ?></td>
                            <td style="padding:10px;"><strong><?php echo htmlspecialchars($h['nome_produto']); ?></strong></td>
                            <td style="padding:10px;font-weight:700;color:<?php echo $saiu ? '#e74c3c' : '#27ae60'; ?>;">
                                <?php echo ($saiu ? '−' : '+') . (int)$h['quantidade']; ?>
                            </td>
                            <td style="padding:10px;">
                                <?php if ($saiu): ?>
                                    <i class="fas fa-arrow-right" style="color:#e74c3c;"></i> Enviado para <?php echo htmlspecialchars($rot('d', $h)); ?>
                                <?php else: ?>
                                    <i class="fas fa-arrow-left" style="color:#27ae60;"></i> Recebido de <?php echo htmlspecialchars($rot('o', $h)); ?>
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px;"><?php echo htmlspecialchars($h['usuario_nome'] ?? '—'); ?></td>
                            <td style="padding:10px;color:#777;"><?php echo htmlspecialchars($h['observacao'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$historico): ?>
                        <tr><td colspan="6" style="text-align:center;padding:20px;color:#777;">Nenhuma transferência registrada.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('form-transferencia');
    if (!form) return;
    const selProd = document.getElementById('tr-produto');
    const inpQtd  = document.getElementById('tr-qtd');
    const saldo   = document.getElementById('tr-saldo');
    const btn     = document.getElementById('btn-transferir');

    selProd.addEventListener('change', function() {
        const s = this.selectedOptions[0]?.dataset.saldo;
        inpQtd.max = s || '';
        saldo.textContent = s ? 'Máximo: ' + s : '';
    });

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const destinoTxt = document.getElementById('tr-destino').selectedOptions[0].text.trim();
        if (!confirm('Transferir ' + inpQtd.value + ' un. para "' + destinoTxt + '"?')) return;

        btn.disabled = true;
        fetch('api_transferir_estoque.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                produto_id: selProd.value,
                destino_id: document.getElementById('tr-destino').value,
                quantidade: inpQtd.value,
                observacao: document.getElementById('tr-obs').value
            })
        })
        .then(res => res.json())
        .then(res => {
            alert(res.message);
            if (res.success) location.reload(); else btn.disabled = false;
        })
        .catch(() => { alert('Erro de comunicação com o servidor.'); btn.disabled = false; });
    });
});
</script>

<?php include 'includes/_footer.php'; ?>
