<?php
/**
 * Suite de Testes End-to-End: Política CRM, Reentrada de Regras/Promoções, Motor de Fidelidade & PDV.
 * Executa contra o banco isolado `petcrm_politicacrm` na branch `politicacrm`.
 */

require_once __DIR__ . '/../sys/conexao.php';
require_once __DIR__ . '/../sys/includes/motor_fidelidade.php';

$dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
$expectedDb = getenv('PETCRM_TEST_DB') ?: 'petcrm_politicacrm';
if ($dbName !== $expectedDb) {
    fwrite(STDERR, "ABORTADO: Banco ativo e '{$dbName}', esperado '{$expectedDb}'.\n");
    exit(2);
}

$passed = 0;
$failed = 0;
$failures = [];

function ok(bool $cond, string $label, string $detail = ''): void {
    global $passed, $failed, $failures;
    if ($cond) {
        $passed++;
        echo "[PASS] {$label}\n";
    } else {
        $failed++;
        $msg = $label . ($detail !== '' ? " -> {$detail}" : '');
        $failures[] = $msg;
        echo "[FAIL] {$msg}\n";
    }
}

function near(float $a, float $b, float $eps = 0.02): bool {
    return abs($a - $b) <= $eps;
}

/**
 * Simula uma venda completa chamando o motor (evaluation + execution + registro em `vendas`).
 */
function simVenda(
    PDO $pdo,
    int $lojaId,
    ?int $cliId,
    array $itens,
    array $opcoes = []
): array {
    $agoraTs = $opcoes['agora_ts'] ?? time();
    $descManual = (float)($opcoes['desconto_manual'] ?? 0.0);
    $participar = array_key_exists('participar_fidelidade', $opcoes) ? (bool)$opcoes['participar_fidelidade'] : true;

    if ($participar) {
        $eval = crmAvaliarCarrinhoCompleto($pdo, $lojaId, $lojaId, [$lojaId], $itens, $cliId, $descManual, $opcoes);
    } else {
        $itensNorm = crmNormalizarCarrinho($itens);
        $promoEval = crmAvaliarPromocoesCarrinho($pdo, $lojaId, $itensNorm, $cliId, $agoraTs);
        $sub = array_sum(array_column($itensNorm, 'subtotal'));
        $descPromo = min($sub, $promoEval['total_desconto_promocao']);
        $eval = [
            'subtotal_bruto'      => round($sub, 2),
            'desconto_promocao'   => round($descPromo, 2),
            'desconto_fidelidade' => 0.0,
            'desconto_classico'   => 0.0,
            'cashback_utilizado'  => 0.0,
            'desconto_total'      => round($descPromo + $descManual, 2),
            'total_liquido'       => max(0.0, round($sub - $descPromo - $descManual, 2)),
            'cashback_gerado'     => 0.0,
            'pontos_gerados'      => 0,
            'promocoes'           => $promoEval,
            'regras'              => [],
            'regras_modulares'    => [],
            'regras_bloqueadas'   => [],
            'cartao_classico'     => null,
            'cliente'             => null,
            'config'              => [],
        ];
    }

    $pdo->prepare("INSERT INTO vendas (instituicao_id, cliente_id, valor_total, desconto, desconto_fidelidade, desconto_promocao, cashback_gerado, cashback_utilizado, pontos_gerados, forma_pagamento, data_venda)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'PIX', ?)")
        ->execute([
            $lojaId,
            $cliId,
            $eval['total_liquido'],
            $eval['desconto_total'],
            $eval['desconto_fidelidade'],
            $eval['desconto_promocao'],
            $eval['cashback_gerado'],
            $eval['cashback_utilizado'],
            $eval['pontos_gerados'],
            date('Y-m-d H:i:s', $agoraTs),
        ]);
    $vendaId = (int)$pdo->lastInsertId();

    $exec = crmExecutarBeneficiosVenda($pdo, $lojaId, $lojaId, $vendaId, $cliId, $eval, 'TesteAuto', array_merge($opcoes, [
        'participar_fidelidade' => $participar,
        'agora_ts' => $agoraTs,
    ]));

    return ['venda_id' => $vendaId, 'eval' => $eval, 'exec' => $exec];
}

// Estado da fixture para limpeza garantida em finally
$lojaId = null;
$serverProc = null;
$cookieFile = tempnam(sys_get_temp_dir(), 'crm_cookie_');

try {
    echo "=== Banco validado: {$dbName} ===\n\n";

    // Cria loja isolada para a bateria de testes
    $pdo->prepare("INSERT INTO instituicoes (nome_fantasia, cnpj, plano, ativo) VALUES ('PetShop Teste PoliticaCRM', '99999999000199', 'premium', 1)")
        ->execute();
    $lojaId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO fidelidade_config (instituicao_id, ativo, tipo_programa, compras_para_recompensa, recompensa_tipo, recompensa_valor, limite_desconto_recompensa, reais_por_ponto, cashback_percentual, tiers_config)
                   VALUES (?, 1, 'selos', 5, 'desconto_percentual', 15.00, 100.00, 1.00, 5.00, ?)")
        ->execute([$lojaId, json_encode([
            ['nome' => 'Bronze', 'meta' => 0],
            ['nome' => 'Prata', 'meta' => 250],
            ['nome' => 'Ouro VIP', 'meta' => 600],
        ])]);

    // Clientes de teste
    $mesAtualDia = date('Y-m-d', strtotime('-20 years')); // aniversariante neste mês
    $mesOutro = date('Y-m-d', strtotime('-20 years +3 months'));
    $pdo->prepare("INSERT INTO clientes (instituicao_id, nome_completo, telefone, data_nascimento) VALUES (?, 'Tutor Um', '11999990001', ?)")
        ->execute([$lojaId, $mesOutro]);
    $cli1 = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO clientes (instituicao_id, nome_completo, telefone, data_nascimento) VALUES (?, 'Tutor Dois Aniversariante', '11999990002', ?)")
        ->execute([$lojaId, $mesAtualDia]);
    $cli2 = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO clientes (instituicao_id, nome_completo, telefone, data_nascimento) VALUES (?, 'Tutor Tres', '11999990003', ?)")
        ->execute([$lojaId, $mesOutro]);
    $cli3 = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO clientes (instituicao_id, nome_completo, telefone, data_nascimento) VALUES (?, 'Tutor Pet Aniv', '11999990004', ?)")
        ->execute([$lojaId, $mesOutro]);
    $cli4 = (int)$pdo->lastInsertId();

    // Pets (Pet do cli1 para racao; Pet do cli4 faz aniversario este mes)
    $pdo->prepare("INSERT INTO pets (instituicao_id, cliente_id, nome, especie, data_nascimento) VALUES (?, ?, 'Thor', 'Cachorro', ?)")
        ->execute([$lojaId, $cli1, $mesOutro]);
    $pet1 = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO pets (instituicao_id, cliente_id, nome, especie, data_nascimento) VALUES (?, ?, 'Luna', 'Gato', ?)")
        ->execute([$lojaId, $cli4, $mesAtualDia]);
    $pet4 = (int)$pdo->lastInsertId();

    // Produtos e Serviços de teste
    $pdo->prepare("INSERT INTO produtos (instituicao_id, nome_produto, preco_venda, quantidade_estoque, categoria, tamanho_embalagem_kg) VALUES (?, 'Ração Super Premium 15kg', 200.00, 50, 'Alimentos', 15.00)")
        ->execute([$lojaId]);
    $prodRacao = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO produtos (instituicao_id, nome_produto, preco_venda, quantidade_estoque, categoria) VALUES (?, 'Petisco Natural', 40.00, 100, 'Alimentos')")
        ->execute([$lojaId]);
    $prodPetisco = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO servicos (instituicao_id, nome_servico, preco_padrao, duracao_minutos_padrao) VALUES (?, 'Banho e Tosa Porte M', 100.00, 60)")
        ->execute([$lojaId]);
    $servBanho = (int)$pdo->lastInsertId();

    $baseTs = strtotime('2026-11-10 10:00:00'); // Terça-feira

    // =========================================================================
    // SUITE A: MOTOR DE REGRAS, POLÍTICAS DE REENTRADA E PROMOÇÕES RELÂMPAGO
    // =========================================================================
    echo "--- Suite A: Promoções Relâmpago (politica_reentrada / limites) ---\n";

    // A1: Promoção 'unica_vez' + walk-in pendente de identificação
    $pdo->prepare("INSERT INTO promocoes (instituicao_id, titulo, produto_id, desconto_percentual, preco_original, preco_promocional, data_inicio, data_fim, ativo, politica_reentrada, aplicar_automatico_pdv)
                   VALUES (?, 'Flash Petisco Unica Vez', ?, 50.00, 40.00, 20.00, '2026-11-01 00:00:00', '2026-12-31 23:59:59', 1, 'unica_vez', 1)")
        ->execute([$lojaId, $prodPetisco]);
    $promoUnica = (int)$pdo->lastInsertId();

    $itemPetisco = [['id' => $prodPetisco, 'tipo' => 'produto', 'nome' => 'Petisco Natural', 'preco' => 40.00, 'qtd' => 1]];

    // Anônimo não deve aplicar promoção 'unica_vez' e deve sinalizar pendentes_identificacao
    $evalAnon = crmAvaliarCarrinhoCompleto($pdo, $lojaId, $lojaId, [$lojaId], $itemPetisco, null, 0.0, ['agora_ts' => $baseTs]);
    ok(
        near((float)$evalAnon['desconto_promocao'], 0.0) && count($evalAnon['promocoes']['pendentes_identificacao']) === 1,
        'A1.1 Anônimo não recebe promo unica_vez sem identificar cliente'
    );

    // 1a compra do cli1 aplica 50% (R$ 20) e grava promocao_usos
    $vA1_1 = simVenda($pdo, $lojaId, $cli1, $itemPetisco, ['agora_ts' => $baseTs]);
    $usoCount = (int)$pdo->query("SELECT COUNT(*) FROM promocao_usos WHERE promocao_id = {$promoUnica} AND cliente_id = {$cli1}")->fetchColumn();
    ok(
        near((float)$vA1_1['eval']['desconto_promocao'], 20.0) && near((float)$vA1_1['eval']['total_liquido'], 20.0) && $usoCount === 1,
        'A1.2 1ª compra em promo unica_vez aplica R$ 20,00 e registra promocao_usos'
    );

    // 2a compra do cli1 deve ser BLOQUEADA
    $vA1_2 = simVenda($pdo, $lojaId, $cli1, $itemPetisco, ['agora_ts' => $baseTs + 3600]);
    ok(
        near((float)$vA1_2['eval']['desconto_promocao'], 0.0) && near((float)$vA1_2['eval']['total_liquido'], 40.0) && count($vA1_2['eval']['promocoes']['bloqueadas']) === 1,
        'A1.3 2ª compra em promo unica_vez é bloqueada (cobra R$ 40,00 integral)'
    );

    // Desativa promoUnica para isolar próximos testes
    $pdo->exec("UPDATE promocoes SET ativo = 0 WHERE id = {$promoUnica}");

    // A2: Promoção 'uma_por_dia'
    $pdo->prepare("INSERT INTO promocoes (instituicao_id, titulo, produto_id, desconto_percentual, preco_original, preco_promocional, data_inicio, data_fim, ativo, politica_reentrada, aplicar_automatico_pdv)
                   VALUES (?, 'Flash 1 Por Dia', ?, 25.00, 40.00, 30.00, '2026-11-01 00:00:00', '2026-12-31 23:59:59', 1, 'uma_por_dia', 1)")
        ->execute([$lojaId, $prodPetisco]);
    $promoDia = (int)$pdo->lastInsertId();

    $vA2_1 = simVenda($pdo, $lojaId, $cli1, $itemPetisco, ['agora_ts' => $baseTs]);
    $vA2_2 = simVenda($pdo, $lojaId, $cli1, $itemPetisco, ['agora_ts' => $baseTs + 7200]); // mesmo dia
    $vA2_3 = simVenda($pdo, $lojaId, $cli1, $itemPetisco, ['agora_ts' => $baseTs + 86400]); // dia seguinte
    ok(
        near((float)$vA2_1['eval']['desconto_promocao'], 10.0) &&
        near((float)$vA2_2['eval']['desconto_promocao'], 0.0) &&
        near((float)$vA2_3['eval']['desconto_promocao'], 10.0),
        'A2 Promoção uma_por_dia: aplica hoje, bloqueia 2ª hoje, libera amanhã'
    );
    $pdo->exec("UPDATE promocoes SET ativo = 0 WHERE id = {$promoDia}");

    // A3: Promoção 'cooldown_dias' (7 dias) + limite_usos_cliente=2 + limite_usos_global=3
    $pdo->prepare("INSERT INTO promocoes (instituicao_id, titulo, produto_id, desconto_percentual, preco_original, preco_promocional, data_inicio, data_fim, ativo, politica_reentrada, cooldown_dias, limite_usos_cliente, limite_usos_global, aplicar_automatico_pdv)
                   VALUES (?, 'Flash Cooldown 7d + Caps', ?, 10.00, 200.00, 180.00, '2026-11-01 00:00:00', '2026-12-31 23:59:59', 1, 'cooldown_dias', 7, 2, 3, 1)")
        ->execute([$lojaId, $prodRacao]);
    $promoCool = (int)$pdo->lastInsertId();
    $itemRacao = [['id' => $prodRacao, 'tipo' => 'produto', 'nome' => 'Ração Super Premium 15kg', 'preco' => 200.00, 'qtd' => 1]];

    $vA3_d0 = simVenda($pdo, $lojaId, $cli1, $itemRacao, ['agora_ts' => $baseTs]);
    $vA3_d2 = simVenda($pdo, $lojaId, $cli1, $itemRacao, ['agora_ts' => $baseTs + 2 * 86400]); // bloqueada cooldown
    $vA3_d8 = simVenda($pdo, $lojaId, $cli1, $itemRacao, ['agora_ts' => $baseTs + 8 * 86400]); // uso 2 do cli1 (total global 2)
    $vA3_d16 = simVenda($pdo, $lojaId, $cli1, $itemRacao, ['agora_ts' => $baseTs + 16 * 86400]); // bloqueada limite_usos_cliente=2
    $vA3_cli2 = simVenda($pdo, $lojaId, $cli2, $itemRacao, ['agora_ts' => $baseTs + 16 * 86400]); // cli2 pega uso global #3
    $vA3_cli3 = simVenda($pdo, $lojaId, $cli3, $itemRacao, ['agora_ts' => $baseTs + 16 * 86400]); // bloqueada limite_usos_global=3

    ok(
        near((float)$vA3_d0['eval']['desconto_promocao'], 20.0) &&
        near((float)$vA3_d2['eval']['desconto_promocao'], 0.0) &&
        near((float)$vA3_d8['eval']['desconto_promocao'], 20.0) &&
        near((float)$vA3_d16['eval']['desconto_promocao'], 0.0) &&
        near((float)$vA3_cli2['eval']['desconto_promocao'], 20.0) &&
        near((float)$vA3_cli3['eval']['desconto_promocao'], 0.0),
        'A3/A4 Promoção cooldown_dias=7 + limite_usos_cliente=2 + limite_usos_global=3'
    );
    $pdo->exec("UPDATE promocoes SET ativo = 0 WHERE id = {$promoCool}");

    echo "\n--- Suite A: Regras Modulares & Políticas de Reentrada ---\n";

    // Limpa progresso de teste entre blocos desativando regras anteriores
    // A5: Modular Rule 'unica_vez' (meta=2, desconto_fixo=30, auto_resgatar_pdv=1)
    $pdo->prepare("INSERT INTO fidelidade_regras (instituicao_id, nome_regra, tipo_mecanica, categoria_alvo, item_alvo_id, item_alvo_tipo, condicao_tipo, condicao_valor, recompensa_tipo, recompensa_valor, politica_reentrada, auto_resgatar_pdv, ativo)
                   VALUES (?, 'Clube Unico Servico', 'compre_ganhe', 'item_especifico', ?, 'servico', 'quantidade_compras', 2, 'desconto_fixo', 30.00, 'unica_vez', 1, 1)")
        ->execute([$lojaId, $servBanho]);
    $rUnica = (int)$pdo->lastInsertId();
    $itemBanho = [['id' => $servBanho, 'tipo' => 'servico', 'nome' => 'Banho e Tosa Porte M', 'preco' => 100.00, 'qtd' => 1]];

    $u1 = simVenda($pdo, $lojaId, $cli1, $itemBanho, ['agora_ts' => $baseTs]); // 1/2
    $u2 = simVenda($pdo, $lojaId, $cli1, $itemBanho, ['agora_ts' => $baseTs + 100]); // 2/2 -> recompensa_disponivel=1
    $u3 = simVenda($pdo, $lojaId, $cli1, $itemBanho, ['agora_ts' => $baseTs + 200]); // resgata desconto 30 e trava ciclo unico
    $u4 = simVenda($pdo, $lojaId, $cli1, $itemBanho, ['agora_ts' => $baseTs + 300]); // bloqueada (não inicia 2o ciclo)

    $progUnica = $pdo->query("SELECT * FROM cliente_progresso_regras WHERE cliente_id = {$cli1} AND regra_id = {$rUnica}")->fetch(PDO::FETCH_ASSOC);
    ok(
        $u1['eval']['regras_modulares'][0]['progresso_depois'] === 1 &&
        $u2['eval']['regras_modulares'][0]['recompensa_disponivel_depois'] === 1 &&
        near((float)$u3['eval']['desconto_fidelidade'], 30.0) &&
        (int)$progUnica['ciclos_completados'] === 1 &&
        (int)$progUnica['bloqueado_reentrada'] === 1 &&
        count($u4['eval']['regras_bloqueadas']) >= 1 &&
        near((float)$u4['eval']['desconto_fidelidade'], 0.0),
        'A5 Regra unica_vez: acumula 1->2, resgata R$ 30, bloqueia reentrada no 2º ciclo'
    );
    $pdo->exec("UPDATE fidelidade_regras SET ativo = 0 WHERE id = {$rUnica}");

    // A6: 'reiniciar_apos_resgate' com auto_resgatar_pdv=0 + Resgate de Balcão + reentrada
    $pdo->prepare("INSERT INTO fidelidade_regras (instituicao_id, nome_regra, tipo_mecanica, categoria_alvo, item_alvo_id, item_alvo_tipo, condicao_tipo, condicao_valor, recompensa_tipo, recompensa_valor, politica_reentrada, auto_resgatar_pdv, ativo)
                   VALUES (?, 'Cartao Banho Balcao', 'cartao_selos', 'item_especifico', ?, 'servico', 'quantidade_compras', 2, 'desconto_percentual', 50.00, 'reiniciar_apos_resgate', 0, 1)")
        ->execute([$lojaId, $servBanho]);
    $rBalcao = (int)$pdo->lastInsertId();

    $b1 = simVenda($pdo, $lojaId, $cli1, $itemBanho, ['agora_ts' => $baseTs]); // 1/2
    $b2 = simVenda($pdo, $lojaId, $cli1, $itemBanho, ['agora_ts' => $baseTs + 100]); // 2/2 -> recompensa_disponivel=1
    $b3 = simVenda($pdo, $lojaId, $cli1, $itemBanho, ['agora_ts' => $baseTs + 200]); // auto_resgatar=0 -> aguardando_resgate (sem reiniciar)
    $resBalcao = crmResgatarRegraModularBalcao($pdo, $lojaId, $cli1, $rBalcao, 'Gerente', $baseTs + 250);
    $b4 = simVenda($pdo, $lojaId, $cli1, $itemBanho, ['agora_ts' => $baseTs + 300]); // inicia 2º ciclo (1/2)

    $progBalcao = $pdo->query("SELECT * FROM cliente_progresso_regras WHERE cliente_id = {$cli1} AND regra_id = {$rBalcao}")->fetch(PDO::FETCH_ASSOC);
    ok(
        $b3['eval']['regras_modulares'][0]['acao_venda'] === 'aguardando_resgate' &&
        !empty($resBalcao['success']) &&
        (int)$resBalcao['ciclos_completados'] === 1 &&
        $b4['eval']['regras_modulares'][0]['progresso_antes'] === 0 &&
        $b4['eval']['regras_modulares'][0]['progresso_depois'] === 1 &&
        (int)$progBalcao['ciclos_completados'] === 1 &&
        (int)$progBalcao['progresso_atual'] === 1,
        'A6 Regra reiniciar_apos_resgate + resgate manual no balcão inicia 2º ciclo (0 -> 1)'
    );
    $pdo->exec("UPDATE fidelidade_regras SET ativo = 0 WHERE id = {$rBalcao}");

    // A7: 'reiniciar_ao_concluir' + contagem_por_item=1 + acumular_sobra=1 (e vs acumular_sobra=0)
    $pdo->prepare("INSERT INTO fidelidade_regras (instituicao_id, nome_regra, tipo_mecanica, categoria_alvo, item_alvo_id, item_alvo_tipo, condicao_tipo, condicao_valor, recompensa_tipo, recompensa_valor, politica_reentrada, contagem_por_item, acumular_sobra, auto_resgatar_pdv, ativo)
                   VALUES (?, 'Compre 3 Petiscos Ganhe 10', 'compre_ganhe', 'item_especifico', ?, 'produto', 'quantidade_compras', 3, 'desconto_fixo', 15.00, 'reiniciar_ao_concluir', 1, 1, 1, 1)")
        ->execute([$lojaId, $prodPetisco]);
    $rSobra = (int)$pdo->lastInsertId();

    $itemPetisco4 = [['id' => $prodPetisco, 'tipo' => 'produto', 'nome' => 'Petisco Natural', 'preco' => 40.00, 'qtd' => 4]];
    $s1 = simVenda($pdo, $lojaId, $cli2, $itemPetisco4, ['agora_ts' => $baseTs]); // delta=4 >= meta 3 => aplica R$15, ciclos=1, sobra=1!
    ok(
        $s1['eval']['regras_modulares'][0]['acao_venda'] === 'concluir_e_reiniciar' &&
        near((float)$s1['eval']['desconto_fidelidade'], 15.0) &&
        $s1['eval']['regras_modulares'][0]['ciclos_depois'] === 1 &&
        $s1['eval']['regras_modulares'][0]['progresso_depois'] === 1,
        'A7.1 reiniciar_ao_concluir + contagem_por_item=1 + acumular_sobra=1: compra 4 na meta 3 conclui e carrega sobra 1'
    );

    // Sem sobra (acumular_sobra=0): compra 4 na meta 3 para cli3 deve zerar progresso em 0
    $pdo->exec("UPDATE fidelidade_regras SET acumular_sobra = 0 WHERE id = {$rSobra}");
    $sZero = simVenda($pdo, $lojaId, $cli3, $itemPetisco4, ['agora_ts' => $baseTs]);
    ok(
        $sZero['eval']['regras_modulares'][0]['ciclos_depois'] === 1 &&
        $sZero['eval']['regras_modulares'][0]['progresso_depois'] === 0,
        'A7.2 acumular_sobra=0 zera excedente após conclusão (progresso_depois = 0)'
    );
    $pdo->exec("UPDATE fidelidade_regras SET ativo = 0 WHERE id = {$rSobra}");

    // A8: Cooldown em Regra Modular (cooldown_dias = 7 em desconto_direto imediato)
    $pdo->prepare("INSERT INTO fidelidade_regras (instituicao_id, nome_regra, tipo_mecanica, categoria_alvo, condicao_tipo, condicao_valor, recompensa_tipo, recompensa_valor, politica_reentrada, cooldown_dias, ativo)
                   VALUES (?, 'Desconto Semanal Cooldown', 'desconto_direto', 'todos', 'valor_minimo', 50.00, 'desconto_percentual', 10.00, 'cooldown_dias', 7, 1)")
        ->execute([$lojaId]);
    $rCool = (int)$pdo->lastInsertId();

    $c1 = simVenda($pdo, $lojaId, $cli1, $itemRacao, ['agora_ts' => $baseTs]); // aplica 10% = 20
    $c2 = simVenda($pdo, $lojaId, $cli1, $itemRacao, ['agora_ts' => $baseTs + 3 * 86400]); // bloqueada (em_cooldown, faltam 4d)
    $c3 = simVenda($pdo, $lojaId, $cli1, $itemRacao, ['agora_ts' => $baseTs + 8 * 86400]); // liberada após 8 dias

    ok(
        near((float)$c1['eval']['desconto_fidelidade'], 20.0) &&
        near((float)$c2['eval']['desconto_fidelidade'], 0.0) &&
        ($c2['eval']['regras_bloqueadas'][0]['status_codigo'] ?? '') === 'em_cooldown' &&
        near((float)$c3['eval']['desconto_fidelidade'], 20.0),
        'A8 Regra cooldown_dias=7: aplica dia 0, bloqueia dia 3 (em_cooldown), libera dia 8'
    );
    $pdo->exec("UPDATE fidelidade_regras SET ativo = 0 WHERE id = {$rCool}");

    // A9: ilimitada_por_compra para cashback e pontos + saldo cashback + upgrade Tier
    $pdo->prepare("INSERT INTO fidelidade_regras (instituicao_id, nome_regra, tipo_mecanica, categoria_alvo, condicao_tipo, condicao_valor, recompensa_tipo, recompensa_valor, politica_reentrada, prioridade, acumular_com_outras, ativo)
                   VALUES (?, 'Cashback 10% Ilimitado', 'cashback', 'todos', 'valor_minimo', 10.00, 'cashback', 10.00, 'ilimitada_por_compra', 1, 1, 1)")
        ->execute([$lojaId]);
    $rCb = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO fidelidade_regras (instituicao_id, nome_regra, tipo_mecanica, categoria_alvo, condicao_tipo, condicao_valor, recompensa_tipo, recompensa_valor, politica_reentrada, prioridade, acumular_com_outras, ativo)
                   VALUES (?, 'Pontos 3x Turbo', 'pontos', 'todos', 'valor_minimo', 10.00, 'multiplicador_pontos', 3.00, 'ilimitada_por_compra', 2, 1, 1)")
        ->execute([$lojaId]);
    $rPts = (int)$pdo->lastInsertId();

    // Usa cli4 zerado (gasto=0 -> Bronze) com compras de R$ 200 (20 cashback + 600 pts), depois R$ 200 + resgata R$ 15 cashback -> total gasto > 250 -> Prata, depois mais R$ 250 -> Ouro VIP
    $p1 = simVenda($pdo, $lojaId, $cli4, $itemRacao, ['agora_ts' => $baseTs]); // cashback 20, pontos 200*3=600, total_gasto=200 (Bronze)
    $p2 = simVenda($pdo, $lojaId, $cli4, $itemRacao, ['agora_ts' => $baseTs + 100, 'usar_cashback' => 15.00]); // desconta 15, liquido 185, total_gasto=385 -> Prata!
    $p3 = simVenda($pdo, $lojaId, $cli4, [['id' => $prodRacao, 'tipo' => 'produto', 'nome' => 'Ração', 'preco' => 250.00, 'qtd' => 1]], ['agora_ts' => $baseTs + 200]); // total_gasto=635 -> Ouro VIP

    $cf4 = $pdo->query("SELECT * FROM cliente_fidelidade WHERE cliente_id = {$cli4} AND instituicao_id = {$lojaId}")->fetch(PDO::FETCH_ASSOC);
    ok(
        near((float)$p1['eval']['cashback_gerado'], 20.0) &&
        (int)$p1['eval']['pontos_gerados'] === 600 &&
        near((float)$p2['eval']['cashback_utilizado'], 15.0) &&
        $p2['exec']['tier_atual'] === 'Prata' &&
        $cf4['tier_atual'] === 'Ouro VIP' &&
        (int)$cf4['pontos_acumulados'] >= 1950 &&
        near((float)$cf4['saldo_cashback'], 50.0), // 20 - 15 + 20 + 25 = 50
        'A9 ilimitada_por_compra (Cashback + Pontos 3x): uso saldo cashback + acúmulo contínuo + Tier Bronze->Prata->Ouro VIP'
    );
    $pdo->exec("UPDATE fidelidade_regras SET ativo = 0 WHERE id IN ({$rCb}, {$rPts})");

    // A10: Exclusividade (acumular_com_outras = 0) respeitando prioridade
    $pdo->prepare("INSERT INTO fidelidade_regras (instituicao_id, nome_regra, tipo_mecanica, categoria_alvo, condicao_tipo, condicao_valor, recompensa_tipo, recompensa_valor, politica_reentrada, prioridade, acumular_com_outras, ativo)
                   VALUES (?, 'VIP Exclusiva P1', 'desconto_direto', 'todos', 'valor_minimo', 10.00, 'desconto_fixo', 25.00, 'ilimitada_por_compra', 1, 0, 1)")
        ->execute([$lojaId]);
    $rExcl = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO fidelidade_regras (instituicao_id, nome_regra, tipo_mecanica, categoria_alvo, condicao_tipo, condicao_valor, recompensa_tipo, recompensa_valor, politica_reentrada, prioridade, acumular_com_outras, ativo)
                   VALUES (?, 'Secundaria P2 Bloqueada', 'desconto_direto', 'todos', 'valor_minimo', 10.00, 'desconto_fixo', 15.00, 'ilimitada_por_compra', 5, 1, 1)")
        ->execute([$lojaId]);
    $rSec = (int)$pdo->lastInsertId();

    $evExcl = crmAvaliarCarrinhoCompleto($pdo, $lojaId, $lojaId, [$lojaId], $itemRacao, $cli3, 0.0, ['agora_ts' => $baseTs]);
    ok(
        near((float)$evExcl['desconto_fidelidade'], 25.0) &&
        count($evExcl['regras_modulares']) === 1 &&
        count($evExcl['regras_bloqueadas']) === 1,
        'A10 Regra prioridade 1 com acumular_com_outras=0 bloqueia regra prioridade 5'
    );
    $pdo->exec("UPDATE fidelidade_regras SET ativo = 0 WHERE id IN ({$rExcl}, {$rSec})");

    // A11: Discriminação estrita Produto vs Serviço
    $pdo->prepare("INSERT INTO fidelidade_regras (instituicao_id, nome_regra, tipo_mecanica, categoria_alvo, item_alvo_id, item_alvo_tipo, condicao_tipo, condicao_valor, recompensa_tipo, recompensa_valor, politica_reentrada, ativo)
                   VALUES (?, 'Somente Banho ID', 'desconto_direto', 'item_especifico', ?, 'servico', 'valor_minimo', 10.00, 'desconto_fixo', 12.00, 'ilimitada_por_compra', 1)")
        ->execute([$lojaId, $servBanho]);
    $rServOnly = (int)$pdo->lastInsertId();

    $evProdSameId = crmAvaliarCarrinhoCompleto($pdo, $lojaId, $lojaId, [$lojaId], [['id' => $servBanho, 'tipo' => 'produto', 'nome' => 'Produto Fake Mesmo ID', 'preco' => 100.00, 'qtd' => 1]], $cli1, 0.0, ['agora_ts' => $baseTs]);
    $evServReal   = crmAvaliarCarrinhoCompleto($pdo, $lojaId, $lojaId, [$lojaId], $itemBanho, $cli1, 0.0, ['agora_ts' => $baseTs]);
    ok(
        near((float)$evProdSameId['desconto_fidelidade'], 0.0) &&
        near((float)$evServReal['desconto_fidelidade'], 12.0),
        'A11 Produto com mesmo ID numérico de Serviço não dispara regra servico_especifico'
    );
    $pdo->exec("UPDATE fidelidade_regras SET ativo = 0 WHERE id = {$rServOnly}");

    // A12: Dia da semana + Aniversário (Tutor & Pet) + Vigência
    $pdo->prepare("INSERT INTO fidelidade_regras (instituicao_id, nome_regra, tipo_mecanica, categoria_alvo, condicao_tipo, condicao_valor, dias_semana_ativos, recompensa_tipo, recompensa_valor, politica_reentrada, ativo)
                   VALUES (?, 'Terca Pet', 'desconto_direto', 'todos', 'dia_semana', 0, ?, 'desconto_fixo', 15.00, 'ilimitada_por_compra', 1)")
        ->execute([$lojaId, json_encode(['ter'])]);
    $rTerca = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO fidelidade_regras (instituicao_id, nome_regra, tipo_mecanica, categoria_alvo, condicao_tipo, condicao_valor, recompensa_tipo, recompensa_valor, politica_reentrada, ativo)
                   VALUES (?, 'Mes Aniversario', 'desconto_direto', 'todos', 'aniversario', 0, 'desconto_fixo', 10.00, 'ilimitada_por_compra', 1)")
        ->execute([$lojaId]);
    $rAniv = (int)$pdo->lastInsertId();

    // $baseTs = 2026-11-10 (terça); +1 dia = quarta; $cli2 aniversariante tutor; $cli4 aniversariante pet; $cli1 nenhum dos dois
    $evTercaCli1 = crmAvaliarCarrinhoCompleto($pdo, $lojaId, $lojaId, [$lojaId], $itemRacao, $cli1, 0.0, ['agora_ts' => $baseTs]); // só terça (15)
    $evQuartaCli2 = crmAvaliarCarrinhoCompleto($pdo, $lojaId, $lojaId, [$lojaId], $itemRacao, $cli2, 0.0, ['agora_ts' => $baseTs + 86400]); // só aniv tutor (10)
    $evTercaCli4 = crmAvaliarCarrinhoCompleto($pdo, $lojaId, $lojaId, [$lojaId], $itemRacao, $cli4, 0.0, ['agora_ts' => $baseTs]); // terça + aniv pet = 25!

    ok(
        near((float)$evTercaCli1['desconto_fidelidade'], 15.0) &&
        near((float)$evQuartaCli2['desconto_fidelidade'], 10.0) &&
        near((float)$evTercaCli4['desconto_fidelidade'], 25.0),
        'A12 Condições dia_semana (Ter vs Qua) + Aniversário por Tutor (cli2) e por Pet (cli4)'
    );
    $pdo->exec("UPDATE fidelidade_regras SET ativo = 0 WHERE id IN ({$rTerca}, {$rAniv})");

    // A13: participar_fidelidade = 0 não pontua nem sela regras, mas bloqueia/aplica promoções relâmpago normalmente
    $pdo->exec("UPDATE promocoes SET ativo = 1 WHERE id = {$promoUnica}"); // já usada pelo cli1 antes; livre para cli3
    $pdo->prepare("INSERT INTO fidelidade_regras (instituicao_id, nome_regra, tipo_mecanica, categoria_alvo, condicao_tipo, condicao_valor, recompensa_tipo, recompensa_valor, politica_reentrada, ativo)
                   VALUES (?, 'Selos Gerais', 'cartao_selos', 'todos', 'quantidade_compras', 3, 'desconto_fixo', 5.00, 'reiniciar_apos_resgate', 1)")
        ->execute([$lojaId]);
    $rGeral = (int)$pdo->lastInsertId();

    $selosAntesCli3 = (int)$pdo->query("SELECT COALESCE(MAX(selos_atuais),0) FROM cliente_fidelidade WHERE cliente_id = {$cli3} AND instituicao_id = {$lojaId}")->fetchColumn();
    $vNoFid = simVenda($pdo, $lojaId, $cli3, $itemPetisco, ['agora_ts' => $baseTs, 'participar_fidelidade' => false]);
    $selosDepoisCli3 = (int)$pdo->query("SELECT COALESCE(MAX(selos_atuais),0) FROM cliente_fidelidade WHERE cliente_id = {$cli3} AND instituicao_id = {$lojaId}")->fetchColumn();
    $progNoFid = $pdo->query("SELECT COUNT(*) FROM cliente_progresso_regras WHERE cliente_id = {$cli3} AND regra_id = {$rGeral}")->fetchColumn();

    ok(
        near((float)$vNoFid['eval']['desconto_promocao'], 20.0) &&
        $selosAntesCli3 === $selosDepoisCli3 &&
        (int)$progNoFid === 0,
        'A13 participar_fidelidade=false não cria selos/progresso em fidelidade, mas aplica promo relâmpago elegível'
    );
    $pdo->exec("UPDATE promocoes SET ativo = 0 WHERE id = {$promoUnica}");
    $pdo->exec("UPDATE fidelidade_regras SET ativo = 0 WHERE id = {$rGeral}");

    // =========================================================================
    // SUITE B: TESTES HTTP END-TO-END CONTRA SERVIDOR PHP EMPACOTADO (sys/)
    // =========================================================================
    echo "\n--- Suite B: Endpoints HTTP (auth.php -> api_fidelidade.php -> salvar_venda.php -> telas) ---\n";

    $emailTeste = 'teste_politica_crm_' . $lojaId . '@example.local';
    $senhaTeste = 'TesteCrm#2026!';
    $pdo->prepare("INSERT INTO usuarios (instituicao_id, nome, email, senha_hash, cargo, precisa_mudar_senha, ativo) VALUES (?, 'Gerente Teste CRM', ?, ?, 'Gerente', 0, 1)")
        ->execute([$lojaId, $emailTeste, password_hash($senhaTeste, PASSWORD_DEFAULT)]);
    $userId = (int)$pdo->lastInsertId();

    $port = 18923;
    $docRoot = realpath(__DIR__ . '/../sys');
    $cmd = ['C:\\xampp\\php\\php.exe', '-S', "127.0.0.1:{$port}", '-t', $docRoot];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['file', 'nul', 'w'], 2 => ['file', 'nul', 'w']];
    $serverProc = proc_open($cmd, $descriptors, $pipes);
    usleep(450000); // 450ms para bind do servidor

    $http = function (string $path, ?array $postData = null, bool $jsonBody = false) use ($port, $cookieFile): array {
        $ch = curl_init("http://127.0.0.1:{$port}/" . ltrim($path, '/'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        if ($postData !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($jsonBody) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            }
        }
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return ['code' => $code, 'body' => $body, 'error' => $err];
    };

    // B1: Login legítimo em auth.php
    $resLogin = $http('auth.php', ['email' => $emailTeste, 'senha' => $senhaTeste]);
    ok($resLogin['code'] === 200 && empty($resLogin['error']), 'B1 Autenticação HTTP em sys/auth.php');

    // B2: api_fidelidade.php -> salvar_regra (com politica_reentrada='reiniciar_apos_resgate', item servico:ID, meta=1)
    $resSaveRegra = $http('api_fidelidade.php', [
        'acao' => 'salvar_regra',
        'nome_regra' => 'Banho VIP HTTP',
        'tipo_mecanica' => 'cartao_selos',
        'categoria_alvo' => 'item_especifico',
        'item_alvo_id' => 'servico:' . $servBanho,
        'condicao_tipo' => 'quantidade_compras',
        'condicao_valor' => 1,
        'recompensa_tipo' => 'desconto_percentual',
        'recompensa_valor' => 20,
        'politica_reentrada' => 'reiniciar_apos_resgate',
        'auto_resgatar_pdv' => 0,
        'contagem_por_item' => 1,
        'acumular_sobra' => 1,
        'acumular_com_outras' => 1,
        'prioridade' => 5,
    ]);
    $jsonSaveRegra = json_decode($resSaveRegra['body'], true);
    $resListRegras = $http('api_fidelidade.php?acao=listar_regras');
    $jsonListRegras = json_decode($resListRegras['body'], true);
    $regraHttpId = 0;
    foreach (($jsonListRegras['regras'] ?? []) as $rr) {
        if ($rr['nome_regra'] === 'Banho VIP HTTP') {
            $regraHttpId = (int)$rr['id'];
        }
    }
    ok(!empty($jsonSaveRegra['success']) && $regraHttpId > 0, 'B2 api_fidelidade.php?acao=salvar_regra + listar_regras cria regra servico:' . $servBanho);

    // B3: api_fidelidade.php -> salvar_promocao (politica_reentrada='unica_vez', 25% na Ração)
    $resSavePromo = $http('api_fidelidade.php', [
        'acao' => 'salvar_promocao',
        'titulo' => 'Ração Flash HTTP',
        'descricao' => 'Teste HTTP',
        'produto_id' => $prodRacao,
        'desconto_percentual' => 25,
        'politica_reentrada' => 'unica_vez',
        'aplicar_automatico_pdv' => 1,
        'data_fim' => '2026-12-31',
    ]);
    $jsonSavePromo = json_decode($resSavePromo['body'], true);
    ok(!empty($jsonSavePromo['success']), 'B3 api_fidelidade.php?acao=salvar_promocao cria promo unica_vez');

    // B4: api_fidelidade.php -> verificar_regras_carrinho (simulando PDV ao vivo)
    $resVerif = $http('api_fidelidade.php', [
        'acao' => 'verificar_regras_carrinho',
        'cliente_id' => $cli1,
        'itens' => [
            ['id' => $prodRacao, 'tipo' => 'produto', 'nome' => 'Ração Super Premium 15kg', 'preco' => 200.00, 'qtd' => 1],
            ['id' => $servBanho, 'tipo' => 'servico', 'nome' => 'Banho e Tosa Porte M', 'preco' => 100.00, 'qtd' => 1],
        ],
    ], true);
    $jsonVerif = json_decode($resVerif['body'], true);
    ok(
        !empty($jsonVerif['success']) &&
        near((float)($jsonVerif['desconto_promocao'] ?? 0), 50.0) && // 25% de 200
        near((float)($jsonVerif['total_liquido'] ?? 0), 250.0),
        'B4 api_fidelidade.php?acao=verificar_regras_carrinho calcula promo 25% R$ 50,00 no PDV'
    );

    // B5: salvar_venda.php via HTTP usando telefone WhatsApp do cli1 (sem cliente_id explícito!)
    // Deve resolver cli1, aplicar promo R$ 50, desbloquear Banho VIP HTTP (1/1), criar oportunidade_venda de ração para Thor (pet1) e retornar link do cartão!
    $resVenda1 = $http('salvar_venda.php', [
        'participar_fidelidade' => true,
        'cliente_whatsapp' => '11999990001',
        'forma_pagamento' => 'PIX',
        'itens' => [
            ['id' => $prodRacao, 'tipo' => 'produto', 'nome' => 'Ração Super Premium 15kg', 'preco' => 200.00, 'qtd' => 1],
            ['id' => $servBanho, 'tipo' => 'servico', 'nome' => 'Banho e Tosa Porte M', 'preco' => 100.00, 'qtd' => 1],
        ],
    ], true);
    $jsonVenda1 = json_decode($resVenda1['body'], true);
    $venda1Id = (int)($jsonVenda1['venda_id'] ?? 0);
    $oppsCli1 = (int)$pdo->query("SELECT COUNT(*) FROM oportunidades_venda WHERE instituicao_id = {$lojaId} AND cliente_id = {$cli1} AND pet_id = {$pet1} AND status = 'Pendente'")->fetchColumn();
    $vendaItemServ = (int)$pdo->query("SELECT COUNT(*) FROM venda_itens WHERE venda_id = {$venda1Id} AND servico_id = {$servBanho} AND produto_id IS NULL")->fetchColumn();
    ok(
        !empty($jsonVenda1['success']) &&
        near((float)($jsonVenda1['desconto_promocao'] ?? 0), 50.0) &&
        near((float)($jsonVenda1['valor_total_pago'] ?? 0), 250.0) &&
        !empty($jsonVenda1['fidelidade']['participou']) &&
        (int)($jsonVenda1['fidelidade']['cliente_id'] ?? 0) === $cli1 &&
        $oppsCli1 >= 1 &&
        $vendaItemServ === 1,
        'B5 salvar_venda.php resolve cli1 via WhatsApp, grava venda_itens.servico_id, cria ciclo ração Thor e aplica promo'
    );

    // B6: 2ª compra HTTP do mesmo cli1 -> promoção unica_vez deve bloquear + regra modular pronta no balcão
    $resVenda2 = $http('salvar_venda.php', [
        'participar_fidelidade' => true,
        'cliente_id' => $cli1,
        'forma_pagamento' => 'Dinheiro',
        'itens' => [
            ['id' => $prodRacao, 'tipo' => 'produto', 'nome' => 'Ração Super Premium 15kg', 'preco' => 200.00, 'qtd' => 1],
        ],
    ], true);
    $jsonVenda2 = json_decode($resVenda2['body'], true);
    $resDetalhar = $http("api_fidelidade.php?acao=detalhar_cliente_regras&cliente_id={$cli1}");
    $jsonDet = json_decode($resDetalhar['body'], true);
    $resResgateBalcao = $http('api_fidelidade.php', [
        'acao' => 'resgatar_regra_modular',
        'cliente_id' => $cli1,
        'regra_id' => $regraHttpId,
    ]);
    $jsonResg = json_decode($resResgateBalcao['body'], true);

    ok(
        !empty($jsonVenda2['success']) &&
        near((float)($jsonVenda2['desconto_promocao'] ?? -1), 0.0) &&
        near((float)($jsonVenda2['valor_total_pago'] ?? 0), 200.0) &&
        count($jsonDet['regras'] ?? []) >= 1 &&
        !empty($jsonResg['success']) &&
        (int)($jsonResg['ciclos_completados'] ?? 0) === 1,
        'B6 2ª venda bloqueia promo unica_vez + detalhar_cliente_regras + resgatar_regra_modular reinicia ciclo no balcão'
    );

    // B7: Renderização HTML das telas principais sem Fatal/Warning/Notice PHP
    $views = [
        'fidelizacao.php',
        'vendas.php',
        "cartao_cliente.php?cliente_id={$cli1}&loja_id={$lojaId}",
    ];
    foreach ($views as $v) {
        $rv = $http($v);
        $hasPhpErr = preg_match('/(Fatal error|Parse error|Warning:|Notice:|Uncaught\s+\w*Error)/i', $rv['body']) === 1;
        ok(
            $rv['code'] === 200 && !$hasPhpErr && strlen($rv['body']) > 1000,
            "B7 Renderização limpa HTTP 200 de {$v}"
        );
    }

} finally {
    if ($serverProc) {
        proc_terminate($serverProc);
        proc_close($serverProc);
    }
    if ($cookieFile && file_exists($cookieFile)) {
        @unlink($cookieFile);
    }
    if ($lojaId) {
        $pdo->exec("DELETE FROM venda_itens WHERE venda_id IN (SELECT id FROM vendas WHERE instituicao_id = {$lojaId})");
        $pdo->exec("DELETE FROM promocao_usos WHERE instituicao_id = {$lojaId}");
        $pdo->exec("DELETE FROM fidelidade_transacoes WHERE instituicao_id = {$lojaId}");
        $pdo->exec("DELETE FROM cliente_progresso_regras WHERE instituicao_id = {$lojaId}");
        $pdo->exec("DELETE FROM cliente_fidelidade WHERE instituicao_id = {$lojaId}");
        $pdo->exec("DELETE FROM oportunidades_venda WHERE instituicao_id = {$lojaId}");
        $pdo->exec("DELETE FROM vendas WHERE instituicao_id = {$lojaId}");
        $pdo->exec("DELETE FROM fidelidade_regras WHERE instituicao_id = {$lojaId}");
        $pdo->exec("DELETE FROM promocoes WHERE instituicao_id = {$lojaId}");
        $pdo->exec("DELETE FROM fidelidade_config WHERE instituicao_id = {$lojaId}");
        $pdo->exec("DELETE FROM pets WHERE instituicao_id = {$lojaId}");
        $pdo->exec("DELETE FROM produtos WHERE instituicao_id = {$lojaId}");
        $pdo->exec("DELETE FROM servicos WHERE instituicao_id = {$lojaId}");
        $pdo->exec("DELETE FROM clientes WHERE instituicao_id = {$lojaId}");
        $pdo->exec("DELETE FROM usuarios WHERE instituicao_id = {$lojaId}");
        $pdo->exec("DELETE FROM instituicoes WHERE id = {$lojaId}");
    }
}

echo "\n========================================\n";
echo "RESULTADO FINAL: {$passed} passaram, {$failed} falharam.\n";
if ($failed > 0) {
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
echo "TODAS AS VERIFICAÇÕES PASSARAM.\n";
