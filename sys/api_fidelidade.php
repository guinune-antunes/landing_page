<?php
session_start();
header('Content-Type: application/json');
require 'conexao.php';

// Segurança: usuário precisa estar logado
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'message' => 'Acesso negado. Faça login.']);
    exit;
}

$id_loja = $_SESSION['instituicao_id'] ?? 0;
// Se for Admin geral, pega a loja ativa enviada ou a primeira disponível
if ($id_loja == 0 && ($_SESSION['usuario_cargo'] ?? '') === 'Admin') {
    $id_loja = intval($_POST['instituicao_id'] ?? $_GET['instituicao_id'] ?? 0);
    if ($id_loja <= 0) {
        $id_loja = (int)$pdo->query("SELECT id FROM instituicoes ORDER BY id ASC LIMIT 1")->fetchColumn();
    }
}

if ($id_loja <= 0) {
    echo json_encode(['success' => false, 'message' => 'Nenhuma instituição vinculada.']);
    exit;
}

$rawInput = file_get_contents('php://input');
$inputData = json_decode($rawInput, true) ?: [];

$acao = $_POST['acao'] ?? $_GET['acao'] ?? ($inputData['acao'] ?? '');

try {
    // ════════════════════════════════════════════════════════════
    // 0. GESTÃO MODULAR DE REGRAS DE FIDELIDADE & DESCONTOS
    // ════════════════════════════════════════════════════════════

    // LISTAR REGRAS MODULARES DA LOJA
    if ($acao === 'listar_regras') {
        $sql = "SELECT r.*,
                       CASE 
                         WHEN r.categoria_alvo = 'item_especifico' THEN COALESCE(prod.nome_produto, serv.nome_servico, CONCAT('Item #', r.item_alvo_id))
                         ELSE NULL 
                       END as item_alvo_nome,
                       CASE 
                         WHEN r.recompensa_tipo = 'item_gratis' THEN COALESCE(rec_p.nome_produto, rec_s.nome_servico, CONCAT('Item #', r.recompensa_item_id))
                         ELSE NULL 
                       END as recompensa_item_nome,
                       (SELECT COUNT(*) FROM cliente_progresso_regras cpr WHERE cpr.regra_id = r.id) as total_participantes,
                       (SELECT COALESCE(SUM(cpr.ciclos_completados), 0) FROM cliente_progresso_regras cpr WHERE cpr.regra_id = r.id) as total_ciclos
                FROM fidelidade_regras r
                LEFT JOIN produtos prod ON (r.categoria_alvo = 'item_especifico' AND r.item_alvo_id = prod.id)
                LEFT JOIN servicos serv ON (r.categoria_alvo = 'item_especifico' AND r.item_alvo_id = serv.id)
                LEFT JOIN produtos rec_p ON (r.recompensa_tipo = 'item_gratis' AND r.recompensa_item_id = rec_p.id)
                LEFT JOIN servicos rec_s ON (r.recompensa_tipo = 'item_gratis' AND r.recompensa_item_id = rec_s.id)
                WHERE r.instituicao_id = ?
                ORDER BY r.ativo DESC, r.id DESC";
        $st = $pdo->prepare($sql);
        $st->execute([$id_loja]);
        $regras = $st->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'regras' => $regras]);
        exit;
    }

    // SALVAR OU EDITAR REGRA MODULAR
    if ($acao === 'salvar_regra') {
        $params = !empty($inputData) ? $inputData : $_POST;
        $regra_id = intval($params['id'] ?? 0);
        $nome_regra = trim($params['nome_regra'] ?? '');
        $descricao = trim($params['descricao'] ?? '');
        $tipo_mecanica = $params['tipo_mecanica'] ?? 'cartao_selos';
        $categoria_alvo = $params['categoria_alvo'] ?? 'todos';
        $item_alvo_id = !empty($params['item_alvo_id']) ? intval($params['item_alvo_id']) : null;
        $condicao_tipo = $params['condicao_tipo'] ?? 'quantidade_compras';
        $condicao_valor = floatval(str_replace(',', '.', $params['condicao_valor'] ?? '10'));
        
        $dias_semana_ativos = null;
        if (!empty($params['dias_semana_ativos'])) {
            $dias_semana_ativos = is_array($params['dias_semana_ativos']) ? json_encode($params['dias_semana_ativos']) : $params['dias_semana_ativos'];
        }

        $recompensa_tipo = $params['recompensa_tipo'] ?? 'desconto_percentual';
        $recompensa_valor = floatval(str_replace(',', '.', $params['recompensa_valor'] ?? '15'));
        $recompensa_item_id = !empty($params['recompensa_item_id']) ? intval($params['recompensa_item_id']) : null;
        $limite_desconto = !empty($params['limite_desconto_recompensa']) ? floatval(str_replace(',', '.', $params['limite_desconto_recompensa'])) : null;
        
        $cor_hex = $params['cor_hex'] ?? '#1abc9c';
        $cor_secundaria = $params['cor_secundaria'] ?? '#2c3e50';
        $icone = $params['icone'] ?? 'fas fa-paw';
        $texto_cartao = trim($params['texto_cartao'] ?? $nome_regra);
        $ativo = isset($params['ativo']) ? (int)$params['ativo'] : 1;

        if (empty($nome_regra)) {
            echo json_encode(['success' => false, 'message' => 'O nome da regra é obrigatório.']);
            exit;
        }

        if ($regra_id > 0) {
            $stmt = $pdo->prepare("UPDATE fidelidade_regras SET 
                nome_regra = ?, descricao = ?, tipo_mecanica = ?, categoria_alvo = ?, item_alvo_id = ?,
                condicao_tipo = ?, condicao_valor = ?, dias_semana_ativos = ?, recompensa_tipo = ?,
                recompensa_valor = ?, recompensa_item_id = ?, limite_desconto_recompensa = ?,
                cor_hex = ?, cor_secundaria = ?, icone = ?, texto_cartao = ?, ativo = ?
                WHERE id = ? AND instituicao_id = ?");
            $stmt->execute([
                $nome_regra, $descricao, $tipo_mecanica, $categoria_alvo, $item_alvo_id,
                $condicao_tipo, $condicao_valor, $dias_semana_ativos, $recompensa_tipo,
                $recompensa_valor, $recompensa_item_id, $limite_desconto,
                $cor_hex, $cor_secundaria, $icone, $texto_cartao, $ativo,
                $regra_id, $id_loja
            ]);
            $msg = 'Regra de fidelidade atualizada com sucesso!';
        } else {
            $stmt = $pdo->prepare("INSERT INTO fidelidade_regras 
                (instituicao_id, nome_regra, descricao, tipo_mecanica, categoria_alvo, item_alvo_id,
                 condicao_tipo, condicao_valor, dias_semana_ativos, recompensa_tipo,
                 recompensa_valor, recompensa_item_id, limite_desconto_recompensa,
                 cor_hex, cor_secundaria, icone, texto_cartao, ativo)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $id_loja, $nome_regra, $descricao, $tipo_mecanica, $categoria_alvo, $item_alvo_id,
                $condicao_tipo, $condicao_valor, $dias_semana_ativos, $recompensa_tipo,
                $recompensa_valor, $recompensa_item_id, $limite_desconto,
                $cor_hex, $cor_secundaria, $icone, $texto_cartao, $ativo
            ]);
            $regra_id = $pdo->lastInsertId();
            $msg = 'Nova regra de fidelidade criada com sucesso!';
        }

        echo json_encode(['success' => true, 'message' => $msg, 'regra_id' => $regra_id]);
        exit;
    }

    // EXCLUIR REGRA MODULAR
    if ($acao === 'excluir_regra') {
        $params = !empty($inputData) ? $inputData : $_POST;
        $regra_id = intval($params['id'] ?? 0);
        $pdo->prepare("DELETE FROM cliente_progresso_regras WHERE regra_id = ? AND instituicao_id = ?")->execute([$regra_id, $id_loja]);
        $pdo->prepare("DELETE FROM fidelidade_regras WHERE id = ? AND instituicao_id = ?")->execute([$regra_id, $id_loja]);
        echo json_encode(['success' => true, 'message' => 'Regra removida com sucesso!']);
        exit;
    }

    // ALTERAR STATUS DA REGRA
    if ($acao === 'toggle_regra') {
        $params = !empty($inputData) ? $inputData : $_POST;
        $regra_id = intval($params['id'] ?? 0);
        $pdo->prepare("UPDATE fidelidade_regras SET ativo = 1 - ativo WHERE id = ? AND instituicao_id = ?")->execute([$regra_id, $id_loja]);
        echo json_encode(['success' => true, 'message' => 'Status da regra alterado!']);
        exit;
    }

    // VERIFICAR REGRAS APLICÁVEIS AO CARRINHO (AUTOMATIZAÇÃO DO BALCÃO / PDV)
    if ($acao === 'verificar_regras_carrinho') {
        $params = !empty($inputData) ? $inputData : $_POST;
        $itens = $params['itens'] ?? [];
        $cliente_id = !empty($params['cliente_id']) ? intval($params['cliente_id']) : null;
        $total_venda = floatval($params['total_venda'] ?? 0);

        // Busca regras modulares ativas
        $stR = $pdo->prepare("SELECT * FROM fidelidade_regras WHERE instituicao_id = ? AND ativo = 1 ORDER BY id ASC");
        $stR->execute([$id_loja]);
        $regras = $stR->fetchAll(PDO::FETCH_ASSOC);

        $diasMap = ['Sun'=>'dom', 'Mon'=>'seg', 'Tue'=>'ter', 'Wed'=>'qua', 'Thu'=>'qui', 'Fri'=>'sex', 'Sat'=>'sab'];
        $hojeDia = $diasMap[date('D')];

        // Busca se é aniversário do cliente
        $aniversario_este_mes = false;
        $cliente_info = null;
        if ($cliente_id > 0) {
            $stC = $pdo->prepare("SELECT id, nome_completo, telefone, data_nascimento FROM clientes WHERE id = ? AND instituicao_id = ?");
            $stC->execute([$cliente_id, $id_loja]);
            $cliente_info = $stC->fetch(PDO::FETCH_ASSOC);
            if ($cliente_info && !empty($cliente_info['data_nascimento'])) {
                $mesNasc = date('m', strtotime($cliente_info['data_nascimento']));
                if ($mesNasc === date('m')) {
                    $aniversario_este_mes = true;
                }
            }
        }

        $regras_elegiveis = [];

        foreach ($regras as $r) {
            $elegivel = false;

            if ($r['categoria_alvo'] === 'todos') {
                $elegivel = count($itens) > 0;
            } elseif ($r['categoria_alvo'] === 'servicos') {
                foreach ($itens as $it) {
                    if (($it['id'] ?? 0) < 0 || stripos($it['nome'] ?? '', 'Banho') !== false || stripos($it['nome'] ?? '', 'Tosa') !== false || stripos($it['nome'] ?? '', 'Consulta') !== false) {
                        $elegivel = true;
                        break;
                    }
                }
            } elseif ($r['categoria_alvo'] === 'produtos') {
                foreach ($itens as $it) {
                    if (($it['id'] ?? 0) > 0) {
                        $elegivel = true;
                        break;
                    }
                }
            } elseif ($r['categoria_alvo'] === 'item_especifico') {
                foreach ($itens as $it) {
                    if (($it['id'] ?? 0) == $r['item_alvo_id']) {
                        $elegivel = true;
                        break;
                    }
                }
            }

            if ($elegivel) {
                if ($r['condicao_tipo'] === 'dia_semana' && !empty($r['dias_semana_ativos'])) {
                    $diasAtivos = json_decode($r['dias_semana_ativos'], true) ?: [];
                    if (!in_array($hojeDia, $diasAtivos)) {
                        $elegivel = false;
                    }
                } elseif ($r['condicao_tipo'] === 'valor_minimo') {
                    if ($total_venda < floatval($r['condicao_valor'])) {
                        $elegivel = false;
                    }
                } elseif ($r['condicao_tipo'] === 'aniversario') {
                    if (!$aniversario_este_mes) {
                        $elegivel = false;
                    }
                }
            }

            if ($elegivel) {
                $beneficio = '';
                if ($r['recompensa_tipo'] === 'desconto_percentual') {
                    $beneficio = number_format($r['recompensa_valor'], 0) . '% de Desconto';
                } elseif ($r['recompensa_tipo'] === 'desconto_fixo') {
                    $beneficio = 'R$ ' . number_format($r['recompensa_valor'], 2, ',', '.') . ' de Desconto';
                } elseif ($r['recompensa_tipo'] === 'item_gratis') {
                    $beneficio = 'Item / Serviço Grátis';
                } elseif ($r['recompensa_tipo'] === 'cashback') {
                    $beneficio = number_format($r['recompensa_valor'], 0) . '% de Cashback';
                }

                $regras_elegiveis[] = [
                    'id' => (int)$r['id'],
                    'nome_regra' => $r['nome_regra'],
                    'tipo_mecanica' => $r['tipo_mecanica'],
                    'meta' => floatval($r['condicao_valor']),
                    'beneficio' => $beneficio,
                    'cor_hex' => $r['cor_hex'],
                    'cor_secundaria' => $r['cor_secundaria'],
                    'icone' => $r['icone'],
                    'texto_cartao' => $r['texto_cartao']
                ];
            }
        }

        // Se não houver regra modular customizada, verifica a config de fidelidade padrão
        if (empty($regras_elegiveis)) {
            $stCfg = $pdo->prepare("SELECT * FROM fidelidade_config WHERE instituicao_id = ?");
            $stCfg->execute([$id_loja]);
            $cfg = $stCfg->fetch(PDO::FETCH_ASSOC);

            if ($cfg && count($itens) > 0) {
                $regras_elegiveis[] = [
                    'id' => 0,
                    'nome_regra' => $cfg['texto_cartao'] ?: 'Cartão Fidelidade Pet VIP',
                    'tipo_mecanica' => $cfg['tipo_programa'] ?: 'selos',
                    'meta' => floatval($cfg['compras_para_recompensa'] ?: 10),
                    'beneficio' => number_format($cfg['recompensa_valor'] ?: 15, 0) . '% de Desconto',
                    'cor_hex' => $cfg['cor_cartao_hex'] ?: '#1abc9c',
                    'cor_secundaria' => $cfg['cor_cartao_secundaria'] ?: '#2c3e50',
                    'icone' => 'fas fa-paw',
                    'texto_cartao' => $cfg['texto_cartao'] ?: 'Cartão Fidelidade Pet VIP'
                ];
            }
        }

        echo json_encode([
            'success' => true,
            'elegivel' => count($regras_elegiveis) > 0,
            'regras' => $regras_elegiveis,
            'cliente' => $cliente_info
        ]);
        exit;
    }

    // BUSCA DE PRODUTOS E SERVIÇOS PARA SELETORES DO CONSTRUTOR DE REGRAS
    if ($acao === 'buscar_itens_loja') {
        $stP = $pdo->prepare("SELECT id, nome_produto as nome, preco_venda as preco, 'produto' as tipo FROM produtos WHERE instituicao_id = ? ORDER BY nome_produto ASC");
        $stP->execute([$id_loja]);
        $prods = $stP->fetchAll(PDO::FETCH_ASSOC);

        $stS = $pdo->prepare("SELECT id, nome_servico as nome, preco_padrao as preco, 'servico' as tipo FROM servicos WHERE instituicao_id = ? ORDER BY nome_servico ASC");
        $stS->execute([$id_loja]);
        $servs = $stS->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'produtos' => $prods,
            'servicos' => $servs
        ]);
        exit;
    }

    // ════════════════════════════════════════════════════════════
    // 1. SALVAR CONFIGURAÇÕES DO PROGRAMA DE FIDELIDADE
    // ════════════════════════════════════════════════════════════
    if ($acao === 'salvar_config') {
        $tipo_programa = $_POST['tipo_programa'] ?? 'selos';
        $compras_para_recompensa = max(1, intval($_POST['compras_para_recompensa'] ?? 10));
        $recompensa_tipo = $_POST['recompensa_tipo'] ?? 'desconto_percentual';
        $recompensa_valor = floatval(str_replace(',', '.', $_POST['recompensa_valor'] ?? '15'));
        $limite_desconto_recompensa = floatval(str_replace(',', '.', $_POST['limite_desconto_recompensa'] ?? '50'));
        $reais_por_ponto = max(0.1, floatval(str_replace(',', '.', $_POST['reais_por_ponto'] ?? '1')));
        $cashback_percentual = floatval(str_replace(',', '.', $_POST['cashback_percentual'] ?? '5'));
        $validade_meses = intval($_POST['validade_meses'] ?? 6);
        $notificar_expiracao = isset($_POST['notificar_expiracao']) ? 1 : 0;
        $dias_aviso_expiracao = intval($_POST['dias_aviso_expiracao'] ?? 15);
        $notificar_whatsapp = isset($_POST['notificar_whatsapp']) ? 1 : 0;
        $notificar_email = isset($_POST['notificar_email']) ? 1 : 0;
        
        // Estilo do Cartão
        $cor_cartao_hex = $_POST['cor_cartao_hex'] ?? '#1abc9c';
        $cor_cartao_secundaria = $_POST['cor_cartao_secundaria'] ?? '#2c3e50';
        $estilo_cartao = $_POST['estilo_cartao'] ?? 'modern_gradient';
        $texto_cartao = trim($_POST['texto_cartao'] ?? 'Cartão Fidelidade Pet VIP');

        // Aceleradores (JSON)
        $aceleradores = [
            'terca_dobro' => isset($_POST['acelerador_terca']),
            'aniversario' => isset($_POST['acelerador_aniver']),
            'servicos_bonus' => isset($_POST['acelerador_servicos'])
        ];
        $regras_aceleradores = json_encode($aceleradores);

        // Upload de Logo se houver
        $logo_cartao = null;
        if (isset($_FILES['logo_cartao']) && $_FILES['logo_cartao']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['logo_cartao']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'svg'])) {
                $novoNome = 'logo_' . $id_loja . '_' . time() . '.' . $ext;
                $destino = __DIR__ . '/uploads/logos/' . $novoNome;
                if (move_uploaded_file($_FILES['logo_cartao']['tmp_name'], $destino)) {
                    $logo_cartao = 'uploads/logos/' . $novoNome;
                }
            }
        }

        // Tiers
        $tiers = [
            ['nome' => 'Bronze', 'meta' => floatval($_POST['tier_bronze_meta'] ?? 0), 'beneficio' => '1x pontos + Selos normais'],
            ['nome' => 'Prata', 'meta' => floatval($_POST['tier_prata_meta'] ?? 500), 'beneficio' => '1.2x pontos + 5% desc. no aniversário'],
            ['nome' => 'Ouro VIP', 'meta' => floatval($_POST['tier_ouro_meta'] ?? 1500), 'beneficio' => '1.5x pontos + Brinde na tosa + Fila VIP']
        ];
        $tiers_config = json_encode($tiers);

        // Verifica se já existe registro para a instituição
        $existe = $pdo->prepare("SELECT id, logo_cartao FROM fidelidade_config WHERE instituicao_id = ?");
        $existe->execute([$id_loja]);
        $row = $existe->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            if (!$logo_cartao) {
                $logo_cartao = $row['logo_cartao'];
            }
            $sql = "UPDATE fidelidade_config SET 
                    tipo_programa = ?, compras_para_recompensa = ?, recompensa_tipo = ?, recompensa_valor = ?, 
                    limite_desconto_recompensa = ?, reais_por_ponto = ?, cashback_percentual = ?, validade_meses = ?, 
                    notificar_expiracao = ?, dias_aviso_expiracao = ?, notificar_whatsapp = ?, notificar_email = ?, 
                    cor_cartao_hex = ?, cor_cartao_secundaria = ?, estilo_cartao = ?, logo_cartao = ?, texto_cartao = ?, 
                    regras_aceleradores = ?, tiers_config = ?
                    WHERE instituicao_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $tipo_programa, $compras_para_recompensa, $recompensa_tipo, $recompensa_valor,
                $limite_desconto_recompensa, $reais_por_ponto, $cashback_percentual, $validade_meses,
                $notificar_expiracao, $dias_aviso_expiracao, $notificar_whatsapp, $notificar_email,
                $cor_cartao_hex, $cor_cartao_secundaria, $estilo_cartao, $logo_cartao, $texto_cartao,
                $regras_aceleradores, $tiers_config, $id_loja
            ]);
        } else {
            $sql = "INSERT INTO fidelidade_config 
                    (instituicao_id, tipo_programa, compras_para_recompensa, recompensa_tipo, recompensa_valor, 
                     limite_desconto_recompensa, reais_por_ponto, cashback_percentual, validade_meses, 
                     notificar_expiracao, dias_aviso_expiracao, notificar_whatsapp, notificar_email, 
                     cor_cartao_hex, cor_cartao_secundaria, estilo_cartao, logo_cartao, texto_cartao, 
                     regras_aceleradores, tiers_config)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $id_loja, $tipo_programa, $compras_para_recompensa, $recompensa_tipo, $recompensa_valor,
                $limite_desconto_recompensa, $reais_por_ponto, $cashback_percentual, $validade_meses,
                $notificar_expiracao, $dias_aviso_expiracao, $notificar_whatsapp, $notificar_email,
                $cor_cartao_hex, $cor_cartao_secundaria, $estilo_cartao, $logo_cartao, $texto_cartao,
                $regras_aceleradores, $tiers_config
            ]);
        }

        echo json_encode([
            'success' => true, 
            'message' => 'Configurações de fidelização salvas com sucesso!',
            'logo_url' => $logo_cartao
        ]);
        exit;
    }

    // ════════════════════════════════════════════════════════════
    // 2. SALVAR / EDITAR PROMOÇÃO COM TEMPORIZADOR E FOTO
    // ════════════════════════════════════════════════════════════
    if ($acao === 'salvar_promocao') {
        $promo_id = intval($_POST['promo_id'] ?? 0);
        $titulo = trim($_POST['titulo'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $produto_id = !empty($_POST['produto_id']) ? intval($_POST['produto_id']) : null;
        $desconto_percentual = floatval(str_replace(',', '.', $_POST['desconto_percentual'] ?? '0'));
        $data_inicio = $_POST['data_inicio'] ?? date('Y-m-d H:i:s');
        $data_fim = $_POST['data_fim'] ?? date('Y-m-d H:i:s', strtotime('+7 days'));
        $ativo = isset($_POST['ativo']) ? 1 : 0;

        if (empty($titulo)) {
            echo json_encode(['success' => false, 'message' => 'Informe o título da promoção.']);
            exit;
        }

        $preco_original = null;
        $preco_promocional = null;

        // Se selecionou produto, busca preço
        if ($produto_id) {
            $stProd = $pdo->prepare("SELECT preco_venda FROM produtos WHERE id = ? AND instituicao_id = ?");
            $stProd->execute([$produto_id, $id_loja]);
            $preco_original = (float)($stProd->fetchColumn() ?: 0.0);
            if ($preco_original > 0 && $desconto_percentual > 0) {
                $preco_promocional = round($preco_original * (1 - ($desconto_percentual / 100)), 2);
            }
        }

        // Upload de Imagem do Produto/Promoção
        $imagem_url = null;
        if (isset($_FILES['imagem_promocao']) && $_FILES['imagem_promocao']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['imagem_promocao']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                $novoNome = 'promo_' . $id_loja . '_' . time() . '_' . rand(100, 999) . '.' . $ext;
                $destino = __DIR__ . '/uploads/promocoes/' . $novoNome;
                if (move_uploaded_file($_FILES['imagem_promocao']['tmp_name'], $destino)) {
                    $imagem_url = 'uploads/promocoes/' . $novoNome;
                }
            }
        }

        if ($promo_id > 0) {
            // Edição
            if (!$imagem_url) {
                // Mantém imagem anterior
                $stImg = $pdo->prepare("SELECT imagem_url FROM promocoes WHERE id = ? AND instituicao_id = ?");
                $stImg->execute([$promo_id, $id_loja]);
                $imagem_url = $stImg->fetchColumn();
            }

            $sql = "UPDATE promocoes SET 
                    titulo = ?, descricao = ?, produto_id = ?, desconto_percentual = ?, 
                    preco_original = ?, preco_promocional = ?, imagem_url = ?, 
                    data_inicio = ?, data_fim = ?, ativo = ? 
                    WHERE id = ? AND instituicao_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $titulo, $descricao, $produto_id, $desconto_percentual,
                $preco_original, $preco_promocional, $imagem_url,
                $data_inicio, $data_fim, $ativo,
                $promo_id, $id_loja
            ]);
            $msg = 'Promoção atualizada com sucesso!';
        } else {
            // Inserção
            $sql = "INSERT INTO promocoes 
                    (instituicao_id, titulo, descricao, produto_id, desconto_percentual, 
                     preco_original, preco_promocional, imagem_url, data_inicio, data_fim, ativo)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $id_loja, $titulo, $descricao, $produto_id, $desconto_percentual,
                $preco_original, $preco_promocional, $imagem_url,
                $data_inicio, $data_fim, $ativo
            ]);
            $msg = 'Promoção com foto e limitador de tempo criada com sucesso!';
        }

        echo json_encode(['success' => true, 'message' => $msg]);
        exit;
    }

    // ════════════════════════════════════════════════════════════
    // 3. EXCLUIR / ALTERAR STATUS DA PROMOÇÃO
    // ════════════════════════════════════════════════════════════
    if ($acao === 'excluir_promocao') {
        $promo_id = intval($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM promocoes WHERE id = ? AND instituicao_id = ?");
        $stmt->execute([$promo_id, $id_loja]);
        echo json_encode(['success' => true, 'message' => 'Promoção removida com sucesso.']);
        exit;
    }

    if ($acao === 'toggle_promocao') {
        $promo_id = intval($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE promocoes SET ativo = 1 - ativo WHERE id = ? AND instituicao_id = ?");
        $stmt->execute([$promo_id, $id_loja]);
        echo json_encode(['success' => true, 'message' => 'Status da promoção alterado.']);
        exit;
    }

    // ════════════════════════════════════════════════════════════
    // 4. OPERAÇÃO DE BALCÃO: PONTUAR / ADICIONAR SELO AO CLIENTE
    // ════════════════════════════════════════════════════════════
    if ($acao === 'adicionar_selo') {
        $cliente_id = intval($_POST['cliente_id'] ?? 0);
        $valor_compra = floatval(str_replace(',', '.', $_POST['valor_compra'] ?? '50'));
        $operador = $_SESSION['usuario_nome'] ?? 'Atendente';

        if ($cliente_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Selecione um cliente.']);
            exit;
        }

        // Pega as configs da loja
        $stCfg = $pdo->prepare("SELECT * FROM fidelidade_config WHERE instituicao_id = ?");
        $stCfg->execute([$id_loja]);
        $cfg = $stCfg->fetch(PDO::FETCH_ASSOC) ?: [
            'compras_para_recompensa' => 10,
            'recompensa_valor' => 15,
            'recompensa_tipo' => 'desconto_percentual'
        ];

        // Garante registro do cliente
        $stCli = $pdo->prepare("SELECT * FROM cliente_fidelidade WHERE instituicao_id = ? AND cliente_id = ?");
        $stCli->execute([$id_loja, $cliente_id]);
        $cliFid = $stCli->fetch(PDO::FETCH_ASSOC);

        if (!$cliFid) {
            $pdo->prepare("INSERT INTO cliente_fidelidade (instituicao_id, cliente_id, selos_atuais) VALUES (?, ?, 0)")
                ->execute([$id_loja, $cliente_id]);
            $selos_atuais = 0;
            $ciclos = 0;
            $total_gasto = 0;
        } else {
            $selos_atuais = (int)$cliFid['selos_atuais'];
            $ciclos = (int)$cliFid['ciclos_completados'];
            $total_gasto = (float)$cliFid['total_gasto_historico'];
        }

        $novos_selos = $selos_atuais + 1;
        $novo_total_gasto = $total_gasto + $valor_compra;
        $max_selos = (int)($cfg['compras_para_recompensa'] ?? 10);
        $recompensa_disponivel = false;

        if ($novos_selos >= $max_selos) {
            $recompensa_disponivel = true;
        }

        // Atualiza cliente
        $pdo->prepare("UPDATE cliente_fidelidade SET selos_atuais = ?, total_gasto_historico = ?, ultimo_acesso = NOW() WHERE instituicao_id = ? AND cliente_id = ?")
            ->execute([$novos_selos, $novo_total_gasto, $id_loja, $cliente_id]);

        // Grava auditoria
        $pdo->prepare("INSERT INTO fidelidade_transacoes (instituicao_id, cliente_id, tipo, quantidade, descricao, usuario_operador) VALUES (?, ?, 'acumulo_selo', 1, ?, ?)")
            ->execute([$id_loja, $cliente_id, "Selo #$novos_selos de $max_selos adicionado (Compra R$ " . number_format($valor_compra, 2, ',', '.') . ")", $operador]);

        echo json_encode([
            'success' => true,
            'selos_atuais' => $novos_selos,
            'max_selos' => $max_selos,
            'recompensa_disponivel' => $recompensa_disponivel,
            'recompensa_texto' => $cfg['recompensa_valor'] . '% de Desconto',
            'message' => "Selo adicionado com sucesso! ($novos_selos de $max_selos selos)."
        ]);
        exit;
    }

    // ════════════════════════════════════════════════════════════
    // 5. OPERAÇÃO DE BALCÃO: RESGATAR RECOMPENSA DE 10 COMPRAS
    // ════════════════════════════════════════════════════════════
    if ($acao === 'resgatar_recompensa') {
        $cliente_id = intval($_POST['cliente_id'] ?? 0);
        $operador = $_SESSION['usuario_nome'] ?? 'Atendente';

        $stCli = $pdo->prepare("SELECT * FROM cliente_fidelidade WHERE instituicao_id = ? AND cliente_id = ?");
        $stCli->execute([$id_loja, $cliente_id]);
        $cliFid = $stCli->fetch(PDO::FETCH_ASSOC);

        $stCfg = $pdo->prepare("SELECT * FROM fidelidade_config WHERE instituicao_id = ?");
        $stCfg->execute([$id_loja]);
        $cfg = $stCfg->fetch(PDO::FETCH_ASSOC) ?: ['compras_para_recompensa' => 10, 'recompensa_valor' => 15];

        $meta = (int)($cfg['compras_para_recompensa'] ?? 10);
        $selos = (int)($cliFid['selos_atuais'] ?? 0);

        if ($selos < $meta) {
            echo json_encode(['success' => false, 'message' => "Cliente possui apenas $selos de $meta selos necessários."]);
            exit;
        }

        // Zera os selos e incrementa ciclo completado
        $sobra = $selos - $meta;
        $novos_ciclos = ((int)($cliFid['ciclos_completados'] ?? 0)) + 1;

        $pdo->prepare("UPDATE cliente_fidelidade SET selos_atuais = ?, ciclos_completados = ?, ultimo_acesso = NOW() WHERE instituicao_id = ? AND cliente_id = ?")
            ->execute([$sobra, $novos_ciclos, $id_loja, $cliente_id]);

        $valDesc = floatval($cfg['recompensa_valor'] ?? 15);
        $pdo->prepare("INSERT INTO fidelidade_transacoes (instituicao_id, cliente_id, tipo, quantidade, valor_recompensa, descricao, usuario_operador) VALUES (?, ?, 'resgate_recompensa', ?, ?, ?, ?)")
            ->execute([$id_loja, $cliente_id, $meta, $valDesc, "Resgate do Cartão Fidelidade ($meta compras completadas) - Desconto aplicado no caixa", $operador]);

        echo json_encode([
            'success' => true,
            'message' => "Parabéns! Recompensa resgatada com sucesso! O cliente agora tem $sobra selos e completou seu {$novos_ciclos}º ciclo fidelidade.",
            'selos_restantes' => $sobra
        ]);
        exit;
    }

    // ════════════════════════════════════════════════════════════
    // 6. BUSCA DE CLIENTE PARA PONTUAÇÃO RÁPIDA NO BALCÃO
    // ════════════════════════════════════════════════════════════
    if ($acao === 'buscar_cliente_fidelidade') {
        $termo = trim($_GET['termo'] ?? '');
        if (strlen($termo) < 2) {
            echo json_encode(['success' => true, 'clientes' => []]);
            exit;
        }

        $sql = "SELECT c.id, c.nome_completo, c.telefone, c.cpf, 
                       COALESCE(cf.selos_atuais, 0) as selos,
                       COALESCE(cf.pontos_acumulados, 0) as pontos,
                       COALESCE(cf.tier_atual, 'Bronze') as tier
                FROM clientes c
                LEFT JOIN cliente_fidelidade cf ON cf.cliente_id = c.id AND cf.instituicao_id = c.instituicao_id
                WHERE c.instituicao_id = ? AND (c.nome_completo LIKE ? OR c.cpf LIKE ? OR c.telefone LIKE ?)
                ORDER BY c.nome_completo ASC LIMIT 10";
        $st = $pdo->prepare($sql);
        $like = "%$termo%";
        $st->execute([$id_loja, $like, $like, $like]);
        $clientes = $st->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'clientes' => $clientes]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Ação não reconhecida.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro interno: ' . $e->getMessage()]);
}
