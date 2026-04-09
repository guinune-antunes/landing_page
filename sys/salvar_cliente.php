<?php
session_start();
header('Content-Type: application/json');
require 'conexao.php';

// 1. Segurança
if (!isset($_SESSION['instituicao_id'])) {
    echo json_encode(['success' => false, 'message' => 'Sessão inválida.']); exit;
}
$id_loja = $_SESSION['instituicao_id'];

// 2. Recebe Dados
$data = json_decode(file_get_contents('php://input'), true);
$cliente = $data['cliente'];
$pets = $data['pets'];

// Validação: Verifica se 'nome_completo' veio preenchido
if (empty($cliente['nome_completo'])) {
    echo json_encode(['success' => false, 'message' => 'O nome do cliente é obrigatório.']); exit;
}

try {
    $pdo->beginTransaction();
    $cliente_id = null;

    if (empty($cliente['id'])) {
        // --- INSERT (Novo) ---
        $sql = "INSERT INTO clientes 
                    (instituicao_id, nome_completo, cpf, telefone, email, cep, logradouro, numero, bairro, cidade, estado) 
                VALUES 
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $id_loja,
            $cliente['nome_completo'],
            $cliente['cpf'], // CPF
            $cliente['telefone'],
            $cliente['email'],
            $cliente['cep'],
            $cliente['logradouro'],
            $cliente['numero'],
            $cliente['bairro'],
            $cliente['cidade'],
            $cliente['estado']
        ]);
        $cliente_id = $pdo->lastInsertId();
        $msg = 'Cliente salvo!';

    } else {
        // --- UPDATE (Edição) ---
        $cliente_id = $cliente['id'];
        
        $sql = "UPDATE clientes SET 
                    nome_completo = ?, 
                    cpf = ?, 
                    telefone = ?, 
                    email = ?, 
                    cep = ?, 
                    logradouro = ?, 
                    numero = ?, 
                    bairro = ?, 
                    cidade = ?, 
                    estado = ? 
                WHERE id = ? AND instituicao_id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $cliente['nome_completo'],
            $cliente['cpf'], // Atualiza CPF
            $cliente['telefone'],
            $cliente['email'],
            $cliente['cep'],
            $cliente['logradouro'],
            $cliente['numero'],
            $cliente['bairro'],
            $cliente['cidade'],
            $cliente['estado'],
            $cliente_id, // ID do Cliente
            $id_loja     // ID da Loja (Segurança)
        ]);
        $msg = 'Cliente atualizado!';
    }

    // --- PETS (Sincronização Simples: Apaga e Recria) ---
    $pdo->prepare("DELETE FROM pets WHERE cliente_id = ?")->execute([$cliente_id]);
    
    if (!empty($pets)) {
        $sql_pet = "INSERT INTO pets (instituicao_id, cliente_id, nome, especie, raca, data_nascimento) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt_pet = $pdo->prepare($sql_pet);
        
        foreach ($pets as $pet) {
            $nasc = !empty($pet['nascimento']) ? $pet['nascimento'] : null;
            $stmt_pet->execute([$id_loja, $cliente_id, $pet['nome'], $pet['especie'], $pet['raca'], $nasc]);
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => $msg]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
}
?>