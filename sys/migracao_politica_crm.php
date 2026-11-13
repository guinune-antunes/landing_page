<?php
// ══════════════════════════════════════════════════════════════════
// migracao_politica_crm.php — Motor Real de Regras CRM, Reentrada & Promoções
// Execução idempotente no banco configurado em sys/conexao.php:
//   C:\xampp\php\php.exe sys/migracao_politica_crm.php
//   ou acessando via navegador como Admin.
// ══════════════════════════════════════════════════════════════════

if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    session_start();
    if (($_SESSION['usuario_cargo'] ?? '') !== 'Admin') {
        http_response_code(403);
        exit('Acesso restrito ao Admin.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/conexao.php';

if (!function_exists('crmColunaExiste')) {
    function crmColunaExiste(PDO $pdo, string $tabela, string $coluna): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $st->execute([$tabela, $coluna]);
        return (int)$st->fetchColumn() > 0;
    }
}

if (!function_exists('crmResolverTipoItemMigracao')) {
    function crmResolverTipoItemMigracao(PDO $pdo, int $itemId, int $instId, ?string $categoriaAlvo = null): string {
        if ($categoriaAlvo === 'servicos') {
            return 'servico';
        }
        if ($categoriaAlvo === 'produtos') {
            return 'produto';
        }

        $stP = $pdo->prepare("SELECT id FROM produtos WHERE id = ? AND instituicao_id = ? LIMIT 1");
        $stP->execute([$itemId, $instId]);
        if ($stP->fetchColumn()) {
            return 'produto';
        }

        $stS = $pdo->prepare("SELECT id FROM servicos WHERE id = ? AND instituicao_id = ? LIMIT 1");
        $stS->execute([$itemId, $instId]);
        if ($stS->fetchColumn()) {
            return 'servico';
        }

        $stPg = $pdo->prepare("SELECT id FROM produtos WHERE id = ? LIMIT 1");
        $stPg->execute([$itemId]);
        if ($stPg->fetchColumn()) {
            return 'produto';
        }

        $stSg = $pdo->prepare("SELECT id FROM servicos WHERE id = ? LIMIT 1");
        $stSg->execute([$itemId]);
        return $stSg->fetchColumn() ? 'servico' : 'produto';
    }
}

if (!function_exists('aplicarMigracaoPoliticaCrm')) {
    function aplicarMigracaoPoliticaCrm(PDO $pdo, bool $verbose = false): array {
        $logs = [];
        $addCols = function (string $tabela, array $colunas) use ($pdo, &$logs, $verbose) {
            foreach ($colunas as $col => $ddl) {
                if (!crmColunaExiste($pdo, $tabela, $col)) {
                    $pdo->exec("ALTER TABLE `{$tabela}` {$ddl}");
                    $msg = "[+] {$tabela}.{$col} adicionada";
                } else {
                    $msg = "[ok] {$tabela}.{$col}";
                }
                $logs[] = $msg;
                if ($verbose) echo $msg . "\n";
            }
        };

        // 1. clientes.data_nascimento (corrige query de aniversário)
        $addCols('clientes', [
            'data_nascimento' => "ADD COLUMN `data_nascimento` DATE NULL DEFAULT NULL AFTER `telefone`"
        ]);

        // 2. fidelidade_regras — política de reentrada, empilhamento, limites e discriminador produto/serviço
        $addCols('fidelidade_regras', [
            'item_alvo_tipo'         => "ADD COLUMN `item_alvo_tipo` ENUM('produto','servico') NULL DEFAULT NULL AFTER `item_alvo_id`",
            'recompensa_item_tipo'   => "ADD COLUMN `recompensa_item_tipo` ENUM('produto','servico') NULL DEFAULT NULL AFTER `recompensa_item_id`",
            'politica_reentrada'     => "ADD COLUMN `politica_reentrada` ENUM('unica_vez','reiniciar_apos_resgate','reiniciar_ao_concluir','cooldown_dias','ilimitada_por_compra') NOT NULL DEFAULT 'reiniciar_apos_resgate' AFTER `ativo`",
            'limite_ciclos_cliente'  => "ADD COLUMN `limite_ciclos_cliente` INT NULL DEFAULT NULL AFTER `politica_reentrada`",
            'limite_usos_global'     => "ADD COLUMN `limite_usos_global` INT NULL DEFAULT NULL AFTER `limite_ciclos_cliente`",
            'usos_globais_atuais'    => "ADD COLUMN `usos_globais_atuais` INT NOT NULL DEFAULT 0 AFTER `limite_usos_global`",
            'cooldown_dias'          => "ADD COLUMN `cooldown_dias` INT NOT NULL DEFAULT 0 AFTER `usos_globais_atuais`",
            'contagem_por_item'      => "ADD COLUMN `contagem_por_item` TINYINT(1) NOT NULL DEFAULT 0 AFTER `cooldown_dias`",
            'acumular_sobra'         => "ADD COLUMN `acumular_sobra` TINYINT(1) NOT NULL DEFAULT 1 AFTER `contagem_por_item`",
            'auto_resgatar_pdv'      => "ADD COLUMN `auto_resgatar_pdv` TINYINT(1) NOT NULL DEFAULT 1 AFTER `acumular_sobra`",
            'acumular_com_outras'    => "ADD COLUMN `acumular_com_outras` TINYINT(1) NOT NULL DEFAULT 1 AFTER `auto_resgatar_pdv`",
            'prioridade'             => "ADD COLUMN `prioridade` INT NOT NULL DEFAULT 10 AFTER `acumular_com_outras`"
        ]);

        // 3. cliente_progresso_regras — rastreio de resgate, cooldown e bloqueio
        $addCols('cliente_progresso_regras', [
            'ultimo_resgate_em'   => "ADD COLUMN `ultimo_resgate_em` DATETIME NULL DEFAULT NULL AFTER `ultimo_uso`",
            'ultima_venda_id'     => "ADD COLUMN `ultima_venda_id` INT(10) UNSIGNED NULL DEFAULT NULL AFTER `ultimo_resgate_em`",
            'bloqueado_reentrada' => "ADD COLUMN `bloqueado_reentrada` TINYINT(1) NOT NULL DEFAULT 0 AFTER `ultima_venda_id`",
            'observacao_status'   => "ADD COLUMN `observacao_status` VARCHAR(255) NULL DEFAULT NULL AFTER `bloqueado_reentrada`"
        ]);

        // 4. promocoes — política de reentrada e limites na campanha
        $addCols('promocoes', [
            'politica_reentrada'     => "ADD COLUMN `politica_reentrada` ENUM('ilimitada','unica_vez','uma_por_dia','cooldown_dias') NOT NULL DEFAULT 'ilimitada' AFTER `ativo`",
            'limite_usos_cliente'    => "ADD COLUMN `limite_usos_cliente` INT NULL DEFAULT NULL AFTER `politica_reentrada`",
            'cooldown_dias'          => "ADD COLUMN `cooldown_dias` INT NOT NULL DEFAULT 0 AFTER `limite_usos_cliente`",
            'limite_usos_global'     => "ADD COLUMN `limite_usos_global` INT NULL DEFAULT NULL AFTER `cooldown_dias`",
            'usos_globais_atuais'    => "ADD COLUMN `usos_globais_atuais` INT NOT NULL DEFAULT 0 AFTER `limite_usos_global`",
            'aplicar_automatico_pdv' => "ADD COLUMN `aplicar_automatico_pdv` TINYINT(1) NOT NULL DEFAULT 1 AFTER `usos_globais_atuais`"
        ]);

        // 5. promocao_usos — controle individual por cliente e venda
        $pdo->exec("CREATE TABLE IF NOT EXISTS `promocao_usos` (
            `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `promocao_id` INT(10) UNSIGNED NOT NULL,
            `instituicao_id` INT(10) UNSIGNED NOT NULL,
            `cliente_id` INT(10) UNSIGNED NULL DEFAULT NULL,
            `venda_id` INT(10) UNSIGNED NOT NULL,
            `quantidade_itens` INT(11) NOT NULL DEFAULT 1,
            `desconto_aplicado` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `valor_final_itens` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `data_uso` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_promo_cli` (`promocao_id`, `cliente_id`),
            KEY `idx_promo_venda` (`venda_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $logs[] = "[ok] tabela promocao_usos";
        if ($verbose) echo "[ok] tabela promocao_usos\n";

        // 6. vendas — detalhamento de descontos e benefícios concedidos no PDV
        $addCols('vendas', [
            'desconto_fidelidade' => "ADD COLUMN `desconto_fidelidade` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `desconto`",
            'desconto_promocao'   => "ADD COLUMN `desconto_promocao` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `desconto_fidelidade`",
            'cashback_gerado'     => "ADD COLUMN `cashback_gerado` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `desconto_promocao`",
            'cashback_utilizado'  => "ADD COLUMN `cashback_utilizado` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `cashback_gerado`",
            'pontos_gerados'      => "ADD COLUMN `pontos_gerados` INT NOT NULL DEFAULT 0 AFTER `cashback_utilizado`"
        ]);

        // 7. fidelidade_transacoes — vínculos com regra/promoção/venda + tipos estendidos
        $addCols('fidelidade_transacoes', [
            'regra_id'    => "ADD COLUMN `regra_id` INT(10) UNSIGNED NULL DEFAULT NULL AFTER `cliente_id`",
            'promocao_id' => "ADD COLUMN `promocao_id` INT(10) UNSIGNED NULL DEFAULT NULL AFTER `regra_id`",
            'venda_id'    => "ADD COLUMN `venda_id` INT(10) UNSIGNED NULL DEFAULT NULL AFTER `promocao_id`"
        ]);
        $pdo->exec("ALTER TABLE `fidelidade_transacoes`
                    MODIFY COLUMN `tipo` ENUM('acumulo_selo','acumulo_pontos','resgate_recompensa','resgate_cashback','bonus_acelerador','expirado','desconto_direto','promocao_relampago','reinicio_ciclo') NOT NULL");
        $logs[] = "[ok] enum fidelidade_transacoes.tipo";
        if ($verbose) echo "[ok] enum fidelidade_transacoes.tipo\n";

        // 8. Backfill seguro para item_alvo_tipo / recompensa_item_tipo em regras antigas
        $stBack = $pdo->query("SELECT id, instituicao_id, categoria_alvo, item_alvo_id, item_alvo_tipo, recompensa_item_id, recompensa_item_tipo FROM fidelidade_regras");
        $rows = $stBack->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if (!empty($r['item_alvo_id']) && empty($r['item_alvo_tipo'])) {
                $tipo = crmResolverTipoItemMigracao($pdo, (int)$r['item_alvo_id'], (int)$r['instituicao_id'], $r['categoria_alvo'] ?? null);
                $pdo->prepare("UPDATE fidelidade_regras SET item_alvo_tipo = ? WHERE id = ?")->execute([$tipo, $r['id']]);
            }

            if (!empty($r['recompensa_item_id']) && empty($r['recompensa_item_tipo'])) {
                $tipoRec = crmResolverTipoItemMigracao($pdo, (int)$r['recompensa_item_id'], (int)$r['instituicao_id'], null);
                $pdo->prepare("UPDATE fidelidade_regras SET recompensa_item_tipo = ? WHERE id = ?")->execute([$tipoRec, $r['id']]);
            }
        }
        $logs[] = "[ok] backfill de item_alvo_tipo/recompensa_item_tipo";
        if ($verbose) echo "[ok] backfill de item_alvo_tipo/recompensa_item_tipo\n";

        return $logs;
    }
}

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    aplicarMigracaoPoliticaCrm($pdo, true);
    echo "✅ Migração CRM/Fidelidade concluída em: " . DB_NAME . "\n";
}

