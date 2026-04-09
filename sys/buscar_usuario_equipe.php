<?php
session_start();
header('Content-Type: application/json');
require 'conexao.php';

if (!isset($_SESSION['instituicao_id']) || empty($_GET['id'])) {
    echo json_encode(['success' => false]); exit;
}

$id_loja = $_SESSION['instituicao_id'];
$id_user = $_GET['id'];

// Busca APENAS se for da mesma loja
$stmt = $pdo->prepare("SELECT id, nome, email, cargo, ativo FROM usuarios WHERE id = ? AND instituicao_id = ?");
$stmt->execute([$id_user, $id_loja]);
$usuario = $stmt->fetch();

if ($usuario) {
    echo json_encode(['success' => true, 'usuario' => $usuario]);
} else {
    echo json_encode(['success' => false, 'message' => 'Não encontrado']);
}
?>