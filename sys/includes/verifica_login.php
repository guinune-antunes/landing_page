<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Se não tem ID na sessão, manda pro login
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}
// Aqui você pode adicionar verificações extras, tipo:
// Se a página atual for 'configuracoes.php' e o cargo não for 'admin', bloqueia.
?>