

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
        // Silencia erro no menu
    }
}

// 2. Busca os Módulos Contratados da Instituição
$modulos_instituicao = [];
$id_loja = $_SESSION['instituicao_id'] ?? 0;
if ($id_loja > 0) {
    try {
        $stmtMod = $pdo->prepare("SELECT modulos_contratados FROM instituicoes WHERE id = ?");
        $stmtMod->execute([$id_loja]);
        $jsonMod = $stmtMod->fetchColumn();
        if ($jsonMod) {
            $modulos_instituicao = json_decode($jsonMod, true);
        }
    } catch (PDOException $e) {
        // Se a coluna ainda não existir, ignorar
    }
}

// Função auxiliar para verificar permissões modulares
if (!function_exists('temPermissao')) {
    function temPermissao($modulo, $permissoes, $cargo, $modulos_instituicao) {
        // 1. Regra Mestra: Se a loja não contratou o módulo, NINGUÉM acessa
        if (!empty($modulos_instituicao) && (!isset($modulos_instituicao[$modulo]) || $modulos_instituicao[$modulo] != true)) {
            return false;
        }

        // 2. Se a loja tem o módulo, verifica a permissão do usuário
        if ($cargo === 'Gerente') return true; 
        return isset($permissoes[$modulo]) && $permissoes[$modulo] == true;
    }
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

            <?php if ($cargo === 'Gerente' && count($_SESSION['unidades_permitidas'] ?? []) > 1): ?>
                <a href="dashboard_rede.php" class="nav-item <?php echo ($paginaAtiva === 'rede') ? 'active' : ''; ?>">
                    <i class="fas fa-network-wired"></i> Visão da Rede
                </a>
            <?php endif; ?>

            <?php if (temPermissao('agenda', $permissoes, $cargo, $modulos_instituicao)): ?>
                <a href="agenda.php" class="nav-item <?php echo ($paginaAtiva === 'agenda') ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-alt"></i> Agenda
                </a>
            <?php endif; ?>

            <?php if (temPermissao('clientes', $permissoes, $cargo, $modulos_instituicao)): ?>
                <a href="clientes.php" class="nav-item <?php echo ($paginaAtiva === 'clientes') ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i> Clientes & Pets
                </a>
            <?php endif; ?>
            
            <?php if (temPermissao('financeiro', $permissoes, $cargo, $modulos_instituicao)): ?>
                <a href="vendas.php" class="nav-item <?php echo ($paginaAtiva === 'vendas') ? 'active' : ''; ?>">
                    <i class="fas fa-shopping-cart"></i> Caixa (PDV)
                </a>
            <?php endif; ?>

            <?php if (temPermissao('estoque', $permissoes, $cargo, $modulos_instituicao)): ?>
                <a href="painel_estoque.php" class="nav-item <?php echo ($paginaAtiva === 'painel_estoque') ? 'active' : ''; ?>">
                    <i class="fas fa-clipboard-list"></i> Tarefas do Estoque
                </a>
            <?php endif; ?>

            <?php if (temPermissao('crm_vendas', $permissoes, $cargo, $modulos_instituicao)): ?>
                <a href="crm_vendas.php" class="nav-item <?php echo ($paginaAtiva === 'crm_vendas') ? 'active' : ''; ?>">
                    <i class="fas fa-headset"></i> CRM Vendas Ativas
                </a>
                <a href="fidelizacao.php" class="nav-item <?php echo ($paginaAtiva === 'fidelizacao') ? 'active' : ''; ?>" style="padding-left: 36px; font-size: 0.9rem; display:flex; align-items:center;">
                    <i class="fas fa-award" style="color: #f1c40f;"></i> Fidelização & Ofertas
                </a>
            <?php endif; ?>
            <?php if ($cargo === 'Gerente'): ?>
                <div style="border-top: 1px solid rgba(255,255,255,0.1); margin: 10px 0;"></div>
                <small style="padding-left: 25px; color: #95a5a6; text-transform: uppercase; font-size: 0.7rem; font-weight: bold; letter-spacing: 1px;">Gestão</small>

                <?php if (temPermissao('relatorios', $permissoes, $cargo, $modulos_instituicao)): ?>
                    <a href="relatorio_financeiro.php" class="nav-item <?php echo ($paginaAtiva === 'relatorios') ? 'active' : ''; ?>">
                        <i class="fas fa-chart-line"></i> Relatório Financeiro
                    </a>
                <?php endif; ?>

                <?php if (temPermissao('equipe', $permissoes, $cargo, $modulos_instituicao)): ?>
                    <a href="usuarios.php" class="nav-item <?php echo ($paginaAtiva === 'equipe') ? 'active' : ''; ?>">
                        <i class="fas fa-id-badge"></i> Minha Equipe
                    </a>
                <?php endif; ?>
                
                <?php if (temPermissao('estoque', $permissoes, $cargo, $modulos_instituicao)): ?>
                    <a href="produtos.php" class="nav-item <?php echo ($paginaAtiva === 'produtos') ? 'active' : ''; ?>">
                        <i class="fas fa-box-open"></i> Produtos
                    </a>
                    <?php if (count($_SESSION['unidades_permitidas'] ?? []) > 1): ?>
                        <a href="transferencia_estoque.php" class="nav-item <?php echo ($paginaAtiva === 'transferencia') ? 'active' : ''; ?>" style="padding-left: 36px; font-size: 0.9rem; display:flex; align-items:center;">
                            <span><i class="fas fa-exchange-alt"></i> Transferir Estoque</span><?php
                            $stReqC = $pdo->prepare("SELECT COUNT(*) FROM transferencias_estoque WHERE origem_id = ? AND status = 'em_transito'");
                            $stReqC->execute([(int)($id_loja ?? 0)]);
                            $qP = (int)$stReqC->fetchColumn();
                            if ($qP > 0) echo '<span style="background:#e74c3c; color:white; border-radius:10px; padding:2px 7px; font-size:0.72rem; font-weight:bold; margin-right:15px; margin-left:auto;">' . $qP . '</span>';
                            ?>
                        </a>
                    <?php endif; ?>
                <?php endif; ?>

                <a href="#" class="nav-item">
                    <i class="fas fa-cog"></i> Config. da Loja
                </a>
            <?php endif; ?>

            <?php if ($cargo === 'Veterinário' && temPermissao('clinica', $permissoes, $cargo, $modulos_instituicao)): ?>
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