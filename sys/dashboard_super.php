<?php
// Garante que o usuário está logado
require 'includes/verifica_login.php';

// PROTEÇÃO DE ACESSO: Só fica nesta página se for 'Admin'
if ($_SESSION['usuario_cargo'] !== 'Admin') {
    if ($_SESSION['usuario_cargo'] === 'Gerente') {
        header("Location: dashboard_gerente.php");
    } else {
        header("Location: dashboard.php");
    }
    exit;
}

// SOLUÇÃO DO ERRO: Faltava incluir a conexão com o banco de dados!
require 'conexao.php'; 

try {
    // --- 1. BUSCAR MÉTRICAS DO SISTEMA ---
    // Usamos um try/catch para evitar que a página quebre se a tabela 'instituicoes' ainda não existir
    
    // Total de Empresas Ativas
    $total_empresas = $pdo->query("SELECT COUNT(*) FROM instituicoes")->fetchColumn();

    // Total de Usuários no sistema todo
    $total_usuarios = $pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();

    // Novas empresas este mês (Assumindo que você criará uma coluna data_criacao)
    $novas_mes = $pdo->query("SELECT COUNT(*) FROM instituicoes")->fetchColumn(); // Simplificado temporariamente

} catch (PDOException $e) {
    // Se a tabela não existir, coloca zero para não quebrar a tela
    $total_empresas = 0;
    $total_usuarios = 0;
    $novas_mes = 0;
    $erro_banco = "Atenção: A tabela 'instituicoes' ainda não existe no banco de dados.";
}
?>

<?php include 'includes/_sidebar.php'; ?>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; ?>

        <main class="main-content">
            
            <div class="page-header">
                <div>
                    <h1 class="main-title">Visão Geral do Sistema</h1>
                    <p style="color: #777;">Bem-vindo à administração do seu software SaaS.</p>
                </div>
                <div class="page-controls">
                    <button class="btn btn-primary" id="btn-nova-empresa">
                        <i class="fas fa-plus-circle"></i> Nova Instituição
                    </button>
                </div>
            </div>

            <div class="kpi-widgets">
                <div class="widget-card kpi-card">
                    <div class="card-icon blue"><i class="fas fa-store"></i></div>
                    <div class="card-content">
                        <span class="card-value"><?php echo $total_empresas; ?></span>
                        <span class="card-label">Pet Shops Ativos</span>
                    </div>
                </div>

                <div class="widget-card kpi-card">
                    <div class="card-icon green"><i class="fas fa-users"></i></div>
                    <div class="card-content">
                        <span class="card-value"><?php echo $total_usuarios; ?></span>
                        <span class="card-label">Usuários Totais</span>
                    </div>
                </div>

                <div class="widget-card kpi-card">
                    <div class="card-icon purple"><i class="fas fa-chart-line"></i></div>
                    <div class="card-content">
                        <span class="card-value">+<?php echo $novas_mes; ?></span>
                        <span class="card-label">Novos este Mês</span>
                    </div>
                </div>
            </div>

            <div class="widget-card" style="margin-top: 20px; text-align: center; padding: 40px;">
                <i class="fas fa-rocket" style="font-size: 3rem; color: #e0e0e0; margin-bottom: 15px;"></i>
                <h3>Ações Rápidas</h3>
                <p style="color: #777;">Gerencie seus clientes ou configure os planos do sistema.</p>
                <br>
                <a href="super_admin_clientes.php" class="btn btn-secondary">Ver Lista de Clientes</a>
            </div>

        </main>
    </div>
</div>

<div class="modal-overlay" id="inst-modal" style="display: none;">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h2>Cadastrar Novo Cliente (Pet Shop)</h2>
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

                <h4 style="margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:5px; margin-top:20px;">Dados do Gerente (Acesso)</h4>
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
            <button class="btn btn-primary" id="saveInstBtn">Criar & Ir para Clientes</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Lógica do Modal
    const modal = document.getElementById('inst-modal');
    const btnAdd = document.getElementById('btn-nova-empresa');
    const btnSave = document.getElementById('saveInstBtn');
    const closeBtns = modal.querySelectorAll('.close-modal-btn, .cancel-btn');

    if(btnAdd) btnAdd.onclick = () => modal.style.display = 'flex';
    
    closeBtns.forEach(btn => btn.onclick = () => modal.style.display = 'none');

    // Salvar Instituição
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

        const originalText = btnSave.innerText;
        btnSave.innerText = "Criando...";
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
                // REDIRECIONA PARA A TELA DE CLIENTES DO SOFTWARE
                window.location.href = 'super_admin_clientes.php';
            } else {
                btnSave.innerText = originalText;
                btnSave.disabled = false;
            }
        })
        .catch(err => {
            console.error(err);
            alert("Erro de conexão.");
            btnSave.innerText = originalText;
            btnSave.disabled = false;
        });
    };
});
</script>

<?php include 'includes/_footer.php'; ?>