<?php
require 'c:/xampp/htdocs/landing_page/sys/conexao.php';

echo "=== TESTE FLUXO DE SENHA ===\n\n";

$pdo->beginTransaction();

try {
    // 1. Criar instituição e gerente para o teste
    $pdo->prepare("INSERT INTO instituicoes (nome_fantasia, ativo) VALUES ('Loja Teste Senha', 1)")->execute();
    $instId = (int)$pdo->lastInsertId();

    $senhaOriginal = 'senhaAntiga123';
    $hashOriginal = password_hash($senhaOriginal, PASSWORD_DEFAULT);

    $pdo->prepare("INSERT INTO usuarios (nome, email, senha_hash, cargo, instituicao_id, ativo) VALUES (?, ?, ?, 'Gerente', ?, 1)")
        ->execute(['Gerente Teste', 'teste_senha@pet.com', $hashOriginal, $instId]);
    $userId = (int)$pdo->lastInsertId();

    echo "[1] Gerente criado: ID $userId, email: teste_senha@pet.com, senha: $senhaOriginal\n";

    // 2. Simular api_admin_reset_senha.php
    $senhaTemp = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
    $hashTemp = password_hash($senhaTemp, PASSWORD_DEFAULT);

    $sql = "UPDATE usuarios SET senha_hash = ?, precisa_mudar_senha = 1 WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$hashTemp, $userId]);

    echo "[2] api_admin_reset_senha executado. Senha temp gerada: $senhaTemp\n";

    // 3. Testar se a senha temp bate com password_verify
    $st = $pdo->prepare("SELECT senha_hash, precisa_mudar_senha FROM usuarios WHERE id = ?");
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    $valida = password_verify($senhaTemp, $row['senha_hash']);
    echo "[3] password_verify com senha temp: " . ($valida ? 'VÁLIDA' : 'INVÁLIDA') . "\n";
    echo "    precisa_mudar_senha está: " . $row['precisa_mudar_senha'] . "\n";

    // 4. Testar trocar_senha_obrigatorio.php
    $novaSenhaDefinitiva = 'NovaSenha@2026';
    $novoHash = password_hash($novaSenhaDefinitiva, PASSWORD_DEFAULT);

    $sqlTroca = "UPDATE usuarios SET senha_hash = ?, precisa_mudar_senha = 0 WHERE id = ?";
    $pdo->prepare($sqlTroca)->execute([$novoHash, $userId]);

    $st->execute([$userId]);
    $rowApos = $st->fetch(PDO::FETCH_ASSOC);
    $validaNova = password_verify($novaSenhaDefinitiva, $rowApos['senha_hash']);
    echo "[4] Apos troca obrigatoria, password_verify com nova senha: " . ($validaNova ? 'VÁLIDA' : 'INVÁLIDA') . "\n";
    echo "    precisa_mudar_senha está: " . $rowApos['precisa_mudar_senha'] . "\n";

} finally {
    $pdo->rollBack();
    echo "\n=== ROLLBACK OK ===\n";
}
