<?php
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=massa_1000_produtos_avancado.csv');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); 
fputcsv($output, ['Nome', 'Descricao', 'Codigo_Barras', 'Custo', 'Preco', 'Estoque', 'Peso_KG', 'Fornecedor', 'Fabricacao', 'Validade'], ';');

$marcas = ['PremieR', 'Golden', 'Royal Canin', 'Pedigree', 'Whiskas', 'Bravecto', 'Simparic'];
$categorias = ['Ração Seca', 'Ração Úmida', 'Petisco', 'Antipulgas', 'Shampoo'];
$tamanhos = ['Pequeno Porte', 'Médio Porte', 'Grande Porte'];
$fornecedores = ['Distribuidora PetSul', 'Atacadão Animal', 'VetDistribui', 'PetLogística SA', 'Central Rações'];

for ($i = 1; $i <= 1000; $i++) {
    $marca = $marcas[array_rand($marcas)];
    $cat = $categorias[array_rand($categorias)];
    $tam = $tamanhos[array_rand($tamanhos)];
    
    $nome = "$cat $marca - $tam";
    $descricao = "Produto original da $marca para $tam.";
    $codigo_barras = "789" . rand(100000000, 999999999);
    
    // Calcula Preço e Custo (Margem de lucro de ~30 a 50%)
    $preco_float = rand(1500, 35000) / 100;
    $custo_float = $preco_float * (rand(50, 70) / 100); 
    
    $preco = number_format($preco_float, 2, ',', '');
    $custo = number_format($custo_float, 2, ',', '');
    
    $estoque = rand(0, 150);
    $peso = number_format(rand(1000, 15000) / 1000, 3, ',', ''); 
    
    $fornecedor = $fornecedores[array_rand($fornecedores)];

    // Datas Aleatórias (Fabricação no último ano, Validade nos próximos 2 anos)
    $timestamp_fab = strtotime('-' . rand(1, 365) . ' days');
    $timestamp_val = strtotime('+' . rand(30, 730) . ' days', $timestamp_fab);
    
    $fabricacao = date('d/m/Y', $timestamp_fab);
    $validade = date('d/m/Y', $timestamp_val);

    fputcsv($output, [$nome, $descricao, $codigo_barras, $custo, $preco, $estoque, $peso, $fornecedor, $fabricacao, $validade], ';');
}

fclose($output);
exit;
?>