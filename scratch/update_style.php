<?php
$file = __DIR__ . '/../sys/style.css';
$content = file_get_contents($file);

$target = <<< 'OLD'
/* --- B: Botão de Alternância de Tema em Formato Pílula / Badge no Header --- */
.theme-toggle-pill {
OLD;

// Let's replace the whole section from /* --- B: Botão de Alternância de Tema to /* --- C: Barra de Rolagem
$pattern = '/\/\* --- B: Botão de Alternância de Tema.*?(?=\/\* --- C: Barra de Rolagem)/s';

$replacement = <<< 'NEW'
/* --- B: Botão de Alternância de Tema em Formato Pílula / Badge no Header --- */
.theme-toggle-pill {
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 20px;
    padding: 6px 14px;
    font-size: 0.82rem;
    font-weight: 600;
    color: #334155;
    cursor: pointer;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    font-family: inherit;
    outline: none;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    user-select: none;
    white-space: nowrap;
    margin: 0 4px;
    line-height: 1;
}
.theme-toggle-pill:hover {
    background: #f1f5f9;
    border-color: #94a3b8;
    color: #0f172a;
    transform: translateY(-1px);
    box-shadow: 0 3px 8px rgba(0,0,0,0.08);
}
.theme-toggle-pill i {
    font-size: 0.95rem;
    transition: transform 0.25s ease;
}
.theme-toggle-pill:hover i {
    transform: scale(1.15) rotate(12deg);
}

/* Estado Ativo no Dark Mode */
.dark-theme .theme-toggle-pill {
    background: #17243d;
    border-color: #2b3d5c;
    color: #f1f5f9;
    box-shadow: 0 1px 4px rgba(0,0,0,0.4);
}
.dark-theme .theme-toggle-pill:hover {
    background: #1f3050;
    border-color: #f59e0b;
    color: #ffffff;
    box-shadow: 0 0 12px rgba(245, 158, 11, 0.25);
    transform: translateY(-1px);
}

NEW;

if (preg_match($pattern, $content)) {
    $content = preg_replace($pattern, $replacement, $content);
    file_put_contents($file, $content);
    echo "Successfully updated style.css\n";
} else {
    echo "Pattern not matched\n";
}

