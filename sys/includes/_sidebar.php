<link rel="stylesheet" href="style.css">

<?php
// Evita avisos de variável indefinida
$paginaAtiva = $paginaAtiva ?? '';
$cargo = $_SESSION['usuario_cargo'] ?? '';
$usuario_id = $_SESSION['usuario_id'] ?? 0;

// Busca as permissões fresquinhas do banco de dados (Acesso em tempo real)
$permissoes = [];
if ($cargo !== 'Admin' && $usuario_id > 0) {
    try {
        $stmtPerm = $pdo->prepare("SELECT permissoes FROM usuarios WHERE id = ?");
        $stmtPerm->execute([$usuario_id]);
        $jsonPerm = $stmtPerm->fetchColumn();
        if ($jsonPerm) {
            $permissoes = json_decode($jsonPerm, true);
        }
    } catch (PDOException $e) {
        // Silencia erro no menu, assume sem permissões extras
    }
}

// Função auxiliar para verificar permissões modulares
function temPermissao($modulo, $permissoes, $cargo) {
    if ($cargo === 'Gerente') return true; // Gerente sempre vê tudo na loja dele
    return isset($permissoes[$modulo]) && $permissoes[$modulo] == true;
}
?>

<aside class="sidebar">
    <div class="sidebar-header">
        <i class="fas fa-paw"></i>
        <span>
            <?php echo ($cargo === 'Admin') ? 'Admin SaaS' : 'PetCRM'; ?>
        </span>
    </div>

    <nav class="sidebar-nav">

        <?php if ($cargo === 'Admin'): ?>
            
            <a href="dashboard_super.php" class="nav-item <?php echo ($paginaAtiva === 'dashboard') ? 'active' : ''; ?>">
                <i class="fas fa-chart-pie"></i> Dashboard SaaS
            </a>

            <a href="super_admin_clientes.php" class="nav-item <?php echo ($paginaAtiva === 'clientes') ? 'active' : ''; ?>">
                <i class="fas fa-store"></i> Gerenciar Lojas
            </a>

            <a href="#" class="nav-item">
                <i class="fas fa-file-invoice-dollar"></i> Faturas & Planos
            </a>

        <?php else: ?>
            
            <a href="<?php echo ($cargo === 'Gerente') ? 'dashboard_gerente.php' : 'dashboard.php'; ?>" 
               class="nav-item <?php echo ($paginaAtiva === 'dashboard') ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>

            <?php if (temPermissao('agenda', $permissoes, $cargo)): ?>
                <a href="agenda.php" class="nav-item <?php echo ($paginaAtiva === 'agenda') ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-alt"></i> Agenda
                </a>
            <?php endif; ?>

            <?php if (temPermissao('clientes', $permissoes, $cargo)): ?>
                <a href="clientes.php" class="nav-item <?php echo ($paginaAtiva === 'clientes') ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i> Clientes & Pets
                </a>
            <?php endif; ?>

            <?php if (temPermissao('financeiro', $permissoes, $cargo)): ?>
                <a href="vendas.php" class="nav-item <?php echo ($paginaAtiva === 'vendas') ? 'active' : ''; ?>">
                    <i class="fas fa-shopping-cart"></i> Caixa (PDV)
                </a>
            <?php endif; ?>

            <?php if (temPermissao('estoque', $permissoes, $cargo)): ?>
                <a href="painel_estoque.php" class="nav-item <?php echo ($paginaAtiva === 'painel_estoque') ? 'active' : ''; ?>">
                    <i class="fas fa-clipboard-list"></i> Tarefas do Estoque
                </a>
            <?php endif; ?> <?php if ($cargo === 'Gerente'): ?>
                <div style="border-top: 1px solid rgba(255,255,255,0.1); margin: 10px 0;"></div>
                <small style="padding-left: 25px; color: #95a5a6; text-transform: uppercase; font-size: 0.7rem; font-weight: bold; letter-spacing: 1px;">Gestão</small>

                <a href="relatorio_financeiro.php" class="nav-item <?php echo ($paginaAtiva === 'relatorios') ? 'active' : ''; ?>">
                    <i class="fas fa-chart-line"></i> Relatório Financeiro
                </a>

                <a href="usuarios.php" class="nav-item <?php echo ($paginaAtiva === 'equipe') ? 'active' : ''; ?>">
                    <i class="fas fa-id-badge"></i> Minha Equipe
                </a>
                
                <a href="produtos.php" class="nav-item <?php echo ($paginaAtiva === 'produtos') ? 'active' : ''; ?>">
                    <i class="fas fa-box-open"></i> Produtos
                </a>

                <a href="#" class="nav-item">
                    <i class="fas fa-cog"></i> Config. da Loja
                </a>
            <?php endif; ?>


            <?php if ($cargo === 'Veterinário'): ?>
                <div style="border-top: 1px solid rgba(255,255,255,0.1); margin: 10px 0;"></div>
                <small style="padding-left: 25px; color: #95a5a6; text-transform: uppercase; font-size: 0.7rem; font-weight: bold; letter-spacing: 1px;">Clínica</small>
                
                <a href="#" class="nav-item">
                    <i class="fas fa-notes-medical"></i> Prontuários
                </a>
            <?php endif; ?>

        <?php endif; ?>

        <a href="logout.php" class="nav-item" style="margin-top: auto; border-top: 1px solid rgba(255,255,255,0.1);">
            <i class="fas fa-sign-out-alt"></i> Sair
        </a>
        
    </nav>
</aside>