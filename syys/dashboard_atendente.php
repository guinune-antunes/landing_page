<?php
session_start();
// Verifica se está logado e se é atendente
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_cargo'] !== 'atendente') {
    header("Location: login.php");
    exit;
}

$pageTitle = 'Painel do Atendente';
require 'conexao.php';
include 'includes/_head.php';

// Busca as tarefas deste atendente
$user_id = $_SESSION['usuario_id'];
$stmt = $pdo->prepare("SELECT * FROM tarefas WHERE usuario_id = ? AND status = 'pendente' ORDER BY prioridade DESC, data_criacao ASC");
$stmt->execute([$user_id]);
$tarefas = $stmt->fetchAll();
?>

<style>
    /* CSS Específico da To-Do List */
    .todo-container { max-width: 800px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
    .todo-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .task-list { list-style: none; padding: 0; }
    .task-item { 
        display: flex; align-items: center; padding: 15px; border-bottom: 1px solid #eee; 
        transition: background 0.3s; 
    }
    .task-item:hover { background-color: #f9f9f9; }
    
    /* Checkbox estilizado */
    .task-checkbox { 
        width: 20px; height: 20px; margin-right: 15px; cursor: pointer; accent-color: #27ae60;
    }
    
    .task-content { flex-grow: 1; }
    .task-desc { font-size: 1.1rem; color: #333; }
    .task-meta { font-size: 0.85rem; color: #777; margin-top: 4px; }
    
    .badge { padding: 4px 8px; border-radius: 12px; font-size: 0.75rem; color: white; }
    .badge-alta { background: #e74c3c; }
    .badge-media { background: #f39c12; }
    .badge-baixa { background: #3498db; }

    .empty-state { text-align: center; padding: 40px; color: #999; }
    .empty-state i { font-size: 3rem; margin-bottom: 10px; color: #ddd; }
</style>

<?php include 'includes/_sidebar.php'; ?>

<div class="main-area">
    <div class="content-wrapper">
        <div class="header">
            <h2>Olá, <?php echo htmlspecialchars($_SESSION['usuario_nome']); ?>! 👋</h2>
            <a href="logout.php" class="btn btn-secondary">Sair</a>
        </div>

        <main class="main-content">
            <div class="todo-container">
                <div class="todo-header">
                    <h3><i class="fas fa-clipboard-list"></i> Suas Tarefas de Hoje</h3>
                    </div>

                <ul class="task-list" id="lista-tarefas">
                    <?php if (count($tarefas) > 0): ?>
                        <?php foreach ($tarefas as $t): ?>
                            <li class="task-item" id="task-<?php echo $t['id']; ?>">
                                <input type="checkbox" class="task-checkbox" onchange="concluirTarefa(<?php echo $t['id']; ?>)">
                                <div class="task-content">
                                    <div class="task-desc"><?php echo htmlspecialchars($t['descricao']); ?></div>
                                    <div class="task-meta">
                                        <span class="badge badge-<?php echo $t['prioridade']; ?>">
                                            <?php echo ucfirst($t['prioridade']); ?>
                                        </span>
                                        Criado em: <?php echo date('d/m H:i', strtotime($t['data_criacao'])); ?>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>Tudo limpo! Você não tem tarefas pendentes.</p>
                        </div>
                    <?php endif; ?>
                </ul>
            </div>
        </main>
    </div>
</div>

<script>
function concluirTarefa(id) {
    if(!confirm("Confirmar conclusão desta tarefa?")) {
        // Se cancelar, desmarca o checkbox
        document.querySelector(`#task-${id} .task-checkbox`).checked = false;
        return;
    }

    // Efeito visual imediato (Otimista)
    const item = document.getElementById(`task-${id}`);
    item.style.opacity = '0.5';
    item.style.textDecoration = 'line-through';

    // Chama o backend
    fetch('concluir_tarefa.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: id })
    })
    .then(res => res.json())
    .then(data => {
        if(data.success) {
            // Remove da tela suavemente
            setTimeout(() => {
                item.remove();
                // Se não sobrar nada, recarrega para mostrar o empty state (ou manipula o DOM)
                if(document.querySelectorAll('.task-item').length === 0) location.reload();
            }, 500);
        } else {
            alert('Erro ao concluir tarefa.');
            item.style.opacity = '1';
            item.style.textDecoration = 'none';
        }
    });
}
</script>

<?php include 'includes/_footer.php'; ?>