<?php
// ══════════════════════════════════════════════════════════════════
// api_transferir_estoque.php — Gestão de Requisições, Alçadas & Auditoria
// ══════════════════════════════════════════════════════════════════
// Pilares Implementados:
//   1. Matriz de Alçada Parametrizável (Limite por valor total).
//   2. Estratégias de Liberação (Release Strategies para Medicamentos e Vacinas).
//   3. Delegação e Ausência (Substituto Temporário de Férias).
//   4. Rastreabilidade Absoluta (Trilha de Auditoria imutável).
// ══════════════════════════════════════════════════════════════════
require 'seguranca_api.php';
require_once __DIR__ . '/includes/unidades.php';
exigirMetodo('POST');
exigirCargo(['Gerente', 'Admin']);

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$acao = trim((string)($data['acao'] ?? 'solicitar'));

$usuarioNomeSessao = $_SESSION['usuario_nome'] ?? 'Usuário';

function falhar(string $msg, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

try {
    // ══════════════════════════════════════════════════════════════
    // AÇÃO 1: SOLICITAR (Abertura de Requisição + Release Strategies)
    // ══════════════════════════════════════════════════════════════
    if ($acao === 'solicitar') {
        $origemId   = (int)($data['origem_id'] ?? 0); // Loja fornecedora
        $produtoId  = (int)($data['produto_id'] ?? 0); // Produto na origem
        $qtd        = $data['quantidade'] ?? null;
        $observacao = trim((string)($data['observacao'] ?? ''));
        $observacao = $observacao !== '' ? mb_substr($observacao, 0, 255) : null;

        $destinoId  = (int)$id_loja; // Loja ativa solicitante

        if (!$origemId || !$produtoId) {
            falhar('Selecione a unidade fornecedora e o produto desejado.');
        }
        if ($origemId === $destinoId) {
            falhar('A unidade fornecedora deve ser diferente da sua unidade atual.');
        }
        if (!is_numeric($qtd) || (int)$qtd != $qtd || (int)$qtd <= 0) {
            falhar('Informe uma quantidade inteira maior que zero.');
        }
        $qtd = (int)$qtd;

        // Valida se ambas as unidades pertencem à mesma rede ativa
        $stLojas = $pdo->prepare("SELECT id, rede_id, nome_fantasia, nome_unidade FROM instituicoes WHERE id IN (?, ?) AND ativo = 1");
        $stLojas->execute([$origemId, $destinoId]);
        $lojas = $stLojas->fetchAll(PDO::FETCH_UNIQUE);

        if (!isset($lojas[$origemId], $lojas[$destinoId])) {
            falhar('Uma das unidades informadas não foi encontrada ou está inativa.', 404);
        }
        $redeId = (int)($lojas[$origemId]['rede_id'] ?? 0);
        if (!$redeId || (int)($lojas[$destinoId]['rede_id'] ?? 0) !== $redeId) {
            falhar('Só é possível requisitar itens entre unidades da mesma rede.', 403);
        }

        $pdo->beginTransaction();

        // 1. Consulta e trava o produto na unidade fornecedora
        $stProd = $pdo->prepare("SELECT * FROM produtos WHERE id = ? AND instituicao_id = ? FOR UPDATE");
        $stProd->execute([$produtoId, $origemId]);
        $prod = $stProd->fetch();
        if (!$prod) {
            $pdo->rollBack();
            falhar('Produto não encontrado na unidade fornecedora.');
        }

        // 2. Baixa condicional na Origem (sai do estoque para retenção 'em trânsito')
        $baixa = $pdo->prepare("UPDATE produtos SET quantidade_estoque = quantidade_estoque - ?
                                WHERE id = ? AND instituicao_id = ? AND quantidade_estoque >= ?");
        $baixa->execute([$qtd, $produtoId, $origemId, $qtd]);
        if ($baixa->rowCount() !== 1) {
            $pdo->rollBack();
            falhar("Estoque insuficiente na unidade fornecedora. Saldo disponível: " . (int)$prod['quantidade_estoque'] . ".");
        }

        // 3. Avaliação de Alçadas & Release Strategies
        $precoReferencia = (float)($prod['preco_custo'] > 0 ? $prod['preco_custo'] : $prod['preco_venda']);
        $valorTotal = round($qtd * $precoReferencia, 2);
        $categoriaItem = $prod['categoria'] ?? 'Geral';

        $analiseAlcada = avaliarAlcadaTransferencia($pdo, $redeId, $valorTotal, $categoriaItem);
        $exigeSuperior = $analiseAlcada['exige_superior'] ? 1 : 0;
        $motivoSuperior = $analiseAlcada['exige_superior'] ? $analiseAlcada['motivo'] : null;

        // Define status inicial: se exige aprovação superior, vai para 'aguardando_aprovacao_superior'
        $statusInicial = $exigeSuperior ? 'aguardando_aprovacao_superior' : 'em_transito';

        // 4. Registra a requisição
        $ins = $pdo->prepare("INSERT INTO transferencias_estoque
            (rede_id, origem_id, destino_id, produto_origem_id, produto_destino_id, nome_produto, quantidade, valor_total, exige_superior, motivo_superior, observacao, usuario_id, status, data_transferencia)
            VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $ins->execute([
            $redeId, $origemId, $destinoId, $produtoId, $prod['nome_produto'], $qtd, $valorTotal, $exigeSuperior, $motivoSuperior, $observacao, $usuario_id, $statusInicial
        ]);
        $transferenciaId = (int)$pdo->lastInsertId();

        // 5. Trilha de Auditoria (Audit Trail)
        registrarAuditoriaTransferencia(
            $pdo,
            $transferenciaId,
            $usuario_id,
            $usuarioNomeSessao,
            'solicitacao_criada',
            null,
            $statusInicial,
            "Requisição criada para $qtd un. de \"{$prod['nome_produto']}\". Valor Total: R$ " . number_format($valorTotal, 2, ',', '.') . ($observacao ? " | Obs: $observacao" : "")
        );

        if ($exigeSuperior) {
            registrarAuditoriaTransferencia(
                $pdo,
                $transferenciaId,
                null,
                'Motor de Alçadas & Compliance',
                'alcada_superior_exigida',
                $statusInicial,
                $statusInicial,
                "Release Strategy ativada: {$analiseAlcada['motivo']}"
            );
        }

        $pdo->commit();

        $nomeFornecedora = trim((string)($lojas[$origemId]['nome_unidade'] ?: $lojas[$origemId]['nome_fantasia']));
        $msg = "Requisição #$transferenciaId enviada para $nomeFornecedora! A quantidade de $qtd un. está retida em trânsito.";
        if ($exigeSuperior) {
            $msg .= " Nota: Esta transferência requer liberação superior do Gerente Geral ({$analiseAlcada['motivo']}).";
        }

        echo json_encode([
            'success'          => true,
            'message'          => $msg,
            'transferencia_id' => $transferenciaId,
            'exige_superior'   => (bool)$exigeSuperior,
        ]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════
    // AÇÃO 2: AUTORIZAR (Aprovação por Gestor de Loja ou Gerente Geral)
    // ══════════════════════════════════════════════════════════════
    if ($acao === 'autorizar') {
        $transfId = (int)($data['transferencia_id'] ?? 0);
        if (!$transfId) falhar('ID da transferência não fornecido.');

        $pdo->beginTransaction();

        $stT = $pdo->prepare("SELECT * FROM transferencias_estoque WHERE id = ? FOR UPDATE");
        $stT->execute([$transfId]);
        $tr = $stT->fetch();

        if (!$tr) {
            $pdo->rollBack();
            falhar('Transferência não encontrada.', 404);
        }

        $statusAtual = $tr['status'];
        if ($statusAtual !== 'em_transito' && $statusAtual !== 'aguardando_aprovacao_superior') {
            $pdo->rollBack();
            falhar("Esta requisição já foi finalizada com status: {$statusAtual}.");
        }

        // Validação de alçada superior
        $ehGeral = usuarioEhGerenteGeral($pdo, (int)$usuario_id, (int)$tr['rede_id']);
        $ehAdmin = ($usuario_cargo === 'Admin');

        if ($statusAtual === 'aguardando_aprovacao_superior') {
            if (!$ehGeral && !$ehAdmin) {
                $pdo->rollBack();
                falhar("Esta transferência excede a alçada da loja ou envolve itens controlados. Apenas o Gerente Geral da Rede pode liberá-la.", 403);
            }
            $tipoAprovacao = 'aprovacao_superior';
            $descricaoAuditoria = "Aprovação de alçada superior concedida pelo Gerente Geral.";
        } else {
            // Em trânsito normal: autoriza gestor da loja fornecedora, substituto de férias ou Gerente Geral
            if (!$ehGeral && !$ehAdmin && !usuarioEhGestorUnidade($pdo, (int)$usuario_id, (int)$tr['origem_id'])) {
                $pdo->rollBack();
                falhar('Apenas o gestor responsável (ou substituto designado) pela unidade fornecedora pode autorizar o envio.', 403);
            }

            // Verifica se a aprovação foi feita por substituto temporário
            $gestorDados = obterGestorUnidade($pdo, (int)$tr['origem_id']);
            if (!empty($gestorDados['substituto_id']) && (int)$gestorDados['substituto_id'] === (int)$usuario_id && !empty($gestorDados['esta_ausente'])) {
                $tipoAprovacao = 'aprovacao_substituto';
                $descricaoAuditoria = "Aprovação concedida por {$usuarioNomeSessao} como Substituto Temporário (Delegação por férias/ausência).";
            } else {
                $tipoAprovacao = 'aprovacao_loja';
                $descricaoAuditoria = "Envio autorizado pelo gestor da unidade fornecedora ({$usuarioNomeSessao}).";
            }
        }

        $origemId  = (int)$tr['origem_id'];
        $destinoId = (int)$tr['destino_id'];
        $qtd       = (int)$tr['quantidade'];

        // Busca dados do produto de origem
        $stP = $pdo->prepare("SELECT * FROM produtos WHERE id = ?");
        $stP->execute([$tr['produto_origem_id']]);
        $prodOrigem = $stP->fetch();

        // Localiza ou cria o produto na unidade de Destino
        $destProdId = null;
        $codigo = trim((string)($prodOrigem['codigo_barras'] ?? ''));

        if ($codigo !== '') {
            $stDest = $pdo->prepare("SELECT id FROM produtos WHERE instituicao_id = ? AND codigo_barras = ? FOR UPDATE");
            $stDest->execute([$destinoId, $codigo]);
            $destProdId = $stDest->fetchColumn();
        }
        if (!$destProdId) {
            $stDest = $pdo->prepare("SELECT id FROM produtos WHERE instituicao_id = ? AND nome_produto = ? FOR UPDATE");
            $stDest->execute([$destinoId, $tr['nome_produto']]);
            $destProdId = $stDest->fetchColumn();
        }

        if ($destProdId) {
            $pdo->prepare("UPDATE produtos SET quantidade_estoque = quantidade_estoque + ? WHERE id = ? AND instituicao_id = ?")
                ->execute([$qtd, $destProdId, $destinoId]);
        } else {
            $insP = $pdo->prepare("INSERT INTO produtos
                (instituicao_id, nome_produto, marca, categoria, descricao, codigo_barras, preco_custo, preco_venda,
                 quantidade_estoque, unidade, tamanho_embalagem_kg, energia_metabolizavel_kg, fornecedor,
                 data_chegada, data_fabricacao, data_validade)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?)");
            $insP->execute([
                $destinoId,
                $prodOrigem['nome_produto'] ?? $tr['nome_produto'],
                $prodOrigem['marca'] ?? null,
                $prodOrigem['categoria'] ?? 'Geral',
                $prodOrigem['descricao'] ?? null,
                $codigo !== '' ? $codigo : null,
                $prodOrigem['preco_custo'] ?? 0,
                $prodOrigem['preco_venda'] ?? 0,
                $qtd,
                $prodOrigem['unidade'] ?? 'UN',
                $prodOrigem['tamanho_embalagem_kg'] ?? null,
                $prodOrigem['energia_metabolizavel_kg'] ?? null,
                $prodOrigem['fornecedor'] ?? null,
                $prodOrigem['data_fabricacao'] ?? null,
                $prodOrigem['data_validade'] ?? null,
            ]);
            $destProdId = (int)$pdo->lastInsertId();
        }

        // Atualiza a transferência para 'recebida'
        $upT = $pdo->prepare("UPDATE transferencias_estoque SET
            status = 'recebida',
            produto_destino_id = ?,
            aprovador_id = ?,
            data_resposta = NOW(),
            recebedor_id = ?,
            data_recebimento = NOW()
            WHERE id = ?");
        $upT->execute([$destProdId, $usuario_id, $usuario_id, $transfId]);

        // Trilha de Auditoria
        registrarAuditoriaTransferencia(
            $pdo,
            $transfId,
            $usuario_id,
            $usuarioNomeSessao,
            $tipoAprovacao,
            $statusAtual,
            'recebida',
            $descricaoAuditoria . " Estoque creditado na loja de destino (Produto ID #$destProdId)."
        );

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => "Envio autorizado com sucesso! A transferência de $qtd un. de \"{$tr['nome_produto']}\" foi concluída e o estoque já está liberado no destino.",
        ]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════
    // AÇÃO 3: NEGAR (Recusa com Justificativa Plausível Obrigatória)
    // ══════════════════════════════════════════════════════════════
    if ($acao === 'negar') {
        $transfId      = (int)($data['transferencia_id'] ?? 0);
        $justificativa = trim((string)($data['justificativa'] ?? ''));

        if (!$transfId) falhar('ID da transferência não fornecido.');
        if (mb_strlen($justificativa) < 4) {
            falhar('Por favor, informe uma justificativa plausível para negar o pedido (mínimo de 4 caracteres).');
        }
        $justificativa = mb_substr($justificativa, 0, 255);

        $pdo->beginTransaction();

        $stT = $pdo->prepare("SELECT * FROM transferencias_estoque WHERE id = ? FOR UPDATE");
        $stT->execute([$transfId]);
        $tr = $stT->fetch();

        if (!$tr) {
            $pdo->rollBack();
            falhar('Transferência não encontrada.', 404);
        }
        $statusAtual = $tr['status'];
        if ($statusAtual !== 'em_transito' && $statusAtual !== 'aguardando_aprovacao_superior') {
            $pdo->rollBack();
            falhar("Esta requisição já foi finalizada com status: {$statusAtual}.");
        }

        // Validação de autoridade
        $ehGeral = usuarioEhGerenteGeral($pdo, (int)$usuario_id, (int)$tr['rede_id']);
        $ehAdmin = ($usuario_cargo === 'Admin');

        if (!$ehGeral && !$ehAdmin && !usuarioEhGestorUnidade($pdo, (int)$usuario_id, (int)$tr['origem_id'])) {
            $pdo->rollBack();
            falhar('Apenas o gestor responsável pela unidade fornecedora ou Gerente Geral pode recusar a requisição.', 403);
        }

        $origemId  = (int)$tr['origem_id'];
        $prodId    = (int)$tr['produto_origem_id'];
        $qtd       = (int)$tr['quantidade'];

        // Estorno automático do saldo de volta para a Origem
        if ($prodId) {
            $pdo->prepare("UPDATE produtos SET quantidade_estoque = quantidade_estoque + ? WHERE id = ? AND instituicao_id = ?")
                ->execute([$qtd, $prodId, $origemId]);
        }

        // Atualiza a transferência para 'negada'
        $pdo->prepare("UPDATE transferencias_estoque SET
            status = 'negada',
            justificativa = ?,
            aprovador_id = ?,
            data_resposta = NOW()
            WHERE id = ?")
            ->execute([$justificativa, $usuario_id, $transfId]);

        // Trilha de Auditoria
        registrarAuditoriaTransferencia(
            $pdo,
            $transfId,
            $usuario_id,
            $usuarioNomeSessao,
            'recusa_pedido',
            $statusAtual,
            'negada',
            "Requisição recusada por {$usuarioNomeSessao}. Motivo registrado: \"$justificativa\". Estoque estornado à origem."
        );

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => "Requisição negada com sucesso. O estoque de $qtd un. de \"{$tr['nome_produto']}\" foi retornado ao saldo da loja fornecedora.",
        ]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════
    // AÇÃO 4: CANCELAR (Solicitante cancela antes da aprovação)
    // ══════════════════════════════════════════════════════════════
    if ($acao === 'cancelar') {
        $transfId = (int)($data['transferencia_id'] ?? 0);
        $motivo   = trim((string)($data['motivo'] ?? 'Cancelado pelo solicitante'));
        $motivo   = mb_substr($motivo, 0, 255);

        if (!$transfId) falhar('ID da transferência não fornecido.');

        $pdo->beginTransaction();

        $stT = $pdo->prepare("SELECT * FROM transferencias_estoque WHERE id = ? FOR UPDATE");
        $stT->execute([$transfId]);
        $tr = $stT->fetch();

        if (!$tr) {
            $pdo->rollBack();
            falhar('Transferência não encontrada.', 404);
        }
        $statusAtual = $tr['status'];
        if ($statusAtual !== 'em_transito' && $statusAtual !== 'aguardando_aprovacao_superior') {
            $pdo->rollBack();
            falhar("Esta requisição já se encontra no status: {$statusAtual}.");
        }

        $ehSolicitante = ((int)$tr['usuario_id'] === (int)$usuario_id);
        $ehGestorDest  = usuarioEhGestorUnidade($pdo, (int)$usuario_id, (int)$tr['destino_id']);
        $ehGeral       = usuarioEhGerenteGeral($pdo, (int)$usuario_id, (int)$tr['rede_id']);

        if (!$ehSolicitante && !$ehGestorDest && !$ehGeral && $usuario_cargo !== 'Admin') {
            $pdo->rollBack();
            falhar('Você não tem permissão para cancelar esta requisição.', 403);
        }

        $origemId = (int)$tr['origem_id'];
        $prodId   = (int)$tr['produto_origem_id'];
        $qtd      = (int)$tr['quantidade'];

        if ($prodId) {
            $pdo->prepare("UPDATE produtos SET quantidade_estoque = quantidade_estoque + ? WHERE id = ? AND instituicao_id = ?")
                ->execute([$qtd, $prodId, $origemId]);
        }

        $pdo->prepare("UPDATE transferencias_estoque SET
            status = 'cancelada',
            justificativa = ?,
            data_resposta = NOW()
            WHERE id = ?")
            ->execute([$motivo, $transfId]);

        // Trilha de Auditoria
        registrarAuditoriaTransferencia(
            $pdo,
            $transfId,
            $usuario_id,
            $usuarioNomeSessao,
            'cancelamento_solicitante',
            $statusAtual,
            'cancelada',
            "Requisição cancelada por {$usuarioNomeSessao}. Motivo: \"$motivo\". Estoque estornado à origem."
        );

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => "Requisição cancelada com sucesso. O estoque foi devolvido para a unidade fornecedora.",
        ]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════
    // AÇÃO 5: TRANSFERÊNCIA DIRETA (Envio Imediato com Auditoria)
    // ══════════════════════════════════════════════════════════════
    if ($acao === 'direta') {
        $produtoId  = (int)($data['produto_id'] ?? 0);
        $destinoId  = (int)($data['destino_id'] ?? 0);
        $qtd        = $data['quantidade'] ?? null;
        $observacao = trim((string)($data['observacao'] ?? ''));
        $observacao = $observacao !== '' ? mb_substr($observacao, 0, 255) : null;

        if (!$produtoId || !$destinoId) falhar('Selecione o produto e a unidade de destino.');
        if (!is_numeric($qtd) || (int)$qtd != $qtd || (int)$qtd <= 0) falhar('Informe uma quantidade inteira maior que zero.');
        $qtd = (int)$qtd;

        $origemId = (int)$id_loja;
        if ($destinoId === $origemId) falhar('A unidade de destino deve ser diferente da unidade atual.');

        $permitidas = carregarUnidadesUsuario($pdo, (int)$usuario_id);
        if (!isset($permitidas[$origemId], $permitidas[$destinoId])) falhar('Você não tem acesso a uma das unidades informadas.', 403);
        $redeId = $permitidas[$origemId]['rede_id'];
        if (!$redeId || (int)$permitidas[$destinoId]['rede_id'] !== (int)$redeId) falhar('Só é possível transferir entre unidades da mesma rede.', 403);

        $pdo->beginTransaction();

        $st = $pdo->prepare("SELECT * FROM produtos WHERE id = ? AND instituicao_id = ? FOR UPDATE");
        $st->execute([$produtoId, $origemId]);
        $prod = $st->fetch();
        if (!$prod) { $pdo->rollBack(); falhar('Produto não encontrado nesta unidade.'); }

        $baixa = $pdo->prepare("UPDATE produtos SET quantidade_estoque = quantidade_estoque - ?
                                WHERE id = ? AND instituicao_id = ? AND quantidade_estoque >= ?");
        $baixa->execute([$qtd, $produtoId, $origemId, $qtd]);
        if ($baixa->rowCount() !== 1) {
            $pdo->rollBack();
            falhar('Estoque insuficiente. Disponível: ' . (int)$prod['quantidade_estoque'] . '.');
        }

        $precoReferencia = (float)($prod['preco_custo'] > 0 ? $prod['preco_custo'] : $prod['preco_venda']);
        $valorTotal = round($qtd * $precoReferencia, 2);

        $codigo = trim((string)($prod['codigo_barras'] ?? ''));
        if ($codigo !== '') {
            $st = $pdo->prepare("SELECT id FROM produtos WHERE instituicao_id = ? AND codigo_barras = ? FOR UPDATE");
            $st->execute([$destinoId, $codigo]);
        } else {
            $st = $pdo->prepare("SELECT id FROM produtos WHERE instituicao_id = ? AND nome_produto = ? FOR UPDATE");
            $st->execute([$destinoId, $prod['nome_produto']]);
        }
        $destProdId = $st->fetchColumn();

        if ($destProdId) {
            $pdo->prepare("UPDATE produtos SET quantidade_estoque = quantidade_estoque + ? WHERE id = ? AND instituicao_id = ?")
                ->execute([$qtd, $destProdId, $destinoId]);
        } else {
            $ins = $pdo->prepare("INSERT INTO produtos
                (instituicao_id, nome_produto, marca, categoria, descricao, codigo_barras, preco_custo, preco_venda,
                 quantidade_estoque, unidade, tamanho_embalagem_kg, energia_metabolizavel_kg, fornecedor,
                 data_chegada, data_fabricacao, data_validade)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?)");
            $ins->execute([
                $destinoId, $prod['nome_produto'], $prod['marca'], $prod['categoria'] ?? 'Geral', $prod['descricao'],
                $codigo !== '' ? $codigo : null, $prod['preco_custo'], $prod['preco_venda'],
                $qtd, $prod['unidade'], $prod['tamanho_embalagem_kg'], $prod['energia_metabolizavel_kg'],
                $prod['fornecedor'], $prod['data_fabricacao'], $prod['data_validade'],
            ]);
            $destProdId = (int)$pdo->lastInsertId();
        }

        $pdo->prepare("INSERT INTO transferencias_estoque
            (rede_id, origem_id, destino_id, produto_origem_id, produto_destino_id, nome_produto, quantidade, valor_total, observacao, usuario_id, status, aprovador_id, data_resposta, data_transferencia)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'recebida', ?, NOW(), NOW())")
            ->execute([$redeId, $origemId, $destinoId, $produtoId, $destProdId, $prod['nome_produto'], $qtd, $valorTotal, $observacao, $usuario_id, $usuario_id]);
        $transfId = (int)$pdo->lastInsertId();

        // Trilha de Auditoria
        registrarAuditoriaTransferencia(
            $pdo,
            $transfId,
            $usuario_id,
            $usuarioNomeSessao,
            'transferencia_direta',
            null,
            'recebida',
            "Transferência direta executada por {$usuarioNomeSessao}. Qtd: $qtd | Valor: R$ " . number_format($valorTotal, 2, ',', '.')
        );

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => "Transferidas $qtd un. de \"{$prod['nome_produto']}\" para {$permitidas[$destinoId]['rotulo']}.",
        ]);
        exit;
    }

    falhar('Ação desconhecida.');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro interno ao processar requisição: ' . $e->getMessage()]);
}
