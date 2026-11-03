<?php
// ══════════════════════════════════════════════════════════════════
// api_definir_gestor_unidade.php — Gestão de Alçadas, Gestores & Substitutos
// Autorização: Apenas o Gerente Geral da Rede (ou Admin do SaaS)
// ══════════════════════════════════════════════════════════════════
require 'seguranca_api.php';
require_once __DIR__ . '/includes/unidades.php';
exigirMetodo('POST');

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$tipo = trim((string)($data['tipo'] ?? 'gestor'));

// 1. Valida se o usuário logado é Gerente Geral da rede ou Admin
$isAdmin = ($usuario_cargo === 'Admin');

if (!$isAdmin) {
    if ($usuario_cargo !== 'Gerente') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Apenas o Gerente Geral pode configurar gestores e políticas da rede.']);
        exit;
    }

    $stAcesso = $pdo->prepare("SELECT acesso_rede, instituicao_id FROM usuarios WHERE id = ?");
    $stAcesso->execute([(int)$usuario_id]);
    $usrDados = $stAcesso->fetch();

    if (empty($usrDados['acesso_rede'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Apenas o Gerente Geral da rede tem permissão para estas configurações.']);
        exit;
    }
}

try {
    // ══════════════════════════════════════════════════════════════
    // CASO A: ATUALIZAR POLÍTICAS DE ALÇADA DA REDE
    // ══════════════════════════════════════════════════════════════
    if ($tipo === 'politicas_rede') {
        $redeId = (int)($data['rede_id'] ?? 0);
        $limite = (float)($data['limite_alcada_loja'] ?? 1000.00);
        $exigeMeds = !empty($data['exige_aprovacao_medicamentos']) ? 1 : 0;

        if (!$redeId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Rede não informada.']);
            exit;
        }

        if ($limite < 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'O limite de alçada deve ser maior ou igual a zero.']);
            exit;
        }

        $stUpRede = $pdo->prepare("UPDATE redes SET limite_alcada_loja = ?, exige_aprovacao_medicamentos = ? WHERE id = ?");
        $stUpRede->execute([$limite, $exigeMeds, $redeId]);

        echo json_encode([
            'success' => true,
            'message' => 'Políticas de alçada da rede atualizadas com sucesso!',
            'limite'  => $limite,
            'exige_medicamentos' => $exigeMeds,
        ]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════
    // CASO B: DEFINIR GESTOR TITULAR OU SUBSTITUTO TEMPORÁRIO
    // ══════════════════════════════════════════════════════════════
    $instituicaoId = (int)($data['instituicao_id'] ?? 0);

    if (!$instituicaoId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unidade não informada.']);
        exit;
    }

    $stInst = $pdo->prepare("SELECT id, rede_id, nome_fantasia, nome_unidade, gestor_id, substituto_id FROM instituicoes WHERE id = ?");
    $stInst->execute([$instituicaoId]);
    $unidadeAlvo = $stInst->fetch();

    if (!$unidadeAlvo) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Unidade não encontrada.']);
        exit;
    }

    if (!$isAdmin) {
        $stRedeUsr = $pdo->prepare("SELECT i.rede_id FROM instituicoes i WHERE i.id = ?");
        $stRedeUsr->execute([$usrDados['instituicao_id']]);
        $redeUsr = $stRedeUsr->fetchColumn();

        if (empty($unidadeAlvo['rede_id']) || empty($redeUsr) || (int)$unidadeAlvo['rede_id'] !== (int)$redeUsr) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Você só pode configurar unidades da sua rede.']);
            exit;
        }
    }

    $rotuloUnidade = trim((string)($unidadeAlvo['nome_unidade'] ?: $unidadeAlvo['nome_fantasia']));

    // Substituto temporário (Delegação por férias/ausência)
    if ($tipo === 'substituto') {
        $substitutoId = isset($data['substituto_id']) && $data['substituto_id'] !== '' ? (int)$data['substituto_id'] : null;
        $dtInicio     = !empty($data['ausente_inicio']) ? trim((string)$data['ausente_inicio']) : null;
        $dtFim        = !empty($data['ausente_fim']) ? trim((string)$data['ausente_fim']) : null;

        if ($substitutoId) {
            if (!$dtInicio || !$dtFim) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Informe a data de início e término do período de ausência/férias.']);
                exit;
            }
            if ($dtInicio > $dtFim) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'A data inicial não pode ser posterior à data final.']);
                exit;
            }

            $stSub = $pdo->prepare("SELECT nome, instituicao_id FROM usuarios WHERE id = ? AND ativo = 1");
            $stSub->execute([$substitutoId]);
            $candSub = $stSub->fetch();
            if (!$candSub) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Substituto não encontrado ou inativo.']);
                exit;
            }

            // Garante acesso à unidade
            if ((int)$candSub['instituicao_id'] !== $instituicaoId) {
                $pdo->prepare("INSERT IGNORE INTO usuario_unidades (usuario_id, instituicao_id) VALUES (?, ?)")
                    ->execute([$substitutoId, $instituicaoId]);
            }

            $stUp = $pdo->prepare("UPDATE instituicoes SET substituto_id = ?, ausente_inicio = ?, ausente_fim = ? WHERE id = ?");
            $stUp->execute([$substitutoId, $dtInicio, $dtFim, $instituicaoId]);

            $dtIniFmt = date('d/m/Y', strtotime($dtInicio));
            $dtFimFmt = date('d/m/Y', strtotime($dtFim));

            echo json_encode([
                'success' => true,
                'message' => "Substituto temporário definido para $rotuloUnidade: {$candSub['nome']} (Vigência de $dtIniFmt até $dtFimFmt).",
            ]);
            exit;
        } else {
            // Limpa substituto
            $pdo->prepare("UPDATE instituicoes SET substituto_id = NULL, ausente_inicio = NULL, ausente_fim = NULL WHERE id = ?")
                ->execute([$instituicaoId]);

            echo json_encode([
                'success' => true,
                'message' => "Substituição temporária da unidade $rotuloUnidade foi encerrada.",
            ]);
            exit;
        }
    }

    // Gestor Titular (tipo = 'gestor')
    $gestorId = isset($data['gestor_id']) && $data['gestor_id'] !== '' && $data['gestor_id'] !== null ? (int)$data['gestor_id'] : null;
    $nomeGestor = null;

    if ($gestorId) {
        $stG = $pdo->prepare("SELECT u.id, u.nome, u.email, u.cargo, u.instituicao_id, i.rede_id
                              FROM usuarios u
                              LEFT JOIN instituicoes i ON i.id = u.instituicao_id
                              WHERE u.id = ? AND u.ativo = 1");
        $stG->execute([$gestorId]);
        $cand = $stG->fetch();

        if (!$cand) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'O usuário selecionado não foi encontrado ou está inativo.']);
            exit;
        }

        if (!empty($unidadeAlvo['rede_id']) && (int)($cand['rede_id'] ?? 0) !== (int)$unidadeAlvo['rede_id']) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'O gestor deve pertencer à mesma rede da unidade.']);
            exit;
        }

        $nomeGestor = $cand['nome'];

        if ((int)$cand['instituicao_id'] !== $instituicaoId) {
            $pdo->prepare("INSERT IGNORE INTO usuario_unidades (usuario_id, instituicao_id) VALUES (?, ?)")
                ->execute([$gestorId, $instituicaoId]);
        }
    }

    $stUp = $pdo->prepare("UPDATE instituicoes SET gestor_id = ? WHERE id = ?");
    $stUp->execute([$gestorId, $instituicaoId]);

    if ($gestorId) {
        $msg = "Gestor titular da unidade \"$rotuloUnidade\" definido com sucesso: $nomeGestor!";
    } else {
        $msg = "Gestor responsável da unidade \"$rotuloUnidade\" foi desvinculado.";
    }

    echo json_encode([
        'success' => true,
        'message' => $msg,
        'gestor'  => $gestorId ? ['id' => $gestorId, 'nome' => $nomeGestor] : null,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro interno ao processar: ' . $e->getMessage()]);
}
