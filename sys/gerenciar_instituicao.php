<?php
require 'includes/verifica_login.php';

// Proteção Máxima: Apenas o dono do SaaS (Admin) acessa isso
if ($_SESSION['usuario_cargo'] !== 'Admin') {
    header("Location: dashboard.php");
    exit;
}

require 'conexao.php';

$id_loja = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id_loja) {
    die("ID da instituição não fornecido.");
}

// 1. Busca os dados da Instituição
$stmt = $pdo->prepare("SELECT * FROM instituicoes WHERE id = ?");
$stmt->execute([$id_loja]);
$loja = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$loja) die("Instituição não encontrada.");

// 1.1 Busca dados da Rede (se houver)
$rede_info = null;
$unidades_rede = [];
if (!empty($loja['rede_id'])) {
    $stRede = $pdo->prepare("SELECT * FROM redes WHERE id = ?");
    $stRede->execute([$loja['rede_id']]);
    $rede_info = $stRede->fetch(PDO::FETCH_ASSOC);

    $stUnits = $pdo->prepare("SELECT id, nome_fantasia, nome_unidade, cidade, uf, matriz FROM instituicoes WHERE rede_id = ? ORDER BY matriz DESC, id ASC");
    $stUnits->execute([$loja['rede_id']]);
    $unidades_rede = $stUnits->fetchAll(PDO::FETCH_ASSOC);
}

// Parse dos módulos contratados
$modulos = [];
if (!empty($loja['modulos_contratados'])) {
    $modulos = json_decode($loja['modulos_contratados'], true);
}
// Helper
function temMod($m, $modulos) { return isset($modulos[$m]) && $modulos[$m] == true; }

// 2. Busca os Usuários desta Instituição
$stmt_users = $pdo->prepare("SELECT id, nome, email, cargo, ativo FROM usuarios WHERE instituicao_id = ? ORDER BY cargo, nome");
$stmt_users->execute([$id_loja]);
$usuarios_loja = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Gerenciar: ' . htmlspecialchars($loja['nome_fantasia']);
$paginaAtiva = 'clientes';
include 'includes/_head.php';
?>

<?php include 'includes/_sidebar.php'; ?>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; ?>

        <main class="main-content">
            <div class="page-header" style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <a href="super_admin_clientes.php" style="color: #777; text-decoration: none; font-size: 0.9rem;"><i class="fas fa-arrow-left"></i> Voltar</a>
                    <h1 class="main-title" style="margin-top: 5px;">Gestão: <?php echo htmlspecialchars($loja['nome_fantasia']); ?></h1>
                    <p style="color: #777;">CNPJ: <?php echo htmlspecialchars($loja['cnpj'] ?? 'Não informado'); ?></p>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; align-items: start;">
                
    <div class="widget-card">
        <h3 style="margin-bottom: 15px; border-bottom: 2px solid #eee; padding-bottom: 10px;">
            <i class="fas fa-users" style="color: var(--color-primary);"></i> Usuários da Instituição
        </h3>
        <table class="data-table" style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="text-align: left; border-bottom: 2px solid #eee;">
                    <th style="padding: 10px;">Nome</th>
                    <th style="padding: 10px;">Cargo</th>
                    <th style="padding: 10px; text-align: right;">Ações de Segurança</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($usuarios_loja as $user): ?>
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 10px;">
                            <strong><?php echo htmlspecialchars($user['nome']); ?></strong><br>
                            <small style="color:#777;"><?php echo htmlspecialchars($user['email']); ?></small>
                        </td>
                        <td style="padding: 10px;">
                            <span style="background: #e0e0e0; padding: 3px 8px; border-radius: 12px; font-size: 0.85rem;">
                                <?php echo htmlspecialchars($user['cargo']); ?>
                            </span>
                        </td>
                        <td style="padding: 10px; text-align: right;">
                            <button class="btn btn-sm btn-alterar-senha" 
                                    data-id="<?php echo $user['id']; ?>" 
                                    data-nome="<?php echo htmlspecialchars($user['nome']); ?>"
                                    data-email="<?php echo htmlspecialchars($user['email']); ?>"
                                    data-cargo="<?php echo htmlspecialchars($user['cargo']); ?>"
                                    style="background: #e74c3c; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer;">
                                <i class="fas fa-key"></i> Alterar Senha
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if(empty($usuarios_loja)): ?>
                    <tr><td colspan="3" style="text-align:center; padding:20px;">Nenhum usuário encontrado.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- GESTÃO DE MÓDULOS -->
    <div class="widget-card" style="margin-top: 20px;">
        <h3 style="margin-bottom: 15px; border-bottom: 2px solid #eee; padding-bottom: 10px;">
            <i class="fas fa-cubes" style="color: #9b59b6;"></i> Módulos Contratados
        </h3>
        <p style="font-size: 0.9rem; color: #555; margin-bottom: 20px;">
            Ative ou desative as funcionalidades para esta instituição. Isso refletirá imediatamente no menu lateral da equipe da loja.
        </p>

        <form id="form-modulos">
            <input type="hidden" id="mod-loja-id" value="<?php echo $loja['id']; ?>">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                    <input type="checkbox" class="chk-mod" value="agenda" <?php echo temMod('agenda', $modulos) ? 'checked' : ''; ?>>
                    <span><i class="fas fa-calendar-alt"></i> Agenda</span>
                </label>
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                    <input type="checkbox" class="chk-mod" value="clientes" <?php echo temMod('clientes', $modulos) ? 'checked' : ''; ?>>
                    <span><i class="fas fa-users"></i> Clientes & Pets</span>
                </label>
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                    <input type="checkbox" class="chk-mod" value="financeiro" <?php echo temMod('financeiro', $modulos) ? 'checked' : ''; ?>>
                    <span><i class="fas fa-shopping-cart"></i> Caixa / PDV</span>
                </label>
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                    <input type="checkbox" class="chk-mod" value="estoque" <?php echo temMod('estoque', $modulos) ? 'checked' : ''; ?>>
                    <span><i class="fas fa-box"></i> Estoque & Produtos</span>
                </label>
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                    <input type="checkbox" class="chk-mod" value="crm_vendas" <?php echo temMod('crm_vendas', $modulos) ? 'checked' : ''; ?>>
                    <span><i class="fas fa-headset"></i> CRM Vendas Ativas</span>
                </label>
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                    <input type="checkbox" class="chk-mod" value="relatorios" <?php echo temMod('relatorios', $modulos) ? 'checked' : ''; ?>>
                    <span><i class="fas fa-chart-line"></i> Relatórios Gerenciais</span>
                </label>
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                    <input type="checkbox" class="chk-mod" value="equipe" <?php echo temMod('equipe', $modulos) ? 'checked' : ''; ?>>
                    <span><i class="fas fa-id-badge"></i> Gestão de Equipe</span>
                </label>
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                    <input type="checkbox" class="chk-mod" value="clinica" <?php echo temMod('clinica', $modulos) ? 'checked' : ''; ?>>
                    <span><i class="fas fa-notes-medical"></i> Clínica Veterinária</span>
                </label>
            </div>

            <button type="button" id="btn-salvar-modulos" class="btn btn-primary" style="width: 100%; background: #8e44ad; border-color: #8e44ad;">
                <i class="fas fa-save"></i> Salvar Módulos
            </button>
        </form>
    </div>

    <div>
        <?php if ($rede_info): ?>
            <!-- GESTÃO DA REDE MULTI-UNIDADES -->
            <div class="widget-card" style="margin-bottom: 20px; border-left: 4px solid #2980b9;">
                <h3 style="margin-bottom: 12px; border-bottom: 2px solid #eee; padding-bottom: 8px; display:flex; justify-content:space-between; align-items:center;">
                    <span><i class="fas fa-network-wired" style="color: #2980b9;"></i> <?php echo htmlspecialchars($rede_info['nome']); ?></span>
                    <span style="font-size:0.75rem; background:#e8f4fd; color:#2980b9; padding:2px 8px; border-radius:10px;">
                        <?php echo !empty($loja['matriz']) ? 'Matriz da Rede' : 'Unidade Filial'; ?>
                    </span>
                </h3>
                <p style="font-size: 0.85rem; color: #555; margin-bottom: 12px;">
                    Esta loja integra uma rede com <strong><?php echo count($unidades_rede); ?> unidade(s)</strong> cadastradas.
                </p>

                <!-- Lista rápida de unidades -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:8px 12px; margin-bottom:12px;">
                    <span style="font-size:0.75rem; font-weight:600; color:#64748b; text-transform:uppercase;">Unidades da Rede:</span>
                    <ul style="list-style:none; padding:0; margin:6px 0 0 0; font-size:0.85rem;">
                        <?php foreach ($unidades_rede as $u): ?>
                            <li style="padding:3px 0; border-bottom:1px dashed #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
                                <span>
                                    <?php if ($u['id'] == $loja['id']): ?><strong><?php endif; ?>
                                    <?php echo htmlspecialchars($u['nome_fantasia']); ?>
                                    <?php if (!empty($u['cidade'])): ?>
                                        <small style="color:#777;">(<?php echo htmlspecialchars($u['cidade'] . ($u['uf'] ? '/' . $u['uf'] : '')); ?>)</small>
                                    <?php endif; ?>
                                    <?php if ($u['id'] == $loja['id']): ?> <em style="color:#2980b9;">(Esta loja)</em></strong><?php endif; ?>
                                </span>
                                <?php if ($u['id'] != $loja['id']): ?>
                                    <a href="gerenciar_instituicao.php?id=<?php echo $u['id']; ?>" style="color:#3498db; font-size:0.8rem;" title="Gerenciar esta filial">
                                        <i class="fas fa-external-link-alt"></i>
                                    </a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Políticas da Rede -->
                <form id="form-rede-politicas" style="margin-top:10px;">
                    <input type="hidden" id="cfg-rede-id" value="<?php echo (int)$rede_info['id']; ?>">
                    <div class="form-group" style="margin-bottom:10px;">
                        <label style="font-size:0.85rem;">Nome da Rede</label>
                        <input type="text" id="cfg-rede-nome" value="<?php echo htmlspecialchars($rede_info['nome']); ?>" style="width:100%; padding:6px; font-size:0.9rem;">
                    </div>
                    <div style="display:flex; flex-direction:column; gap:8px; margin-bottom:12px; font-size:0.85rem;">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="checkbox" id="cfg-rede-clientes" <?php echo !empty($rede_info['compartilhar_clientes']) ? 'checked' : ''; ?>>
                            <span>Compartilhar Clientes entre todas as lojas</span>
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="checkbox" id="cfg-rede-fidelidade" <?php echo !empty($rede_info['compartilhar_fidelidade']) ? 'checked' : ''; ?>>
                            <span>Compartilhar Programa de Fidelidade & Pontos</span>
                        </label>
                    </div>
                    <button type="button" id="btn-salvar-rede" class="btn btn-primary btn-sm" style="width:100%;">
                        <i class="fas fa-save"></i> Salvar Políticas da Rede
                    </button>
                </form>
            </div>
        <?php else: ?>
            <!-- OPÇÃO PARA TRANSFORMAR EM REDE -->
            <div class="widget-card" style="margin-bottom: 20px; border-left: 4px solid #8e44ad;">
                <h3 style="margin-bottom: 10px; border-bottom: 2px solid #eee; padding-bottom: 8px;">
                    <i class="fas fa-network-wired" style="color: #8e44ad;"></i> Multi-Unidades
                </h3>
                <p style="font-size: 0.85rem; color: #555; margin-bottom: 12px;">
                    Esta loja ainda opera isoladamente. Transforme-a em uma Rede para abrir filiais (ex.: Filial Curitiba, São Paulo) com gestão centralizada.
                </p>
                <button type="button" id="btn-criar-rede-isolada" class="btn btn-sm" 
                        data-id="<?php echo (int)$loja['id']; ?>" 
                        data-nome="<?php echo htmlspecialchars($loja['nome_fantasia']); ?>"
                        style="background: #8e44ad; color: white; border: none; padding: 8px 12px; border-radius: 4px; cursor: pointer; width: 100%;">
                    <i class="fas fa-plus-circle"></i> Criar Rede a partir desta loja
                </button>
            </div>
        <?php endif; ?>

        <div class="widget-card" style="margin-bottom: 20px;">
            <h3 style="margin-bottom: 15px; border-bottom: 2px solid #eee; padding-bottom: 10px;">
                <i class="fas fa-database" style="color: #f39c12;"></i> Portabilidade de Dados
            </h3>
            <p style="font-size: 0.9rem; color: #555; margin-bottom: 20px;">
                Em caso de encerramento de contrato, extraia a base de tutores e pets desta instituição.
            </p>
            
            <a href="exportar_dados_loja.php?id=<?php echo $loja['id']; ?>" class="btn" style="background: #27ae60; color: white; display: block; text-align: center; text-decoration: none; padding: 10px; border-radius: 5px;">
                <i class="fas fa-file-excel"></i> Exportar Dados (CSV)
            </a>
            <a href="exportar_produtos_loja.php?id=<?php echo $loja['id']; ?>" class="btn" style="background: #e67e22; color: white; display: block; text-align: center; text-decoration: none; padding: 10px; border-radius: 5px; margin-top: 10px;">
                <i class="fas fa-box-open"></i> Exportar Produtos (CSV)
            </a>
        </div>

        <div class="widget-card">
            <h3 style="margin-bottom: 15px; border-bottom: 2px solid #eee; padding-bottom: 10px;">
                <i class="fas fa-file-import" style="color: #3498db;"></i> Onboarding de Clientes
            </h3>
            <p style="font-size: 0.9rem; color: #555; margin-bottom: 15px;">
                Faça o upload de uma planilha CSV com os clientes da loja. Ordem das colunas: <br>
                <small style="color: #888;"><em>Tutor; CPF; Telefone; Email; CEP; Logradouro; Número; Bairro; Cidade; Estado; Pet; Espécie; Raça</em></small>
            </p>
            
            <form action="api_admin_importar_dados.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="instituicao_id" value="<?php echo $loja['id']; ?>">
                
                <div style="margin-bottom: 15px;">
                    <input type="file" name="arquivo_csv" accept=".csv" required style="width: 100%; font-size: 0.9rem; padding: 10px; border: 1px dashed #ccc; border-radius: 5px; background: #fdfdfd;">
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-upload"></i> Importar Planilha
                </button>
            </form>
        </div>
        <h3 style="margin-top: 30px; margin-bottom: 15px; border-bottom: 2px solid #eee; padding-bottom: 10px;">
                <i class="fas fa-boxes" style="color: #e67e22;"></i> Onboarding de Estoque
            </h3>
            <p style="font-size: 0.9rem; color: #555; margin-bottom: 15px;">
                Faça o upload do CSV de produtos. Ordem das colunas: <br>
                <small style="color: #888;"><em>Nome; Descrição; Código de Barras; Preço; Estoque; Peso (KG)</em></small>
            </p>
            
            <form action="api_admin_importar_produtos.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="instituicao_id" value="<?php echo $loja['id']; ?>">
                <div style="margin-bottom: 15px;">
                    <input type="file" name="arquivo_csv_produtos" accept=".csv" required style="width: 100%; font-size: 0.9rem; padding: 10px; border: 1px dashed #ccc; border-radius: 5px; background: #fdfdfd;">
                </div>
                <button type="submit" class="btn" style="background: #e67e22; color: white; width: 100%; border: none; padding: 10px; border-radius: 5px; cursor: pointer;">
                    <i class="fas fa-upload"></i> Importar Produtos
                </button>
            </form>
        </main>
    </div>
</div>

<!-- MODAL ALTERAR SENHA DO USUÁRIO -->
<div class="modal-overlay" id="modal-alterar-senha" style="display: none;">
    <div class="modal-content" style="max-width: 480px;">
        <div class="modal-header">
            <h2><i class="fas fa-key" style="color: #e74c3c;"></i> Alterar Senha de Acesso</h2>
            <button class="close-modal-btn">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="pwd-user-id">
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:12px; margin-bottom:15px;">
                <div style="font-size:0.95rem; font-weight:600; color:#1e293b;" id="pwd-user-nome">Nome do Usuário</div>
                <div style="font-size:0.85rem; color:#64748b;" id="pwd-user-detalhes">email@loja.com • Cargo</div>
            </div>

            <div class="form-group" style="margin-bottom:12px;">
                <label>Nova Senha *</label>
                <div style="position:relative;">
                    <input type="password" id="pwd-nova" required placeholder="Digite a nova senha desejada" style="width:100%; padding:10px 40px 10px 10px; border:1px solid #ddd; border-radius:5px;">
                    <button type="button" id="btn-toggle-pwd" style="position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; color:#777;" title="Mostrar/ocultar senha">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <div class="form-group" style="margin-bottom:12px;">
                <label>Confirmar Nova Senha *</label>
                <input type="password" id="pwd-confirma" required placeholder="Confirme a nova senha" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:5px;">
            </div>

            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                <button type="button" id="btn-gerar-senha-aleatoria" class="btn btn-sm" style="background:#f1f5f9; color:#334155; border:1px solid #cbd5e1; padding:6px 10px; border-radius:4px; font-size:0.8rem; cursor:pointer;">
                    <i class="fas fa-magic"></i> Gerar Senha Segura
                </button>
            </div>

            <div style="background:#fff7ed; border:1px solid #fed7aa; border-radius:6px; padding:10px; font-size:0.85rem; color:#9a3412;">
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:normal;">
                    <input type="checkbox" id="pwd-forcar-troca">
                    <span>Exigir que o usuário troque a senha no próximo login</span>
                </label>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary cancel-btn">Cancelar</button>
            <button class="btn btn-primary" id="btnSalvarNovaSenha" style="background:#e74c3c; border-color:#e74c3c;">Salvar Nova Senha</button>
        </div>
    </div>
</div>

<script>
// Lógica de Alteração de Senha pelo Super Admin
const modalSenha = document.getElementById('modal-alterar-senha');
const inputPwdNova = document.getElementById('pwd-nova');
const inputPwdConfirma = document.getElementById('pwd-confirma');
const chkForcarTroca = document.getElementById('pwd-forcar-troca');
const btnSalvarPwd = document.getElementById('btnSalvarNovaSenha');
const btnTogglePwd = document.getElementById('btn-toggle-pwd');
const btnGerarPwd = document.getElementById('btn-gerar-senha-aleatoria');

document.querySelectorAll('.btn-alterar-senha, .btn-reset-senha').forEach(btn => {
    btn.addEventListener('click', function() {
        const userId = this.getAttribute('data-id');
        const userName = this.getAttribute('data-nome') || 'Usuário';
        const userEmail = this.getAttribute('data-email') || '';
        const userCargo = this.getAttribute('data-cargo') || '';

        document.getElementById('pwd-user-id').value = userId;
        document.getElementById('pwd-user-nome').innerText = userName;
        document.getElementById('pwd-user-detalhes').innerText = (userEmail ? userEmail + ' • ' : '') + userCargo;
        inputPwdNova.value = '';
        inputPwdConfirma.value = '';
        inputPwdNova.type = 'password';
        chkForcarTroca.checked = false;

        modalSenha.style.display = 'flex';
        inputPwdNova.focus();
    });
});

if (modalSenha) {
    modalSenha.querySelectorAll('.close-modal-btn, .cancel-btn').forEach(btn => {
        btn.onclick = () => modalSenha.style.display = 'none';
    });
}

if (btnTogglePwd) {
    btnTogglePwd.onclick = function() {
        const isPass = inputPwdNova.type === 'password';
        inputPwdNova.type = isPass ? 'text' : 'password';
        inputPwdConfirma.type = isPass ? 'text' : 'password';
        this.querySelector('i').className = isPass ? 'fas fa-eye-slash' : 'fas fa-eye';
    };
}

if (btnGerarPwd) {
    btnGerarPwd.onclick = function() {
        const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#';
        let gerada = '';
        for (let i = 0; i < 8; i++) gerada += chars.charAt(Math.floor(Math.random() * chars.length));
        inputPwdNova.value = gerada;
        inputPwdConfirma.value = gerada;
        inputPwdNova.type = 'text';
        inputPwdConfirma.type = 'text';
        chkForcarTroca.checked = true;
        alert('Senha gerada: ' + gerada + '\n(Você pode copiá-la ou ajustá-la antes de salvar).');
    };
}

if (btnSalvarPwd) {
    btnSalvarPwd.onclick = function() {
        const userId = document.getElementById('pwd-user-id').value;
        const senha = inputPwdNova.value.trim();
        const confirma = inputPwdConfirma.value.trim();
        const forcar = chkForcarTroca.checked ? 1 : 0;

        if (senha.length < 4) {
            alert('A nova senha deve ter no mínimo 4 caracteres.');
            inputPwdNova.focus();
            return;
        }

        if (senha !== confirma) {
            alert('As senhas não conferem. Por favor, digite a mesma senha nos dois campos.');
            inputPwdConfirma.focus();
            return;
        }

        const origText = this.innerHTML;
        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
        this.disabled = true;

        fetch('api_admin_reset_senha.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ id: userId, nova_senha: senha, forcar_troca: forcar })
        })
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                alert('Senha alterada com sucesso para o usuário!\n' + (res.foi_gerada ? 'Nova senha: ' + res.senha : ''));
                modalSenha.style.display = 'none';
            } else {
                alert(res.message || 'Erro ao alterar senha.');
            }
        })
        .catch(err => {
            console.error(err);
            alert('Erro de comunicação com o servidor.');
        })
        .finally(() => {
            btnSalvarPwd.innerHTML = origText;
            btnSalvarPwd.disabled = false;
        });
    };
}

// Lógica de Salvar Módulos Contratados
document.getElementById('btn-salvar-modulos').addEventListener('click', function() {
    const id = document.getElementById('mod-loja-id').value;
    const checkboxes = document.querySelectorAll('.chk-mod');
    const modulosAtivos = [];
    
    checkboxes.forEach(chk => {
        if (chk.checked) modulosAtivos.push(chk.value);
    });

    const originalText = this.innerHTML;
    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
    this.disabled = true;

    fetch('api_admin_salvar_modulos.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ instituicao_id: id, modulos: modulosAtivos })
    })
    .then(res => res.json())
    .then(res => {
        alert(res.message);
        this.innerHTML = originalText;
        this.disabled = false;
    })
    .catch(err => {
        alert("Erro ao salvar módulos.");
        this.innerHTML = originalText;
        this.disabled = false;
    });
});

// Salvar Políticas da Rede a partir do painel da loja
const btnSalvarRede = document.getElementById('btn-salvar-rede');
if (btnSalvarRede) {
    btnSalvarRede.addEventListener('click', function() {
        const id = document.getElementById('cfg-rede-id').value;
        const nome = document.getElementById('cfg-rede-nome').value;
        const compCli = document.getElementById('cfg-rede-clientes').checked ? 1 : 0;
        const compFid = document.getElementById('cfg-rede-fidelidade').checked ? 1 : 0;

        if (!nome.trim()) { alert('Informe o nome da rede.'); return; }

        const origText = this.innerHTML;
        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
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
            else { this.disabled = false; this.innerHTML = origText; }
        })
        .catch(err => {
            alert('Erro de comunicação com o servidor.');
            this.disabled = false;
            this.innerHTML = origText;
        });
    });
}

// Criar rede a partir de uma loja isolada
const btnCriarRede = document.getElementById('btn-criar-rede-isolada');
if (btnCriarRede) {
    btnCriarRede.addEventListener('click', function() {
        const nomeRede = prompt('Digite o nome da nova Rede/Empresa:', this.dataset.nome);
        if (nomeRede === null) return;
        
        this.disabled = true;
        fetch('api_admin_criar_rede.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ id: this.dataset.id, nome_rede: nomeRede })
        })
        .then(res => res.json())
        .then(res => {
            alert(res.message);
            if (res.success) location.reload();
            else this.disabled = false;
        })
        .catch(err => {
            alert('Erro de comunicação com o servidor.');
            this.disabled = false;
        });
    });
}
</script>

<?php include 'includes/_footer.php'; ?>