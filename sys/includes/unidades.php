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
        $st  = $pdo->prepare("SELECT id, rede_id, nome_fantasia, nome_unidade, cidade, uf, matriz
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
            return null;
        }
        if (isset($cache[$instituicaoId])) {
            return $cache[$instituicaoId];
        }
        $st = $pdo->prepare("SELECT r.id, r.nome, r.compartilhar_clientes, r.compartilhar_fidelidade, r.ativo
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
