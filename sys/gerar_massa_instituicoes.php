<?php
// ══════════════════════════════════════════════════════════════
// GERADOR DE MASSA DE TESTE: INSTITUIÇÕES (PET SHOPS)
// Acesse no navegador logado como Admin:
//   gerar_massa_instituicoes.php          -> gera 240 instituições
//   gerar_massa_instituicoes.php?qtd=100  -> quantidade customizada
// Usuários gerados usam o domínio @massa.petcrm.test e a senha 123456
// ══════════════════════════════════════════════════════════════
session_start();
require 'conexao.php';
set_time_limit(300);

if (!isset($_SESSION['usuario_cargo']) || $_SESSION['usuario_cargo'] !== 'Admin') {
    die("<p style='font-family:sans-serif'>⛔ Apenas o Admin pode rodar este script. <a href='login.php'>Fazer login</a></p>");
}

$qtd = max(1, min(1000, (int)($_GET['qtd'] ?? 240)));
const DOMINIO = 'massa.petcrm.test';
const SENHA_PADRAO = '123456';

// ── 1. Garante as colunas que as telas já esperam (ativo / plano) ──
function colunaExiste(PDO $pdo, string $tabela, string $coluna): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$tabela, $coluna]);
    return (bool)$st->fetchColumn();
}
$colunasCriadas = [];
if (!colunaExiste($pdo, 'instituicoes', 'ativo')) {
    $pdo->exec("ALTER TABLE instituicoes ADD COLUMN ativo TINYINT(1) NOT NULL DEFAULT 1 AFTER modulos_contratados");
    $colunasCriadas[] = 'ativo';
}
if (!colunaExiste($pdo, 'instituicoes', 'plano')) {
    $pdo->exec("ALTER TABLE instituicoes ADD COLUMN plano VARCHAR(30) NULL DEFAULT NULL AFTER cnpj");
    $colunasCriadas[] = 'plano';
}

// ── 2. Dicionários ──
$prefixos = ['Pet Shop', 'Clínica Veterinária', 'Banho & Tosa', 'Pet Center', 'Mundo Pet', 'Casa de Ração', 'Espaço Pet', 'Hospital Veterinário', 'Pet Care', 'Agropet'];
$nomesMeio = ['Amigo Fiel', 'Patinhas', 'Focinho Feliz', 'Bicho Mimado', 'Quatro Patas', 'Au Au Miau', 'Rabo Abanando', 'Pelúcia', 'Bom Pastor', 'Vida Animal', 'Pata Branca', 'Toca do Bicho', 'Fofura', 'Cão & Gato', 'Pet Lovers', 'Arca de Noé'];
$locais = ['Centro', 'Batel', 'Água Verde', 'Portão', 'Santa Felicidade', 'Boa Vista', 'Cabral', 'Rebouças', 'Ecoville', 'São José dos Pinhais', 'Colombo', 'Pinhais', 'Araucária', 'Londrina', 'Maringá', 'Joinville', 'Florianópolis', 'Ponta Grossa'];
$nomes = ['Ana', 'Bruno', 'Carlos', 'Daniela', 'Eduardo', 'Fernanda', 'Gabriel', 'Helena', 'Igor', 'Juliana', 'Lucas', 'Mariana', 'Nicolas', 'Olivia', 'Pedro', 'Rafael', 'Sofia', 'Tiago', 'Vanessa', 'Wagner'];
$sobrenomes = ['Silva', 'Santos', 'Oliveira', 'Souza', 'Rodrigues', 'Ferreira', 'Alves', 'Pereira', 'Lima', 'Gomes', 'Costa', 'Ribeiro', 'Martins', 'Carvalho', 'Almeida'];

$todosModulos = ['agenda', 'clientes', 'financeiro', 'estoque', 'crm_vendas', 'relatorios', 'equipe', 'clinica'];
$planos = [
    'basico'        => ['agenda', 'clientes'],
    'profissional'  => ['agenda', 'clientes', 'financeiro', 'estoque', 'crm_vendas'],
    'premium'       => $todosModulos,
    'personalizado' => null, // sorteado
];

// Situações (peso = chance relativa)
$situacoes = [
    'ativo_completo'        => ['peso' => 40, 'label' => 'Ativo com gerente e equipe'],
    'ativo_novo_mes'        => ['peso' => 10, 'label' => 'Ativo, criado este mês (sem equipe)'],
    'ativo_sem_cnpj'        => ['peso' => 8,  'label' => 'Ativo sem CNPJ'],
    'ativo_troca_senha'     => ['peso' => 8,  'label' => 'Ativo, gerente com troca de senha pendente'],
    'ativo_sem_gerente'     => ['peso' => 6,  'label' => 'Ativo, setup incompleto (sem gerente)'],
    'ativo_gerente_inativo' => ['peso' => 5,  'label' => 'Ativo, gerente desativado'],
    'ativo_sem_modulos'     => ['peso' => 3,  'label' => 'Ativo sem módulos contratados'],
    'inativo_bloqueado'     => ['peso' => 12, 'label' => 'Inativo / Bloqueado (equipe desativada)'],
    'inativo_cancelado'     => ['peso' => 8,  'label' => 'Inativo / Cancelado antigo (sem usuários)'],
];

// ── 3. Helpers ──
function sortear(array $arr) { return $arr[array_rand($arr)]; }

function sortearSituacao(array $situacoes): string {
    $total = array_sum(array_column($situacoes, 'peso'));
    $r = mt_rand(1, $total);
    foreach ($situacoes as $chave => $s) {
        $r -= $s['peso'];
        if ($r <= 0) return $chave;
    }
    return array_key_first($situacoes);
}

function gerarCnpj(): string {
    $n = [];
    for ($i = 0; $i < 8; $i++) $n[] = mt_rand(0, 9);
    $n = array_merge($n, [0, 0, 0, 1]);
    foreach ([[5,4,3,2,9,8,7,6,5,4,3,2], [6,5,4,3,2,9,8,7,6,5,4,3,2]] as $pesos) {
        $soma = 0;
        foreach ($pesos as $i => $p) $soma += $n[$i] * $p;
        $resto = $soma % 11;
        $n[] = $resto < 2 ? 0 : 11 - $resto;
    }
    $s = implode('', $n);
    return sprintf('%s.%s.%s/%s-%s', substr($s,0,2), substr($s,2,3), substr($s,5,3), substr($s,8,4), substr($s,12,2));
}

function slug(string $txt): string {
    $txt = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $txt);
    return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '.', trim($txt)));
}

function dataAleatoria(string $situacao): string {
    return match ($situacao) {
        'ativo_novo_mes'    => date('Y-m-d H:i:s', mt_rand(strtotime(date('Y-m-01')), time())),
        'inativo_cancelado' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(400, 900) . ' days -' . mt_rand(0, 86399) . ' seconds')),
        default             => date('Y-m-d H:i:s', strtotime('-' . mt_rand(1, 540) . ' days -' . mt_rand(0, 86399) . ' seconds')),
    };
}

// ── 4. Geração ──
$senhaHash = password_hash(SENHA_PADRAO, PASSWORD_DEFAULT); // hash único (rápido)

$stInst = $pdo->prepare("INSERT INTO instituicoes (nome_fantasia, cnpj, plano, modulos_contratados, ativo, data_criacao) VALUES (?, ?, ?, ?, ?, ?)");
$stUser = $pdo->prepare("INSERT INTO usuarios (instituicao_id, nome, email, senha_hash, cargo, permissoes, precisa_mudar_senha, ativo, data_criacao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

$resumoSituacao = array_fill_keys(array_keys($situacoes), 0);
$resumoPlano = array_fill_keys(array_keys($planos), 0);
$totalUsuarios = 0;
$exemplosLogin = [];

try {
    $pdo->beginTransaction();

    for ($i = 0; $i < $qtd; $i++) {
        $sit = sortearSituacao($situacoes);
        $plano = sortear(array_keys($planos));

        $mods = $planos[$plano];
        if ($mods === null) {
            $mods = (array)array_rand(array_flip($todosModulos), mt_rand(2, 6));
        }
        if ($sit === 'ativo_sem_modulos') $mods = [];
        $modulosJson = json_encode(array_fill_keys($mods, true) ?: new stdClass());

        $ativoInst = str_starts_with($sit, 'inativo') ? 0 : 1;
        $nomeFantasia = sortear($prefixos) . ' ' . sortear($nomesMeio) . ' - ' . sortear($locais);
        $cnpj = ($sit === 'ativo_sem_cnpj' || mt_rand(1, 100) <= 5) ? null : gerarCnpj();
        $dataCriacao = dataAleatoria($sit);

        $stInst->execute([$nomeFantasia, $cnpj, $plano, $modulosJson, $ativoInst, $dataCriacao]);
        $instId = (int)$pdo->lastInsertId();

        $resumoSituacao[$sit]++;
        $resumoPlano[$plano]++;

        if (in_array($sit, ['ativo_sem_gerente', 'inativo_cancelado'], true)) continue;

        // Gerente
        $nomeGer = sortear($nomes) . ' ' . sortear($sobrenomes);
        $emailGer = 'gerente.' . $instId . '@' . DOMINIO;
        $gerAtivo = ($sit === 'ativo_gerente_inativo' || $sit === 'inativo_bloqueado') ? 0 : 1;
        $trocaSenha = $sit === 'ativo_troca_senha' ? 1 : 0;
        $stUser->execute([$instId, $nomeGer, $emailGer, $senhaHash, 'Gerente', null, $trocaSenha, $gerAtivo, $dataCriacao]);
        $totalUsuarios++;

        if (count($exemplosLogin) < 6 && !isset($exemplosLogin[$sit])) {
            $exemplosLogin[$sit] = $emailGer;
        }

        if ($sit === 'ativo_novo_mes') continue;

        // Equipe (1 a 6 funcionários)
        $qtdEquipe = mt_rand(1, 6);
        for ($e = 1; $e <= $qtdEquipe; $e++) {
            $cargo = sortear(['Atendente', 'Atendente', 'Tosador', 'Veterinário']);
            $nomeFunc = sortear($nomes) . ' ' . sortear($sobrenomes);
            $emailFunc = slug($nomeFunc) . '.' . $instId . '.' . $e . '@' . DOMINIO;
            $perms = array_fill_keys(array_intersect($mods, (array)array_rand(array_flip($todosModulos), mt_rand(1, 4))), true);
            $funcAtivo = $sit === 'inativo_bloqueado' ? 0 : (mt_rand(1, 100) <= 90 ? 1 : 0);
            $stUser->execute([$instId, $nomeFunc, $emailFunc, $senhaHash, $cargo, json_encode($perms ?: new stdClass()), 0, $funcAtivo, $dataCriacao]);
            $totalUsuarios++;
        }
    }

    $pdo->commit();
} catch (Throwable $t) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    die("<p style='font-family:sans-serif;color:#c0392b'>Erro ao gerar massa: " . htmlspecialchars($t->getMessage()) . "</p>");
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Massa de Instituições Gerada - PetCRM</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap');
        body { font-family: 'Poppins', sans-serif; background: #2c3e50; display: flex; justify-content: center; padding: 40px 15px; margin: 0; }
        .card { background: #fff; border-radius: 10px; padding: 30px 35px; max-width: 640px; width: 100%; box-shadow: 0 10px 25px rgba(0,0,0,.2); }
        h2 { color: #1abc9c; margin-top: 0; }
        h3 { margin-bottom: 8px; color: #333; }
        table { width: 100%; border-collapse: collapse; font-size: .9rem; margin-bottom: 15px; }
        td { padding: 6px 4px; border-bottom: 1px solid #eee; }
        td:last-child { text-align: right; font-weight: 600; }
        .note { background: #fef9e7; border: 1px solid #f7dc6f; padding: 10px 12px; border-radius: 6px; font-size: .85rem; color: #7d6608; }
        a.btn { display: inline-block; margin-top: 15px; padding: 10px 22px; background: #1abc9c; color: #fff; border-radius: 5px; text-decoration: none; }
        code { background: #f4f4f4; padding: 1px 5px; border-radius: 4px; }
    </style>
</head>
<body>
<div class="card">
    <h2>✅ <?= $qtd ?> instituições geradas</h2>
    <p><?= $totalUsuarios ?> usuários criados (senha de todos: <code><?= SENHA_PADRAO ?></code>).</p>

    <?php if ($colunasCriadas): ?>
        <p class="note">Colunas adicionadas em <code>instituicoes</code>: <strong><?= implode(', ', $colunasCriadas) ?></strong></p>
    <?php endif; ?>

    <h3>Por situação</h3>
    <table>
        <?php foreach ($resumoSituacao as $k => $v): ?>
            <tr><td><?= $situacoes[$k]['label'] ?></td><td><?= $v ?></td></tr>
        <?php endforeach; ?>
    </table>

    <h3>Por plano</h3>
    <table>
        <?php foreach ($resumoPlano as $k => $v): ?>
            <tr><td><?= ucfirst($k) ?></td><td><?= $v ?></td></tr>
        <?php endforeach; ?>
    </table>

    <h3>Logins de exemplo</h3>
    <table>
        <?php foreach ($exemplosLogin as $sit => $email): ?>
            <tr><td><?= $situacoes[$sit]['label'] ?></td><td><code><?= $email ?></code></td></tr>
        <?php endforeach; ?>
    </table>

    <a class="btn" href="super_admin_clientes.php">Ver Instituições</a>
</div>
</body>
</html>
