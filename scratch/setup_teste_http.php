<?php
// Prepara/limpa dados de teste. Uso: php setup_teste_http.php up|down
require 'c:/xampp/htdocs/landing_page/sys/conexao.php';
$modo = $argv[1] ?? 'up';
$tag = 'ZZTESTE';

if ($modo === 'down') {
    $ids = $pdo->query("SELECT id FROM instituicoes WHERE nome_fantasia LIKE '$tag%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $in = implode(',', $ids);
        $pdo->exec("DELETE FROM transferencias_estoque WHERE origem_id IN ($in) OR destino_id IN ($in)");
        $pdo->exec("DELETE FROM produtos WHERE instituicao_id IN ($in)");
        $pdo->exec("DELETE FROM usuarios WHERE instituicao_id IN ($in)");
        $pdo->exec("DELETE FROM instituicoes WHERE id IN ($in)");
    }
    $pdo->exec("DELETE FROM redes WHERE nome LIKE '$tag%'");
    echo "limpo\n"; exit;
}

$pdo->exec("INSERT INTO redes (nome) VALUES ('$tag Rede')");
$rede = $pdo->lastInsertId();
$ins = $pdo->prepare("INSERT INTO instituicoes (nome_fantasia, rede_id, nome_unidade, cidade, uf, matriz) VALUES (?,?,?,?,?,?)");
$ins->execute(["$tag Pet", $rede, 'Unidade SP', 'São Paulo', 'SP', 1]); $sp = $pdo->lastInsertId();
$ins->execute(["$tag Pet", $rede, 'Unidade Curitiba', 'Curitiba', 'PR', 0]); $cwb = $pdo->lastInsertId();
$pdo->prepare("INSERT INTO usuarios (nome,email,senha_hash,cargo,instituicao_id,acesso_rede) VALUES (?,?,?,?,?,1)")
    ->execute(['Gerente Teste', 'zzteste@teste.x', password_hash('Teste123', PASSWORD_DEFAULT), 'Gerente', $sp]);
$pr = $pdo->prepare("INSERT INTO produtos (instituicao_id,nome_produto,codigo_barras,preco_venda,quantidade_estoque,unidade) VALUES (?,?,?,?,?,?)");
$pr->execute([$sp, 'Racao Teste', '7890000000001', 99.90, 10, 'un']); $p1 = $pdo->lastInsertId();
$pr->execute([$sp, 'Shampoo Teste', '7890000000002', 20.00, 5, 'un']); $p2 = $pdo->lastInsertId();
// Shampoo já existe no destino (deve somar, não duplicar)
$pr->execute([$cwb, 'Shampoo Teste', '7890000000002', 20.00, 3, 'un']);
echo json_encode(['sp'=>$sp,'cwb'=>$cwb,'racao'=>$p1,'shampoo'=>$p2])."\n";
