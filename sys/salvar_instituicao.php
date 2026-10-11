<?php
session_start();
header('Content-Type: application/json');
require 'conexao.php';

// CORREÇÃO 1: O cargo correto do administrador no seu banco é 'Admin'
if (!isset($_SESSION['usuario_cargo']) || $_SESSION['usuario_cargo'] !== 'Admin') {
    echo json_encode(['success' => false, 'message' => 'Sem permissão.']); 
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

// ── Multi-unidade ─────────────────────────────────────────────────
//  rede_id      → adiciona esta loja como NOVA UNIDADE de uma rede existente
//  criar_rede   → cria uma rede nova e esta loja vira a matriz
//  (nenhum)     → loja independente (comportamento original)
$redeIdExistente = !empty($data['rede_id']) ? (int)$data['rede_id'] : null;
$criarRede       = !empty($data['criar_rede']);
$nomeRede        = trim($data['nome_rede'] ?? '');
$nomeUnidade     = trim($data['nome_unidade'] ?? '') ?: null;
$cidade          = trim($data['cidade'] ?? '') ?: null;
$uf              = isset($data['uf']) && trim($data['uf']) !== '' ? strtoupper(substr(trim($data['uf']), 0, 2)) : null;
$gerenteGeral    = !empty($data['gerente_geral']);

try {
    $pdo->beginTransaction();

    $modulosPadrao = json_encode([
        'agenda' => true,
        'clientes' => true,
        'financeiro' => true,
        'estoque' => true,
        'crm_vendas' => true,
        'relatorios' => true,
        'equipe' => true,
        'clinica' => true
    ]);

    // 1. Resolve a rede
    $redeId = null;
    $matriz = 0;
    if ($redeIdExistente) {
        $chk = $pdo->prepare("SELECT id FROM redes WHERE id = ?");
        $chk->execute([$redeIdExistente]);
        if (!$chk->fetchColumn()) {
            throw new RuntimeException('Rede não encontrada.');
        }
        $redeId = $redeIdExistente;
    } elseif ($criarRede) {
        $compCli = !empty($data['compartilhar_clientes']) ? 1 : 0;
        $compFid = !empty($data['compartilhar_fidelidade']) ? 1 : 0;
        $pdo->prepare("INSERT INTO redes (nome, compartilhar_clientes, compartilhar_fidelidade) VALUES (?, ?, ?)")
            ->execute([$nomeRede !== '' ? $nomeRede : $data['nome_fantasia'], $compCli, $compFid]);
        $redeId = (int)$pdo->lastInsertId();
        $matriz = 1;
    }

    // 2. Cria a instituição (unidade)
    $sqlInst = "INSERT INTO instituicoes (nome_fantasia, cnpj, modulos_contratados, rede_id, nome_unidade, cidade, uf, matriz)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($sqlInst);
    $stmt->execute([$data['nome_fantasia'], $data['cnpj'], $modulosPadrao, $redeId, $nomeUnidade, $cidade, $uf, $matriz]);
    
    $novaInstituicaoId = (int)$pdo->lastInsertId();

    // 3. Gerente da unidade
    $emailGerente = trim($data['gerente_email'] ?? '');
    $stmtEx = $pdo->prepare("SELECT u.id, u.cargo, u.instituicao_id, i.rede_id
                             FROM usuarios u LEFT JOIN instituicoes i ON i.id = u.instituicao_id
                             WHERE u.email = ?");
    $stmtEx->execute([$emailGerente]);
    $existente = $stmtEx->fetch();

    if ($existente) {
        // E-mail já cadastrado: só pode ser reaproveitado se for Gerente da MESMA rede
        // (é assim que um gerente passa a ter mais de uma unidade).
        if (!$redeId || $existente['cargo'] !== 'Gerente' || (int)$existente['rede_id'] !== $redeId) {
            throw new RuntimeException('Este e-mail de gerente já existe.');
        }
        $pdo->prepare("INSERT IGNORE INTO usuario_unidades (usuario_id, instituicao_id) VALUES (?, ?)")
            ->execute([$existente['id'], $novaInstituicaoId]);
        if ($gerenteGeral) {
            $pdo->prepare("UPDATE usuarios SET acesso_rede = 1 WHERE id = ?")->execute([$existente['id']]);
        }
        if (!empty($data['gerente_senha']) && strlen(trim((string)$data['gerente_senha'])) >= 4) {
            $novoHash = password_hash($data['gerente_senha'], PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE usuarios SET senha_hash = ?, precisa_mudar_senha = 0 WHERE id = ?")
                ->execute([$novoHash, $existente['id']]);
        }
        $msg = 'Unidade criada e vinculada ao gerente existente!';
    } else {
        // Criar o Usuário Gerente vinculado à unidade (Gerente Geral só faz sentido dentro de uma rede)
        $senhaHash = password_hash($data['gerente_senha'], PASSWORD_DEFAULT);
        $sqlUser = "INSERT INTO usuarios (nome, email, senha_hash, cargo, instituicao_id, ativo, acesso_rede) VALUES (?, ?, ?, 'Gerente', ?, 1, ?)";
        $pdo->prepare($sqlUser)->execute([
            $data['gerente_nome'],
            $emailGerente,
            $senhaHash,
            $novaInstituicaoId,
            ($redeId && $gerenteGeral) ? 1 : 0
        ]);
        $msg = 'Instituição e Gerente criados com sucesso!';
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => $msg]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Verifica erro de e-mail duplicado
    if ($e->getCode() == 23000) {
        echo json_encode(['success' => false, 'message' => 'Este e-mail de gerente já existe.']);
    } elseif ($e instanceof RuntimeException) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Erro do Banco: ' . $e->getMessage()]);
    }
}
?>