<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - CRM Petshop</title>

    <script>
        (function() {
            try {
                var theme = localStorage.getItem('petcrm_theme');
                if (theme === 'dark' || (!theme && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                    document.documentElement.classList.add('dark-theme');
                } else {
                    document.documentElement.classList.remove('dark-theme');
                }
            } catch(e) {}

            // Função global infalível para alternar o tema
            window.toggleDarkMode = function() {
                var isDark = document.documentElement.classList.toggle('dark-theme');
                try {
                    localStorage.setItem('petcrm_theme', isDark ? 'dark' : 'light');
                } catch(e) {}

                var textEl = document.getElementById('theme-toggle-text');
                if (textEl) {
                    textEl.textContent = isDark ? 'Modo Claro' : 'Modo Escuro';
                }

                var btnEl = document.getElementById('theme-toggle-btn');
                if (btnEl) {
                    btnEl.setAttribute('title', isDark ? 'Mudar para Modo Claro' : 'Mudar para Modo Escuro');
                }

                window.dispatchEvent(new CustomEvent('petcrm:theme-change', { detail: { theme: isDark ? 'dark' : 'light' } }));
            };
        })();
    </script>

    <link rel="stylesheet" href="style.css?v=<?php echo file_exists(__DIR__ . '/../style.css') ? filemtime(__DIR__ . '/../style.css') : time(); ?>">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
</head>
<body>
    <div class="dashboard-container">