<?php
require 'includes/verifica_login.php';

// --- SEGURANÇA MÁXIMA ---
// Apenas o Super Admin (que tem instituicao_id NULL ou cargo super_admin) pode ver isso
if ($_SESSION['usuario_cargo'] !== 'super_admin') {
    header("Location: dashboard.php");
    exit;
}

$pageTitle = 'Gestão de Instituições';
$paginaAtiva = 'super_admin';
include 'includes/_head.php';
require 'conexao.php';

// Busca todas as instituições e conta quantos usuários cada uma tem
$sql = "SELECT i.*, 
        (SELECT COUNT(*) FROM usuarios u WHERE u.instituicao_id = i.id) as total_usuarios 
        FROM instituicoes i 
        ORDER BY i.id DESC";
$instituicoes = $pdo->query($sql)->fetchAll();
?>

<?php include 'includes/_sidebar.php'; ?>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1 class="main-title">Painel do Super Admin</h1>
                <div class="page-controls">
                    <button class="btn btn-primary" id="add-inst-btn">
                        <i class="fas fa-store"></i> Nova Instituição
                    </button>
                </div>
            </div>

            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Pet Shop</th>
                            <th>CNPJ</th>
                            <th>Plano</th>
                            <th>Usuários</th>
                            <th>Status</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($instituicoes as $inst): ?>
                            <tr>
                                <td>#<?php echo $inst['id']; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($inst['nome_fantasia']); ?></strong><br>
                                    <small style="color:#777"><?php echo htmlspecialchars($inst['razao_social']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($inst['cnpj']); ?></td>
                                <td><span class="badge badge-info"><?php echo strtoupper($inst['plano']); ?></span></td>
                                <td style="text-align:center"><?php echo $inst['total_usuarios']; ?></td>
                                <td>
                                    <?php echo $inst['ativo'] ? '<span style="color:green">Ativo</span>' : '<span style="color:red">Bloqueado</span>'; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn-icon btn-edit" title="Editar"><i class="fas fa-pencil-alt"></i></button>
                                        <button class="btn-icon" title="Acessar Painel"><i class="fas fa-sign-in-alt"></i></button>
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

<div class="modal-overlay" id="inst-modal" style="display: none;">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h2>Cadastrar Novo Pet Shop</h2>
            <button class="close-modal-btn">&times;</button>
        </div>
        <div class="modal-body">
            <form id="inst-form">
                
                <h4 style="margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:5px;">1. Dados da Empresa</h4>
                <div class="form-group-row">
                    <div class="form-group">
                        <label>Nome Fantasia *</label>
                        <input type="text" id="inst-nome" required placeholder="Ex: Pet Shop do Bairro">
                    </div>
                    <div class="form-group">
                        <label>CNPJ</label>
                        <input type="text" id="inst-cnpj" placeholder="00.000.000/0000-00" maxlength="18">
                    </div>
                </div>

                <h4 style="margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:5px; margin-top:20px;">2. Primeiro Acesso (Gerente)</h4>
                <div class="form-group-row">
                    <div class="form-group">
                        <label>Nome do Gerente *</label>
                        <input type="text" id="manager-nome" required placeholder="Quem vai gerenciar?">
                    </div>
                    <div class="form-group">
                        <label>E-mail de Login *</label>
                        <input type="email" id="manager-email" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Senha Inicial *</label>
                    <input type="password" id="manager-senha" required>
                </div>

            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary cancel-btn">Cancelar</button>
            <button class="btn btn-primary" id="saveInstBtn">Criar Pet Shop</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('inst-modal');
    const btnAdd = document.getElementById('add-inst-btn');
    const btnSave = document.getElementById('saveInstBtn');

    // Abrir/Fechar
    btnAdd.onclick = () => modal.style.display = 'flex';
    modal.querySelector('.close-modal-btn').onclick = () => modal.style.display = 'none';
    modal.querySelector('.cancel-btn').onclick = () => modal.style.display = 'none';

    // Salvar
    btnSave.onclick = function() {
        const data = {
            nome_fantasia: document.getElementById('inst-nome').value,
            cnpj: document.getElementById('inst-cnpj').value,
            gerente_nome: document.getElementById('manager-nome').value,
            gerente_email: document.getElementById('manager-email').value,
            gerente_senha: document.getElementById('manager-senha').value
        };

        if(!data.nome_fantasia || !data.gerente_email || !data.gerente_senha) {
            alert("Preencha os campos obrigatórios."); return;
        }

        fetch('salvar_instituicao.php', {
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