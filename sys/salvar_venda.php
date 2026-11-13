<?php
// --- 1. BLINDAGEM CONTRA ERROS DE RESPOSTA ---
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');
session_start();

function responderErro($mensagem) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $mensagem]);
    exit;
}

try {
    require 'conexao.php';
    require_once __DIR__ . '/includes/unidades.php';
    require_once __DIR__ . '/includes/motor_fidelidade.php';

    // 2. Segurança: Verifica se está logado
    if (!isset($_SESSION['instituicao_id'])) {
        responderErro('Sessão expirada. Faça login novamente.');
    }

    $id_loja = (int)$_SESSION['instituicao_id'];
    $id_loja_fid = obterInstituicaoFidelidade($pdo, $id_loja);
    $ids_clientes = obterInstituicoesClientes($pdo, $id_loja);

    // Pega os dados
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        responderErro('Dados inválidos recebidos do navegador.');
    }

    if (empty($data['itens']) || !is_array($data['itens'])) {
        responderErro('O carrinho está vazio.');
    }

    $pdo->beginTransaction();

    // 3. Resolve cliente ANTES da venda para que Promoções, Regras Modulares e CRM de Reposição operem juntos
    $cliente_id = !empty($data['cliente_id']) ? (int)$data['cliente_id'] : null;
    $participar_fidelidade = !empty($data['participar_fidelidade']);
    $cliente_whatsapp = trim($data['cliente_whatsapp'] ?? '');
    $cliente_nome_input = trim($data['cliente_nome'] ?? '');

    $in_cli = implode(',', array_fill(0, count($ids_clientes), '?'));

    if (!$cliente_id && !empty($cliente_whatsapp)) {
        $digitsPhone = preg_replace('/\D/', '', $cliente_whatsapp);
        $last8 = substr($digitsPhone, -8);

        if (strlen($last8) >= 8) {
            $stBusca = $pdo->prepare("SELECT id, nome_completo, telefone FROM clientes WHERE instituicao_id IN ($in_cli) AND REPLACE(REPLACE(REPLACE(REPLACE(telefone, '(', ''), ')', ''), '-', ''), ' ', '') LIKE ? LIMIT 1");
            $stBusca->execute([...$ids_clientes, "%$last8%"]);
            $cliExistente = $stBusca->fetch(PDO::FETCH_ASSOC);

            if ($cliExistente) {
                $cliente_id = (int)$cliExistente['id'];
            }
        }

        if (!$cliente_id && $participar_fidelidade) {
            $nomeFinal = !empty($cliente_nome_input) ? $cliente_nome_input : 'Cliente Balcão VIP';
            $stNovoCli = $pdo->prepare("INSERT INTO clientes (instituicao_id, nome_completo, telefone, data_cadastro) VALUES (?, ?, ?, NOW())");
            $stNovoCli->execute([$id_loja, $nomeFinal, $cliente_whatsapp]);
            $cliente_id = (int)$pdo->lastInsertId();
        }
    } elseif ($cliente_id && !empty($cliente_whatsapp)) {
        $pdo->prepare("UPDATE clientes SET telefone = ? WHERE id = ? AND instituicao_id IN ($in_cli) AND (telefone IS NULL OR telefone = '')")
            ->execute([$cliente_whatsapp, $cliente_id, ...$ids_clientes]);
    }

    // 4. Avalia Promoções Relâmpago + Regras Modulares com a política de reentrada
    $desconto_manual = max(0.0, (float)($data['desconto'] ?? 0));
    $opcoesCrm = isset($data['opcoes_crm']) && is_array($data['opcoes_crm']) ? $data['opcoes_crm'] : [];
    if (isset($data['agora_ts'])) {
        $opcoesCrm['agora_ts'] = (int)$data['agora_ts'];
    }

    $itensNorm = crmNormalizarCarrinho($data['itens']);
    if ($participar_fidelidade) {
        $eval = crmAvaliarCarrinhoCompleto($pdo, $id_loja, $id_loja_fid, $ids_clientes, $data['itens'], $cliente_id, $desconto_manual, $opcoesCrm);
    } else {
        // Sem participação em fidelidade: ainda aplica promoções relâmpago válidas no produto
        $agoraTs = isset($opcoesCrm['agora_ts']) ? (int)$opcoesCrm['agora_ts'] : time();
        $promoEval = crmAvaliarPromocoesCarrinho($pdo, $id_loja, $itensNorm, $cliente_id, $agoraTs);
        $subtotalBruto = 0.0;
        foreach ($itensNorm as $it) {
            $subtotalBruto += $it['subtotal'];
        }
        $subtotalBruto = round($subtotalBruto, 2);
        $descPromo = min($subtotalBruto, $promoEval['total_desconto_promocao']);
        $descTotal = round($descPromo + $desconto_manual, 2);
        $eval = [
            'subtotal_bruto'      => $subtotalBruto,
            'desconto_promocao'   => round($descPromo, 2),
            'desconto_fidelidade' => 0.0,
            'desconto_classico'   => 0.0,
            'cashback_utilizado'  => 0.0,
            'desconto_total'      => $descTotal,
            'total_liquido'       => max(0.0, round($subtotalBruto - $descTotal, 2)),
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

    $valor_total = (float)$eval['total_liquido'];
    $desconto_total = (float)$eval['desconto_total'];

    // 5. Salva a Venda (Cabeçalho) com detalhamento real dos descontos e benefícios
    $sql_venda = "INSERT INTO vendas (instituicao_id, cliente_id, valor_total, desconto, desconto_fidelidade, desconto_promocao, cashback_gerado, cashback_utilizado, pontos_gerados, forma_pagamento, data_venda)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmt = $pdo->prepare($sql_venda);
    $stmt->execute([
        $id_loja,
        $cliente_id,
        $valor_total,
        $desconto_total,
        $eval['desconto_fidelidade'],
        $eval['desconto_promocao'],
        $eval['cashback_gerado'],
        $eval['cashback_utilizado'],
        $eval['pontos_gerados'],
        $data['forma_pagamento'] ?? 'Dinheiro'
    ]);
    $venda_id = (int)$pdo->lastInsertId();

    // 6. Salva Itens, Baixa Estoque e Atualiza Esteira de Reposição Nutricional
    $sql_item = "INSERT INTO venda_itens (venda_id, produto_id, servico_id, nome_produto, quantidade, preco_unitario, subtotal)
                 VALUES (?, ?, ?, ?, ?, ?, ?)";
    $sql_estoque = "UPDATE produtos SET quantidade_estoque = quantidade_estoque - ? WHERE id = ? AND instituicao_id = ?";

    $stmt_item = $pdo->prepare($sql_item);
    $stmt_estoque = $pdo->prepare($sql_estoque);

    $pet_id_explicito = !empty($data['pet_id']) ? (int)$data['pet_id'] : null;

    foreach ($itensNorm as $item) {
        $produto_id = ($item['tipo'] === 'produto' && $item['catalogo_id']) ? (int)$item['catalogo_id'] : null;
        $servico_id = ($item['tipo'] === 'servico' && $item['catalogo_id']) ? (int)$item['catalogo_id'] : null;

        $stmt_item->execute([
            $venda_id,
            $produto_id,
            $servico_id,
            $item['nome'],
            $item['qtd'],
            $item['preco'],
            $item['subtotal']
        ]);

        if ($produto_id) {
            $stmt_estoque->execute([$item['qtd'], $produto_id, $id_loja]);

            // Esteira do CRM Preditivo (Ração) — agora funciona também para cliente recém-identificado via WhatsApp
            if ($cliente_id) {
                $stmt_check = $pdo->prepare("SELECT tamanho_embalagem_kg FROM produtos WHERE id = ?");
                $stmt_check->execute([$produto_id]);
                $prod_info = $stmt_check->fetch(PDO::FETCH_ASSOC);

                if ($prod_info && !empty($prod_info['tamanho_embalagem_kg'])) {
                    $pet_id_venda = null;
                    if ($pet_id_explicito) {
                        $stPetExp = $pdo->prepare("SELECT id FROM pets WHERE id = ? AND cliente_id = ? LIMIT 1");
                        $stPetExp->execute([$pet_id_explicito, $cliente_id]);
                        $pet_id_venda = $stPetExp->fetchColumn() ?: null;
                    }
                    if (!$pet_id_venda) {
                        $stmt_pet = $pdo->prepare("SELECT id FROM pets WHERE cliente_id = ? ORDER BY id ASC LIMIT 1");
                        $stmt_pet->execute([$cliente_id]);
                        $pet = $stmt_pet->fetch(PDO::FETCH_ASSOC);
                        $pet_id_venda = $pet ? (int)$pet['id'] : null;
                    }

                    if ($pet_id_venda) {
                        $pdo->prepare("UPDATE oportunidades_venda SET status = 'Venda Fechada' WHERE pet_id = ? AND status = 'Pendente'")
                            ->execute([$pet_id_venda]);

                        $sql_crm = "INSERT INTO oportunidades_venda (instituicao_id, cliente_id, pet_id, produto_id, produto_nome, data_compra, status)
                                    VALUES (?, ?, ?, ?, ?, NOW(), 'Pendente')";
                        $pdo->prepare($sql_crm)->execute([
                            $id_loja, $cliente_id, $pet_id_venda, $produto_id, $item['nome']
                        ]);
                    }
                }
            }
        }
    }

    // 7. Persiste execução do Motor de Fidelidade & Promoções
    $operador = $_SESSION['usuario_nome'] ?? 'Caixa';
    $opcoesCrm['participar_fidelidade'] = $participar_fidelidade;
    $execResult = crmExecutarBeneficiosVenda($pdo, $id_loja, $id_loja_fid, $venda_id, $cliente_id, $eval, $operador, $opcoesCrm);

    $fidelidadeInfo = ['participou' => false];
    if ($participar_fidelidade && $cliente_id && !empty($execResult['participou'])) {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $cartao_url = "{$proto}://{$host}/landing_page/sys/cartao_cliente.php?cliente_id={$cliente_id}&loja_id={$id_loja_fid}";

        $stLojaInfo = $pdo->prepare("SELECT nome_fantasia FROM instituicoes WHERE id = ?");
        $stLojaInfo->execute([$id_loja]);
        $nomeLoja = $stLojaInfo->fetchColumn() ?: 'Pet Shop & Veterinária';

        $stCliInfo = $pdo->prepare("SELECT nome_completo, telefone FROM clientes WHERE id = ?");
        $stCliInfo->execute([$cliente_id]);
        $cliRow = $stCliInfo->fetch(PDO::FETCH_ASSOC);
        $nomeCliente = $cliRow['nome_completo'] ?? 'Cliente';
        $telefoneEnvio = !empty($cliente_whatsapp) ? $cliente_whatsapp : ($cliRow['telefone'] ?? '');

        $itensTexto = "";
        foreach ($itensNorm as $it) {
            $sub = number_format($it['subtotal'], 2, ',', '.');
            $itensTexto .= "• {$it['qtd']}x {$it['nome']} - R$ {$sub}\n";
        }

        $dataHora = date('d/m/Y H:i');
        $totalFormatado = number_format($valor_total, 2, ',', '.');
        $formaPagto = $data['forma_pagamento'] ?? 'Balcão';
        $selosAtuais = (int)$execResult['selos_atuais'];
        $metaSelos = (int)$execResult['meta_selos'];
        $recompensaDesbloqueada = !empty($execResult['recompensa_desbloqueada']);

        $msg = "🐾 *{$nomeLoja}* 🐾\n";
        $msg .= "Olá, *{$nomeCliente}*! Obrigado por sua compra!\n\n";
        $msg .= "🧾 *COMPROVANTE DE PAGAMENTO* (Venda #{$venda_id})\n";
        $msg .= "📅 *Data:* {$dataHora}\n";
        $msg .= "💳 *Forma de Pagamento:* {$formaPagto}\n";
        $msg .= "----------------------------------------\n";
        $msg .= $itensTexto;
        $msg .= "----------------------------------------\n";
        if ($desconto_total > 0) {
            $descFmt = number_format($desconto_total, 2, ',', '.');
            $msg .= "🏷️ *Descontos Aplicados:* -R$ {$descFmt}\n";
        }
        $msg .= "💰 *TOTAL PAGO: R$ {$totalFormatado}*\n\n";
        $msg .= "⭐ *SEU CARTÃO FIDELIDADE PET VIP* ⭐\n";
        $msg .= "📊 *Progresso Base:* {$selosAtuais} de {$metaSelos} selos\n";
        if ((float)$eval['cashback_gerado'] > 0) {
            $cbFmt = number_format((float)$eval['cashback_gerado'], 2, ',', '.');
            $saldoFmt = number_format((float)$execResult['saldo_cashback'], 2, ',', '.');
            $msg .= "💰 *Cashback Ganho:* +R$ {$cbFmt} (Saldo: R$ {$saldoFmt})\n";
        }
        if ((int)$eval['pontos_gerados'] > 0) {
            $msg .= "⭐ *Pontos Ganhos:* +{$eval['pontos_gerados']} pts (Total: {$execResult['pontos_acumulados']} pts)\n";
        }

        if ($recompensaDesbloqueada) {
            $msg .= "🎁 *PARABÉNS!* Você possui recompensa desbloqueada no seu cartão!\n";
        } else {
            $restam = max(0, $metaSelos - $selosAtuais);
            $msg .= "🎯 Faltam {$restam} selo(s) para sua próxima recompensa padrão!\n";
        }

        $msg .= "\n📲 *Acesse seu cartão virtual em tempo real pelo link:*\n";
        $msg .= "👉 {$cartao_url}\n\n";
        $msg .= "Agradecemos o carinho e a confiança! 🐶🐱✨";

        $cleanPhone = preg_replace('/\D/', '', $telefoneEnvio);
        if (!empty($cleanPhone) && strlen($cleanPhone) <= 11 && substr($cleanPhone, 0, 2) !== '55') {
            $cleanPhone = '55' . $cleanPhone;
        }
        $whatsapp_url = !empty($cleanPhone) ? "https://api.whatsapp.com/send?phone={$cleanPhone}&text=" . rawurlencode($msg) : "";

        $fidelidadeInfo = [
            'participou'             => true,
            'cliente_id'             => $cliente_id,
            'cliente_nome'           => $nomeCliente,
            'telefone'               => $cleanPhone,
            'whatsapp'               => $cleanPhone,
            'selos_atuais'           => $selosAtuais,
            'meta_selos'             => $metaSelos,
            'recompensa_desbloqueada'=> $recompensaDesbloqueada,
            'pontos_acumulados'      => $execResult['pontos_acumulados'],
            'saldo_cashback'         => $execResult['saldo_cashback'],
            'tier_atual'             => $execResult['tier_atual'],
            'regras_processadas'     => $execResult['regras_processadas'],
            'regras_bloqueadas'      => $execResult['regras_bloqueadas'],
            'promos_bloqueadas'      => $execResult['promos_bloqueadas'],
            'cartao_url'             => $cartao_url,
            'whatsapp_url'           => $whatsapp_url,
            'whatsapp_texto'         => $msg,
        ];
    }

    $pdo->commit();

    ob_clean();
    echo json_encode([
        'success'            => true,
        'message'            => 'Venda realizada com sucesso!',
        'venda_id'           => $venda_id,
        'subtotal_bruto'     => $eval['subtotal_bruto'],
        'desconto_promocao'  => $eval['desconto_promocao'],
        'desconto_fidelidade'=> $eval['desconto_fidelidade'],
        'desconto_total'     => $desconto_total,
        'valor_total_pago'   => $valor_total,
        'promocoes'          => $eval['promocoes'],
        'regras_bloqueadas'  => $eval['regras_bloqueadas'],
        'fidelidade'         => $fidelidadeInfo,
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responderErro('Erro interno no servidor: ' . $e->getMessage());
}
?>
