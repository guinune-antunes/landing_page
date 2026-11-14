<?php
require_once 'includes/verifica_login.php';
require_once 'conexao.php';
require_once __DIR__ . '/includes/unidades.php';
require_once __DIR__ . '/includes/motor_fidelidade.php';
require_once __DIR__ . '/includes/motor_nutricional.php';
require_once __DIR__ . '/includes/relatorio_crm_panorama.php';

$cargo = $_SESSION['usuario_cargo'] ?? '';
if ($cargo === 'Admin') {
    header("Location: super_admin_clientes.php");
    exit;
}

$id_loja = (int)($_SESSION['instituicao_id'] ?? 0);
if ($id_loja <= 0) {
    die("Sessão expirada. Faça login novamente.");
}

$id_loja_fid = obterInstituicaoFidelidade($pdo, $id_loja);
$ids_clientes = obterInstituicoesClientes($pdo, $id_loja);
$in_cli = implode(',', array_map('intval', $ids_clientes));

$cliente_id = intval($_GET['cliente_id'] ?? 0);
$data_inicio = trim($_GET['data_inicio'] ?? '');
$data_fim = trim($_GET['data_fim'] ?? '');
$tipo_evento = trim($_GET['tipo_evento'] ?? 'todos');

$stCfg = $pdo->prepare("SELECT * FROM fidelidade_config WHERE instituicao_id = ?");
$stCfg->execute([$id_loja_fid]);
$cfg = $stCfg->fetch(PDO::FETCH_ASSOC) ?: [
    'tipo_programa' => 'selos',
    'compras_para_recompensa' => 10,
    'recompensa_tipo' => 'desconto_percentual',
    'recompensa_valor' => 15.00,
    'reais_por_ponto' => 1.00,
    'cashback_percentual' => 5.00,
    'texto_cartao' => 'Cartão Fidelidade Pet VIP',
];

$labelsPoliticaRegra = [
    'unica_vez' => 'Uso Único (1 ciclo na vida)',
    'reiniciar_apos_resgate' => 'Reiniciar após Resgate',
    'reiniciar_ao_concluir' => 'Reiniciar ao Concluir',
    'cooldown_dias' => 'Carência entre Ciclos (Cooldown)',
    'ilimitada_por_compra' => 'Ilimitada por Compra',
];

$labelsPoliticaPromo = [
    'ilimitada' => 'Ilimitada',
    'unica_vez' => '1x por Cliente',
    'uma_por_dia' => '1x por Dia',
    'cooldown_dias' => 'Carência (Dias)',
];

if (isset($_GET['acao']) && $_GET['acao'] === 'detalhar_promocao') {
    header('Content-Type: application/json; charset=utf-8');
    $promoIdReq = (int)($_GET['promocao_id'] ?? 0);
    if ($promoIdReq <= 0) {
        echo json_encode(['success' => false, 'message' => 'Promoção inválida.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $periodoReq = crmPanoramaResolverPeriodo(trim($_GET['data_inicio'] ?? ''), trim($_GET['data_fim'] ?? ''));
    $detalheReq = crmPanoramaDetalharPromocao($pdo, $id_loja, $promoIdReq, $periodoReq, $labelsPoliticaPromo);
    if (!$detalheReq) {
        echo json_encode(['success' => false, 'message' => 'Promoção não encontrada para esta unidade.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['success' => true, 'dados' => $detalheReq], JSON_UNESCAPED_UNICODE);
    exit;
}

$labelsTipoTransacao = [
    'acumulo_selo' => ['Acúmulo de Selo/Progresso', '#0284c7', '#e0f2fe'],
    'resgate_recompensa' => ['Resgate de Recompensa', '#b45309', '#fef3c7'],
    'desconto_direto' => ['Desconto Direto CRM', '#047857', '#d1fae5'],
    'promocao_relampago' => ['Promoção Relâmpago', '#be185d', '#fce7f3'],
    'bonus_acelerador' => ['Cashback / Bônus', '#0f766e', '#ccfbf1'],
    'resgate_cashback' => ['Uso de Saldo Cashback', '#7c3aed', '#ede9fe'],
    'acumulo_pontos' => ['Acúmulo de Pontos', '#d97706', '#fef3c7'],
    'reinicio_ciclo' => ['Reinício de Ciclo', '#0369a1', '#e0f2fe'],
    'expirado' => ['Expirado', '#dc2626', '#fee2e2'],
];

// Lista inicial de clientes para popular select2 antes do usuário digitar
$stListaCli = $pdo->prepare("SELECT c.id, c.nome_completo, c.telefone,
                                    COALESCE(cf.selos_atuais, 0) AS selos,
                                    COALESCE(cf.tier_atual, 'Bronze') AS tier,
                                    COALESCE(cf.total_gasto_historico, 0) AS total_gasto_historico
                             FROM clientes c
                             LEFT JOIN cliente_fidelidade cf ON cf.cliente_id = c.id AND cf.instituicao_id = ?
                             WHERE c.instituicao_id IN ($in_cli)
                             ORDER BY c.nome_completo ASC
                             LIMIT 200");
$stListaCli->execute([$id_loja_fid]);
$clientesSelect = $stListaCli->fetchAll(PDO::FETCH_ASSOC);

$cliente = null;
$pets = [];
$aniversarioInfo = ['aniversario' => false, 'motivo' => null];
$cliFid = [
    'selos_atuais' => 0,
    'ciclos_completados' => 0,
    'pontos_acumulados' => 0,
    'saldo_cashback' => 0.00,
    'tier_atual' => 'Bronze',
    'total_gasto_historico' => 0.00,
    'ultimo_acesso' => null,
];
$kpisCliente = [
    'qtd_vendas' => 0,
    'soma_liquido' => 0.0,
    'ticket_medio' => 0.0,
    'desconto_promocao' => 0.0,
    'desconto_fidelidade' => 0.0,
    'desconto_total' => 0.0,
    'cashback_gerado' => 0.0,
    'cashback_utilizado' => 0.0,
    'pontos_gerados' => 0,
    'primeira_compra' => null,
    'ultima_compra' => null,
];
$regrasCliente = [];
$transacoes = [];
$promosUsadas = [];
$vendasCliente = [];
$itensPorVenda = [];
$oportunidades = [];
$agendamentos = [];

if ($cliente_id > 0) {
    $stCli = $pdo->prepare("SELECT c.*, COALESCE(i.nome_unidade, i.nome_fantasia) AS loja_nome
                            FROM clientes c
                            LEFT JOIN instituicoes i ON i.id = c.instituicao_id
                            WHERE c.id = ? AND c.instituicao_id IN ($in_cli)
                            LIMIT 1");
    $stCli->execute([$cliente_id]);
    $cliente = $stCli->fetch(PDO::FETCH_ASSOC);

    if ($cliente) {
        $stPets = $pdo->prepare("SELECT * FROM pets WHERE cliente_id = ? ORDER BY nome ASC");
        $stPets->execute([$cliente_id]);
        $pets = $stPets->fetchAll(PDO::FETCH_ASSOC);

        $aniversarioInfo = crmVerificarAniversarioCliente($pdo, $cliente_id, $ids_clientes);

        $stCf = $pdo->prepare("SELECT * FROM cliente_fidelidade WHERE instituicao_id = ? AND cliente_id = ? LIMIT 1");
        $stCf->execute([$id_loja_fid, $cliente_id]);
        $cfRow = $stCf->fetch(PDO::FETCH_ASSOC);
        if ($cfRow) {
            $cliFid = array_merge($cliFid, $cfRow);
        }

        // KPIs agregados de vendas do cliente (respeitando filtro de data se informado)
        $whereDataVendas = '';
        $paramsVendasKpi = [$cliente_id, $id_loja_fid];
        // Considera compras na loja atual ou em toda rede conforme escopo de fidelidade
        $idsLojasRel = ($id_loja_fid === $id_loja && count($ids_clientes) === 1) ? [$id_loja] : $ids_clientes;
        $inLojasRel = implode(',', array_map('intval', $idsLojasRel));

        $paramsVendasKpi = [$cliente_id];
        if ($data_inicio !== '') {
            $whereDataVendas .= " AND DATE(v.data_venda) >= ?";
            $paramsVendasKpi[] = $data_inicio;
        }
        if ($data_fim !== '') {
            $whereDataVendas .= " AND DATE(v.data_venda) <= ?";
            $paramsVendasKpi[] = $data_fim;
        }

        $sqlKpiV = "SELECT COUNT(*) AS qtd_vendas,
                           COALESCE(SUM(v.valor_total), 0) AS soma_liquido,
                           COALESCE(SUM(v.desconto_promocao), 0) AS desconto_promocao,
                           COALESCE(SUM(v.desconto_fidelidade), 0) AS desconto_fidelidade,
                           COALESCE(SUM(v.desconto), 0) AS desconto_total,
                           COALESCE(SUM(v.cashback_gerado), 0) AS cashback_gerado,
                           COALESCE(SUM(v.cashback_utilizado), 0) AS cashback_utilizado,
                           COALESCE(SUM(v.pontos_gerados), 0) AS pontos_gerados,
                           MIN(v.data_venda) AS primeira_compra,
                           MAX(v.data_venda) AS ultima_compra
                    FROM vendas v
                    WHERE v.cliente_id = ? AND v.instituicao_id IN ($inLojasRel) {$whereDataVendas}";
        $stKv = $pdo->prepare($sqlKpiV);
        $stKv->execute($paramsVendasKpi);
        $kv = $stKv->fetch(PDO::FETCH_ASSOC);
        if ($kv) {
            $kpisCliente = [
                'qtd_vendas' => (int)$kv['qtd_vendas'],
                'soma_liquido' => (float)$kv['soma_liquido'],
                'ticket_medio' => ((int)$kv['qtd_vendas'] > 0) ? ((float)$kv['soma_liquido'] / (int)$kv['qtd_vendas']) : 0.0,
                'desconto_promocao' => (float)$kv['desconto_promocao'],
                'desconto_fidelidade' => (float)$kv['desconto_fidelidade'],
                'desconto_total' => (float)$kv['desconto_total'],
                'cashback_gerado' => (float)$kv['cashback_gerado'],
                'cashback_utilizado' => (float)$kv['cashback_utilizado'],
                'pontos_gerados' => (int)$kv['pontos_gerados'],
                'primeira_compra' => $kv['primeira_compra'],
                'ultima_compra' => $kv['ultima_compra'],
            ];
        }

        // Regras Modulares & Status de Reentrada ao vivo
        $sqlReg = "SELECT r.*,
                          cpr.progresso_atual, cpr.ciclos_completados, cpr.recompensa_disponivel,
                          cpr.ultimo_uso, cpr.ultimo_resgate_em, cpr.ultima_venda_id,
                          cpr.bloqueado_reentrada, cpr.observacao_status,
                          CASE
                              WHEN r.categoria_alvo = 'item_especifico' AND r.item_alvo_tipo = 'servico' THEN COALESCE(serv.nome_servico, CONCAT('Serviço #', r.item_alvo_id))
                              WHEN r.categoria_alvo = 'item_especifico' AND r.item_alvo_tipo = 'produto' THEN COALESCE(prod.nome_produto, CONCAT('Produto #', r.item_alvo_id))
                              WHEN r.categoria_alvo = 'item_especifico' THEN COALESCE(prod.nome_produto, serv.nome_servico)
                              ELSE NULL
                          END AS item_alvo_nome
                   FROM fidelidade_regras r
                   LEFT JOIN cliente_progresso_regras cpr ON cpr.regra_id = r.id AND cpr.cliente_id = ?
                   LEFT JOIN produtos prod ON r.categoria_alvo = 'item_especifico' AND (r.item_alvo_tipo IS NULL OR r.item_alvo_tipo = 'produto') AND prod.id = r.item_alvo_id
                   LEFT JOIN servicos serv ON r.categoria_alvo = 'item_especifico' AND r.item_alvo_tipo = 'servico' AND serv.id = r.item_alvo_id
                   WHERE r.instituicao_id = ? AND (r.ativo = 1 OR cpr.id IS NOT NULL)
                   ORDER BY r.ativo DESC, r.prioridade ASC, r.id ASC";
        $stReg = $pdo->prepare($sqlReg);
        $stReg->execute([$cliente_id, $id_loja_fid]);
        $agoraTs = time();
        foreach ($stReg->fetchAll(PDO::FETCH_ASSOC) as $rRow) {
            $progRow = ($rRow['progresso_atual'] !== null || $rRow['ciclos_completados'] !== null) ? $rRow : null;
            $rRow['_status'] = crmAvaliarPoliticaReentradaRegra($rRow, $progRow, $agoraTs);
            $regrasCliente[] = $rRow;
        }

        // Histórico Unificado de Transações de Fidelidade
        $sqlTr = "SELECT t.*, r.nome_regra, p.titulo AS promocao_titulo
                  FROM fidelidade_transacoes t
                  LEFT JOIN fidelidade_regras r ON r.id = t.regra_id
                  LEFT JOIN promocoes p ON p.id = t.promocao_id
                  WHERE t.cliente_id = ? AND t.instituicao_id = ?";
        $pTr = [$cliente_id, $id_loja_fid];
        if ($data_inicio !== '') {
            $sqlTr .= " AND DATE(t.data_transacao) >= ?";
            $pTr[] = $data_inicio;
        }
        if ($data_fim !== '') {
            $sqlTr .= " AND DATE(t.data_transacao) <= ?";
            $pTr[] = $data_fim;
        }
        if ($tipo_evento !== '' && $tipo_evento !== 'todos') {
            $sqlTr .= " AND t.tipo = ?";
            $pTr[] = $tipo_evento;
        }
        $sqlTr .= " ORDER BY t.data_transacao DESC, t.id DESC LIMIT 300";
        $stTr = $pdo->prepare($sqlTr);
        $stTr->execute($pTr);
        $transacoes = $stTr->fetchAll(PDO::FETCH_ASSOC);

        // Histórico de Promoções Relâmpago Utilizadas
        $sqlPu = "SELECT pu.*, p.titulo, p.desconto_percentual, p.politica_reentrada, p.cooldown_dias,
                         p.limite_usos_cliente, prod.nome_produto
                  FROM promocao_usos pu
                  JOIN promocoes p ON p.id = pu.promocao_id
                  LEFT JOIN produtos prod ON prod.id = p.produto_id
                  WHERE pu.cliente_id = ? AND pu.instituicao_id IN ($inLojasRel)";
        $pPu = [$cliente_id];
        if ($data_inicio !== '') {
            $sqlPu .= " AND DATE(pu.data_uso) >= ?";
            $pPu[] = $data_inicio;
        }
        if ($data_fim !== '') {
            $sqlPu .= " AND DATE(pu.data_uso) <= ?";
            $pPu[] = $data_fim;
        }
        $sqlPu .= " ORDER BY pu.data_uso DESC, pu.id DESC LIMIT 200";
        $stPu = $pdo->prepare($sqlPu);
        $stPu->execute($pPu);
        $promosUsadas = $stPu->fetchAll(PDO::FETCH_ASSOC);

        // Compras do PDV + itens
        $sqlV = "SELECT v.*, COALESCE(i.nome_unidade, i.nome_fantasia) AS loja_nome
                 FROM vendas v
                 LEFT JOIN instituicoes i ON i.id = v.instituicao_id
                 WHERE v.cliente_id = ? AND v.instituicao_id IN ($inLojasRel) {$whereDataVendas}
                 ORDER BY v.data_venda DESC, v.id DESC LIMIT 100";
        $stV = $pdo->prepare($sqlV);
        $stV->execute($paramsVendasKpi);
        $vendasCliente = $stV->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($vendasCliente)) {
            $vendaIds = array_map(fn($x) => (int)$x['id'], $vendasCliente);
            $inV = implode(',', $vendaIds);
            $stIt = $pdo->query("SELECT * FROM venda_itens WHERE venda_id IN ($inV) ORDER BY id ASC");
            foreach ($stIt->fetchAll(PDO::FETCH_ASSOC) as $vi) {
                $itensPorVenda[(int)$vi['venda_id']][] = $vi;
            }
        }

        // Esteira Preditiva de Reposição Nutricional (oportunidades_venda)
        $stOp = $pdo->prepare("SELECT o.*, p.nome AS pet_nome, p.especie, p.peso_kg, p.fator_manutencao,
                                      prod.nome_produto, prod.tamanho_embalagem_kg, prod.energia_metabolizavel_kg, prod.preco_venda
                               FROM oportunidades_venda o
                               JOIN pets p ON p.id = o.pet_id
                               JOIN produtos prod ON prod.id = o.produto_id
                               WHERE o.cliente_id = ? AND o.instituicao_id IN ($inLojasRel)
                               ORDER BY o.data_compra DESC LIMIT 50");
        $stOp->execute([$cliente_id]);
        foreach ($stOp->fetchAll(PDO::FETCH_ASSOC) as $opRow) {
            $diasDur = calcularDiasRacao($opRow['peso_kg'], $opRow['fator_manutencao'], $opRow['energia_metabolizavel_kg'], $opRow['tamanho_embalagem_kg']);
            if (!$diasDur || $diasDur <= 0) $diasDur = 30;
            $dtFim = date('d/m/Y', strtotime($opRow['data_compra'] . " +{$diasDur} days"));
            $opRow['dias_duracao'] = $diasDur;
            $opRow['data_fim_prevista'] = $dtFim;
            $oportunidades[] = $opRow;
        }

        // Agendamentos recentes do cliente
        $stAg = $pdo->prepare("SELECT a.*, p.nome AS pet_nome
                               FROM agendamentos a
                               LEFT JOIN pets p ON p.id = a.pet_id
                               WHERE a.cliente_id = ? AND a.instituicao_id IN ($inLojasRel)
                               ORDER BY a.data_hora_inicio DESC LIMIT 30");
        $stAg->execute([$cliente_id]);
        $agendamentos = $stAg->fetchAll(PDO::FETCH_ASSOC);

        // Exportação CSV quando solicitada
        if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            $slugNome = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $cliente['nome_completo']);
            header('Content-Type: text/csv; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"relatorio_crm_{$slugNome}.csv\"");
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['RELATÓRIO CRM 360° DO CLIENTE'], ';');
            fputcsv($out, ['Cliente', $cliente['nome_completo'], 'Telefone', $cliente['telefone'], 'CPF', $cliente['cpf'], 'Tier', $cliFid['tier_atual']], ';');
            fputcsv($out, ['Selos Clássicos', $cliFid['selos_atuais'] . '/' . $cfg['compras_para_recompensa'], 'Ciclos Clássicos', $cliFid['ciclos_completados'], 'Pontos', $cliFid['pontos_acumulados'], 'Saldo Cashback (R$)', number_format((float)$cliFid['saldo_cashback'], 2, ',', '.')], ';');
            fputcsv($out, ['Compras no Período', $kpisCliente['qtd_vendas'], 'Total Pago (R$)', number_format($kpisCliente['soma_liquido'], 2, ',', '.'), 'Desconto Promo (R$)', number_format($kpisCliente['desconto_promocao'], 2, ',', '.'), 'Desconto Fidelidade (R$)', number_format($kpisCliente['desconto_fidelidade'], 2, ',', '.')], ';');
            fputcsv($out, [], ';');

            fputcsv($out, ['REGRAS MODULARES & CICLOS DE REENTRADA'], ';');
            fputcsv($out, ['Regra', 'Mecânica', 'Política Reentrada', 'Progresso', 'Meta', 'Ciclos Completados', 'Limite Ciclos', 'Última Compra', 'Último Resgate', 'Status Atual'], ';');
            foreach ($regrasCliente as $rg) {
                fputcsv($out, [
                    $rg['nome_regra'],
                    $rg['tipo_mecanica'],
                    $labelsPoliticaRegra[$rg['politica_reentrada'] ?? 'reiniciar_apos_resgate'] ?? $rg['politica_reentrada'],
                    (int)($rg['progresso_atual'] ?? 0),
                    (int)($rg['condicao_valor'] ?? 0),
                    (int)($rg['ciclos_completados'] ?? 0),
                    $rg['limite_ciclos_cliente'] ?: 'Ilimitado',
                    $rg['ultimo_uso'] ? date('d/m/Y H:i', strtotime($rg['ultimo_uso'])) : '-',
                    $rg['ultimo_resgate_em'] ? date('d/m/Y H:i', strtotime($rg['ultimo_resgate_em'])) : '-',
                    $rg['_status']['status_label'] ?? ''
                ], ';');
            }
            fputcsv($out, [], ';');

            fputcsv($out, ['HISTÓRICO DE MOVIMENTAÇÕES CRM'], ';');
            fputcsv($out, ['Data/Hora', 'Tipo', 'Regra / Promoção', 'Venda #', 'Qtd/Pontos', 'Valor (R$)', 'Operador', 'Descrição'], ';');
            foreach ($transacoes as $tr) {
                fputcsv($out, [
                    date('d/m/Y H:i', strtotime($tr['data_transacao'])),
                    $labelsTipoTransacao[$tr['tipo']][0] ?? $tr['tipo'],
                    $tr['nome_regra'] ?: ($tr['promocao_titulo'] ?: 'Cartão Base'),
                    $tr['venda_id'] ? '#' . $tr['venda_id'] : '-',
                    $tr['quantidade'],
                    number_format((float)$tr['valor_recompensa'], 2, ',', '.'),
                    $tr['usuario_operador'],
                    $tr['descricao'],
                ], ';');
            }
            fputcsv($out, [], ';');

            fputcsv($out, ['HISTÓRICO DE COMPRAS PDV'], ';');
            fputcsv($out, ['Venda #', 'Data/Hora', 'Forma Pagto', 'Itens', 'Desc. Promo', 'Desc. Fidelidade', 'Cashback Usado', 'Cashback Ganho', 'Pts', 'Total Pago (R$)'], ';');
            foreach ($vendasCliente as $vd) {
                $its = $itensPorVenda[(int)$vd['id']] ?? [];
                $itsTxt = implode(' | ', array_map(fn($x) => $x['quantidade'] . 'x ' . $x['nome_produto'], $its));
                fputcsv($out, [
                    '#' . $vd['id'],
                    date('d/m/Y H:i', strtotime($vd['data_venda'])),
                    $vd['forma_pagamento'],
                    $itsTxt,
                    number_format((float)$vd['desconto_promocao'], 2, ',', '.'),
                    number_format((float)$vd['desconto_fidelidade'], 2, ',', '.'),
                    number_format((float)$vd['cashback_utilizado'], 2, ',', '.'),
                    number_format((float)$vd['cashback_gerado'], 2, ',', '.'),
                    (int)$vd['pontos_gerados'],
                    number_format((float)$vd['valor_total'], 2, ',', '.'),
                ], ';');
            }
            fclose($out);
            exit;
        }
    }
}

// Visão Geral da Loja (quando nenhum cliente específico selecionado ou abaixo para troca rápida)
$panoramaKpis = ['clientes_ativos' => 0, 'selos_totais' => 0, 'ciclos_totais' => 0, 'cashback_circulante' => 0.0, 'promos_usadas_total' => 0, 'regras_prontas' => 0];
$rankingClientes = [];
$panoramaPeriodo = crmPanoramaResolverPeriodo($data_inicio, $data_fim);
$panoramaDetalhes = null;
if ($cliente_id <= 0 || !$cliente) {
    $stPan = $pdo->prepare("SELECT COUNT(*) AS cli_ativos,
                                   COALESCE(SUM(selos_atuais), 0) AS selos_totais,
                                   COALESCE(SUM(ciclos_completados), 0) AS ciclos_totais,
                                   COALESCE(SUM(saldo_cashback), 0) AS cashback_circulante
                            FROM cliente_fidelidade
                            WHERE instituicao_id = ?");
    $stPan->execute([$id_loja_fid]);
    $pan = $stPan->fetch(PDO::FETCH_ASSOC);
    if ($pan) {
        $panoramaKpis['clientes_ativos'] = (int)$pan['cli_ativos'];
        $panoramaKpis['selos_totais'] = (int)$pan['selos_totais'];
        $panoramaKpis['ciclos_totais'] = (int)$pan['ciclos_totais'];
        $panoramaKpis['cashback_circulante'] = (float)$pan['cashback_circulante'];
    }

    $stPronto = $pdo->prepare("SELECT COUNT(*) FROM cliente_progresso_regras WHERE instituicao_id = ? AND recompensa_disponivel = 1");
    $stPronto->execute([$id_loja_fid]);
    $panoramaKpis['regras_prontas'] = (int)$stPronto->fetchColumn();

    $stPromoCnt = $pdo->prepare("SELECT COUNT(*) FROM promocao_usos WHERE instituicao_id = ?");
    $stPromoCnt->execute([$id_loja]);
    $panoramaKpis['promos_usadas_total'] = (int)$stPromoCnt->fetchColumn();

    $stRank = $pdo->prepare("SELECT c.id, c.nome_completo, c.telefone,
                                    COALESCE(cf.selos_atuais, 0) AS selos_atuais,
                                    COALESCE(cf.ciclos_completados, 0) AS ciclos_completados,
                                    COALESCE(cf.pontos_acumulados, 0) AS pontos_acumulados,
                                    COALESCE(cf.saldo_cashback, 0) AS saldo_cashback,
                                    COALESCE(cf.tier_atual, 'Bronze') AS tier_atual,
                                    COALESCE(cf.total_gasto_historico, 0) AS total_gasto_historico,
                                    cf.ultimo_acesso,
                                    COALESCE(agg_reg.premios_prontos, 0) AS premios_prontos,
                                    COALESCE(agg_reg.ciclos_modulares, 0) AS ciclos_modulares,
                                    COALESCE(agg_pu.qtd_promos, 0) AS qtd_promos
                             FROM clientes c
                             LEFT JOIN cliente_fidelidade cf ON cf.cliente_id = c.id AND cf.instituicao_id = ?
                             LEFT JOIN (
                                 SELECT cliente_id,
                                        SUM(CASE WHEN recompensa_disponivel = 1 THEN 1 ELSE 0 END) AS premios_prontos,
                                        SUM(ciclos_completados) AS ciclos_modulares
                                 FROM cliente_progresso_regras
                                 WHERE instituicao_id = ?
                                 GROUP BY cliente_id
                             ) agg_reg ON agg_reg.cliente_id = c.id
                             LEFT JOIN (
                                 SELECT cliente_id, COUNT(*) AS qtd_promos
                                 FROM promocao_usos
                                 WHERE instituicao_id = ?
                                 GROUP BY cliente_id
                             ) agg_pu ON agg_pu.cliente_id = c.id
                             WHERE c.instituicao_id IN ($in_cli)
                             ORDER BY premios_prontos DESC, COALESCE(cf.total_gasto_historico, 0) DESC, c.nome_completo ASC
                             LIMIT 40");
    $stRank->execute([$id_loja_fid, $id_loja_fid, $id_loja]);
    $rankingClientes = $stRank->fetchAll(PDO::FETCH_ASSOC);

    $panoramaDetalhes = crmPanoramaCarregarDados($pdo, $id_loja, $id_loja_fid, $ids_clientes, $panoramaPeriodo);
}

$pageTitle = $cliente ? ('Relatório CRM · ' . $cliente['nome_completo']) : 'Relatório CRM por Cliente';
$paginaAtiva = 'relatorio_crm';
include 'includes/_head.php';
?>

<style>
    .crm-rep-filter-bar {
        background: var(--bg-widget);
        border: 1px solid var(--border-color);
        border-radius: 14px;
        padding: 18px 20px;
        margin-bottom: 22px;
        box-shadow: 0 3px 10px rgba(0,0,0,0.04);
    }
    .crm-rep-filter-grid {
        display: grid;
        grid-template-columns: 2fr 1fr 1fr 1.2fr auto;
        gap: 12px;
        align-items: end;
    }
    .crm-dossier-hero {
        background: linear-gradient(135deg, #0f172a, #1e293b);
        color: #f8fafc;
        border-radius: 16px;
        padding: 22px 26px;
        margin-bottom: 22px;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        flex-wrap: wrap;
        gap: 18px;
        box-shadow: 0 10px 25px rgba(15, 23, 42, 0.16);
    }
    .crm-section-card {
        background: var(--bg-widget);
        border: 1px solid var(--border-color);
        border-radius: 14px;
        padding: 20px;
        margin-bottom: 22px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.03);
    }
    .crm-section-title {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 14px;
        padding-bottom: 10px;
        border-bottom: 1px solid var(--border-color-light);
    }
    .crm-section-title h3 {
        margin: 0;
        font-size: 1.05rem;
        font-weight: 700;
        color: var(--text-dark);
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .crm-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 9px;
        border-radius: 20px;
        font-size: 0.74rem;
        font-weight: 700;
        white-space: nowrap;
    }
    .crm-progress-bar {
        width: 110px;
        height: 7px;
        border-radius: 99px;
        background: rgba(148, 163, 184, 0.25);
        overflow: hidden;
        margin-top: 4px;
    }
    .crm-progress-fill {
        height: 100%;
        background: #1abc9c;
        border-radius: 99px;
    }
    .crm-pet-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: rgba(255,255,255,0.12);
        border: 1px solid rgba(255,255,255,0.18);
        color: #f8fafc;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.78rem;
    }
    .crm-venda-card {
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 14px 16px;
        margin-bottom: 12px;
        background: var(--bg-main);
    }
    .crm-period-banner {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
        background: var(--bg-widget);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 12px 18px;
        margin-bottom: 18px;
    }
    .crm-period-presets {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 6px;
    }
    .crm-preset-link {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 11px;
        border-radius: 999px;
        font-size: 0.78rem;
        font-weight: 600;
        text-decoration: none;
        background: var(--bg-main);
        border: 1px solid var(--border-color);
        color: var(--text-dark);
        transition: all 0.18s ease;
    }
    .crm-preset-link:hover {
        border-color: #1abc9c;
        color: #1abc9c;
    }
    .crm-charts-grid {
        display: grid;
        grid-template-columns: 1.15fr 1fr;
        gap: 18px;
        margin-bottom: 20px;
    }
    .crm-chart-box {
        background: var(--bg-main);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 16px;
        display: flex;
        flex-direction: column;
    }
    .crm-chart-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 12px;
    }
    .crm-chart-header h4 {
        margin: 0;
        font-size: 0.94rem;
        font-weight: 700;
        color: var(--text-dark);
    }
    .crm-chart-wrap {
        position: relative;
        height: 310px;
        width: 100%;
    }
    .crm-cross-strip {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
        gap: 12px;
        margin-bottom: 18px;
    }
    .crm-cross-mini {
        background: var(--bg-main);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        padding: 12px 14px;
    }
    .crm-cross-mini small {
        display: block;
        color: var(--text-muted);
        font-size: 0.74rem;
        font-weight: 600;
        margin-bottom: 4px;
    }
    .crm-cross-mini strong {
        font-size: 1.08rem;
        color: var(--text-dark);
    }
    .crm-share-track {
        width: 110px;
        height: 8px;
        border-radius: 999px;
        background: rgba(148, 163, 184, 0.22);
        overflow: hidden;
        margin-top: 4px;
    }
    .crm-share-fill {
        height: 100%;
        border-radius: 999px;
        background: linear-gradient(90deg, #1abc9c, #0ea5e9);
    }
    .crm-acq-tabs {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 14px;
    }
    .crm-tab-btn,
    .crm-pill-filter {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border: 1px solid var(--border-color);
        background: var(--bg-main);
        color: var(--text-dark);
        border-radius: 10px;
        padding: 7px 14px;
        font-size: 0.82rem;
        font-weight: 600;
        cursor: pointer;
        font-family: inherit;
        transition: all 0.18s ease;
    }
    .crm-pill-filter {
        border-radius: 999px;
        padding: 5px 12px;
        font-size: 0.77rem;
    }
    .crm-tab-btn.active {
        background: #1abc9c;
        border-color: #1abc9c;
        color: #0f172a;
        font-weight: 700;
    }
    .crm-pill-filter.active {
        background: #0ea5e9;
        border-color: #0ea5e9;
        color: #ffffff;
    }
    .crm-tab-count {
        background: rgba(15, 23, 42, 0.12);
        padding: 1px 7px;
        border-radius: 999px;
        font-size: 0.74rem;
        font-weight: 700;
    }
    html.dark-theme .crm-tab-btn:not(.active) .crm-tab-count {
        background: rgba(255, 255, 255, 0.12);
    }
    .crm-badge-newcad {
        background: #d1fae5;
        color: #065f46;
    }
    .crm-badge-oldcad {
        background: #e0f2fe;
        color: #075985;
    }
    .crm-badge-wait {
        background: #fef3c7;
        color: #92400e;
    }
    html.dark-theme .crm-badge-newcad {
        background: rgba(16, 185, 129, 0.18);
        color: #6ee7b7;
    }
    html.dark-theme .crm-badge-oldcad {
        background: rgba(56, 189, 248, 0.18);
        color: #7dd3fc;
    }
    html.dark-theme .crm-badge-wait {
        background: rgba(245, 158, 11, 0.18);
        color: #fcd34d;
    }
    tr.crm-promo-clickable {
        cursor: pointer;
        transition: background-color 0.15s ease;
    }
    tr.crm-promo-clickable:hover,
    tr.crm-promo-clickable:focus {
        background-color: rgba(26, 188, 156, 0.08) !important;
        outline: none;
    }
    .crm-promo-inspect-hint {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 0.72rem;
        font-weight: 700;
        color: #1abc9c;
        margin-left: 6px;
        opacity: 0.85;
    }
    tr.crm-promo-clickable:hover .crm-promo-inspect-hint {
        opacity: 1;
        text-decoration: underline;
    }
    .crm-modal-backdrop {
        position: fixed;
        inset: 0;
        z-index: 9999;
        background: rgba(2, 6, 23, 0.72);
        backdrop-filter: blur(3px);
        display: none;
        align-items: center;
        justify-content: center;
        padding: 18px;
        overflow-y: auto;
    }
    .crm-modal-backdrop.open {
        display: flex;
    }
    .crm-modal-box {
        background: var(--bg-widget);
        color: var(--text-dark);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        width: 100%;
        max-width: 1060px;
        max-height: 92vh;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        box-shadow: 0 24px 60px rgba(0, 0, 0, 0.45);
    }
    .crm-modal-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 14px;
        padding: 18px 22px;
        border-bottom: 1px solid var(--border-color);
        background: var(--bg-main);
    }
    .crm-modal-close {
        background: transparent;
        border: 1px solid var(--border-color);
        color: var(--text-dark);
        width: 34px;
        height: 34px;
        border-radius: 10px;
        font-size: 1rem;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .crm-modal-close:hover {
        border-color: #ef4444;
        color: #ef4444;
    }
    .crm-modal-body {
        padding: 20px 22px;
        overflow-y: auto;
        flex: 1;
    }
    .crm-modal-kpis {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(175px, 1fr));
        gap: 12px;
        margin-bottom: 18px;
    }
    .crm-modal-kpi {
        background: var(--bg-main);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 12px 14px;
    }
    .crm-modal-kpi small {
        display: block;
        color: var(--text-muted);
        font-size: 0.74rem;
        font-weight: 600;
        margin-bottom: 4px;
    }
    .crm-modal-kpi strong {
        font-size: 1.12rem;
        color: var(--text-dark);
    }
    .crm-modal-grid {
        display: grid;
        grid-template-columns: 1.25fr 1fr;
        gap: 16px;
        margin-bottom: 18px;
    }
    .crm-pay-row {
        margin-bottom: 10px;
    }
    .crm-pay-bar-track {
        width: 100%;
        height: 7px;
        border-radius: 999px;
        background: rgba(148, 163, 184, 0.22);
        overflow: hidden;
        margin-top: 4px;
    }
    .crm-pay-bar-fill {
        height: 100%;
        border-radius: 999px;
        background: linear-gradient(90deg, #1abc9c, #3b82f6);
    }
    @media (max-width: 900px) {
        .crm-modal-grid {
            grid-template-columns: 1fr;
        }
    }
    @media (max-width: 1120px) {
        .crm-charts-grid {
            grid-template-columns: 1fr;
        }
    }
    @media (max-width: 1024px) {
        .crm-rep-filter-grid {
            grid-template-columns: 1fr 1fr;
        }
    }
    @media (max-width: 640px) {
        .crm-rep-filter-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<?php include 'includes/_sidebar.php'; ?>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; ?>

        <main class="main-content">
            <div class="page-header no-print" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:14px;">
                <div>
                    <h1 class="main-title" style="display:flex;align-items:center;gap:10px;">
                        <i class="fas fa-address-card" style="color:#1abc9c;"></i> Relatório CRM 360° do Cliente
                    </h1>
                    <p style="color:var(--text-muted);margin:0;">Histórico completo de ciclos, políticas de reentrada, promoções usadas, cashback, pontos, compras no PDV e reposição de ração.</p>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <a href="fidelizacao.php" class="btn btn-secondary" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
                        <i class="fas fa-award"></i> Regras & Ofertas
                    </a>
                    <?php if ($cliente): ?>
                        <a href="relatorio_crm.php?cliente_id=<?php echo (int)$cliente['id']; ?>&data_inicio=<?php echo urlencode($data_inicio); ?>&data_fim=<?php echo urlencode($data_fim); ?>&tipo_evento=<?php echo urlencode($tipo_evento); ?>&export=csv"
                           class="btn" style="background:#0f766e;color:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
                            <i class="fas fa-file-csv"></i> Exportar CSV
                        </a>
                        <button type="button" onclick="window.print()" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:6px;">
                            <i class="fas fa-print"></i> Imprimir Relatório
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- BARRA DE PESQUISA COM SELECT2 E FILTROS -->
            <div class="crm-rep-filter-bar no-print">
                <form method="GET" action="relatorio_crm.php" id="form-relatorio-crm-filtro">
                    <div class="crm-rep-filter-grid">
                        <div class="form-group" style="margin:0;">
                            <label for="relatorio-crm-cliente-select" style="font-size:0.82rem;font-weight:700;margin-bottom:5px;display:block;">
                                <i class="fas fa-search" style="color:#1abc9c;"></i> Pesquisar Cliente / Pet (Select2)
                            </label>
                            <select name="cliente_id" id="relatorio-crm-cliente-select" data-no-auto-select2="1" style="width:100%;">
                                <option value="">Panorama Geral da Loja (Todos os Clientes)</option>
                                <?php if ($cliente): ?>
                                    <option value="<?php echo (int)$cliente['id']; ?>" selected>
                                        <?php echo htmlspecialchars($cliente['nome_completo'] . (!empty($cliente['telefone']) ? ' · ' . $cliente['telefone'] : '')); ?>
                                    </option>
                                <?php endif; ?>
                                <?php foreach ($clientesSelect as $cs): ?>
                                    <?php if ($cliente && (int)$cs['id'] === (int)$cliente['id']) continue; ?>
                                    <option value="<?php echo (int)$cs['id']; ?>">
                                        <?php echo htmlspecialchars($cs['nome_completo'] . (!empty($cs['telefone']) ? ' · ' . $cs['telefone'] : '') . ' [' . $cs['tier'] . ']'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group" style="margin:0;">
                            <label for="filtro-data-inicio" style="font-size:0.82rem;font-weight:600;margin-bottom:5px;display:block;">Data Inicial</label>
                            <input type="date" id="filtro-data-inicio" name="data_inicio" value="<?php echo htmlspecialchars($data_inicio); ?>" class="form-control">
                        </div>

                        <div class="form-group" style="margin:0;">
                            <label for="filtro-data-fim" style="font-size:0.82rem;font-weight:600;margin-bottom:5px;display:block;">Data Final</label>
                            <input type="date" id="filtro-data-fim" name="data_fim" value="<?php echo htmlspecialchars($data_fim); ?>" class="form-control">
                        </div>

                        <div class="form-group" style="margin:0;">
                            <label for="filtro-tipo-evento" style="font-size:0.82rem;font-weight:600;margin-bottom:5px;display:block;">Tipo de Evento CRM</label>
                            <select name="tipo_evento" id="filtro-tipo-evento" style="width:100%;">
                                <option value="todos" <?php echo ($tipo_evento === 'todos') ? 'selected' : ''; ?>>Todos os Eventos</option>
                                <?php foreach ($labelsTipoTransacao as $tk => $tInfo): ?>
                                    <option value="<?php echo htmlspecialchars($tk); ?>" <?php echo ($tipo_evento === $tk) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tInfo[0]); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="display:flex;gap:6px;">
                            <button type="submit" class="btn btn-primary" style="height:40px;padding:0 16px;">
                                <i class="fas fa-filter"></i> Filtrar
                            </button>
                            <?php if ($cliente_id > 0 || $data_inicio !== '' || $data_fim !== '' || $tipo_evento !== 'todos'): ?>
                                <a href="relatorio_crm.php" class="btn btn-secondary" style="height:40px;display:inline-flex;align-items:center;padding:0 12px;text-decoration:none;" title="Limpar filtros">
                                    <i class="fas fa-times"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <?php if ($cliente_id > 0 && !$cliente): ?>
                <div class="crm-section-card" style="text-align:center;padding:40px;">
                    <i class="fas fa-user-slash" style="font-size:2.5rem;color:#94a3b8;margin-bottom:10px;"></i>
                    <h3>Cliente não encontrado nesta unidade/rede</h3>
                    <p style="color:var(--text-muted);">Selecione outro cliente no buscador Select2 acima.</p>
                </div>

            <?php elseif ($cliente): ?>
                <?php
                    $cartaoUrl = "cartao_cliente.php?cliente_id={$cliente['id']}&loja_id={$id_loja_fid}";
                    $msgZapCli = "Olá " . explode(' ', trim($cliente['nome_completo']))[0] . "! 🐾 Aqui é da Pet Shop. Confira seu cartão fidelidade e benefícios ativos em tempo real!";
                    $linkZapCli = crmFormatarWhatsappUrl($cliente['telefone'] ?? '', $msgZapCli);
                ?>

                <!-- HERO / DOSSIÊ DO CLIENTE -->
                <div class="crm-dossier-hero">
                    <div style="flex:1;min-width:260px;">
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px;">
                            <span style="background:#f59e0b;color:#111827;padding:4px 12px;border-radius:20px;font-size:0.78rem;font-weight:800;">
                                <i class="fas fa-crown"></i> Tier <?php echo htmlspecialchars($cliFid['tier_atual']); ?>
                            </span>
                            <?php if (!empty($aniversarioInfo['aniversario'])): ?>
                                <span style="background:#ec4899;color:#fff;padding:4px 12px;border-radius:20px;font-size:0.78rem;font-weight:700;">
                                    <i class="fas fa-birthday-cake"></i> Aniversariante do Mês (<?php echo htmlspecialchars($aniversarioInfo['motivo'] ?? 'Tutor/Pet'); ?>)
                                </span>
                            <?php endif; ?>
                            <span style="font-size:0.78rem;color:#94a3b8;">
                                Cliente #<?php echo (int)$cliente['id']; ?> · Desde <?php echo $cliente['data_cadastro'] ? date('d/m/Y', strtotime($cliente['data_cadastro'])) : '-'; ?>
                            </span>
                        </div>

                        <h2 style="margin:0 0 6px 0;font-size:1.5rem;font-weight:800;color:#ffffff;">
                            <?php echo htmlspecialchars($cliente['nome_completo']); ?>
                        </h2>

                        <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:0.86rem;color:#cbd5e1;margin-bottom:12px;">
                            <span><i class="fas fa-phone-alt" style="color:#1abc9c;"></i> <?php echo htmlspecialchars($cliente['telefone'] ?: 'Não informado'); ?></span>
                            <span><i class="fas fa-id-card" style="color:#38bdf8;"></i> CPF: <?php echo htmlspecialchars($cliente['cpf'] ?: 'Não informado'); ?></span>
                            <span><i class="fas fa-envelope" style="color:#f59e0b;"></i> <?php echo htmlspecialchars($cliente['email'] ?: 'Sem e-mail'); ?></span>
                            <?php if (!empty($cliente['data_nascimento'])): ?>
                                <span><i class="fas fa-birthday-cake" style="color:#f472b6;"></i> Nasc.: <?php echo date('d/m/Y', strtotime($cliente['data_nascimento'])); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($cliente['loja_nome'])): ?>
                                <span><i class="fas fa-store" style="color:#34d399;"></i> <?php echo htmlspecialchars($cliente['loja_nome']); ?></span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($pets)): ?>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                                <small style="color:#94a3b8;font-weight:600;">Pets vinculados:</small>
                                <?php foreach ($pets as $pt): ?>
                                    <span class="crm-pet-pill">
                                        <i class="fas <?php echo (stripos((string)$pt['especie'], 'gat') !== false) ? 'fa-cat' : 'fa-dog'; ?>" style="color:#1abc9c;"></i>
                                        <strong><?php echo htmlspecialchars($pt['nome']); ?></strong>
                                        <?php if (!empty($pt['raca'])): ?>· <?php echo htmlspecialchars($pt['raca']); ?><?php endif; ?>
                                        <?php if (!empty($pt['peso_kg'])): ?>· <?php echo (float)$pt['peso_kg']; ?> kg<?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <small style="color:#94a3b8;">Nenhum pet vinculado a este tutor.</small>
                        <?php endif; ?>
                    </div>

                    <div class="no-print" style="display:flex;flex-direction:column;gap:8px;min-width:190px;">
                        <a href="<?php echo htmlspecialchars($cartaoUrl); ?>" target="_blank" class="btn" style="background:#1abc9c;color:#0f172a;font-weight:700;text-align:center;text-decoration:none;border-radius:10px;padding:9px 14px;">
                            <i class="fas fa-id-badge"></i> Abrir Cartão Virtual
                        </a>
                        <a href="vendas.php?cliente_id=<?php echo (int)$cliente['id']; ?>" class="btn" style="background:#334155;color:#fff;font-weight:600;text-align:center;text-decoration:none;border-radius:10px;padding:9px 14px;">
                            <i class="fas fa-cash-register"></i> Nova Venda no PDV
                        </a>
                        <?php if ($linkZapCli !== ''): ?>
                            <a href="<?php echo htmlspecialchars($linkZapCli); ?>" target="_blank" class="btn" style="background:#22c55e;color:#fff;font-weight:600;text-align:center;text-decoration:none;border-radius:10px;padding:9px 14px;">
                                <i class="fab fa-whatsapp"></i> Chamar no WhatsApp
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 6 KPIS DO CLIENTE -->
                <div class="kpi-widgets" style="margin-bottom:22px;">
                    <div class="widget-card kpi-card">
                        <div class="card-icon green"><i class="fas fa-shopping-bag"></i></div>
                        <div class="card-content">
                            <span class="card-value">R$ <?php echo number_format($kpisCliente['soma_liquido'], 2, ',', '.'); ?></span>
                            <span class="card-label"><?php echo $kpisCliente['qtd_vendas']; ?> compra(s) · Ticket R$ <?php echo number_format($kpisCliente['ticket_medio'], 2, ',', '.'); ?></span>
                        </div>
                    </div>

                    <div class="widget-card kpi-card">
                        <div class="card-icon blue"><i class="fas fa-wallet"></i></div>
                        <div class="card-content">
                            <span class="card-value">R$ <?php echo number_format((float)$cliFid['saldo_cashback'], 2, ',', '.'); ?></span>
                            <span class="card-label">Saldo Cashback (Ganho: R$ <?php echo number_format($kpisCliente['cashback_gerado'], 2, ',', '.'); ?>)</span>
                        </div>
                    </div>

                    <div class="widget-card kpi-card">
                        <div class="card-icon" style="background:#fef3c7;color:#d97706;"><i class="fas fa-star"></i></div>
                        <div class="card-content">
                            <span class="card-value"><?php echo number_format((int)$cliFid['pontos_acumulados'], 0, ',', '.'); ?> pts</span>
                            <span class="card-label">Tier <?php echo htmlspecialchars($cliFid['tier_atual']); ?> · Histórico R$ <?php echo number_format((float)$cliFid['total_gasto_historico'], 2, ',', '.'); ?></span>
                        </div>
                    </div>

                    <div class="widget-card kpi-card">
                        <div class="card-icon purple"><i class="fas fa-stamp"></i></div>
                        <div class="card-content">
                            <span class="card-value"><?php echo (int)$cliFid['selos_atuais']; ?> / <?php echo (int)$cfg['compras_para_recompensa']; ?></span>
                            <span class="card-label">Selos Base · <?php echo (int)$cliFid['ciclos_completados']; ?> ciclo(s) resgatados</span>
                        </div>
                    </div>

                    <div class="widget-card kpi-card">
                        <div class="card-icon" style="background:#fce7f3;color:#db2777;"><i class="fas fa-percentage"></i></div>
                        <div class="card-content">
                            <span class="card-value">R$ <?php echo number_format($kpisCliente['desconto_total'] + $kpisCliente['cashback_utilizado'], 2, ',', '.'); ?></span>
                            <span class="card-label">Economia Total (Promo + Fidelidade + Cashback)</span>
                        </div>
                    </div>

                    <div class="widget-card kpi-card">
                        <div class="card-icon" style="background:#e0f2fe;color:#0284c7;"><i class="fas fa-bolt"></i></div>
                        <div class="card-content">
                            <span class="card-value"><?php echo count($promosUsadas); ?> promo(s)</span>
                            <span class="card-label">Última compra: <?php echo $kpisCliente['ultima_compra'] ? date('d/m/Y', strtotime($kpisCliente['ultima_compra'])) : 'Nenhuma'; ?></span>
                        </div>
                    </div>
                </div>

                <!-- 1. REGRAS MODULARES, CICLOS E POLÍTICAS DE REENTRADA -->
                <div class="crm-section-card">
                    <div class="crm-section-title">
                        <h3><i class="fas fa-layer-group" style="color:#1abc9c;"></i> Regras Modulares, Ciclos & Política de Reentrada</h3>
                        <small style="color:var(--text-muted);"><?php echo count($regrasCliente); ?> regra(s) monitorada(s)</small>
                    </div>

                    <?php if (!empty($regrasCliente)): ?>
                        <div class="table-container" style="overflow-x:auto;">
                            <table class="data-table" style="width:100%;border-collapse:collapse;">
                                <thead>
                                    <tr>
                                        <th>Regra / Alvo</th>
                                        <th>Política de Reentrada</th>
                                        <th>Progresso do Ciclo</th>
                                        <th>Ciclos Concluídos</th>
                                        <th>Último Uso / Resgate</th>
                                        <th>Status Atual no Motor</th>
                                        <th class="no-print">Ação</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($regrasCliente as $rg):
                                        $st = $rg['_status'];
                                        $meta = max(1, (int)$rg['condicao_valor']);
                                        $prog = (int)($rg['progresso_atual'] ?? 0);
                                        $pct = $rg['condicao_tipo'] === 'quantidade_compras' ? min(100, (int)round(($prog / $meta) * 100)) : 100;
                                        $ciclos = (int)($rg['ciclos_completados'] ?? 0);
                                        $limCiclos = crmLimitePositivo($rg['limite_ciclos_cliente'] ?? null);
                                        $cod = $st['status_codigo'];
                                        $pronto = !empty($rg['recompensa_disponivel']);
                                        $bloq = !$st['pode_participar'];
                                        $badgeBg = $pronto ? '#fef3c7' : ($bloq ? '#fee2e2' : '#d1fae5');
                                        $badgeCor = $pronto ? '#b45309' : ($bloq ? '#dc2626' : '#047857');
                                    ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($rg['nome_regra']); ?></strong>
                                                <?php if (empty($rg['ativo'])): ?>
                                                    <span class="crm-badge" style="background:#e2e8f0;color:#475569;">Inativa</span>
                                                <?php endif; ?>
                                                <div style="font-size:0.78rem;color:var(--text-muted);">
                                                    <?php echo htmlspecialchars($rg['tipo_mecanica']); ?>
                                                    <?php if (!empty($rg['item_alvo_nome'])): ?>
                                                        · Item: <?php echo htmlspecialchars($rg['item_alvo_nome']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span style="font-size:0.82rem;font-weight:600;">
                                                    <?php echo htmlspecialchars($labelsPoliticaRegra[$rg['politica_reentrada'] ?? 'reiniciar_apos_resgate'] ?? $rg['politica_reentrada']); ?>
                                                </span>
                                                <?php if (($rg['politica_reentrada'] ?? '') === 'cooldown_dias' && (int)$rg['cooldown_dias'] > 0): ?>
                                                    <div style="font-size:0.75rem;color:var(--text-muted);">Carência: <?php echo (int)$rg['cooldown_dias']; ?> dia(s)</div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($rg['condicao_tipo'] === 'quantidade_compras'): ?>
                                                    <strong><?php echo $prog; ?> / <?php echo $meta; ?></strong>
                                                    <div class="crm-progress-bar"><div class="crm-progress-fill" style="width:<?php echo $pct; ?>%;"></div></div>
                                                <?php else: ?>
                                                    <small style="color:var(--text-muted);">Regra Imediata</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?php echo $ciclos; ?></strong>
                                                <?php if ($limCiclos !== null): ?>
                                                    <span style="color:var(--text-muted);">/ <?php echo $limCiclos; ?> máx.</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="font-size:0.8rem;">
                                                <div>Compra: <?php echo $rg['ultimo_uso'] ? date('d/m/Y H:i', strtotime($rg['ultimo_uso'])) : '-'; ?></div>
                                                <div style="color:var(--text-muted);">Resgate: <?php echo $rg['ultimo_resgate_em'] ? date('d/m/Y H:i', strtotime($rg['ultimo_resgate_em'])) : '-'; ?></div>
                                            </td>
                                            <td>
                                                <span class="crm-badge" style="background:<?php echo $badgeBg; ?>;color:<?php echo $badgeCor; ?>;">
                                                    <?php echo htmlspecialchars($st['status_label']); ?>
                                                </span>
                                                <?php if (!empty($st['motivo_bloqueio'])): ?>
                                                    <div style="font-size:0.75rem;color:#ef4444;margin-top:3px;"><?php echo htmlspecialchars($st['motivo_bloqueio']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="no-print">
                                                <?php if ($pronto): ?>
                                                    <button type="button" class="btn btn-sm btn-resgatar-regra-rel" data-cliente="<?php echo (int)$cliente['id']; ?>" data-regra="<?php echo (int)$rg['id']; ?>"
                                                            style="background:#f59e0b;color:#111827;border:none;font-weight:700;border-radius:6px;padding:6px 10px;cursor:pointer;font-size:0.78rem;">
                                                        <i class="fas fa-gift"></i> Resgatar
                                                    </button>
                                                <?php else: ?>
                                                    <span style="color:var(--text-muted);font-size:0.78rem;">—</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p style="color:var(--text-muted);margin:0;">Nenhuma regra modular cadastrada na unidade.</p>
                    <?php endif; ?>
                </div>

                <!-- 2. HISTÓRICO DE PROMOÇÕES RELÂMPAGO UTILIZADAS -->
                <div class="crm-section-card">
                    <div class="crm-section-title">
                        <h3><i class="fas fa-bolt" style="color:#ec4899;"></i> Histórico de Promoções Relâmpago Aproveitadas</h3>
                        <small style="color:var(--text-muted);"><?php echo count($promosUsadas); ?> registro(s)</small>
                    </div>

                    <?php if (!empty($promosUsadas)): ?>
                        <div class="table-container" style="overflow-x:auto;">
                            <table class="data-table" style="width:100%;border-collapse:collapse;">
                                <thead>
                                    <tr>
                                        <th>Data/Hora</th>
                                        <th>Promoção</th>
                                        <th>Produto</th>
                                        <th>Política</th>
                                        <th>Qtd</th>
                                        <th>Desconto Aplicado</th>
                                        <th>Valor Final</th>
                                        <th>Venda</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($promosUsadas as $pu): ?>
                                        <tr>
                                            <td style="white-space:nowrap;"><?php echo date('d/m/Y H:i', strtotime($pu['data_uso'])); ?></td>
                                            <td><strong><?php echo htmlspecialchars($pu['titulo']); ?></strong> (<?php echo (float)$pu['desconto_percentual']; ?>%)</td>
                                            <td><?php echo htmlspecialchars($pu['nome_produto'] ?: 'Geral / Loja'); ?></td>
                                            <td>
                                                <span class="crm-badge" style="background:#f1f5f9;color:#334155;">
                                                    <?php echo htmlspecialchars($labelsPoliticaPromo[$pu['politica_reentrada'] ?? 'ilimitada'] ?? $pu['politica_reentrada']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo (int)$pu['quantidade_itens']; ?></td>
                                            <td style="color:#10b981;font-weight:700;">-R$ <?php echo number_format((float)$pu['desconto_aplicado'], 2, ',', '.'); ?></td>
                                            <td>R$ <?php echo number_format((float)$pu['valor_final_itens'], 2, ',', '.'); ?></td>
                                            <td><?php echo $pu['venda_id'] ? '#' . (int)$pu['venda_id'] : '-'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p style="color:var(--text-muted);margin:0;">Este cliente ainda não utilizou nenhuma promoção relâmpago no período filtrado.</p>
                    <?php endif; ?>
                </div>

                <!-- 3. EXTRATO CRONOLÓGICO COMPLETO DE FIDELIDADE -->
                <div class="crm-section-card">
                    <div class="crm-section-title">
                        <h3><i class="fas fa-history" style="color:#3b82f6;"></i> Extrato Cronológico de Fidelidade & Movimentações</h3>
                        <small style="color:var(--text-muted);"><?php echo count($transacoes); ?> evento(s)</small>
                    </div>

                    <?php if (!empty($transacoes)): ?>
                        <div class="table-container" style="overflow-x:auto;">
                            <table class="data-table" style="width:100%;border-collapse:collapse;">
                                <thead>
                                    <tr>
                                        <th>Data/Hora</th>
                                        <th>Tipo</th>
                                        <th>Origem (Regra / Promoção)</th>
                                        <th>Venda</th>
                                        <th>Qtd / Pts</th>
                                        <th>Valor / Benefício</th>
                                        <th>Descrição</th>
                                        <th>Operador</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transacoes as $tr):
                                        $tMeta = $labelsTipoTransacao[$tr['tipo']] ?? [$tr['tipo'], '#475569', '#f1f5f9'];
                                    ?>
                                        <tr>
                                            <td style="white-space:nowrap;"><?php echo date('d/m/Y H:i', strtotime($tr['data_transacao'])); ?></td>
                                            <td>
                                                <span class="crm-badge" style="background:<?php echo $tMeta[2]; ?>;color:<?php echo $tMeta[1]; ?>;">
                                                    <?php echo htmlspecialchars($tMeta[0]); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($tr['nome_regra'] ?: ($tr['promocao_titulo'] ?: 'Cartão Fidelidade Base')); ?></td>
                                            <td><?php echo $tr['venda_id'] ? '#' . (int)$tr['venda_id'] : '-'; ?></td>
                                            <td><?php echo (int)$tr['quantidade']; ?></td>
                                            <td><strong>R$ <?php echo number_format((float)$tr['valor_recompensa'], 2, ',', '.'); ?></strong></td>
                                            <td style="font-size:0.84rem;"><?php echo htmlspecialchars($tr['descricao']); ?></td>
                                            <td style="font-size:0.82rem;color:var(--text-muted);"><?php echo htmlspecialchars($tr['usuario_operador'] ?: '-'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p style="color:var(--text-muted);margin:0;">Nenhuma movimentação encontrada para os filtros selecionados.</p>
                    <?php endif; ?>
                </div>

                <!-- 4. HISTÓRICO DETALHADO DE COMPRAS NO PDV -->
                <div class="crm-section-card">
                    <div class="crm-section-title">
                        <h3><i class="fas fa-receipt" style="color:#10b981;"></i> Histórico de Compras no PDV (Itens & Descontos)</h3>
                        <small style="color:var(--text-muted);"><?php echo count($vendasCliente); ?> venda(s)</small>
                    </div>

                    <?php if (!empty($vendasCliente)): ?>
                        <?php foreach ($vendasCliente as $vd):
                            $itensVd = $itensPorVenda[(int)$vd['id']] ?? [];
                        ?>
                            <div class="crm-venda-card">
                                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:8px;">
                                    <div>
                                        <strong>Venda #<?php echo (int)$vd['id']; ?></strong>
                                        <span style="color:var(--text-muted);font-size:0.84rem;margin-left:8px;">
                                            <i class="far fa-calendar-alt"></i> <?php echo date('d/m/Y H:i', strtotime($vd['data_venda'])); ?>
                                            · <?php echo htmlspecialchars($vd['forma_pagamento'] ?: 'Balcão'); ?>
                                            <?php if (!empty($vd['loja_nome'])): ?>· <?php echo htmlspecialchars($vd['loja_nome']); ?><?php endif; ?>
                                        </span>
                                    </div>
                                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                                        <?php if ((float)$vd['desconto_promocao'] > 0): ?>
                                            <span class="crm-badge" style="background:#fce7f3;color:#be185d;">Promo -R$ <?php echo number_format((float)$vd['desconto_promocao'], 2, ',', '.'); ?></span>
                                        <?php endif; ?>
                                        <?php if ((float)$vd['desconto_fidelidade'] > 0): ?>
                                            <span class="crm-badge" style="background:#d1fae5;color:#047857;">Fidelidade -R$ <?php echo number_format((float)$vd['desconto_fidelidade'], 2, ',', '.'); ?></span>
                                        <?php endif; ?>
                                        <?php if ((float)$vd['cashback_utilizado'] > 0): ?>
                                            <span class="crm-badge" style="background:#ede9fe;color:#6d28d9;">Usou Cashback -R$ <?php echo number_format((float)$vd['cashback_utilizado'], 2, ',', '.'); ?></span>
                                        <?php endif; ?>
                                        <?php if ((float)$vd['cashback_gerado'] > 0): ?>
                                            <span class="crm-badge" style="background:#ccfbf1;color:#0f766e;">+R$ <?php echo number_format((float)$vd['cashback_gerado'], 2, ',', '.'); ?> cashback</span>
                                        <?php endif; ?>
                                        <?php if ((int)$vd['pontos_gerados'] > 0): ?>
                                            <span class="crm-badge" style="background:#fef3c7;color:#b45309;">+<?php echo (int)$vd['pontos_gerados']; ?> pts</span>
                                        <?php endif; ?>
                                        <strong style="font-size:1.02rem;color:#10b981;">Pago: R$ <?php echo number_format((float)$vd['valor_total'], 2, ',', '.'); ?></strong>
                                    </div>
                                </div>
                                <?php if (!empty($itensVd)): ?>
                                    <div style="display:flex;flex-wrap:wrap;gap:8px;font-size:0.82rem;color:var(--text-muted);">
                                        <?php foreach ($itensVd as $it): ?>
                                            <span style="background:var(--bg-widget);border:1px solid var(--border-color);padding:4px 10px;border-radius:8px;">
                                                <?php if (!empty($it['servico_id'])): ?>
                                                    <i class="fas fa-cut" style="color:#a855f7;"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-box" style="color:#3b82f6;"></i>
                                                <?php endif; ?>
                                                <?php echo (int)$it['quantidade']; ?>x <?php echo htmlspecialchars($it['nome_produto']); ?>
                                                <strong>(R$ <?php echo number_format((float)$it['subtotal'], 2, ',', '.'); ?>)</strong>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="color:var(--text-muted);margin:0;">Nenhuma compra registrada no PDV para este cliente no período.</p>
                    <?php endif; ?>
                </div>

                <!-- 5. ESTEIRA NUTRICIONAL DE RAÇÃO & AGENDAMENTOS -->
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:18px;">
                    <div class="crm-section-card">
                        <div class="crm-section-title">
                            <h3><i class="fas fa-bone" style="color:#d97706;"></i> Esteira Preditiva de Ração</h3>
                            <small style="color:var(--text-muted);"><?php echo count($oportunidades); ?> registro(s)</small>
                        </div>
                        <?php if (!empty($oportunidades)): ?>
                            <div class="table-container" style="overflow-x:auto;">
                                <table class="data-table" style="width:100%;border-collapse:collapse;">
                                    <thead>
                                        <tr>
                                            <th>Pet / Ração</th>
                                            <th>Compra</th>
                                            <th>Previsão Fim</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($oportunidades as $op): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($op['pet_nome']); ?></strong>
                                                    <div style="font-size:0.78rem;color:var(--text-muted);"><?php echo htmlspecialchars($op['nome_produto']); ?></div>
                                                </td>
                                                <td><?php echo date('d/m/Y', strtotime($op['data_compra'])); ?></td>
                                                <td><?php echo $op['data_fim_prevista']; ?> <small>(<?php echo (int)$op['dias_duracao']; ?>d)</small></td>
                                                <td>
                                                    <span class="crm-badge" style="background:<?php echo $op['status'] === 'Pendente' ? '#fef3c7' : '#d1fae5'; ?>;color:<?php echo $op['status'] === 'Pendente' ? '#b45309' : '#047857'; ?>;">
                                                        <?php echo htmlspecialchars($op['status']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p style="color:var(--text-muted);margin:0;">Nenhum ciclo nutricional registrado.</p>
                        <?php endif; ?>
                    </div>

                    <div class="crm-section-card">
                        <div class="crm-section-title">
                            <h3><i class="fas fa-calendar-check" style="color:#8b5cf6;"></i> Histórico de Agendamentos</h3>
                            <small style="color:var(--text-muted);"><?php echo count($agendamentos); ?> atendimento(s)</small>
                        </div>
                        <?php if (!empty($agendamentos)): ?>
                            <div class="table-container" style="overflow-x:auto;">
                                <table class="data-table" style="width:100%;border-collapse:collapse;">
                                    <thead>
                                        <tr>
                                            <th>Data/Hora</th>
                                            <th>Pet / Serviço</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($agendamentos as $ag): ?>
                                            <tr>
                                                <td style="white-space:nowrap;"><?php echo date('d/m/Y H:i', strtotime($ag['data_hora_inicio'])); ?></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($ag['pet_nome'] ?: 'Pet'); ?></strong>
                                                    <div style="font-size:0.78rem;color:var(--text-muted);"><?php echo htmlspecialchars($ag['servico']); ?></div>
                                                </td>
                                                <td>
                                                    <span class="crm-badge" style="background:#e0f2fe;color:#0369a1;"><?php echo htmlspecialchars($ag['status']); ?></span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p style="color:var(--text-muted);margin:0;">Nenhum agendamento encontrado para este cliente.</p>
                        <?php endif; ?>
                    </div>
                </div>

            <?php else: ?>
                <!-- PANORAMA GERAL DE CLIENTES NO CRM (ANTES DE SELECIONAR UM CLIENTE ESPECÍFICO) -->
                <?php include __DIR__ . '/views/relatorio_crm_panorama_view.php'; ?>
            <?php endif; ?>

        </main>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selCli = document.getElementById('relatorio-crm-cliente-select');
    if (selCli && typeof window.jQuery !== 'undefined' && typeof window.jQuery.fn.select2 === 'function') {
        const $sel = window.jQuery(selCli);
        $sel.select2({
            language: 'pt-BR',
            width: '100%',
            placeholder: 'Pesquise cliente por nome, telefone, CPF ou nome do pet...',
            allowClear: true,
            minimumInputLength: 0,
            ajax: {
                url: 'api_busca_global.php',
                dataType: 'json',
                delay: 200,
                data: params => ({ tipo: 'clientes', q: params.term || '' }),
                processResults: data => {
                    const baseOpt = [{ id: '', text: 'Panorama Geral da Loja (Todos os Clientes)' }];
                    return { results: baseOpt.concat(data.results || []) };
                },
                cache: true
            }
        });
        $sel.on('select2:select select2:clear', function() {
            document.getElementById('form-relatorio-crm-filtro')?.submit();
        });
    }

    document.querySelectorAll('.btn-resgatar-regra-rel').forEach(btn => {
        btn.addEventListener('click', function() {
            if (!confirm('Confirmar resgate desta recompensa modular e aplicar política de reentrada?')) return;
            const cli = this.dataset.cliente;
            const reg = this.dataset.regra;
            this.disabled = true;
            this.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            fetch('api_fidelidade.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `acao=resgatar_regra_modular&cliente_id=${encodeURIComponent(cli)}&regra_id=${encodeURIComponent(reg)}&instituicao_id=<?php echo (int)$id_loja; ?>`
            })
            .then(r => r.json())
            .then(res => {
                alert(res.message);
                if (res.success) location.reload();
                else {
                    this.disabled = false;
                    this.innerHTML = '<i class="fas fa-gift"></i> Resgatar';
                }
            })
            .catch(() => {
                alert('Erro de comunicação com o servidor.');
                this.disabled = false;
                this.innerHTML = '<i class="fas fa-gift"></i> Resgatar';
            });
        });
    });
});
</script>

<?php include 'includes/_footer.php'; ?>

