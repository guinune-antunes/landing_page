<?php
/**
 * Motor Central de Regras CRM, Fidelidade, Promoções Relâmpago & Reentrada.
 * Fonte única de verdade usada pelo preview do PDV (api_fidelidade.php),
 * fechamento da venda (salvar_venda.php), balcão e cartão virtual.
 */

if (!function_exists('crmParseItemAlvo')) {
    /**
     * Converte valor de seletor ("produto:12", "servico:4" ou "12") em ['id' => ?int, 'tipo' => ?string].
     */
    function crmParseItemAlvo($val, ?string $tipoExplicito = null): array {
        $tipo = in_array($tipoExplicito, ['produto', 'servico'], true) ? $tipoExplicito : null;
        if ($val === null || $val === '') {
            return ['id' => null, 'tipo' => $tipo];
        }
        $str = trim((string)$val);
        if (preg_match('/^(produto|servico):(\d+)$/', $str, $m)) {
            return ['id' => (int)$m[2], 'tipo' => $m[1]];
        }
        $id = (int)$str;
        return ['id' => ($id > 0 ? $id : null), 'tipo' => $tipo];
    }
}

if (!function_exists('crmNormalizarItemCarrinho')) {
    /**
     * Normaliza um item vindo do PDV ou API para estrutura uniforme com tipo ('produto'|'servico').
     */
    function crmNormalizarItemCarrinho(array $it): array {
        $idBruto = (int)($it['id'] ?? 0);
        $nome = (string)($it['nome'] ?? '');
        $qtd = max(1, (int)($it['qtd'] ?? 1));
        $preco = max(0.0, (float)($it['preco'] ?? 0));
        $servicoIdParam = !empty($it['servico_id']) ? (int)$it['servico_id'] : null;
        $tipoParam = $it['tipo'] ?? null;

        if ($tipoParam === 'servico' || $servicoIdParam !== null) {
            $tipo = 'servico';
            $catalogoId = $servicoIdParam ?? ($idBruto > 0 ? $idBruto : null);
        } elseif ($idBruto < 0 || preg_match('/\b(Banho|Tosa|Consulta|Vacina|Hidrata)/i', $nome)) {
            $tipo = 'servico';
            $catalogoId = null;
        } else {
            $tipo = 'produto';
            $catalogoId = ($idBruto > 0 ? $idBruto : null);
        }

        return [
            'raw_id'      => $idBruto,
            'catalogo_id' => $catalogoId,
            'tipo'        => $tipo,
            'nome'        => $nome,
            'qtd'         => $qtd,
            'preco'       => $preco,
            'subtotal'    => round($qtd * $preco, 2),
        ];
    }
}

if (!function_exists('crmNormalizarCarrinho')) {
    function crmNormalizarCarrinho(array $itensBrutos): array {
        $res = [];
        foreach ($itensBrutos as $it) {
            if (is_array($it)) {
                $res[] = crmNormalizarItemCarrinho($it);
            }
        }
        return $res;
    }
}

if (!function_exists('crmContarItensElegiveisRegra')) {
    /**
     * Calcula quantos itens e qual subtotal do carrinho pertencem ao alvo da regra.
     */
    function crmContarItensElegiveisRegra(array $regra, array $itensNormalizados): array {
        $alvo = $regra['categoria_alvo'] ?? 'todos';
        $alvoId = !empty($regra['item_alvo_id']) ? (int)$regra['item_alvo_id'] : null;
        $alvoTipo = !empty($regra['item_alvo_tipo']) ? $regra['item_alvo_tipo'] : null;

        $qtdTotal = 0;
        $subtotal = 0.0;
        $casados = [];

        foreach ($itensNormalizados as $it) {
            $bate = false;
            if ($alvo === 'todos') {
                $bate = true;
            } elseif ($alvo === 'servicos') {
                $bate = ($it['tipo'] === 'servico');
            } elseif ($alvo === 'produtos') {
                $bate = ($it['tipo'] === 'produto');
            } elseif ($alvo === 'item_especifico' && $alvoId !== null) {
                $bateId = ($it['catalogo_id'] !== null && (int)$it['catalogo_id'] === $alvoId);
                $bateTipo = ($alvoTipo === null || $it['tipo'] === $alvoTipo);
                $bate = ($bateId && $bateTipo);
            }

            if ($bate) {
                $qtdTotal += $it['qtd'];
                $subtotal += $it['subtotal'];
                $casados[] = $it;
            }
        }

        return [
            'match'             => ($qtdTotal > 0),
            'qtd_itens'         => $qtdTotal,
            'subtotal_elegivel' => round($subtotal, 2),
            'itens_casados'     => $casados,
        ];
    }
}

if (!function_exists('crmVerificarAniversarioCliente')) {
    /**
     * Verifica se o mês atual coincide com aniversário do tutor (clientes.data_nascimento)
     * ou de algum pet cadastrado do tutor (pets.data_nascimento).
     */
    function crmVerificarAniversarioCliente(PDO $pdo, ?int $clienteId, array $idsClientes): array {
        if (!$clienteId || $clienteId <= 0) {
            return ['aniversario' => false, 'origem' => null, 'cliente' => null];
        }

        $inCli = implode(',', array_map('intval', $idsClientes ?: [0]));
        $stC = $pdo->prepare("SELECT id, nome_completo, telefone, data_nascimento FROM clientes WHERE id = ? AND instituicao_id IN ({$inCli})");
        $stC->execute([$clienteId]);
        $cli = $stC->fetch(PDO::FETCH_ASSOC) ?: null;

        $mesAtual = date('m');
        if ($cli && !empty($cli['data_nascimento'])) {
            if (date('m', strtotime($cli['data_nascimento'])) === $mesAtual) {
                return ['aniversario' => true, 'origem' => 'cliente', 'cliente' => $cli];
            }
        }

        $stPets = $pdo->prepare("SELECT data_nascimento FROM pets WHERE cliente_id = ? AND data_nascimento IS NOT NULL");
        $stPets->execute([$clienteId]);
        foreach ($stPets->fetchAll(PDO::FETCH_COLUMN) as $dnPet) {
            if (!empty($dnPet) && date('m', strtotime($dnPet)) === $mesAtual) {
                return ['aniversario' => true, 'origem' => 'pet', 'cliente' => $cli];
            }
        }

        return ['aniversario' => false, 'origem' => null, 'cliente' => $cli];
    }
}

if (!function_exists('crmObterPrecoItemCatalogo')) {
    function crmObterPrecoItemCatalogo(PDO $pdo, ?int $itemId, ?string $tipo, float $fallback = 0.0): float {
        if (!$itemId || $itemId <= 0) {
            return $fallback;
        }
        if ($tipo === 'servico') {
            $st = $pdo->prepare("SELECT preco_padrao FROM servicos WHERE id = ? LIMIT 1");
            $st->execute([$itemId]);
            $v = $st->fetchColumn();
            return ($v !== false && (float)$v > 0) ? (float)$v : $fallback;
        }
        if ($tipo === 'produto') {
            $st = $pdo->prepare("SELECT preco_venda FROM produtos WHERE id = ? LIMIT 1");
            $st->execute([$itemId]);
            $v = $st->fetchColumn();
            return ($v !== false && (float)$v > 0) ? (float)$v : $fallback;
        }
        $stP = $pdo->prepare("SELECT preco_venda FROM produtos WHERE id = ? LIMIT 1");
        $stP->execute([$itemId]);
        $vp = $stP->fetchColumn();
        if ($vp !== false && (float)$vp > 0) {
            return (float)$vp;
        }
        $stS = $pdo->prepare("SELECT preco_padrao FROM servicos WHERE id = ? LIMIT 1");
        $stS->execute([$itemId]);
        $vs = $stS->fetchColumn();
        return ($vs !== false && (float)$vs > 0) ? (float)$vs : $fallback;
    }
}

if (!function_exists('crmCalcularValorBeneficioRegra')) {
    /**
     * Calcula o valor monetário real (desconto/cashback/multiplicador) da regra sobre a base informada.
     */
    function crmCalcularValorBeneficioRegra(PDO $pdo, array $regra, float $baseCalculo): array {
        $tipo = $regra['recompensa_tipo'] ?? 'desconto_percentual';
        $val = max(0.0, (float)($regra['recompensa_valor'] ?? 0));
        $teto = isset($regra['limite_desconto_recompensa']) && (float)$regra['limite_desconto_recompensa'] > 0
            ? (float)$regra['limite_desconto_recompensa']
            : null;

        $desconto = 0.0;
        $cashback = 0.0;
        $multiplicadorPontos = 1.0;
        $texto = '';

        if ($tipo === 'desconto_percentual') {
            $desconto = round($baseCalculo * ($val / 100.0), 2);
            if ($teto !== null) {
                $desconto = min($desconto, $teto);
            }
            $desconto = min($desconto, $baseCalculo);
            $texto = number_format($val, 0) . '% de Desconto';
        } elseif ($tipo === 'desconto_fixo') {
            $desconto = min($baseCalculo, $val);
            if ($teto !== null) {
                $desconto = min($desconto, $teto);
            }
            $texto = 'R$ ' . number_format($val, 2, ',', '.') . ' de Desconto';
        } elseif ($tipo === 'item_gratis') {
            $valorItem = crmObterPrecoItemCatalogo(
                $pdo,
                !empty($regra['recompensa_item_id']) ? (int)$regra['recompensa_item_id'] : null,
                $regra['recompensa_item_tipo'] ?? null,
                $val
            );
            $desconto = min($baseCalculo, $valorItem);
            if ($teto !== null) {
                $desconto = min($desconto, $teto);
            }
            $texto = 'Item / Serviço Grátis' . ($desconto > 0 ? ' (até R$ ' . number_format($desconto, 2, ',', '.') . ')' : '');
        } elseif ($tipo === 'cashback') {
            $cashback = round($baseCalculo * ($val / 100.0), 2);
            if ($teto !== null) {
                $cashback = min($cashback, $teto);
            }
            $texto = number_format($val, 0) . '% de Cashback';
        } elseif ($tipo === 'multiplicador_pontos') {
            $multiplicadorPontos = max(1.0, $val);
            $texto = number_format($multiplicadorPontos, 1, ',', '.') . 'x Pontos';
        }

        return [
            'desconto_reais'       => round(max(0.0, $desconto), 2),
            'cashback_reais'       => round(max(0.0, $cashback), 2),
            'multiplicador_pontos' => $multiplicadorPontos,
            'descricao_beneficio'  => $texto,
        ];
    }
}

if (!function_exists('crmLimitePositivo')) {
    /**
     * Normaliza limites opcionais (ciclos/usos): null, vazio ou <= 0 significam "ilimitado" (null).
     */
    function crmLimitePositivo($valor): ?int {
        if ($valor === null || $valor === '') {
            return null;
        }
        $n = (int)$valor;
        return $n > 0 ? $n : null;
    }
}

if (!function_exists('crmAvaliarPoliticaReentradaRegra')) {
    /**
     * Determina se o cliente pode iniciar/avançar ciclo, se pode resgatar recompensa
     * e o motivo exato caso a reentrada esteja bloqueada.
     */
    function crmAvaliarPoliticaReentradaRegra(array $regra, ?array $prog, int $agoraTs): array {
        $politica = $regra['politica_reentrada'] ?? 'reiniciar_apos_resgate';
        $limiteCiclosCli = crmLimitePositivo($regra['limite_ciclos_cliente'] ?? null);
        $limiteGlobal = crmLimitePositivo($regra['limite_usos_global'] ?? null);
        $usosGlobais = (int)($regra['usos_globais_atuais'] ?? 0);
        $cooldownDias = max(0, (int)($regra['cooldown_dias'] ?? 0));

        $progressoAtual = (int)($prog['progresso_atual'] ?? 0);
        $ciclosCompletados = (int)($prog['ciclos_completados'] ?? 0);
        $recDisponivel = !empty($prog['recompensa_disponivel']);
        $bloqueadoReentrada = !empty($prog['bloqueado_reentrada']);
        $ultimoResgate = $prog['ultimo_resgate_em'] ?? null;

        // 1. Limite global da campanha esgotado para novos ciclos
        if ($limiteGlobal !== null && $usosGlobais >= $limiteGlobal && !$recDisponivel) {
            return [
                'pode_participar'      => false,
                'pode_acumular'        => false,
                'pode_resgatar'        => false,
                'novo_ciclo_iniciaria' => false,
                'status_codigo'        => 'limite_global_esgotado',
                'status_label'         => 'Campanha Esgotada',
                'motivo_bloqueio'      => "Limite global de {$limiteGlobal} uso(s) desta regra já foi atingido.",
            ];
        }

        // 2. Limite máximo de ciclos do cliente atingido
        if ($limiteCiclosCli !== null && $ciclosCompletados >= $limiteCiclosCli) {
            return [
                'pode_participar'      => false,
                'pode_acumular'        => false,
                'pode_resgatar'        => false,
                'novo_ciclo_iniciaria' => false,
                'status_codigo'        => 'limite_ciclos_cliente',
                'status_label'         => 'Limite de Ciclos Atingido',
                'motivo_bloqueio'      => "Cliente já completou {$ciclosCompletados}/{$limiteCiclosCli} ciclo(s) permitidos.",
            ];
        }

        // 3. Regra de participação única na vida ('unica_vez')
        if ($politica === 'unica_vez' && ($bloqueadoReentrada || $ciclosCompletados >= 1)) {
            return [
                'pode_participar'      => false,
                'pode_acumular'        => false,
                'pode_resgatar'        => false,
                'novo_ciclo_iniciaria' => false,
                'status_codigo'        => 'concluida_unica_vez',
                'status_label'         => 'Participação Única Já Utilizada',
                'motivo_bloqueio'      => 'Esta regra permite apenas 1 ciclo por cliente e já foi concluída.',
            ];
        }

        // 4. Carência entre ciclos ('cooldown_dias') após último resgate/conclusão
        if ($politica === 'cooldown_dias' && $cooldownDias > 0 && $ultimoResgate && !$recDisponivel && $progressoAtual === 0) {
            $diffSeg = $agoraTs - strtotime($ultimoResgate);
            $exigidoSeg = $cooldownDias * 86400;
            if ($diffSeg < $exigidoSeg) {
                $diasFaltam = (int)ceil(($exigidoSeg - $diffSeg) / 86400);
                return [
                    'pode_participar'      => false,
                    'pode_acumular'        => false,
                    'pode_resgatar'        => false,
                    'novo_ciclo_iniciaria' => false,
                    'status_codigo'        => 'em_cooldown',
                    'status_label'         => "Em Carência ({$diasFaltam}d restantes)",
                    'motivo_bloqueio'      => "Novo ciclo bloqueado por carência de {$cooldownDias} dias (faltam {$diasFaltam} dia(s)).",
                ];
            }
        }

        // 5. Recompensa já desbloqueada esperando resgate em 'reiniciar_apos_resgate'
        if ($recDisponivel && $politica === 'reiniciar_apos_resgate') {
            return [
                'pode_participar'      => true,
                'pode_acumular'        => false,
                'pode_resgatar'        => true,
                'novo_ciclo_iniciaria' => false,
                'status_codigo'        => 'aguardando_resgate',
                'status_label'         => 'Prêmio Pronto p/ Resgate',
                'motivo_bloqueio'      => null,
            ];
        }

        if ($recDisponivel) {
            return [
                'pode_participar'      => true,
                'pode_acumular'        => true,
                'pode_resgatar'        => true,
                'novo_ciclo_iniciaria' => ($progressoAtual === 0),
                'status_codigo'        => 'recompensa_disponivel',
                'status_label'         => 'Recompensa Disponível',
                'motivo_bloqueio'      => null,
            ];
        }

        $ehReentrada = ($progressoAtual === 0 && $ciclosCompletados > 0);
        return [
            'pode_participar'      => true,
            'pode_acumular'        => true,
            'pode_resgatar'        => false,
            'novo_ciclo_iniciaria' => ($progressoAtual === 0),
            'status_codigo'        => $ehReentrada ? 'reinicio_ciclo' : ($progressoAtual === 0 ? 'inicio_ciclo' : 'em_progresso'),
            'status_label'         => $ehReentrada ? 'Inicia Novo Ciclo (' . ($ciclosCompletados + 1) . 'º)' : ($progressoAtual === 0 ? 'Inicia 1º Ciclo' : 'Ciclo em Progresso'),
            'motivo_bloqueio'      => null,
        ];
    }
}

if (!function_exists('crmAvaliarPromocoesCarrinho')) {
    /**
     * Avalia todas as promoções relâmpago vigentes contra os itens do carrinho e a política de reentrada do cliente.
     */
    function crmAvaliarPromocoesCarrinho(PDO $pdo, int $idLoja, array $itensNormalizados, ?int $clienteId, ?int $agoraTs = null): array {
        $agoraTs = $agoraTs ?? time();
        $agoraSql = date('Y-m-d H:i:s', $agoraTs);

        $st = $pdo->prepare("SELECT * FROM promocoes
                             WHERE instituicao_id = ? AND ativo = 1
                               AND data_inicio <= ? AND data_fim >= ?
                             ORDER BY desconto_percentual DESC, id DESC");
        $st->execute([$idLoja, $agoraSql, $agoraSql]);
        $promos = $st->fetchAll(PDO::FETCH_ASSOC);

        $aplicaveis = [];
        $bloqueadas = [];
        $pendentesId = [];
        $totalDescPromos = 0.0;

        foreach ($promos as $pr) {
            // Verifica se algum item do carrinho corresponde à promoção
            $baseItens = 0.0;
            $qtdItens = 0;
            foreach ($itensNormalizados as $it) {
                $casaProduto = empty($pr['produto_id'])
                    ? ($it['tipo'] === 'produto')
                    : ($it['tipo'] === 'produto' && (int)$it['catalogo_id'] === (int)$pr['produto_id']);
                if ($casaProduto) {
                    $baseItens += $it['subtotal'];
                    $qtdItens += $it['qtd'];
                }
            }

            if ($qtdItens <= 0) {
                continue;
            }

            $politica = $pr['politica_reentrada'] ?? 'ilimitada';
            $limGlobal = crmLimitePositivo($pr['limite_usos_global'] ?? null);
            $usosGlobais = (int)($pr['usos_globais_atuais'] ?? 0);
            $limCli = crmLimitePositivo($pr['limite_usos_cliente'] ?? null);
            $cooldownDias = max(0, (int)($pr['cooldown_dias'] ?? 0));

            if ($limGlobal !== null && $usosGlobais >= $limGlobal) {
                $bloqueadas[] = [
                    'id' => (int)$pr['id'],
                    'titulo' => $pr['titulo'],
                    'motivo' => "Limite global de {$limGlobal} uso(s) esgotado."
                ];
                continue;
            }

            // Regras que exigem identificar o cliente antes de conceder desconto
            $precisaCliente = ($politica !== 'ilimitada' || $limCli !== null);
            if ($precisaCliente && (!$clienteId || $clienteId <= 0)) {
                $pendentesId[] = [
                    'id' => (int)$pr['id'],
                    'titulo' => $pr['titulo'],
                    'desconto_percentual' => (float)$pr['desconto_percentual'],
                    'politica_reentrada' => $politica,
                    'motivo' => 'Selecione ou identifique o cliente para validar se ele pode usar esta promoção.'
                ];
                continue;
            }

            if ($clienteId && $clienteId > 0) {
                $stUso = $pdo->prepare("SELECT COUNT(*) as total_usos, MAX(data_uso) as ultimo_uso FROM promocao_usos WHERE promocao_id = ? AND cliente_id = ?");
                $stUso->execute([$pr['id'], $clienteId]);
                $usoInfo = $stUso->fetch(PDO::FETCH_ASSOC) ?: ['total_usos' => 0, 'ultimo_uso' => null];
                $totalUsosCli = (int)$usoInfo['total_usos'];
                $ultimoUso = $usoInfo['ultimo_uso'];

                if ($limCli !== null && $totalUsosCli >= $limCli) {
                    $bloqueadas[] = [
                        'id' => (int)$pr['id'],
                        'titulo' => $pr['titulo'],
                        'motivo' => "Cliente já atingiu o limite de {$limCli} compra(s) promocional(is)."
                    ];
                    continue;
                }

                if ($politica === 'unica_vez' && $totalUsosCli >= 1) {
                    $bloqueadas[] = [
                        'id' => (int)$pr['id'],
                        'titulo' => $pr['titulo'],
                        'motivo' => 'Promoção válida apenas 1 vez por cliente (não inicia novamente nesta recompra).'
                    ];
                    continue;
                }

                if ($politica === 'uma_por_dia' && $ultimoUso && date('Y-m-d', strtotime($ultimoUso)) === date('Y-m-d', $agoraTs)) {
                    $bloqueadas[] = [
                        'id' => (int)$pr['id'],
                        'titulo' => $pr['titulo'],
                        'motivo' => 'Cliente já utilizou esta promoção hoje (limite: 1 por dia).'
                    ];
                    continue;
                }

                if ($politica === 'cooldown_dias' && $cooldownDias > 0 && $ultimoUso) {
                    $diffSeg = $agoraTs - strtotime($ultimoUso);
                    $exigidoSeg = $cooldownDias * 86400;
                    if ($diffSeg < $exigidoSeg) {
                        $diasFaltam = (int)ceil(($exigidoSeg - $diffSeg) / 86400);
                        $bloqueadas[] = [
                            'id' => (int)$pr['id'],
                            'titulo' => $pr['titulo'],
                            'motivo' => "Carência de {$cooldownDias} dias ativa (faltam {$diasFaltam} dia(s) para repetir)."
                        ];
                        continue;
                    }
                }
            }

            $pct = max(0.0, min(100.0, (float)$pr['desconto_percentual']));
            $descValor = round($baseItens * ($pct / 100.0), 2);
            $autoAplicar = !isset($pr['aplicar_automatico_pdv']) || (int)$pr['aplicar_automatico_pdv'] === 1;

            if ($autoAplicar && $descValor > 0) {
                $totalDescPromos += $descValor;
            }

            $aplicaveis[] = [
                'id'                  => (int)$pr['id'],
                'titulo'              => $pr['titulo'],
                'produto_id'          => $pr['produto_id'] ? (int)$pr['produto_id'] : null,
                'desconto_percentual' => $pct,
                'desconto_valor'      => $autoAplicar ? $descValor : 0.0,
                'base_itens'          => round($baseItens, 2),
                'quantidade_itens'    => $qtdItens,
                'politica_reentrada'  => $politica,
                'aplicar_automatico'  => $autoAplicar,
            ];
        }

        return [
            'aplicaveis'               => $aplicaveis,
            'bloqueadas'               => $bloqueadas,
            'pendentes_identificacao'  => $pendentesId,
            'total_desconto_promocao'  => round($totalDescPromos, 2),
        ];
    }
}

if (!function_exists('crmAvaliarCarrinhoCompleto')) {
    /**
     * Simula (e prepara para execução) todas as promoções relâmpago, regras modulares e fidelidade clássica.
     */
    function crmAvaliarCarrinhoCompleto(
        PDO $pdo,
        int $idLoja,
        int $idLojaFid,
        array $idsClientes,
        array $itensBrutos,
        ?int $clienteId,
        float $descontoManual = 0.0,
        array $opcoes = []
    ): array {
        $agoraTs = isset($opcoes['agora_ts']) ? (int)$opcoes['agora_ts'] : time();
        $itensNorm = crmNormalizarCarrinho($itensBrutos);

        $subtotalBruto = 0.0;
        foreach ($itensNorm as $it) {
            $subtotalBruto += $it['subtotal'];
        }
        $subtotalBruto = round($subtotalBruto, 2);

        // 1. Promoções Relâmpago vigentes
        $promoEval = crmAvaliarPromocoesCarrinho($pdo, $idLoja, $itensNorm, $clienteId, $agoraTs);
        $descontoPromos = min($subtotalBruto, $promoEval['total_desconto_promocao']);
        $restanteParaDesconto = max(0.0, round($subtotalBruto - $descontoPromos - max(0.0, $descontoManual), 2));

        // 2. Aniversário do cliente ou pet + info básica do cliente
        $niverInfo = crmVerificarAniversarioCliente($pdo, $clienteId, $idsClientes);
        $clienteInfo = $niverInfo['cliente'];
        $aniversarioEsteMes = $niverInfo['aniversario'];

        // Config padrão de fidelidade da loja
        $stCfg = $pdo->prepare("SELECT * FROM fidelidade_config WHERE instituicao_id = ?");
        $stCfg->execute([$idLojaFid]);
        $cfg = $stCfg->fetch(PDO::FETCH_ASSOC) ?: [
            'tipo_programa' => 'selos',
            'compras_para_recompensa' => 10,
            'recompensa_tipo' => 'desconto_percentual',
            'recompensa_valor' => 15.00,
            'limite_desconto_recompensa' => 50.00,
            'reais_por_ponto' => 1.00,
            'cashback_percentual' => 5.00,
            'cor_cartao_hex' => '#1abc9c',
            'cor_cartao_secundaria' => '#2c3e50',
            'texto_cartao' => 'Cartão Fidelidade Pet VIP',
            'tiers_config' => json_encode([
                ['nome' => 'Bronze', 'meta' => 0],
                ['nome' => 'Prata', 'meta' => 500],
                ['nome' => 'Ouro VIP', 'meta' => 1500],
            ])
        ];

        $cliFid = null;
        if ($clienteId && $clienteId > 0) {
            $stCf = $pdo->prepare("SELECT * FROM cliente_fidelidade WHERE instituicao_id = ? AND cliente_id = ?");
            $stCf->execute([$idLojaFid, $clienteId]);
            $cliFid = $stCf->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        // 3. Regras modulares ordenadas por prioridade (1 = mais prioritária)
        $stRegras = $pdo->prepare("SELECT * FROM fidelidade_regras
                                   WHERE instituicao_id = ? AND ativo = 1
                                   ORDER BY prioridade ASC, id ASC");
        $stRegras->execute([$idLojaFid]);
        $regras = $stRegras->fetchAll(PDO::FETCH_ASSOC);

        $diasMap = ['Sun' => 'dom', 'Mon' => 'seg', 'Tue' => 'ter', 'Wed' => 'qua', 'Thu' => 'qui', 'Fri' => 'sex', 'Sat' => 'sab'];
        $hojeDia = $diasMap[date('D', $agoraTs)];
        $agoraSql = date('Y-m-d H:i:s', $agoraTs);

        $regrasElegiveis = [];
        $regrasBloqueadas = [];
        $descontoFidelidadeTotal = 0.0;
        $cashbackTotal = 0.0;
        $pontosTotais = 0;
        $regraExclusivaAplicada = null;

        // Conjunto opcional de regras que o operador optou por não resgatar agora
        $idsResgateManual = isset($opcoes['resgatar_regra_ids']) && is_array($opcoes['resgatar_regra_ids'])
            ? array_map('intval', $opcoes['resgatar_regra_ids'])
            : null;

        foreach ($regras as $r) {
            // Vigência por data_inicio / data_fim
            if (!empty($r['data_inicio']) && $agoraSql < $r['data_inicio']) {
                continue;
            }
            if (!empty($r['data_fim']) && $agoraSql > $r['data_fim']) {
                continue;
            }

            // Verifica itens alvo
            $alvoCheck = crmContarItensElegiveisRegra($r, $itensNorm);
            if (!$alvoCheck['match']) {
                continue;
            }

            // Verifica condição
            $condTipo = $r['condicao_tipo'] ?? 'quantidade_compras';
            $condVal = (float)($r['condicao_valor'] ?? 0);

            if ($condTipo === 'dia_semana') {
                $diasAtivos = json_decode($r['dias_semana_ativos'] ?? '[]', true) ?: [];
                if (!empty($diasAtivos) && !in_array($hojeDia, $diasAtivos, true)) {
                    $regrasBloqueadas[] = [
                        'id' => (int)$r['id'],
                        'nome_regra' => $r['nome_regra'],
                        'motivo' => 'Válida apenas nos dias selecionados (' . implode(', ', $diasAtivos) . ').'
                    ];
                    continue;
                }
            } elseif ($condTipo === 'valor_minimo') {
                if ($subtotalBruto < $condVal) {
                    $regrasBloqueadas[] = [
                        'id' => (int)$r['id'],
                        'nome_regra' => $r['nome_regra'],
                        'motivo' => 'Requer compra mínima de R$ ' . number_format($condVal, 2, ',', '.') . '.'
                    ];
                    continue;
                }
            } elseif ($condTipo === 'aniversario') {
                if (!$clienteId || $clienteId <= 0) {
                    $regrasBloqueadas[] = [
                        'id' => (int)$r['id'],
                        'nome_regra' => $r['nome_regra'],
                        'motivo' => 'Identifique o cliente para validar aniversário do tutor ou do pet.'
                    ];
                    continue;
                }
                if (!$aniversarioEsteMes) {
                    continue;
                }
            }

            // Se já aplicou uma regra exclusiva antes, ignora regras seguintes
            if ($regraExclusivaAplicada !== null) {
                $regrasBloqueadas[] = [
                    'id' => (int)$r['id'],
                    'nome_regra' => $r['nome_regra'],
                    'motivo' => "Não acumulável: regra prioritária \"{$regraExclusivaAplicada}\" já aplicada."
                ];
                continue;
            }

            // Estado atual do cliente nessa regra
            $prog = null;
            if ($clienteId && $clienteId > 0) {
                $stProg = $pdo->prepare("SELECT * FROM cliente_progresso_regras WHERE cliente_id = ? AND regra_id = ?");
                $stProg->execute([$clienteId, $r['id']]);
                $prog = $stProg->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            $estadoReentrada = crmAvaliarPoliticaReentradaRegra($r, $prog, $agoraTs);
            if (!$estadoReentrada['pode_participar']) {
                $regrasBloqueadas[] = [
                    'id' => (int)$r['id'],
                    'nome_regra' => $r['nome_regra'],
                    'status_codigo' => $estadoReentrada['status_codigo'],
                    'motivo' => $estadoReentrada['motivo_bloqueio']
                ];
                continue;
            }

            $baseCalculo = ($r['categoria_alvo'] === 'todos') ? $subtotalBruto : $alvoCheck['subtotal_elegivel'];
            $benefCalc = crmCalcularValorBeneficioRegra($pdo, $r, $baseCalculo);
            $mec = $r['tipo_mecanica'] ?? 'cartao_selos';
            $meta = ($condTipo === 'quantidade_compras') ? max(1, (int)$condVal) : 0;
            $deltaProgresso = (!empty($r['contagem_por_item'])) ? $alvoCheck['qtd_itens'] : 1;

            $progAntes = (int)($prog['progresso_atual'] ?? 0);
            $ciclosAntes = (int)($prog['ciclos_completados'] ?? 0);
            $politica = $r['politica_reentrada'] ?? 'reiniciar_apos_resgate';
            $autoResgatarPdv = !isset($r['auto_resgatar_pdv']) || (int)$r['auto_resgatar_pdv'] === 1;
            $acumularSobra = !isset($r['acumular_sobra']) || (int)$r['acumular_sobra'] === 1;

            $acaoNestaVenda = 'acumular';
            $descontoEstaRegra = 0.0;
            $cashbackEstaRegra = 0.0;
            $pontosEstaRegra = 0;
            $progDepois = $progAntes;
            $ciclosDepois = $ciclosAntes;
            $recDispDepois = !empty($prog['recompensa_disponivel']) ? 1 : 0;
            $bloqueadoDepois = !empty($prog['bloqueado_reentrada']) ? 1 : 0;
            $completaOuResgata = false;

            if (in_array($mec, ['desconto_direto', 'cashback', 'pontos'], true) || $meta === 0) {
                // Mecânicas imediatas (não dependem de acumular N compras, ou meta = 0)
                $completaOuResgata = true;
                $acaoNestaVenda = 'aplicar_imediato';

                if ($mec === 'cashback' || $r['recompensa_tipo'] === 'cashback') {
                    $cashbackEstaRegra = $benefCalc['cashback_reais'];
                    if ($cashbackEstaRegra <= 0 && (float)$r['recompensa_valor'] > 0) {
                        $cashbackEstaRegra = round($baseCalculo * ((float)$r['recompensa_valor'] / 100.0), 2);
                    }
                    $cashbackTotal += $cashbackEstaRegra;
                } elseif ($mec === 'pontos') {
                    $reaisPorPonto = max(0.1, (float)($cfg['reais_por_ponto'] ?? 1.0));
                    $ptsBase = (int)floor($baseCalculo / $reaisPorPonto);
                    $pontosEstaRegra = (int)round($ptsBase * $benefCalc['multiplicador_pontos']);
                    $pontosTotais += $pontosEstaRegra;
                } else {
                    $descontoEstaRegra = min($restanteParaDesconto, $benefCalc['desconto_reais']);
                    $restanteParaDesconto = max(0.0, round($restanteParaDesconto - $descontoEstaRegra, 2));
                    $descontoFidelidadeTotal += $descontoEstaRegra;
                }

                $ciclosDepois++;
                $progDepois = 0;
                $recDispDepois = 0;
                $limCiclosCliRegra = crmLimitePositivo($r['limite_ciclos_cliente'] ?? null);
                if ($politica === 'unica_vez' || ($limCiclosCliRegra !== null && $ciclosDepois >= $limCiclosCliRegra)) {
                    $bloqueadoDepois = 1;
                }
            } else {
                // Mecânica de ciclo / selos / compre_ganhe com meta N
                $permitidoResgatar = ($idsResgateManual === null)
                    ? $autoResgatarPdv
                    : in_array((int)$r['id'], $idsResgateManual, true);

                if ($estadoReentrada['pode_resgatar']) {
                    // Já tinha recompensa pendente antes desta compra
                    if ($permitidoResgatar) {
                        $acaoNestaVenda = 'resgatar_pendente';
                        $completaOuResgata = true;
                        $descontoEstaRegra = min($restanteParaDesconto, $benefCalc['desconto_reais']);
                        $restanteParaDesconto = max(0.0, round($restanteParaDesconto - $descontoEstaRegra, 2));
                        $descontoFidelidadeTotal += $descontoEstaRegra;
                        $cashbackEstaRegra = $benefCalc['cashback_reais'];
                        $cashbackTotal += $cashbackEstaRegra;

                        $ciclosDepois++;
                        $recDispDepois = 0;
                        $sobra = ($acumularSobra && $politica !== 'cooldown_dias') ? max(0, $progAntes - $meta) : 0;
                        $progDepois = $sobra;
                        $limCiclosCliRegra = crmLimitePositivo($r['limite_ciclos_cliente'] ?? null);
                        if ($politica === 'unica_vez' || ($limCiclosCliRegra !== null && $ciclosDepois >= $limCiclosCliRegra)) {
                            $bloqueadoDepois = 1;
                            $progDepois = 0;
                        }
                    } else {
                        $acaoNestaVenda = 'aguardando_resgate';
                    }
                } else {
                    // Acumula no ciclo atual
                    $soma = $progAntes + $deltaProgresso;
                    if ($soma >= $meta) {
                        if ($politica === 'reiniciar_ao_concluir' || ($autoResgatarPdv && $permitidoResgatar && $politica === 'ilimitada_por_compra')) {
                            $acaoNestaVenda = 'concluir_e_reiniciar';
                            $completaOuResgata = true;
                            $descontoEstaRegra = min($restanteParaDesconto, $benefCalc['desconto_reais']);
                            $restanteParaDesconto = max(0.0, round($restanteParaDesconto - $descontoEstaRegra, 2));
                            $descontoFidelidadeTotal += $descontoEstaRegra;
                            $cashbackEstaRegra = $benefCalc['cashback_reais'];
                            $cashbackTotal += $cashbackEstaRegra;

                            $ciclosDepois++;
                            $recDispDepois = 0;
                            $sobra = ($acumularSobra && $politica !== 'cooldown_dias') ? max(0, $soma - $meta) : 0;
                            $progDepois = $sobra;
                            $limCiclosCliRegra = crmLimitePositivo($r['limite_ciclos_cliente'] ?? null);
                            if ($limCiclosCliRegra !== null && $ciclosDepois >= $limCiclosCliRegra) {
                                $bloqueadoDepois = 1;
                                $progDepois = 0;
                            }
                        } else {
                            // Atingiu a meta agora: marca recompensa disponível (para resgate no próximo atendimento ou balcão)
                            $acaoNestaVenda = 'desbloquear_recompensa';
                            $progDepois = $soma;
                            $recDispDepois = 1;
                        }
                    } else {
                        $acaoNestaVenda = 'acumular';
                        $progDepois = $soma;
                        $recDispDepois = 0;
                    }
                }
            }

            if (isset($r['acumular_com_outras']) && (int)$r['acumular_com_outras'] === 0) {
                $regraExclusivaAplicada = $r['nome_regra'];
            }

            $regrasElegiveis[] = [
                'id'                   => (int)$r['id'],
                'nome_regra'           => $r['nome_regra'],
                'tipo_mecanica'        => $mec,
                'categoria_alvo'       => $r['categoria_alvo'],
                'politica_reentrada'   => $politica,
                'prioridade'           => (int)($r['prioridade'] ?? 10),
                'acumular_com_outras'  => !isset($r['acumular_com_outras']) || (int)$r['acumular_com_outras'] === 1,
                'meta'                 => (float)$meta,
                'progresso_antes'      => $progAntes,
                'progresso_depois'     => $progDepois,
                'delta_progresso'      => $deltaProgresso,
                'ciclos_antes'         => $ciclosAntes,
                'ciclos_depois'        => $ciclosDepois,
                'recompensa_disponivel_depois' => $recDispDepois,
                'bloqueado_depois'     => $bloqueadoDepois,
                'completa_ou_resgata'  => $completaOuResgata,
                'acao_venda'           => $acaoNestaVenda,
                'status_label'         => $estadoReentrada['status_label'],
                'beneficio'            => $benefCalc['descricao_beneficio'],
                'desconto_aplicado'    => round($descontoEstaRegra, 2),
                'cashback_gerado'      => round($cashbackEstaRegra, 2),
                'pontos_gerados'       => $pontosEstaRegra,
                'cor_hex'              => $r['cor_hex'] ?? '#1abc9c',
                'cor_secundaria'       => $r['cor_secundaria'] ?? '#2c3e50',
                'icone'                => $r['icone'] ?? 'fas fa-paw',
                'texto_cartao'         => $r['texto_cartao'] ?: $r['nome_regra'],
            ];
        }

        // Resumo do cartão clássico (selos padrão da loja)
        $metaClassica = max(1, (int)($cfg['compras_para_recompensa'] ?? 10));
        $selosClassicosAntes = (int)($cliFid['selos_atuais'] ?? 0);
        $classicoProntoResgate = ($selosClassicosAntes >= $metaClassica);
        $descontoClassico = 0.0;
        $usarResgateClassico = !empty($opcoes['resgatar_cartao_classico']);

        if ($classicoProntoResgate && $usarResgateClassico && $restanteParaDesconto > 0) {
            $regraFakeClassica = [
                'recompensa_tipo' => $cfg['recompensa_tipo'] ?? 'desconto_percentual',
                'recompensa_valor' => $cfg['recompensa_valor'] ?? 15,
                'limite_desconto_recompensa' => $cfg['limite_desconto_recompensa'] ?? 50,
            ];
            $benefCls = crmCalcularValorBeneficioRegra($pdo, $regraFakeClassica, $subtotalBruto);
            $descontoClassico = min($restanteParaDesconto, $benefCls['desconto_reais']);
            $restanteParaDesconto = max(0.0, round($restanteParaDesconto - $descontoClassico, 2));
            $descontoFidelidadeTotal += $descontoClassico;
        }

        // Uso opcional de saldo cashback da carteira
        $saldoCashbackDisp = (float)($cliFid['saldo_cashback'] ?? 0.0);
        $cashbackSolicitado = max(0.0, (float)($opcoes['usar_cashback'] ?? 0.0));
        $cashbackUtilizado = min($saldoCashbackDisp, $cashbackSolicitado, $restanteParaDesconto);
        $restanteParaDesconto = max(0.0, round($restanteParaDesconto - $cashbackUtilizado, 2));

        // Se loja não tiver nenhuma regra modular ativa/elegível nem promo, mantém card clássico no modal
        $regrasParaModal = $regrasElegiveis;
        if (empty($regrasParaModal) && $cfg && count($itensNorm) > 0 && empty($regrasBloqueadas)) {
            $regrasParaModal[] = [
                'id' => 0,
                'nome_regra' => $cfg['texto_cartao'] ?: 'Cartão Fidelidade Pet VIP',
                'tipo_mecanica' => $cfg['tipo_programa'] ?: 'selos',
                'meta' => (float)$metaClassica,
                'progresso_antes' => $selosClassicosAntes,
                'progresso_depois' => $selosClassicosAntes + 1,
                'beneficio' => number_format((float)($cfg['recompensa_valor'] ?: 15), 0) . '% de Desconto',
                'desconto_aplicado' => $descontoClassico,
                'status_label' => $classicoProntoResgate ? 'Pronto p/ Resgate' : 'Acumulando Selos',
                'cor_hex' => $cfg['cor_cartao_hex'] ?: '#1abc9c',
                'cor_secundaria' => $cfg['cor_cartao_secundaria'] ?: '#2c3e50',
                'icone' => 'fas fa-paw',
                'texto_cartao' => $cfg['texto_cartao'] ?: 'Cartão Fidelidade Pet VIP',
            ];
        }

        $descontoTotalCalculado = round($descontoPromos + $descontoFidelidadeTotal + $cashbackUtilizado + max(0.0, $descontoManual), 2);
        $totalLiquido = max(0.0, round($subtotalBruto - $descontoTotalCalculado, 2));

        return [
            'subtotal_bruto'         => $subtotalBruto,
            'desconto_promocao'      => round($descontoPromos, 2),
            'desconto_fidelidade'    => round($descontoFidelidadeTotal, 2),
            'desconto_classico'      => round($descontoClassico, 2),
            'cashback_utilizado'     => round($cashbackUtilizado, 2),
            'desconto_total'         => $descontoTotalCalculado,
            'total_liquido'          => $totalLiquido,
            'cashback_gerado'        => round($cashbackTotal, 2),
            'pontos_gerados'         => (int)$pontosTotais,
            'promocoes'              => $promoEval,
            'regras'                 => $regrasParaModal,
            'regras_modulares'       => $regrasElegiveis,
            'regras_bloqueadas'      => $regrasBloqueadas,
            'cartao_classico'        => [
                'selos_atuais'          => $selosClassicosAntes,
                'meta_selos'            => $metaClassica,
                'pontos_acumulados'     => (int)($cliFid['pontos_acumulados'] ?? 0),
                'saldo_cashback'        => $saldoCashbackDisp,
                'tier_atual'            => $cliFid['tier_atual'] ?? 'Bronze',
                'recompensa_disponivel' => $classicoProntoResgate,
            ],
            'cliente'                => $clienteInfo,
            'aniversario_mes'        => $aniversarioEsteMes,
            'config'                 => $cfg,
        ];
    }
}

if (!function_exists('crmCalcularTierCliente')) {
    function crmCalcularTierCliente(float $totalGastoHistorico, array $cfg): string {
        $tiers = json_decode($cfg['tiers_config'] ?? '[]', true);
        if (!is_array($tiers) || empty($tiers)) {
            $tiers = [
                ['nome' => 'Bronze', 'meta' => 0],
                ['nome' => 'Prata', 'meta' => 500],
                ['nome' => 'Ouro VIP', 'meta' => 1500],
            ];
        }
        $tierAtual = 'Bronze';
        usort($tiers, fn($a, $b) => ((float)($a['meta'] ?? 0)) <=> ((float)($b['meta'] ?? 0)));
        foreach ($tiers as $t) {
            if ($totalGastoHistorico >= (float)($t['meta'] ?? 0) && !empty($t['nome'])) {
                $tierAtual = (string)$t['nome'];
            }
        }
        return $tierAtual;
    }
}

if (!function_exists('crmExecutarBeneficiosVenda')) {
    /**
     * Persiste atomicamente os efeitos da venda no CRM: promoções usadas, progresso/reinício das regras modulares,
     * cartão clássico, pontos/cashback/tiers e histórico em fidelidade_transacoes.
     */
    function crmExecutarBeneficiosVenda(
        PDO $pdo,
        int $idLoja,
        int $idLojaFid,
        int $vendaId,
        ?int $clienteId,
        array $eval,
        string $operador,
        array $opcoes = []
    ): array {
        $agoraTs = isset($opcoes['agora_ts']) ? (int)$opcoes['agora_ts'] : time();
        $agoraSql = date('Y-m-d H:i:s', $agoraTs);

        // 1. Grava uso de Promoções Relâmpago aplicadas no caixa (mesmo se anônimo p/ ilimitadas)
        foreach ($eval['promocoes']['aplicaveis'] as $pr) {
            if (!$pr['aplicar_automatico']) {
                continue;
            }
            $pdo->prepare("INSERT INTO promocao_usos (promocao_id, instituicao_id, cliente_id, venda_id, quantidade_itens, desconto_aplicado, valor_final_itens, data_uso)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([
                    $pr['id'],
                    $idLoja,
                    $clienteId,
                    $vendaId,
                    $pr['quantidade_itens'],
                    $pr['desconto_valor'],
                    max(0.0, round($pr['base_itens'] - $pr['desconto_valor'], 2)),
                    $agoraSql
                ]);

            $pdo->prepare("UPDATE promocoes SET usos_globais_atuais = usos_globais_atuais + 1 WHERE id = ?")
                ->execute([$pr['id']]);

            if ($clienteId) {
                $pdo->prepare("INSERT INTO fidelidade_transacoes (instituicao_id, cliente_id, promocao_id, venda_id, tipo, quantidade, valor_recompensa, descricao, usuario_operador, data_transacao)
                               VALUES (?, ?, ?, ?, 'promocao_relampago', ?, ?, ?, ?, ?)")
                    ->execute([
                        $idLojaFid,
                        $clienteId,
                        $pr['id'],
                        $vendaId,
                        $pr['quantidade_itens'],
                        $pr['desconto_valor'],
                        "Promoção \"{$pr['titulo']}\" aplicada na venda #{$vendaId}",
                        $operador,
                        $agoraSql
                    ]);
            }
        }

        if (!$clienteId || $clienteId <= 0 || (isset($opcoes['participar_fidelidade']) && empty($opcoes['participar_fidelidade']))) {
            return ['participou' => false];
        }

        // 2. Garante e atualiza cartão clássico + carteira de pontos/cashback/tier
        $stCliFid = $pdo->prepare("SELECT * FROM cliente_fidelidade WHERE instituicao_id = ? AND cliente_id = ?");
        $stCliFid->execute([$idLojaFid, $clienteId]);
        $cliFid = $stCliFid->fetch(PDO::FETCH_ASSOC);

        $cfg = $eval['config'];
        $metaSelos = max(1, (int)($cfg['compras_para_recompensa'] ?? 10));
        $valorPagoLiquido = (float)$eval['total_liquido'];
        $descontoClassico = (float)($eval['desconto_classico'] ?? 0.0);
        $cashbackGerado = (float)($eval['cashback_gerado'] ?? 0.0);
        $cashbackUtilizado = (float)($eval['cashback_utilizado'] ?? 0.0);
        $pontosGerados = (int)($eval['pontos_gerados'] ?? 0);

        if (!$cliFid) {
            $selosAntes = 0;
            $ciclosAntes = 0;
            $pontosAntes = 0;
            $saldoAntes = 0.0;
            $gastoAntes = 0.0;
            $pdo->prepare("INSERT INTO cliente_fidelidade (instituicao_id, cliente_id, selos_atuais, ciclos_completados, pontos_acumulados, saldo_cashback, tier_atual, total_gasto_historico, ultimo_acesso)
                           VALUES (?, ?, 0, 0, 0, 0.00, 'Bronze', 0.00, ?)")
                ->execute([$idLojaFid, $clienteId, $agoraSql]);
        } else {
            $selosAntes = (int)$cliFid['selos_atuais'];
            $ciclosAntes = (int)$cliFid['ciclos_completados'];
            $pontosAntes = (int)$cliFid['pontos_acumulados'];
            $saldoAntes = (float)$cliFid['saldo_cashback'];
            $gastoAntes = (float)$cliFid['total_gasto_historico'];
        }

        $usarResgateClassico = !empty($opcoes['resgatar_cartao_classico']) && $selosAntes >= $metaSelos;
        if ($usarResgateClassico) {
            $selosDepois = max(0, $selosAntes - $metaSelos);
            $ciclosDepois = $ciclosAntes + 1;
            $pdo->prepare("INSERT INTO fidelidade_transacoes (instituicao_id, cliente_id, venda_id, tipo, quantidade, valor_recompensa, descricao, usuario_operador, data_transacao)
                           VALUES (?, ?, ?, 'resgate_recompensa', ?, ?, ?, ?, ?)")
                ->execute([$idLojaFid, $clienteId, $vendaId, $metaSelos, $descontoClassico, "Resgate cartão fidelidade no PDV (Venda #{$vendaId})", $operador, $agoraSql]);
        } else {
            // Não soma infinito quando já atingiu a meta se não estiver resgatando; caso contrário soma +1 selo
            $selosDepois = ($selosAntes >= $metaSelos) ? $selosAntes : ($selosAntes + 1);
            $ciclosDepois = $ciclosAntes;
            if ($selosDepois > $selosAntes) {
                $pdo->prepare("INSERT INTO fidelidade_transacoes (instituicao_id, cliente_id, venda_id, tipo, quantidade, valor_recompensa, descricao, usuario_operador, data_transacao)
                               VALUES (?, ?, ?, 'acumulo_selo', 1, 0, ?, ?, ?)")
                    ->execute([$idLojaFid, $clienteId, $vendaId, "Selo #{$selosDepois} de {$metaSelos} (Venda PDV #{$vendaId})", $operador, $agoraSql]);
            }
        }

        $novoGasto = round($gastoAntes + $valorPagoLiquido, 2);
        $novosPontos = $pontosAntes + $pontosGerados;
        $novoSaldoCashback = max(0.0, round($saldoAntes - $cashbackUtilizado + $cashbackGerado, 2));
        $novoTier = crmCalcularTierCliente($novoGasto, $cfg);

        $pdo->prepare("UPDATE cliente_fidelidade
                       SET selos_atuais = ?, ciclos_completados = ?, pontos_acumulados = ?, saldo_cashback = ?, tier_atual = ?, total_gasto_historico = ?, ultimo_acesso = ?
                       WHERE instituicao_id = ? AND cliente_id = ?")
            ->execute([$selosDepois, $ciclosDepois, $novosPontos, $novoSaldoCashback, $novoTier, $novoGasto, $agoraSql, $idLojaFid, $clienteId]);

        if ($cashbackUtilizado > 0) {
            $pdo->prepare("INSERT INTO fidelidade_transacoes (instituicao_id, cliente_id, venda_id, tipo, quantidade, valor_recompensa, descricao, usuario_operador, data_transacao)
                           VALUES (?, ?, ?, 'resgate_cashback', 1, ?, ?, ?, ?)")
                ->execute([$idLojaFid, $clienteId, $vendaId, $cashbackUtilizado, "Abatimento com carteira Cashback na venda #{$vendaId}", $operador, $agoraSql]);
        }

        // 3. Executa e atualiza estado das Regras Modulares (respeitando política de reentrada)
        $resumoRegrasModulares = [];
        foreach ($eval['regras_modulares'] as $rEval) {
            $regraId = (int)$rEval['id'];
            $stExiste = $pdo->prepare("SELECT id FROM cliente_progresso_regras WHERE cliente_id = ? AND regra_id = ?");
            $stExiste->execute([$clienteId, $regraId]);
            $progId = $stExiste->fetchColumn();

            $ultimoResgateSql = $rEval['completa_ou_resgata'] ? $agoraSql : null;

            if (!$progId) {
                $pdo->prepare("INSERT INTO cliente_progresso_regras
                    (instituicao_id, cliente_id, regra_id, progresso_atual, ciclos_completados, recompensa_disponivel, ultimo_uso, ultimo_resgate_em, ultima_venda_id, bloqueado_reentrada, observacao_status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([
                        $idLojaFid,
                        $clienteId,
                        $regraId,
                        $rEval['progresso_depois'],
                        $rEval['ciclos_depois'],
                        $rEval['recompensa_disponivel_depois'],
                        $agoraSql,
                        $ultimoResgateSql,
                        $vendaId,
                        $rEval['bloqueado_depois'],
                        $rEval['acao_venda']
                    ]);
            } else {
                $sqlUpProg = "UPDATE cliente_progresso_regras
                              SET progresso_atual = ?, ciclos_completados = ?, recompensa_disponivel = ?,
                                  ultimo_uso = ?, ultima_venda_id = ?, bloqueado_reentrada = ?, observacao_status = ?";
                $paramsUp = [
                    $rEval['progresso_depois'],
                    $rEval['ciclos_depois'],
                    $rEval['recompensa_disponivel_depois'],
                    $agoraSql,
                    $vendaId,
                    $rEval['bloqueado_depois'],
                    $rEval['acao_venda']
                ];
                if ($rEval['completa_ou_resgata']) {
                    $sqlUpProg .= ", ultimo_resgate_em = ?";
                    $paramsUp[] = $agoraSql;
                }
                $sqlUpProg .= " WHERE id = ?";
                $paramsUp[] = $progId;
                $pdo->prepare($sqlUpProg)->execute($paramsUp);
            }

            // Incrementa contador global de conclusões e grava transação detalhada
            if ($rEval['completa_ou_resgata']) {
                $pdo->prepare("UPDATE fidelidade_regras SET usos_globais_atuais = usos_globais_atuais + 1 WHERE id = ?")
                    ->execute([$regraId]);

                $tipoTrans = ($rEval['tipo_mecanica'] === 'pontos')
                    ? 'acumulo_pontos'
                    : (($rEval['cashback_gerado'] > 0) ? 'bonus_acelerador'
                        : (($rEval['acao_venda'] === 'aplicar_imediato') ? 'desconto_direto' : 'resgate_recompensa'));
                $valTrans = max((float)$rEval['desconto_aplicado'], (float)$rEval['cashback_gerado']);
                $qtdTrans = $rEval['pontos_gerados'] > 0 ? $rEval['pontos_gerados'] : $rEval['delta_progresso'];

                $pdo->prepare("INSERT INTO fidelidade_transacoes (instituicao_id, cliente_id, regra_id, venda_id, tipo, quantidade, valor_recompensa, descricao, usuario_operador, data_transacao)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([
                        $idLojaFid,
                        $clienteId,
                        $regraId,
                        $vendaId,
                        $tipoTrans,
                        $qtdTrans,
                        $valTrans,
                        "Regra \"{$rEval['nome_regra']}\" ({$rEval['acao_venda']}) na venda #{$vendaId}",
                        $operador,
                        $agoraSql
                    ]);
            } else {
                $pdo->prepare("INSERT INTO fidelidade_transacoes (instituicao_id, cliente_id, regra_id, venda_id, tipo, quantidade, valor_recompensa, descricao, usuario_operador, data_transacao)
                               VALUES (?, ?, ?, ?, 'acumulo_selo', ?, 0, ?, ?, ?)")
                    ->execute([
                        $idLojaFid,
                        $clienteId,
                        $regraId,
                        $vendaId,
                        $rEval['delta_progresso'],
                        "Progresso {$rEval['progresso_depois']}/{$rEval['meta']} na regra \"{$rEval['nome_regra']}\" (Venda #{$vendaId})",
                        $operador,
                        $agoraSql
                    ]);
            }

            $resumoRegrasModulares[] = $rEval;
        }

        return [
            'participou'             => true,
            'selos_atuais'           => $selosDepois,
            'meta_selos'             => $metaSelos,
            'recompensa_desbloqueada'=> ($selosDepois >= $metaSelos) || count(array_filter($resumoRegrasModulares, fn($x) => !empty($x['recompensa_disponivel_depois']) || !empty($x['completa_ou_resgata']))) > 0,
            'pontos_acumulados'      => $novosPontos,
            'saldo_cashback'         => $novoSaldoCashback,
            'tier_atual'             => $novoTier,
            'regras_processadas'     => $resumoRegrasModulares,
            'regras_bloqueadas'      => $eval['regras_bloqueadas'],
            'promos_bloqueadas'      => $eval['promocoes']['bloqueadas'],
        ];
    }
}

if (!function_exists('crmResgatarRegraModularBalcao')) {
    /**
     * Executa o resgate manual no balcão (ou via API) de uma regra modular que atingiu recompensa_disponivel = 1,
     * aplicando a política de reentrada para reiniciar ou encerrar o ciclo do cliente.
     */
    function crmResgatarRegraModularBalcao(PDO $pdo, int $idLojaFid, int $clienteId, int $regraId, string $operador, ?int $agoraTs = null): array {
        $agoraSql = date('Y-m-d H:i:s', $agoraTs ?? time());
        $stR = $pdo->prepare("SELECT * FROM fidelidade_regras WHERE id = ? AND instituicao_id = ?");
        $stR->execute([$regraId, $idLojaFid]);
        $regra = $stR->fetch(PDO::FETCH_ASSOC);
        if (!$regra) {
            return ['success' => false, 'message' => 'Regra não encontrada nesta loja.'];
        }

        $stP = $pdo->prepare("SELECT * FROM cliente_progresso_regras WHERE cliente_id = ? AND regra_id = ?");
        $stP->execute([$clienteId, $regraId]);
        $prog = $stP->fetch(PDO::FETCH_ASSOC);
        if (!$prog || (empty($prog['recompensa_disponivel']) && (int)$prog['progresso_atual'] < (int)$regra['condicao_valor'])) {
            return ['success' => false, 'message' => 'Cliente ainda não atingiu a meta desta regra para resgate.'];
        }

        $politica = $regra['politica_reentrada'] ?? 'reiniciar_apos_resgate';
        $limiteCiclos = crmLimitePositivo($regra['limite_ciclos_cliente'] ?? null);
        $ciclosAntes = (int)$prog['ciclos_completados'];
        if ($limiteCiclos !== null && $ciclosAntes >= $limiteCiclos) {
            return ['success' => false, 'message' => 'Limite de ciclos deste cliente já foi atingido nesta campanha.'];
        }

        $meta = max(1, (int)$regra['condicao_valor']);
        $acumularSobra = (!isset($regra['acumular_sobra']) || (int)$regra['acumular_sobra'] === 1) && ($politica !== 'cooldown_dias');
        $sobra = $acumularSobra ? max(0, (int)$prog['progresso_atual'] - $meta) : 0;
        $novosCiclos = $ciclosAntes + 1;
        $bloquear = ($politica === 'unica_vez' || ($limiteCiclos !== null && $novosCiclos >= $limiteCiclos)) ? 1 : 0;
        if ($bloquear) {
            $sobra = 0;
        }

        $pdo->prepare("UPDATE cliente_progresso_regras
                       SET progresso_atual = ?, ciclos_completados = ?, recompensa_disponivel = 0,
                           ultimo_resgate_em = ?, bloqueado_reentrada = ?, observacao_status = ?
                       WHERE id = ?")
            ->execute([$sobra, $novosCiclos, $agoraSql, $bloquear, $bloquear ? 'encerrada_apos_resgate' : 'reiniciada_apos_resgate', $prog['id']]);

        $pdo->prepare("UPDATE fidelidade_regras SET usos_globais_atuais = usos_globais_atuais + 1 WHERE id = ?")
            ->execute([$regraId]);

        $valorRef = (float)($regra['recompensa_valor'] ?? 0);
        $pdo->prepare("INSERT INTO fidelidade_transacoes (instituicao_id, cliente_id, regra_id, tipo, quantidade, valor_recompensa, descricao, usuario_operador, data_transacao)
                       VALUES (?, ?, ?, 'resgate_recompensa', ?, ?, ?, ?, ?)")
            ->execute([
                $idLojaFid,
                $clienteId,
                $regraId,
                $meta,
                $valorRef,
                "Resgate manual da regra \"{$regra['nome_regra']}\" ({$novosCiclos}º ciclo)",
                $operador,
                $agoraSql
            ]);

        $msgReinicio = $bloquear
            ? 'Ciclo único concluído — cliente não iniciará novo ciclo nesta regra.'
            : ($politica === 'cooldown_dias' && (int)$regra['cooldown_dias'] > 0
                ? "Ciclo encerrado! Novo ciclo liberado após carência de {$regra['cooldown_dias']} dias."
                : "Ciclo concluído! Próxima compra elegível iniciará o " . ($novosCiclos + 1) . "º ciclo ({$sobra} de sobra).");

        return [
            'success'             => true,
            'message'             => "Recompensa resgatada com sucesso! {$msgReinicio}",
            'progresso_atual'     => $sobra,
            'ciclos_completados'  => $novosCiclos,
            'bloqueado_reentrada' => (bool)$bloquear,
        ];
    }
}
