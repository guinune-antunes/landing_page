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

            window.syncThemeUI = function() {
                var isDark = document.documentElement.classList.contains('dark-theme');
                var iconEl = document.getElementById('theme-toggle-icon');
                var labelEl = document.getElementById('theme-toggle-label');
                var btnEl = document.getElementById('theme-toggle-btn');
                if (iconEl) {
                    iconEl.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
                    iconEl.style.color = isDark ? '#f59e0b' : '#3b82f6';
                }
                if (labelEl) {
                    labelEl.textContent = isDark ? 'Modo Claro' : 'Modo Escuro';
                }
                if (btnEl) {
                    btnEl.setAttribute('title', isDark ? 'Alternar para Modo Claro' : 'Alternar para Modo Escuro');
                    btnEl.setAttribute('aria-label', isDark ? 'Alternar para Modo Claro' : 'Alternar para Modo Escuro');
                }
            };

            window.toggleDarkMode = function() {
                var isDark = document.documentElement.classList.toggle('dark-theme');
                try {
                    localStorage.setItem('petcrm_theme', isDark ? 'dark' : 'light');
                } catch(e) {}
                window.syncThemeUI();
                window.dispatchEvent(new CustomEvent('petcrm:theme-change', { detail: { theme: isDark ? 'dark' : 'light' } }));
            };

            document.addEventListener('DOMContentLoaded', window.syncThemeUI);
        })();
    </script>

    <style>
        /* Estilos Críticos e Imediatos do Botão de Alternância de Tema */
        .theme-toggle-pill {
            display: inline-flex !important;
            align-items: center !important;
            gap: 8px !important;
            background: #ffffff !important;
            border: 1px solid #cbd5e1 !important;
            border-radius: 20px !important;
            padding: 6px 14px !important;
            font-size: 0.82rem !important;
            font-weight: 600 !important;
            color: #334155 !important;
            cursor: pointer !important;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1) !important;
            font-family: inherit !important;
            outline: none !important;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06) !important;
            user-select: none !important;
            white-space: nowrap !important;
            margin: 0 4px !important;
            line-height: 1 !important;
        }
        .theme-toggle-pill:hover {
            background: #f1f5f9 !important;
            border-color: #94a3b8 !important;
            color: #0f172a !important;
            transform: translateY(-1px) !important;
            box-shadow: 0 3px 8px rgba(0,0,0,0.08) !important;
        }
        .theme-toggle-pill:active {
            transform: translateY(0) !important;
        }
        .theme-toggle-pill i {
            font-size: 0.95rem !important;
            transition: transform 0.25s ease !important;
        }
        .theme-toggle-pill:hover i {
            transform: scale(1.15) rotate(12deg) !important;
        }

        /* Estilos no Tema Escuro */
        .dark-theme .theme-toggle-pill {
            background: #17243d !important;
            border-color: #2b3d5c !important;
            color: #f1f5f9 !important;
            box-shadow: 0 1px 4px rgba(0,0,0,0.4) !important;
        }
        .dark-theme .theme-toggle-pill:hover {
            background: #1f3050 !important;
            border-color: #f59e0b !important;
            color: #ffffff !important;
            box-shadow: 0 0 12px rgba(245, 158, 11, 0.25) !important;
        }
    </style>

    <link rel="stylesheet" href="style.css?v=<?php echo file_exists(__DIR__ . '/../style.css') ? filemtime(__DIR__ . '/../style.css') : time(); ?>">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
</head>
<body>
    <div class="dashboard-container">
