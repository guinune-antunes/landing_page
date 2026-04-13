<?php
// 1. Tranca a página
require 'includes/verifica_login.php';

$cargo = $_SESSION['usuario_cargo'] ?? '';

// 2. Roteador: Se for chefe, joga para as telas avançadas
if ($cargo === 'Admin') {
    header("Location: dashboard_super.php");
    exit;
} elseif ($cargo === 'Gerente') {
    header("Location: dashboard_gerente.php");
    exit;
}

// =========================================================================
// 3. DAQUI PARA BAIXO É A TELA DOS FUNCIONÁRIOS (Atendente, Tosador, Vet)
// =========================================================================

$pageTitle = 'Dashboard da Loja';
$paginaAtiva = 'dashboard';
include 'includes/_head.php';
require 'conexao.php';
?>

<?php include 'includes/_sidebar.php'; ?>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; ?>

        <main class="main-content">
            <div class="page-header" style="margin-bottom: 30px;">
                <h1 class="main-title">Olá, <?php echo htmlspecialchars($_SESSION['usuario_nome']); ?>!</h1>
                <p style="color: #777; margin-top: 5px;">Seja bem-vindo ao seu painel. Tenha um excelente dia de trabalho!</p>
            </div>

            <div class="widget-card" style="text-align: center; padding: 60px 20px; border-top: 4px solid var(--color-primary);">
                <div style="background: rgba(26, 188, 156, 0.1); width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;">
                    <i class="fas fa-paw" style="font-size: 2.5rem; color: var(--color-primary);"></i>
                </div>
                
                <h2 style="color: #2c3e50; margin-bottom: 10px;">Sistema Operacional PetCRM</h2>
                <p style="color: #7f8c8d; max-width: 500px; margin: 0 auto; line-height: 1.6;">
                    Você está logado como <strong><?php echo htmlspecialchars($cargo); ?></strong>. 
                    Utilize o menu lateral à esquerda para acessar a Agenda, o cadastro de Clientes & Pets ou o PDV do Caixa (de acordo com as suas permissões).
                </p>
                
                <div style="margin-top: 30px;">
                    <a href="agenda.php" class="btn btn-primary" style="margin-right: 10px;">
                        <i class="fas fa-calendar-alt"></i> Ir para a Agenda
                    </a>
                </div>
            </div>
        </main>
    </div>
</div>

<?php include 'includes/_footer.php'; ?>