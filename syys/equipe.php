<?php
require 'includes/verifica_login.php';

// Apenas Gerente ou Admin da Loja pode acessar
if ($_SESSION['usuario_cargo'] !== 'gerente' && $_SESSION['usuario_cargo'] !== 'admin') {
    header("Location: dashboard.php"); exit;
}

$pageTitle = 'Minha Equipe';
$paginaAtiva = 'equipe';
include 'includes/_head.php';
require 'conexao.php';

$id_loja = $_SESSION['instituicao_id'];

// Busca usuários DA MINHA LOJA
$sql = "SELECT id, nome, email, cargo, ativo FROM usuarios WHERE instituicao_id = ? ORDER BY nome";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id_loja]);
$membros = $stmt->fetchAll();
?>

<?php include 'includes/_sidebar.php'; ?>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1 class="main-title">Minha Equipe</h1>
                <div class="page-controls">
                    <button class="btn btn-primary" id="add-member-btn">
                        <i class="fas fa-user-plus"></i> Novo Funcionário
                    </button>
                </div>
            </div>

            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>E-mail / Login</th>
                            <th>Função</th>
                            <th>Status</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody id="teamTableBody">
                        <?php foreach ($membros as $u): ?>
                            <tr data-id="<?php echo $u['id']; ?>">
                                <td><?php echo htmlspecialchars($u['nome']); ?></td>
                                <td><?php echo htmlspecialchars($u['email']); ?></td>
                                <td>
                                    <?php 
                                        $badgeColor = 'gray';
                                        if($u['cargo'] == 'veterinario') $badgeColor = '#e74c3c'; // Vermelho
                                        if($u['cargo'] == 'banho_tosa') $badgeColor = '#3498db'; // Azul
                                        if($u['cargo'] == 'atendente') $badgeColor = '#f39c12'; // Laranja
                                        if($u['cargo'] == 'gerente') $badgeColor = '#27ae60'; // Verde
                                    ?>
                                    <span class="badge" style="background-color: <?php echo $badgeColor; ?>; color: white; padding: 4px 8px; border-radius: 4px;">
                                        <?php echo ucfirst(str_replace('_', ' ', $u['cargo'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo $u['ativo'] ? '<span style="color:green">Ativo</span>' : '<span style="color:red">Inativo</span>'; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn-icon btn-edit" data-id="<?php echo $u['id']; ?>" title="Editar / Resetar Senha">
                                            <i class="fas fa-pencil-alt"></i>
                                        </button>
                                        
                                        <?php if($u['id'] != $_SESSION['usuario_id']): ?>
                                            <button class="btn-icon btn-delete" data-id="<?php echo $u['id']; ?>" title="Remover">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</div>

<div class="modal-overlay" id="member-modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modal-title">Novo Membro da Equipe</h2>
            <button class="close-modal-btn">&times;</button>
        </div>
        <div class="modal-body">
            <form id="member-form">
                <input type="hidden" id="m-id">

                <div class="form-group">
                    <label>Nome Completo *</label>
                    <input type="text" id="m-nome" required>
                </div>
                <div class="form-group">
                    <label>E-mail de Login *</label>
                    <input type="email" id="m-email" required>
                </div>
                <div class="form-group">
                    <label>Função / Cargo *</label>
                    <select id="m-cargo" required>
                        <option value="atendente">Atendente (Geral)</option>
                        <option value="veterinario">Veterinário</option>
                        <option value="banho_tosa">Banho e Tosa</option>
                        <option value="gerente">Gerente</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Senha</label>
                    <input type="password" id="m-senha" placeholder="Digite a senha...">
                    <small style="color: #777; display: none;" id="senha-hint">
                        <i class="fas fa-info-circle"></i> Deixe em branco para manter a senha atual.
                    </small>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary cancel-btn">Cancelar</button>
            <button class="btn btn-primary" id="saveMemberBtn">Salvar</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('member-modal');
    const btnAdd = document.getElementById('add-member-btn');
    const btnSave = document.getElementById('saveMemberBtn');
    const tableBody = document.getElementById('teamTableBody');
    
    // Elementos do Form
    const formId = document.getElementById('m-id');
    const formNome = document.getElementById('m-nome');
    const formEmail = document.getElementById('m-email');
    const formCargo = document.getElementById('m-cargo');
    const formSenha = document.getElementById('m-senha');
    const modalTitle = document.getElementById('modal-title');
    const senhaHint = document.getElementById('senha-hint');

    // Abrir Modal (NOVO)
    if(btnAdd) {
        btnAdd.onclick = () => {
            formId.value = ''; // Limpa ID
            document.getElementById('member-form').reset();
            modalTitle.textContent = 'Novo Membro da Equipe';
            senhaHint.style.display = 'none'; // Esconde a dica
            formSenha.required = true; // Senha é obrigatória no cadastro
            modal.style.display = 'flex';
        };
    }

    // Fechar Modal
    modal.querySelector('.close-modal-btn').onclick = () => modal.style.display = 'none';
    modal.querySelector('.cancel-btn').onclick = () => modal.style.display = 'none';

    // DELEGAÇÃO DE EVENTOS (Editar / Excluir)
    tableBody.addEventListener('click', function(e) {
        const btn = e.target.closest('button');
        if(!btn) return;
        const id = btn.dataset.id;

        // --- EDITAR ---
        if (btn.classList.contains('btn-edit')) {
            fetch(`buscar_usuario_equipe.php?id=${id}`)
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    const u = data.usuario;
                    // Preenche o form
                    formId.value = u.id;
                    formNome.value = u.nome;
                    formEmail.value = u.email;
                    formCargo.value = u.cargo;
                    formSenha.value = ''; // Limpa campo senha
                    
                    // Ajustes visuais para edição
                    modalTitle.textContent = 'Editar Funcionário';
                    senhaHint.style.display = 'block'; // Mostra a dica "deixe em branco"
                    formSenha.required = false; // Senha opcional na edição

                    modal.style.display = 'flex';
                } else {
                    alert('Erro ao buscar dados.');
                }
            });
        }

        // --- EXCLUIR ---
        if (btn.classList.contains('btn-delete')) {
            // (Você pode implementar a lógica de exclusão aqui se quiser)
            alert('Funcionalidade de exclusão pendente de implementação no backend.');
        }
    });

    // Salvar (Novo ou Edição)
    btnSave.onclick = function() {
        const data = {
            id: formId.value, // Se tiver valor, é Update. Se vazio, é Insert.
            nome: formNome.value,
            email: formEmail.value,
            cargo: formCargo.value,
            senha: formSenha.value
        };

        // Validação simples
        if(!data.nome || !data.email) { alert('Preencha nome e email!'); return; }
        if(!data.id && !data.senha) { alert('Senha é obrigatória para novos cadastros!'); return; }

        fetch('salvar_equipe.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        })
        .then(res => res.json())
        .then(res => {
            alert(res.message);
            if(res.success) location.reload();
        });
    };
});
</script>

<?php include 'includes/_footer.php'; ?>