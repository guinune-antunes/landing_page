<?php
session_start();
require 'conexao.php';

if (!isset($_SESSION['usuario_cargo']) || $_SESSION['usuario_cargo'] !== 'Admin') die("Acesso negado.");

$instituicao_id = $_POST['instituicao_id'] ?? null;

if (!$instituicao_id || !isset($_FILES['arquivo_csv_produtos']) || $_FILES['arquivo_csv_produtos']['error'] !== UPLOAD_ERR_OK) {
    die("<script>alert('Erro no envio do arquivo.'); window.history.back();</script>");
}

$arquivo_tmp = $_FILES['arquivo_csv_produtos']['tmp_name'];

try {
    $handle = fopen($arquivo_tmp, "r");
    if ($handle !== FALSE) {
        $pdo->beginTransaction();
        fgetcsv($handle, 1000, ";"); // Pula o cabeçalho

        $total_produtos = 0;
        // O INSERT agora tem 10 colunas em vez de 6
        $sql = "INSERT INTO produtos (instituicao_id, nome_produto, descricao, codigo_barras, preco_custo, preco_venda, quantidade_estoque, tamanho_embalagem_kg, fornecedor, data_fabricacao, data_validade) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);

        while (($linha = fgetcsv($handle, 1000, ";")) !== FALSE) {
            if (empty(array_filter($linha))) continue;

            $nome      = $linha[0] ?? '';
            $descricao = $linha[1] ?? '';
            $codigo    = $linha[2] ?? '';
            
            // Tratamento financeiro (troca vírgula por ponto)
            $custo   = (float) str_replace(',', '.', $linha[3] ?? '0');
            $preco   = (float) str_replace(',', '.', $linha[4] ?? '0');
            
            $estoque = (int) ($linha[5] ?? 0);
            $peso    = !empty($linha[6]) ? (float) str_replace(',', '.', $linha[6]) : null;
            $fornecedor = $linha[7] ?? null;

            // Tratamento de Datas (Converte de DD/MM/AAAA para AAAA-MM-DD do MySQL)
            $fab_cru = $linha[8] ?? '';
            $val_cru = $linha[9] ?? '';
            
            $fabricacao = !empty($fab_cru) ? date('Y-m-d', strtotime(str_replace('/', '-', $fab_cru))) : null;
            $validade   = !empty($val_cru) ? date('Y-m-d', strtotime(str_replace('/', '-', $val_cru))) : null;

            if (empty($nome)) continue; 

            // Injeta as 11 variáveis na ordem exata do INSERT
            $stmt->execute([$instituicao_id, $nome, $descricao, $codigo, $custo, $preco, $estoque, $peso, $fornecedor, $fabricacao, $validade]);
            $total_produtos++;
        }

        fclose($handle);
        $pdo->commit();

        echo "<script>
            alert('Importação de Estoque concluída!\\nProdutos cadastrados: $total_produtos');
            window.location.href = 'gerenciar_instituicao.php?id=$instituicao_id';
        </script>";
    }
} catch (Exception $e) {
    $pdo->rollBack();
    die("<script>alert('Erro na importação: " . addslashes($e->getMessage()) . "'); window.history.back();</script>");
}
?>