<?php
session_start();
header('Content-Type: application/json');
require 'conexao.php';

// Segurança
if (!isset($_SESSION['instituicao_id'])) {
    echo json_encode(['labels' => [], 'data' => []]); exit;
}

$id_loja = $_SESSION['instituicao_id'];
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

// Define a data base (Hoje +/- semanas)
$dataBase = date('Y-m-d', strtotime("$offset week"));

$labels = [];
$dados = [];
$periodoTexto = "";

// Loop dos últimos 7 dias a partir da data base
for ($i = 6; $i >= 0; $i--) {
    $dataLoop = date('Y-m-d', strtotime("-$i days", strtotime($dataBase)));
    
    // Label (ex: 18/11)
    $labels[] = date('d/m', strtotime($dataLoop));
    
    // Busca no banco
    $sql = "SELECT COUNT(*) FROM agendamentos 
            WHERE instituicao_id = ? AND DATE(data_hora_inicio) = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id_loja, $dataLoop]);
    $dados[] = $stmt->fetchColumn();
}

// Texto para exibir na tela (Ex: 10/11 - 17/11)
$inicio = date('d/m', strtotime("-6 days", strtotime($dataBase)));
$fim = date('d/m', strtotime($dataBase));

echo json_encode([
    'labels' => $labels,
    'data' => $dados,
    'periodo' => "$inicio até $fim"
]);
?>