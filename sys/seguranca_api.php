<?php
// ══════════════════════════════════════════════════════════════════
// seguranca_api.php — Middleware de segurança para endpoints de API
// ══════════════════════════════════════════════════════════════════
//
// Como usar:
//   require 'seguranca_api.php';
//
// Após o require, as seguintes variáveis estarão disponíveis:
//   $id_loja     → ID da instituição do usuário logado
//   $usuario_id  → ID do usuário logado
//   $usuario_cargo → Cargo do usuário logado
//
// Funções auxiliares:
//   exigirCargo(['Gerente', 'Admin'])  → Bloqueia se o cargo não estiver na lista
//   exigirMetodo('POST')              → Bloqueia se o método HTTP for diferente
// ══════════════════════════════════════════════════════════════════

// Inicia a sessão se ainda não estiver ativa
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
require_once __DIR__ . '/conexao.php';

// ── 1. Autenticação ──────────────────────────────────────────────
// Verifica se o usuário fez login (se existe um usuario_id na sessão)
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sessão expirada. Faça login novamente.']);
    exit;
}

// ── 2. Variáveis Globais de Segurança ────────────────────────────
$id_loja       = $_SESSION['instituicao_id'] ?? null;
$usuario_id    = $_SESSION['usuario_id'];
$usuario_cargo = $_SESSION['usuario_cargo'] ?? '';

// ── 3. Helper: Verificação de Cargo (RBAC) ──────────────────────
// Uso: exigirCargo(['Gerente', 'Admin']);
if (!function_exists('exigirCargo')) {
    function exigirCargo(array $cargosPermitidos) {
        global $usuario_cargo;
        if (!in_array($usuario_cargo, $cargosPermitidos)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Você não tem permissão para esta ação.']);
            exit;
        }
    }
}

// ── 4. Helper: Forçar Método HTTP ────────────────────────────────
// Uso: exigirMetodo('POST');
if (!function_exists('exigirMetodo')) {
    function exigirMetodo(string $metodo = 'POST') {
        if ($_SERVER['REQUEST_METHOD'] !== strtoupper($metodo)) {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
            exit;
        }
    }
}
