<?php
session_start();
require 'conexao.php';

// Segurança: Só roda se estiver logado como Gerente ou Admin
$id_loja = $_SESSION['instituicao_id'] ?? null;
if (!$id_loja || ($_SESSION['usuario_cargo'] !== 'Gerente' && $_SESSION['usuario_cargo'] !== 'Admin')) {
    die("<h2 style='color:red; font-family:sans-serif;'>Acesso Negado. Faça login como Gerente primeiro.</h2>");
}

echo "<body style='font-family: Arial, sans-serif; background: #f4f6f9; color: #333; padding: 40px;'>";
echo "<div style='max-width: 800px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);'>";
echo "<h1><i class='fas fa-magic' style='color:#3498db;'></i> Injetor de Massa de Dados (TCC)</h1>";
echo "<p>Populando o banco de dados da instituição ID: <strong>$id_loja</strong>...</p>";

try {
    $pdo->beginTransaction();

    // ==========================================
    // 1. INJETAR SERVIÇOS BASE
    // ==========================================
    $servicos = ['Banho e Tosa', 'Consulta Veterinária', 'Vacina V10 (Cães)'];
    $ids_servicos = [];
    foreach ($servicos as $s) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO servicos (instituicao_id, nome_servico, preco, duracao_minutos) VALUES (?, ?, 50.00, 60)");
        $stmt->execute([$id_loja, $s]);
        
        // Pega o ID (seja do novo ou de um que já existia)
        $st = $pdo->prepare("SELECT id FROM servicos WHERE instituicao_id = ? AND nome_servico = ?");
        $st->execute([$id_loja, $s]);
        $ids_servicos[] = $st->fetchColumn();
    }
    echo "✅ Serviços base criados ou verificados.<br>";

    // ==========================================
    // 2. INJETAR CLIENTES E PETS
    // ==========================================
    $nomes_clientes = ['Carlos Eduardo', 'Mariana Souza', 'Fernando Almeida', 'Beatriz Costa', 'Roberto Justus', 'Luciana Gimenez', 'Thiago Leifert', 'Sabrina Sato', 'Rodrigo Faro', 'Ana Maria'];
    $ids_clientes = [];
    $ids_pets = [];
    
    foreach ($nomes_clientes as $i => $nome) {
        $tel = "4199" . rand(1000000, 9999999);
        $stmt = $pdo->prepare("INSERT INTO clientes (instituicao_id, nome_completo, telefone) VALUES (?, ?, ?)");
        $stmt->execute([$id_loja, $nome, $tel]);
        $cliente_id = $pdo->lastInsertId();
        $ids_clientes[] = $cliente_id;

        // Cria 1 Pet para cada cliente
        $nome_pet = ['Rex', 'Luna', 'Thor', 'Mel', 'Bob', 'Amora', 'Bidu', 'Nina', 'Max', 'Maggie'][$i];
        $consumo = rand(150, 400); // Come de 150g a 400g por dia
        $stmt_pet = $pdo->prepare("INSERT INTO pets (instituicao_id, cliente_id, nome, especie, raca, consumo_diario_g) VALUES (?, ?, ?, 'Cão', 'SRD', ?)");
        $stmt_pet->execute([$id_loja, $cliente_id, $nome_pet, $consumo]);
        $ids_pets[] = $pdo->lastInsertId();
    }
    echo "✅ 10 Clientes e seus Pets gerados (com consumo diário preenchido).<br>";

    // ==========================================
    // 3. INJETAR PRODUTOS (Rações)
    // ==========================================
    $racoes = [
        ['Ração Golden Formula Cães 15kg', 15.000],
        ['Ração PremieR Ambientes Internos 12kg', 12.000],
        ['Ração Pedigree Nutrição 10kg', 10.000],
        ['Ração Royal Canin Shih Tzu 7.5kg', 7.500]
    ];
    $ids_produtos = [];
    foreach ($racoes as $r) {
        $stmt = $pdo->prepare("INSERT INTO produtos (instituicao_id, nome_produto, codigo_barras, preco_venda, quantidade_estoque, tamanho_embalagem_kg) VALUES (?, ?, ?, ?, ?, ?)");
        $cod = "789" . rand(100000, 999999);
        $preco = rand(120, 250);
        $stmt->execute([$id_loja, $r[0], $cod, $preco, 50, $r[1]]);
        $ids_produtos[] = ['id' => $pdo->lastInsertId(), 'nome' => $r[0]];
    }
    echo "✅ Produtos (Rações) cadastrados.<br>";

    // ==========================================
    // 4. INJETAR VENDAS (Faturamento)
    // ==========================================
    // Gera 15 vendas aleatórias no mês atual
    for ($i = 0; $i < 15; $i++) {
        $dia_rand = str_pad(rand(1, date('d')), 2, '0', STR_PAD_LEFT);
        $data_venda = date("Y-m-$dia_rand H:i:s");
        $valor = rand(50, 300);
        $stmt = $pdo->prepare("INSERT INTO vendas (instituicao_id, cliente_id, valor_total, data_venda, forma_pagamento) VALUES (?, ?, ?, ?, 'Cartão Crédito')");
        $stmt->execute([$id_loja, $ids_clientes[array_rand($ids_clientes)], $valor, $data_venda]);
    }
    echo "✅ 15 Vendas criadas para alimentar a Dashboard Financeira.<br>";

    // ==========================================
    // 5. INJETAR AGENDAMENTOS (Gráficos)
    // ==========================================
    // Gera agendamentos para os últimos 7 dias e para hoje
    for ($d = 6; $d >= 0; $d--) {
        $qtd_agendamentos = rand(1, 5); // 1 a 5 agendamentos por dia
        $data_base = date('Y-m-d', strtotime("-$d days"));
        
        for ($a = 0; $a < $qtd_agendamentos; $a++) {
            $hora = str_pad(rand(8, 17), 2, '0', STR_PAD_LEFT) . ':00:00'; // Entre 08h e 17h
            $data_hora = "$data_base $hora";
            $servico_id = $ids_servicos[array_rand($ids_servicos)];
            $pet_id = $ids_pets[array_rand($ids_pets)];
            
            // Se for hoje, deixa 'Marcado', se for passado, deixa 'Concluído' (na maioria)
            $status = ($d == 0) ? 'Marcado' : (rand(0,10) > 2 ? 'Concluído' : 'Cancelado');

            $stmt = $pdo->prepare("INSERT INTO agendamentos (instituicao_id, pet_id, servico_id, data_hora_inicio, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$id_loja, $pet_id, $servico_id, $data_hora, $status]);
        }
    }
    echo "✅ Agendamentos distribuídos na semana (Gráficos alimentados).<br>";

    // ==========================================
    // 6. INJETAR CRM (Previsão de Ração)
    // ==========================================
    // Criamos 3 alertas estourando no CRM
    $previsoes = ['-1 days', '+2 days', '+5 days']; 
    for ($i = 0; $i < 3; $i++) {
        $c_id = $ids_clientes[$i];
        $p_id = $ids_pets[$i];
        $prod = $ids_produtos[array_rand($ids_produtos)]['nome'];
        
        $data_compra = date('Y-m-d', strtotime('-40 days')); // Comprou mês passado
        $data_fim = date('Y-m-d', strtotime($previsoes[$i])); // Acaba perto de hoje
        
        $stmt = $pdo->prepare("INSERT INTO oportunidades_venda (instituicao_id, cliente_id, pet_id, produto_nome, data_compra, data_previsao_fim, status) VALUES (?, ?, ?, ?, ?, ?, 'Pendente')");
        $stmt->execute([$id_loja, $c_id, $p_id, $prod, $data_compra, $data_fim]);
    }
    echo "✅ 3 Oportunidades de Venda ativas injetadas no CRM.<br>";

    $pdo->commit();

    echo "<h2 style='color:#27ae60; margin-top:30px;'>🎉 Injeção Concluída com Sucesso!</h2>";
    echo "<p>Seu sistema agora tem vida! Volte para a Dashboard para ver os gráficos, o faturamento e a tela de CRM piscando.</p>";
    echo "<a href='dashboard_gerente.php' style='display:inline-block; margin-top:15px; padding: 10px 20px; background:#3498db; color:white; text-decoration:none; border-radius:5px;'>Ir para a Dashboard</a>";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "<h2 style='color:#e74c3c;'>❌ Erro Fatal</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
}
echo "</div></body>";
?>