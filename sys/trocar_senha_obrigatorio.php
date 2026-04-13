<?php
session_start();
require 'conexao.php';

// Proteção: Só acessa se tiver logado E com a flag de forçar troca ativada
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['forcar_troca'])) {
    header("Location: login.php"); exit;
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nova_senha = $_POST['nova_senha'] ?? '';
    $confirma_senha = $_POST['confirma_senha'] ?? '';

    if (strlen($nova_senha) < 6) {
        $erro = "A senha deve ter pelo menos 6 caracteres.";
    } elseif ($nova_senha !== $confirma_senha) {
        $erro = "As senhas não coincidem.";
    } else {
        // Criptografa a nova senha e DESLIGA a flag (0)
        $senhaHash = password_hash($nova_senha, PASSWORD_DEFAULT);
        $sql = "UPDATE usuarios SET senha_hash = ?, precisa_mudar_senha = 0 WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$senhaHash, $_SESSION['usuario_id']]);

        // ==========================================
        // NOVO FLUXO: Desloga o usuário após a troca
        // ==========================================
        session_unset();    // Limpa as variáveis
        session_destroy();  // Destrói a sessão no servidor

        // Redireciona para o login com uma mensagem de sucesso
        header("Location: login.php?msg=senha_atualizada");
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Atualização de Segurança - PetCRM</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { display: flex; justify-content: center; align-items: center; height: 100vh; background: #f4f6f9; }
        .lock-box { background: white; padding: 40px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); max-width: 400px; width: 100%; text-align: center; }
        .lock-box h2 { color: #e74c3c; margin-bottom: 10px; }
        .lock-box p { color: #666; margin-bottom: 20px; font-size: 0.9rem; }
        .form-group { text-align: left; margin-bottom: 15px; }
        .form-group input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; }
    </style>
</head>
<body>

<div class="lock-box">
    <h2><i class="fas fa-shield-alt"></i> Ação Requerida</h2>
    <p>Sua senha foi resetada pela gerência. Para a sua segurança, você deve criar uma nova senha agora para acessar o sistema.</p>
    
    <?php if($erro): ?><div style="color: red; margin-bottom: 15px;"><?php echo $erro; ?></div><?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>Nova Senha</label>
            <input type="password" name="nova_senha" required>
        </div>
        <div class="form-group">
            <label>Confirmar Nova Senha</label>
            <input type="password" name="confirma_senha" required>
        </div>
        <button type="submit" class="btn btn-primary" style="width: 100%;">Salvar e Acessar</button>
    </form>
</div>

</body>
</html>