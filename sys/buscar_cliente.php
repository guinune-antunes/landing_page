<?php
// Inicia a sessão para pegar o ID da loja
session_start();
header('Content-Type: application/json');

require 'conexao.php'; 

// --- 1. SEGURANÇA SAAS ---
if (!isset($_SESSION['instituicao_id'])) {
    echo json_encode(['success' => false, 'message' => 'Sessão inválida ou expirada.']);
    exit;
}
$id_loja = $_SESSION['instituicao_id'];

$response = [
    'success' => false,
    'cliente' => null,
    'pets' => []
];

// --- 2. VERIFICAR ID ---
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $response['message'] = 'ID do cliente não fornecido.';
    echo json_encode($response);
    exit;
}

$clientId = (int)$_GET['id'];

try {
    // --- 3. BUSCAR CLIENTE (FILTRADO PELA LOJA) ---
    $sql_cliente = "SELECT 
                        id, 
                        nome_completo, 
                        cpf,  -- <-- Adicionado CPF
                        telefone, 
                        email, 
                        cep, 
                        logradouro, 
                        numero, 
                        bairro, 
                        cidade, 
                        estado 
                    FROM 
                        clientes 
                    WHERE 
                        id = ? AND instituicao_id = ?"; // <-- Trava de segurança
    
    $stmt_cliente = $pdo->prepare($sql_cliente);
    $stmt_cliente->execute([$clientId, $id_loja]); // Passa ID do cliente e ID da loja
    $cliente = $stmt_cliente->fetch();

    if ($cliente) {
        $response['success'] = true;
        $response['cliente'] = $cliente;

        // --- 4. BUSCAR PETS ---
        $sql_pets = "SELECT 
                        id, -- É bom trazer o ID do pet também
                        nome, 
                        especie, 
                        raca, 
                        data_nascimento AS nascimento 
                     FROM 
                        pets 
                     WHERE 
                        cliente_id = ?";
        
        $stmt_pets = $pdo->prepare($sql_pets);
        $stmt_pets->execute([$clientId]);
        $pets = $stmt_pets->fetchAll();

        $response['pets'] = $pets;

    } else {
        $response['message'] = 'Cliente não encontrado ou não pertence à sua loja.';
    }

} catch (PDOException $e) {
    $response['message'] = 'Erro de SQL: ' . $e->getMessage();
}

echo json_encode($response);
?>