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

    // 2. Segurança: Verifica se está logado
    if (!isset($_SESSION['instituicao_id'])) {
        responderErro('Sessão expirada. Faça login novamente.');
    }

    $id_loja = $_SESSION['instituicao_id'];
    
    // Pega os dados
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        responderErro('Dados inválidos recebidos do navegador.');
    }

    if (empty($data['itens'])) {
        responderErro('O carrinho está vazio.');
    }

    $pdo->beginTransaction();

    // 3. Salva a Venda (Cabeçalho) - CORRIGIDO: valor_total
    $sql_venda = "INSERT INTO vendas (instituicao_id, cliente_id, valor_total, forma_pagamento, data_venda) 
                  VALUES (?, ?, ?, ?, NOW())";
    
    $stmt = $pdo->prepare($sql_venda);
    
    $cliente_id = !empty($data['cliente_id']) ? $data['cliente_id'] : null;
    $valor_total = !empty($data['total_venda']) ? $data['total_venda'] : 0;
    
    $stmt->execute([
        $id_loja,
        $cliente_id,
        $valor_total,
        $data['forma_pagamento']
    ]);
    
    $venda_id = $pdo->lastInsertId();

    // 4. Salva os Itens (CORRIGIDO para venda_itens) e Baixa Estoque
    $sql_item = "INSERT INTO venda_itens (venda_id, produto_id, nome_produto, quantidade, preco_unitario, subtotal)
                 VALUES (?, ?, ?, ?, ?, ?)";
    
    // Query para baixar estoque (CORRIGIDO: quantidade_estoque)
    $sql_estoque = "UPDATE produtos SET quantidade_estoque = quantidade_estoque - ? WHERE id = ? AND instituicao_id = ?";
    
    $stmt_item = $pdo->prepare($sql_item);
    $stmt_estoque = $pdo->prepare($sql_estoque);

    foreach ($data['itens'] as $item) {
        $produto_id = ($item['id'] > 0) ? $item['id'] : null;
        $qtd = $item['qtd'];
        $preco = $item['preco'];
        $subtotal = $qtd * $preco;

        // Salva o item no carrinho
        $stmt_item->execute([
            $venda_id,
            $produto_id,
            $item['nome'],
            $qtd,
            $preco,
            $subtotal
        ]);

        // Se for um produto do banco, baixa o estoque e analisa para o CRM
        if ($produto_id) {
            $stmt_estoque->execute([$qtd, $produto_id, $id_loja]);

            // =================================================================
            // INTELIGÊNCIA DO CRM: Detecta se é ração e lança na esteira
            // =================================================================
            if ($cliente_id) {
                // Verifica se o produto tem dados nutricionais (Ração)
                $stmt_check = $pdo->prepare("SELECT tamanho_embalagem_kg FROM produtos WHERE id = ?");
                $stmt_check->execute([$produto_id]);
                $prod_info = $stmt_check->fetch(PDO::FETCH_ASSOC);

                if ($prod_info && !empty($prod_info['tamanho_embalagem_kg'])) {
                    
                    // Pega o primeiro pet do cliente para associar à ração
                    $stmt_pet = $pdo->prepare("SELECT id FROM pets WHERE cliente_id = ? LIMIT 1");
                    $stmt_pet->execute([$cliente_id]);
                    $pet = $stmt_pet->fetch(PDO::FETCH_ASSOC);
                    $pet_id_venda = $pet ? $pet['id'] : null;

                    // Lança a oportunidade como Pendente no CRM
                    if ($pet_id_venda) {
                        $sql_crm = "INSERT INTO oportunidades_venda (instituicao_id, cliente_id, pet_id, produto_id, produto_nome, data_compra, status)
                                    VALUES (?, ?, ?, ?, ?, NOW(), 'Pendente')";
                        $pdo->prepare($sql_crm)->execute([
                            $id_loja, $cliente_id, $pet_id_venda, $produto_id, $item['nome']
                        ]);
                    }
                }
            }
            // =================================================================
        }
    }

    // =================================================================
    // 5. MOTOR DE FIDELIDADE AUTOMATIZADO COM WHATSAPP & COMPROVANTE
    // =================================================================
    $fidelidadeInfo = ['participou' => false];
    $participar_fidelidade = !empty($data['participar_fidelidade']);
    $cliente_whatsapp = trim($data['cliente_whatsapp'] ?? '');
    $cliente_nome_input = trim($data['cliente_nome'] ?? '');

    if ($participar_fidelidade && (!empty($cliente_whatsapp) || !empty($cliente_id))) {
        // Se cliente ainda não estava cadastrado/selecionado, busca pelo WhatsApp ou cria cliente rápido
        if (!$cliente_id && !empty($cliente_whatsapp)) {
            require_once __DIR__ . '/includes/unidades.php';
            $ids_clientes = obterInstituicoesClientes($pdo, (int)$id_loja);
            $in_cli = implode(',', array_fill(0, count($ids_clientes), '?'));

            $digitsPhone = preg_replace('/\D/', '', $cliente_whatsapp);
            $last8 = substr($digitsPhone, -8);
            
            $stBusca = $pdo->prepare("SELECT id, nome_completo, telefone FROM clientes WHERE instituicao_id IN ($in_cli) AND REPLACE(REPLACE(REPLACE(REPLACE(telefone, '(', ''), ')', ''), '-', ''), ' ', '') LIKE ? LIMIT 1");
            $stBusca->execute([...$ids_clientes, "%$last8%"]);
            $cliExistente = $stBusca->fetch(PDO::FETCH_ASSOC);

            if ($cliExistente) {
                $cliente_id = $cliExistente['id'];
            } else {
                $nomeFinal = !empty($cliente_nome_input) ? $cliente_nome_input : 'Cliente Balcão VIP';
                $stNovoCli = $pdo->prepare("INSERT INTO clientes (instituicao_id, nome_completo, telefone, data_cadastro) VALUES (?, ?, ?, NOW())");
                $stNovoCli->execute([$id_loja, $nomeFinal, $cliente_whatsapp]);
                $cliente_id = $pdo->lastInsertId();
            }

            // Atualiza o ID do cliente na venda recém gravada
            $pdo->prepare("UPDATE vendas SET cliente_id = ? WHERE id = ?")->execute([$cliente_id, $venda_id]);
        } elseif ($cliente_id && !empty($cliente_whatsapp)) {
            require_once __DIR__ . '/includes/unidades.php';
            $ids_clientes = obterInstituicoesClientes($pdo, (int)$id_loja);
            $in_cli = implode(',', array_fill(0, count($ids_clientes), '?'));
            // Atualiza telefone se necessário
            $pdo->prepare("UPDATE clientes SET telefone = ? WHERE id = ? AND instituicao_id IN ($in_cli) AND (telefone IS NULL OR telefone = '')")->execute([$cliente_whatsapp, $cliente_id, ...$ids_clientes]);
        }

        if ($cliente_id) {
            $operador = $_SESSION['usuario_nome'] ?? 'Caixa';

            require_once __DIR__ . '/includes/unidades.php';
            $id_loja_fid = obterInstituicaoFidelidade($pdo, (int)$id_loja);

            // 1. Atualiza cartão clássico (cliente_fidelidade)
            $stCliFid = $pdo->prepare("SELECT * FROM cliente_fidelidade WHERE instituicao_id = ? AND cliente_id = ?");
            $stCliFid->execute([$id_loja_fid, $cliente_id]);
            $cliFid = $stCliFid->fetch(PDO::FETCH_ASSOC);

            $stCfg = $pdo->prepare("SELECT * FROM fidelidade_config WHERE instituicao_id = ?");
            $stCfg->execute([$id_loja_fid]);
            $cfg = $stCfg->fetch(PDO::FETCH_ASSOC) ?: [
                'compras_para_recompensa' => 10,
                'recompensa_valor' => 15,
                'recompensa_tipo' => 'desconto_percentual',
                'texto_cartao' => 'Cartão Fidelidade Pet VIP'
            ];

            if (!$cliFid) {
                $pdo->prepare("INSERT INTO cliente_fidelidade (instituicao_id, cliente_id, selos_atuais, total_gasto_historico, ultimo_acesso) VALUES (?, ?, 1, ?, NOW())")
                    ->execute([$id_loja_fid, $cliente_id, $valor_total]);
                $selosAtuais = 1;
                $ciclos = 0;
            } else {
                $selosAtuais = (int)$cliFid['selos_atuais'] + 1;
                $novoGasto = (float)$cliFid['total_gasto_historico'] + $valor_total;
                $ciclos = (int)$cliFid['ciclos_completados'];
                $pdo->prepare("UPDATE cliente_fidelidade SET selos_atuais = ?, total_gasto_historico = ?, ultimo_acesso = NOW() WHERE instituicao_id = ? AND cliente_id = ?")
                    ->execute([$selosAtuais, $novoGasto, $id_loja_fid, $cliente_id]);
            }

            $metaSelos = (int)($cfg['compras_para_recompensa'] ?? 10);
            $recompensaDesbloqueada = ($selosAtuais >= $metaSelos);

            // Log de auditoria
            $pdo->prepare("INSERT INTO fidelidade_transacoes (instituicao_id, cliente_id, tipo, quantidade, descricao, usuario_operador) VALUES (?, ?, 'acumulo_selo', 1, ?, ?)")
                ->execute([$id_loja_fid, $cliente_id, "Selo #$selosAtuais de $metaSelos (Venda PDV #$venda_id)", $operador]);

            // 2. Atualiza regras modulares (cliente_progresso_regras)
            $stRegras = $pdo->prepare("SELECT * FROM fidelidade_regras WHERE instituicao_id = ? AND ativo = 1");
            $stRegras->execute([$id_loja_fid]);
            $regrasAtivas = $stRegras->fetchAll(PDO::FETCH_ASSOC);

            foreach ($regrasAtivas as $regra) {
                $regraBate = false;
                if ($regra['categoria_alvo'] === 'todos') {
                    $regraBate = true;
                } elseif ($regra['categoria_alvo'] === 'servicos') {
                    foreach ($data['itens'] as $it) {
                        if (($it['id'] ?? 0) < 0 || stripos($it['nome'] ?? '', 'Banho') !== false || stripos($it['nome'] ?? '', 'Tosa') !== false || stripos($it['nome'] ?? '', 'Consulta') !== false) {
                            $regraBate = true;
                            break;
                        }
                    }
                } elseif ($regra['categoria_alvo'] === 'produtos') {
                    foreach ($data['itens'] as $it) {
                        if (($it['id'] ?? 0) > 0) {
                            $regraBate = true;
                            break;
                        }
                    }
                } elseif ($regra['categoria_alvo'] === 'item_especifico') {
                    foreach ($data['itens'] as $it) {
                        if (($it['id'] ?? 0) == $regra['item_alvo_id']) {
                            $regraBate = true;
                            break;
                        }
                    }
                }

                if ($regraBate) {
                    $stProg = $pdo->prepare("SELECT * FROM cliente_progresso_regras WHERE cliente_id = ? AND regra_id = ?");
                    $stProg->execute([$cliente_id, $regra['id']]);
                    $prog = $stProg->fetch(PDO::FETCH_ASSOC);

                    if (!$prog) {
                        $novoProg = 1;
                        $ciclosRegra = 0;
                        $recDisp = ($novoProg >= (int)$regra['condicao_valor']) ? 1 : 0;
                        $pdo->prepare("INSERT INTO cliente_progresso_regras (instituicao_id, cliente_id, regra_id, progresso_atual, ciclos_completados, recompensa_disponivel, ultimo_uso) VALUES (?, ?, ?, ?, ?, ?, NOW())")
                            ->execute([$id_loja_fid, $cliente_id, $regra['id'], $novoProg, $ciclosRegra, $recDisp]);
                    } else {
                        $novoProg = (int)$prog['progresso_atual'] + 1;
                        $ciclosRegra = (int)$prog['ciclos_completados'];
                        $recDisp = $prog['recompensa_disponivel'];
                        if ($novoProg >= (int)$regra['condicao_valor']) {
                            $recDisp = 1;
                        }
                        $pdo->prepare("UPDATE cliente_progresso_regras SET progresso_atual = ?, recompensa_disponivel = ?, ultimo_uso = NOW() WHERE id = ?")
                            ->execute([$novoProg, $recDisp, $prog['id']]);
                    }
                }
            }

            // 3. Monta link do Cartão Virtual
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $cartao_url = "{$proto}://{$host}/landing_page/sys/cartao_cliente.php?cliente_id={$cliente_id}&loja_id={$id_loja_fid}";

            // 4. Monta Mensagem Formatada para WhatsApp com Comprovante e Cartão
            $stLojaInfo = $pdo->prepare("SELECT nome_fantasia FROM instituicoes WHERE id = ?");
            $stLojaInfo->execute([$id_loja]);
            $nomeLoja = $stLojaInfo->fetchColumn() ?: 'Pet Shop & Veterinária';

            $stCliInfo = $pdo->prepare("SELECT nome_completo, telefone FROM clientes WHERE id = ?");
            $stCliInfo->execute([$cliente_id]);
            $cliRow = $stCliInfo->fetch(PDO::FETCH_ASSOC);
            $nomeCliente = $cliRow['nome_completo'] ?? 'Cliente';
            $telefoneEnvio = !empty($cliente_whatsapp) ? $cliente_whatsapp : ($cliRow['telefone'] ?? '');

            $itensTexto = "";
            foreach ($data['itens'] as $it) {
                $sub = number_format($it['qtd'] * $it['preco'], 2, ',', '.');
                $itensTexto .= "• {$it['qtd']}x {$it['nome']} - R$ {$sub}\n";
            }

            $dataHora = date('d/m/Y H:i');
            $totalFormatado = number_format($valor_total, 2, ',', '.');
            $formaPagto = $data['forma_pagamento'] ?? 'Balcão';

            $msg = "🐾 *{$nomeLoja}* 🐾\n";
            $msg .= "Olá, *{$nomeCliente}*! Obrigado por sua compra!\n\n";
            $msg .= "🧾 *COMPROVANTE DE PAGAMENTO* (Venda #{$venda_id})\n";
            $msg .= "📅 *Data:* {$dataHora}\n";
            $msg .= "💳 *Forma de Pagamento:* {$formaPagto}\n";
            $msg .= "----------------------------------------\n";
            $msg .= $itensTexto;
            $msg .= "----------------------------------------\n";
            $msg .= "💰 *TOTAL PAGO: R$ {$totalFormatado}*\n\n";
            $msg .= "⭐ *SEU CARTÃO FIDELIDADE PET VIP* ⭐\n";
            $msg .= "🎉 *Você ganhou +1 selo nesta compra!*\n";
            $msg .= "📊 *Progresso Atual:* {$selosAtuais} de {$metaSelos} selos\n";
            
            if ($recompensaDesbloqueada) {
                $msg .= "🎁 *PARABÉNS!* Você completou seu cartão e já pode resgatar seu desconto na próxima visita!\n";
            } else {
                $restam = max(0, $metaSelos - $selosAtuais);
                $msg .= "🎯 Faltam apenas {$restam} selo(s) para sua próxima recompensa!\n";
            }

            $msg .= "\n📲 *Acesse seu cartão virtual em tempo real pelo link:*\n";
            $msg .= "👉 {$cartao_url}\n\n";
            $msg .= "Agradecemos o carinho e a confiança! 🐶🐱✨";

            $cleanPhone = preg_replace('/\D/', '', $telefoneEnvio);
            if (!empty($cleanPhone)) {
                if (strlen($cleanPhone) <= 11 && substr($cleanPhone, 0, 2) !== '55') {
                    $cleanPhone = '55' . $cleanPhone;
                }
            }
            $whatsapp_url = !empty($cleanPhone) ? "https://api.whatsapp.com/send?phone={$cleanPhone}&text=" . rawurlencode($msg) : "";

            $fidelidadeInfo = [
                'participou' => true,
                'cliente_id' => $cliente_id,
                'cliente_nome' => $nomeCliente,
                'telefone' => $cleanPhone,
                'whatsapp' => $cleanPhone,
                'selos_atuais' => $selosAtuais,
                'meta_selos' => $metaSelos,
                'recompensa_desbloqueada' => $recompensaDesbloqueada,
                'cartao_url' => $cartao_url,
                'whatsapp_url' => $whatsapp_url,
                'whatsapp_texto' => $msg
            ];
        }
    }

    $pdo->commit();

    // --- SUCESSO ---
    ob_clean(); 
    echo json_encode([
        'success' => true, 
        'message' => 'Venda realizada com sucesso!', 
        'venda_id' => $venda_id,
        'fidelidade' => $fidelidadeInfo
    ]);

} catch (Throwable $e) { 
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responderErro('Erro interno no servidor.');
}
?>