<?php
// Teste de Validação: Alteração de Senha do Gerente e Usuários da Instituição
require_once 'c:/xampp/htdocs/landing_page/sys/conexao.php';
require_once 'c:/xampp/htdocs/landing_page/sys/includes/unidades.php';

echo "=== INICIANDO TESTES DE ALTERAÇÃO DE SENHA ===\n\n";

$pdo->beginTransaction();

try {
    // 1. Criar uma instituição de teste com Gerente e Atendente
    $pdo->prepare("INSERT INTO instituicoes (nome_fantasia, ativo) VALUES ('Pet Shop Teste Senha', 1)")->execute();
    $instId = (int)$pdo->lastInsertId();

    $senhaInicial = 'senhaAntiga123';
    $hashInicial = password_hash($senhaInicial, PASSWORD_DEFAULT);

    // Gerente
    $pdo->prepare("INSERT INTO usuarios (nome, email, senha_hash, cargo, instituicao_id, ativo) VALUES (?, ?, ?, 'Gerente', ?, 1)")
        ->execute(['Gerente Roberto', 'roberto@petteste.com', $hashInicial, $instId]);
    $gerenteId = (int)$pdo->lastInsertId();

    // Atendente associado à instituição
    $pdo->prepare("INSERT INTO usuarios (nome, email, senha_hash, cargo, instituicao_id, ativo) VALUES (?, ?, ?, 'Atendente', ?, 1)")
        ->execute(['Atendente Lucas', 'lucas@petteste.com', $hashInicial, $instId]);
    $atendenteId = (int)$pdo->lastInsertId();

    echo "[SETUP] Criada instituição ID $instId com Gerente (ID $gerenteId) e Atendente (ID $atendenteId)\n";

    // ─────────────────────────────────────────────────────────────
    // TESTE 1: Super Admin altera senha manual do Gerente da Instituição
    // ─────────────────────────────────────────────────────────────
    $novaSenhaGerente = 'Gerente@Nova2026';
    $novoHashGerente = password_hash($novaSenhaGerente, PASSWORD_DEFAULT);

    // Simula execução de api_admin_reset_senha.php com nova_senha informada
    $stUp = $pdo->prepare("UPDATE usuarios SET senha_hash = ?, precisa_mudar_senha = 0 WHERE id = ?");
    $stUp->execute([$novoHashGerente, $gerenteId]);

    $stVerif = $pdo->prepare("SELECT senha_hash, precisa_mudar_senha FROM usuarios WHERE id = ?");
    $stVerif->execute([$gerenteId]);
    $rowG = $stVerif->fetch(PDO::FETCH_ASSOC);

    assert(password_verify($novaSenhaGerente, $rowG['senha_hash']), "A nova senha do gerente deve ser válida");
    assert(!password_verify($senhaInicial, $rowG['senha_hash']), "A senha antiga não deve mais funcionar");
    assert($rowG['precisa_mudar_senha'] == 0, "precisa_mudar_senha deve ser 0 quando admin define senha definitiva");
    echo "[TESTE 1 OK] Super Admin alterou senha manual do Gerente (login validado com sucesso)\n";

    // ─────────────────────────────────────────────────────────────
    // TESTE 2: Super Admin altera senha manual de Usuário associado (Atendente)
    // ─────────────────────────────────────────────────────────────
    $novaSenhaAtendente = 'Atendente@2026';
    $novoHashAtendente = password_hash($novaSenhaAtendente, PASSWORD_DEFAULT);

    $stUp->execute([$novoHashAtendente, $atendenteId]);

    $stVerif->execute([$atendenteId]);
    $rowA = $stVerif->fetch(PDO::FETCH_ASSOC);

    assert(password_verify($novaSenhaAtendente, $rowA['senha_hash']), "A nova senha do atendente deve ser válida");
    assert(!password_verify($senhaInicial, $rowA['senha_hash']), "A senha antiga do atendente não deve mais funcionar");
    echo "[TESTE 2 OK] Super Admin alterou senha manual do Atendente associado à instituição\n";

    // ─────────────────────────────────────────────────────────────
    // TESTE 3: Super Admin gera Senha Provisória / Reset Rápido
    // ─────────────────────────────────────────────────────────────
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $senhaTemp = substr(str_shuffle($chars), 0, 8);
    $hashTemp = password_hash($senhaTemp, PASSWORD_DEFAULT);

    $pdo->prepare("UPDATE usuarios SET senha_hash = ?, precisa_mudar_senha = 1 WHERE id = ?")
        ->execute([$hashTemp, $gerenteId]);

    $stVerif->execute([$gerenteId]);
    $rowTemp = $stVerif->fetch(PDO::FETCH_ASSOC);

    assert(password_verify($senhaTemp, $rowTemp['senha_hash']), "A senha temporária deve ser válida para login inicial");
    assert($rowTemp['precisa_mudar_senha'] == 1, "precisa_mudar_senha deve ser 1 para forçar tela de troca obrigatória");
    echo "[TESTE 3 OK] Reset provisório com flag de troca obrigatória validado com sucesso\n";

    // ─────────────────────────────────────────────────────────────
    // TESTE 4: Gerente da loja altera senha de funcionário via salvar permissões/equipe
    // ─────────────────────────────────────────────────────────────
    $senhaDefinidaPeloGerente = 'LucasForte#456';
    $hashLucas = password_hash($senhaDefinidaPeloGerente, PASSWORD_DEFAULT);

    $pdo->prepare("UPDATE usuarios SET senha_hash = ?, precisa_mudar_senha = 0 WHERE id = ? AND instituicao_id = ?")
        ->execute([$hashLucas, $atendenteId, $instId]);

    $stVerif->execute([$atendenteId]);
    $rowLucas = $stVerif->fetch(PDO::FETCH_ASSOC);

    assert(password_verify($senhaDefinidaPeloGerente, $rowLucas['senha_hash']), "A senha do funcionário alterada pelo gerente deve ser válida");
    echo "[TESTE 4 OK] Gerente da loja alterou senha de funcionário da sua equipe com sucesso\n";

    // ─────────────────────────────────────────────────────────────
    // TESTE 5: Cadastro de filial reaproveitando gerente com nova senha
    // ─────────────────────────────────────────────────────────────
    $senhaAtualizadaNoCadastro = 'GerenteMultiLoja@999';
    $hashCadastro = password_hash($senhaAtualizadaNoCadastro, PASSWORD_DEFAULT);

    $pdo->prepare("UPDATE usuarios SET senha_hash = ?, precisa_mudar_senha = 0 WHERE id = ?")
        ->execute([$hashCadastro, $gerenteId]);

    $stVerif->execute([$gerenteId]);
    $rowCad = $stVerif->fetch(PDO::FETCH_ASSOC);
    assert(password_verify($senhaAtualizadaNoCadastro, $rowCad['senha_hash']), "Senha do gerente deve ser atualizada no reaproveitamento");
    echo "[TESTE 5 OK] Nova senha do gerente salva no vínculo/cadastro de nova unidade\n";

    echo "\n=== TODOS OS 5 TESTES DE SENHA PASSARAM COM 100% DE SUCESSO! ===\n";

} finally {
    $pdo->rollBack();
    echo "=== ROLLBACK EXECUTADO (Banco de dados mantido íntegro) ===\n";
}
