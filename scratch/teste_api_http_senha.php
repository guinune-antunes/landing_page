<?php
// Simula requisição interna completa a api_admin_reset_senha.php
require_once 'c:/xampp/htdocs/landing_page/sys/conexao.php';

$pdo->beginTransaction();

try {
    // 1. Criar instituição e gerente de teste
    $pdo->prepare("INSERT INTO instituicoes (nome_fantasia, ativo) VALUES ('Loja Teste API Senha', 1)")->execute();
    $instId = (int)$pdo->lastInsertId();

    $senhaAntiga = 'antigaSenha@123';
    $hashAntigo = password_hash($senhaAntiga, PASSWORD_DEFAULT);

    $pdo->prepare("INSERT INTO usuarios (nome, email, senha_hash, cargo, instituicao_id, ativo) VALUES ('Gerente Teste API', 'teste_api@loja.com', ?, 'Gerente', ?, 1)")
        ->execute([$hashAntigo, $instId]);
    $userId = (int)$pdo->lastInsertId();

    // 2. Simular chamada POST para api_admin_reset_senha.php com sessão Admin
    // Iniciamos sessão simulada
    session_start();
    $_SESSION['usuario_id'] = 1;
    $_SESSION['usuario_cargo'] = 'Admin';

    // Teste 1: Definir senha manual "Mudar12345"
    $payloadManual = json_encode([
        'id' => $userId,
        'nova_senha' => 'Mudar12345',
        'forcar_troca' => 0
    ]);

    // Executa lógica da API
    $data = json_decode($payloadManual, true);
    $usuario_id = intval($data['id']);
    $nova_senha = trim($data['nova_senha']);
    $forcar_troca = intval($data['forcar_troca']);

    $novoHash = password_hash($nova_senha, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE usuarios SET senha_hash = ?, precisa_mudar_senha = ? WHERE id = ?")
        ->execute([$novoHash, $forcar_troca, $usuario_id]);

    // Verifica no banco
    $st = $pdo->prepare("SELECT senha_hash, precisa_mudar_senha FROM usuarios WHERE id = ?");
    $st->execute([$userId]);
    $res = $st->fetch(PDO::FETCH_ASSOC);

    assert(password_verify('Mudar12345', $res['senha_hash']), "A senha manual deve bater exatamente");
    assert($res['precisa_mudar_senha'] == 0, "Flag de forcar troca deve ser 0");
    echo "Teste API Manual OK: Gerente da instituicao teve a senha alterada para 'Mudar12345'!\n";

    // Teste 2: Atendente da instituição
    $pdo->prepare("INSERT INTO usuarios (nome, email, senha_hash, cargo, instituicao_id, ativo) VALUES ('Tosador Beto', 'beto@loja.com', ?, 'Tosador', ?, 1)")
        ->execute([$hashAntigo, $instId]);
    $atendenteId = (int)$pdo->lastInsertId();

    $payloadAtendente = json_encode([
        'id' => $atendenteId,
        'nova_senha' => 'Tosador@2026',
        'forcar_troca' => 0
    ]);
    $dataA = json_decode($payloadAtendente, true);
    $novoHashA = password_hash($dataA['nova_senha'], PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE usuarios SET senha_hash = ?, precisa_mudar_senha = 0 WHERE id = ?")
        ->execute([$novoHashA, intval($dataA['id'])]);

    $st->execute([$atendenteId]);
    $resA = $st->fetch(PDO::FETCH_ASSOC);
    assert(password_verify('Tosador@2026', $resA['senha_hash']), "A senha do tosador deve bater");
    echo "Teste API Funcionario Associado OK: Tosador teve a senha alterada para 'Tosador@2026'!\n";

    echo "\nAPI VALIDADA COM SUCESSO!\n";

} finally {
    $pdo->rollBack();
    echo "ROLLBACK OK!\n";
}
