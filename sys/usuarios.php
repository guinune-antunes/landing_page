<?php
require 'includes/verifica_login.php';

// Segurança: Apenas o Gerente da loja pode gerenciar a equipe
if ($_SESSION['usuario_cargo'] !== 'Gerente') {
    header("Location: dashboard.php");
    exit;
}

$pageTitle = 'Gestão de Equipe';
$paginaAtiva = 'equipe'; 
include 'includes/_head.php';
require 'conexao.php';

$id_loja = $_SESSION['instituicao_id'] ?? 0;

try {
    $sql = "SELECT id, nome, email, cargo, ativo, permissoes FROM usuarios WHERE instituicao_id = ? AND cargo != 'Admin'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id_loja]);
    $equipe = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $equipe = [];
    $erro = "Erro ao buscar equipe: " . $e->getMessage();
}
?>

<?php include 'includes/_sidebar.php'; ?>

<style>
    /* Estilo para os Toggles (Chaves estilo Discord) */
    .switch { position: relative; display: inline-block; width: 40px; height: 20px; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 20px; }
    .slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 2px; bottom: 2px; background-color: white; transition: .4s; border-radius: 50%; }
    input:checked + .slider { background-color: #5865F2; }
    input:checked + .slider:before { transform: translateX(20px); }
    .perm-row { display: flex; justify-content: space-between; align-items: center; padding: 10px; border-bottom: 1px solid #eee; }
    .perm-row:last-child { border-bottom: none; }
</style>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; ?>

        <main class="main-content">
            <div class="page-header" style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1 class="main-title">Gestão de Equipe</h1>
                    <p style="color: #777;">Controle quem tem acesso ao sistema e o que podem fazer.</p>
                </div>
                <button class="btn btn-primary" id="btn-novo-membro">
                    <i class="fas fa-user-plus"></i> Adicionar Membro
                </button>
            </div>

            <div class="widget-card">
                <table class="data-table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="text-align: left; border-bottom: 2px solid #eee;">
                            <th style="padding: 10px;">Nome</th>
                            <th style="padding: 10px;">E-mail</th>
                            <th style="padding: 10px;">Cargo Base</th>
                            <th style="padding: 10px;">Status</th>
                            <th style="padding: 10px;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($equipe as $membro): ?>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 10px;">
                                    <strong><?php echo htmlspecialchars($membro['nome']); ?></strong>
                                    <?php if($membro['id'] == $_SESSION['usuario_id']) echo "<span style='font-size:0.8rem; color:#777;'>(Você)</span>"; ?>
                                </td>
                                <td style="padding: 10px;"><?php echo htmlspecialchars($membro['email']); ?></td>
                                <td style="padding: 10px;">
                                    <span style="background: #e0e0e0; padding: 3px 8px; border-radius: 12px; font-size: 0.85rem;">
                                        <?php echo htmlspecialchars($membro['cargo']); ?>
                                    </span>
                                </td>
                                <td style="padding: 10px;">
                                    <?php echo $membro['ativo'] ? '<span style="color:green;">Ativo</span>' : '<span style="color:red;">Inativo</span>'; ?>
                                </td>
                                <td style="padding: 10px;">
                                    <button class="btn-icon btn-edit-perm" 
                                            data-id="<?php echo $membro['id']; ?>"
                                            data-nome="<?php echo htmlspecialchars($membro['nome']); ?>"
                                            data-cargo="<?php echo htmlspecialchars($membro['cargo']); ?>"
                                            data-permissoes="<?php echo htmlspecialchars($membro['permissoes'] ?? '{}'); ?>"
                                            style="color: #3498db; border: none; background: none; cursor: pointer;">
                                        <i class="fas fa-cog"></i> Editar Acessos
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</div>

<div class="modal-overlay" id="equipe-modal" style="display: none;">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h2>Adicionar Membro à Equipe</h2>
            <button class="close-modal-btn">&times;</button>
        </div>
        <div class="modal-body">
            <form id="equipe-form">
                <div class="form-group-row">
                    <div class="form-group">
                        <label>Nome Completo *</label>
                        <input type="text" id="user-nome" required>
                    </div>
                    <div class="form-group">
                        <label>Cargo Base *</label>
                        <select id="user-cargo" required>
                            <option value="Atendente">Atendente</option>
                            <option value="Tosador">Tosador</option>
                            <option value="Veterinário">Veterinário</option>
                            <option value="Gerente">Gerente (Acesso Total)</option>
                        </select>
                    </div>
                </div>
                <div class="form-group-row">
                    <div class="form-group">
                        <label>E-mail (Login) *</label>
                        <input type="email" id="user-email" required>
                    </div>
                    <div class="form-group">
                        <label>Senha Provisória *</label>
                        <input type="password" id="user-senha" required>
                    </div>
                </div>

                <h4 style="margin-top: 20px; border-bottom: 1px solid #eee; padding-bottom: 5px;">Permissões Modulares</h4>
                <div style="background: #f9f9f9; border-radius: 8px; padding: 10px; border: 1px solid #eee;">
                    <div class="perm-row">
                        <div><strong>Módulo de Clientes e Pets</strong></div>
                        <label class="switch"><input type="checkbox" id="perm-clientes" checked><span class="slider"></span></label>
                    </div>
                    <div class="perm-row">
                        <div><strong>Módulo de Agenda</strong></div>
                        <label class="switch"><input type="checkbox" id="perm-agenda" checked><span class="slider"></span></label>
                    </div>
                    <div class="perm-row">
                        <div><strong>Módulo Financeiro (Caixa)</strong></div>
                        <label class="switch"><input type="checkbox" id="perm-financeiro"><span class="slider"></span></label>
                    </div>
                    <div class="perm-row">
                        <div><strong>Módulo de Estoque (Painel de Tarefas)</strong></div>
                        <label class="switch"><input type="checkbox" id="perm-estoque"><span class="slider"></span></label>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary cancel-btn">Cancelar</button>
            <button class="btn btn-primary" id="saveUserBtn">Salvar Membro</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="edit-modal" style="display: none;">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h2>Editar Acessos: <span id="edit-nome-display" style="color: #5865F2;"></span></h2>
            <button class="close-edit-btn" style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
        </div>
        <div class="modal-body">
            <form id="edit-form">
                <input type="hidden" id="edit-user-id">

                <div class="form-group">
                    <label>Cargo Base</label>
                    <select id="edit-user-cargo" required>
                        <option value="Atendente">Atendente</option>
                        <option value="Tosador">Tosador</option>
                        <option value="Veterinário">Veterinário</option>
                        <option value="Gerente">Gerente (Acesso Total)</option>
                    </select>
                </div>

                <h4 style="margin-top: 20px; border-bottom: 1px solid #eee; padding-bottom: 5px;">Permissões Modulares</h4>
                <div style="background: #f9f9f9; border-radius: 8px; padding: 10px; border: 1px solid #eee;">
                    <div class="perm-row">
                        <div><strong>Módulo de Clientes e Pets</strong></div>
                        <label class="switch"><input type="checkbox" id="edit-perm-clientes"><span class="slider"></span></label>
                    </div>
                    <div class="perm-row">
                        <div><strong>Módulo de Agenda</strong></div>
                        <label class="switch"><input type="checkbox" id="edit-perm-agenda"><span class="slider"></span></label>
                    </div>
                    <div class="perm-row">
                        <div><strong>Módulo Financeiro (Caixa)</strong></div>
                        <label class="switch"><input type="checkbox" id="edit-perm-financeiro"><span class="slider"></span></label>
                    </div>
                    <div class="perm-row">
                        <div><strong>Módulo de Estoque (Painel de Tarefas)</strong></div>
                        <label class="switch"><input type="checkbox" id="edit-perm-estoque"><span class="slider"></span></label>
                    </div>
                </div>
            </form>
            
            <h4 style="margin-top: 20px; border-bottom: 1px solid #eee; padding-bottom: 5px; color: #e74c3c;">Segurança</h4>
            <div style="background: #fdf2f0; border-radius: 8px; padding: 10px; border: 1px solid #fadbd8; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <strong>Reset de Senha</strong>
                    <div style="font-size: 0.8rem; color: #666;">Gera uma senha provisória para este usuário.</div>
                </div>
                <button type="button" id="btn-reset-senha" class="btn" style="background: #e74c3c; color: white; border: none; padding: 5px 15px; border-radius: 5px; cursor: pointer;">
                    Gerar Nova Senha
                </button>
            </div>
        </div>
        
        <div class="modal-footer">
            <button class="btn btn-secondary close-edit-btn">Cancelar</button>
            <button class="btn btn-primary" id="updateUserBtn">Salvar Alterações</button>
        </div>
    </div>
</div>

<script>
// Todo o JS protegido dentro do DOMContentLoaded
document.addEventListener('DOMContentLoaded', function() {
    
    // --- LÓGICA DO MODAL DE ADICIONAR ---
    const modalAdd = document.getElementById('equipe-modal');
    const btnAdd = document.getElementById('btn-novo-membro');
    const btnSave = document.getElementById('saveUserBtn');
    
    btnAdd.onclick = () => modalAdd.style.display = 'flex';
    document.querySelectorAll('.cancel-btn, .close-modal-btn').forEach(btn => btn.onclick = () => modalAdd.style.display = 'none');

    document.getElementById('user-cargo').addEventListener('change', function() {
        const isGerente = this.value === 'Gerente';
        ['perm-clientes', 'perm-agenda', 'perm-financeiro', 'perm-estoque'].forEach(id => {
            const checkbox = document.getElementById(id);
            if(isGerente) {
                checkbox.checked = true;
                checkbox.disabled = true;
            } else {
                checkbox.disabled = false;
            }
        });
    });

    btnSave.onclick = function() {
        const data = {
            nome: document.getElementById('user-nome').value,
            email: document.getElementById('user-email').value,
            senha: document.getElementById('user-senha').value,
            cargo: document.getElementById('user-cargo').value,
            permissoes: {
                clientes: document.getElementById('perm-clientes').checked,
                agenda: document.getElementById('perm-agenda').checked,
                financeiro: document.getElementById('perm-financeiro').checked,
                estoque: document.getElementById('perm-estoque').checked
            }
        };

        if(!data.nome || !data.email || !data.senha) {
            alert("Preencha todos os campos obrigatórios."); return;
        }

        btnSave.innerText = "Salvando...";
        fetch('salvar_usuario_loja.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        })
        .then(res => res.json())
        .then(res => {
            alert(res.message);
            if(res.success) location.reload();
            else btnSave.innerText = "Salvar Membro";
        });
    };

    // --- LÓGICA DO MODAL DE EDIÇÃO ---
    const editModal = document.getElementById('edit-modal');
    const btnUpdate = document.getElementById('updateUserBtn');
    
    document.getElementById('btn-reset-senha').addEventListener('click', function() {
        const userId = document.getElementById('edit-user-id').value;
        const userName = document.getElementById('edit-nome-display').innerText;

        if(confirm(`Tem certeza que deseja resetar a senha de ${userName}? O usuário será forçado a criar uma nova no próximo login.`)) {
            
            this.innerText = "Gerando...";
            this.disabled = true;

            fetch('api_resetar_senha.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ id: userId })
            })
            .then(res => res.json())
            .then(res => {
                if(res.success) {
                    prompt("Senha temporária gerada com sucesso! Copie a senha abaixo e envie para o funcionário:", res.senha_temp);
                } else {
                    alert(res.message);
                }
                this.innerText = "Gerar Nova Senha";
                this.disabled = false;
            })
            .catch(err => {
                alert("Erro de conexão.");
                this.innerText = "Gerar Nova Senha";
                this.disabled = false;
            });
        }
    });

    document.querySelectorAll('.btn-edit-perm').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const nome = this.getAttribute('data-nome');
            const cargo = this.getAttribute('data-cargo');
            
            let permissoes = {};
            try { permissoes = JSON.parse(this.getAttribute('data-permissoes') || '{}'); } 
            catch (e) { console.error("Erro ao ler permissões", e); }

            document.getElementById('edit-user-id').value = id;
            document.getElementById('edit-nome-display').innerText = nome;
            document.getElementById('edit-user-cargo').value = cargo;

            // Alimenta as chavinhas com o que veio do banco
            document.getElementById('edit-perm-clientes').checked = !!permissoes.clientes;
            document.getElementById('edit-perm-agenda').checked = !!permissoes.agenda;
            document.getElementById('edit-perm-financeiro').checked = !!permissoes.financeiro;
            document.getElementById('edit-perm-estoque').checked = !!permissoes.estoque;

            const isGerente = (cargo === 'Gerente');
            ['edit-perm-clientes', 'edit-perm-agenda', 'edit-perm-financeiro', 'edit-perm-estoque'].forEach(checkId => {
                document.getElementById(checkId).disabled = isGerente;
                if(isGerente) document.getElementById(checkId).checked = true;
            });

            editModal.style.display = 'flex';
        });
    });

    document.querySelectorAll('.close-edit-btn').forEach(btn => {
        btn.addEventListener('click', () => editModal.style.display = 'none');
    });

    btnUpdate.addEventListener('click', function() {
        const data = {
            id: document.getElementById('edit-user-id').value,
            cargo: document.getElementById('edit-user-cargo').value,
            permissoes: {
                clientes: document.getElementById('edit-perm-clientes').checked,
                agenda: document.getElementById('edit-perm-agenda').checked,
                financeiro: document.getElementById('edit-perm-financeiro').checked,
                estoque: document.getElementById('edit-perm-estoque').checked
            }
        };

        btnUpdate.innerText = "Atualizando...";
        btnUpdate.disabled = true;

        fetch('atualizar_permissoes.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        })
        .then(res => res.json())
        .then(res => {
            alert(res.message);
            if(res.success) location.reload();
            else {
                btnUpdate.innerText = "Salvar Alterações";
                btnUpdate.disabled = false;
            }
        })
        .catch(err => {
            alert("Erro de conexão.");
            btnUpdate.innerText = "Salvar Alterações";
            btnUpdate.disabled = false;
        });
    });
});
</script>

<?php include 'includes/_footer.php'; ?>