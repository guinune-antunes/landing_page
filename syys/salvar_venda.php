<?php
// --- 1. BLINDAGEM CONTRA ERROS DE RESPOSTA ---
ob_start(); // Inicia o buffer de memória
ini_set('display_errors', 0); // Desativa exibição de erros HTML na tela
error_reporting(E_ALL); // Continua registrando erros no log

header('Content-Type: application/json');
session_start();

// Função auxiliar para responder erro limpo e parar tudo
function responderErro($mensagem) {
    ob_clean(); // Limpa qualquer lixo que tenha sido gerado antes
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

    // Verifica se o JSON veio correto
    if (json_last_error() !== JSON_ERROR_NONE) {
        responderErro('Dados inválidos recebidos do navegador.');
    }

    if (empty($data['itens'])) {
        responderErro('O carrinho está vazio.');
    }

    $pdo->beginTransaction();

    // 3. Salva a Venda (Cabeçalho)
    // ATENÇÃO: Certifique-se que os nomes das colunas batem com seu banco
    $sql_venda = "INSERT INTO vendas 
        (instituicao_id, cliente_id, total_venda, desconto, forma_pagamento, status, agendamentos_id) 
        VALUES (?, ?, ?, ?, ?, 'Finalizada', NULL)";
    
    $stmt = $pdo->prepare($sql_venda);
    
    // Trata valores opcionais
    $cliente_id = !empty($data['cliente_id']) ? $data['cliente_id'] : null;
    $total_venda = !empty($data['total_venda']) ? $data['total_venda'] : 0;
    $desconto = !empty($data['desconto']) ? $data['desconto'] : 0;
    
    $stmt->execute([
        $id_loja,
        $cliente_id,
        $total_venda,
        $desconto,
        $data['forma_pagamento']
    ]);
    
    $venda_id = $pdo->lastInsertId();

    // 4. Salva os Itens e Baixa Estoque
    $sql_item = "INSERT INTO itens_venda 
        (venda_id, produto_id, nome_item, quantidade, preco_unitario, subtotal) 
        VALUES (?, ?, ?, ?, ?, ?)";
    
    // Query para baixar estoque (apenas se for da mesma loja)
    $sql_estoque = "UPDATE produtos SET quantidade = quantidade - ? WHERE id = ? AND instituicao_id = ?";
    
    $stmt_item = $pdo->prepare($sql_item);
    $stmt_estoque = $pdo->prepare($sql_estoque);

    foreach ($data['itens'] as $item) {
        $produto_id = ($item['id'] > 0) ? $item['id'] : null;
        $qtd = $item['qtd'];
        $preco = $item['preco'];
        $subtotal = $qtd * $preco;

        $stmt_item->execute([
            $venda_id,
            $produto_id,
            $item['nome'],
            $qtd,
            $preco,
            $subtotal
        ]);

        // Baixa estoque se for produto cadastrado
        if ($produto_id) {
            $stmt_estoque->execute([$qtd, $produto_id, $id_loja]);
        }
    }

    $pdo->commit();

    // --- SUCESSO ---
    ob_clean(); // Limpa buffer para garantir JSON puro
    echo json_encode(['success' => true, 'message' => 'Venda realizada com sucesso!', 'venda_id' => $venda_id]);

} catch (Throwable $e) { // Usa Throwable para pegar qualquer erro (até fatais)
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responderErro('Erro no servidor: ' . $e->getMessage());
}
?>