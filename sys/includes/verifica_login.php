<?php
// Inicia a sessão se ela ainda não existir nesta página
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verifica se a variável de identificação do usuário existe
if (!isset($_SESSION['usuario_id'])) {
    // Se não existir, ele não fez o login pelo auth.php!
    // Expulsa ele de volta para a tela de login
    header("Location: ../login.php?erro=negado");
    exit;
}
?>