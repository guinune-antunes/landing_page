<link rel="stylesheet" href="style.css">

<aside class="sidebar">
    <div class="sidebar-header">
        <i class="fas fa-paw"></i>
        <span>
            <?php 
            // Se a sessão não existir, mostra um nome padrão para evitar erro
            $cargo = isset($_SESSION['usuario_cargo']) ? $_SESSION['usuario_cargo'] : '';
            echo ($cargo === 'super_admin') ? 'Admin SaaS' : 'PetCRM'; 
            ?>
        </span>
    </div>

    <nav class="sidebar-nav">

        <?php if ($cargo === 'super_admin'): ?>
            
            <a href="dashboard.php" class="nav-item <?php echo ($paginaAtiva === 'super_admin_dash') ? 'active' : ''; ?>">
                <i class="fas fa-chart-pie"></i> Dashboard SaaS
            </a>

            <a href="super_admin.php" class="nav-item <?php echo ($paginaAtiva === 'super_admin_lojas') ? 'active' : ''; ?>">
                <i class="fas fa-store"></i> Gerenciar Lojas
            </a>

            <a href="#" class="nav-item">
                <i class="fas fa-file-invoice-dollar"></i> Faturas & Planos
            </a>

            <a href="#" class="nav-item">
                <i class="fas fa-cogs"></i> Config. Sistema
            </a>

        <?php else: ?>
            
            <a href="dashboard.php" class="nav-item <?php echo ($paginaAtiva === 'dashboard' || $paginaAtiva === 'atendente') ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>

            <a href="agenda.php" class="nav-item <?php echo ($paginaAtiva === 'agenda') ? 'active' : ''; ?>">
                <i class="fas fa-calendar-alt"></i> Agenda
            </a>

            <?php if ($cargo !== 'banho_tosa'): ?>
                <a href="clientes.php" class="nav-item <?php echo ($paginaAtiva === 'clientes') ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i> Clientes
                </a>
            <?php endif; ?>

            <?php if ($cargo === 'atendente' || $cargo === 'gerente' || $cargo === 'admin'): ?>
                <a href="vendas.php" class="nav-item <?php echo ($paginaAtiva === 'vendas') ? 'active' : ''; ?>">
                    <i class="fas fa-shopping-cart"></i> Caixa (PDV)
                </a>
            <?php endif; ?>

            <?php if ($cargo === 'gerente' || $cargo === 'admin'): ?>
                
                <div style="border-top: 1px solid rgba(255,255,255,0.1); margin: 10px 0;"></div>
                <small style="padding-left: 25px; color: #95a5a6; text-transform: uppercase; font-size: 0.7rem;">Gerência</small>

                <a href="estoque.php" class="nav-item <?php echo ($paginaAtiva === 'estoque') ? 'active' : ''; ?>">
                    <i class="fas fa-boxes"></i> Estoque
                </a>

                <a href="#" class="nav-item <?php echo ($paginaAtiva === 'relatorios') ? 'active' : ''; ?>">
                    <i class="fas fa-chart-line"></i> Relatórios
                </a>

                <a href="equipe.php" class="nav-item <?php echo ($paginaAtiva === 'equipe') ? 'active' : ''; ?>">
                    <i class="fas fa-id-badge"></i> Minha Equipe
                </a>

                <a href="#" class="nav-item <?php echo ($paginaAtiva === 'configuracoes') ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i> Config. da Loja
                </a>

            <?php endif; ?>

            <?php if ($cargo === 'veterinario'): ?>
                <a href="estoque.php" class="nav-item <?php echo ($paginaAtiva === 'estoque') ? 'active' : ''; ?>">
                    <i class="fas fa-medkit"></i> Estoque Médico
                </a>
            <?php endif; ?>

        <?php endif; ?>

        <a href="logout.php" class="nav-item" style="margin-top: auto; border-top: 1px solid rgba(255,255,255,0.1);">
            <i class="fas fa-sign-out-alt"></i> Sair
        </a>
        
    </nav>
</aside>