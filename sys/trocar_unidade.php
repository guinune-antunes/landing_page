<?php
// ══════════════════════════════════════════════════════════════════
// trocar_unidade.php — Troca a unidade ativa do usuário (POST)
// ══════════════════════════════════════════════════════════════════
require_once 'includes/verifica_login.php';
require_once 'conexao.php';
require_once 'includes/unidades.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$destino = (int)($_POST['unidade_id'] ?? 0);

// Revalida contra o banco (unidade pode ter sido bloqueada/desvinculada desde o login)
$permitidas = carregarUnidadesUsuario($pdo, (int)$_SESSION['usuario_id']);

if (!isset($permitidas[$destino])) {
    http_response_code(403);
    exit('Você não tem acesso a esta unidade.');
}

definirContextoUnidades($pdo, (int)$_SESSION['usuario_id'], $destino);

// Volta para a página de origem (somente nomes de arquivo .php locais) ou ao dashboard do cargo
$voltar = $_POST['voltar'] ?? '';
if (!preg_match('/^[a-z0-9_]+\.php$/i', $voltar) || !is_file(__DIR__ . '/' . $voltar)) {
    $voltar = ($_SESSION['usuario_cargo'] === 'Gerente') ? 'dashboard_gerente.php' : 'dashboard.php';
}
// Páginas de detalhe/edição não fazem sentido em outra unidade
if (in_array($voltar, ['gerenciar_instituicao.php', 'cartao_cliente.php'], true)) {
    $voltar = ($_SESSION['usuario_cargo'] === 'Gerente') ? 'dashboard_gerente.php' : 'dashboard.php';
}

header('Location: ' . $voltar);
exit;
