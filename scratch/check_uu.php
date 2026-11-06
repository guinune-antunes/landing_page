<?php
require_once __DIR__ . '/../sys/conexao.php';
$st = $pdo->prepare("SELECT uu.*, i.nome_fantasia, i.nome_unidade 
                     FROM usuario_unidades uu 
                     JOIN instituicoes i ON i.id = uu.instituicao_id 
                     WHERE uu.usuario_id = 864");
$st->execute();
print_r($st->fetchAll(PDO::FETCH_ASSOC));

