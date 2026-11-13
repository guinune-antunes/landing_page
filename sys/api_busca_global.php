<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/includes/unidades.php';

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['results' => [], 'error' => 'Acesso negado.']);
    exit;
}

$cargo = $_SESSION['usuario_cargo'] ?? '';
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$idLoja = (int)($_SESSION['instituicao_id'] ?? 0);

if ($idLoja <= 0 && $cargo === 'Admin') {
    $idLoja = (int)$pdo->query("SELECT id FROM instituicoes ORDER BY id ASC LIMIT 1")->fetchColumn();
}

if ($idLoja <= 0) {
    echo json_encode(['results' => []]);
    exit;
}

$idsClientes = obterInstituicoesClientes($pdo, $idLoja);
$inCli = implode(',', array_map('intval', $idsClientes));

$q = trim($_GET['q'] ?? $_GET['termo'] ?? '');
$tipo = trim($_GET['tipo'] ?? 'all');

// Descobre permissões rápidas para escolher link de destino adequado na busca global
$permissoes = [];
if ($cargo !== 'Admin' && $usuarioId > 0) {
    try {
        $stPerm = $pdo->prepare("SELECT permissoes FROM usuarios WHERE id = ?");
        $stPerm->execute([$usuarioId]);
        $permissoes = json_decode((string)$stPerm->fetchColumn(), true) ?: [];
    } catch (Throwable $e) {
        $permissoes = [];
    }
}

$podeVerRelatorioCrm = ($cargo === 'Gerente' || !empty($permissoes['crm_vendas']) || !empty($permissoes['relatorios']) || !empty($permissoes['clientes']));
$podeVerProdutos = ($cargo === 'Gerente');

if ($tipo === 'clientes' || $tipo === 'relatorio') {
    $idLojaFid = obterInstituicaoFidelidade($pdo, $idLoja);
    $sql = "SELECT c.id, c.nome_completo, c.telefone, c.cpf, c.email,
                   COALESCE(cf.selos_atuais, 0) AS selos,
                   COALESCE(cf.ciclos_completados, 0) AS ciclos_completados,
                   COALESCE(cf.pontos_acumulados, 0) AS pontos,
                   COALESCE(cf.saldo_cashback, 0.00) AS saldo_cashback,
                   COALESCE(cf.tier_atual, 'Bronze') AS tier,
                   (SELECT GROUP_CONCAT(p.nome SEPARATOR ', ') FROM pets p WHERE p.cliente_id = c.id) AS pets_nomes
            FROM clientes c
            LEFT JOIN cliente_fidelidade cf ON cf.cliente_id = c.id AND cf.instituicao_id = ?";

    $params = [$idLojaFid];
    if ($q !== '') {
        $like = '%' . $q . '%';
        $sql .= " WHERE c.instituicao_id IN ($inCli)
                  AND (c.nome_completo LIKE ? OR c.telefone LIKE ? OR c.cpf LIKE ? OR c.email LIKE ?
                       OR EXISTS (SELECT 1 FROM pets px WHERE px.cliente_id = c.id AND px.nome LIKE ?))";
        array_push($params, $like, $like, $like, $like, $like);
    } else {
        $sql .= " WHERE c.instituicao_id IN ($inCli)";
    }
    $sql .= " ORDER BY c.nome_completo ASC LIMIT 25";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($rows as $r) {
        $tel = trim((string)($r['telefone'] ?? ''));
        $pets = trim((string)($r['pets_nomes'] ?? ''));
        $label = $r['nome_completo'];
        if ($tel !== '') $label .= " · {$tel}";
        if ($pets !== '') $label .= " (Pets: {$pets})";

        $results[] = [
            'id' => (int)$r['id'],
            'text' => $label,
            'tipo' => 'cliente',
            'nome_completo' => $r['nome_completo'],
            'telefone' => $r['telefone'],
            'cpf' => $r['cpf'],
            'tier' => $r['tier'],
            'selos' => (int)$r['selos'],
            'pontos' => (int)$r['pontos'],
            'saldo_cashback' => (float)$r['saldo_cashback'],
            'pets_nomes' => $pets,
            'url' => 'relatorio_crm.php?cliente_id=' . (int)$r['id'],
        ];
    }

    echo json_encode(['results' => $results]);
    exit;
}

// Modo Global ('all') para a barra de pesquisa no _header.php
$results = [];
$like = '%' . $q . '%';

if ($cargo === 'Admin') {
    $sqlLojas = "SELECT id, nome_fantasia, cnpj, cidade, uf FROM instituicoes";
    $pLojas = [];
    if ($q !== '') {
        $sqlLojas .= " WHERE nome_fantasia LIKE ? OR cnpj LIKE ? OR cidade LIKE ?";
        $pLojas = [$like, $like, $like];
    }
    $sqlLojas .= " ORDER BY nome_fantasia ASC LIMIT 12";
    $stL = $pdo->prepare($sqlLojas);
    $stL->execute($pLojas);
    $childrenLojas = [];
    foreach ($stL->fetchAll(PDO::FETCH_ASSOC) as $lj) {
        $loc = trim(($lj['cidade'] ?? '') . (!empty($lj['uf']) ? '/' . $lj['uf'] : ''));
        $childrenLojas[] = [
            'id' => 'loja:' . $lj['id'],
            'text' => $lj['nome_fantasia'] . ($loc ? " ({$loc})" : ''),
            'tipo' => 'loja',
            'subtitulo' => $lj['cnpj'] ?: 'Instituição SaaS',
            'url' => 'gerenciar_instituicao.php?id=' . (int)$lj['id'],
        ];
    }
    if ($childrenLojas) {
        $results[] = ['text' => 'Instituições / Lojas', 'children' => $childrenLojas];
    }
    echo json_encode(['results' => $results]);
    exit;
}

// 1. Clientes
$sqlCli = "SELECT id, nome_completo, telefone, cpf FROM clientes WHERE instituicao_id IN ($inCli)";
$pCli = [];
if ($q !== '') {
    $sqlCli .= " AND (nome_completo LIKE ? OR telefone LIKE ? OR cpf LIKE ? OR email LIKE ?)";
    $pCli = [$like, $like, $like, $like];
}
$sqlCli .= " ORDER BY nome_completo ASC LIMIT 8";
$stCli = $pdo->prepare($sqlCli);
$stCli->execute($pCli);
$cliChildren = [];
foreach ($stCli->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $cliChildren[] = [
        'id' => 'cli:' . $c['id'],
        'text' => $c['nome_completo'] . (!empty($c['telefone']) ? ' · ' . $c['telefone'] : ''),
        'tipo' => 'cliente',
        'subtitulo' => 'Relatório 360° & Histórico do Tutor',
        'url' => ($podeVerRelatorioCrm ? 'relatorio_crm.php?cliente_id=' : 'clientes.php?cliente_id=') . (int)$c['id'],
    ];
}
if ($cliChildren) {
    $results[] = ['text' => 'Clientes / Tutores', 'children' => $cliChildren];
}

// 2. Pets
$sqlPet = "SELECT p.id, p.nome, p.especie, p.raca, p.cliente_id, c.nome_completo AS tutor_nome
           FROM pets p
           JOIN clientes c ON c.id = p.cliente_id
           WHERE c.instituicao_id IN ($inCli)";
$pPet = [];
if ($q !== '') {
    $sqlPet .= " AND (p.nome LIKE ? OR p.raca LIKE ? OR c.nome_completo LIKE ?)";
    $pPet = [$like, $like, $like];
}
$sqlPet .= " ORDER BY p.nome ASC LIMIT 8";
$stPet = $pdo->prepare($sqlPet);
$stPet->execute($pPet);
$petChildren = [];
foreach ($stPet->fetchAll(PDO::FETCH_ASSOC) as $pt) {
    $det = trim(($pt['especie'] ?? '') . (!empty($pt['raca']) ? ' · ' . $pt['raca'] : ''));
    $petChildren[] = [
        'id' => 'pet:' . $pt['id'],
        'text' => $pt['nome'] . ($det ? " ({$det})" : '') . " — Tutor: {$pt['tutor_nome']}",
        'tipo' => 'pet',
        'subtitulo' => 'Abrir Dossiê CRM do Tutor',
        'url' => 'relatorio_crm.php?cliente_id=' . (int)$pt['cliente_id'],
    ];
}
if ($petChildren) {
    $results[] = ['text' => 'Pets', 'children' => $petChildren];
}

// 3. Produtos
$sqlProd = "SELECT id, nome_produto, preco_venda, quantidade_estoque, codigo_barras
            FROM produtos WHERE instituicao_id = ?";
$pProd = [$idLoja];
if ($q !== '') {
    $sqlProd .= " AND (nome_produto LIKE ? OR marca LIKE ? OR codigo_barras LIKE ?)";
    array_push($pProd, $like, $like, $like);
}
$sqlProd .= " ORDER BY nome_produto ASC LIMIT 6";
$stProd = $pdo->prepare($sqlProd);
$stProd->execute($pProd);
$prodChildren = [];
foreach ($stProd->fetchAll(PDO::FETCH_ASSOC) as $pr) {
    $prodChildren[] = [
        'id' => 'prod:' . $pr['id'],
        'text' => $pr['nome_produto'] . ' — R$ ' . number_format((float)$pr['preco_venda'], 2, ',', '.'),
        'tipo' => 'produto',
        'subtitulo' => 'Estoque: ' . (int)$pr['quantidade_estoque'] . ' un',
        'url' => $podeVerProdutos
            ? 'produtos.php?busca=' . urlencode($pr['nome_produto'])
            : 'vendas.php?produto_id=' . (int)$pr['id'],
    ];
}
if ($prodChildren) {
    $results[] = ['text' => 'Produtos', 'children' => $prodChildren];
}

// 4. Serviços
$sqlServ = "SELECT id, nome_servico, preco_padrao FROM servicos WHERE instituicao_id = ?";
$pServ = [$idLoja];
if ($q !== '') {
    $sqlServ .= " AND nome_servico LIKE ?";
    $pServ[] = $like;
}
$sqlServ .= " ORDER BY nome_servico ASC LIMIT 6";
$stServ = $pdo->prepare($sqlServ);
$stServ->execute($pServ);
$servChildren = [];
foreach ($stServ->fetchAll(PDO::FETCH_ASSOC) as $sv) {
    $servChildren[] = [
        'id' => 'serv:' . $sv['id'],
        'text' => $sv['nome_servico'] . ' — R$ ' . number_format((float)$sv['preco_padrao'], 2, ',', '.'),
        'tipo' => 'servico',
        'subtitulo' => 'Agendamento / Caixa PDV',
        'url' => 'agenda.php',
    ];
}
if ($servChildren) {
    $results[] = ['text' => 'Serviços', 'children' => $servChildren];
}

echo json_encode(['results' => $results]);

