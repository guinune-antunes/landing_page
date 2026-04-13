<?php
// 1. Inicia a sessão para podermos manipulá-la
session_start();

// 2. Esvazia todas as variáveis da sessão atual
$_SESSION = array();

// 3. (Opcional, mas recomendado) Apaga o cookie de sessão do navegador do usuário
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 4. Destrói a sessão completamente no servidor
session_destroy();

// 5. Redireciona para a tela de login que você especificou
header("Location: login.php");
exit;
?>