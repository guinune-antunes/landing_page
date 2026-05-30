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

// Função centralizada de verificação de permissões (RBAC)
if (!function_exists('verificarPermissao')) {
    function verificarPermissao(array $cargosPermitidos) {
        $cargoUsuario = $_SESSION['usuario_cargo'] ?? '';
        
        if (!in_array($cargoUsuario, $cargosPermitidos)) {
            // Se não tiver permissão, redireciona de volta para o dashboard correto
            if ($cargoUsuario === 'Admin') {
                header("Location: dashboard_super.php"); 
            } else if ($cargoUsuario === 'Gerente') {
                header("Location: dashboard_gerente.php");
            } else {
                header("Location: dashboard.php"); 
            }
            exit;
        }
    }
}
?>