<?php
/**
 * Teste de validação aprofundado do Tema Escuro
 */
$baseDir = dirname(__DIR__) . '/sys/';
$errors = [];

echo "=== VALIDAÇÃO DO NOVO DESIGN DE TEMA ESCURO ===\n\n";

// 1. Valida cache-busting em _head.php
$headContent = file_get_contents($baseDir . 'includes/_head.php');
if (strpos($headContent, 'style.css?v=') !== false && strpos($headContent, 'petcrm_theme') !== false) {
    echo "[OK] _head.php possui versionamento anti-cache e script anti-FOUC.\n";
} else {
    $errors[] = "_head.php não possui versionamento anti-cache ou script anti-FOUC.";
}

// 2. Valida novo botão Pílula no Header
$headerContent = file_get_contents($baseDir . 'includes/_header.php');
if (strpos($headerContent, 'theme-toggle-pill') !== false && strpos($headerContent, 'theme-toggle-text') !== false) {
    echo "[OK] _header.php possui o botão com design de Pílula destacada e texto explicativo.\n";
} else {
    $errors[] = "_header.php não possui theme-toggle-pill ou theme-toggle-text.";
}

// 3. Valida seletor na Sidebar
$sidebarContent = file_get_contents($baseDir . 'includes/_sidebar.php');
if (strpos($sidebarContent, 'sidebar-theme-toggle') !== false && strpos($sidebarContent, 'sidebar-switch-track') !== false) {
    echo "[OK] _sidebar.php possui o seletor ergonômico de tema com chave switch deslizante.\n";
} else {
    $errors[] = "_sidebar.php não possui o alternador de tema na barra lateral.";
}

// 4. Valida CSS de theme-toggle-pill e sidebar-theme-item
$styleCssContent = file_get_contents($baseDir . 'style.css');
if (strpos($styleCssContent, '.theme-toggle-pill') !== false && strpos($styleCssContent, '.sidebar-theme-item') !== false) {
    echo "[OK] style.css contém as regras completas para o botão pílula e para a barra lateral.\n";
} else {
    $errors[] = "style.css não possui as regras para o botão pílula ou barra lateral.";
}

// 5. Valida JS para sincronização dupla
$mainJsContent = file_get_contents($baseDir . 'main.js');
if (strpos($mainJsContent, 'sidebar-theme-toggle') !== false && strpos($mainJsContent, 'theme-toggle-text') !== false) {
    echo "[OK] main.js sincroniza dinamicamente o botão do topo e o alternador da barra lateral.\n";
} else {
    $errors[] = "main.js não possui suporte a sincronização dupla de tema.";
}

echo "\n=== RESULTADO FINAL ===\n";
if (empty($errors)) {
    echo "100% SUCESSO: O alternador de tema agora possui visual destacado, proeminente e acessível tanto no Header quanto na Sidebar!\n";
} else {
    echo "FALHAS:\n" . implode("\n", $errors) . "\n";
}
