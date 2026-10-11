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
    $stmt = $pdo->query("SELECT i.*, r.nome AS rede_nome, r.compartilhar_clientes, r.compartilhar_fidelidade
                         FROM instituicoes i LEFT JOIN redes r ON r.id = i.rede_id
                         ORDER BY i.data_criacao DESC");
    $instituicoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $redes = $pdo->query("SELECT id, nome, compartilhar_clientes, compartilhar_fidelidade FROM redes ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $instituicoes = [];
    $redes = [];
    $erro = "Crie a tabela 'instituicoes' (e rode migracao_rede_unidades.php) no banco de dados primeiro.";
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
                            <th style="padding: 10px;">Rede / Unidade</th>
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
                                    <td style="padding: 10px;">
                                        <?php if (!empty($inst['rede_id'])): ?>
                                            <span style="background:#e8f4fd;color:#2980b9;padding:3px 8px;border-radius:12px;font-size:0.8rem;display:inline-flex;align-items:center;gap:6px;">
                                                <i class="fas fa-network-wired"></i> <?php echo htmlspecialchars($inst['rede_nome']); ?>
                                                <button type="button" class="btn-config-rede" 
                                                        data-id="<?php echo (int)$inst['rede_id']; ?>" 
                                                        data-nome="<?php echo htmlspecialchars($inst['rede_nome']); ?>"
                                                        data-clientes="<?php echo (int)($inst['compartilhar_clientes'] ?? 1); ?>"
                                                        data-fidelidade="<?php echo (int)($inst['compartilhar_fidelidade'] ?? 1); ?>"
                                                        style="border:none;background:none;color:#2980b9;cursor:pointer;padding:0;font-size:0.75rem;" title="Configurar políticas da Rede">
                                                    <i class="fas fa-cog"></i>
                                                </button>
                                            </span>
                                            <?php if (!empty($inst['matriz'])): ?><small style="color:#f39c12;font-weight:600;margin-left:4px;">Matriz</small><?php endif; ?>
                                            <?php if (!empty($inst['nome_unidade']) || !empty($inst['cidade'])): ?>
                                                <br><small style="color:#777;"><?php echo htmlspecialchars(trim(($inst['nome_unidade'] ?? '') . ' ' . ($inst['cidade'] ? '(' . $inst['cidade'] . ($inst['uf'] ? '/' . $inst['uf'] : '') . ')' : ''))); ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <small style="color:#aaa;">Independente</small>
                                        <?php endif; ?>
                                    </td>
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
                                        <?php if (!empty($inst['rede_id'])): ?>
                                            <button class="btn-icon btn-add-unidade" data-rede="<?php echo (int)$inst['rede_id']; ?>" data-rede-nome="<?php echo htmlspecialchars($inst['rede_nome']); ?>"
                                                    style="color:#16a085;border:none;background:none;cursor:pointer;margin-right:10px;" title="Adicionar unidade a esta rede">
                                                <i class="fas fa-plus-square"></i>
                                            </button>
                                        <?php else: ?>
                                            <button class="btn-icon btn-agrupar-rede" data-id="<?php echo (int)$inst['id']; ?>" data-nome="<?php echo htmlspecialchars($inst['nome_fantasia']); ?>"
                                                    style="color:#8e44ad;border:none;background:none;cursor:pointer;margin-right:10px;" title="Transformar em rede (permite múltiplas unidades)">
                                                <i class="fas fa-network-wired"></i>
                                            </button>
                                        <?php endif; ?>
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
                                <td colspan="7" style="text-align: center; padding: 20px; color: #777;">Nenhuma instituição cadastrada ainda.</td>
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

                <h4 style="margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:5px; margin-top:20px;">Estrutura (Rede / Unidades)</h4>
                <div class="form-group">
                    <label>Tipo de cadastro</label>
                    <select id="inst-tipo">
                        <option value="independente">Loja independente (1 unidade)</option>
                        <option value="nova_rede">Nova rede (empresa com várias unidades — esta será a matriz)</option>
                        <?php foreach ($redes as $r): ?>
                            <option value="rede:<?php echo (int)$r['id']; ?>">Adicionar como unidade da rede “<?php echo htmlspecialchars($r['nome']); ?>”</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="grp-nome-rede" style="display:none;">
                    <label>Nome da Rede / Empresa</label>
                    <input type="text" id="inst-nome-rede" placeholder="Ex: Rede PetLove">
                    <div style="margin-top:10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px; display:flex; flex-direction:column; gap:8px;">
                        <span style="font-size:0.8rem; font-weight:600; color:#475569; text-transform:uppercase;">Políticas da Rede</span>
                        <label style="display:flex;align-items:center;gap:8px;font-weight:normal;font-size:0.9rem;cursor:pointer;">
                            <input type="checkbox" id="inst-comp-clientes" checked>
                            Compartilhar clientes entre todas as unidades da rede
                        </label>
                        <label style="display:flex;align-items:center;gap:8px;font-weight:normal;font-size:0.9rem;cursor:pointer;">
                            <input type="checkbox" id="inst-comp-fidelidade" checked>
                            Compartilhar programa de fidelidade (cartão único na rede)
                        </label>
                    </div>
                </div>
                <div id="grp-unidade" style="display:none;">
                    <div class="form-group-row">
                        <div class="form-group">
                            <label>Nome da Unidade</label>
                            <input type="text" id="inst-nome-unidade" placeholder="Ex: Unidade Curitiba">
                        </div>
                        <div class="form-group">
                            <label>Cidade</label>
                            <input type="text" id="inst-cidade" placeholder="Curitiba">
                        </div>
                        <div class="form-group" style="max-width:90px;">
                            <label>UF</label>
                            <input type="text" id="inst-uf" maxlength="2" placeholder="PR">
                        </div>
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
                <div class="form-group" id="grp-gerente-geral" style="display:none;">
                    <label style="display:flex;align-items:center;gap:8px;font-weight:normal;">
                        <input type="checkbox" id="manager-geral" checked>
                        Gerente Geral — enxerga todas as unidades da rede (inclusive futuras)
                    </label>
                    <small style="color:#777;">Se o e-mail já for de um gerente desta rede, ele será apenas vinculado à nova unidade (a senha informada é ignorada).</small>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary cancel-btn">Cancelar</button>
            <button class="btn btn-primary" id="saveInstBtn">Salvar Instituição</button>
        </div>
    </div>
</div>

<!-- MODAL CONFIGURAR POLÍTICAS DA REDE -->
<div class="modal-overlay" id="modal-config-rede" style="display: none;">
    <div class="modal-content" style="max-width: 520px;">
        <div class="modal-header">
            <h2><i class="fas fa-network-wired" style="color: #2980b9;"></i> Políticas da Rede</h2>
            <button class="close-modal-btn">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="cfg-rede-id">
            <div class="form-group">
                <label>Nome da Rede / Empresa *</label>
                <input type="text" id="cfg-rede-nome" required>
            </div>
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:15px; margin-top:15px; display:flex; flex-direction:column; gap:12px;">
                <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;">
                    <input type="checkbox" id="cfg-rede-clientes" style="margin-top:3px;">
                    <div>
                        <strong>Compartilhar base de clientes</strong><br>
                        <small style="color:#64748b;">Tutores e pets aparecem na agenda, histórico e balcão/PDV de todas as unidades da rede.</small>
                    </div>
                </label>
                <hr style="border:0; border-top:1px solid #e2e8f0; margin:0;">
                <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;">
                    <input type="checkbox" id="cfg-rede-fidelidade" style="margin-top:3px;">
                    <div>
                        <strong>Compartilhar fidelidade e pontuação</strong><br>
                        <small style="color:#64748b;">Compras em qualquer loja da rede somam selos no mesmo cartão de fidelidade (unificado na matriz).</small>
                    </div>
                </label>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary cancel-btn">Cancelar</button>
            <button class="btn btn-primary" id="btnSalvarConfigRede">Salvar Políticas</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('inst-modal');
    const btnAdd = document.getElementById('btn-nova-empresa');
    const btnSave = document.getElementById('saveInstBtn');
    const closeBtns = modal.querySelectorAll('.close-modal-btn, .cancel-btn');

    if(btnAdd) btnAdd.onclick = () => { setTipo('independente'); modal.style.display = 'flex'; };
    closeBtns.forEach(btn => btn.onclick = () => modal.style.display = 'none');

    // Estrutura de rede/unidade no modal
    const selTipo = document.getElementById('inst-tipo');
    function atualizarCamposRede() {
        const v = selTipo.value;
        document.getElementById('grp-nome-rede').style.display = v === 'nova_rede' ? 'block' : 'none';
        document.getElementById('grp-unidade').style.display = v === 'independente' ? 'none' : 'block';
        document.getElementById('grp-gerente-geral').style.display = v === 'independente' ? 'none' : 'block';
    }
    function setTipo(v) { selTipo.value = v; atualizarCamposRede(); }
    selTipo.addEventListener('change', atualizarCamposRede);

    // "Adicionar unidade" numa rede existente
    document.querySelectorAll('.btn-add-unidade').forEach(btn => {
        btn.addEventListener('click', function() {
            setTipo('rede:' + this.dataset.rede);
            modal.style.display = 'flex';
        });
    });

    // "Transformar em rede" uma loja independente
    document.querySelectorAll('.btn-agrupar-rede').forEach(btn => {
        btn.addEventListener('click', function() {
            const nomeRede = prompt('Nome da rede/empresa:', this.dataset.nome);
            if (nomeRede === null) return;
            fetch('api_admin_criar_rede.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ id: this.dataset.id, nome_rede: nomeRede })
            })
            .then(res => res.json())
            .then(res => { alert(res.message); if (res.success) location.reload(); })
            .catch(err => { console.error(err); alert('Erro de comunicação com o servidor.'); });
        });
    });

    // "Configurar políticas da Rede"
    const modalRede = document.getElementById('modal-config-rede');
    document.querySelectorAll('.btn-config-rede').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            document.getElementById('cfg-rede-id').value = this.dataset.id;
            document.getElementById('cfg-rede-nome').value = this.dataset.nome;
            document.getElementById('cfg-rede-clientes').checked = this.dataset.clientes === '1';
            document.getElementById('cfg-rede-fidelidade').checked = this.dataset.fidelidade === '1';
            modalRede.style.display = 'flex';
        });
    });
    modalRede.querySelectorAll('.close-modal-btn, .cancel-btn').forEach(btn => {
        btn.onclick = () => modalRede.style.display = 'none';
    });

    const btnSalvarCfgRede = document.getElementById('btnSalvarConfigRede');
    if (btnSalvarCfgRede) {
        btnSalvarCfgRede.onclick = function() {
            const id = document.getElementById('cfg-rede-id').value;
            const nome = document.getElementById('cfg-rede-nome').value;
            const compCli = document.getElementById('cfg-rede-clientes').checked ? 1 : 0;
            const compFid = document.getElementById('cfg-rede-fidelidade').checked ? 1 : 0;
            
            if (!nome.trim()) { alert('Informe o nome da rede.'); return; }

            const origText = this.innerText;
            this.innerText = 'Salvando...';
            this.disabled = true;

            fetch('api_admin_config_rede.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ rede_id: id, nome: nome, compartilhar_clientes: compCli, compartilhar_fidelidade: compFid })
            })
            .then(res => res.json())
            .then(res => {
                alert(res.message);
                if (res.success) location.reload();
                else { this.disabled = false; this.innerText = origText; }
            })
            .catch(err => {
                console.error(err);
                alert('Erro de comunicação com o servidor.');
                this.disabled = false;
                this.innerText = origText;
            });
        };
    }

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
        const compCliEl = document.getElementById('inst-comp-clientes');
        const compFidEl = document.getElementById('inst-comp-fidelidade');

        const data = {
            nome_fantasia: document.getElementById('inst-nome').value,
            cnpj: document.getElementById('inst-cnpj').value,
            gerente_nome: document.getElementById('manager-nome').value,
            gerente_email: document.getElementById('manager-email').value,
            gerente_senha: document.getElementById('manager-senha').value,
            criar_rede: selTipo.value === 'nova_rede',
            rede_id: selTipo.value.startsWith('rede:') ? parseInt(selTipo.value.split(':')[1], 10) : null,
            nome_rede: document.getElementById('inst-nome-rede').value,
            nome_unidade: document.getElementById('inst-nome-unidade').value,
            cidade: document.getElementById('inst-cidade').value,
            uf: document.getElementById('inst-uf').value,
            gerente_geral: document.getElementById('manager-geral').checked,
            compartilhar_clientes: compCliEl ? (compCliEl.checked ? 1 : 0) : 1,
            compartilhar_fidelidade: compFidEl ? (compFidEl.checked ? 1 : 0) : 1
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