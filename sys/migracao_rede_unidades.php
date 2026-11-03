<?php
// ══════════════════════════════════════════════════════════════════
// migracao_rede_unidades.php — Multi-unidade (Rede → Unidades)
// ══════════════════════════════════════════════════════════════════
// Modelo:
//   redes (empresa/grupo)  1 ─── N  instituicoes (UNIDADES / lojas)
//   usuarios ─── N:N ─── instituicoes  (usuario_unidades)
//   usuarios.acesso_rede = 1  → "Gerente Geral": enxerga todas as unidades da rede
//
// Compatibilidade: a tabela `instituicoes` continua sendo a "unidade".
// Todas as telas que filtram por instituicao_id seguem funcionando, pois
// $_SESSION['instituicao_id'] passa a representar a UNIDADE ATIVA.
//
// Execução (idempotente — pode rodar mais de uma vez):
//   php migracao_rede_unidades.php           (linha de comando)
//   ou acessar pelo navegador logado como Admin.
// ══════════════════════════════════════════════════════════════════

if (PHP_SAPI !== 'cli') {
    session_start();
    if (($_SESSION['usuario_cargo'] ?? '') !== 'Admin') {
        http_response_code(403);
        exit('Acesso restrito ao Admin.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require __DIR__ . '/conexao.php';

function colunaExiste(PDO $pdo, string $tabela, string $coluna): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$tabela, $coluna]);
    return (int)$st->fetchColumn() > 0;
}

function logm(string $m): void { echo $m . "\n"; }

// 1. Tabela de redes ────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `redes` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome` varchar(200) NOT NULL,
  `compartilhar_clientes` tinyint(1) NOT NULL DEFAULT 0,
  `compartilhar_fidelidade` tinyint(1) NOT NULL DEFAULT 0,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `data_criacao` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
logm('[ok] tabela redes');

// 2. Colunas em instituicoes (unidade) ──────────────────────────
$colsInst = [
    'rede_id'      => "ADD COLUMN `rede_id` int(10) UNSIGNED DEFAULT NULL",
    'nome_unidade' => "ADD COLUMN `nome_unidade` varchar(120) DEFAULT NULL",
    'cidade'       => "ADD COLUMN `cidade` varchar(100) DEFAULT NULL",
    'uf'           => "ADD COLUMN `uf` varchar(2) DEFAULT NULL",
    'matriz'       => "ADD COLUMN `matriz` tinyint(1) NOT NULL DEFAULT 0",
];
foreach ($colsInst as $col => $ddl) {
    if (!colunaExiste($pdo, 'instituicoes', $col)) {
        $pdo->exec("ALTER TABLE `instituicoes` $ddl");
        logm("[ok] instituicoes.$col criada");
    } else {
        logm("[--] instituicoes.$col já existe");
    }
}

$fk = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'instituicoes'
                     AND CONSTRAINT_NAME = 'fk_instituicoes_redes'")->fetchColumn();
if (!(int)$fk) {
    $pdo->exec("ALTER TABLE `instituicoes`
                ADD KEY `fk_instituicoes_redes` (`rede_id`),
                ADD CONSTRAINT `fk_instituicoes_redes` FOREIGN KEY (`rede_id`)
                    REFERENCES `redes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE");
    logm('[ok] FK instituicoes.rede_id → redes.id');
}

// 3. Flag de Gerente Geral em usuarios ──────────────────────────
if (!colunaExiste($pdo, 'usuarios', 'acesso_rede')) {
    $pdo->exec("ALTER TABLE `usuarios` ADD COLUMN `acesso_rede` tinyint(1) NOT NULL DEFAULT 0");
    logm('[ok] usuarios.acesso_rede criada');
} else {
    logm('[--] usuarios.acesso_rede já existe');
}

// 4. Vínculo usuário ↔ unidades adicionais ──────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `usuario_unidades` (
  `usuario_id` int(10) UNSIGNED NOT NULL,
  `instituicao_id` int(10) UNSIGNED NOT NULL,
  `data_vinculo` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`usuario_id`, `instituicao_id`),
  KEY `idx_uu_instituicao` (`instituicao_id`),
  CONSTRAINT `fk_uu_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_uu_instituicao` FOREIGN KEY (`instituicao_id`) REFERENCES `instituicoes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
logm('[ok] tabela usuario_unidades');

// 5. Código de barras único POR UNIDADE (antes era único no sistema inteiro) ─────
//    Necessário para o mesmo produto existir em unidades diferentes da rede.
function indiceExiste(PDO $pdo, string $tabela, string $indice): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $st->execute([$tabela, $indice]);
    return (int)$st->fetchColumn() > 0;
}
if (indiceExiste($pdo, 'produtos', 'uidx_codigo_barras')) {
    $pdo->exec("ALTER TABLE `produtos` DROP INDEX `uidx_codigo_barras`");
    logm('[ok] índice global uidx_codigo_barras removido');
}
if (!indiceExiste($pdo, 'produtos', 'uidx_inst_codigo_barras')) {
    $pdo->exec("ALTER TABLE `produtos` ADD UNIQUE KEY `uidx_inst_codigo_barras` (`instituicao_id`, `codigo_barras`)");
    logm('[ok] índice único (instituicao_id, codigo_barras) criado');
}

// 6. Histórico de transferências de estoque entre unidades ─────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `transferencias_estoque` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `rede_id` int(10) UNSIGNED NOT NULL,
  `origem_id` int(10) UNSIGNED NOT NULL,
  `destino_id` int(10) UNSIGNED NOT NULL,
  `produto_origem_id` int(10) UNSIGNED DEFAULT NULL,
  `produto_destino_id` int(10) UNSIGNED DEFAULT NULL,
  `nome_produto` varchar(200) NOT NULL,
  `quantidade` int(11) NOT NULL,
  `observacao` varchar(255) DEFAULT NULL,
  `usuario_id` int(10) UNSIGNED DEFAULT NULL,
  `data_transferencia` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_te_origem` (`origem_id`, `data_transferencia`),
  KEY `idx_te_destino` (`destino_id`, `data_transferencia`),
  KEY `idx_te_rede` (`rede_id`),
  CONSTRAINT `fk_te_rede` FOREIGN KEY (`rede_id`) REFERENCES `redes` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_te_origem` FOREIGN KEY (`origem_id`) REFERENCES `instituicoes` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_te_destino` FOREIGN KEY (`destino_id`) REFERENCES `instituicoes` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
logm('[ok] tabela transferencias_estoque');

// 7. Gestor responsável por cada unidade ───────────────────────────────────────
//    Definido pelo Gerente Geral da rede. Aprova/nega requisições de estoque.
if (!colunaExiste($pdo, 'instituicoes', 'gestor_id')) {
    $pdo->exec("ALTER TABLE `instituicoes`
                ADD COLUMN `gestor_id` int(10) UNSIGNED DEFAULT NULL,
                ADD KEY `fk_instituicoes_gestor` (`gestor_id`),
                ADD CONSTRAINT `fk_instituicoes_gestor` FOREIGN KEY (`gestor_id`)
                    REFERENCES `usuarios` (`id`) ON DELETE SET NULL ON UPDATE CASCADE");
    logm('[ok] instituicoes.gestor_id criada');
} else {
    logm('[--] instituicoes.gestor_id já existe');
}

// 8. Fluxo de aprovação das transferências (requisições entre unidades) ────────
//    origem_id  = unidade FORNECEDORA (envia)   → seu gestor autoriza ou nega
//    destino_id = unidade SOLICITANTE (recebe)  → seu gestor abre e confirma o recebimento
//    pendente → em_transito → recebida | negada | cancelada | recusada
//    Registros antigos (transferências diretas) ficam como 'recebida'.
$colsTe = [
    'status'           => "ADD COLUMN `status` enum('pendente','em_transito','recebida','negada','cancelada','recusada') NOT NULL DEFAULT 'recebida'",
    'justificativa'    => "ADD COLUMN `justificativa` varchar(255) DEFAULT NULL",
    'aprovador_id'     => "ADD COLUMN `aprovador_id` int(10) UNSIGNED DEFAULT NULL",
    'data_resposta'    => "ADD COLUMN `data_resposta` datetime DEFAULT NULL",
    'recebedor_id'     => "ADD COLUMN `recebedor_id` int(10) UNSIGNED DEFAULT NULL",
    'data_recebimento' => "ADD COLUMN `data_recebimento` datetime DEFAULT NULL",
];
foreach ($colsTe as $col => $ddl) {
    if (!colunaExiste($pdo, 'transferencias_estoque', $col)) {
        $pdo->exec("ALTER TABLE `transferencias_estoque` $ddl");
        logm("[ok] transferencias_estoque.$col criada");
    } else {
        logm("[--] transferencias_estoque.$col já existe");
    }
}
if (!indiceExiste($pdo, 'transferencias_estoque', 'idx_te_status')) {
    $pdo->exec("ALTER TABLE `transferencias_estoque` ADD KEY `idx_te_status` (`status`)");
    logm('[ok] índice transferencias_estoque.status criado');
}

// 9. Políticas de Alçada & Release Strategies em Redes ──────────────────────────
$colsRede = [
    'limite_alcada_loja'           => "ADD COLUMN `limite_alcada_loja` decimal(10,2) NOT NULL DEFAULT 1000.00",
    'exige_aprovacao_medicamentos' => "ADD COLUMN `exige_aprovacao_medicamentos` tinyint(1) NOT NULL DEFAULT 1",
];
foreach ($colsRede as $col => $ddl) {
    if (!colunaExiste($pdo, 'redes', $col)) {
        $pdo->exec("ALTER TABLE `redes` $ddl");
        logm("[ok] redes.$col criada");
    } else {
        logm("[--] redes.$col já existe");
    }
}

// 10. Delegação e Ausência / Substituto Temporário em Instituições ────────────
$colsInstSub = [
    'substituto_id'  => "ADD COLUMN `substituto_id` int(10) UNSIGNED DEFAULT NULL",
    'ausente_inicio' => "ADD COLUMN `ausente_inicio` date DEFAULT NULL",
    'ausente_fim'    => "ADD COLUMN `ausente_fim` date DEFAULT NULL",
];
foreach ($colsInstSub as $col => $ddl) {
    if (!colunaExiste($pdo, 'instituicoes', $col)) {
        $pdo->exec("ALTER TABLE `instituicoes` $ddl");
        logm("[ok] instituicoes.$col criada");
    } else {
        logm("[--] instituicoes.$col já existe");
    }
}
$fkSub = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'instituicoes'
                        AND CONSTRAINT_NAME = 'fk_instituicoes_substituto'")->fetchColumn();
if (!(int)$fkSub) {
    $pdo->exec("ALTER TABLE `instituicoes`
                ADD KEY `fk_instituicoes_substituto` (`substituto_id`),
                ADD CONSTRAINT `fk_instituicoes_substituto` FOREIGN KEY (`substituto_id`)
                    REFERENCES `usuarios` (`id`) ON DELETE SET NULL ON UPDATE CASCADE");
    logm('[ok] FK instituicoes.substituto_id → usuarios.id');
}

// 11. Categorização de Produtos para Release Strategies ────────────────────────
if (!colunaExiste($pdo, 'produtos', 'categoria')) {
    $pdo->exec("ALTER TABLE `produtos` ADD COLUMN `categoria` enum('Geral','Ração','Medicamento','Vacina','Higiene & Estética','Acessórios','Outros') NOT NULL DEFAULT 'Geral'");
    logm('[ok] produtos.categoria criada');
} else {
    logm('[--] produtos.categoria já existe');
}

// Auto-classificação inicial baseada no nome para produtos existentes
$pdo->exec("UPDATE `produtos` SET `categoria` = 'Vacina' WHERE `categoria` = 'Geral' AND (LOWER(`nome_produto`) LIKE '%vacina%' OR LOWER(`nome_produto`) LIKE '%v8%' OR LOWER(`nome_produto`) LIKE '%v10%')");
$pdo->exec("UPDATE `produtos` SET `categoria` = 'Medicamento' WHERE `categoria` = 'Geral' AND (LOWER(`nome_produto`) LIKE '%verm%' OR LOWER(`nome_produto`) LIKE '%antib%' OR LOWER(`nome_produto`) LIKE '%anti-infl%' OR LOWER(`nome_produto`) LIKE '%remedio%' OR LOWER(`nome_produto`) LIKE '%colírio%' OR LOWER(`nome_produto`) LIKE '%pomada%')");
$pdo->exec("UPDATE `produtos` SET `categoria` = 'Ração' WHERE `categoria` = 'Geral' AND (LOWER(`nome_produto`) LIKE '%ração%' OR LOWER(`nome_produto`) LIKE '%racao%' OR LOWER(`nome_produto`) LIKE '%sachê%' OR LOWER(`nome_produto`) LIKE '%petisco%')");

// 12. Atualização dos campos de Alçada em transferencias_estoque ───────────────
$colsTeAlcada = [
    'valor_total'     => "ADD COLUMN `valor_total` decimal(10,2) NOT NULL DEFAULT 0.00",
    'exige_superior'  => "ADD COLUMN `exige_superior` tinyint(1) NOT NULL DEFAULT 0",
    'motivo_superior' => "ADD COLUMN `motivo_superior` varchar(255) DEFAULT NULL",
];
foreach ($colsTeAlcada as $col => $ddl) {
    if (!colunaExiste($pdo, 'transferencias_estoque', $col)) {
        $pdo->exec("ALTER TABLE `transferencias_estoque` $ddl");
        logm("[ok] transferencias_estoque.$col criada");
    } else {
        logm("[--] transferencias_estoque.$col já existe");
    }
}

// Atualiza o enum de status para suportar 'aguardando_aprovacao_superior'
$pdo->exec("ALTER TABLE `transferencias_estoque` MODIFY COLUMN `status` enum('pendente','em_transito','aguardando_aprovacao_superior','recebida','negada','cancelada','recusada') NOT NULL DEFAULT 'recebida'");
logm('[ok] transferencias_estoque.status enum atualizado com aguardando_aprovacao_superior');

// 13. Tabela de Trilha de Auditoria Imutável (Audit Trail) ─────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `transferencias_auditoria` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `transferencia_id` int(10) UNSIGNED NOT NULL,
  `usuario_id` int(10) UNSIGNED DEFAULT NULL,
  `usuario_nome` varchar(200) DEFAULT NULL,
  `acao` varchar(60) NOT NULL,
  `de_status` varchar(40) DEFAULT NULL,
  `para_status` varchar(40) NOT NULL,
  `detalhes` text DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `data_hora` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ta_transf` (`transferencia_id`, `data_hora`),
  KEY `idx_ta_usuario` (`usuario_id`),
  CONSTRAINT `fk_ta_transf` FOREIGN KEY (`transferencia_id`) REFERENCES `transferencias_estoque` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
logm('[ok] tabela transferencias_auditoria criada');



logm("\nMigração concluída. Instituições existentes continuam como unidades independentes (rede_id = NULL).");
