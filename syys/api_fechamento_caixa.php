<?php
session_start();
header('Content-Type: application/json');
require 'conexao.php';

if (!isset($_SESSION['instituicao_id'])) {
    echo json_encode(['success' => false, 'message' => 'Erro de sessão.']); exit;
}

$id_loja = $_SESSION['instituicao_id'];

try {
    // 1. Totais Financeiros
    $sqlTotais = "SELECT forma_pagamento, SUM(total_venda) as total 
                  FROM vendas 
                  WHERE instituicao_id = ? AND DATE(data_venda) = CURRENT_DATE() 
                  GROUP BY forma_pagamento";
    $stmt = $pdo->prepare($sqlTotais);
    $stmt->execute([$id_loja]);
    $resumoFinanceiro = $stmt->fetchAll();

    // 2. Itens Vendidos
    $sqlItens = "SELECT i.nome_item, SUM(i.quantidade) as qtd, SUM(i.subtotal) as total
                 FROM itens_venda i
                 JOIN vendas v ON i.venda_id = v.id
                 WHERE v.instituicao_id = ? AND DATE(v.data_venda) = CURRENT_DATE()
                 GROUP BY i.nome_item
                 ORDER BY qtd DESC";
    $stmt2 = $pdo->prepare($sqlItens);
    $stmt2->execute([$id_loja]);
    $itensVendidos = $stmt2->fetchAll();

    // 3. Total Geral
    $totalGeral = 0;
    foreach ($resumoFinanceiro as $r) {
        $totalGeral += $r['total'];
    }

    // 4. Dados da Empresa (Para o cabeçalho do Ticket)
    $stmtEmpresa = $pdo->prepare("SELECT nome_fantasia, cnpj FROM instituicoes WHERE id = ?");
    $stmtEmpresa->execute([$id_loja]);
    $empresa = $stmtEmpresa->fetch();

    echo json_encode([
        'success' => true,
        'financeiro' => $resumoFinanceiro,
        'itens' => $itensVendidos,
        'total_geral' => $totalGeral,
        // DADOS DO OPERADOR E EMPRESA
        'operador' => [
            'nome' => $_SESSION['usuario_nome'],
            'cargo' => ucfirst($_SESSION['usuario_cargo'])
        ],
        'empresa' => $empresa,
        'data_hora' => date('d/m/Y H:i:s')
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>