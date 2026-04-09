<?php
ob_start(); // Proteção contra erros de cabeçalho/espaços em branco
require 'includes/verifica_login.php';

// Garante que a variável cargo existe
$cargo = isset($_SESSION['usuario_cargo']) ? $_SESSION['usuario_cargo'] : '';

switch ($cargo) {
    case 'super_admin':
        // Manda para a nova dashboard de métricas do SaaS
        // Certifique-se que o arquivo 'dashboard_super.php' existe na pasta!
        header("Location: dashboard_super.php");
        exit;

    case 'atendente':
        header("Location: dashboard_atendente.php");
        exit;

    case 'admin':
    case 'gerente':
    default:
        // Manda para o dashboard da loja
        // Certifique-se que você renomeou o antigo dashboard para 'dashboard_gerente.php'
        header("Location: dashboard_gerente.php");
        exit;
}

ob_end_flush();
?>