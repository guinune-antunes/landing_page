<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Login - PetCRM</title>
    <style>
        body { font-family: 'Poppins', sans-serif; background: #2c3e50; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .login-card { background: white; padding: 40px; border-radius: 10px; width: 100%; max-width: 400px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); text-align: center; }
        .login-card h2 { color: #333; margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; text-align: left; }
        .form-group label { display: block; margin-bottom: 5px; color: #666; }
        .form-group input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; }
        .btn-login { width: 100%; padding: 12px; background: #1abc9c; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 1rem; font-weight: bold; transition: 0.3s; }
        .btn-login:hover { background: #16a085; }
        .error-msg { color: #e74c3c; background: #fadbd8; padding: 10px; border-radius: 5px; margin-bottom: 15px; display: none; }
    </style>
</head>
<body>
    <div class="login-card">
        <h2>PetCRM Login</h2>
        
        <?php if(isset($_GET['erro'])): ?>
            <div class="error-msg" style="display: block;">E-mail ou senha incorretos.</div>
        <?php endif; ?>

        <form action="auth.php" method="POST">
            <div class="form-group">
                <label>E-mail</label>
                <input type="email" name="email" required>
            </div>
            <div class="form-group">
                <label>Senha</label>
                <input type="password" name="senha" required>
            </div>
            <button type="submit" class="btn-login">Entrar</button>
        </form>
    </div>
</body>
</html>