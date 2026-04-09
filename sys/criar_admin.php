<?php
require 'conexao.php';

$nome = "Administrador Supremo";
$email = "admin@petcrm.com";
$senha = "admin123"; // Senha inicial
$cargo = "admin";

// Criptografa a senha
$senhaHash = password_hash($senha, PASSWORD_DEFAULT);

try {
    $sql = "INSERT INTO usuarios (nome, email, senha, cargo) VALUES (?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$nome, $email, $senhaHash, $cargo]);
    echo "Usuário Admin criado com sucesso!";
} catch (PDOException $e) {
    echo "Erro: " . $e->getMessage();
}
?>