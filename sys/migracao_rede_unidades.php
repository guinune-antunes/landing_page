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


logm("\nMigração concluída. Instituições existentes continuam como unidades independentes (rede_id = NULL).");
