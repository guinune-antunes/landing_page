<?php
/**
 * Motor de Previsão Nutricional
 * Recebe os parâmetros do Pet e da Ração e retorna os dias de duração.
 */
function calcularDiasRacao($peso_kg, $fator_manutencao, $energia_metabolizavel_kg, $tamanho_embalagem_kg) {
    // Validação de segurança para evitar cálculo com campos vazios ou divisão por zero
    if (empty($peso_kg) || empty($fator_manutencao) || empty($energia_metabolizavel_kg) || empty($tamanho_embalagem_kg)) {
        return null; 
    }

    // 1. Necessidade de Energia em Repouso (NER) = 70 * P^0.75
    $ner = 70 * pow($peso_kg, 0.75);

    // 2. Necessidade Energética Diária (NED) = NER * Fator
    $ned = $ner * $fator_manutencao;

    // 3. Consumo Diário em KG = NED / Energia da Ração
    $consumo_diario_kg = $ned / $energia_metabolizavel_kg;

    // 4. Dias de Duração = Tamanho do Saco / Consumo Diário
    $dias = $tamanho_embalagem_kg / $consumo_diario_kg;

    // Retorna o valor arredondado para baixo (ex: 45.8 dias = 45 dias garantidos)
    return floor($dias);
}
?>