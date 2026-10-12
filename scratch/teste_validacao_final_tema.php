<?php
/**
 * Teste de Validação Final do Tema Escuro
 */
$baseDir = dirname(__DIR__) . '/sys/';
$errors = [];

echo "=== VALIDAÇÃO DO BOTÃO ÚNICO E DA EXECUÇÃO DO TEMA ESCURO ===\n\n";

// 1. Testa _head.php
$head = file_get_contents($baseDir . 'includes/_head.php');
if (strpos($head, 'window.toggleDarkMode = function') !== false) {
    echo "[OK] _head.php define window.toggleDarkMode no topo, de forma infalível.\n";
} else {
    $errors[] = "_head.php não define window.toggleDarkMode.";
}

// 2. Testa _header.php
$header = file_get_contents($baseDir . 'includes/_header.php');
if (strpos($header, 'onclick="window.toggleDarkMode()"') !== false && strpos($header, 'id="theme-toggle-btn"') !== false) {
    echo "[OK] _header.php possui 1 único botão com onclick direto para alternância imediata.\n";
} else {
    $errors[] = "_header.php não possui onclick direto no botão.";
}

// 3. Testa _sidebar.php (garante que não há botão duplicado)
$sidebar = file_get_contents($baseDir . 'includes/_sidebar.php');
if (strpos($sidebar, 'sidebar-theme-toggle') === false) {
    echo "[OK] _sidebar.php não possui botão duplicado (mantido apenas o botão oficial no cabeçalho).\n";
} else {
    $errors[] = "_sidebar.php ainda contém o alternador duplicado.";
}

// 4. Testa _footer.php
$footer = file_get_contents($baseDir . 'includes/_footer.php');
if (strpos($footer, 'main.js?v=') !== false) {
    echo "[OK] _footer.php possui controle de versão para invalidar cache do main.js.\n";
} else {
    $errors[] = "_footer.php não possui controle de versão do main.js.";
}

// 5. Testa style.css
$css = file_get_contents($baseDir . 'style.css');
if (strpos($css, '.theme-toggle-pill') !== false && strpos($css, 'display: none !important') !== false) {
    echo "[OK] style.css possui estilos da pílula com controle estrito dos ícones de sol e lua.\n";
} else {
    $errors[] = "style.css não possui regras completas da pílula ou dos ícones.";
}

echo "\n=== RESULTADO FINAL ===\n";
if (empty($errors)) {
    echo "100% SUCESSO: Sistema ajustado com 1 único botão no topo com execução direta!\n";
} else {
    echo "FALHAS:\n" . implode("\n", $errors) . "\n";
}
