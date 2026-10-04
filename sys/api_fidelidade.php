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

$acao = $_POST['acao'] ?? $_GET['acao'] ?? '';

try {
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
