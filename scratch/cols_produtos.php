<?php
require 'sys/conexao.php';
$cols = $pdo->query("SHOW COLUMNS FROM produtos")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "{$c['Field']} ({$c['Type']})\n";
}

