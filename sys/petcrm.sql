-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Tempo de geração: 30/09/2025 às 15:20
-- Versão do servidor: 10.4.32-MariaDB
-- Versão do PHP: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Banco de dados: `petcrm`
--

-- --------------------------------------------------------

--
-- Estrutura para tabela `agendamentos`
--

CREATE TABLE `agendamentos` (
  `id` int(10) UNSIGNED NOT NULL,
  `pet_id` int(10) UNSIGNED NOT NULL,
  `servico_id` int(10) UNSIGNED NOT NULL,
  `profissional_id` int(10) UNSIGNED DEFAULT NULL,
  `data_hora_inicio` datetime NOT NULL,
  `data_hora_fim` datetime NOT NULL,
  `status` enum('Agendado','Confirmado','Aguardando','Em Andamento','Concluído','Cancelado','Não Compareceu') NOT NULL DEFAULT 'Agendado',
  `observacoes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `agendamentos`
--

INSERT INTO `agendamentos` (`id`, `pet_id`, `servico_id`, `profissional_id`, `data_hora_inicio`, `data_hora_fim`, `status`, `observacoes`) VALUES
(1, 1, 1, 1, '2025-10-05 10:00:00', '2025-10-05 11:30:00', 'Agendado', NULL),
(2, 3, 2, 2, '2025-10-07 14:00:00', '2025-10-07 14:30:00', 'Confirmado', NULL);

-- --------------------------------------------------------

--
-- Estrutura para tabela `clientes`
--

CREATE TABLE `clientes` (
  `id` int(10) UNSIGNED NOT NULL,
  `nome_completo` varchar(200) NOT NULL,
  `telefone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `logradouro` varchar(255) DEFAULT NULL,
  `numero` varchar(20) DEFAULT NULL,
  `bairro` varchar(100) DEFAULT NULL,
  `cidade` varchar(100) DEFAULT NULL,
  `estado` varchar(2) DEFAULT NULL,
  `cep` varchar(10) DEFAULT NULL,
  `data_cadastro` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `clientes`
--

INSERT INTO `clientes` (`id`, `nome_completo`, `telefone`, `email`, `logradouro`, `numero`, `bairro`, `cidade`, `estado`, `cep`, `data_cadastro`) VALUES
(1, 'Ana Teste da Silva', '(11) 98877-6655', 'ana.teste@email.com', 'Rua das Flores', '123', 'Centro', 'São Paulo', 'SP', '01000-000', '2025-09-30 13:19:55'),
(2, 'Bruno Exemplo de Souza', '(21) 91122-3344', 'bruno.ex@email.com', 'Avenida Principal', '456', 'Copacabana', 'Rio de Janeiro', 'RJ', '22000-000', '2025-09-30 13:19:55');

-- --------------------------------------------------------

--
-- Estrutura para tabela `pets`
--

CREATE TABLE `pets` (
  `id` int(10) UNSIGNED NOT NULL,
  `cliente_id` int(10) UNSIGNED NOT NULL,
  `nome` varchar(100) NOT NULL,
  `especie` varchar(50) DEFAULT NULL,
  `raca` varchar(100) DEFAULT NULL,
  `data_nascimento` date DEFAULT NULL,
  `peso_kg` decimal(5,2) DEFAULT NULL,
  `porte` enum('Mini','Pequeno','Médio','Grande','Gigante') DEFAULT NULL,
  `observacoes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `pets`
--

INSERT INTO `pets` (`id`, `cliente_id`, `nome`, `especie`, `raca`, `data_nascimento`, `peso_kg`, `porte`, `observacoes`) VALUES
(1, 1, 'Bolinha', 'Cão', 'Poodle', '2022-05-10', NULL, NULL, NULL),
(2, 2, 'Frajola', 'Gato', 'Siamês', '2021-01-15', NULL, NULL, NULL),
(3, 2, 'Rex', 'Cão', 'Labrador', '2023-03-20', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Estrutura para tabela `produtos`
--

CREATE TABLE `produtos` (
  `id` int(10) UNSIGNED NOT NULL,
  `nome_produto` varchar(200) NOT NULL,
  `descricao` text DEFAULT NULL,
  `codigo_barras` varchar(100) DEFAULT NULL,
  `preco_venda` decimal(10,2) NOT NULL,
  `quantidade_estoque` int(11) NOT NULL DEFAULT 0,
  `tamanho_embalagem_kg` decimal(6,3) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `produtos`
--

INSERT INTO `produtos` (`id`, `nome_produto`, `descricao`, `codigo_barras`, `preco_venda`, `quantidade_estoque`, `tamanho_embalagem_kg`) VALUES
(1, 'Ração Premium para Cães Adultos 15kg', NULL, NULL, 250.00, 30, NULL),
(2, 'Shampoo Antipulgas 500ml', NULL, NULL, 45.50, 50, NULL),
(3, 'Brinquedo de Corda', NULL, NULL, 25.00, 100, NULL);

-- --------------------------------------------------------

--
-- Estrutura para tabela `servicos`
--

CREATE TABLE `servicos` (
  `id` int(10) UNSIGNED NOT NULL,
  `nome_servico` varchar(150) NOT NULL,
  `descricao` text DEFAULT NULL,
  `preco_padrao` decimal(10,2) NOT NULL,
  `duracao_minutos_padrao` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `servicos`
--

INSERT INTO `servicos` (`id`, `nome_servico`, `descricao`, `preco_padrao`, `duracao_minutos_padrao`) VALUES
(1, 'Banho e Tosa', 'Banho completo com tosa higiênica e corte de unhas.', 95.00, 90),
(2, 'Consulta Veterinária', 'Consulta de rotina ou emergencial.', 150.00, 30),
(3, 'Vacina V10 (Cães)', 'Vacina polivalente para cães.', 120.00, 15);

-- --------------------------------------------------------

--
-- Estrutura para tabela `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int(10) UNSIGNED NOT NULL,
  `nome` varchar(200) NOT NULL,
  `email` varchar(100) NOT NULL,
  `senha_hash` varchar(255) NOT NULL,
  `cargo` enum('Atendente','Tosador','Veterinário','Gerente','Admin') NOT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `usuarios`
--

INSERT INTO `usuarios` (`id`, `nome`, `email`, `senha_hash`, `cargo`, `ativo`) VALUES
(1, 'Juliana Silva', 'juliana@petcrm.com', '$2y$10$Y.aV.1.a8.1...ExemploDeHash...12345', 'Atendente', 1),
(2, 'Dr. Roberto', 'roberto.vet@petcrm.com', '$2y$10$Y.aV.1.a8.1...ExemploDeHash...ABCDE', 'Veterinário', 1);

-- --------------------------------------------------------

--
-- Estrutura para tabela `vendas`
--

CREATE TABLE `vendas` (
  `id` int(10) UNSIGNED NOT NULL,
  `cliente_id` int(10) UNSIGNED NOT NULL,
  `vendedor_id` int(10) UNSIGNED DEFAULT NULL,
  `data_venda` datetime NOT NULL DEFAULT current_timestamp(),
  `valor_total` decimal(10,2) NOT NULL,
  `metodo_pagamento` enum('Dinheiro','Cartão de Crédito','Cartão de Débito','PIX','Fiado') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `vendas`
--

INSERT INTO `vendas` (`id`, `cliente_id`, `vendedor_id`, `data_venda`, `valor_total`, `metodo_pagamento`) VALUES
(1, 2, 1, '2025-09-30 13:19:55', 120.50, 'Cartão de Débito');

-- --------------------------------------------------------

--
-- Estrutura para tabela `venda_itens`
--

CREATE TABLE `venda_itens` (
  `id` int(10) UNSIGNED NOT NULL,
  `venda_id` int(10) UNSIGNED NOT NULL,
  `produto_id` int(10) UNSIGNED DEFAULT NULL,
  `servico_id` int(10) UNSIGNED DEFAULT NULL,
  `quantidade` int(11) NOT NULL DEFAULT 1,
  `preco_unitario_na_venda` decimal(10,2) NOT NULL
) ;

--
-- Despejando dados para a tabela `venda_itens`
--

INSERT INTO `venda_itens` (`id`, `venda_id`, `produto_id`, `servico_id`, `quantidade`, `preco_unitario_na_venda`) VALUES
(1, 1, 2, NULL, 1, 45.50),
(2, 1, 3, NULL, 3, 25.00);

--
-- Índices para tabelas despejadas
--

--
-- Índices de tabela `agendamentos`
--
ALTER TABLE `agendamentos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_data_hora_inicio` (`data_hora_inicio`),
  ADD KEY `fk_agendamentos_pets` (`pet_id`),
  ADD KEY `fk_agendamentos_servicos` (`servico_id`),
  ADD KEY `fk_agendamentos_profissionais` (`profissional_id`);

--
-- Índices de tabela `clientes`
--
ALTER TABLE `clientes`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `pets`
--
ALTER TABLE `pets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_pets_clientes` (`cliente_id`);

--
-- Índices de tabela `produtos`
--
ALTER TABLE `produtos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uidx_codigo_barras` (`codigo_barras`);

--
-- Índices de tabela `servicos`
--
ALTER TABLE `servicos`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uidx_email` (`email`);

--
-- Índices de tabela `vendas`
--
ALTER TABLE `vendas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_vendas_clientes` (`cliente_id`),
  ADD KEY `fk_vendas_vendedores` (`vendedor_id`);

--
-- Índices de tabela `venda_itens`
--
ALTER TABLE `venda_itens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_venda_itens_vendas` (`venda_id`),
  ADD KEY `fk_venda_itens_produtos` (`produto_id`),
  ADD KEY `fk_venda_itens_servicos` (`servico_id`);

--
-- AUTO_INCREMENT para tabelas despejadas
--

--
-- AUTO_INCREMENT de tabela `agendamentos`
--
ALTER TABLE `agendamentos`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de tabela `clientes`
--
ALTER TABLE `clientes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de tabela `pets`
--
ALTER TABLE `pets`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de tabela `produtos`
--
ALTER TABLE `produtos`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de tabela `servicos`
--
ALTER TABLE `servicos`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de tabela `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de tabela `vendas`
--
ALTER TABLE `vendas`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de tabela `venda_itens`
--
ALTER TABLE `venda_itens`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Restrições para tabelas despejadas
--

--
-- Restrições para tabelas `agendamentos`
--
ALTER TABLE `agendamentos`
  ADD CONSTRAINT `fk_agendamentos_pets` FOREIGN KEY (`pet_id`) REFERENCES `pets` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_agendamentos_profissionais` FOREIGN KEY (`profissional_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_agendamentos_servicos` FOREIGN KEY (`servico_id`) REFERENCES `servicos` (`id`) ON UPDATE CASCADE;

--
-- Restrições para tabelas `pets`
--
ALTER TABLE `pets`
  ADD CONSTRAINT `fk_pets_clientes` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Restrições para tabelas `vendas`
--
ALTER TABLE `vendas`
  ADD CONSTRAINT `fk_vendas_clientes` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_vendas_vendedores` FOREIGN KEY (`vendedor_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Restrições para tabelas `venda_itens`
--
ALTER TABLE `venda_itens`
  ADD CONSTRAINT `fk_venda_itens_produtos` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_venda_itens_servicos` FOREIGN KEY (`servico_id`) REFERENCES `servicos` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_venda_itens_vendas` FOREIGN KEY (`venda_id`) REFERENCES `vendas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
