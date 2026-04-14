<?php
session_start();
require 'conexao.php';

// Segurança Máxima
if (!isset($_SESSION['usuario_cargo']) || $_SESSION['usuario_cargo'] !== 'Admin') {
    die("Acesso negado.");
}

$id_loja = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id_loja) die("ID da instituição não fornecido.");

try {
    // Busca todos os Pets e seus respectivos Tutores vinculados à loja
    $sql = "SELECT c.nome_completo AS Tutor, c.cpf AS CPF, c.telefone AS Telefone, c.email AS Email, 
                   c.cep AS CEP, c.logradouro AS Logradouro, c.numero AS Numero, c.bairro AS Bairro, c.cidade AS Cidade, c.estado AS Estado,
                   p.nome AS Pet, p.especie AS Especie, p.raca AS Raca 
            FROM clientes c
            LEFT JOIN pets p ON c.id = p.cliente_id
            WHERE c.instituicao_id = ?
            ORDER BY c.nome_completo";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id_loja]);
    $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Configura os cabeçalhos do navegador para forçar o download de um CSV
    $nome_arquivo = "exportacao_loja_" . $id_loja . "_" . date('Ymd_His') . ".csv";
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $nome_arquivo);
    
    // Abre o buffer de saída do PHP
    $output = fopen('php://output', 'w');
    
    // Força o Excel a ler os acentos corretamente (BOM UTF-8)
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // Se houver dados, imprime o cabeçalho (nomes das colunas)
    if (count($dados) > 0) {
        // Usa ponto-e-vírgula (;) pois é o padrão do Excel em português
        fputcsv($output, array_keys($dados[0]), ';'); 
        
        // Imprime as linhas de dados
        foreach ($dados as $linha) {
            fputcsv($output, $linha, ';');
        }
    } else {
        // Se a loja não tiver clientes cadastrados
        fputcsv($output, ['Nenhum dado encontrado para esta instituição'], ';');
    }

    fclose($output);
    exit;

} catch (Exception $e) {
    die("Erro ao gerar exportação: " . $e->getMessage());
}
?>