<?php
// 1. Segura qualquer saída acidental
ob_start();

// 2. Inicia a sessão
session_start();

// 3. Inclui a conexão
require 'conexao.php';

// --- LÓGICA DE LOGIN ---
$email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
$senha = $_POST['senha'];

if ($email && $senha) {
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ?");
    $stmt->execute([$email]);
    $usuario = $stmt->fetch();

    if ($usuario && password_verify($senha, $usuario['senha'])) {
        
        // Login Sucesso: Salva dados na sessão
        $_SESSION['usuario_id'] = $usuario['id'];
        $_SESSION['usuario_nome'] = $usuario['nome'];
        $_SESSION['usuario_cargo'] = $usuario['cargo'];
        $_SESSION['instituicao_id'] = $usuario['instituicao_id'];

        // Redireciona para o Roteador
        header("Location: dashboard.php");
        exit;

    } else {
        // Senha errada
        header("Location: login.php?erro=1");
        exit;
    }
} else {
    // Campos vazios
    header("Location: login.php");
    exit;
}

// Limpa o buffer (segurança extra)
ob_end_flush();
?>