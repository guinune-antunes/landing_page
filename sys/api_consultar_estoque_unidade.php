<?php
// ══════════════════════════════════════════════════════════════════
// api_consultar_estoque_unidade.php — Consulta produtos com saldo em outra unidade da rede
// ══════════════════════════════════════════════════════════════════
require 'seguranca_api.php';
require_once __DIR__ . '/includes/unidades.php';
exigirMetodo('GET');
exigirCargo(['Gerente', 'Admin']);

$unidadeConsulta = (int)($_GET['unidade_id'] ?? 0);
$minhaUnidade    = (int)$id_loja;

if (!$unidadeConsulta) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unidade não informada.']);
    exit;
}

if ($unidadeConsulta === $minhaUnidade) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Selecione uma unidade diferente da sua unidade atual.']);
    exit;
}

try {
    // Valida se as duas unidades pertencem à mesma rede
    $stRedes = $pdo->prepare("SELECT id, rede_id, nome_fantasia, nome_unidade FROM instituicoes WHERE id IN (?, ?) AND ativo = 1");
    $stRedes->execute([$minhaUnidade, $unidadeConsulta]);
    $lojas = $stRedes->fetchAll(PDO::FETCH_UNIQUE);

    if (!isset($lojas[$minhaUnidade], $lojas[$unidadeConsulta])) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Uma das unidades não foi encontrada ou está inativa.']);
        exit;
    }

    if (empty($lojas[$minhaUnidade]['rede_id']) || (int)$lojas[$minhaUnidade]['rede_id'] !== (int)$lojas[$unidadeConsulta]['rede_id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Apenas unidades da mesma rede podem compartilhar estoque.']);
        exit;
    }

    // Busca produtos com saldo > 0 na unidade de origem
    $stProd = $pdo->prepare("SELECT id, nome_produto, codigo_barras, quantidade_estoque, unidade, preco_venda, marca
                             FROM produtos
                             WHERE instituicao_id = ? AND quantidade_estoque > 0
                             ORDER BY nome_produto ASC");
    $stProd->execute([$unidadeConsulta]);
    $produtos = $stProd->fetchAll();

    echo json_encode([
        'success'  => true,
        'unidade'  => [
            'id'     => $unidadeConsulta,
            'nome'   => trim((string)($lojas[$unidadeConsulta]['nome_unidade'] ?: $lojas[$unidadeConsulta]['nome_fantasia'])),
            'rotulo' => unidadeRotulo($lojas[$unidadeConsulta]),
        ],
        'produtos' => $produtos,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro ao consultar estoque da unidade: ' . $e->getMessage()]);
}

