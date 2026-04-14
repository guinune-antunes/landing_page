<?php
session_start();
require 'conexao.php';

// Segurança Máxima
if (!isset($_SESSION['usuario_cargo']) || $_SESSION['usuario_cargo'] !== 'Admin') {
    die("Acesso negado.");
}

$instituicao_id = $_POST['instituicao_id'] ?? null;

// Verifica se o arquivo foi enviado e não tem erros
if (!$instituicao_id || !isset($_FILES['arquivo_csv']) || $_FILES['arquivo_csv']['error'] !== UPLOAD_ERR_OK) {
    die("<script>alert('Erro no envio do arquivo.'); window.history.back();</script>");
}

$arquivo_tmp = $_FILES['arquivo_csv']['tmp_name'];

try {
    // Abre o arquivo CSV em modo de leitura
    $handle = fopen($arquivo_tmp, "r");
    if ($handle !== FALSE) {
        
        // Inicia uma transação: Se der erro na linha 50, ele desfaz as 49 anteriores (Segurança de Dados)
        $pdo->beginTransaction();

        // Pula o cabeçalho do CSV (primeira linha)
        fgetcsv($handle, 1000, ";");

        // Dicionário de memória para evitar duplicar o mesmo Tutor se ele tiver 2 ou mais pets
        $clientes_em_memoria = [];
        $total_clientes = 0;
        $total_pets = 0;

        // Loop lendo linha por linha
        while (($linha = fgetcsv($handle, 1000, ";")) !== FALSE) {
            
            // Ignora linhas totalmente vazias
            if (empty(array_filter($linha))) continue;

            // Extrai os dados baseado no NOVO padrão (13 colunas)
            $tutor_nome = $linha[0] ?? '';
            $cpf        = $linha[1] ?? '';
            $telefone   = $linha[2] ?? '';
            $email      = $linha[3] ?? '';
            $cep        = $linha[4] ?? '';
            $logradouro = $linha[5] ?? '';
            $numero     = $linha[6] ?? '';
            $bairro     = $linha[7] ?? '';
            $cidade     = $linha[8] ?? '';
            $estado     = $linha[9] ?? '';
            $pet_nome   = $linha[10] ?? '';
            $especie    = $linha[11] ?? '';
            $raca       = $linha[12] ?? '';

            if (empty($tutor_nome)) continue; 

            $chave_tutor = !empty($cpf) ? $cpf : $tutor_nome;

            if (!isset($clientes_em_memoria[$chave_tutor])) {
                // Cadastra o Tutor novo COM ENDEREÇO
                $sql_cliente = "INSERT INTO clientes (instituicao_id, nome_completo, cpf, telefone, email, cep, logradouro, numero, bairro, cidade, estado) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt_cliente = $pdo->prepare($sql_cliente);
                $stmt_cliente->execute([
                    $instituicao_id, $tutor_nome, $cpf, $telefone, $email, 
                    $cep, $logradouro, $numero, $bairro, $cidade, $estado
                ]);
                
                $id_cliente_inserido = $pdo->lastInsertId();
                $clientes_em_memoria[$chave_tutor] = $id_cliente_inserido;
                $total_clientes++;
            } else {
                $id_cliente_inserido = $clientes_em_memoria[$chave_tutor];
            }

            // --- LÓGICA DO PET (Mantém igual) ---
            if (!empty($pet_nome)) {
                $sql_pet = "INSERT INTO pets (instituicao_id, cliente_id, nome, especie, raca) VALUES (?, ?, ?, ?, ?)";
                $stmt_pet = $pdo->prepare($sql_pet);
                $stmt_pet->execute([$instituicao_id, $id_cliente_inserido, $pet_nome, $especie, $raca]);
                $total_pets++;
            }
        }

        fclose($handle);
        $pdo->commit(); // Salva tudo no banco de forma definitiva

        echo "<script>
            alert('Importação concluída com sucesso!\\nClientes inseridos: $total_clientes\\nPets vinculados: $total_pets');
            window.location.href = 'gerenciar_instituicao.php?id=$instituicao_id';
        </script>";

    } else {
        throw new Exception("Não foi possível ler o arquivo.");
    }

} catch (Exception $e) {
    $pdo->rollBack(); // Em caso de erro, desfaz tudo que tentou importar
    die("<script>alert('Erro fatal durante a importação: " . addslashes($e->getMessage()) . "'); window.history.back();</script>");
}
?>