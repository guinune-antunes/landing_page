<?php
/**
 * Suite de Testes End-to-End: Select2 Global, Endpoints AJAX & Relatório CRM 360° do Cliente.
 * Executa contra o banco isolado `petcrm_politicacrm` e servidor HTTP local efêmero.
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

function httpReq(string $baseUrl, string $path, string $method = 'GET', $body = null, array &$cookies = [], array $extraHeaders = []): array {
    $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    $headers = [
        'User-Agent: PetCrmTestRunner/1.0',
    ];
    if (!empty($cookies)) {
        $pairs = [];
        foreach ($cookies as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }

    $content = null;
    if ($body !== null) {
        if (is_array($body)) {
            $content = http_build_query($body);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } else {
            $content = (string)$body;
        }
        $headers[] = 'Content-Length: ' . strlen($content);
    }
    foreach ($extraHeaders as $h) {
        $headers[] = $h;
    }

    $ctx = stream_context_create([
        'http' => [
            'method'           => $method,
            'header'           => implode("\r\n", $headers),
            'content'          => $content ?? '',
            'ignore_errors'    => true,
            'follow_location'  => 0,
            'timeout'          => 15,
        ],
    ]);

    $respBody = @file_get_contents($url, false, $ctx);
    $respHeaders = $http_response_header ?? [];
    $status = 0;
    $location = '';
    $contentType = '';

    foreach ($respHeaders as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#i', $h, $m)) {
            $status = (int)$m[1];
        } elseif (stripos($h, 'Set-Cookie:') === 0) {
            $cookiePart = trim(substr($h, 11));
            $kv = explode(';', $cookiePart)[0] ?? '';
            if (strpos($kv, '=') !== false) {
                [$ck, $cv] = explode('=', $kv, 2);
                $cookies[trim($ck)] = trim($cv);
            }
        } elseif (stripos($h, 'Location:') === 0) {
            $location = trim(substr($h, 9));
        } elseif (stripos($h, 'Content-Type:') === 0) {
            $contentType = trim(substr($h, 13));
        }
    }

    return [
        'status'       => $status,
        'location'     => $location,
        'content_type' => $contentType,
        'headers'      => $respHeaders,
        'body'         => $respBody === false ? '' : $respBody,
    ];
}

function hasPhpRuntimeError(string $body): string {
    $patterns = [
        'Fatal error:',
        'Parse error:',
        'Uncaught PDOException',
        'Uncaught TypeError',
        'Uncaught Error',
        'Uncaught Exception',
        'Warning:</b>',
        'Notice:</b>',
        'Deprecated:</b>',
        '<b>Warning</b>:',
        '<b>Notice</b>:',
        '<b>Fatal error</b>:',
        '<b>Parse error</b>:',
    ];
    foreach ($patterns as $p) {
        if (stripos($body, $p) !== false) {
            $pos = stripos($body, $p);
            return trim(substr($body, max(0, $pos - 20), 220));
        }
    }
    if (preg_match('/^(Warning|Notice|Fatal error|Parse error):/m', $body, $m)) {
        return $m[0];
    }
    return '';
}

function validateInlineScriptsWithNode(string $html, string $pageLabel): array {
    $errors = [];
    if (!preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#is', $html, $matches)) {
        return ['count' => 0, 'errors' => []];
    }
    $blocks = $matches[1];
    $tmpJs = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'petcrm_chk_' . md5($pageLabel . uniqid('', true)) . '.js';
    try {
        foreach ($blocks as $idx => $code) {
            $trimmed = trim($code);
            if ($trimmed === '') continue;
            file_put_contents($tmpJs, $trimmed);
            $cmd = 'node --check ' . escapeshellarg($tmpJs) . ' 2>&1';
            $out = [];
            $codeExit = 0;
            exec($cmd, $out, $codeExit);
            if ($codeExit !== 0) {
                $errors[] = "Block #{$idx}: " . implode(' | ', $out);
            }
        }
    } finally {
        @unlink($tmpJs);
    }
    return ['count' => count($blocks), 'errors' => $errors];
}

$phpExe = 'C:\xampp\php\php.exe';
$docRoot = realpath(__DIR__ . '/../sys');
$port = 18924;
$baseUrl = "http://127.0.0.1:{$port}";
$serverProc = null;
$serverErrLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'petcrm_srv_' . uniqid('', true) . '.log';

$instId = 0;
$userId = 0;
$cli1 = 0;
$cli2 = 0;
$pet1 = 0;
$prod1 = 0;
$serv1 = 0;
$regraRecorrenteId = 0;
$regraReentradaId = 0;
$promoId = 0;
$vendaId = 0;

try {
    // 1. Iniciar servidor PHP embutido apontando para sys/ (sem pipe blocking no Windows)
    $cmd = "\"{$phpExe}\" -S 127.0.0.1:{$port} -t \"{$docRoot}\"";
    $desc = [
        0 => ['file', 'NUL', 'r'],
        1 => ['file', 'NUL', 'w'],
        2 => ['file', $serverErrLog, 'w'],
    ];
    $serverProc = proc_open($cmd, $desc, $pipesUnused);
    if (!is_resource($serverProc)) {
        throw new RuntimeException('Falha ao iniciar servidor PHP embutido.');
    }

    $ready = false;
    for ($i = 0; $i < 40; $i++) {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.25);
        if ($fp) {
            fclose($fp);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    ok($ready, 'Servidor HTTP efêmero iniciou na porta ' . $port);
    if (!$ready) {
        throw new RuntimeException('Servidor HTTP não respondeu a tempo.');
    }

    // 2. Criar massa de dados sintética isolada
    $suf = date('His') . mt_rand(10, 99);
    $modulosJson = json_encode([
        'agenda' => true,
        'clientes' => true,
        'financeiro' => true,
        'estoque' => true,
        'crm_vendas' => true,
        'relatorios' => true,
        'equipe' => true,
        'clinica' => true,
    ]);
    $pdo->prepare("INSERT INTO instituicoes (nome_fantasia, cnpj, plano, modulos_contratados, ativo, nome_unidade, cidade, uf, matriz)
                   VALUES (?, ?, 'premium', ?, 1, 'Unidade Select2 QA', 'Curitiba', 'PR', 1)")
        ->execute(["Loja Select2 QA {$suf}", "99{$suf}000199", $modulosJson]);
    $instId = (int)$pdo->lastInsertId();

    $emailTest = "qa_select2_{$suf}@petcrm.test";
    $senhaTest = 'Teste@Select2!';
    $hashTest = password_hash($senhaTest, PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO usuarios (instituicao_id, nome, email, senha_hash, cargo, precisa_mudar_senha, ativo, acesso_rede)
                   VALUES (?, 'Gerente Select2 QA', ?, ?, 'Gerente', 0, 1, 1)")
        ->execute([$instId, $emailTest, $hashTest]);
    $userId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO fidelidade_config (instituicao_id, ativo, tipo_programa, compras_para_recompensa, recompensa_tipo, recompensa_valor, reais_por_ponto, cashback_percentual)
                   VALUES (?, 1, 'hibrido', 5, 'desconto_fixo', 25.00, 1.00, 5.00)")
        ->execute([$instId]);

    $hojeMesDia = date('m-d');
    $pdo->prepare("INSERT INTO clientes (instituicao_id, nome_completo, cpf, telefone, data_nascimento, email)
                   VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$instId, "Ana Maria Select2 {$suf}", "888{$suf}11", "4199999{$suf}", "1992-{$hojeMesDia}", "ana{$suf}@test.local"]);
    $cli1 = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO clientes (instituicao_id, nome_completo, cpf, telefone, data_nascimento, email)
                   VALUES (?, ?, ?, ?, '1988-01-15', ?)")
        ->execute([$instId, "Bruno Sem Compras {$suf}", "777{$suf}22", "4198888{$suf}", "bruno{$suf}@test.local"]);
    $cli2 = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO pets (instituicao_id, cliente_id, nome, especie, raca, peso_kg, porte, fator_manutencao)
                   VALUES (?, ?, ?, 'Cão', 'Golden Retriever', 28.50, 'Grande', 1.60)")
        ->execute([$instId, $cli1, "RexS2_{$suf}"]);
    $pet1 = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO produtos (instituicao_id, nome_produto, marca, categoria, codigo_barras, preco_custo, preco_venda, quantidade_estoque, unidade, tamanho_embalagem_kg, energia_metabolizavel_kg)
                   VALUES (?, ?, 'MarcaQA', 'Ração Seca', ?, 90.00, 189.90, 15, 'SC', 15.00, 3800)")
        ->execute([$instId, "Ração Select2 Golden {$suf}", "789{$suf}01"]);
    $prod1 = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO servicos (instituicao_id, nome_servico, descricao, preco_padrao, duracao_minutos_padrao)
                   VALUES (?, ?, 'Banho completo QA', 85.00, 60)")
        ->execute([$instId, "Banho Tosa Select2 {$suf}"]);
    $serv1 = (int)$pdo->lastInsertId();

    // Regra modular recorrente pronta para resgate (balcão) com limite_ciclos_cliente = 0 (deve valer ilimitado)
    $pdo->prepare("INSERT INTO fidelidade_regras (instituicao_id, nome_regra, descricao, tipo_mecanica, condicao_tipo, condicao_valor, recompensa_tipo, recompensa_valor, ativo, politica_reentrada, limite_ciclos_cliente)
                   VALUES (?, ?, 'A cada 3 compras ganha R$ 20', 'quantidade_compras', 'quantidade_compras', 3, 'desconto_fixo', 20.00, 1, 'reiniciar_apos_resgate', 0)")
        ->execute([$instId, "Cartão Recorrente QA {$suf}"]);
    $regraRecorrenteId = (int)$pdo->lastInsertId();

    // Regra de recompras com 1 ciclo concluído e bloqueio
    $pdo->prepare("INSERT INTO fidelidade_regras (instituicao_id, nome_regra, descricao, tipo_mecanica, item_alvo_id, item_alvo_tipo, condicao_tipo, condicao_valor, recompensa_tipo, recompensa_valor, ativo, politica_reentrada, limite_ciclos_cliente)
                   VALUES (?, ?, 'Campanha ciclo único QA', 'recompras', ?, 'produto', 'quantidade_compras', 2, 'desconto_percentual', 15.00, 1, 'unica_vez', 1)")
        ->execute([$instId, "Regra Ciclo Único QA {$suf}", $prod1]);
    $regraReentradaId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO cliente_fidelidade (instituicao_id, cliente_id, selos_atuais, ciclos_completados, pontos_acumulados, saldo_cashback, tier_atual, total_gasto_historico)
                   VALUES (?, ?, 4, 1, 240, 18.50, 'Ouro', 640.00)")
        ->execute([$instId, $cli1]);

    $pdo->prepare("INSERT INTO cliente_progresso_regras (instituicao_id, cliente_id, regra_id, progresso_atual, ciclos_completados, recompensa_disponivel, ultimo_uso, bloqueado_reentrada, observacao_status)
                   VALUES (?, ?, ?, 3, 1, 1, NOW(), 0, 'Pronto para resgate QA')")
        ->execute([$instId, $cli1, $regraRecorrenteId]);

    $pdo->prepare("INSERT INTO cliente_progresso_regras (instituicao_id, cliente_id, regra_id, progresso_atual, ciclos_completados, recompensa_disponivel, ultimo_uso, ultimo_resgate_em, bloqueado_reentrada, observacao_status)
                   VALUES (?, ?, ?, 2, 1, 0, NOW(), NOW(), 1, 'Ciclo único encerrado QA')")
        ->execute([$instId, $cli1, $regraReentradaId]);

    // Promoção + uso
    $pdo->prepare("INSERT INTO promocoes (instituicao_id, titulo, descricao, produto_id, desconto_percentual, preco_original, preco_promocional, data_inicio, data_fim, ativo, politica_reentrada, limite_usos_cliente, cooldown_dias, aplicar_automatico_pdv)
                   VALUES (?, ?, 'Oferta relâmpago QA', ?, 20.00, 189.90, 151.92, DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_ADD(NOW(), INTERVAL 5 DAY), 1, 'cooldown', 3, 7, 1)")
        ->execute([$instId, "Relâmpago Select2 {$suf}", $prod1]);
    $promoId = (int)$pdo->lastInsertId();

    // Venda + itens
    $pdo->prepare("INSERT INTO vendas (instituicao_id, cliente_id, vendedor_id, data_venda, valor_total, desconto, desconto_fidelidade, desconto_promocao, cashback_gerado, cashback_utilizado, pontos_gerados, forma_pagamento)
                   VALUES (?, ?, ?, NOW(), 236.92, 37.98, 0.00, 37.98, 11.85, 0.00, 236, 'PIX')")
        ->execute([$instId, $cli1, $userId]);
    $vendaId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO venda_itens (venda_id, produto_id, servico_id, nome_produto, quantidade, preco_unitario, subtotal)
                   VALUES (?, ?, NULL, ?, 1, 189.90, 189.90),
                          (?, NULL, ?, ?, 1, 85.00, 85.00)")
        ->execute([$vendaId, $prod1, "Ração Select2 Golden {$suf}", $vendaId, $serv1, "Banho Tosa Select2 {$suf}"]);

    $pdo->prepare("INSERT INTO promocao_usos (promocao_id, instituicao_id, cliente_id, venda_id, quantidade_itens, desconto_aplicado, valor_final_itens, data_uso)
                   VALUES (?, ?, ?, ?, 1, 37.98, 151.92, NOW())")
        ->execute([$promoId, $instId, $cli1, $vendaId]);

    $pdo->prepare("INSERT INTO fidelidade_transacoes (instituicao_id, cliente_id, regra_id, promocao_id, venda_id, tipo, quantidade, valor_recompensa, descricao, usuario_operador, data_transacao)
                   VALUES
                   (?, ?, NULL, ?, ?, 'promocao_relampago', 1, 37.98, 'Aplicação automática promoção relâmpago QA', 'Gerente Select2 QA', NOW()),
                   (?, ?, ?, NULL, ?, 'acumulo_cashback', 1, 11.85, 'Cashback 5% venda PDV QA', 'Gerente Select2 QA', NOW()),
                   (?, ?, ?, NULL, ?, 'acumulo_ponto', 236, 0.00, 'Acúmulo 236 pontos venda PDV QA', 'Gerente Select2 QA', NOW())")
        ->execute([$instId, $cli1, $promoId, $vendaId, $instId, $cli1, $regraRecorrenteId, $vendaId, $instId, $cli1, $regraRecorrenteId, $vendaId]);

    $pdo->prepare("INSERT INTO oportunidades_venda (instituicao_id, cliente_id, pet_id, produto_id, produto_nome, data_compra, status)
                   VALUES (?, ?, ?, ?, ?, DATE_SUB(CURDATE(), INTERVAL 40 DAY), 'Pendente')")
        ->execute([$instId, $cli1, $pet1, $prod1, "Ração Select2 Golden {$suf}"]);

    $pdo->prepare("INSERT INTO agendamentos (instituicao_id, cliente_id, pet_id, servico_id, servico, profissional_id, profissional, data_hora_inicio, data_hora_fim, status, observacoes)
                   VALUES (?, ?, ?, ?, ?, ?, 'Gerente Select2 QA', DATE_ADD(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 25 HOUR), 'Agendado', 'Banho retorno QA')")
        ->execute([$instId, $cli1, $pet1, $serv1, "Banho Tosa Select2 {$suf}", $userId]);

    // 3. Login HTTP via auth.php
    $cookies = [];
    $loginRes = httpReq($baseUrl, 'auth.php', 'POST', [
        'email' => $emailTest,
        'senha' => $senhaTest,
    ], $cookies);
    ok(
        $loginRes['status'] === 302 && strpos($loginRes['location'], 'dashboard_gerente.php') !== false && !empty($cookies['PHPSESSID']),
        'Autenticação HTTP POST auth.php criou sessão válida',
        "status={$loginRes['status']}, loc={$loginRes['location']}"
    );

    // 4. Testar api_busca_global.php (tipo=all, clientes por pet, relatorio vazio)
    $resGlobalAll = httpReq($baseUrl, 'api_busca_global.php?tipo=all&q=' . urlencode("Select2"), 'GET', null, $cookies);
    $jsonAll = json_decode($resGlobalAll['body'], true);
    $groupTexts = array_column($jsonAll['results'] ?? [], 'text');
    ok(
        $resGlobalAll['status'] === 200 && is_array($jsonAll) && in_array('Clientes / Tutores', $groupTexts, true) && in_array('Pets', $groupTexts, true) && in_array('Produtos', $groupTexts, true) && in_array('Serviços', $groupTexts, true),
        'api_busca_global.php?tipo=all retorna grupos Clientes / Tutores, Pets, Produtos e Serviços',
        'groups=' . implode(',', $groupTexts) . ' body=' . substr($resGlobalAll['body'], 0, 200)
    );

    $resGlobalPet = httpReq($baseUrl, 'api_busca_global.php?tipo=clientes&q=' . urlencode("RexS2_{$suf}"), 'GET', null, $cookies);
    $jsonPet = json_decode($resGlobalPet['body'], true);
    $foundTutorViaPet = false;
    foreach (($jsonPet['results'] ?? []) as $item) {
        if ((int)($item['id'] ?? 0) === $cli1 && strpos((string)($item['text'] ?? ''), "Ana Maria Select2 {$suf}") !== false) {
            $foundTutorViaPet = true;
        }
    }
    ok(
        $resGlobalPet['status'] === 200 && $foundTutorViaPet,
        'api_busca_global.php?tipo=clientes encontra tutor pelo nome do pet',
        substr($resGlobalPet['body'], 0, 250)
    );

    $resGlobalRelVazio = httpReq($baseUrl, 'api_busca_global.php?tipo=relatorio&q=', 'GET', null, $cookies);
    $jsonRelVazio = json_decode($resGlobalRelVazio['body'], true);
    $hasCli1Url = false;
    foreach (($jsonRelVazio['results'] ?? []) as $item) {
        if ((int)($item['id'] ?? 0) === $cli1 && ($item['url'] ?? '') === "relatorio_crm.php?cliente_id={$cli1}") {
            $hasCli1Url = true;
        }
    }
    ok(
        $resGlobalRelVazio['status'] === 200 && $hasCli1Url,
        'api_busca_global.php?tipo=relatorio com termo vazio pré-carrega clientes e URL do relatório',
        substr($resGlobalRelVazio['body'], 0, 250)
    );

    // 5. Testar buscar_produto_pdv.php (select2=1 e legado ?termo=)
    $resPdvS2 = httpReq($baseUrl, 'buscar_produto_pdv.php?select2=1&q=' . urlencode("Select2"), 'GET', null, $cookies);
    $jsonPdvS2 = json_decode($resPdvS2['body'], true);
    $pdvGroups = array_column($jsonPdvS2['results'] ?? [], 'text');
    ok(
        $resPdvS2['status'] === 200 && in_array('Produtos em Estoque', $pdvGroups, true) && in_array('Serviços do Catálogo', $pdvGroups, true),
        'buscar_produto_pdv.php?select2=1 retorna produtos e serviços agrupados',
        substr($resPdvS2['body'], 0, 250)
    );

    $resPdvLeg = httpReq($baseUrl, 'buscar_produto_pdv.php?termo=' . urlencode("789{$suf}01"), 'GET', null, $cookies);
    $jsonPdvLeg = json_decode($resPdvLeg['body'], true);
    ok(
        $resPdvLeg['status'] === 200 && is_array($jsonPdvLeg) && count($jsonPdvLeg) >= 1 && isset($jsonPdvLeg[0]['id'], $jsonPdvLeg[0]['nome'], $jsonPdvLeg[0]['preco']) && ($jsonPdvLeg[0]['codigo_barras'] ?? '') === "789{$suf}01",
        'buscar_produto_pdv.php?termo= mantém payload legado plano para código de barras',
        substr($resPdvLeg['body'], 0, 250)
    );

    // 6. Testar api_fidelidade.php?acao=buscar_cliente_fidelidade&select2=1
    $resFidS2 = httpReq($baseUrl, 'api_fidelidade.php?acao=buscar_cliente_fidelidade&select2=1&q=', 'GET', null, $cookies);
    $jsonFidS2 = json_decode($resFidS2['body'], true);
    $fidIds = array_map(static fn($r) => (int)($r['id'] ?? 0), $jsonFidS2['results'] ?? []);
    ok(
        $resFidS2['status'] === 200 && !empty($jsonFidS2['success']) && in_array($cli1, $fidIds, true) && !empty($jsonFidS2['clientes']),
        'api_fidelidade.php?acao=buscar_cliente_fidelidade&select2=1 retorna results e clientes mesmo com q vazio',
        substr($resFidS2['body'], 0, 250)
    );

    // 7. Testar relatorio_crm.php Panorama (gráficos Promo Ativa × Venda Geral + 1ª Compra & Cadastros)
    $resPan = httpReq($baseUrl, 'relatorio_crm.php', 'GET', null, $cookies);
    $errPan = hasPhpRuntimeError($resPan['body']);
    $checksPan = [
        strpos($resPan['body'], "Ana Maria Select2 {$suf}") !== false,
        strpos($resPan['body'], 'id="relatorio-crm-cliente-select"') !== false,
        strpos($resPan['body'], 'id="chartPromosVsVendas"') !== false,
        strpos($resPan['body'], 'id="chartSerieCrmVendas"') !== false,
        strpos($resPan['body'], "Relâmpago Select2 {$suf}") !== false,
        strpos($resPan['body'], 'Novo Cadastro + 1ª Compra') !== false,
        strpos($resPan['body'], "Bruno Sem Compras {$suf}") !== false,
        strpos($resPan['body'], 'Aguardando 1ª Compra') !== false,
    ];
    ok(
        $resPan['status'] === 200 && $errPan === '' && !in_array(false, $checksPan, true),
        'relatorio_crm.php Panorama renderiza HTTP 200, gráficos Promo × Venda Geral, 1ª compra/cadastros e Select2',
        $errPan !== '' ? "PHP Error: {$errPan}" : ('checks=' . json_encode($checksPan))
    );
    $jsPan = validateInlineScriptsWithNode($resPan['body'], 'relatorio_crm.php (Panorama)');
    ok(
        empty($jsPan['errors']),
        "Inline <script> em relatorio_crm.php Panorama ({$jsPan['count']} blocos) passa node --check",
        implode(' ; ', $jsPan['errors'])
    );

    $hoje = date('Y-m-d');
    $resPanFilt = httpReq($baseUrl, "relatorio_crm.php?data_inicio={$hoje}&data_fim={$hoje}", 'GET', null, $cookies);
    $errPanFilt = hasPhpRuntimeError($resPanFilt['body']);
    ok(
        $resPanFilt['status'] === 200 && $errPanFilt === '' && strpos($resPanFilt['body'], 'Filtro personalizado aplicado') !== false,
        'relatorio_crm.php Panorama aceita filtro personalizado de período',
        $errPanFilt
    );

    // 8. Testar relatorio_crm.php Dossiê 360°
    $resDos = httpReq($baseUrl, "relatorio_crm.php?cliente_id={$cli1}", 'GET', null, $cookies);
    $errDos = hasPhpRuntimeError($resDos['body']);
    $checksDos = [
        strpos($resDos['body'], "Ana Maria Select2 {$suf}") !== false,
        strpos($resDos['body'], "RexS2_{$suf}") !== false,
        strpos($resDos['body'], 'Aniversariante do Mês') !== false,
        strpos($resDos['body'], "Cartão Recorrente QA {$suf}") !== false,
        strpos($resDos['body'], "Regra Ciclo Único QA {$suf}") !== false,
        strpos($resDos['body'], "Relâmpago Select2 {$suf}") !== false,
        strpos($resDos['body'], "Ração Select2 Golden {$suf}") !== false,
        strpos($resDos['body'], "Banho Tosa Select2 {$suf}") !== false,
        strpos($resDos['body'], 'btn-resgatar-regra-rel') !== false,
        strpos($resDos['body'], '/ 0 máx.') === false,
    ];
    ok(
        $resDos['status'] === 200 && $errDos === '' && !in_array(false, $checksDos, true),
        'relatorio_crm.php?cliente_id=X exibe dossiê 360 completo (perfil, pet, aniversário, regras/reentrada sem 0 máx, promos, vendas, ração, agenda)',
        $errDos !== '' ? "PHP Error: {$errDos}" : ('checks=' . json_encode($checksDos))
    );

    // 9. Filtro por período + tipo_evento=promocao_relampago
    $hoje = date('Y-m-d');
    $resFilt = httpReq($baseUrl, "relatorio_crm.php?cliente_id={$cli1}&data_inicio={$hoje}&data_fim={$hoje}&tipo_evento=promocao_relampago", 'GET', null, $cookies);
    $errFilt = hasPhpRuntimeError($resFilt['body']);
    ok(
        $resFilt['status'] === 200 && $errFilt === '' && strpos($resFilt['body'], 'promoção relâmpago QA') !== false,
        'relatorio_crm.php com filtro de período e tipo_evento=promocao_relampago funciona sem erros',
        $errFilt
    );

    // 10. Exportação CSV UTF-8 BOM
    $resCsv = httpReq($baseUrl, "relatorio_crm.php?cliente_id={$cli1}&export=csv", 'GET', null, $cookies);
    $hasBom = substr($resCsv['body'], 0, 3) === "\xEF\xBB\xBF";
    ok(
        $resCsv['status'] === 200 && stripos($resCsv['content_type'], 'text/csv') !== false && $hasBom && strpos($resCsv['body'], 'RELATÓRIO CRM 360° DO CLIENTE') !== false && strpos($resCsv['body'], 'HISTÓRICO DE MOVIMENTAÇÕES CRM') !== false && strpos($resCsv['body'], "Ana Maria Select2 {$suf}") !== false,
        'relatorio_crm.php?export=csv gera CSV com BOM UTF-8 e todas as seções',
        "status={$resCsv['status']} ct={$resCsv['content_type']} bom=" . ($hasBom ? '1' : '0')
    );

    // 11. Resgate via balcão modular (POST api_fidelidade.php acao=resgatar_regra_modular)
    $resResgate = httpReq($baseUrl, 'api_fidelidade.php', 'POST', [
        'acao'       => 'resgatar_regra_modular',
        'cliente_id' => $cli1,
        'regra_id'   => $regraRecorrenteId,
    ], $cookies);
    $jsonResgate = json_decode($resResgate['body'], true);
    $txCount = (int)$pdo->query("SELECT COUNT(*) FROM fidelidade_transacoes WHERE instituicao_id = {$instId} AND cliente_id = {$cli1} AND regra_id = {$regraRecorrenteId} AND tipo = 'resgate_recompensa'")->fetchColumn();
    $ciclosAtual = (int)$pdo->query("SELECT ciclos_completados FROM cliente_progresso_regras WHERE cliente_id = {$cli1} AND regra_id = {$regraRecorrenteId}")->fetchColumn();
    ok(
        $resResgate['status'] === 200 && !empty($jsonResgate['success']) && $txCount === 1 && $ciclosAtual === 2,
        'POST api_fidelidade.php?acao=resgatar_regra_modular efetua resgate com limite 0 (ilimitado), registra resgate_recompensa e avança ciclo',
        substr($resResgate['body'], 0, 220) . " txCount={$txCount} ciclos={$ciclosAtual}"
    );

    // 12. Verificar TODAS as páginas atualizadas + validação JS inline via `node --check`
    $pagesToCheck = [
        'relatorio_crm.php?cliente_id=' . $cli1 => ['vendor/select2/select2.min.css', 'vendor/jquery-3.7.1.min.js', 'vendor/select2/select2.min.js', 'vendor/select2/i18n/pt-BR.js', 'id="header-global-search"', 'id="relatorio-crm-cliente-select"'],
        'vendas.php'                             => ['vendor/select2/select2.min.css', 'id="header-global-search"', 'id="pdv-search-select2"', 'id="pdv-cliente"'],
        'clientes.php'                           => ['vendor/select2/select2.min.css', 'id="searchInput"', 'relatorio_crm.php?cliente_id='],
        'agenda.php'                             => ['vendor/select2/select2.min.css', 'id="filtro-agenda-busca"', 'data-s2-allow-clear="1"'],
        'estoque.php'                            => ['vendor/select2/select2.min.css', 'id="productSearchInput"', 'data-s2-tags="1"'],
        'produtos.php'                           => ['vendor/select2/select2.min.css', 'id="produtos-select2-busca"', 'data-s2-tags="1"'],
        'crm_vendas.php'                         => ['vendor/select2/select2.min.css', 'id="filtro-rapido"', 'relatorio_crm.php?cliente_id='],
        'fidelizacao.php'                        => ['vendor/select2/select2.min.css', 'id="balcao-busca"', 'id="balcao-btn-relatorio"', 'relatorio_crm.php'],
        'usuarios.php'                           => ['vendor/select2/select2.min.css', 'PetCrmSelect2'],
    ];

    foreach ($pagesToCheck as $path => $expectedMarkers) {
        $r = httpReq($baseUrl, $path, 'GET', null, $cookies);
        $phpErr = hasPhpRuntimeError($r['body']);
        $missing = [];
        foreach ($expectedMarkers as $m) {
            if (strpos($r['body'], $m) === false) {
                $missing[] = $m;
            }
        }
        ok(
            $r['status'] === 200 && $phpErr === '' && empty($missing),
            "Página {$path} renderiza HTTP 200 sem erros PHP e contém ganchos Select2",
            "status={$r['status']}" . ($phpErr !== '' ? " phpErr={$phpErr}" : '') . (!empty($missing) ? ' missing=' . implode(',', $missing) : '')
        );

        $jsCheck = validateInlineScriptsWithNode($r['body'], $path);
        ok(
            empty($jsCheck['errors']),
            "Inline <script> em {$path} ({$jsCheck['count']} blocos) passa node --check",
            implode(' ; ', $jsCheck['errors'])
        );
    }

    // dashboard_rede.php (pode retornar 200 ou 302 se o usuário não pertencer a uma `redes`, verificamos zero erro 500 / PHP error e script check se 200)
    $rRede = httpReq($baseUrl, 'dashboard_rede.php', 'GET', null, $cookies);
    $errRede = hasPhpRuntimeError($rRede['body']);
    ok(
        in_array($rRede['status'], [200, 302], true) && $errRede === '',
        'dashboard_rede.php responde sem erro 500 ou erro PHP',
        "status={$rRede['status']} err={$errRede}"
    );
    if ($rRede['status'] === 200) {
        $jsRede = validateInlineScriptsWithNode($rRede['body'], 'dashboard_rede.php');
        ok(empty($jsRede['errors']), "Inline <script> em dashboard_rede.php passa node --check", implode(' ; ', $jsRede['errors']));
    }

    echo "\nResumo: {$passed} passed, {$failed} failed.\n";
} finally {
    if (is_resource($serverProc)) {
        @proc_terminate($serverProc);
        @proc_close($serverProc);
    }
    @unlink($serverErrLog);

    // Limpeza determinística dos dados QA
    try {
        if ($instId > 0) {
            $pdo->prepare("DELETE FROM fidelidade_transacoes WHERE instituicao_id = ?")->execute([$instId]);
            $pdo->prepare("DELETE FROM promocao_usos WHERE instituicao_id = ?")->execute([$instId]);
            $pdo->prepare("DELETE FROM cliente_progresso_regras WHERE instituicao_id = ?")->execute([$instId]);
            $pdo->prepare("DELETE FROM cliente_fidelidade WHERE instituicao_id = ?")->execute([$instId]);
            $pdo->prepare("DELETE FROM oportunidades_venda WHERE instituicao_id = ?")->execute([$instId]);
            $pdo->prepare("DELETE FROM agendamentos WHERE instituicao_id = ?")->execute([$instId]);
            if ($vendaId > 0) {
                $pdo->prepare("DELETE FROM venda_itens WHERE venda_id = ?")->execute([$vendaId]);
            }
            $pdo->prepare("DELETE FROM vendas WHERE instituicao_id = ?")->execute([$instId]);
            $pdo->prepare("DELETE FROM promocoes WHERE instituicao_id = ?")->execute([$instId]);
            $pdo->prepare("DELETE FROM fidelidade_regras WHERE instituicao_id = ?")->execute([$instId]);
            $pdo->prepare("DELETE FROM fidelidade_config WHERE instituicao_id = ?")->execute([$instId]);
            $pdo->prepare("DELETE FROM pets WHERE instituicao_id = ?")->execute([$instId]);
            $pdo->prepare("DELETE FROM clientes WHERE instituicao_id = ?")->execute([$instId]);
            $pdo->prepare("DELETE FROM produtos WHERE instituicao_id = ?")->execute([$instId]);
            $pdo->prepare("DELETE FROM servicos WHERE instituicao_id = ?")->execute([$instId]);
            $pdo->prepare("DELETE FROM usuarios WHERE instituicao_id = ?")->execute([$instId]);
            $pdo->prepare("DELETE FROM instituicoes WHERE id = ?")->execute([$instId]);
        }
    } catch (Throwable $cleanupErr) {
        fwrite(STDERR, "Aviso limpeza QA: " . $cleanupErr->getMessage() . "\n");
    }
}

exit($failed > 0 ? 1 : 0);

