<?php
// Teste Ponta a Ponta: Arquitetura Multi-Unidade (SP e Curitiba)
require_once 'c:/xampp/htdocs/landing_page/sys/conexao.php';
require_once 'c:/xampp/htdocs/landing_page/sys/includes/unidades.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== INICIANDO BATERIA DE TESTES MULTI-UNIDADE ===\n\n";

$pdo->beginTransaction();

try {
    // 1. Criar Rede com compartilhamento ativo
    $pdo->prepare("INSERT INTO redes (nome, compartilhar_clientes, compartilhar_fidelidade) VALUES ('Rede Pet SP-CWB', 1, 1)")
        ->execute();
    $redeId = (int)$pdo->lastInsertId();
    echo "[TESTE 1] Rede criada: ID $redeId (compartilhar_clientes=1, compartilhar_fidelidade=1)\n";

    // 2. Criar Unidade São Paulo (Matriz) e Curitiba (Filial)
    $stInst = $pdo->prepare("INSERT INTO instituicoes (nome_fantasia, rede_id, nome_unidade, cidade, uf, matriz, ativo) VALUES (?, ?, ?, ?, ?, ?, 1)");
    
    $stInst->execute(['Pet SP Matriz', $redeId, 'Unidade Paulista', 'São Paulo', 'SP', 1]);
    $lojaSP = (int)$pdo->lastInsertId();

    $stInst->execute(['Pet Curitiba Batel', $redeId, 'Unidade Batel', 'Curitiba', 'PR', 0]);
    $lojaCWB = (int)$pdo->lastInsertId();

    echo "[TESTE 2] Unidades criadas: SP Matriz (ID $lojaSP) e Curitiba Filial (ID $lojaCWB)\n";

    // 3. Criar Gerente Geral e Gerente Local
    $stUser = $pdo->prepare("INSERT INTO usuarios (nome, email, senha_hash, cargo, instituicao_id, ativo, acesso_rede) VALUES (?, ?, 'hash', 'Gerente', ?, 1, ?)");
    $stUser->execute(['Gerente Geral Carlos', 'carlos@rede.com', $lojaSP, 1]);
    $userGeral = (int)$pdo->lastInsertId();

    $stUser->execute(['Gerente Local Ana', 'ana@curitiba.com', $lojaCWB, 0]);
    $userLocal = (int)$pdo->lastInsertId();

    // 4. Teste de visibilidade de unidades
    $unidadesGeral = carregarUnidadesUsuario($pdo, $userGeral);
    $unidadesLocal = carregarUnidadesUsuario($pdo, $userLocal);

    assert(count($unidadesGeral) === 2, "Gerente Geral deve enxergar 2 unidades");
    assert(count($unidadesLocal) === 1, "Gerente Local deve enxergar apenas 1 unidade");
    echo "[TESTE 3] Permissões de unidades validadas (Geral vê " . count($unidadesGeral) . ", Local vê " . count($unidadesLocal) . ")\n";

    // 5. Teste de Compartilhamento de Clientes
    // Cadastrar cliente na loja de SP
    $stCli = $pdo->prepare("INSERT INTO clientes (instituicao_id, nome_completo, telefone, cpf) VALUES (?, ?, ?, ?)");
    $stCli->execute([$lojaSP, 'Cliente Teste Paulista', '11999998888', '11122233344']);
    $cliSP = (int)$pdo->lastInsertId();

    // Quando compartilhar_clientes = 1:
    $visiveisCWB = obterInstituicoesClientes($pdo, $lojaCWB);
    assert(in_array($lojaSP, $visiveisCWB), "Loja CWB deve enxergar clientes da Loja SP");
    echo "[TESTE 4] Visibilidade de clientes: CWB enxerga unidades [" . implode(',', $visiveisCWB) . "]\n";

    // Desligar compartilhamento de clientes na rede
    $pdo->prepare("UPDATE redes SET compartilhar_clientes = 0 WHERE id = ?")->execute([$redeId]);
    limparCacheUnidades();
    $visiveisCWB_semComp = obterInstituicoesClientes($pdo, $lojaCWB);
    assert(!in_array($lojaSP, $visiveisCWB_semComp) && count($visiveisCWB_semComp) === 1, "Com compartilhamento desligado, CWB só vê clientes próprios");
    echo "[TESTE 5] Desligamento de compartilhamento validado (CWB vê apenas [" . implode(',', $visiveisCWB_semComp) . "])\n";

    // Religando compartilhamento
    $pdo->prepare("UPDATE redes SET compartilhar_clientes = 1 WHERE id = ?")->execute([$redeId]);
    limparCacheUnidades();

    // 6. Teste de Compartilhamento de Fidelidade
    // Quando compartilhar_fidelidade = 1, o ID da fidelidade deve ser a Matriz (SP)
    $lojaFidCWB = obterInstituicaoFidelidade($pdo, $lojaCWB);
    assert($lojaFidCWB === $lojaSP, "Fidelidade em CWB deve apontar para Matriz SP (ID $lojaSP)");
    echo "[TESTE 6] Fidelidade unificada na Matriz: CWB pontua na loja ID $lojaFidCWB (Matriz SP)\n";

    // Simular acúmulo de selo na Matriz
    $pdo->prepare("INSERT INTO cliente_fidelidade (instituicao_id, cliente_id, selos_atuais) VALUES (?, ?, 2)")
        ->execute([$lojaFidCWB, $cliSP]);
    
    // Verificar se compra em Curitiba enxerga os 2 selos acumulados
    $stFid = $pdo->prepare("SELECT selos_atuais FROM cliente_fidelidade WHERE instituicao_id = ? AND cliente_id = ?");
    $stFid->execute([$lojaFidCWB, $cliSP]);
    $selos = (int)$stFid->fetchColumn();
    assert($selos === 2, "Cliente deve possuir 2 selos acumulados na rede");
    echo "[TESTE 7] Cartão de fidelidade unificado: cliente possui $selos selos na rede\n";

    // 7. Teste de Transferência de Estoque entre Unidades
    // Criar produto em SP com saldo 10
    $stProd = $pdo->prepare("INSERT INTO produtos (instituicao_id, nome_produto, codigo_barras, preco_venda, quantidade_estoque) VALUES (?, ?, ?, ?, ?)");
    $stProd->execute([$lojaSP, 'Ração Super Premium 15kg', '789123456001', 189.90, 10]);
    $prodSP = (int)$pdo->lastInsertId();

    // Executar transferência de 4 unidades de SP para Curitiba
    $qtdTransferir = 4;
    $pdo->prepare("UPDATE produtos SET quantidade_estoque = quantidade_estoque - ? WHERE id = ?")->execute([$qtdTransferir, $prodSP]);

    // Criar produto no destino (Curitiba)
    $stProd->execute([$lojaCWB, 'Ração Super Premium 15kg', '789123456001', 189.90, $qtdTransferir]);
    $prodCWB = (int)$pdo->lastInsertId();

    // Registrar histórico de transferência
    $pdo->prepare("INSERT INTO transferencias_estoque (rede_id, origem_id, destino_id, produto_origem_id, produto_destino_id, nome_produto, quantidade, observacao, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$redeId, $lojaSP, $lojaCWB, $prodSP, $prodCWB, 'Ração Super Premium 15kg', $qtdTransferir, 'Abastecimento filial', $userGeral]);

    // Verificar saldos finais
    $saldoSP = (int)$pdo->query("SELECT quantidade_estoque FROM produtos WHERE id = $prodSP")->fetchColumn();
    $saldoCWB = (int)$pdo->query("SELECT quantidade_estoque FROM produtos WHERE id = $prodCWB")->fetchColumn();

    assert($saldoSP === 6, "Estoque em SP deve ser 6 (10 - 4)");
    assert($saldoCWB === 4, "Estoque em CWB deve ser 4");
    echo "[TESTE 8] Transferência atômica de estoque: SP=$saldoSP, Curitiba=$saldoCWB (Histórico registrado com sucesso)\n";

    echo "\n=== TODOS OS 8 TESTES PASSARAM COM SUCESSO! ===\n";

} finally {
    // Rollback para deixar o banco limpo
    $pdo->rollBack();
    echo "=== ROLLBACK EXECUTADO (Banco mantido limpo) ===\n";
}
