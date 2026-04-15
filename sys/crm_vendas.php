<?php
require 'includes/verifica_login.php';
require 'conexao.php';
require 'includes/motor_nutricional.php'; // O cérebro matemático

$cargo = $_SESSION['usuario_cargo'] ?? '';
$id_loja = $_SESSION['instituicao_id'] ?? 0;

$pageTitle = 'CRM - Vendas Ativas';
$paginaAtiva = 'crm_vendas'; 
include 'includes/_head.php';

$hoje = new DateTime();

// 1. Buscamos as vendas de ração pendentes com os dados técnicos para o cálculo
// Note que agora fazemos JOIN com a tabela de produtos para pegar calorias e peso do saco
$sql = "SELECT o.id, o.data_compra, o.status, 
               c.nome_completo, c.telefone, 
               p.nome as pet_nome, p.peso_kg, p.fator_manutencao,
               prod.nome_produto, prod.tamanho_embalagem_kg, prod.energia_metabolizavel_kg
        FROM oportunidades_venda o
        JOIN clientes c ON o.cliente_id = c.id
        JOIN pets p ON o.pet_id = p.id
        JOIN produtos prod ON o.produto_id = prod.id 
        WHERE o.instituicao_id = ? AND o.status = 'Pendente'";

$stmt = $pdo->prepare($sql);
$stmt->execute([$id_loja]);
$vendas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$oportunidades_quentes = [];

// 2. Processamento Dinâmico (Motor Nutricional)
foreach ($vendas as $v) {
    // Roda a equação de Necessidade Energética
    $dias_duracao = calcularDiasRacao(
        $v['peso_kg'], 
        $v['fator_manutencao'], 
        $v['energia_metabolizavel_kg'], 
        $v['tamanho_embalagem_kg']
    );

    if ($dias_duracao) {
        $data_compra = new DateTime($v['data_compra']);
        $data_fim_prevista = clone $data_compra;
        $data_fim_prevista->modify("+$dias_duracao days");

        // Diferença de dias para hoje
        $diff = $hoje->diff($data_fim_prevista);
        $dias_restantes = $diff->invert ? -$diff->days : $diff->days;

        // Regra de Vendas: Mostrar apenas se faltar 7 dias ou se já tiver acabado
        if ($dias_restantes <= 7) {
            $v['dias_restantes'] = $dias_restantes;
            $v['data_fim_formatada'] = $data_fim_prevista->format('d/m/Y');
            $oportunidades_quentes[] = $v;
        }
    }
}

// Ordena: os que acabaram primeiro (negativos) ficam no topo
usort($oportunidades_quentes, function($a, $b) {
    return $a['dias_restantes'] <=> $b['dias_restantes'];
});
?>

<?php include 'includes/_sidebar.php'; ?>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1 class="main-title"><i class="fas fa-headset" style="color: #27ae60;"></i> CRM de Vendas Ativas</h1>
                <p style="color: #777;">Previsão inteligente de consumo baseada na Necessidade Energética (NE) do pet.</p>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 20px;">
                
                <?php foreach ($oportunidades_quentes as $op): 
                    $dias = $op['dias_restantes'];
                    
                    // Inteligência Visual
                    $corStatus = $dias < 0 ? '#e74c3c' : ($dias <= 3 ? '#f39c12' : '#27ae60');
                    $textoStatus = $dias < 0 ? "Acabou há " . abs($dias) . " dias" : ($dias == 0 ? "Acaba HOJE!" : "Acaba em $dias dias");

                    // WhatsApp Dinâmico
                    $telefoneLimpo = preg_replace('/[^0-9]/', '', $op['telefone']);
                    if (substr($telefoneLimpo, 0, 2) != '55') $telefoneLimpo = '55' . $telefoneLimpo;
                    $primeiroNome = explode(' ', trim($op['nome_completo']))[0];
                    
                    $msgZap = "Olá $primeiroNome! 🐾 Aqui é do Pet Shop. Notamos que a ração do(a) {$op['pet_nome']} ({$op['nome_produto']}) está prevista para acabar por volta de {$op['data_fim_formatada']}. Gostaria que reservássemos um novo pacote ou prefere que façamos a entrega?";
                    $linkZap = "https://wa.me/$telefoneLimpo?text=" . urlencode($msgZap);
                ?>
                    <div class="widget-card" style="border-left: 4px solid <?php echo $corStatus; ?>; position: relative;">
                        
                        <div style="position: absolute; top: 15px; right: 15px; font-size: 0.75rem; font-weight: bold; background: <?php echo $corStatus; ?>; color: white; padding: 4px 10px; border-radius: 20px;">
                            <?php echo $textoStatus; ?>
                        </div>

                        <h3 style="font-size: 1.1rem; margin-bottom: 5px; color: #333;">
                            <i class="fas fa-user-circle" style="color: #95a5a6;"></i> <?php echo htmlspecialchars($op['nome_completo']); ?>
                        </h3>
                        
                        <div style="margin-bottom: 15px; font-size: 0.9rem; color: #555;">
                            <p style="margin: 3px 0;"><strong>Pet:</strong> <?php echo htmlspecialchars($op['pet_nome']); ?> (<?php echo $op['peso_kg']; ?>kg)</p>
                            <p style="margin: 3px 0;"><strong>Produto:</strong> <?php echo htmlspecialchars($op['nome_produto']); ?></p>
                            <p style="margin: 3px 0; color: #777;"><small><i class="fas fa-history"></i> Compra: <?php echo date('d/m/Y', strtotime($op['data_compra'])); ?></small></p>
                        </div>

                        <hr style="border: 0; border-top: 1px dashed #eee; margin: 15px 0;">

                        <div style="display: flex; gap: 10px;">
                            <a href="<?php echo $linkZap; ?>" target="_blank" class="btn" style="flex: 1.5; text-align: center; background: #25D366; color: white; text-decoration: none; padding: 10px; border-radius: 6px; font-weight: 600; font-size: 0.85rem;">
                                <i class="fab fa-whatsapp"></i> Oferecer Reposição
                            </a>
                            
                            <button class="btn btn-baixa" data-id="<?php echo $op['id']; ?>" style="flex: 1; padding: 10px; font-size: 0.85rem; background: #f8f9fa; border: 1px solid #ddd; border-radius: 6px; cursor: pointer; transition: 0.2s;">
                                <i class="fas fa-check"></i> Dar Baixa
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if(empty($oportunidades_quentes)): ?>
                    <div style="grid-column: 1 / -1; text-align: center; padding: 60px; background: white; border-radius: 12px; border: 2px dashed #eee;">
                        <i class="fas fa-bolt" style="font-size: 3rem; color: #ddd; margin-bottom: 15px;"></i>
                        <h2 style="color: #555;">Tudo em dia!</h2>
                        <p style="color: #999;">O motor nutricional não detectou pets ficando sem ração nos próximos 7 dias.</p>
                    </div>
                <?php endif; ?>

            </div>
        </main>
    </div>
</div>

<script>
document.querySelectorAll('.btn-baixa').forEach(btn => {
    btn.addEventListener('click', function() {
        const idOp = this.getAttribute('data-id');
        const acao = confirm("Deseja marcar esta oportunidade como resolvida?");
        
        if (!acao) return;

        this.innerText = "Processando...";
        this.disabled = true;
        
        fetch('api_atualizar_oportunidade.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ id: idOp, status: 'Venda Fechada' })
        })
        .then(res => res.json())
        .then(res => {
            if(res.success) {
                this.closest('.widget-card').style.transform = 'scale(0)';
                setTimeout(() => { this.closest('.widget-card').style.display = 'none'; }, 300);
            } else {
                alert("Erro ao salvar.");
                this.innerText = "Dar Baixa";
                this.disabled = false;
            }
        });
    });
});
</script>

<?php include 'includes/_footer.php'; ?>