<?php
require 'includes/verifica_login.php';

// PROTEÇÃO: Apenas o Administrador do Sistema (SaaS) pode acessar
if ($_SESSION['usuario_cargo'] !== 'Admin') {
    header("Location: dashboard.php");
    exit;
}

$pageTitle = 'Gerenciamento de Pet Shops (SaaS)';
$paginaAtiva = 'clientes'; // Para o menu lateral ficar marcado
include 'includes/_head.php';
require 'conexao.php';

// Busca todas as instituições cadastradas
try {
    $stmt = $pdo->query("SELECT * FROM instituicoes ORDER BY data_criacao DESC");
    $instituicoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $instituicoes = [];
    $erro = "Crie a tabela 'instituicoes' no banco de dados primeiro.";
}
?>

<?php include 'includes/_sidebar.php'; ?>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; ?>

        <main class="main-content">
            <div class="page-header" style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1 class="main-title">Instituições Cadastradas</h1>
                    <p style="color: #777;">Gerencie os Pet Shops que utilizam o seu sistema.</p>
                </div>
                <button class="btn btn-primary" id="btn-nova-empresa">
                    <i class="fas fa-plus-circle"></i> Nova Instituição
                </button>
            </div>

            <?php if(isset($erro)): ?>
                <div style="background: #ffcccc; color: #cc0000; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                    <?php echo $erro; ?>
                </div>
            <?php endif; ?>

            <div class="widget-card">
                <table class="data-table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="text-align: left; border-bottom: 2px solid #eee;">
                            <th style="padding: 10px;">ID</th>
                            <th style="padding: 10px;">Nome Fantasia</th>
                            <th style="padding: 10px;">CNPJ</th>
                            <th style="padding: 10px;">Status</th>
                            <th style="padding: 10px;">Data de Cadastro</th>
                            <th style="padding: 10px;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($instituicoes) > 0): ?>
                            <?php foreach ($instituicoes as $inst): ?>
                                <tr style="border-bottom: 1px solid #eee;">
                                    <td style="padding: 10px;">#<?php echo str_pad($inst['id'], 3, '0', STR_PAD_LEFT); ?></td>
                                    <td style="padding: 10px;"><strong><?php echo htmlspecialchars($inst['nome_fantasia']); ?></strong></td>
                                    <td style="padding: 10px;"><?php echo htmlspecialchars($inst['cnpj'] ?? '') ?: 'Não informado'; ?></td>
                                    <td style="padding: 10px;" class="status-cell">
                                        <?php if (!empty($inst['ativo'])): ?>
                                            <span style="background: #e0f8e9; color: #28a745; padding: 3px 8px; border-radius: 12px; font-size: 0.8rem;">Ativo</span>
                                        <?php else: ?>
                                            <span style="background: #ffe5e5; color: #dc3545; padding: 3px 8px; border-radius: 12px; font-size: 0.8rem;">Inativo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 10px;"><?php echo date('d/m/Y', strtotime($inst['data_criacao'])); ?></td>
                                    <td style="padding: 10px;">
                                        <a href="gerenciar_instituicao.php?id=<?php echo $inst['id']; ?>" class="btn-icon" style="color: #3498db; text-decoration: none; display: inline-block; margin-right: 10px;" title="Gerenciar Instituição (Reset/Exportar)">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php $ativo = !empty($inst['ativo']); ?>
                                        <button class="btn-icon btn-toggle-status" data-id="<?php echo $inst['id']; ?>" data-nome="<?php echo htmlspecialchars($inst['nome_fantasia']); ?>" data-ativo="<?php echo $ativo ? 1 : 0; ?>"
                                                style="color: <?php echo $ativo ? '#e74c3c' : '#28a745'; ?>; border: none; background: none; cursor: pointer;"
                                                title="<?php echo $ativo ? 'Bloquear' : 'Desbloquear'; ?>">
                                            <i class="fas <?php echo $ativo ? 'fa-ban' : 'fa-unlock'; ?>"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 20px; color: #777;">Nenhuma instituição cadastrada ainda.</td>
                            </tr>
                        <?php endif; ?>
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
                <h4 style="margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:5px;">Dados da Empresa</h4>
                <div class="form-group-row">
                    <div class="form-group">
                        <label>Nome Fantasia *</label>
                        <input type="text" id="inst-nome" required placeholder="Ex: Pet Shop do Bairro">
                    </div>
                    <div class="form-group">
                        <label>CNPJ</label>
                        <input type="text" id="inst-cnpj" placeholder="00.000.000/0000-00">
                    </div>
                </div>

                <h4 style="margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:5px; margin-top:20px;">Dados do Gerente (Acesso Inicial)</h4>
                <div class="form-group-row">
                    <div class="form-group">
                        <label>Nome do Gerente *</label>
                        <input type="text" id="manager-nome" required placeholder="Nome do responsável">
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
            <button class="btn btn-primary" id="saveInstBtn">Salvar Instituição</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('inst-modal');
    const btnAdd = document.getElementById('btn-nova-empresa');
    const btnSave = document.getElementById('saveInstBtn');
    const closeBtns = modal.querySelectorAll('.close-modal-btn, .cancel-btn');

    if(btnAdd) btnAdd.onclick = () => modal.style.display = 'flex';
    closeBtns.forEach(btn => btn.onclick = () => modal.style.display = 'none');

    // Bloquear / Desbloquear instituição
    const badgeAtivo = '<span style="background: #e0f8e9; color: #28a745; padding: 3px 8px; border-radius: 12px; font-size: 0.8rem;">Ativo</span>';
    const badgeInativo = '<span style="background: #ffe5e5; color: #dc3545; padding: 3px 8px; border-radius: 12px; font-size: 0.8rem;">Inativo</span>';

    document.querySelectorAll('.btn-toggle-status').forEach(btn => {
        btn.addEventListener('click', function() {
            const ativo = this.dataset.ativo === '1';
            const acao = ativo ? 'BLOQUEAR' : 'DESBLOQUEAR';
            if (!confirm(`Deseja ${acao} a instituição "${this.dataset.nome}"?` + (ativo ? '\n\nOs usuários dela não conseguirão mais fazer login.' : ''))) return;

            this.disabled = true;
            fetch('api_admin_status_instituicao.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ id: this.dataset.id })
            })
            .then(res => res.json())
            .then(res => {
                if (!res.success) { alert(res.message); return; }
                const novoAtivo = res.ativo === 1;
                this.dataset.ativo = novoAtivo ? '1' : '0';
                this.title = novoAtivo ? 'Bloquear' : 'Desbloquear';
                this.style.color = novoAtivo ? '#e74c3c' : '#28a745';
                this.querySelector('i').className = 'fas ' + (novoAtivo ? 'fa-ban' : 'fa-unlock');
                this.closest('tr').querySelector('.status-cell').innerHTML = novoAtivo ? badgeAtivo : badgeInativo;
            })
            .catch(err => { console.error(err); alert('Erro de comunicação com o servidor.'); })
            .finally(() => { this.disabled = false; });
        });
    });

    btnSave.onclick = function() {
        const data = {
            nome_fantasia: document.getElementById('inst-nome').value,
            cnpj: document.getElementById('inst-cnpj').value,
            gerente_nome: document.getElementById('manager-nome').value,
            gerente_email: document.getElementById('manager-email').value,
            gerente_senha: document.getElementById('manager-senha').value
        };

        if(!data.nome_fantasia || !data.gerente_email || !data.gerente_senha) {
            alert("Preencha os campos obrigatórios (Nome da Empresa, E-mail e Senha)."); return;
        }

        const originalText = btnSave.innerText;
        btnSave.innerText = "Salvando...";
        btnSave.disabled = true;

        fetch('salvar_instituicao.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        })
        .then(res => res.json())
        .then(res => {
            alert(res.message);
            if(res.success) {
                location.reload(); 
            } else {
                btnSave.innerText = originalText;
                btnSave.disabled = false;
            }
        })
        .catch(err => {
            console.error(err);
            alert("Erro de comunicação com o servidor.");
            btnSave.innerText = originalText;
            btnSave.disabled = false;
        });
    };
});
</script>

<?php include 'includes/_footer.php'; ?>