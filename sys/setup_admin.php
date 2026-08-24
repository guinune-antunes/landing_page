<?php
// ══════════════════════════════════════════════════════════
// TRAVA DE SEGURANÇA: Bloqueia se já existe um Super Admin
// ══════════════════════════════════════════════════════════
require 'conexao.php';
$checkAdmin = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE cargo IN ('Admin', 'super_admin')")->fetchColumn();
if ($checkAdmin > 0) {
    die("<html><body style='font-family:Poppins,sans-serif;background:#2c3e50;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;'>
         <div style='background:white;padding:40px;border-radius:10px;text-align:center;max-width:450px;box-shadow:0 10px 25px rgba(0,0,0,0.2);'>
         <h2 style='color:#e74c3c;'>⛔ Acesso Bloqueado</h2>
         <p style='color:#666;'>O Setup inicial já foi realizado. Esta página não está mais disponível.</p>
         <a href='login.php' style='display:inline-block;margin-top:15px;padding:10px 25px;background:#1abc9c;color:white;text-decoration:none;border-radius:5px;'>Ir para o Login</a>
         </div></body></html>");
}

// Processamento do Formulário
$mensagem = '';
$tipo_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $nome = filter_input(INPUT_POST, 'nome', FILTER_SANITIZE_STRING);
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $senha = $_POST['senha'];
    $senha_confirma = $_POST['senha_confirma'];

    if ($senha !== $senha_confirma) {
        $mensagem = "As senhas não conferem!";
        $tipo_msg = "error";
    } else {
        // Criptografa a senha
        $senhaHash = password_hash($senha, PASSWORD_DEFAULT);

        try {
            // Insere o Super Admin (instituicao_id é NULL pois ele é dono do SaaS)
            $sql = "INSERT INTO usuarios (nome, email, senha, cargo, instituicao_id, ativo) 
                    VALUES (?, ?, ?, 'super_admin', NULL, 1)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$nome, $email, $senhaHash]);

            $mensagem = "Super Admin criado com sucesso! <br><a href='login.php'>Ir para o Login</a>";
            $tipo_msg = "success";
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $mensagem = "Este e-mail já está cadastrado.";
            } else {
                $mensagem = "Erro ao criar: " . $e->getMessage();
            }
            $tipo_msg = "error";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Criar Super Admin - PetCRM</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap');
        
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #2c3e50;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .setup-card {
            background: white;
            padding: 40px;
            border-radius: 10px;
            width: 100%;
            max-width: 450px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        }
        .setup-card h2 {
            text-align: center;
            color: #333;
            margin-bottom: 10px;
        }
        .setup-card p {
            text-align: center;
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #444;
        }
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 1rem;
            box-sizing: border-box; 
        }
        .btn-submit {
            width: 100%;
            padding: 12px;
            background-color: #e74c3c; /* Vermelho para indicar atenção (Admin) */
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
            margin-top: 10px;
        }
        .btn-submit:hover {
            background-color: #c0392b;
        }
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 0.9rem;
        }
        .alert.error { background-color: #fadbd8; color: #c0392b; border: 1px solid #f5b7b1; }
        .alert.success { background-color: #d4efdf; color: #1e8449; border: 1px solid #a9dfbf; }
        .alert a { font-weight: bold; color: #1e8449; text-decoration: underline; }
    </style>
</head>
<body>

    <div class="setup-card">
        <h2>Configuração Inicial</h2>
        <p>Crie o usuário <strong>Dono do Software</strong> (Super Admin).</p>

        <?php if ($mensagem): ?>
            <div class="alert <?php echo $tipo_msg; ?>">
                <?php echo $mensagem; ?>
            </div>
        <?php endif; ?>

        <?php if ($tipo_msg !== 'success'): // Esconde o form se já criou ?>
        <form method="POST">
            <div class="form-group">
                <label>Nome Completo</label>
                <input type="text" name="nome" required placeholder="Ex: Guilherme Admin">
            </div>
            <div class="form-group">
                <label>E-mail de Login</label>
                <input type="email" name="email" required placeholder="admin@sistemapet.com">
            </div>
            <div class="form-group">
                <label>Senha</label>
                <input type="password" name="senha" required placeholder="******">
            </div>
            <div class="form-group">
                <label>Confirmar Senha</label>
                <input type="password" name="senha_confirma" required placeholder="******">
            </div>
            <button type="submit" class="btn-submit">Criar Acesso Mestre</button>
        </form>
        <?php endif; ?>
    </div>

</body>
</html>