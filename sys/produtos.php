<?php
require_once 'includes/verifica_login.php';
verificarPermissao(['Gerente', 'Admin']);

require_once 'conexao.php';
$id_loja = $_SESSION['instituicao_id'];

// Busca os produtos da loja
try {
    $sql = "SELECT * FROM produtos WHERE instituicao_id = ? ORDER BY nome_produto ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id_loja]);
    $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $produtos = [];
}

$hoje = date('Y-m-d');
$pageTitle = 'Gestão de Estoque';
$paginaAtiva = 'produtos'; 

include 'includes/_head.php';

// Renderiza a View separada
require_once 'views/produtos_view.php';
?>