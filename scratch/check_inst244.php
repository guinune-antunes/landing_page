<?php
require_once __DIR__ . '/../sys/conexao.php';

echo "=== CLIENTES DA UNIDADE 244 ===\n";
$st = $pdo->prepare("SELECT c.id, c.nome_completo, c.telefone, p.id as pet_id, p.nome as pet_nome, p.peso_kg, p.fator_manutencao 
                     FROM clientes c 
                     LEFT JOIN pets p ON p.cliente_id = c.id 
                     WHERE c.instituicao_id = 244");
$st->execute();
print_r($st->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== PRODUTOS DA UNIDADE 244 ===\n";
$stP = $pdo->prepare("SELECT id, nome_produto, categoria, tamanho_embalagem_kg, energia_metabolizavel_kg, quantidade_estoque, preco_venda 
                      FROM produtos 
                      WHERE instituicao_id = 244");
$stP->execute();
print_r($stP->fetchAll(PDO::FETCH_ASSOC));

