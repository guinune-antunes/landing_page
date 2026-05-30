<?php
// --- 1. BLINDAGEM CONTRA ERROS DE RESPOSTA ---
ob_start(); 
ini_set('display_errors', 0); 
error_reporting(E_ALL); 

header('Content-Type: application/json');
session_start();

function responderErro($mensagem) {
    ob_clean(); 
    echo json_encode(['success' => false, 'message' => $mensagem]);
    exit;
}

try {
    require 'conexao.php';

    // 2. Segurança: Verifica se está logado
    if (!isset($_SESSION['instituicao_id'])) {
        responderErro('Sessão expirada. Faça login novamente.');
    }

    $id_loja = $_SESSION['instituicao_id'];
    
    // Pega os dados
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        responderErro('Dados inválidos recebidos do navegador.');
    }

    if (empty($data['itens'])) {
        responderErro('O carrinho está vazio.');
    }

    $pdo->beginTransaction();

    // 3. Salva a Venda (Cabeçalho) - CORRIGIDO: valor_total
    $sql_venda = "INSERT INTO vendas (instituicao_id, cliente_id, valor_total, forma_pagamento, data_venda) 
                  VALUES (?, ?, ?, ?, NOW())";
    
    $stmt = $pdo->prepare($sql_venda);
    
    $cliente_id = !empty($data['cliente_id']) ? $data['cliente_id'] : null;
    $valor_total = !empty($data['total_venda']) ? $data['total_venda'] : 0;
    
    $stmt->execute([
        $id_loja,
        $cliente_id,
        $valor_total,
        $data['forma_pagamento']
    ]);
    
    $venda_id = $pdo->lastInsertId();

    // 4. Salva os Itens (CORRIGIDO para venda_itens) e Baixa Estoque
    $sql_item = "INSERT INTO venda_itens (venda_id, produto_id, nome_produto, quantidade, preco_unitario, subtotal)
                 VALUES (?, ?, ?, ?, ?, ?)";
    
    // Query para baixar estoque (CORRIGIDO: quantidade_estoque)
    $sql_estoque = "UPDATE produtos SET quantidade_estoque = quantidade_estoque - ? WHERE id = ? AND instituicao_id = ?";
    
    $stmt_item = $pdo->prepare($sql_item);
    $stmt_estoque = $pdo->prepare($sql_estoque);

    foreach ($data['itens'] as $item) {
        $produto_id = ($item['id'] > 0) ? $item['id'] : null;
        $qtd = $item['qtd'];
        $preco = $item['preco'];
        $subtotal = $qtd * $preco;

        // Salva o item no carrinho
        $stmt_item->execute([
            $venda_id,
            $produto_id,
            $item['nome'],
            $qtd,
            $preco,
            $subtotal
        ]);

        // Se for um produto do banco, baixa o estoque e analisa para o CRM
        if ($produto_id) {
            $stmt_estoque->execute([$qtd, $produto_id, $id_loja]);

            // =================================================================
            // INTELIGÊNCIA DO CRM: Detecta se é ração e lança na esteira
            // =================================================================
            if ($cliente_id) {
                // Verifica se o produto tem dados nutricionais (Ração)
                $stmt_check = $pdo->prepare("SELECT tamanho_embalagem_kg FROM produtos WHERE id = ?");
                $stmt_check->execute([$produto_id]);
                $prod_info = $stmt_check->fetch(PDO::FETCH_ASSOC);

                if ($prod_info && !empty($prod_info['tamanho_embalagem_kg'])) {
                    
                    // Pega o primeiro pet do cliente para associar à ração
                    $stmt_pet = $pdo->prepare("SELECT id FROM pets WHERE cliente_id = ? LIMIT 1");
                    $stmt_pet->execute([$cliente_id]);
                    $pet = $stmt_pet->fetch(PDO::FETCH_ASSOC);
                    $pet_id_venda = $pet ? $pet['id'] : null;

                    // Lança a oportunidade como Pendente no CRM
                    if ($pet_id_venda) {
                        $sql_crm = "INSERT INTO oportunidades_venda (instituicao_id, cliente_id, pet_id, produto_id, produto_nome, data_compra, status)
                                    VALUES (?, ?, ?, ?, ?, NOW(), 'Pendente')";
                        $pdo->prepare($sql_crm)->execute([
                            $id_loja, $cliente_id, $pet_id_venda, $produto_id, $item['nome']
                        ]);
                    }
                }
            }
            // =================================================================
        }
    }

    $pdo->commit();

    // --- SUCESSO ---
    ob_clean(); 
    echo json_encode(['success' => true, 'message' => 'Venda realizada com sucesso!', 'venda_id' => $venda_id]);

} catch (Throwable $e) { 
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responderErro('Erro interno no servidor.');
}
?>