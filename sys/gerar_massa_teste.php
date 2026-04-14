<?php
// Configurações para forçar o download de um arquivo CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=massa_1000_clientes_petcrm.csv');

// Abre a saída do PHP como se fosse um arquivo
$output = fopen('php://output', 'w');

// BOM (Byte Order Mark) para o Excel ler os acentos (UTF-8) corretamente
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Escreve o Cabeçalho (Padrão exato que seu importador espera)
fputcsv($output, ['Tutor', 'CPF', 'Telefone', 'Email', 'CEP', 'Logradouro', 'Numero', 'Bairro', 'Cidade', 'Estado', 'Pet', 'Especie', 'Raca'], ';');

// Dicionários para gerar dados aleatórios
$nomes = ['Ana', 'Bruno', 'Carlos', 'Daniela', 'Eduardo', 'Fernanda', 'Gabriel', 'Helena', 'Igor', 'Juliana', 'Lucas', 'Mariana', 'Nicolas', 'Olivia', 'Pedro', 'Rafael', 'Sofia', 'Tiago', 'Vanessa', 'Wagner'];
$sobrenomes = ['Silva', 'Santos', 'Oliveira', 'Souza', 'Rodrigues', 'Ferreira', 'Alves', 'Pereira', 'Lima', 'Gomes', 'Costa', 'Ribeiro', 'Martins', 'Carvalho', 'Almeida'];
$ruas = ['Rua XV de Novembro', 'Av. Brasil', 'Rua das Flores', 'Av. Sete de Setembro', 'Rua Marechal Deodoro', 'Av. Presidente Kennedy', 'Rua da Paz', 'Av. Anita Garibaldi'];
$bairros = ['Centro', 'Batel', 'Água Verde', 'Portão', 'Santa Felicidade', 'Boa Vista', 'Cabral', 'Rebouças', 'Ecoville'];
$pets_caes = ['Rex', 'Thor', 'Max', 'Bolinha', 'Mel', 'Luna', 'Bidu', 'Bob', 'Amora', 'Nina'];
$racas_caes = ['Poodle', 'Bulldog', 'Labrador', 'Golden Retriever', 'Vira-lata (SRD)', 'Shih Tzu', 'Pug', 'Beagle'];
$pets_gatos = ['Mimi', 'Garfield', 'Frajola', 'Simba', 'Nala', 'Mia', 'Tom', 'Salem'];
$racas_gatos = ['Persa', 'Siamês', 'Angorá', 'Maine Coon', 'Vira-lata (SRD)', 'Sphynx'];

// Gera 1000 clientes únicos
for ($i = 1; $i <= 1000; $i++) {
    
    // Gera dados do cliente
    $nome_tutor = $nomes[array_rand($nomes)] . ' ' . $sobrenomes[array_rand($sobrenomes)] . ' ' . $sobrenomes[array_rand($sobrenomes)];
    $cpf = sprintf('%03d.%03d.%03d-%02d', rand(100,999), rand(100,999), rand(100,999), rand(10,99));
    $telefone = sprintf('(41) 9%04d-%04d', rand(1000,9999), rand(1000,9999));
    
    // Email sem acentos e espaços
    $email_base = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $nome_tutor));
    $email = $email_base . rand(10, 999) . '@email.com';
    
    $cep = sprintf('%05d-%03d', rand(80000, 83000), rand(100, 999));
    $logradouro = $ruas[array_rand($ruas)];
    $numero = rand(10, 2000);
    $bairro = $bairros[array_rand($bairros)];
    $cidade = 'Curitiba';
    $estado = 'PR';

    // Sorteia quantos pets esse cliente vai ter (1, 2 ou 3)
    $qtd_pets = rand(1, 100) > 85 ? rand(2, 3) : 1; // 85% tem 1 pet, 15% tem 2 ou 3

    for ($p = 0; $p < $qtd_pets; $p++) {
        $especie = rand(0, 1) == 0 ? 'Cão' : 'Gato';
        
        if ($especie == 'Cão') {
            $pet_nome = $pets_caes[array_rand($pets_caes)] . ($p > 0 ? " Jr" : "");
            $raca = $racas_caes[array_rand($racas_caes)];
        } else {
            $pet_nome = $pets_gatos[array_rand($pets_gatos)] . ($p > 0 ? " II" : "");
            $raca = $racas_gatos[array_rand($racas_gatos)];
        }

        // Monta a linha e escreve no arquivo CSV
        $linha = [
            $nome_tutor, $cpf, $telefone, $email, $cep, $logradouro, $numero, $bairro, $cidade, $estado,
            $pet_nome, $especie, $raca
        ];
        
        fputcsv($output, $linha, ';');
    }
}

fclose($output);
exit;
?>