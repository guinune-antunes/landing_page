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

require_once __DIR__ . '/includes/unidades.php';
require_once __DIR__ . '/includes/motor_fidelidade.php';
$id_loja_fid = obterInstituicaoFidelidade($pdo, $id_loja);
$ids_clientes = obterInstituicoesClientes($pdo, $id_loja);

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
                         WHEN r.categoria_alvo = 'item_especifico' AND r.item_alvo_tipo = 'produto' THEN COALESCE(prod.nome_produto, CONCAT('Produto #', r.item_alvo_id))
                         WHEN r.categoria_alvo = 'item_especifico' AND r.item_alvo_tipo = 'servico' THEN COALESCE(serv.nome_servico, CONCAT('Serviço #', r.item_alvo_id))
                         WHEN r.categoria_alvo = 'item_especifico' THEN COALESCE(prod.nome_produto, serv.nome_servico, CONCAT('Item #', r.item_alvo_id))
                         ELSE NULL 
                       END as item_alvo_nome,
                       CASE 
                         WHEN r.recompensa_tipo = 'item_gratis' AND r.recompensa_item_tipo = 'produto' THEN COALESCE(rec_p.nome_produto, CONCAT('Produto #', r.recompensa_item_id))
                         WHEN r.recompensa_tipo = 'item_gratis' AND r.recompensa_item_tipo = 'servico' THEN COALESCE(rec_s.nome_servico, CONCAT('Serviço #', r.recompensa_item_id))
                         WHEN r.recompensa_tipo = 'item_gratis' THEN COALESCE(rec_p.nome_produto, rec_s.nome_servico, CONCAT('Item #', r.recompensa_item_id))
                         ELSE NULL 
                       END as recompensa_item_nome,
                       (SELECT COUNT(*) FROM cliente_progresso_regras cpr WHERE cpr.regra_id = r.id) as total_participantes,
                       (SELECT COALESCE(SUM(cpr.ciclos_completados), 0) FROM cliente_progresso_regras cpr WHERE cpr.regra_id = r.id) as total_ciclos
                FROM fidelidade_regras r
                LEFT JOIN produtos prod ON (r.categoria_alvo = 'item_especifico' AND (r.item_alvo_tipo IS NULL OR r.item_alvo_tipo = 'produto') AND r.item_alvo_id = prod.id)
                LEFT JOIN servicos serv ON (r.categoria_alvo = 'item_especifico' AND (r.item_alvo_tipo IS NULL OR r.item_alvo_tipo = 'servico') AND r.item_alvo_id = serv.id)
                LEFT JOIN produtos rec_p ON (r.recompensa_tipo = 'item_gratis' AND (r.recompensa_item_tipo IS NULL OR r.recompensa_item_tipo = 'produto') AND r.recompensa_item_id = rec_p.id)
                LEFT JOIN servicos rec_s ON (r.recompensa_tipo = 'item_gratis' AND (r.recompensa_item_tipo IS NULL OR r.recompensa_item_tipo = 'servico') AND r.recompensa_item_id = rec_s.id)
                WHERE r.instituicao_id = ?
                ORDER BY r.ativo DESC, r.prioridade ASC, r.id DESC";
        $st = $pdo->prepare($sql);
        $st->execute([$id_loja_fid]);
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

        $alvoParsed = crmParseItemAlvo($params['item_alvo_id'] ?? null, $params['item_alvo_tipo'] ?? null);
        $item_alvo_id = ($categoria_alvo === 'item_especifico') ? $alvoParsed['id'] : null;
        $item_alvo_tipo = ($categoria_alvo === 'item_especifico') ? $alvoParsed['tipo'] : null;

        $condicao_tipo = $params['condicao_tipo'] ?? 'quantidade_compras';
        $condicao_valor = floatval(str_replace(',', '.', $params['condicao_valor'] ?? '10'));

        // Aceita tanto dias_semana_ativos quanto dias[] vindo do form HTML
        $diasRaw = $params['dias_semana_ativos'] ?? ($params['dias'] ?? null);
        $dias_semana_ativos = null;
        if (!empty($diasRaw)) {
            $dias_semana_ativos = is_array($diasRaw) ? json_encode(array_values($diasRaw)) : $diasRaw;
        }

        $recompensa_tipo = $params['recompensa_tipo'] ?? 'desconto_percentual';
        $recompensa_valor = floatval(str_replace(',', '.', $params['recompensa_valor'] ?? '15'));
        $recParsed = crmParseItemAlvo($params['recompensa_item_id'] ?? null, $params['recompensa_item_tipo'] ?? null);
        $recompensa_item_id = ($recompensa_tipo === 'item_gratis') ? $recParsed['id'] : null;
        $recompensa_item_tipo = ($recompensa_tipo === 'item_gratis') ? $recParsed['tipo'] : null;
        $limite_desconto = (isset($params['limite_desconto_recompensa']) && $params['limite_desconto_recompensa'] !== '')
            ? floatval(str_replace(',', '.', $params['limite_desconto_recompensa'])) : null;

        $data_inicio = !empty($params['data_inicio']) ? str_replace('T', ' ', trim($params['data_inicio'])) : null;
        $data_fim = !empty($params['data_fim']) ? str_replace('T', ' ', trim($params['data_fim'])) : null;

        // Controles de Política de Reentrada, Limites e Empilhamento
        $politicasPermitidas = ['unica_vez', 'reiniciar_apos_resgate', 'reiniciar_ao_concluir', 'cooldown_dias', 'ilimitada_por_compra'];
        $politica_reentrada = in_array($params['politica_reentrada'] ?? '', $politicasPermitidas, true)
            ? $params['politica_reentrada'] : 'reiniciar_apos_resgate';
        $limite_ciclos_cliente = (isset($params['limite_ciclos_cliente']) && $params['limite_ciclos_cliente'] !== '' && intval($params['limite_ciclos_cliente']) > 0)
            ? intval($params['limite_ciclos_cliente']) : null;
        $limite_usos_global = (isset($params['limite_usos_global']) && $params['limite_usos_global'] !== '' && intval($params['limite_usos_global']) > 0)
            ? intval($params['limite_usos_global']) : null;
        $cooldown_dias = max(0, intval($params['cooldown_dias'] ?? 0));
        $contagem_por_item = !empty($params['contagem_por_item']) ? 1 : 0;
        $acumular_sobra = !isset($params['acumular_sobra']) ? 0 : (!empty($params['acumular_sobra']) ? 1 : 0);
        // Em envio via FormData de checkboxes HTML, distingue criação/edição com campo marcador
        if (isset($params['_form_politica'])) {
            $contagem_por_item = isset($params['contagem_por_item']) ? 1 : 0;
            $acumular_sobra = isset($params['acumular_sobra']) ? 1 : 0;
            $auto_resgatar_pdv = isset($params['auto_resgatar_pdv']) ? 1 : 0;
            $acumular_com_outras = isset($params['acumular_com_outras']) ? 1 : 0;
        } else {
            $auto_resgatar_pdv = isset($params['auto_resgatar_pdv']) ? (int)!empty($params['auto_resgatar_pdv']) : 1;
            $acumular_com_outras = isset($params['acumular_com_outras']) ? (int)!empty($params['acumular_com_outras']) : 1;
            $acumular_sobra = isset($params['acumular_sobra']) ? (int)!empty($params['acumular_sobra']) : 1;
        }
        $prioridade = max(1, intval($params['prioridade'] ?? 10));

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
                nome_regra = ?, descricao = ?, tipo_mecanica = ?, categoria_alvo = ?, item_alvo_id = ?, item_alvo_tipo = ?,
                condicao_tipo = ?, condicao_valor = ?, dias_semana_ativos = ?, recompensa_tipo = ?,
                recompensa_valor = ?, recompensa_item_id = ?, recompensa_item_tipo = ?, limite_desconto_recompensa = ?,
                data_inicio = ?, data_fim = ?, politica_reentrada = ?, limite_ciclos_cliente = ?, limite_usos_global = ?,
                cooldown_dias = ?, contagem_por_item = ?, acumular_sobra = ?, auto_resgatar_pdv = ?, acumular_com_outras = ?,
                prioridade = ?, cor_hex = ?, cor_secundaria = ?, icone = ?, texto_cartao = ?, ativo = ?
                WHERE id = ? AND instituicao_id = ?");
            $stmt->execute([
                $nome_regra, $descricao, $tipo_mecanica, $categoria_alvo, $item_alvo_id, $item_alvo_tipo,
                $condicao_tipo, $condicao_valor, $dias_semana_ativos, $recompensa_tipo,
                $recompensa_valor, $recompensa_item_id, $recompensa_item_tipo, $limite_desconto,
                $data_inicio, $data_fim, $politica_reentrada, $limite_ciclos_cliente, $limite_usos_global,
                $cooldown_dias, $contagem_por_item, $acumular_sobra, $auto_resgatar_pdv, $acumular_com_outras,
                $prioridade, $cor_hex, $cor_secundaria, $icone, $texto_cartao, $ativo,
                $regra_id, $id_loja_fid
            ]);
            $msg = 'Regra de fidelidade atualizada com sucesso!';
        } else {
            $stmt = $pdo->prepare("INSERT INTO fidelidade_regras 
                (instituicao_id, nome_regra, descricao, tipo_mecanica, categoria_alvo, item_alvo_id, item_alvo_tipo,
                 condicao_tipo, condicao_valor, dias_semana_ativos, recompensa_tipo,
                 recompensa_valor, recompensa_item_id, recompensa_item_tipo, limite_desconto_recompensa,
                 data_inicio, data_fim, politica_reentrada, limite_ciclos_cliente, limite_usos_global,
                 cooldown_dias, contagem_por_item, acumular_sobra, auto_resgatar_pdv, acumular_com_outras,
                 prioridade, cor_hex, cor_secundaria, icone, texto_cartao, ativo)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $id_loja_fid, $nome_regra, $descricao, $tipo_mecanica, $categoria_alvo, $item_alvo_id, $item_alvo_tipo,
                $condicao_tipo, $condicao_valor, $dias_semana_ativos, $recompensa_tipo,
                $recompensa_valor, $recompensa_item_id, $recompensa_item_tipo, $limite_desconto,
                $data_inicio, $data_fim, $politica_reentrada, $limite_ciclos_cliente, $limite_usos_global,
                $cooldown_dias, $contagem_por_item, $acumular_sobra, $auto_resgatar_pdv, $acumular_com_outras,
                $prioridade, $cor_hex, $cor_secundaria, $icone, $texto_cartao, $ativo
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
        $pdo->prepare("DELETE FROM cliente_progresso_regras WHERE regra_id = ? AND instituicao_id = ?")->execute([$regra_id, $id_loja_fid]);
        $pdo->prepare("DELETE FROM fidelidade_regras WHERE id = ? AND instituicao_id = ?")->execute([$regra_id, $id_loja_fid]);
        echo json_encode(['success' => true, 'message' => 'Regra removida com sucesso!']);
        exit;
    }

    // ALTERAR STATUS DA REGRA
    if ($acao === 'toggle_regra') {
        $params = !empty($inputData) ? $inputData : $_POST;
        $regra_id = intval($params['id'] ?? 0);
        $pdo->prepare("UPDATE fidelidade_regras SET ativo = 1 - ativo WHERE id = ? AND instituicao_id = ?")->execute([$regra_id, $id_loja_fid]);
        echo json_encode(['success' => true, 'message' => 'Status da regra alterado!']);
        exit;
    }

    // VERIFICAR REGRAS APLICÁVEIS AO CARRINHO (AUTOMATIZAÇÃO DO BALCÃO / PDV)
    if ($acao === 'verificar_regras_carrinho') {
        $params = !empty($inputData) ? $inputData : $_POST;
        $itens = $params['itens'] ?? [];
        $cliente_id = !empty($params['cliente_id']) ? intval($params['cliente_id']) : null;
        $desconto_manual = floatval($params['desconto_manual'] ?? 0);
        $whatsappParam = trim($params['cliente_whatsapp'] ?? '');

        // Se o cliente ainda não foi escolhido no select mas digitou WhatsApp, tenta resolver
        if (!$cliente_id && !empty($whatsappParam)) {
            $digits = preg_replace('/\D/', '', $whatsappParam);
            if (strlen($digits) >= 8) {
                $last8 = substr($digits, -8);
                $in_cli = implode(',', array_map('intval', $ids_clientes));
                $stW = $pdo->prepare("SELECT id FROM clientes WHERE instituicao_id IN ($in_cli) AND REPLACE(REPLACE(REPLACE(REPLACE(telefone, '(', ''), ')', ''), '-', ''), ' ', '') LIKE ? LIMIT 1");
                $stW->execute(["%{$last8}%"]);
                $foundId = (int)$stW->fetchColumn();
                if ($foundId > 0) {
                    $cliente_id = $foundId;
                }
            }
        }

        $eval = crmAvaliarCarrinhoCompleto($pdo, (int)$id_loja, (int)$id_loja_fid, $ids_clientes, $itens, $cliente_id, $desconto_manual, $params['opcoes'] ?? []);

        $elegivel = count($eval['regras']) > 0
            || count($eval['promocoes']['aplicaveis']) > 0
            || count($eval['promocoes']['bloqueadas']) > 0
            || count($eval['promocoes']['pendentes_identificacao']) > 0
            || count($eval['regras_bloqueadas']) > 0;

        echo json_encode([
            'success'               => true,
            'elegivel'              => $elegivel,
            'subtotal_bruto'        => $eval['subtotal_bruto'],
            'desconto_promocao'     => $eval['desconto_promocao'],
            'desconto_fidelidade'   => $eval['desconto_fidelidade'],
            'desconto_total'        => $eval['desconto_total'],
            'total_liquido'         => $eval['total_liquido'],
            'cashback_gerado'       => $eval['cashback_gerado'],
            'pontos_gerados'        => $eval['pontos_gerados'],
            'promocoes'             => $eval['promocoes'],
            'regras'                => $eval['regras'],
            'regras_modulares'      => $eval['regras_modulares'],
            'regras_bloqueadas'     => $eval['regras_bloqueadas'],
            'cartao_classico'       => $eval['cartao_classico'],
            'cliente'               => $eval['cliente'],
            'cliente_id_resolvido'  => $cliente_id,
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
        $existe = $pdo->prepare("SELECT * FROM fidelidade_config WHERE instituicao_id = ?");
        $existe->execute([$id_loja_fid]);
        $row = $existe->fetch(PDO::FETCH_ASSOC) ?: [];

        // Preserva campos já gravados caso o formulário parcial não os envie
        $tipo_programa = $_POST['tipo_programa'] ?? ($row['tipo_programa'] ?? 'selos');
        $compras_para_recompensa = max(1, intval($_POST['compras_para_recompensa'] ?? ($row['compras_para_recompensa'] ?? 10)));
        $recompensa_tipo = $_POST['recompensa_tipo'] ?? ($row['recompensa_tipo'] ?? 'desconto_percentual');
        $recompensa_valor = floatval(str_replace(',', '.', $_POST['recompensa_valor'] ?? ($row['recompensa_valor'] ?? '15')));
        $limite_desconto_recompensa = floatval(str_replace(',', '.', $_POST['limite_desconto_recompensa'] ?? ($row['limite_desconto_recompensa'] ?? '50')));
        $reais_por_ponto = max(0.1, floatval(str_replace(',', '.', $_POST['reais_por_ponto'] ?? ($row['reais_por_ponto'] ?? '1'))));
        $cashback_percentual = floatval(str_replace(',', '.', $_POST['cashback_percentual'] ?? ($row['cashback_percentual'] ?? '5')));
        $validade_meses = intval($_POST['validade_meses'] ?? ($row['validade_meses'] ?? 6));
        $notificar_expiracao = array_key_exists('notificar_expiracao', $_POST) ? 1 : (int)($row['notificar_expiracao'] ?? 1);
        $dias_aviso_expiracao = intval($_POST['dias_aviso_expiracao'] ?? ($row['dias_aviso_expiracao'] ?? 15));
        $notificar_whatsapp = array_key_exists('notificar_whatsapp', $_POST) ? 1 : (int)($row['notificar_whatsapp'] ?? 1);
        $notificar_email = array_key_exists('notificar_email', $_POST) ? 1 : (int)($row['notificar_email'] ?? 0);

        $cor_cartao_hex = $_POST['cor_cartao_hex'] ?? ($row['cor_cartao_hex'] ?? '#1abc9c');
        $cor_cartao_secundaria = $_POST['cor_cartao_secundaria'] ?? ($row['cor_cartao_secundaria'] ?? '#2c3e50');
        $estilo_cartao = $_POST['estilo_cartao'] ?? ($row['estilo_cartao'] ?? 'modern_gradient');
        $texto_cartao = trim($_POST['texto_cartao'] ?? ($row['texto_cartao'] ?? 'Cartão Fidelidade Pet VIP'));

        if (isset($_POST['aceleradores_enviados'])) {
            $aceleradores = [
                'terca_dobro' => isset($_POST['acelerador_terca']),
                'aniversario' => isset($_POST['acelerador_aniver']),
                'servicos_bonus' => isset($_POST['acelerador_servicos'])
            ];
            $regras_aceleradores = json_encode($aceleradores);
        } else {
            $regras_aceleradores = $row['regras_aceleradores'] ?? json_encode(['terca_dobro' => true, 'aniversario' => true, 'servicos_bonus' => true]);
        }

        // Upload de Logo se houver
        $logo_cartao = $row['logo_cartao'] ?? null;
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

        if (isset($_POST['tier_bronze_meta']) || isset($_POST['tier_prata_meta']) || isset($_POST['tier_ouro_meta'])) {
            $tiers = [
                ['nome' => 'Bronze', 'meta' => floatval($_POST['tier_bronze_meta'] ?? 0), 'beneficio' => '1x pontos + Selos normais'],
                ['nome' => 'Prata', 'meta' => floatval($_POST['tier_prata_meta'] ?? 500), 'beneficio' => '1.2x pontos + 5% desc. no aniversário'],
                ['nome' => 'Ouro VIP', 'meta' => floatval($_POST['tier_ouro_meta'] ?? 1500), 'beneficio' => '1.5x pontos + Brinde na tosa + Fila VIP']
            ];
            $tiers_config = json_encode($tiers);
        } else {
            $tiers_config = $row['tiers_config'] ?? json_encode([
                ['nome' => 'Bronze', 'meta' => 0, 'beneficio' => '1x pontos + Selos normais'],
                ['nome' => 'Prata', 'meta' => 500, 'beneficio' => '1.2x pontos + 5% desc. no aniversário'],
                ['nome' => 'Ouro VIP', 'meta' => 1500, 'beneficio' => '1.5x pontos + Brinde na tosa + Fila VIP']
            ]);
        }

        if ($row) {
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
                $regras_aceleradores, $tiers_config, $id_loja_fid
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
                $id_loja_fid, $tipo_programa, $compras_para_recompensa, $recompensa_tipo, $recompensa_valor,
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
    // 2. SALVAR / EDITAR PROMOÇÃO COM TEMPORIZADOR, FOTO E POLÍTICA DE REENTRADA
    // ════════════════════════════════════════════════════════════
    if ($acao === 'salvar_promocao') {
        $promo_id = intval($_POST['promo_id'] ?? 0);
        $titulo = trim($_POST['titulo'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $produto_id = !empty($_POST['produto_id']) ? intval($_POST['produto_id']) : null;
        $desconto_percentual = floatval(str_replace(',', '.', $_POST['desconto_percentual'] ?? '0'));
        $data_inicio = str_replace('T', ' ', trim($_POST['data_inicio'] ?? date('Y-m-d H:i:s')));
        $data_fim = str_replace('T', ' ', trim($_POST['data_fim'] ?? date('Y-m-d H:i:s', strtotime('+7 days'))));
        $ativo = isset($_POST['_form_promo']) ? (isset($_POST['ativo']) ? 1 : 0) : (isset($_POST['ativo']) ? intval($_POST['ativo']) : 1);

        $polsPromo = ['ilimitada', 'unica_vez', 'uma_por_dia', 'cooldown_dias'];
        $politica_reentrada = in_array($_POST['politica_reentrada'] ?? '', $polsPromo, true)
            ? $_POST['politica_reentrada'] : 'ilimitada';
        $limite_usos_cliente = (isset($_POST['limite_usos_cliente']) && $_POST['limite_usos_cliente'] !== '' && intval($_POST['limite_usos_cliente']) > 0)
            ? intval($_POST['limite_usos_cliente']) : null;
        $cooldown_dias = max(0, intval($_POST['cooldown_dias'] ?? 0));
        $limite_usos_global = (isset($_POST['limite_usos_global']) && $_POST['limite_usos_global'] !== '' && intval($_POST['limite_usos_global']) > 0)
            ? intval($_POST['limite_usos_global']) : null;
        $aplicar_automatico_pdv = isset($_POST['_form_promo'])
            ? (isset($_POST['aplicar_automatico_pdv']) ? 1 : 0)
            : (isset($_POST['aplicar_automatico_pdv']) ? intval($_POST['aplicar_automatico_pdv']) : 1);

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
            if (!$imagem_url) {
                $stImg = $pdo->prepare("SELECT imagem_url FROM promocoes WHERE id = ? AND instituicao_id = ?");
                $stImg->execute([$promo_id, $id_loja]);
                $imagem_url = $stImg->fetchColumn();
            }

            $sql = "UPDATE promocoes SET 
                    titulo = ?, descricao = ?, produto_id = ?, desconto_percentual = ?, 
                    preco_original = ?, preco_promocional = ?, imagem_url = ?, 
                    data_inicio = ?, data_fim = ?, ativo = ?,
                    politica_reentrada = ?, limite_usos_cliente = ?, cooldown_dias = ?,
                    limite_usos_global = ?, aplicar_automatico_pdv = ?
                    WHERE id = ? AND instituicao_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $titulo, $descricao, $produto_id, $desconto_percentual,
                $preco_original, $preco_promocional, $imagem_url,
                $data_inicio, $data_fim, $ativo,
                $politica_reentrada, $limite_usos_cliente, $cooldown_dias,
                $limite_usos_global, $aplicar_automatico_pdv,
                $promo_id, $id_loja
            ]);
            $msg = 'Promoção atualizada com sucesso!';
        } else {
            $sql = "INSERT INTO promocoes 
                    (instituicao_id, titulo, descricao, produto_id, desconto_percentual, 
                     preco_original, preco_promocional, imagem_url, data_inicio, data_fim, ativo,
                     politica_reentrada, limite_usos_cliente, cooldown_dias, limite_usos_global, aplicar_automatico_pdv)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $id_loja, $titulo, $descricao, $produto_id, $desconto_percentual,
                $preco_original, $preco_promocional, $imagem_url,
                $data_inicio, $data_fim, $ativo,
                $politica_reentrada, $limite_usos_cliente, $cooldown_dias, $limite_usos_global, $aplicar_automatico_pdv
            ]);
            $promo_id = $pdo->lastInsertId();
            $msg = 'Promoção salva com política de reentrada e desconto automático no PDV!';
        }

        echo json_encode(['success' => true, 'message' => $msg, 'promo_id' => $promo_id]);
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

        // Pega as configs da loja (ou da matriz da rede se compartilhado)
        $stCfg = $pdo->prepare("SELECT * FROM fidelidade_config WHERE instituicao_id = ?");
        $stCfg->execute([$id_loja_fid]);
        $cfg = $stCfg->fetch(PDO::FETCH_ASSOC) ?: [
            'compras_para_recompensa' => 10,
            'recompensa_valor' => 15,
            'recompensa_tipo' => 'desconto_percentual'
        ];

        // Garante registro do cliente na fidelidade
        $stCli = $pdo->prepare("SELECT * FROM cliente_fidelidade WHERE instituicao_id = ? AND cliente_id = ?");
        $stCli->execute([$id_loja_fid, $cliente_id]);
        $cliFid = $stCli->fetch(PDO::FETCH_ASSOC);

        if (!$cliFid) {
            $pdo->prepare("INSERT INTO cliente_fidelidade (instituicao_id, cliente_id, selos_atuais) VALUES (?, ?, 0)")
                ->execute([$id_loja_fid, $cliente_id]);
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
            ->execute([$novos_selos, $novo_total_gasto, $id_loja_fid, $cliente_id]);

        // Grava auditoria
        $pdo->prepare("INSERT INTO fidelidade_transacoes (instituicao_id, cliente_id, tipo, quantidade, descricao, usuario_operador) VALUES (?, ?, 'acumulo_selo', 1, ?, ?)")
            ->execute([$id_loja_fid, $cliente_id, "Selo #$novos_selos de $max_selos adicionado (Compra R$ " . number_format($valor_compra, 2, ',', '.') . ")", $operador]);

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
        $stCli->execute([$id_loja_fid, $cliente_id]);
        $cliFid = $stCli->fetch(PDO::FETCH_ASSOC);

        $stCfg = $pdo->prepare("SELECT * FROM fidelidade_config WHERE instituicao_id = ?");
        $stCfg->execute([$id_loja_fid]);
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
            ->execute([$sobra, $novos_ciclos, $id_loja_fid, $cliente_id]);

        $valDesc = floatval($cfg['recompensa_valor'] ?? 15);
        $pdo->prepare("INSERT INTO fidelidade_transacoes (instituicao_id, cliente_id, tipo, quantidade, valor_recompensa, descricao, usuario_operador) VALUES (?, ?, 'resgate_recompensa', ?, ?, ?, ?)")
            ->execute([$id_loja_fid, $cliente_id, $meta, $valDesc, "Resgate do Cartão Fidelidade ($meta compras completadas) - Desconto aplicado no caixa", $operador]);

        echo json_encode([
            'success' => true,
            'message' => "Parabéns! Recompensa resgatada com sucesso! O cliente agora tem $sobra selos e completou seu {$novos_ciclos}º ciclo fidelidade.",
            'selos_restantes' => $sobra
        ]);
        exit;
    }

    // ════════════════════════════════════════════════════════════
    // 6. BUSCA E OPERAÇÃO DETALHADA DO CLIENTE NO BALCÃO (INCLUI REGRAS MODULARES)
    // ════════════════════════════════════════════════════════════
    if ($acao === 'resgatar_regra_modular') {
        $params = !empty($inputData) ? $inputData : $_POST;
        $cliente_id = intval($params['cliente_id'] ?? 0);
        $regra_id = intval($params['regra_id'] ?? 0);
        $operador = $_SESSION['usuario_nome'] ?? 'Atendente';

        if ($cliente_id <= 0 || $regra_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Informe cliente e regra válidos.']);
            exit;
        }

        $res = crmResgatarRegraModularBalcao($pdo, (int)$id_loja_fid, $cliente_id, $regra_id, $operador);
        echo json_encode($res);
        exit;
    }

    if ($acao === 'detalhar_cliente_regras') {
        $cliente_id = intval($_GET['cliente_id'] ?? $_POST['cliente_id'] ?? 0);
        if ($cliente_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Cliente inválido.']);
            exit;
        }

        $stR = $pdo->prepare("SELECT r.*, cpr.progresso_atual, cpr.ciclos_completados, cpr.recompensa_disponivel,
                                     cpr.ultimo_uso, cpr.ultimo_resgate_em, cpr.bloqueado_reentrada
                              FROM fidelidade_regras r
                              LEFT JOIN cliente_progresso_regras cpr ON cpr.regra_id = r.id AND cpr.cliente_id = ?
                              WHERE r.instituicao_id = ? AND r.ativo = 1
                              ORDER BY r.prioridade ASC, r.id ASC");
        $stR->execute([$cliente_id, $id_loja_fid]);
        $regras = $stR->fetchAll(PDO::FETCH_ASSOC);

        $agoraTs = time();
        $regrasDetalhe = [];
        foreach ($regras as $r) {
            $progRow = ($r['progresso_atual'] !== null || $r['ciclos_completados'] !== null) ? $r : null;
            $status = crmAvaliarPoliticaReentradaRegra($r, $progRow, $agoraTs);
            $regrasDetalhe[] = [
                'id'                   => (int)$r['id'],
                'nome_regra'           => $r['nome_regra'],
                'tipo_mecanica'        => $r['tipo_mecanica'],
                'politica_reentrada'   => $r['politica_reentrada'] ?? 'reiniciar_apos_resgate',
                'meta'                 => (int)$r['condicao_valor'],
                'progresso_atual'      => (int)($r['progresso_atual'] ?? 0),
                'ciclos_completados'   => (int)($r['ciclos_completados'] ?? 0),
                'recompensa_disponivel'=> !empty($r['recompensa_disponivel']),
                'bloqueado_reentrada'  => !empty($r['bloqueado_reentrada']),
                'ultimo_resgate_em'    => $r['ultimo_resgate_em'] ?? null,
                'status_codigo'        => $status['status_codigo'],
                'status_label'         => $status['status_label'],
                'motivo_bloqueio'      => $status['motivo_bloqueio'],
            ];
        }

        $stPU = $pdo->prepare("SELECT pu.*, p.titulo
                               FROM promocao_usos pu
                               JOIN promocoes p ON p.id = pu.promocao_id
                               WHERE pu.cliente_id = ? AND pu.instituicao_id = ?
                               ORDER BY pu.data_uso DESC LIMIT 10");
        $stPU->execute([$cliente_id, $id_loja]);
        $promosUsadas = $stPU->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'regras' => $regrasDetalhe,
            'promocoes_usadas' => $promosUsadas
        ]);
        exit;
    }

    if ($acao === 'buscar_cliente_fidelidade') {
        $termo = trim($_GET['q'] ?? $_GET['termo'] ?? '');
        $isSelect2 = !empty($_GET['select2']);
        if (!$isSelect2 && strlen($termo) < 2) {
            echo json_encode(['success' => true, 'clientes' => [], 'results' => []]);
            exit;
        }

        $in_cli = implode(',', array_map('intval', $ids_clientes));
        $whereBusca = $termo !== ''
            ? " AND (c.nome_completo LIKE ? OR c.cpf LIKE ? OR c.telefone LIKE ?)"
            : "";
        $sql = "SELECT c.id, c.nome_completo, c.telefone, c.cpf, 
                       COALESCE(cf.selos_atuais, 0) as selos,
                       COALESCE(cf.ciclos_completados, 0) as ciclos_completados,
                       COALESCE(cf.pontos_acumulados, 0) as pontos,
                       COALESCE(cf.saldo_cashback, 0.00) as saldo_cashback,
                       COALESCE(cf.tier_atual, 'Bronze') as tier,
                       (SELECT COUNT(*) FROM cliente_progresso_regras cpr WHERE cpr.cliente_id = c.id AND cpr.instituicao_id = ? AND cpr.recompensa_disponivel = 1) as regras_prontas
                FROM clientes c
                LEFT JOIN cliente_fidelidade cf ON cf.cliente_id = c.id AND cf.instituicao_id = ?
                WHERE c.instituicao_id IN ($in_cli){$whereBusca}
                ORDER BY c.nome_completo ASC LIMIT 20";
        $st = $pdo->prepare($sql);
        $paramsCli = [$id_loja_fid, $id_loja_fid];
        if ($termo !== '') {
            $like = "%$termo%";
            array_push($paramsCli, $like, $like, $like);
        }
        $st->execute($paramsCli);
        $clientes = $st->fetchAll(PDO::FETCH_ASSOC);

        $resultsS2 = [];
        foreach ($clientes as $cliRow) {
            $sub = $cliRow['telefone'] ? $cliRow['telefone'] : ($cliRow['cpf'] ?: 'sem telefone');
            $resultsS2[] = [
                'id' => (int)$cliRow['id'],
                'text' => $cliRow['nome_completo'] . " ({$sub}) — Selos: " . (int)$cliRow['selos'] . " · " . $cliRow['tier'],
                'cliente' => $cliRow,
            ];
        }

        echo json_encode(['success' => true, 'clientes' => $clientes, 'results' => $resultsS2]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Ação não reconhecida.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro interno: ' . $e->getMessage()]);
}
