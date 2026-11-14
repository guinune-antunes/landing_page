<?php
/**
 * Helpers analíticos do Panorama Geral no Relatório CRM 360° (sys/relatorio_crm.php).
 * Cruza promoções ativas com vendas gerais e apura clientes em 1ª compra & novos cadastros.
 */

if (!function_exists('crmFormatarWhatsappUrl')) {
    function crmFormatarWhatsappUrl(?string $telefone, string $mensagem): string
    {
        $tel = preg_replace('/\D/', '', (string)$telefone);
        if ($tel === '') {
            return '';
        }
        if (strlen($tel) <= 11 && substr($tel, 0, 2) !== '55') {
            $tel = '55' . $tel;
        }
        return 'https://api.whatsapp.com/send?phone=' . $tel . '&text=' . rawurlencode($mensagem);
    }
}

if (!function_exists('crmPanoramaResolverPeriodo')) {
    /**
     * Resolve e normaliza o intervalo de datas para o Panorama Geral do CRM.
     * Quando data_inicio e data_fim estão vazios, assume os últimos 30 dias.
     */
    function crmPanoramaResolverPeriodo(string $dataInicio, string $dataFim): array
    {
        $reData = '/^\d{4}-\d{2}-\d{2}$/';
        $ini = preg_match($reData, $dataInicio) ? $dataInicio : '';
        $fim = preg_match($reData, $dataFim) ? $dataFim : '';
        $isDefault = ($ini === '' && $fim === '');

        if ($isDefault) {
            $fim = date('Y-m-d');
            $ini = date('Y-m-d', strtotime('-29 days'));
        } elseif ($ini !== '' && $fim === '') {
            $fim = date('Y-m-d');
        } elseif ($ini === '' && $fim !== '') {
            $ini = date('Y-m-d', strtotime($fim . ' -29 days'));
        }

        if ($ini > $fim) {
            [$ini, $fim] = [$fim, $ini];
        }

        $dtIni = new DateTime($ini);
        $dtFim = new DateTime($fim);
        $dias = (int)$dtIni->diff($dtFim)->days + 1;
        $granularity = ($dias > 90) ? 'month' : 'day';

        return [
            'inicio' => $ini,
            'fim' => $fim,
            'inicio_dt' => $ini . ' 00:00:00',
            'fim_dt' => $fim . ' 23:59:59',
            'dias' => $dias,
            'granularity' => $granularity,
            'is_default' => $isDefault,
        ];
    }
}

if (!function_exists('crmPanoramaCarregarDados')) {
    /**
     * Carrega métricas cruzadas de Promoções Ativas vs Vendas Gerais,
     * série temporal e coortes de 1ª compra + novos cadastros.
     */
    function crmPanoramaCarregarDados(
        PDO $pdo,
        int $idLoja,
        int $idLojaFid,
        array $idsClientes,
        array $periodo
    ): array {
        $idsCli = array_values(array_unique(array_filter(array_map('intval', $idsClientes), static fn($v) => $v > 0)));
        if (empty($idsCli)) {
            $idsCli = [$idLoja];
        }
        $inCli = implode(',', $idsCli);
        $iniDt = $periodo['inicio_dt'];
        $fimDt = $periodo['fim_dt'];

        // 1. Vendas Gerais da unidade no período vs Vendas com Promoção Ativa/Aplicada
        $sqlVg = "SELECT COUNT(*) AS qtd_vendas,
                         COUNT(DISTINCT v.cliente_id) AS clientes_compradores,
                         COALESCE(SUM(v.valor_total), 0) AS fat_geral,
                         COALESCE(SUM(v.desconto_promocao), 0) AS desc_promo_total,
                         COUNT(CASE WHEN pu_agg.venda_id IS NOT NULL OR v.desconto_promocao > 0 THEN 1 END) AS qtd_com_promo,
                         COALESCE(SUM(CASE WHEN pu_agg.venda_id IS NOT NULL OR v.desconto_promocao > 0 THEN v.valor_total ELSE 0 END), 0) AS fat_com_promo
                  FROM vendas v
                  LEFT JOIN (
                      SELECT DISTINCT venda_id
                      FROM promocao_usos
                      WHERE instituicao_id = ? AND data_uso >= ? AND data_uso <= ?
                  ) pu_agg ON pu_agg.venda_id = v.id
                  WHERE v.instituicao_id = ? AND v.data_venda >= ? AND v.data_venda <= ?";
        $stVg = $pdo->prepare($sqlVg);
        $stVg->execute([$idLoja, $iniDt, $fimDt, $idLoja, $iniDt, $fimDt]);
        $vgRaw = $stVg->fetch(PDO::FETCH_ASSOC) ?: [];

        $qtdVendas = (int)($vgRaw['qtd_vendas'] ?? 0);
        $fatGeral = (float)($vgRaw['fat_geral'] ?? 0);
        $qtdComPromo = (int)($vgRaw['qtd_com_promo'] ?? 0);
        $fatComPromo = (float)($vgRaw['fat_com_promo'] ?? 0);
        $descPromoTotal = (float)($vgRaw['desc_promo_total'] ?? 0);
        $qtdSemPromo = max(0, $qtdVendas - $qtdComPromo);
        $fatSemPromo = max(0.0, $fatGeral - $fatComPromo);

        $vendasGerais = [
            'qtd_vendas' => $qtdVendas,
            'clientes_compradores' => (int)($vgRaw['clientes_compradores'] ?? 0),
            'fat_geral' => $fatGeral,
            'desc_promo_total' => $descPromoTotal,
            'qtd_com_promo' => $qtdComPromo,
            'fat_com_promo' => $fatComPromo,
            'qtd_sem_promo' => $qtdSemPromo,
            'fat_sem_promo' => $fatSemPromo,
            'ticket_medio_geral' => $qtdVendas > 0 ? ($fatGeral / $qtdVendas) : 0.0,
            'ticket_medio_promo' => $qtdComPromo > 0 ? ($fatComPromo / $qtdComPromo) : 0.0,
            'ticket_medio_sem_promo' => $qtdSemPromo > 0 ? ($fatSemPromo / $qtdSemPromo) : 0.0,
            'share_promo_pct' => $fatGeral > 0 ? (($fatComPromo / $fatGeral) * 100.0) : 0.0,
            'share_qtd_promo_pct' => $qtdVendas > 0 ? (($qtdComPromo / $qtdVendas) * 100.0) : 0.0,
        ];

        // 2. Desempenho de cada Promoção Ativa cruzada com Vendas Gerais
        $sqlPromos = "SELECT p.id, p.titulo, p.desconto_percentual, p.politica_reentrada,
                             p.cooldown_dias, p.limite_usos_cliente, p.limite_usos_global,
                             p.data_inicio, p.data_fim, pr.nome_produto,
                             COUNT(pu.id) AS qtd_usos,
                             COUNT(DISTINCT pu.cliente_id) AS clientes_unicos,
                             COUNT(DISTINCT pu.venda_id) AS vendas_com_promo,
                             COALESCE(SUM(pu.quantidade_itens), 0) AS itens_vendidos,
                             COALESCE(SUM(pu.valor_final_itens), 0) AS receita_itens_promo,
                             COALESCE(SUM(pu.desconto_aplicado), 0) AS desconto_concedido,
                             COALESCE(SUM(v.valor_total), 0) AS faturamento_vendas_promo
                      FROM promocoes p
                      LEFT JOIN produtos pr ON pr.id = p.produto_id
                      LEFT JOIN promocao_usos pu
                             ON pu.promocao_id = p.id
                            AND pu.instituicao_id = ?
                            AND pu.data_uso >= ? AND pu.data_uso <= ?
                      LEFT JOIN vendas v ON v.id = pu.venda_id
                      WHERE p.instituicao_id = ? AND p.ativo = 1
                      GROUP BY p.id
                      ORDER BY faturamento_vendas_promo DESC, qtd_usos DESC, p.id DESC";
        $stPromos = $pdo->prepare($sqlPromos);
        $stPromos->execute([$idLoja, $iniDt, $fimDt, $idLoja]);
        $promocoesAtivas = [];
        foreach ($stPromos->fetchAll(PDO::FETCH_ASSOC) as $prRow) {
            $fatVendasP = (float)$prRow['faturamento_vendas_promo'];
            $descConcedido = (float)$prRow['desconto_concedido'];
            $vendasPromo = (int)$prRow['vendas_com_promo'];
            $prRow['qtd_usos'] = (int)$prRow['qtd_usos'];
            $prRow['clientes_unicos'] = (int)$prRow['clientes_unicos'];
            $prRow['vendas_com_promo'] = $vendasPromo;
            $prRow['itens_vendidos'] = (int)$prRow['itens_vendidos'];
            $prRow['receita_itens_promo'] = (float)$prRow['receita_itens_promo'];
            $prRow['desconto_concedido'] = $descConcedido;
            $prRow['faturamento_vendas_promo'] = $fatVendasP;
            $prRow['ticket_medio_venda'] = $vendasPromo > 0 ? ($fatVendasP / $vendasPromo) : 0.0;
            $prRow['share_vendas_geral_pct'] = $fatGeral > 0 ? (($fatVendasP / $fatGeral) * 100.0) : 0.0;
            $prRow['share_qtd_vendas_pct'] = $qtdVendas > 0 ? (($vendasPromo / $qtdVendas) * 100.0) : 0.0;
            $prRow['roi_desconto'] = $descConcedido > 0 ? ($fatVendasP / $descConcedido) : 0.0;
            $promocoesAtivas[] = $prRow;
        }

        // 3. Resumo & Listas de Aquisição: Clientes em 1ª Compra & Novos Cadastros
        $sqlResPri = "SELECT COUNT(*) AS total_primeira_compra,
                             SUM(CASE WHEN c.data_cadastro >= ? AND c.data_cadastro <= ? THEN 1 ELSE 0 END) AS cad_periodo_e_1a,
                             SUM(CASE WHEN DATE(c.data_cadastro) = DATE(fv.primeira_compra) THEN 1 ELSE 0 END) AS cad_mesmo_dia_1a,
                             SUM(CASE WHEN fv.qtd_vendas = 1 THEN 1 ELSE 0 END) AS somente_uma_compra,
                             SUM(CASE WHEN fv.qtd_vendas >= 2 THEN 1 ELSE 0 END) AS recorrentes_pos_1a,
                             SUM(CASE WHEN fv.usou_promo_qtd > 0 THEN 1 ELSE 0 END) AS com_promo_1a,
                             COALESCE(SUM(fv.primeira_venda_valor), 0) AS soma_valor_primeiras
                      FROM (
                          SELECT v.cliente_id,
                                 MIN(v.data_venda) AS primeira_compra,
                                 COUNT(*) AS qtd_vendas,
                                 SUM(CASE WHEN v.desconto_promocao > 0 THEN 1 ELSE 0 END) AS usou_promo_qtd,
                                 CAST(SUBSTRING_INDEX(GROUP_CONCAT(v.valor_total ORDER BY v.data_venda ASC, v.id ASC SEPARATOR '|'), '|', 1) AS DECIMAL(10,2)) AS primeira_venda_valor
                          FROM vendas v
                          WHERE v.instituicao_id = ? AND v.cliente_id IS NOT NULL
                          GROUP BY v.cliente_id
                          HAVING primeira_compra >= ? AND primeira_compra <= ?
                      ) fv
                      JOIN clientes c ON c.id = fv.cliente_id
                      WHERE c.instituicao_id IN ({$inCli})";
        $stResPri = $pdo->prepare($sqlResPri);
        $stResPri->execute([$iniDt, $fimDt, $idLoja, $iniDt, $fimDt]);
        $resPriRaw = $stResPri->fetch(PDO::FETCH_ASSOC) ?: [];

        $sqlResCad = "SELECT COUNT(*) AS total_novos_cadastros,
                             SUM(CASE WHEN EXISTS (
                                 SELECT 1 FROM vendas v WHERE v.cliente_id = c.id AND v.instituicao_id IN ({$inCli})
                             ) THEN 1 ELSE 0 END) AS cadastros_que_compraram
                      FROM clientes c
                      WHERE c.instituicao_id IN ({$inCli})
                        AND c.data_cadastro >= ? AND c.data_cadastro <= ?";
        $stResCad = $pdo->prepare($sqlResCad);
        $stResCad->execute([$iniDt, $fimDt]);
        $resCadRaw = $stResCad->fetch(PDO::FETCH_ASSOC) ?: [];

        $tot1a = (int)($resPriRaw['total_primeira_compra'] ?? 0);
        $cadE1a = (int)($resPriRaw['cad_periodo_e_1a'] ?? 0);
        $soma1a = (float)($resPriRaw['soma_valor_primeiras'] ?? 0);
        $totNovosCad = (int)($resCadRaw['total_novos_cadastros'] ?? 0);
        $cadCompraram = (int)($resCadRaw['cadastros_que_compraram'] ?? 0);

        $aquisicaoResumo = [
            'total_primeira_compra' => $tot1a,
            'cad_periodo_e_primeira_compra' => $cadE1a,
            'cad_anterior_e_primeira_compra' => max(0, $tot1a - $cadE1a),
            'cad_mesmo_dia_1a' => (int)($resPriRaw['cad_mesmo_dia_1a'] ?? 0),
            'somente_uma_compra' => (int)($resPriRaw['somente_uma_compra'] ?? 0),
            'recorrentes_pos_primeira' => (int)($resPriRaw['recorrentes_pos_1a'] ?? 0),
            'com_promo_na_primeira' => (int)($resPriRaw['com_promo_1a'] ?? 0),
            'faturamento_primeiras_compras' => $soma1a,
            'ticket_medio_primeira' => $tot1a > 0 ? ($soma1a / $tot1a) : 0.0,
            'total_novos_cadastros' => $totNovosCad,
            'cadastros_que_compraram' => $cadCompraram,
            'cadastros_sem_compra' => max(0, $totNovosCad - $cadCompraram),
            'taxa_conversao_cad_pct' => $totNovosCad > 0 ? (($cadCompraram / $totNovosCad) * 100.0) : 0.0,
        ];

        // Lista top 40 clientes na 1ª compra (prioriza quem fez cadastro no período + mais recentes)
        $sqlListaPri = "SELECT c.id, c.nome_completo, c.telefone, c.data_cadastro, c.instituicao_id,
                               COALESCE(NULLIF(i.nome_unidade, ''), i.nome_fantasia) AS unidade_nome,
                               fv.primeira_compra, fv.ultima_compra, fv.qtd_vendas, fv.total_gasto,
                               fv.primeira_venda_valor, fv.usou_promo_qtd,
                               COALESCE(cf.tier_atual, 'Bronze') AS tier_atual,
                               COALESCE(cf.saldo_cashback, 0) AS saldo_cashback,
                               CASE WHEN c.data_cadastro >= ? AND c.data_cadastro <= ? THEN 1 ELSE 0 END AS cadastro_no_periodo,
                               (SELECT GROUP_CONCAT(p.nome SEPARATOR ', ') FROM pets p WHERE p.cliente_id = c.id) AS pets_nomes
                        FROM (
                            SELECT v.cliente_id,
                                   MIN(v.data_venda) AS primeira_compra,
                                   MAX(v.data_venda) AS ultima_compra,
                                   COUNT(*) AS qtd_vendas,
                                   SUM(v.valor_total) AS total_gasto,
                                   SUM(CASE WHEN v.desconto_promocao > 0 THEN 1 ELSE 0 END) AS usou_promo_qtd,
                                   CAST(SUBSTRING_INDEX(GROUP_CONCAT(v.valor_total ORDER BY v.data_venda ASC, v.id ASC SEPARATOR '|'), '|', 1) AS DECIMAL(10,2)) AS primeira_venda_valor
                            FROM vendas v
                            WHERE v.instituicao_id = ? AND v.cliente_id IS NOT NULL
                            GROUP BY v.cliente_id
                            HAVING primeira_compra >= ? AND primeira_compra <= ?
                        ) fv
                        JOIN clientes c ON c.id = fv.cliente_id
                        LEFT JOIN instituicoes i ON i.id = c.instituicao_id
                        LEFT JOIN cliente_fidelidade cf ON cf.cliente_id = c.id AND cf.instituicao_id = ?
                        WHERE c.instituicao_id IN ({$inCli})
                        ORDER BY cadastro_no_periodo DESC, fv.primeira_compra DESC
                        LIMIT 40";
        $stListaPri = $pdo->prepare($sqlListaPri);
        $stListaPri->execute([$iniDt, $fimDt, $idLoja, $iniDt, $fimDt, $idLojaFid]);
        $primeirasComprasLista = $stListaPri->fetchAll(PDO::FETCH_ASSOC);

        // Lista top 40 novos cadastros no período (prioriza quem já comprou para visibilidade imediata + mais recentes)
        $sqlListaCad = "SELECT c.id, c.nome_completo, c.telefone, c.data_cadastro, c.instituicao_id,
                               COALESCE(NULLIF(i.nome_unidade, ''), i.nome_fantasia) AS unidade_nome,
                               COALESCE(vc.qtd_vendas, 0) AS qtd_vendas,
                               vc.primeira_compra,
                               COALESCE(vc.primeira_venda_valor, 0) AS primeira_venda_valor,
                               COALESCE(vc.total_gasto, 0) AS total_gasto,
                               COALESCE(vc.usou_promo_qtd, 0) AS usou_promo_qtd,
                               COALESCE(cf.tier_atual, 'Bronze') AS tier_atual,
                               COALESCE(cf.saldo_cashback, 0) AS saldo_cashback,
                               (SELECT GROUP_CONCAT(p.nome SEPARATOR ', ') FROM pets p WHERE p.cliente_id = c.id) AS pets_nomes
                        FROM clientes c
                        LEFT JOIN instituicoes i ON i.id = c.instituicao_id
                        LEFT JOIN cliente_fidelidade cf ON cf.cliente_id = c.id AND cf.instituicao_id = ?
                        LEFT JOIN (
                            SELECT v.cliente_id,
                                   COUNT(*) AS qtd_vendas,
                                   MIN(v.data_venda) AS primeira_compra,
                                   SUM(v.valor_total) AS total_gasto,
                                   SUM(CASE WHEN v.desconto_promocao > 0 THEN 1 ELSE 0 END) AS usou_promo_qtd,
                                   CAST(SUBSTRING_INDEX(GROUP_CONCAT(v.valor_total ORDER BY v.data_venda ASC, v.id ASC SEPARATOR '|'), '|', 1) AS DECIMAL(10,2)) AS primeira_venda_valor
                            FROM vendas v
                            WHERE v.instituicao_id IN ({$inCli}) AND v.cliente_id IS NOT NULL
                            GROUP BY v.cliente_id
                        ) vc ON vc.cliente_id = c.id
                        WHERE c.instituicao_id IN ({$inCli})
                          AND c.data_cadastro >= ? AND c.data_cadastro <= ?
                        ORDER BY (CASE WHEN COALESCE(vc.qtd_vendas, 0) > 0 THEN 0 ELSE 1 END) ASC, c.data_cadastro DESC
                        LIMIT 40";
        $stListaCad = $pdo->prepare($sqlListaCad);
        $stListaCad->execute([$idLojaFid, $iniDt, $fimDt]);
        $novosCadastrosLista = $stListaCad->fetchAll(PDO::FETCH_ASSOC);

        // 4. Série Temporal (diária até 90 dias, mensal acima de 90 dias)
        $isMensal = ($periodo['granularity'] === 'month');
        $fmtSqlV = $isMensal ? "DATE_FORMAT(v.data_venda, '%Y-%m')" : "DATE(v.data_venda)";
        $fmtSqlPri = $isMensal ? "DATE_FORMAT(fv.primeira_compra, '%Y-%m')" : "DATE(fv.primeira_compra)";
        $fmtSqlCad = $isMensal ? "DATE_FORMAT(c.data_cadastro, '%Y-%m')" : "DATE(c.data_cadastro)";

        $sqlSerV = "SELECT {$fmtSqlV} AS bucket,
                           COUNT(*) AS qtd_vendas,
                           COALESCE(SUM(v.valor_total), 0) AS fat_geral,
                           COALESCE(SUM(CASE WHEN pu_agg.venda_id IS NOT NULL OR v.desconto_promocao > 0 THEN v.valor_total ELSE 0 END), 0) AS fat_promo
                    FROM vendas v
                    LEFT JOIN (
                        SELECT DISTINCT venda_id
                        FROM promocao_usos
                        WHERE instituicao_id = ? AND data_uso >= ? AND data_uso <= ?
                    ) pu_agg ON pu_agg.venda_id = v.id
                    WHERE v.instituicao_id = ? AND v.data_venda >= ? AND v.data_venda <= ?
                    GROUP BY bucket
                    ORDER BY bucket ASC";
        $stSerV = $pdo->prepare($sqlSerV);
        $stSerV->execute([$idLoja, $iniDt, $fimDt, $idLoja, $iniDt, $fimDt]);
        $mapV = [];
        foreach ($stSerV->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mapV[(string)$r['bucket']] = [
                'qtd' => (int)$r['qtd_vendas'],
                'fat_geral' => round((float)$r['fat_geral'], 2),
                'fat_promo' => round((float)$r['fat_promo'], 2),
            ];
        }

        $sqlSerPri = "SELECT {$fmtSqlPri} AS bucket, COUNT(*) AS qtd_1a
                      FROM (
                          SELECT cliente_id, MIN(data_venda) AS primeira_compra
                          FROM vendas
                          WHERE instituicao_id = ? AND cliente_id IS NOT NULL
                          GROUP BY cliente_id
                          HAVING primeira_compra >= ? AND primeira_compra <= ?
                      ) fv
                      GROUP BY bucket";
        $stSerPri = $pdo->prepare($sqlSerPri);
        $stSerPri->execute([$idLoja, $iniDt, $fimDt]);
        $mapPri = [];
        foreach ($stSerPri->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mapPri[(string)$r['bucket']] = (int)$r['qtd_1a'];
        }

        $sqlSerCad = "SELECT {$fmtSqlCad} AS bucket, COUNT(*) AS qtd_cad
                      FROM clientes c
                      WHERE c.instituicao_id IN ({$inCli})
                        AND c.data_cadastro >= ? AND c.data_cadastro <= ?
                      GROUP BY bucket";
        $stSerCad = $pdo->prepare($sqlSerCad);
        $stSerCad->execute([$iniDt, $fimDt]);
        $mapCad = [];
        foreach ($stSerCad->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mapCad[(string)$r['bucket']] = (int)$r['qtd_cad'];
        }

        $labels = [];
        $serieFatGeral = [];
        $serieFatPromo = [];
        $serieQtd1a = [];
        $serieQtdCad = [];

        foreach (crmPanoramaGerarEixoTemporal($periodo) as $slot) {
            $bk = $slot['key'];
            $labels[] = $slot['label'];
            $serieFatGeral[] = $mapV[$bk]['fat_geral'] ?? 0.0;
            $serieFatPromo[] = $mapV[$bk]['fat_promo'] ?? 0.0;
            $serieQtd1a[] = $mapPri[$bk] ?? 0;
            $serieQtdCad[] = $mapCad[$bk] ?? 0;
        }

        return [
            'periodo' => $periodo,
            'vendas_gerais' => $vendasGerais,
            'promocoes_ativas' => $promocoesAtivas,
            'aquisicao_resumo' => $aquisicaoResumo,
            'primeiras_compras_lista' => $primeirasComprasLista,
            'novos_cadastros_lista' => $novosCadastrosLista,
            'serie_temporal' => [
                'labels' => $labels,
                'fat_geral' => $serieFatGeral,
                'fat_promo' => $serieFatPromo,
                'qtd_primeira_compra' => $serieQtd1a,
                'qtd_novos_cadastros' => $serieQtdCad,
            ],
        ];
    }
}

if (!function_exists('crmPanoramaGerarEixoTemporal')) {
    /**
     * Gera sequência contínua de buckets (diários ou mensais) para gráficos temporais.
     */
    function crmPanoramaGerarEixoTemporal(array $periodo): array
    {
        $slots = [];
        if (($periodo['granularity'] ?? 'day') === 'month') {
            $cur = new DateTime(substr($periodo['inicio'], 0, 7) . '-01');
            $end = new DateTime(substr($periodo['fim'], 0, 7) . '-01');
            while ($cur <= $end) {
                $slots[] = ['key' => $cur->format('Y-m'), 'label' => $cur->format('m/Y')];
                $cur->modify('+1 month');
            }
        } else {
            $cur = new DateTime($periodo['inicio']);
            $end = new DateTime($periodo['fim']);
            while ($cur <= $end) {
                $slots[] = ['key' => $cur->format('Y-m-d'), 'label' => $cur->format('d/m')];
                $cur->modify('+1 day');
            }
        }
        return $slots;
    }
}

if (!function_exists('crmPanoramaDetalharPromocao')) {
    /**
     * Carrega estatísticas individuais completas de uma promoção ativa (cruzada com vendas gerais,
     * cross-sell, formas de pagamento, série temporal, reentrada e lista de usos).
     */
    function crmPanoramaDetalharPromocao(
        PDO $pdo,
        int $idLoja,
        int $promocaoId,
        array $periodo,
        array $labelsPoliticaPromo = []
    ): ?array {
        $stPromo = $pdo->prepare("SELECT p.*, pr.nome_produto, pr.preco_venda AS produto_preco_atual
                                  FROM promocoes p
                                  LEFT JOIN produtos pr ON pr.id = p.produto_id
                                  WHERE p.id = ? AND p.instituicao_id = ?
                                  LIMIT 1");
        $stPromo->execute([$promocaoId, $idLoja]);
        $promo = $stPromo->fetch(PDO::FETCH_ASSOC);
        if (!$promo) {
            return null;
        }

        $iniDt = $periodo['inicio_dt'];
        $fimDt = $periodo['fim_dt'];

        $stVg = $pdo->prepare("SELECT COUNT(*) AS qtd_vendas, COALESCE(SUM(valor_total), 0) AS fat_geral
                               FROM vendas
                               WHERE instituicao_id = ? AND data_venda >= ? AND data_venda <= ?");
        $stVg->execute([$idLoja, $iniDt, $fimDt]);
        $vg = $stVg->fetch(PDO::FETCH_ASSOC) ?: ['qtd_vendas' => 0, 'fat_geral' => 0];
        $qtdVendasGeral = (int)$vg['qtd_vendas'];
        $fatGeral = (float)$vg['fat_geral'];
        $ticketGeral = $qtdVendasGeral > 0 ? ($fatGeral / $qtdVendasGeral) : 0.0;

        $stTotHist = $pdo->prepare("SELECT COUNT(*) FROM promocao_usos WHERE promocao_id = ? AND instituicao_id = ?");
        $stTotHist->execute([$promocaoId, $idLoja]);
        $usosCampanhaTotal = (int)$stTotHist->fetchColumn();

        $stAgg = $pdo->prepare("SELECT COUNT(pu.id) AS qtd_usos,
                                       COUNT(DISTINCT pu.cliente_id) AS clientes_unicos,
                                       COUNT(DISTINCT pu.venda_id) AS vendas_com_promo,
                                       COALESCE(SUM(pu.quantidade_itens), 0) AS itens_vendidos,
                                       COALESCE(SUM(pu.valor_final_itens), 0) AS receita_itens_promo,
                                       COALESCE(SUM(pu.desconto_aplicado), 0) AS desconto_concedido,
                                       COALESCE(SUM(v.valor_total), 0) AS faturamento_vendas_promo
                                FROM promocao_usos pu
                                LEFT JOIN vendas v ON v.id = pu.venda_id
                                WHERE pu.promocao_id = ? AND pu.instituicao_id = ?
                                  AND pu.data_uso >= ? AND pu.data_uso <= ?");
        $stAgg->execute([$promocaoId, $idLoja, $iniDt, $fimDt]);
        $agg = $stAgg->fetch(PDO::FETCH_ASSOC) ?: [];

        $qtdUsos = (int)($agg['qtd_usos'] ?? 0);
        $vendasPromo = (int)($agg['vendas_com_promo'] ?? 0);
        $recItens = (float)($agg['receita_itens_promo'] ?? 0);
        $descConcedido = (float)($agg['desconto_concedido'] ?? 0);
        $fatVendas = (float)($agg['faturamento_vendas_promo'] ?? 0);
        $crossSellTotal = max(0.0, $fatVendas - $recItens);
        $ticketPromo = $vendasPromo > 0 ? ($fatVendas / $vendasPromo) : 0.0;
        $varTicketPct = ($ticketGeral > 0 && $vendasPromo > 0) ? ((($ticketPromo / $ticketGeral) - 1.0) * 100.0) : 0.0;

        $stPerfCli = $pdo->prepare("SELECT COUNT(*) AS cli_unicos_com_id,
                                           SUM(CASE WHEN u.usos_no_periodo >= 2 THEN 1 ELSE 0 END) AS clientes_multi_uso,
                                           SUM(CASE WHEN c.data_cadastro >= ? AND c.data_cadastro <= ? THEN 1 ELSE 0 END) AS clientes_novos_cadastros,
                                           SUM(CASE WHEN fv.primeira_venda_id IS NOT NULL AND u.usou_na_primeira = 1 THEN 1 ELSE 0 END) AS clientes_primeira_compra
                                    FROM (
                                        SELECT pu.cliente_id,
                                               COUNT(*) AS usos_no_periodo,
                                               MAX(CASE WHEN pu.venda_id = fv_inner.primeira_venda_id THEN 1 ELSE 0 END) AS usou_na_primeira
                                        FROM promocao_usos pu
                                        LEFT JOIN (
                                            SELECT cliente_id,
                                                   CAST(SUBSTRING_INDEX(GROUP_CONCAT(id ORDER BY data_venda ASC, id ASC), ',', 1) AS UNSIGNED) AS primeira_venda_id
                                            FROM vendas
                                            WHERE instituicao_id = ? AND cliente_id IS NOT NULL
                                            GROUP BY cliente_id
                                        ) fv_inner ON fv_inner.cliente_id = pu.cliente_id
                                        WHERE pu.promocao_id = ? AND pu.instituicao_id = ?
                                          AND pu.data_uso >= ? AND pu.data_uso <= ?
                                          AND pu.cliente_id IS NOT NULL
                                        GROUP BY pu.cliente_id
                                    ) u
                                    JOIN clientes c ON c.id = u.cliente_id
                                    LEFT JOIN (
                                        SELECT cliente_id,
                                               CAST(SUBSTRING_INDEX(GROUP_CONCAT(id ORDER BY data_venda ASC, id ASC), ',', 1) AS UNSIGNED) AS primeira_venda_id
                                        FROM vendas
                                        WHERE instituicao_id = ? AND cliente_id IS NOT NULL
                                        GROUP BY cliente_id
                                    ) fv ON fv.cliente_id = u.cliente_id");
        $stPerfCli->execute([$iniDt, $fimDt, $idLoja, $promocaoId, $idLoja, $iniDt, $fimDt, $idLoja]);
        $perfCli = $stPerfCli->fetch(PDO::FETCH_ASSOC) ?: [];

        // Série temporal individual da promoção
        $fmtSqlPu = (($periodo['granularity'] ?? 'day') === 'month')
            ? "DATE_FORMAT(pu.data_uso, '%Y-%m')"
            : "DATE(pu.data_uso)";
        $stSerP = $pdo->prepare("SELECT {$fmtSqlPu} AS bucket,
                                        COUNT(pu.id) AS qtd_usos,
                                        COALESCE(SUM(pu.desconto_aplicado), 0) AS desconto,
                                        COALESCE(SUM(v.valor_total), 0) AS fat_vendas
                                 FROM promocao_usos pu
                                 LEFT JOIN vendas v ON v.id = pu.venda_id
                                 WHERE pu.promocao_id = ? AND pu.instituicao_id = ?
                                   AND pu.data_uso >= ? AND pu.data_uso <= ?
                                 GROUP BY bucket
                                 ORDER BY bucket ASC");
        $stSerP->execute([$promocaoId, $idLoja, $iniDt, $fimDt]);
        $mapSerP = [];
        foreach ($stSerP->fetchAll(PDO::FETCH_ASSOC) as $sr) {
            $mapSerP[(string)$sr['bucket']] = $sr;
        }
        $serLabels = [];
        $serFat = [];
        $serDesc = [];
        $serUsos = [];
        foreach (crmPanoramaGerarEixoTemporal($periodo) as $slot) {
            $bk = $slot['key'];
            $serLabels[] = $slot['label'];
            $serFat[] = isset($mapSerP[$bk]) ? round((float)$mapSerP[$bk]['fat_vendas'], 2) : 0.0;
            $serDesc[] = isset($mapSerP[$bk]) ? round((float)$mapSerP[$bk]['desconto'], 2) : 0.0;
            $serUsos[] = isset($mapSerP[$bk]) ? (int)$mapSerP[$bk]['qtd_usos'] : 0;
        }

        // Cross-sell: o que os clientes mais compram na mesma venda junto com esta promoção
        $stCs = $pdo->prepare("SELECT vi.nome_produto,
                                      COUNT(DISTINCT vi.venda_id) AS vendas,
                                      COALESCE(SUM(vi.quantidade), 0) AS qtd_itens,
                                      COALESCE(SUM(vi.subtotal), 0) AS receita
                               FROM promocao_usos pu
                               JOIN promocoes p ON p.id = pu.promocao_id
                               JOIN venda_itens vi ON vi.venda_id = pu.venda_id
                               WHERE pu.promocao_id = ? AND pu.instituicao_id = ?
                                 AND pu.data_uso >= ? AND pu.data_uso <= ?
                                 AND (vi.produto_id IS NULL OR p.produto_id IS NULL OR vi.produto_id != p.produto_id)
                               GROUP BY vi.nome_produto
                               ORDER BY vendas DESC, receita DESC
                               LIMIT 6");
        $stCs->execute([$promocaoId, $idLoja, $iniDt, $fimDt]);
        $crossSellItens = [];
        foreach ($stCs->fetchAll(PDO::FETCH_ASSOC) as $csRow) {
            $crossSellItens[] = [
                'nome_produto' => (string)$csRow['nome_produto'],
                'vendas' => (int)$csRow['vendas'],
                'qtd_itens' => (int)$csRow['qtd_itens'],
                'receita' => round((float)$csRow['receita'], 2),
            ];
        }

        // Distribuição por forma de pagamento
        $stPag = $pdo->prepare("SELECT COALESCE(NULLIF(v.forma_pagamento, ''), 'Não informado') AS forma_pagamento,
                                       COUNT(*) AS qtd,
                                       COALESCE(SUM(v.valor_total), 0) AS total
                                FROM promocao_usos pu
                                JOIN vendas v ON v.id = pu.venda_id
                                WHERE pu.promocao_id = ? AND pu.instituicao_id = ?
                                  AND pu.data_uso >= ? AND pu.data_uso <= ?
                                GROUP BY forma_pagamento
                                ORDER BY qtd DESC, total DESC");
        $stPag->execute([$promocaoId, $idLoja, $iniDt, $fimDt]);
        $pagamentos = [];
        $totPagQtd = 0;
        $pagRaw = $stPag->fetchAll(PDO::FETCH_ASSOC);
        foreach ($pagRaw as $pr) {
            $totPagQtd += (int)$pr['qtd'];
        }
        foreach ($pagRaw as $pr) {
            $qPag = (int)$pr['qtd'];
            $pagamentos[] = [
                'forma_pagamento' => (string)$pr['forma_pagamento'],
                'qtd' => $qPag,
                'total' => round((float)$pr['total'], 2),
                'pct' => $totPagQtd > 0 ? round(($qPag / $totPagQtd) * 100.0, 1) : 0.0,
            ];
        }

        // Últimos 50 usos detalhados (clientes e vendas que usaram esta promoção)
        $stUsos = $pdo->prepare("SELECT pu.id AS uso_id, pu.venda_id, pu.cliente_id, pu.quantidade_itens,
                                        pu.desconto_aplicado, pu.valor_final_itens, pu.data_uso,
                                        v.valor_total AS venda_valor_total, v.forma_pagamento,
                                        c.nome_completo, c.telefone, c.data_cadastro,
                                        (SELECT GROUP_CONCAT(pt.nome SEPARATOR ', ') FROM pets pt WHERE pt.cliente_id = c.id) AS pets_nomes,
                                        (SELECT COUNT(*) FROM promocao_usos pu2 WHERE pu2.promocao_id = pu.promocao_id AND pu2.cliente_id = pu.cliente_id) AS usos_cliente_promo,
                                        (SELECT COUNT(*) FROM vendas vp WHERE vp.cliente_id = pu.cliente_id AND vp.instituicao_id = ? AND vp.data_venda < v.data_venda) AS compras_anteriores
                                 FROM promocao_usos pu
                                 LEFT JOIN vendas v ON v.id = pu.venda_id
                                 LEFT JOIN clientes c ON c.id = pu.cliente_id
                                 WHERE pu.promocao_id = ? AND pu.instituicao_id = ?
                                   AND pu.data_uso >= ? AND pu.data_uso <= ?
                                 ORDER BY pu.data_uso DESC, pu.id DESC
                                 LIMIT 50");
        $stUsos->execute([$idLoja, $promocaoId, $idLoja, $iniDt, $fimDt]);
        $usosLista = [];
        foreach ($stUsos->fetchAll(PDO::FETCH_ASSOC) as $ur) {
            $cliCad = (string)($ur['data_cadastro'] ?? '');
            $usosLista[] = [
                'uso_id' => (int)$ur['uso_id'],
                'venda_id' => (int)($ur['venda_id'] ?? 0),
                'cliente_id' => (int)($ur['cliente_id'] ?? 0),
                'cliente_nome' => $ur['nome_completo'] ?: 'Cliente Balcão (Anônimo)',
                'telefone' => (string)($ur['telefone'] ?? ''),
                'pets_nomes' => (string)($ur['pets_nomes'] ?? ''),
                'data_uso' => (string)$ur['data_uso'],
                'data_uso_fmt' => date('d/m/Y H:i', strtotime($ur['data_uso'])),
                'quantidade_itens' => (int)$ur['quantidade_itens'],
                'desconto_aplicado' => round((float)$ur['desconto_aplicado'], 2),
                'valor_final_itens' => round((float)$ur['valor_final_itens'], 2),
                'venda_valor_total' => round((float)($ur['venda_valor_total'] ?? $ur['valor_final_itens']), 2),
                'forma_pagamento' => (string)($ur['forma_pagamento'] ?? '-'),
                'usos_cliente_promo' => (int)($ur['usos_cliente_promo'] ?? 1),
                'is_primeira_compra' => ((int)($ur['cliente_id'] ?? 0) > 0 && (int)($ur['compras_anteriores'] ?? 0) === 0),
                'is_novo_cadastro' => ($cliCad !== '' && $cliCad >= $iniDt && $cliCad <= $fimDt),
            ];
        }

        $polKey = (string)($promo['politica_reentrada'] ?? 'ilimitada');
        return [
            'periodo' => [
                'inicio' => date('d/m/Y', strtotime($periodo['inicio'])),
                'fim' => date('d/m/Y', strtotime($periodo['fim'])),
                'dias' => (int)$periodo['dias'],
            ],
            'promocao' => [
                'id' => (int)$promo['id'],
                'titulo' => (string)$promo['titulo'],
                'descricao' => (string)($promo['descricao'] ?? ''),
                'nome_produto' => (string)($promo['nome_produto'] ?? ''),
                'desconto_percentual' => round((float)$promo['desconto_percentual'], 1),
                'preco_original' => round((float)$promo['preco_original'], 2),
                'preco_promocional' => round((float)$promo['preco_promocional'], 2),
                'politica_reentrada' => $polKey,
                'politica_label' => $labelsPoliticaPromo[$polKey] ?? $polKey,
                'cooldown_dias' => (int)($promo['cooldown_dias'] ?? 0),
                'limite_usos_cliente' => (int)($promo['limite_usos_cliente'] ?? 0),
                'limite_usos_global' => (int)($promo['limite_usos_global'] ?? 0),
                'usos_globais_atuais' => (int)($promo['usos_globais_atuais'] ?? 0),
                'aplicar_automatico_pdv' => ((int)($promo['aplicar_automatico_pdv'] ?? 0) === 1),
                'data_inicio_fmt' => !empty($promo['data_inicio']) ? date('d/m/Y', strtotime($promo['data_inicio'])) : '-',
                'data_fim_fmt' => !empty($promo['data_fim']) ? date('d/m/Y', strtotime($promo['data_fim'])) : '-',
            ],
            'resumo' => [
                'qtd_usos' => $qtdUsos,
                'usos_campanha_total' => $usosCampanhaTotal,
                'clientes_unicos' => (int)($agg['clientes_unicos'] ?? 0),
                'vendas_com_promo' => $vendasPromo,
                'itens_vendidos' => (int)($agg['itens_vendidos'] ?? 0),
                'receita_itens_promo' => round($recItens, 2),
                'desconto_concedido' => round($descConcedido, 2),
                'faturamento_vendas_promo' => round($fatVendas, 2),
                'faturamento_cross_sell' => round($crossSellTotal, 2),
                'faturamento_geral_loja' => round($fatGeral, 2),
                'qtd_vendas_geral_loja' => $qtdVendasGeral,
                'ticket_medio_promo' => round($ticketPromo, 2),
                'ticket_medio_geral' => round($ticketGeral, 2),
                'variacao_ticket_pct' => round($varTicketPct, 1),
                'share_vendas_geral_pct' => $fatGeral > 0 ? round(($fatVendas / $fatGeral) * 100.0, 2) : 0.0,
                'roi_desconto' => $descConcedido > 0 ? round($fatVendas / $descConcedido, 1) : 0.0,
                'clientes_multi_uso' => (int)($perfCli['clientes_multi_uso'] ?? 0),
                'clientes_primeira_compra' => (int)($perfCli['clientes_primeira_compra'] ?? 0),
                'clientes_novos_cadastros' => (int)($perfCli['clientes_novos_cadastros'] ?? 0),
            ],
            'serie' => [
                'labels' => $serLabels,
                'fat_vendas' => $serFat,
                'desconto' => $serDesc,
                'qtd_usos' => $serUsos,
            ],
            'cross_sell' => $crossSellItens,
            'pagamentos' => $pagamentos,
            'usos_lista' => $usosLista,
        ];
    }
}
