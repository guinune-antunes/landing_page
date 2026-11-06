<?php
require_once __DIR__ . '/../sys/conexao.php';

echo "=== CLIENTES E PETS ===\n";
$st = $pdo->query("SELECT c.id as cli_id, c.nome_completo, c.telefone, c.instituicao_id, p.id as pet_id, p.nome as pet_nome, p.peso_kg, p.fator_manutencao 
                   FROM clientes c 
                   LEFT JOIN pets p ON p.cliente_id = c.id 
                   LIMIT 20");
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "Cli: {$r['cli_id']} - {$r['nome_completo']} (Inst: {$r['instituicao_id']}) | Pet: {$r['pet_id']} - {$r['pet_nome']} | Peso: {$r['peso_kg']}kg | Fator: {$r['fator_manutencao']}\n";
}

echo "\n=== PRODUTOS (RAÇÕES) ===\n";
$stP = $pdo->query("SELECT id, instituicao_id, nome_produto, categoria, tamanho_embalagem_kg, energia_metabolizavel_kg, quantidade_estoque, preco_venda 
                    FROM produtos 
                    WHERE tamanho_embalagem_kg IS NOT NULL OR categoria = 'Ração' OR nome_produto LIKE '%ração%' 
                    LIMIT 20");
$prods = $stP->fetchAll(PDO::FETCH_ASSOC);
foreach ($prods as $p) {
    echo "Prod: {$p['id']} - {$p['nome_produto']} | Inst: {$p['instituicao_id']} | Cat: {$p['categoria']} | Peso: {$p['tamanho_embalagem_kg']}kg | EM: {$p['energia_metabolizavel_kg']}kcal | Est: {$p['quantidade_estoque']} | R$ {$p['preco_venda']}\n";
}

echo "\n=== OPORTUNIDADES_VENDA ATUAIS ===\n";
$stOp = $pdo->query("SELECT * FROM oportunidades_venda");
$ops = $stOp->fetchAll(PDO::FETCH_ASSOC);
echo "Total: " . count($ops) . "\n";
foreach ($ops as $o) {
    echo "ID: {$o['id']} | Inst: {$o['instituicao_id']} | Cli: {$o['cliente_id']} | Pet: {$o['pet_id']} | Prod: {$o['produto_id']} - {$o['produto_nome']} | Data: {$o['data_compra']} | Status: {$o['status']}\n";
}

