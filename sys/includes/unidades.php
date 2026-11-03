<?php
// ══════════════════════════════════════════════════════════════════
// includes/unidades.php — Helpers de Multi-Unidade (Rede → Unidades)
// ══════════════════════════════════════════════════════════════════
// Regra de acesso de um usuário (não-Admin) às unidades:
//   1. sua unidade de origem (usuarios.instituicao_id)
//   2. unidades extras vinculadas em usuario_unidades
//   3. se usuarios.acesso_rede = 1 → TODAS as unidades da rede da sua origem
// Só unidades ativas (instituicoes.ativo = 1) são retornadas.
// ══════════════════════════════════════════════════════════════════

if (!function_exists('unidadeRotulo')) {
    /** "Nome da Unidade · Cidade/UF" (cai para o nome fantasia se não houver nome de unidade). */
    function unidadeRotulo(array $u): string {
        $nome = trim((string)($u['nome_unidade'] ?? '')) !== '' ? $u['nome_unidade'] : $u['nome_fantasia'];
        $local = trim((string)($u['cidade'] ?? ''));
        if ($local !== '' && !empty($u['uf'])) {
            $local .= '/' . $u['uf'];
        }
        return $local !== '' && stripos($nome, $u['cidade']) === false ? "$nome · $local" : $nome;
    }
}

if (!function_exists('carregarUnidadesUsuario')) {
    /**
     * Retorna as unidades ativas que o usuário pode acessar.
     * @return array<int,array> [id => linha da instituição + rotulo], matriz primeiro.
     */
    function carregarUnidadesUsuario(PDO $pdo, int $usuarioId): array {
        $st = $pdo->prepare("SELECT u.instituicao_id, u.acesso_rede, i.rede_id
                             FROM usuarios u
                             LEFT JOIN instituicoes i ON i.id = u.instituicao_id
                             WHERE u.id = ?");
        $st->execute([$usuarioId]);
        $usr = $st->fetch();
        if (!$usr || empty($usr['instituicao_id'])) {
            return [];
        }

        $ids = [(int)$usr['instituicao_id']];

        $st = $pdo->prepare("SELECT instituicao_id FROM usuario_unidades WHERE usuario_id = ?");
        $st->execute([$usuarioId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $ids[] = (int)$id;
        }

        if ((int)$usr['acesso_rede'] === 1 && !empty($usr['rede_id'])) {
            $st = $pdo->prepare("SELECT id FROM instituicoes WHERE rede_id = ?");
            $st->execute([$usr['rede_id']]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $ids[] = (int)$id;
            }
        }

        $ids = array_values(array_unique($ids));
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $st  = $pdo->prepare("SELECT id, rede_id, nome_fantasia, nome_unidade, cidade, uf, matriz, gestor_id
                              FROM instituicoes
                              WHERE ativo = 1 AND id IN ($in)
                              ORDER BY matriz DESC, COALESCE(NULLIF(nome_unidade, ''), nome_fantasia) ASC");
        $st->execute($ids);

        $unidades = [];
        foreach ($st->fetchAll() as $row) {
            $row['rotulo'] = unidadeRotulo($row);
            $unidades[(int)$row['id']] = $row;
        }
        return $unidades;
    }
}

if (!function_exists('definirContextoUnidades')) {
    /**
     * Popula a sessão com o contexto multi-unidade e garante que a unidade ativa é válida.
     * Retorna false se o usuário não tem nenhuma unidade ativa (login deve ser bloqueado).
     */
    function definirContextoUnidades(PDO $pdo, int $usuarioId, ?int $unidadeDesejada = null): bool {
        $unidades = carregarUnidadesUsuario($pdo, $usuarioId);
        if (!$unidades) {
            return false;
        }

        $ativa = $unidadeDesejada ?? (int)($_SESSION['instituicao_id'] ?? 0);
        if (!isset($unidades[$ativa])) {
            $ativa = (int)array_key_first($unidades);
        }

        $_SESSION['instituicao_id']       = $ativa;
        $_SESSION['rede_id']              = $unidades[$ativa]['rede_id'] ? (int)$unidades[$ativa]['rede_id'] : null;
        $_SESSION['unidades_permitidas']  = array_map(fn($u) => $u['rotulo'], $unidades);
        return true;
    }
}

if (!function_exists('temMultiplasUnidades')) {
    function temMultiplasUnidades(): bool {
        return count($_SESSION['unidades_permitidas'] ?? []) > 1;
    }
}

if (!function_exists('limparCacheUnidades')) {
    function limparCacheUnidades(): void {
        obterConfigRede(new PDO('sqlite::memory:'), 0, true);
        obterInstituicoesClientes(new PDO('sqlite::memory:'), 0, true);
        obterInstituicaoFidelidade(new PDO('sqlite::memory:'), 0, true);
    }
}

if (!function_exists('obterConfigRede')) {
    /**
     * Retorna a configuração da rede (ou null se loja independente).
     * @return ?array [id, nome, compartilhar_clientes, compartilhar_fidelidade, ativo]
     */
    function obterConfigRede(PDO $pdo, int $instituicaoId, bool $forcarRecarregar = false): ?array {
        static $cache = [];
        if ($forcarRecarregar) {
            $cache = [];
        }
        if (isset($cache[$instituicaoId])) {
            return $cache[$instituicaoId];
        }
        $st = $pdo->prepare("SELECT r.id, r.nome, r.compartilhar_clientes, r.compartilhar_fidelidade, r.ativo,
                                    r.limite_alcada_loja, r.exige_aprovacao_medicamentos
                             FROM redes r
                             JOIN instituicoes i ON i.rede_id = r.id
                             WHERE i.id = ?");
        $st->execute([$instituicaoId]);
        $res = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $cache[$instituicaoId] = $res;
        return $res;
    }
}

if (!function_exists('obterInstituicoesClientes')) {
    /**
     * Retorna IDs de instituições cujos clientes e pets são visíveis para a instituição informada.
     * Se a rede tiver compartilhar_clientes = 1, retorna todas as unidades ativas da rede.
     * Caso contrário, retorna apenas [$instituicaoId].
     * @return int[]
     */
    function obterInstituicoesClientes(PDO $pdo, int $instituicaoId, bool $forcarRecarregar = false): array {
        static $cache = [];
        if ($forcarRecarregar) {
            $cache = [];
            return [];
        }
        if (isset($cache[$instituicaoId])) {
            return $cache[$instituicaoId];
        }
        $rede = obterConfigRede($pdo, $instituicaoId);
        if ($rede && !empty($rede['compartilhar_clientes'])) {
            $st = $pdo->prepare("SELECT id FROM instituicoes WHERE rede_id = ? AND ativo = 1 ORDER BY matriz DESC, id ASC");
            $st->execute([$rede['id']]);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            if (!empty($ids)) {
                $cache[$instituicaoId] = $ids;
                return $ids;
            }
        }
        $res = [$instituicaoId];
        $cache[$instituicaoId] = $res;
        return $res;
    }
}

if (!function_exists('obterInstituicaoFidelidade')) {
    /**
     * Retorna o ID da instituição responsável pelo programa de fidelidade.
     * Se a rede compartilhar fidelidade, retorna a Matriz da rede (ou a primeira unidade da rede).
     * Caso contrário, retorna a própria instituição ($instituicaoId).
     */
    function obterInstituicaoFidelidade(PDO $pdo, int $instituicaoId, bool $forcarRecarregar = false): int {
        static $cache = [];
        if ($forcarRecarregar) {
            $cache = [];
            return 0;
        }
        if (isset($cache[$instituicaoId])) {
            return $cache[$instituicaoId];
        }
        $rede = obterConfigRede($pdo, $instituicaoId);
        if ($rede && !empty($rede['compartilhar_fidelidade'])) {
            $st = $pdo->prepare("SELECT id FROM instituicoes WHERE rede_id = ? AND ativo = 1 ORDER BY matriz DESC, id ASC LIMIT 1");
            $st->execute([$rede['id']]);
            $matrizId = $st->fetchColumn();
            if ($matrizId) {
                $res = (int)$matrizId;
                $cache[$instituicaoId] = $res;
                return $res;
            }
        }
        $res = $instituicaoId;
        $cache[$instituicaoId] = $res;
        return $res;
    }
}

if (!function_exists('obterGestorUnidade')) {
    /**
     * Retorna os dados do gestor atribuído a uma unidade (ou null se não definido),
     * incluindo substituto temporário e status de ausência/férias.
     */
    function obterGestorUnidade(PDO $pdo, int $instituicaoId): ?array {
        $st = $pdo->prepare("SELECT u.id, u.nome, u.email, u.cargo,
                                    i.substituto_id, sub.nome AS substituto_nome, sub.email AS substituto_email,
                                    i.ausente_inicio, i.ausente_fim,
                                    (i.substituto_id IS NOT NULL AND CURDATE() BETWEEN i.ausente_inicio AND i.ausente_fim) AS esta_ausente
                             FROM instituicoes i
                             LEFT JOIN usuarios u ON u.id = i.gestor_id
                             LEFT JOIN usuarios sub ON sub.id = i.substituto_id
                             WHERE i.id = ? AND (u.ativo = 1 OR i.gestor_id IS NULL)");
        $st->execute([$instituicaoId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || (empty($row['id']) && empty($row['substituto_id']))) {
            return null;
        }
        $row['esta_ausente'] = (bool)($row['esta_ausente'] ?? false);
        return $row;
    }
}

if (!function_exists('usuarioEhGestorUnidade')) {
    /**
     * Verifica se o usuário tem autoridade de gestor sobre a unidade especificada:
     * 1. É o gestor oficialmente designado da unidade (instituicoes.gestor_id = $usuarioId).
     * 2. OU é o Substituto Temporário ATIVO no período de ausência/férias do titular.
     * 3. OU é Gerente Geral da rede (usuarios.acesso_rede = 1) na mesma rede da unidade.
     * 4. OU, se não houver gestor oficial definido, é um Gerente com acesso a essa unidade.
     */
    function usuarioEhGestorUnidade(PDO $pdo, int $usuarioId, int $instituicaoId): bool {
        $st = $pdo->prepare("SELECT u.cargo, u.acesso_rede, u.instituicao_id, i.rede_id, i.gestor_id,
                                    i.substituto_id, i.ausente_inicio, i.ausente_fim,
                                    (i.substituto_id = u.id AND CURDATE() BETWEEN i.ausente_inicio AND i.ausente_fim) AS eh_substituto_ativo
                             FROM usuarios u
                             CROSS JOIN instituicoes i ON i.id = ?
                             WHERE u.id = ?");
        $st->execute([$instituicaoId, $usuarioId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }

        // 1. Gestor titular designado
        if (!empty($row['gestor_id']) && (int)$row['gestor_id'] === $usuarioId) {
            return true;
        }

        // 2. Substituto temporário ativo (delegação de ausência/férias)
        if (!empty($row['eh_substituto_ativo'])) {
            return true;
        }

        // Se não for gestor nem substituto, precisa ser cargo Gerente para os próximos passos
        if ($row['cargo'] !== 'Gerente') {
            return false;
        }

        // 3. Gerente Geral da rede
        if ((int)$row['acesso_rede'] === 1 && !empty($row['rede_id'])) {
            $stR = $pdo->prepare("SELECT i.rede_id FROM instituicoes i WHERE i.id = ?");
            $stR->execute([$row['instituicao_id']]);
            $usrRede = $stR->fetchColumn();
            if ($usrRede && (int)$usrRede === (int)$row['rede_id']) {
                return true;
            }
        }

        // 4. Sem gestor designado: qualquer gerente com acesso à unidade pode agir
        if (empty($row['gestor_id'])) {
            $unidadesUsr = carregarUnidadesUsuario($pdo, $usuarioId);
            return isset($unidadesUsr[$instituicaoId]);
        }

        return false;
    }
}

if (!function_exists('usuarioEhGerenteGeral')) {
    /**
     * Verifica se o usuário é o Gerente Geral da Rede (acesso_rede = 1) ou Admin do SaaS.
     */
    function usuarioEhGerenteGeral(PDO $pdo, int $usuarioId, ?int $redeId = null): bool {
        $st = $pdo->prepare("SELECT u.cargo, u.acesso_rede, i.rede_id
                             FROM usuarios u
                             LEFT JOIN instituicoes i ON i.id = u.instituicao_id
                             WHERE u.id = ?");
        $st->execute([$usuarioId]);
        $usr = $st->fetch(PDO::FETCH_ASSOC);
        if (!$usr) return false;
        if ($usr['cargo'] === 'Admin') return true;
        if ($usr['cargo'] === 'Gerente' && (int)$usr['acesso_rede'] === 1) {
            if ($redeId !== null) {
                return (int)($usr['rede_id'] ?? 0) === $redeId;
            }
            return true;
        }
        return false;
    }
}

if (!function_exists('avaliarAlcadaTransferencia')) {
    /**
     * Motor de Alçadas & Release Strategies:
     * Avalia se a transferência requer aprovação superior (Gerente Geral):
     * - Se a categoria for Medicamento/Vacina e a rede exigir aprovação de medicamentos.
     * - OU se o valor total da transferência exceder o limite de alçada da loja.
     * @return array [exige_superior => bool, motivo => string, limite => float]
     */
    function avaliarAlcadaTransferencia(PDO $pdo, int $redeId, float $valorTotal, string $categoria): array {
        $st = $pdo->prepare("SELECT limite_alcada_loja, exige_aprovacao_medicamentos FROM redes WHERE id = ?");
        $st->execute([$redeId]);
        $cfg = $st->fetch(PDO::FETCH_ASSOC);

        $limite = (float)($cfg['limite_alcada_loja'] ?? 1000.00);
        $exigeMeds = (bool)($cfg['exige_aprovacao_medicamentos'] ?? true);

        // 1. Release Strategy por categoria sensível (Medicamentos e Vacinas)
        if ($exigeMeds && in_array($categoria, ['Medicamento', 'Vacina'], true)) {
            return [
                'exige_superior' => true,
                'motivo'         => "Categoria restrita (\"$categoria\"): requer liberação técnica do Gerente Geral.",
                'limite'         => $limite,
            ];
        }

        // 2. Alçada por valor monetário total
        if ($valorTotal > $limite) {
            $valFmt = number_format($valorTotal, 2, ',', '.');
            $limFmt = number_format($limite, 2, ',', '.');
            return [
                'exige_superior' => true,
                'motivo'         => "Valor total (R$ $valFmt) excede o limite de alçada do gestor de loja (R$ $limFmt).",
                'limite'         => $limite,
            ];
        }

        return [
            'exige_superior' => false,
            'motivo'         => "Dentro da alçada do gestor de loja (R$ " . number_format($limite, 2, ',', '.') . ").",
            'limite'         => $limite,
        ];
    }
}

if (!function_exists('registrarAuditoriaTransferencia')) {
    /**
     * Rastreabilidade Absoluta (Audit Trail):
     * Registra log inalterável de cada evento do ciclo de vida da transferência.
     */
    function registrarAuditoriaTransferencia(
        PDO $pdo,
        int $transfId,
        ?int $usuarioId,
        ?string $usuarioNome,
        string $acao,
        ?string $deStatus,
        string $paraStatus,
        ?string $detalhes = null
    ): void {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $st = $pdo->prepare("INSERT INTO transferencias_auditoria
                (transferencia_id, usuario_id, usuario_nome, acao, de_status, para_status, detalhes, ip, data_hora)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $st->execute([
                $transfId, $usuarioId, $usuarioNome, $acao, $deStatus, $paraStatus, $detalhes, $ip
            ]);
        } catch (Throwable $e) {
            // Não quebra a transação principal em caso de erro no log
            error_log("Erro ao registrar auditoria de transferência: " . $e->getMessage());
        }
    }
}

if (!function_exists('contarRequisicoesPendentesUnidade')) {
    /**
     * Retorna a quantidade de requisições de estoque pendentes para a unidade de Origem (aguardando envio/aprovação).
     */
    function contarRequisicoesPendentesUnidade(PDO $pdo, int $origemId): int {
        $st = $pdo->prepare("SELECT COUNT(*) FROM transferencias_estoque WHERE origem_id = ? AND status IN ('em_transito', 'aguardando_aprovacao_superior')");
        $st->execute([$origemId]);
        return (int)$st->fetchColumn();
    }
}

if (!function_exists('listarCandidatosGestor')) {
    /**
     * Lista usuários ativos de uma rede que podem ser selecionados como gestor de unidade ou substituto.
     */
    function listarCandidatosGestor(PDO $pdo, int $redeId): array {
        $st = $pdo->prepare("SELECT u.id, u.nome, u.email, u.cargo, u.instituicao_id, i.nome_unidade, i.nome_fantasia
                             FROM usuarios u
                             JOIN instituicoes i ON i.id = u.instituicao_id
                             WHERE i.rede_id = ? AND u.ativo = 1 AND u.cargo IN ('Gerente', 'Atendente', 'Veterinário')
                             ORDER BY (u.cargo = 'Gerente') DESC, u.nome ASC");
        $st->execute([$redeId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
