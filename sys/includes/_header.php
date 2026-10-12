<header class="header">
    <div class="header-search">
        <i class="fas fa-search"></i>
        <input type="text" placeholder="Buscar por cliente, pet...">
    </div>
    <div class="header-profile">
        <?php
        // Seletor de unidade (só aparece se o usuário tem acesso a mais de uma)
        $unidadesHeader = $_SESSION['unidades_permitidas'] ?? [];
        if (count($unidadesHeader) > 1):
            $unidadeAtivaHeader = (int)($_SESSION['instituicao_id'] ?? 0);
        ?>
            <form method="post" action="trocar_unidade.php" id="form-trocar-unidade" style="margin:0;">
                <input type="hidden" name="voltar" value="<?php echo htmlspecialchars(basename($_SERVER['PHP_SELF'])); ?>">
                <label for="select-unidade" style="display:flex;align-items:center;gap:8px;background:#f0faf7;border:1px solid #1abc9c;border-radius:20px;padding:4px 12px;color:#16a085;font-size:0.85rem;font-weight:600;cursor:pointer;">
                    <i class="fas fa-store"></i>
                    <select name="unidade_id" id="select-unidade" onchange="this.form.submit()"
                            style="border:none;background:transparent;color:#16a085;font-weight:600;font-size:0.85rem;outline:none;cursor:pointer;max-width:240px;">
                        <?php foreach ($unidadesHeader as $uid => $rotulo): ?>
                            <option value="<?php echo (int)$uid; ?>" <?php echo ((int)$uid === $unidadeAtivaHeader) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($rotulo); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>
        <?php endif; ?>
        <!-- Botão Único com Destaque para Alternar Tema Claro / Escuro -->
        <button type="button" id="theme-toggle-btn" class="theme-toggle-pill" onclick="window.toggleDarkMode()" title="Alternar Modo Escuro / Claro" aria-label="Alternar Modo Escuro / Claro">
            <span class="theme-icon-container">
                <i class="fas fa-moon theme-icon-moon"></i>
                <i class="fas fa-sun theme-icon-sun"></i>
            </span>
            <span id="theme-toggle-text" class="theme-toggle-text">
                <script>document.write(document.documentElement.classList.contains('dark-theme') ? 'Modo Claro' : 'Modo Escuro');</script>
            </span>
        </button>
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