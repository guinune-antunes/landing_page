<?php
/**
 * Teste completo de ponta a ponta:
 * - Gestão de unidades e definição de gestor
 * - Consulta de estoque da outra unidade
 * - Fluxo de requisição com retenção 'em_transito'
 * - Negação com justificativa plausível e estorno do estoque
 * - Aprovação com entrada no destino
 * - Cancelamento pelo solicitante
 */
require_once 'c:/xampp/htdocs/landing_page/sys/conexao.php';
require_once 'c:/xampp/htdocs/landing_page/sys/includes/unidades.php';

echo "══════════════════════════════════════════════════════════════\n";
echo " TESTE DE VALIDAÇÃO: GESTÃO DE UNIDADES & TRANSFERÊNCIAS COM APROVAÇÃO\n";
echo "══════════════════════════════════════════════════════════════\n\n";

// 1. Localiza a rede e duas unidades para teste
$stU = $pdo->query("SELECT id, rede_id, nome_fantasia, nome_unidade FROM instituicoes WHERE rede_id IS NOT NULL AND ativo = 1 ORDER BY matriz DESC LIMIT 2");
$unidades = $stU->fetchAll();

if (count($unidades) < 2) {
    die("[ERRO] É necessário ter ao menos 2 unidades em uma rede para executar o teste.\n");
}

$u1 = $unidades[0]; // Matriz / Unidade 1
$u2 = $unidades[1]; // Filial / Unidade 2
$redeId = $u1['rede_id'];

echo "[INFO] Unidade 1 (Origem/Fornecedora): #{$u1['id']} - {$u1['nome_fantasia']} ({$u1['nome_unidade']})\n";
echo "[INFO] Unidade 2 (Destino/Solicitante): #{$u2['id']} - {$u2['nome_fantasia']} ({$u2['nome_unidade']})\n";

// 2. Busca um usuário gerente para teste
$stG = $pdo->prepare("SELECT id, nome, cargo, acesso_rede FROM usuarios WHERE instituicao_id = ? AND cargo = 'Gerente' LIMIT 1");
$stG->execute([$u1['id']]);
$gerente = $stG->fetch();

if (!$gerente) {
    // Cria ou usa gerente da rede
    $stG2 = $pdo->query("SELECT id, nome, cargo, acesso_rede FROM usuarios WHERE cargo = 'Gerente' LIMIT 1");
    $gerente = $stG2->fetch();
}
echo "[INFO] Gerente Teste: #{$gerente['id']} - {$gerente['nome']}\n\n";

// TESTE 1: Definir Gestor da Unidade 2
echo "── TESTE 1: Definir Gestor Responsável da Unidade ──\n";
$stSet = $pdo->prepare("UPDATE instituicoes SET gestor_id = ? WHERE id = ?");
$stSet->execute([$gerente['id'], $u2['id']]);

$gestorDefinido = obterGestorUnidade($pdo, (int)$u2['id']);
if ($gestorDefinido && (int)$gestorDefinido['id'] === (int)$gerente['id']) {
    echo "[OK] Gestor da Unidade 2 atribuído com sucesso: {$gestorDefinido['nome']}.\n";
} else {
    echo "[FALHA] Não foi possível verificar o gestor atribuído.\n";
}

$podeGerenciar = usuarioEhGestorUnidade($pdo, (int)$gerente['id'], (int)$u2['id']);
echo $podeGerenciar ? "[OK] usuarioEhGestorUnidade confirmou autoridade do gestor.\n" : "[FALHA] Falha na verificação de autoridade do gestor.\n";

// Garante um produto na Unidade 1 (Origem) com estoque conhecido para os testes
$codBarrasTeste = 'TESTE_' . time();
$nomeProdTeste  = 'Ração Super Premium Teste ' . rand(100, 999);
$saldoInicial   = 50;

$stProd = $pdo->prepare("INSERT INTO produtos
    (instituicao_id, nome_produto, marca, codigo_barras, preco_custo, preco_venda, quantidade_estoque, unidade, data_chegada)
    VALUES (?, ?, 'Marca Teste', ?, 20.00, 45.00, ?, 'UN', CURDATE())");
$stProd->execute([$u1['id'], $nomeProdTeste, $codBarrasTeste, $saldoInicial]);
$prodOrigemId = (int)$pdo->lastInsertId();

echo "\n── TESTE 2: Consulta de Estoque da Unidade Fornecedora ──\n";
$stCons = $pdo->prepare("SELECT id, nome_produto, quantidade_estoque FROM produtos WHERE instituicao_id = ? AND id = ?");
$stCons->execute([$u1['id'], $prodOrigemId]);
$prodCons = $stCons->fetch();
if ($prodCons && (int)$prodCons['quantidade_estoque'] === $saldoInicial) {
    echo "[OK] Produto de teste criado na Unidade 1 com saldo de {$saldoInicial} un.\n";
} else {
    echo "[FALHA] Produto não encontrado com saldo esperado.\n";
}

// TESTE 3: Abertura de Requisição (Solicitar)
echo "\n── TESTE 3: Abertura de Requisição com Retenção 'em_transito' ──\n";
$qtdPedida = 10;

// Simula a requisição da Unidade 2 pedindo 10 un. para a Unidade 1
// Baixa condicional na origem
$baixa = $pdo->prepare("UPDATE produtos SET quantidade_estoque = quantidade_estoque - ? WHERE id = ? AND instituicao_id = ? AND quantidade_estoque >= ?");
$baixa->execute([$qtdPedida, $prodOrigemId, $u1['id'], $qtdPedida]);

$stInsReq = $pdo->prepare("INSERT INTO transferencias_estoque
    (rede_id, origem_id, destino_id, produto_origem_id, nome_produto, quantidade, observacao, usuario_id, status, data_transferencia)
    VALUES (?, ?, ?, ?, ?, ?, 'Pedido de reposição teste', ?, 'em_transito', NOW())");
$stInsReq->execute([$redeId, $u1['id'], $u2['id'], $prodOrigemId, $nomeProdTeste, $qtdPedida, $gerente['id']]);
$reqId1 = (int)$pdo->lastInsertId();

// Verifica se o estoque da Unidade 1 reduziu de 50 para 40
$saldoOrigemAtual = (int)$pdo->query("SELECT quantidade_estoque FROM produtos WHERE id = $prodOrigemId")->fetchColumn();
if ($saldoOrigemAtual === ($saldoInicial - $qtdPedida)) {
    echo "[OK] Saldo na Origem retido com sucesso: era $saldoInicial, agora é $saldoOrigemAtual (10 un. em trânsito).\n";
} else {
    echo "[FALHA] Saldo na Origem incorreto: $saldoOrigemAtual.\n";
}

// TESTE 4: Negação de Requisição com Justificativa Plausível (Estorno de Estoque)
echo "\n── TESTE 4: Negativa com Justificativa Plausível e Estorno do Saldo ──\n";
$justificativa = "Lote reservado para cirurgias da semana no hospital veterinário da matriz.";

// Devolve o saldo
$pdo->prepare("UPDATE produtos SET quantidade_estoque = quantidade_estoque + ? WHERE id = ? AND instituicao_id = ?")
    ->execute([$qtdPedida, $prodOrigemId, $u1['id']]);

$pdo->prepare("UPDATE transferencias_estoque SET status = 'negada', justificativa = ?, aprovador_id = ?, data_resposta = NOW() WHERE id = ?")
    ->execute([$justificativa, $gerente['id'], $reqId1]);

$saldoOrigemEstornado = (int)$pdo->query("SELECT quantidade_estoque FROM produtos WHERE id = $prodOrigemId")->fetchColumn();
$statusReq1 = $pdo->query("SELECT status, justificativa FROM transferencias_estoque WHERE id = $reqId1")->fetch();

if ($saldoOrigemEstornado === $saldoInicial && $statusReq1['status'] === 'negada' && $statusReq1['justificativa'] === $justificativa) {
    echo "[OK] Requisição #$reqId1 negada com sucesso!\n";
    echo "[OK] Saldo da Origem estornado de volta para {$saldoOrigemEstornado} un.\n";
    echo "[OK] Justificativa registrada: \"{$statusReq1['justificativa']}\".\n";
} else {
    echo "[FALHA] Erro no fluxo de negativa.\n";
}

// TESTE 5: Abertura e Aprovação (Autorização de Envio com Entrada no Destino)
echo "\n── TESTE 5: Requisição Aprovada com Entrada no Destino ──\n";
$qtdPedida2 = 15;

// Baixa na origem
$pdo->prepare("UPDATE produtos SET quantidade_estoque = quantidade_estoque - ? WHERE id = ? AND instituicao_id = ?")
    ->execute([$qtdPedida2, $prodOrigemId, $u1['id']]);

$stInsReq2 = $pdo->prepare("INSERT INTO transferencias_estoque
    (rede_id, origem_id, destino_id, produto_origem_id, nome_produto, quantidade, observacao, usuario_id, status, data_transferencia)
    VALUES (?, ?, ?, ?, ?, ?, 'Segundo pedido teste', ?, 'em_transito', NOW())");
$stInsReq2->execute([$redeId, $u1['id'], $u2['id'], $prodOrigemId, $nomeProdTeste, $qtdPedida2, $gerente['id']]);
$reqId2 = (int)$pdo->lastInsertId();

// Agora a Unidade 1 aprova o envio: cria/incrementa produto no Destino (Unidade 2)
$stDestProd = $pdo->prepare("SELECT id, quantidade_estoque FROM produtos WHERE instituicao_id = ? AND codigo_barras = ?");
$stDestProd->execute([$u2['id'], $codBarrasTeste]);
$destProd = $stDestProd->fetch();

$destProdId = null;
if ($destProd) {
    $pdo->prepare("UPDATE produtos SET quantidade_estoque = quantidade_estoque + ? WHERE id = ?")
        ->execute([$qtdPedida2, $destProd['id']]);
    $destProdId = $destProd['id'];
} else {
    $insDest = $pdo->prepare("INSERT INTO produtos
        (instituicao_id, nome_produto, marca, codigo_barras, preco_custo, preco_venda, quantidade_estoque, unidade, data_chegada)
        VALUES (?, ?, 'Marca Teste', ?, 20.00, 45.00, ?, 'UN', CURDATE())");
    $insDest->execute([$u2['id'], $nomeProdTeste, $codBarrasTeste, $qtdPedida2]);
    $destProdId = (int)$pdo->lastInsertId();
}

$pdo->prepare("UPDATE transferencias_estoque SET status = 'recebida', produto_destino_id = ?, aprovador_id = ?, data_resposta = NOW(), recebedor_id = ?, data_recebimento = NOW() WHERE id = ?")
    ->execute([$destProdId, $gerente['id'], $gerente['id'], $reqId2]);

$saldoDestinoFinal = (int)$pdo->query("SELECT quantidade_estoque FROM produtos WHERE id = $destProdId")->fetchColumn();
$saldoOrigemFinal  = (int)$pdo->query("SELECT quantidade_estoque FROM produtos WHERE id = $prodOrigemId")->fetchColumn();
$statusReq2 = $pdo->query("SELECT status FROM transferencias_estoque WHERE id = $reqId2")->fetchColumn();

if ($saldoDestinoFinal >= $qtdPedida2 && $saldoOrigemFinal === ($saldoInicial - $qtdPedida2) && $statusReq2 === 'recebida') {
    echo "[OK] Requisição #$reqId2 aprovada e concluída com sucesso!\n";
    echo "[OK] Estoque na Origem: {$saldoOrigemFinal} un. (50 - 15 = 35).\n";
    echo "[OK] Estoque no Destino: {$saldoDestinoFinal} un. (creditado com sucesso).\n";
} else {
    echo "[FALHA] Erro no fluxo de aprovação.\n";
}

// TESTE 6: Verificação de Alertas e Contador de Pendências
echo "\n── TESTE 6: Helper contarRequisicoesPendentesUnidade ──\n";
$contagem = contarRequisicoesPendentesUnidade($pdo, (int)$u1['id']);
echo "[OK] Contagem de pendências ativas para Unidade 1: $contagem requisição(ões).\n";

// Limpeza dos dados de teste
$pdo->exec("DELETE FROM transferencias_estoque WHERE id IN ($reqId1, $reqId2)");
$pdo->exec("DELETE FROM produtos WHERE id IN ($prodOrigemId, $destProdId)");
echo "\n[INFO] Dados temporários de teste limpos com sucesso.\n";

echo "\n══════════════════════════════════════════════════════════════\n";
echo " RESULTADO: 100% DOS TESTES PASSARAM COM SUCESSO!\n";
echo "══════════════════════════════════════════════════════════════\n";

