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

// 2. Busca os Usuários desta Instituição
$stmt_users = $pdo->prepare("SELECT id, nome, email, cargo, ativo FROM usuarios WHERE instituicao_id = ? ORDER BY cargo, nome");
$stmt_users->execute([$id_loja]);
$usuarios_loja = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Gerenciar: ' . htmlspecialchars($loja['nome_fantasia']);
$paginaAtiva = 'super_admin_lojas';
include 'includes/_head.php';
?>

<?php include 'includes/_sidebar.php'; ?>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; ?>

        <main class="main-content">
            <div class="page-header" style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <a href="super_admin_lojas.php" style="color: #777; text-decoration: none; font-size: 0.9rem;"><i class="fas fa-arrow-left"></i> Voltar</a>
                    <h1 class="main-title" style="margin-top: 5px;">Gestão: <?php echo htmlspecialchars($loja['nome_fantasia']); ?></h1>
                    <p style="color: #777;">CNPJ: <?php echo htmlspecialchars($loja['cnpj']); ?></p>
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
                            <button class="btn btn-sm btn-reset-senha" 
                                    data-id="<?php echo $user['id']; ?>" 
                                    data-nome="<?php echo htmlspecialchars($user['nome']); ?>"
                                    style="background: #e74c3c; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer;">
                                <i class="fas fa-key"></i> Resetar Senha
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

    <div> <div class="widget-card" style="margin-bottom: 20px;">
            <h3 style="margin-bottom: 15px; border-bottom: 2px solid #eee; padding-bottom: 10px;">
                <i class="fas fa-database" style="color: #f39c12;"></i> Portabilidade de Dados
            </h3>
            <p style="font-size: 0.9rem; color: #555; margin-bottom: 20px;">
                Em caso de encerramento de contrato, extraia a base de tutores e pets desta instituição.
            </p>
            
            <a href="exportar_dados_loja.php?id=<?php echo $loja['id']; ?>" class="btn" style="background: #27ae60; color: white; display: block; text-align: center; text-decoration: none; padding: 10px; border-radius: 5px;">
                <i class="fas fa-file-excel"></i> Exportar Dados (CSV)
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
        </main>
    </div>
</div>

<script>
// Lógica de Reset de Senha pelo Super Admin
document.querySelectorAll('.btn-reset-senha').forEach(btn => {
    btn.addEventListener('click', function() {
        const userId = this.getAttribute('data-id');
        const userName = this.getAttribute('data-nome');

        if(confirm(`ATENÇÃO: Você está prestes a resetar a senha de ${userName}. Deseja continuar?`)) {
            
            this.innerText = "Gerando...";
            this.disabled = true;

            fetch('api_admin_reset_senha.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ id: userId })
            })
            .then(res => res.json())
            .then(res => {
                if(res.success) {
                    prompt("Senha temporária gerada! Copie e envie para o cliente:", res.senha_temp);
                } else {
                    alert(res.message);
                }
                this.innerHTML = '<i class="fas fa-key"></i> Resetar Senha';
                this.disabled = false;
            })
            .catch(err => {
                alert("Erro de conexão.");
                this.innerHTML = '<i class="fas fa-key"></i> Resetar Senha';
                this.disabled = false;
            });
        }
    });
});
</script>

<?php include 'includes/_footer.php'; ?>