<?php
require 'includes/verifica_login.php';
require 'conexao.php';

$cargo = $_SESSION['usuario_cargo'] ?? '';
$usuario_id = $_SESSION['usuario_id'] ?? 0;
$id_loja = $_SESSION['instituicao_id'] ?? 0;

// Trava de Segurança Modular
if ($cargo !== 'Gerente' && $cargo !== 'Admin') {
    $stmtPerm = $pdo->prepare("SELECT permissoes FROM usuarios WHERE id = ?");
    $stmtPerm->execute([$usuario_id]);
    $permissoes = json_decode($stmtPerm->fetchColumn() ?: '{}', true);

    if (!isset($permissoes['estoque']) || $permissoes['estoque'] != true) {
        header("Location: dashboard.php");
        exit;
    }
}

$pageTitle = 'Tarefas de Estoque';
$paginaAtiva = 'painel_estoque'; 
include 'includes/_head.php';

$stmt = $pdo->prepare("SELECT * FROM tarefas_estoque WHERE instituicao_id = ? AND status = 'Pendente' ORDER BY data_criacao DESC");
$stmt->execute([$id_loja]);
$tarefas = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include 'includes/_sidebar.php'; ?>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; ?>

        <main class="main-content">
            <div class="page-header" style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1 class="main-title"><i class="fas fa-clipboard-list" style="color: #3498db;"></i> Missões do Estoque</h1>
                    <p style="color: #777;">Ordens de serviço geradas pelo sistema ou pela gerência para revisão de produtos.</p>
                </div>
                
                <?php if ($cargo === 'Gerente' || $cargo === 'Admin'): ?>
                <button class="btn btn-primary" id="btn-nova-tarefa">
                    <i class="fas fa-plus-circle"></i> Nova Ordem de Serviço
                </button>
                <?php endif; ?>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px;">
                <?php foreach ($tarefas as $t): 
                    // Cores baseadas no tipo da tarefa
                    $corBorda = '#3498db'; // Padrão (Manual)
                    $icone = 'fa-tasks';
                    
                    if ($t['tipo'] == 'Vencimento') { $corBorda = '#e74c3c'; $icone = 'fa-skull-crossbones'; }
                    elseif ($t['tipo'] == 'Estoque Baixo') { $corBorda = '#f39c12'; $icone = 'fa-boxes'; }
                    elseif ($t['tipo'] == 'Auditoria') { $corBorda = '#9b59b6'; $icone = 'fa-search-dollar'; }
                ?>
                    <div class="widget-card" style="border-top: 4px solid <?php echo $corBorda; ?>;">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                            <h3 style="font-size: 1.1rem; display: flex; align-items: center; gap: 8px; margin: 0;">
                                <i class="fas <?php echo $icone; ?>" style="color: <?php echo $corBorda; ?>;"></i> 
                                <?php echo htmlspecialchars($t['titulo']); ?>
                            </h3>
                            <span style="font-size: 0.7rem; background: #eee; padding: 2px 6px; border-radius: 4px; color: #555;">
                                <?php echo htmlspecialchars($t['tipo']); ?>
                            </span>
                        </div>
                        
                        <p style="color: #555; font-size: 0.9rem; margin-bottom: 15px; line-height: 1.5;">
                            <?php echo nl2br(htmlspecialchars($t['descricao'])); ?>
                        </p>
                        
                        <p style="font-size: 0.8rem; color: #999; margin-bottom: 15px;">
                            <i class="fas fa-clock"></i> Solicitado em: <?php echo date('d/m/Y H:i', strtotime($t['data_criacao'])); ?>
                        </p>
                        
                        <div style="background: #f9f9f9; padding: 10px; border-radius: 5px; border: 1px solid #eee;">
                            <input type="text" id="obs-<?php echo $t['id']; ?>" placeholder="O que você fez? (Ex: Descartei os produtos)" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 10px; font-size: 0.85rem;">
                            
                            <button class="btn btn-primary btn-resolver" data-id="<?php echo $t['id']; ?>" style="width: 100%; background: #27ae60; border: none;">
                                <i class="fas fa-check"></i> Marcar como Resolvido
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if(empty($tarefas)): ?>
                    <div style="grid-column: 1 / -1; text-align: center; padding: 50px; background: white; border-radius: 8px;">
                        <i class="fas fa-glass-cheers" style="font-size: 3rem; color: #2ecc71; margin-bottom: 15px;"></i>
                        <h2 style="color: #333;">Tudo limpo!</h2>
                        <p style="color: #777;">Não há nenhuma ordem de serviço pendente para o estoque no momento.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<?php if ($cargo === 'Gerente' || $cargo === 'Admin'): ?>
<div class="modal-overlay" id="nova-tarefa-modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Criar Ordem de Serviço</h2>
            <button class="close-modal-btn" style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Título da Tarefa *</label>
                <input type="text" id="tar-titulo" required placeholder="Ex: Contagem da Prateleira 3">
            </div>
            <div class="form-group">
                <label>Tipo de Tarefa *</label>
                <select id="tar-tipo">
                    <option value="Auditoria">Auditoria / Contagem</option>
                    <option value="Limpeza">Limpeza / Organização</option>
                    <option value="Revisão Manual">Revisão de Validades</option>
                    <option value="Outros">Outros</option>
                </select>
            </div>
            <div class="form-group">
                <label>Descrição detalhada da missão *</label>
                <textarea id="tar-descricao" rows="4" required placeholder="Descreva exatamente o que a equipe precisa fazer..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary close-modal-btn">Cancelar</button>
            <button class="btn btn-primary" id="saveTarefaBtn">Delegar para a Equipe</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // --- Lógica para o Gerente Criar Tarefa Manual ---
    const modalTarefa = document.getElementById('nova-tarefa-modal');
    const btnNova = document.getElementById('btn-nova-tarefa');
    const btnSaveTar = document.getElementById('saveTarefaBtn');

    if (btnNova) {
        btnNova.onclick = () => {
            document.getElementById('tar-titulo').value = '';
            document.getElementById('tar-descricao').value = '';
            modalTarefa.style.display = 'flex';
        };

        document.querySelectorAll('.close-modal-btn').forEach(btn => btn.onclick = () => modalTarefa.style.display = 'none');

        btnSaveTar.onclick = function() {
            const data = {
                titulo: document.getElementById('tar-titulo').value,
                tipo: document.getElementById('tar-tipo').value,
                descricao: document.getElementById('tar-descricao').value
            };

            if (!data.titulo || !data.descricao) {
                alert("Preencha o título e a descrição."); return;
            }

            btnSaveTar.innerText = "Enviando...";
            btnSaveTar.disabled = true;

            fetch('api_criar_tarefa.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            })
            .then(res => res.json())
            .then(res => {
                alert(res.message);
                if(res.success) location.reload();
                else { btnSaveTar.innerText = "Delegar para a Equipe"; btnSaveTar.disabled = false; }
            });
        };
    }

    // --- Lógica do Funcionário Resolver Tarefa ---
    document.querySelectorAll('.btn-resolver').forEach(btn => {
        btn.addEventListener('click', function() {
            const idTarefa = this.getAttribute('data-id');
            const observacao = document.getElementById('obs-' + idTarefa).value;

            if(!observacao) {
                alert("Por favor, digite o que foi feito na observação antes de concluir a tarefa."); return;
            }

            this.innerText = "Processando...";
            this.disabled = true;

            fetch('api_resolver_tarefa.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ id: idTarefa, observacao: observacao })
            })
            .then(res => res.json())
            .then(res => {
                if(res.success) {
                    this.closest('.widget-card').style.opacity = '0';
                    setTimeout(() => location.reload(), 500);
                } else {
                    alert(res.message);
                    this.innerText = "Marcar como Resolvido";
                    this.disabled = false;
                }
            });
        });
    });
});
</script>

<?php include 'includes/_footer.php'; ?>