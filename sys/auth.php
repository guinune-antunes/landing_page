<?php
ob_start();
session_start();
require 'conexao.php';

$email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
$senha_digitada = $_POST['senha'] ?? '';

try {
    if ($email && $senha_digitada) {
        // 1. ADICIONADO: Coluna 'precisa_mudar_senha' incluída na busca
        //    + status da instituição (lojas bloqueadas não fazem login)
        $stmt = $pdo->prepare("SELECT u.id, u.nome, u.email, u.senha_hash, u.cargo, u.instituicao_id, u.precisa_mudar_senha, i.ativo AS instituicao_ativa
                               FROM usuarios u
                               LEFT JOIN instituicoes i ON i.id = u.instituicao_id
                               WHERE u.email = ? AND u.ativo = 1");
        $stmt->execute([$email]);
        $usuario = $stmt->fetch();

        if ($usuario && password_verify($senha_digitada, $usuario['senha_hash'])) {

            // Instituição bloqueada pelo Admin do SaaS
            if (!empty($usuario['instituicao_id']) && (int)$usuario['instituicao_ativa'] === 0) {
                header("Location: login.php?erro=bloqueado");
                exit;
            }
            
            // Salva os dados vitais na sessão
            $_SESSION['usuario_id'] = $usuario['id'];
            $_SESSION['usuario_nome'] = $usuario['nome'];
            $_SESSION['usuario_cargo'] = $usuario['cargo'];
            $_SESSION['instituicao_id'] = $usuario['instituicao_id'];

            // === 2. TRAVA DE SEGURANÇA: TROCA DE SENHA OBRIGATÓRIA ===
            // Se o gerente resetou a senha, intercepta o login aqui e bloqueia
            if ($usuario['precisa_mudar_senha'] == 1) {
                $_SESSION['forcar_troca'] = true; // Cria uma autorização temporária
                header("Location: trocar_senha_obrigatorio.php");
                exit;
            }
            // ==========================================================

            // Lógica de Redirecionamento (RBAC) - Só passa se não precisar trocar a senha
            switch ($usuario['cargo']) {
                case 'Admin':
                    header("Location: dashboard_super.php");
                    break;
                case 'Gerente':
                    header("Location: dashboard_gerente.php");
                    break;
                default:
                    // Atendentes, Tosadores, Veterinários
                    header("Location: dashboard.php");
                    break;
            }
            exit;

        } else {
            // Senha ou e-mail errados
            header("Location: login.php?erro=1");
            exit;
        }
    } else {
        // Campos vazios
        header("Location: login.php?erro=vazio");
        exit;
    }
} catch (\Throwable $th) {
    header("Location: login.php?erro=sistema");
    exit;
}

ob_end_flush();
?>