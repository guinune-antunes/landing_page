<?php
// ══════════════════════════════════════════════════════════════════
// api_transferir_estoque.php — Transfere estoque entre unidades da MESMA rede
// ══════════════════════════════════════════════════════════════════
// Regras:
//  • Origem = unidade ativa do gerente. Destino precisa estar entre as unidades
//    que ele pode acessar E pertencer à mesma rede da origem.
//  • Operação atômica: baixa na origem + entrada no destino + histórico.
//  • Linhas travadas (FOR UPDATE) e baixa condicional → impede saldo negativo
//    mesmo com vendas simultâneas no PDV.
//  • No destino, o produto é localizado por código de barras (ou nome, se não
//    houver código). Se não existir, é criado copiando o cadastro da origem.
// ══════════════════════════════════════════════════════════════════
require 'seguranca_api.php';
require_once __DIR__ . '/includes/unidades.php';
exigirMetodo('POST');
exigirCargo(['Gerente']);

$data = json_decode(file_get_contents('php://input'), true) ?? [];

$produtoId  = (int)($data['produto_id'] ?? 0);
$destinoId  = (int)($data['destino_id'] ?? 0);
$qtd        = $data['quantidade'] ?? null;
$observacao = trim((string)($data['observacao'] ?? ''));
$observacao = $observacao !== '' ? mb_substr($observacao, 0, 255) : null;

function falhar(string $msg, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

if (!$produtoId || !$destinoId) {
    falhar('Selecione o produto e a unidade de destino.');
}
if (!is_numeric($qtd) || (int)$qtd != $qtd || (int)$qtd <= 0) {
    falhar('Informe uma quantidade inteira maior que zero.');
}
$qtd = (int)$qtd;

$origemId = (int)$id_loja;
if ($destinoId === $origemId) {
    falhar('A unidade de destino deve ser diferente da unidade atual.');
}

// Autorização: o gerente precisa ter acesso às duas unidades, e ambas na mesma rede
$permitidas = carregarUnidadesUsuario($pdo, (int)$usuario_id);
if (!isset($permitidas[$origemId], $permitidas[$destinoId])) {
    falhar('Você não tem acesso a uma das unidades informadas.', 403);
}
$redeId = $permitidas[$origemId]['rede_id'];
if (!$redeId || (int)$permitidas[$destinoId]['rede_id'] !== (int)$redeId) {
    falhar('Só é possível transferir entre unidades da mesma rede.', 403);
}

try {
    $pdo->beginTransaction();

    // 1. Produto de origem (travado)
    $st = $pdo->prepare("SELECT * FROM produtos WHERE id = ? AND instituicao_id = ? FOR UPDATE");
    $st->execute([$produtoId, $origemId]);
    $prod = $st->fetch();
    if (!$prod) {
        $pdo->rollBack();
        falhar('Produto não encontrado nesta unidade.');
    }

    // 2. Baixa condicional na origem (nunca deixa negativo)
    $baixa = $pdo->prepare("UPDATE produtos SET quantidade_estoque = quantidade_estoque - ?
                            WHERE id = ? AND instituicao_id = ? AND quantidade_estoque >= ?");
    $baixa->execute([$qtd, $produtoId, $origemId, $qtd]);
    if ($baixa->rowCount() !== 1) {
        $pdo->rollBack();
        falhar('Estoque insuficiente. Disponível: ' . (int)$prod['quantidade_estoque'] . '.');
    }

    // 3. Localiza o produto equivalente no destino (travado)
    $codigo = trim((string)($prod['codigo_barras'] ?? ''));
    if ($codigo !== '') {
        $st = $pdo->prepare("SELECT id FROM produtos WHERE instituicao_id = ? AND codigo_barras = ? FOR UPDATE");
        $st->execute([$destinoId, $codigo]);
    } else {
        $st = $pdo->prepare("SELECT id FROM produtos WHERE instituicao_id = ? AND nome_produto = ? FOR UPDATE");
        $st->execute([$destinoId, $prod['nome_produto']]);
    }
    $destProdId = $st->fetchColumn();

    if ($destProdId) {
        $pdo->prepare("UPDATE produtos SET quantidade_estoque = quantidade_estoque + ? WHERE id = ? AND instituicao_id = ?")
            ->execute([$qtd, $destProdId, $destinoId]);
    } else {
        // Cria o cadastro no destino (preço de venda copiado; o gerente pode ajustar depois)
        $ins = $pdo->prepare("INSERT INTO produtos
            (instituicao_id, nome_produto, marca, descricao, codigo_barras, preco_custo, preco_venda,
             quantidade_estoque, unidade, tamanho_embalagem_kg, energia_metabolizavel_kg, fornecedor,
             data_chegada, data_fabricacao, data_validade)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?)");
        $ins->execute([
            $destinoId, $prod['nome_produto'], $prod['marca'], $prod['descricao'],
            $codigo !== '' ? $codigo : null, $prod['preco_custo'], $prod['preco_venda'],
            $qtd, $prod['unidade'], $prod['tamanho_embalagem_kg'], $prod['energia_metabolizavel_kg'],
            $prod['fornecedor'], $prod['data_fabricacao'], $prod['data_validade'],
        ]);
        $destProdId = (int)$pdo->lastInsertId();
    }

    // 4. Histórico
    $pdo->prepare("INSERT INTO transferencias_estoque
        (rede_id, origem_id, destino_id, produto_origem_id, produto_destino_id, nome_produto, quantidade, observacao, usuario_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$redeId, $origemId, $destinoId, $produtoId, $destProdId, $prod['nome_produto'], $qtd, $observacao, $usuario_id]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "Transferidas $qtd un. de \"{$prod['nome_produto']}\" para {$permitidas[$destinoId]['rotulo']}.",
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro interno ao transferir estoque.']);
}
