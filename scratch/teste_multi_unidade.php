<?php
// Teste descartável (rollback no final): rede com SP + Curitiba
require 'c:/xampp/htdocs/landing_page/sys/conexao.php';
require 'c:/xampp/htdocs/landing_page/sys/includes/unidades.php';
$_SESSION = [];

$pdo->beginTransaction();
$pdo->exec("INSERT INTO redes (nome) VALUES ('Rede Teste')");
$rede = $pdo->lastInsertId();
$ins = $pdo->prepare("INSERT INTO instituicoes (nome_fantasia, rede_id, nome_unidade, cidade, uf, matriz) VALUES (?,?,?,?,?,?)");
$ins->execute(['PetLove', $rede, 'Unidade SP', 'São Paulo', 'SP', 1]); $sp = $pdo->lastInsertId();
$ins->execute(['PetLove', $rede, 'Unidade Curitiba', 'Curitiba', 'PR', 0]); $cwb = $pdo->lastInsertId();
$ins->execute(['PetLove', $rede, 'Unidade Bloqueada', 'Recife', 'PE', 0]); $rec = $pdo->lastInsertId();
$pdo->prepare("UPDATE instituicoes SET ativo=0 WHERE id=?")->execute([$rec]);

$u = $pdo->prepare("INSERT INTO usuarios (nome,email,senha_hash,cargo,instituicao_id,acesso_rede) VALUES (?,?,?,?,?,?)");
$u->execute(['Geral','geral@teste.x','x','Gerente',$sp,1]);     $geral = $pdo->lastInsertId();
$u->execute(['Local','local@teste.x','x','Gerente',$cwb,0]);    $local = $pdo->lastInsertId();
$u->execute(['Vinc','vinc@teste.x','x','Gerente',$sp,0]);       $vinc  = $pdo->lastInsertId();
$pdo->prepare("INSERT INTO usuario_unidades (usuario_id,instituicao_id) VALUES (?,?)")->execute([$vinc,$cwb]);

function show($t,$a){ echo $t.': '.implode(' | ', array_map(fn($x)=>$x['rotulo'],$a))."\n"; }
show('Gerente Geral (espera SP+CWB, sem Recife bloqueada)', carregarUnidadesUsuario($pdo,$geral));
show('Gerente local (espera so CWB)', carregarUnidadesUsuario($pdo,$local));
show('Gerente vinculado (espera SP+CWB)', carregarUnidadesUsuario($pdo,$vinc));

definirContextoUnidades($pdo,(int)$geral,(int)$cwb);
echo "Ativa=$cwb sessao=".$_SESSION['instituicao_id']." rede=".$_SESSION['rede_id']." multi=".(temMultiplasUnidades()?'sim':'nao')."\n";
// Tentativa de forçar unidade fora das permitidas
definirContextoUnidades($pdo,(int)$local,(int)$sp);
echo "Local tenta SP -> cai para ".$_SESSION['instituicao_id']." (espera $cwb)\n";

$pdo->rollBack();
echo "rollback ok\n";
