<?php
session_start();
require 'conexao.php';

if (!isset($_SESSION['usuario_cargo']) || $_SESSION['usuario_cargo'] !== 'Admin') die("Acesso negado.");

$id_loja = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id_loja) die("ID da instituição não fornecido.");

try {
    $sql = "SELECT nome_produto AS Nome, descricao AS Descricao, codigo_barras AS Codigo_Barras, 
                   valor_compra AS Custo, preco_venda AS Preco, quantidade_estoque AS Estoque, 
                   tamanho_embalagem_kg AS Peso_KG, fornecedor AS Fornecedor, 
                   data_fabricacao AS Fabricacao, data_validade AS Validade
            FROM produtos 
            WHERE instituicao_id = ? ORDER BY nome_produto";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id_loja]);
    $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=estoque_loja_' . $id_loja . '_' . date('Ymd_His') . '.csv');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // Formato UTF-8 pro Excel

    if (count($dados) > 0) {
        fputcsv($output, array_keys($dados[0]), ';'); 
        foreach ($dados as $linha) { fputcsv($output, $linha, ';'); }
    } else {
        fputcsv($output, ['Nenhum produto encontrado'], ';');
    }

    fclose($output);
    exit;
} catch (Exception $e) {
    die("Erro: " . $e->getMessage());
}
?>