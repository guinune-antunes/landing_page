<?php
require_once __DIR__ . '/../sys/conexao.php';
require_once __DIR__ . '/../sys/includes/unidades.php';

echo "═══════════════════════════════════════════════════\n";
echo "TESTE DE VALIDAÇÃO: TEMA ESCURO & ERP MULTIUNIDADES\n";
echo "═══════════════════════════════════════════════════\n";

$passou = true;

// 1. Validação de Integridade do Tema Escuro
$headContent = file_get_contents(__DIR__ . '/../sys/includes/_head.php');
$headerContent = file_get_contents(__DIR__ . '/../sys/includes/_header.php');
$sidebarContent = file_get_contents(__DIR__ . '/../sys/includes/_sidebar.php');

$contaIcones = substr_count($headerContent, 'id="theme-toggle-icon"');
if ($contaIcones === 1 && !str_contains($headerContent, 'theme-icon-moon') && !str_contains($headerContent, 'theme-icon-sun')) {
    echo "[OK] Header: Apenas 1 ícone exclusivo no botão de tema (#theme-toggle-icon).\n";
} else {
    echo "[FALHA] Header contém múltiplos ícones ou classes obsoletas.\n";
    $passou = false;
}

if (!str_contains($sidebarContent, '<link rel="stylesheet" href="style.css">')) {
    echo "[OK] Sidebar: Removido link de stylesheet não versionado (cache fix).\n";
} else {
    echo "[FALHA] Sidebar ainda possui link de stylesheet não versionado.\n";
    $passou = false;
}

// 2. Validação da Matriz de Alçada (ERP Rules)
$stRede = $pdo->query("SELECT id FROM redes LIMIT 1");
$redeId = (int)$stRede->fetchColumn();

$stLoja = $pdo->query("SELECT id FROM instituicoes LIMIT 1");
$lojaId = (int)$stLoja->fetchColumn();

if (!$redeId) {
    $pdo->exec("INSERT INTO redes (nome, limite_alcada_loja, exige_aprovacao_medicamentos) VALUES ('Rede Teste Matriz', 1000.00, 1)");
    $redeId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE instituicoes SET rede_id = ? WHERE id = ?")->execute([$redeId, $lojaId]);
} else {
    $pdo->prepare("UPDATE redes SET limite_alcada_loja = 1000.00, exige_aprovacao_medicamentos = 1 WHERE id = ?")->execute([$redeId]);
    $pdo->prepare("UPDATE instituicoes SET rede_id = ? WHERE id = ?")->execute([$redeId, $lojaId]);
}

$cfg = obterConfigRede($pdo, $lojaId, true); // Força recarregar
if ($cfg && (float)$cfg['limite_alcada_loja'] === 1000.00 && (int)$cfg['exige_aprovacao_medicamentos'] === 1) {
    echo "[OK] Configuração de Alçada da Rede: R$ 1.000,00 e Medicamentos Ativados.\n";
} else {
    echo "[FALHA] Configuração de Alçada divergente.\n";
    $passou = false;
}

// Teste 2.1: Transferência de R$ 500 (Geral) -> NÃO exige superior
$ev1 = avaliarAlcadaTransferencia($pdo, $redeId, 500.00, 'Geral');
if ($ev1['exige_superior'] === false) {
    echo "[OK] Alçada Loja (R$ 500 Geral): Aprovado para gestão local.\n";
} else {
    echo "[FALHA] R$ 500 Geral não deveria exigir superior.\n";
    $passou = false;
}

// Teste 2.2: Transferência de R$ 1.500 (Geral) -> EXIGE superior por valor
$ev2 = avaliarAlcadaTransferencia($pdo, $redeId, 1500.00, 'Geral');
if ($ev2['exige_superior'] === true && str_contains($ev2['motivo'], 'excede')) {
    echo "[OK] Alçada Superior (R$ 1.500 Geral): Exige Gerente Geral por valor.\n";
} else {
    echo "[FALHA] R$ 1.500 Geral deveria exigir superior.\n";
    $passou = false;
}

// Teste 2.3: Transferência de R$ 80 (Vacina) -> EXIGE superior por categoria controlada
$ev3 = avaliarAlcadaTransferencia($pdo, $redeId, 80.00, 'Vacina');
if ($ev3['exige_superior'] === true && str_contains($ev3['motivo'], 'restrita')) {
    echo "[OK] Release Strategy (Vacina R$ 80): Exige Gerente Geral por categoria controlada.\n";
} else {
    echo "[FALHA] Vacina deveria exigir superior.\n";
    $passou = false;
}

// 3. Validação de Delegação e Ausência (Férias)
$stUser = $pdo->query("SELECT id FROM usuarios LIMIT 2");
$users = $stUser->fetchAll(PDO::FETCH_COLUMN);

if (count($users) >= 2) {
    $titularId = (int)$users[0];
    $substitutoId = (int)$users[1];

    // Simula gestor em férias hoje
    $hoje = date('Y-m-d');
    $ontem = date('Y-m-d', strtotime('-1 day'));
    $amanha = date('Y-m-d', strtotime('+7 days'));

    $pdo->prepare("UPDATE instituicoes SET gestor_id = ?, substituto_id = ?, ausente_inicio = ?, ausente_fim = ? WHERE id = ?")
        ->execute([$titularId, $substitutoId, $ontem, $amanha, $lojaId]);

    $subAtivo = usuarioEhGestorUnidade($pdo, $substitutoId, $lojaId);
    if ($subAtivo) {
        echo "[OK] Delegação Ativa: Substituto temporário reconhecido como gestor durante o período de ausência.\n";
    } else {
        echo "[FALHA] Substituto deveria ter permissão de gestor hoje.\n";
        $passou = false;
    }

    // Simula ausência expirada
    $passadoInicio = date('Y-m-d', strtotime('-10 days'));
    $passadoFim = date('Y-m-d', strtotime('-2 days'));

    $pdo->prepare("UPDATE instituicoes SET ausente_inicio = ?, ausente_fim = ? WHERE id = ?")
        ->execute([$passadoInicio, $passadoFim, $lojaId]);

    $subExpirado = usuarioEhGestorUnidade($pdo, $substitutoId, $lojaId);
    if (!$subExpirado) {
        echo "[OK] Delegação Expirada: Substituto perde automaticamente permissão após o fim das férias.\n";
    } else {
        echo "[FALHA] Substituto não deveria ter permissão após a data final.\n";
        $passou = false;
    }
}

echo "═══════════════════════════════════════════════════\n";
if ($passou) {
    echo "TODOS OS TESTES PASSARAM COM SUCESSO!\n";
} else {
    echo "HOUVE FALHAS NA VALIDAÇÃO.\n";
}
echo "═══════════════════════════════════════════════════\n";
