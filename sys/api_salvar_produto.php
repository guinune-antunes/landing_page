<?php
session_start();
header('Content-Type: application/json');
require 'conexao.php';

$id_loja = $_SESSION['instituicao_id'] ?? null;
if (!$id_loja || ($_SESSION['usuario_cargo'] !== 'Gerente' && $_SESSION['usuario_cargo'] !== 'Admin')) {
    echo json_encode(['success' => false, 'message' => 'Sessão inválida ou sem permissão.']); exit;
}

// Suporte tanto a FormData (multipart/form-data) quanto a JSON bruto (php://input)
$data = [];
if (!empty($_POST)) {
    $data = $_POST;
} else {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true) ?: [];
}

if (empty($data['nome_produto']) || !isset($data['preco_venda'])) {
    echo json_encode(['success' => false, 'message' => 'Nome do produto e preço de venda são obrigatórios.']); exit;
}

// Tratamento de valores para formato consistente
$preco_custo = !empty($data['preco_custo']) ? (float)$data['preco_custo'] : (!empty($data['valor_compra']) ? (float)$data['valor_compra'] : 0.00);
$preco_venda = (float)$data['preco_venda'];
$estoque = isset($data['quantidade_estoque']) ? (int)$data['quantidade_estoque'] : 0;
$peso = !empty($data['tamanho_embalagem_kg']) ? (float)$data['tamanho_embalagem_kg'] : null;
$fab = !empty($data['data_fabricacao']) ? $data['data_fabricacao'] : null;
$val = !empty($data['data_validade']) ? $data['data_validade'] : null;
$categoria = !empty($data['categoria']) ? trim($data['categoria']) : 'Geral';
$marca = !empty($data['marca']) ? trim($data['marca']) : null;
$codigo_barras = !empty($data['codigo_barras']) ? trim($data['codigo_barras']) : null;
$descricao = !empty($data['descricao']) ? trim($data['descricao']) : null;
$fornecedor = !empty($data['fornecedor']) ? trim($data['fornecedor']) : null;
$unidade = !empty($data['unidade']) ? trim($data['unidade']) : 'Unidade';

// Inteligência CRM Ração
$energia = !empty($data['energia_metabolizavel_kg']) ? (float)$data['energia_metabolizavel_kg'] : null;
if ($categoria === 'Ração' && empty($energia)) {
    $energia = 3800.00; // Padrão de 3.800 kcal/kg para cálculo inteligente
}

// Processamento de Upload de Imagem (Opcional)
$caminhoImagem = null;
if (!empty($_FILES['imagem']) && $_FILES['imagem']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['imagem'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $fileType = mime_content_type($file['tmp_name']);
    
    if (in_array($fileType, $allowedTypes)) {
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        if (empty($ext)) {
            $ext = ($fileType === 'image/png') ? 'png' : (($fileType === 'image/webp') ? 'webp' : 'jpg');
        }
        $uploadDir = __DIR__ . '/uploads/produtos/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $filename = 'prod_' . $id_loja . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($ext);
        $dest = $uploadDir . $filename;
        if (move_uploaded_file($file['tmp_name'], $dest)) {
            $caminhoImagem = 'uploads/produtos/' . $filename;
        }
    }
}

try {
    if (empty($data['id'])) {
        // NOVO PRODUTO (INSERT)
        $sql = "INSERT INTO produtos (
                    instituicao_id, nome_produto, marca, categoria, descricao, 
                    codigo_barras, preco_custo, preco_venda, quantidade_estoque, 
                    unidade, tamanho_embalagem_kg, energia_metabolizavel_kg, 
                    fornecedor, data_fabricacao, data_validade, imagem
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $id_loja, $data['nome_produto'], $marca, $categoria, $descricao,
            $codigo_barras, $preco_custo, $preco_venda, $estoque,
            $unidade, $peso, $energia, $fornecedor, $fab, $val, $caminhoImagem
        ]);
        $novoId = $pdo->lastInsertId();
        $msg = 'Produto cadastrado com sucesso no estoque!';
        echo json_encode(['success' => true, 'message' => $msg, 'id' => $novoId, 'imagem' => $caminhoImagem]);
    } else {
        // EDITAR PRODUTO (UPDATE)
        if ($caminhoImagem) {
            // Atualizou a foto
            $sql = "UPDATE produtos SET 
                    nome_produto = ?, marca = ?, categoria = ?, descricao = ?, codigo_barras = ?, 
                    preco_custo = ?, preco_venda = ?, quantidade_estoque = ?, unidade = ?, 
                    tamanho_embalagem_kg = ?, energia_metabolizavel_kg = ?, fornecedor = ?, 
                    data_fabricacao = ?, data_validade = ?, imagem = ?
                    WHERE id = ? AND instituicao_id = ?";
            $params = [
                $data['nome_produto'], $marca, $categoria, $descricao, $codigo_barras,
                $preco_custo, $preco_venda, $estoque, $unidade,
                $peso, $energia, $fornecedor, $fab, $val, $caminhoImagem,
                $data['id'], $id_loja
            ];
        } else {
            // Mantém a foto anterior
            $sql = "UPDATE produtos SET 
                    nome_produto = ?, marca = ?, categoria = ?, descricao = ?, codigo_barras = ?, 
                    preco_custo = ?, preco_venda = ?, quantidade_estoque = ?, unidade = ?, 
                    tamanho_embalagem_kg = ?, energia_metabolizavel_kg = ?, fornecedor = ?, 
                    data_fabricacao = ?, data_validade = ?
                    WHERE id = ? AND instituicao_id = ?";
            $params = [
                $data['nome_produto'], $marca, $categoria, $descricao, $codigo_barras,
                $preco_custo, $preco_venda, $estoque, $unidade,
                $peso, $energia, $fornecedor, $fab, $val,
                $data['id'], $id_loja
            ];
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $msg = 'Produto atualizado com sucesso!';
        echo json_encode(['success' => true, 'message' => $msg, 'id' => $data['id'], 'imagem' => $caminhoImagem]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erro ao salvar produto: ' . $e->getMessage()]);
}
