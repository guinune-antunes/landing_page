<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['instituicao_id'])) {
    echo json_encode(['error' => 'Acesso negado.']);
    exit;
}

require 'conexao.php';

$isSelect2 = !empty($_GET['select2']);
$termo = trim($_GET['q'] ?? $_GET['termo'] ?? '');

if (!$isSelect2 && $termo === '') {
    echo json_encode([]);
    exit;
}

try {
    $instituicao_id = (int)$_SESSION['instituicao_id'];

    if (!$isSelect2) {
        // Modo compatível com leitor de código de barras (Enter no PDV)
        $sql = "SELECT id, nome_produto as nome, preco_venda as preco, codigo_barras 
                FROM produtos 
                WHERE instituicao_id = ? 
                AND (nome_produto LIKE ? OR codigo_barras = ?) 
                AND quantidade_estoque > 0 
                LIMIT 10";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$instituicao_id, "%$termo%", $termo]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // Modo Select2 (Produtos com estoque + Serviços do Catálogo)
    $results = [];

    $sqlP = "SELECT id, nome_produto AS nome, preco_venda AS preco, quantidade_estoque, codigo_barras
             FROM produtos
             WHERE instituicao_id = ? AND quantidade_estoque > 0";
    $pParams = [$instituicao_id];
    if ($termo !== '') {
        $sqlP .= " AND (nome_produto LIKE ? OR marca LIKE ? OR codigo_barras LIKE ?)";
        $like = "%{$termo}%";
        array_push($pParams, $like, $like, $like);
    }
    $sqlP .= " ORDER BY nome_produto ASC LIMIT 15";
    $stP = $pdo->prepare($sqlP);
    $stP->execute($pParams);
    $prodChildren = [];
    foreach ($stP->fetchAll(PDO::FETCH_ASSOC) as $pr) {
        $cod = !empty($pr['codigo_barras']) ? " [Cód: {$pr['codigo_barras']}]" : '';
        $prodChildren[] = [
            'id' => 'produto:' . (int)$pr['id'],
            'text' => $pr['nome'] . $cod . ' — R$ ' . number_format((float)$pr['preco'], 2, ',', '.') . ' (' . (int)$pr['quantidade_estoque'] . ' un)',
            'tipo' => 'produto',
            'raw_id' => (int)$pr['id'],
            'nome' => $pr['nome'],
            'preco' => (float)$pr['preco'],
            'estoque' => (int)$pr['quantidade_estoque'],
        ];
    }
    if (!empty($prodChildren)) {
        $results[] = ['text' => 'Produtos em Estoque', 'children' => $prodChildren];
    }

    $sqlS = "SELECT id, nome_servico AS nome, preco_padrao AS preco FROM servicos WHERE instituicao_id = ?";
    $sParams = [$instituicao_id];
    if ($termo !== '') {
        $sqlS .= " AND nome_servico LIKE ?";
        $sParams[] = "%{$termo}%";
    }
    $sqlS .= " ORDER BY nome_servico ASC LIMIT 10";
    $stS = $pdo->prepare($sqlS);
    $stS->execute($sParams);
    $servChildren = [];
    foreach ($stS->fetchAll(PDO::FETCH_ASSOC) as $sv) {
        $servChildren[] = [
            'id' => 'servico:' . (int)$sv['id'],
            'text' => $sv['nome'] . ' — R$ ' . number_format((float)$sv['preco'], 2, ',', '.'),
            'tipo' => 'servico',
            'raw_id' => (int)$sv['id'],
            'nome' => $sv['nome'],
            'preco' => (float)$sv['preco'],
        ];
    }
    if (!empty($servChildren)) {
        $results[] = ['text' => 'Serviços do Catálogo', 'children' => $servChildren];
    }

    echo json_encode(['results' => $results]);

} catch (PDOException $e) {
    echo json_encode($isSelect2 ? ['results' => []] : ['error' => 'Erro interno ao buscar produtos.']);
}
