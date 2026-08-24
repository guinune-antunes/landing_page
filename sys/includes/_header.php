<header class="header">
    <div class="header-search">
        <i class="fas fa-search"></i>
        <input type="text" placeholder="Buscar por cliente, pet...">
    </div>
    <div class="header-profile">
        <i class="fas fa-bell"></i>
        <div class="user-info">
            <span><?php echo htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário'); ?></span>
            <small><?php echo htmlspecialchars($_SESSION['usuario_cargo'] ?? ''); ?></small>
        </div>
        <div style="width:40px;height:40px;border-radius:50%;background:#1abc9c;display:flex;align-items:center;justify-content:center;color:white;font-weight:bold;font-size:1.1rem;">
            <?php echo strtoupper(substr($_SESSION['usuario_nome'] ?? 'U', 0, 1)); ?>
        </div>
    </div>
</header>