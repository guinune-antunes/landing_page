-- ============================================================
-- PetCRM — Schema FINAL unificado
-- Banco: petcrm | Charset: utf8mb4 | Engine: InnoDB
-- ============================================================
-- Colunas canônicas (sem duplicatas):
--   produtos: nome_produto, quantidade_estoque, data_validade, preco_custo
--   vendas: valor_total, forma_pagamento
--   venda_itens: nome_produto, preco_unitario
--   usuarios: senha_hash
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- ============================================================
-- 1. INSTITUIÇÕES (Multi-tenant)
-- ============================================================

CREATE TABLE `instituicoes` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome_fantasia` varchar(200) NOT NULL,
  `cnpj` varchar(20) DEFAULT NULL,
  `plano` varchar(30) DEFAULT NULL,
  `modulos_contratados` json DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `data_criacao` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 2. USUÁRIOS
-- ============================================================

CREATE TABLE `usuarios` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `instituicao_id` int(10) UNSIGNED DEFAULT NULL,
  `nome` varchar(200) NOT NULL,
  `email` varchar(100) NOT NULL,
  `senha_hash` varchar(255) NOT NULL,
  `cargo` enum('Atendente','Tosador','Veterinário','Gerente','Admin') NOT NULL,
  `permissoes` json DEFAULT NULL,
  `precisa_mudar_senha` tinyint(1) NOT NULL DEFAULT 0,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `data_criacao` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uidx_email` (`email`),
  KEY `fk_usuarios_instituicoes` (`instituicao_id`),
  CONSTRAINT `fk_usuarios_instituicoes` FOREIGN KEY (`instituicao_id`) REFERENCES `instituicoes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 3. CLIENTES
-- ============================================================

CREATE TABLE `clientes` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `instituicao_id` int(10) UNSIGNED NOT NULL,
  `nome_completo` varchar(200) NOT NULL,
  `cpf` varchar(20) DEFAULT NULL,
  `telefone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `cep` varchar(10) DEFAULT NULL,
  `logradouro` varchar(255) DEFAULT NULL,
  `numero` varchar(20) DEFAULT NULL,
  `bairro` varchar(100) DEFAULT NULL,
  `cidade` varchar(100) DEFAULT NULL,
  `estado` varchar(2) DEFAULT NULL,
  `data_cadastro` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_clientes_instituicoes` (`instituicao_id`),
  CONSTRAINT `fk_clientes_instituicoes` FOREIGN KEY (`instituicao_id`) REFERENCES `instituicoes` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 4. PETS
-- ============================================================

CREATE TABLE `pets` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `instituicao_id` int(10) UNSIGNED NOT NULL,
  `cliente_id` int(10) UNSIGNED NOT NULL,
  `nome` varchar(100) NOT NULL,
  `especie` varchar(50) DEFAULT NULL,
  `raca` varchar(100) DEFAULT NULL,
  `data_nascimento` date DEFAULT NULL,
  `peso_kg` decimal(5,2) DEFAULT NULL,
  `porte` enum('Mini','Pequeno','Médio','Grande','Gigante') DEFAULT NULL,
  `fator_manutencao` decimal(3,2) DEFAULT NULL,
  `observacoes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_pets_clientes` (`cliente_id`),
  KEY `fk_pets_instituicoes` (`instituicao_id`),
  CONSTRAINT `fk_pets_clientes` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pets_instituicoes` FOREIGN KEY (`instituicao_id`) REFERENCES `instituicoes` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 5. SERVIÇOS
-- ============================================================

CREATE TABLE `servicos` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `instituicao_id` int(10) UNSIGNED DEFAULT NULL,
  `nome_servico` varchar(150) NOT NULL,
  `descricao` text DEFAULT NULL,
  `preco_padrao` decimal(10,2) NOT NULL,
  `duracao_minutos_padrao` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_servicos_instituicoes` (`instituicao_id`),
  CONSTRAINT `fk_servicos_instituicoes` FOREIGN KEY (`instituicao_id`) REFERENCES `instituicoes` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 6. PRODUTOS (nomes unificados)
-- ============================================================

CREATE TABLE `produtos` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `instituicao_id` int(10) UNSIGNED NOT NULL,
  `nome_produto` varchar(200) NOT NULL,
  `marca` varchar(100) DEFAULT NULL,
  `descricao` text DEFAULT NULL,
  `codigo_barras` varchar(100) DEFAULT NULL,
  `preco_custo` decimal(10,2) DEFAULT 0.00,
  `preco_venda` decimal(10,2) DEFAULT 0.00,
  `quantidade_estoque` int(11) NOT NULL DEFAULT 0,
  `unidade` varchar(50) DEFAULT NULL,
  `tamanho_embalagem_kg` decimal(6,3) DEFAULT NULL,
  `energia_metabolizavel_kg` decimal(8,2) DEFAULT NULL,
  `fornecedor` varchar(200) DEFAULT NULL,
  `data_chegada` date DEFAULT NULL,
  `data_fabricacao` date DEFAULT NULL,
  `data_validade` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uidx_codigo_barras` (`codigo_barras`),
  KEY `fk_produtos_instituicoes` (`instituicao_id`),
  CONSTRAINT `fk_produtos_instituicoes` FOREIGN KEY (`instituicao_id`) REFERENCES `instituicoes` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 7. AGENDAMENTOS
-- ============================================================

CREATE TABLE `agendamentos` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `instituicao_id` int(10) UNSIGNED NOT NULL,
  `cliente_id` int(10) UNSIGNED DEFAULT NULL,
  `pet_id` int(10) UNSIGNED NOT NULL,
  `servico_id` int(10) UNSIGNED DEFAULT NULL,
  `servico` varchar(150) DEFAULT NULL,
  `profissional_id` int(10) UNSIGNED DEFAULT NULL,
  `profissional` varchar(150) DEFAULT NULL,
  `data_hora_inicio` datetime NOT NULL,
  `data_hora_fim` datetime NOT NULL,
  `status` enum('Agendado','Confirmado','Aguardando','Em Andamento','Concluído','Cancelado','Não Compareceu','Marcado','Remarcado') NOT NULL DEFAULT 'Agendado',
  `observacoes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_data_hora_inicio` (`data_hora_inicio`),
  KEY `fk_agendamentos_pets` (`pet_id`),
  KEY `fk_agendamentos_servicos` (`servico_id`),
  KEY `fk_agendamentos_profissionais` (`profissional_id`),
  KEY `fk_agendamentos_instituicoes` (`instituicao_id`),
  KEY `fk_agendamentos_clientes` (`cliente_id`),
  CONSTRAINT `fk_agendamentos_pets` FOREIGN KEY (`pet_id`) REFERENCES `pets` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_agendamentos_servicos` FOREIGN KEY (`servico_id`) REFERENCES `servicos` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_agendamentos_profissionais` FOREIGN KEY (`profissional_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_agendamentos_instituicoes` FOREIGN KEY (`instituicao_id`) REFERENCES `instituicoes` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_agendamentos_clientes` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 8. VENDAS (nomes unificados)
-- ============================================================

CREATE TABLE `vendas` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `instituicao_id` int(10) UNSIGNED NOT NULL,
  `cliente_id` int(10) UNSIGNED DEFAULT NULL,
  `vendedor_id` int(10) UNSIGNED DEFAULT NULL,
  `data_venda` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `valor_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `desconto` decimal(10,2) DEFAULT 0.00,
  `forma_pagamento` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_vendas_clientes` (`cliente_id`),
  KEY `fk_vendas_vendedores` (`vendedor_id`),
  KEY `fk_vendas_instituicoes` (`instituicao_id`),
  CONSTRAINT `fk_vendas_clientes` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_vendas_vendedores` FOREIGN KEY (`vendedor_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_vendas_instituicoes` FOREIGN KEY (`instituicao_id`) REFERENCES `instituicoes` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 9. ITENS DE VENDA (nomes unificados)
-- ============================================================

CREATE TABLE `venda_itens` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `venda_id` int(10) UNSIGNED NOT NULL,
  `produto_id` int(10) UNSIGNED DEFAULT NULL,
  `servico_id` int(10) UNSIGNED DEFAULT NULL,
  `nome_produto` varchar(200) DEFAULT NULL,
  `quantidade` int(11) NOT NULL DEFAULT 1,
  `preco_unitario` decimal(10,2) DEFAULT NULL,
  `subtotal` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_venda_itens_vendas` (`venda_id`),
  KEY `fk_venda_itens_produtos` (`produto_id`),
  KEY `fk_venda_itens_servicos` (`servico_id`),
  CONSTRAINT `fk_venda_itens_vendas` FOREIGN KEY (`venda_id`) REFERENCES `vendas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_venda_itens_produtos` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_venda_itens_servicos` FOREIGN KEY (`servico_id`) REFERENCES `servicos` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 10. OPORTUNIDADES DE VENDA (CRM)
-- ============================================================

CREATE TABLE `oportunidades_venda` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `instituicao_id` int(10) UNSIGNED NOT NULL,
  `cliente_id` int(10) UNSIGNED NOT NULL,
  `pet_id` int(10) UNSIGNED DEFAULT NULL,
  `produto_id` int(10) UNSIGNED DEFAULT NULL,
  `produto_nome` varchar(200) DEFAULT NULL,
  `data_compra` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('Pendente','Venda Fechada','Cancelado') NOT NULL DEFAULT 'Pendente',
  PRIMARY KEY (`id`),
  KEY `fk_oport_instituicoes` (`instituicao_id`),
  KEY `fk_oport_clientes` (`cliente_id`),
  KEY `fk_oport_pets` (`pet_id`),
  KEY `fk_oport_produtos` (`produto_id`),
  CONSTRAINT `fk_oport_instituicoes` FOREIGN KEY (`instituicao_id`) REFERENCES `instituicoes` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_oport_clientes` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_oport_pets` FOREIGN KEY (`pet_id`) REFERENCES `pets` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_oport_produtos` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 11. TAREFAS DE ESTOQUE
-- ============================================================

CREATE TABLE `tarefas_estoque` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `instituicao_id` int(10) UNSIGNED NOT NULL,
  `tipo` varchar(50) NOT NULL DEFAULT 'Outros',
  `titulo` varchar(255) NOT NULL,
  `descricao` text DEFAULT NULL,
  `status` enum('Pendente','Resolvido') NOT NULL DEFAULT 'Pendente',
  `data_criacao` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolvido_por` int(10) UNSIGNED DEFAULT NULL,
  `resolvido_em` datetime DEFAULT NULL,
  `observacao_resolucao` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_tarefas_instituicoes` (`instituicao_id`),
  KEY `fk_tarefas_resolvedor` (`resolvido_por`),
  CONSTRAINT `fk_tarefas_instituicoes` FOREIGN KEY (`instituicao_id`) REFERENCES `instituicoes` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_tarefas_resolvedor` FOREIGN KEY (`resolvido_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 12. FIDELIZAÇÃO E PROMOÇÕES (CRM)
-- ============================================================

CREATE TABLE IF NOT EXISTS `fidelidade_config` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `instituicao_id` int(10) UNSIGNED NOT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `tipo_programa` enum('selos','pontos','hibrido') NOT NULL DEFAULT 'selos',
  `compras_para_recompensa` int(11) NOT NULL DEFAULT 10,
  `recompensa_tipo` enum('desconto_percentual','desconto_fixo','produto_gratis','cashback') NOT NULL DEFAULT 'desconto_percentual',
  `recompensa_valor` decimal(10,2) NOT NULL DEFAULT 15.00,
  `limite_desconto_recompensa` decimal(10,2) NOT NULL DEFAULT 50.00,
  `reais_por_ponto` decimal(10,2) NOT NULL DEFAULT 1.00,
  `cashback_percentual` decimal(5,2) NOT NULL DEFAULT 5.00,
  `validade_meses` int(11) NOT NULL DEFAULT 6,
  `notificar_expiracao` tinyint(1) NOT NULL DEFAULT 1,
  `dias_aviso_expiracao` int(11) NOT NULL DEFAULT 15,
  `notificar_whatsapp` tinyint(1) NOT NULL DEFAULT 1,
  `notificar_email` tinyint(1) NOT NULL DEFAULT 0,
  `cor_cartao_hex` varchar(20) NOT NULL DEFAULT '#1abc9c',
  `cor_cartao_secundaria` varchar(20) NOT NULL DEFAULT '#2c3e50',
  `estilo_cartao` varchar(50) NOT NULL DEFAULT 'modern_gradient',
  `logo_cartao` varchar(255) DEFAULT NULL,
  `texto_cartao` varchar(200) NOT NULL DEFAULT 'Cartão Fidelidade Pet VIP',
  `regras_aceleradores` json DEFAULT NULL,
  `tiers_config` json DEFAULT NULL,
  `data_atualizacao` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uidx_inst` (`instituicao_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cliente_fidelidade` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `instituicao_id` int(10) UNSIGNED NOT NULL,
  `cliente_id` int(10) UNSIGNED NOT NULL,
  `selos_atuais` int(11) NOT NULL DEFAULT 0,
  `ciclos_completados` int(11) NOT NULL DEFAULT 0,
  `pontos_acumulados` int(11) NOT NULL DEFAULT 0,
  `saldo_cashback` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tier_atual` varchar(50) NOT NULL DEFAULT 'Bronze',
  `total_gasto_historico` decimal(10,2) NOT NULL DEFAULT 0.00,
  `ultimo_acesso` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uidx_inst_cli` (`instituicao_id`,`cliente_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fidelidade_transacoes` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `instituicao_id` int(10) UNSIGNED NOT NULL,
  `cliente_id` int(10) UNSIGNED NOT NULL,
  `tipo` enum('acumulo_selo','acumulo_pontos','resgate_recompensa','resgate_cashback','bonus_acelerador','expirado') NOT NULL,
  `quantidade` int(11) NOT NULL DEFAULT 1,
  `valor_recompensa` decimal(10,2) NOT NULL DEFAULT 0.00,
  `descricao` varchar(255) NOT NULL,
  `usuario_operador` varchar(150) DEFAULT 'Sistema',
  `data_transacao` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_inst_data` (`instituicao_id`,`data_transacao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `promocoes` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `instituicao_id` int(10) UNSIGNED NOT NULL,
  `titulo` varchar(200) NOT NULL,
  `descricao` text DEFAULT NULL,
  `produto_id` int(10) UNSIGNED DEFAULT NULL,
  `desconto_percentual` decimal(5,2) NOT NULL DEFAULT 0.00,
  `preco_original` decimal(10,2) DEFAULT NULL,
  `preco_promocional` decimal(10,2) DEFAULT NULL,
  `imagem_url` varchar(255) DEFAULT NULL,
  `data_inicio` datetime NOT NULL,
  `data_fim` datetime NOT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `data_criacao` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_inst_ativo` (`instituicao_id`,`ativo`,`data_fim`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fidelidade_regras` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `instituicao_id` int(10) UNSIGNED NOT NULL,
  `nome_regra` varchar(200) NOT NULL,
  `descricao` text DEFAULT NULL,
  `tipo_mecanica` enum('cartao_selos','pontos','cashback','desconto_direto','compre_ganhe') NOT NULL DEFAULT 'cartao_selos',
  `categoria_alvo` enum('todos','servicos','produtos','categoria_especifica','item_especifico') NOT NULL DEFAULT 'todos',
  `item_alvo_id` int(10) UNSIGNED DEFAULT NULL,
  `condicao_tipo` enum('quantidade_compras','valor_minimo','dia_semana','aniversario','sem_condicao') NOT NULL DEFAULT 'quantidade_compras',
  `condicao_valor` decimal(10,2) NOT NULL DEFAULT 10.00,
  `dias_semana_ativos` json DEFAULT NULL,
  `recompensa_tipo` enum('desconto_percentual','desconto_fixo','item_gratis','cashback','multiplicador_pontos') NOT NULL DEFAULT 'desconto_percentual',
  `recompensa_valor` decimal(10,2) NOT NULL DEFAULT 15.00,
  `recompensa_item_id` int(10) UNSIGNED DEFAULT NULL,
  `limite_desconto_recompensa` decimal(10,2) DEFAULT NULL,
  `data_inicio` datetime DEFAULT NULL,
  `data_fim` datetime DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `cor_hex` varchar(20) NOT NULL DEFAULT '#1abc9c',
  `cor_secundaria` varchar(20) NOT NULL DEFAULT '#2c3e50',
  `icone` varchar(50) NOT NULL DEFAULT 'fas fa-paw',
  `texto_cartao` varchar(200) NOT NULL DEFAULT 'Cartão Fidelidade Pet VIP',
  `data_criacao` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_inst_ativo` (`instituicao_id`, `ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cliente_progresso_regras` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `instituicao_id` int(10) UNSIGNED NOT NULL,
  `cliente_id` int(10) UNSIGNED NOT NULL,
  `regra_id` int(10) UNSIGNED NOT NULL,
  `progresso_atual` int(11) NOT NULL DEFAULT 0,
  `ciclos_completados` int(11) NOT NULL DEFAULT 0,
  `recompensa_disponivel` tinyint(1) NOT NULL DEFAULT 0,
  `ultimo_uso` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uidx_cli_regra` (`cliente_id`, `regra_id`),
  KEY `idx_inst_cli` (`instituicao_id`, `cliente_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- ============================================================
-- DADOS INICIAIS (Admin do sistema)
-- ============================================================
-- Senha: admin123 (bcrypt hash)
-- ============================================================

INSERT INTO `usuarios` (`id`, `nome`, `email`, `senha_hash`, `cargo`, `ativo`) VALUES
(1, 'Administrador', 'admin@petcrm.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin', 1);

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
