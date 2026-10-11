<?php
$pageTitle = 'PDV - Vendas';
$paginaAtiva = 'vendas';
include 'includes/_head.php';
require 'conexao.php';
require 'includes/verifica_login.php';

// Confirma a loja
$id_loja = $_SESSION['instituicao_id'] ?? null;
if (!$id_loja) {
    die("Sessão expirada. Faça login novamente.");
}

// Busca os clientes da loja para o Select do PDV
$sql_clientes = "SELECT id, nome_completo, telefone FROM clientes WHERE instituicao_id = ? ORDER BY nome_completo ASC";
$stmt_clientes = $pdo->prepare($sql_clientes);
$stmt_clientes->execute([$id_loja]);
$clientes = $stmt_clientes->fetchAll(PDO::FETCH_ASSOC);

// =========================================================================
// CORREÇÃO: A linha abaixo havia sido apagada acidentalmente!
$sql_produtos = "SELECT * FROM produtos WHERE instituicao_id = ? AND quantidade_estoque > 0 ORDER BY nome_produto ASC";
$stmt_produtos = $pdo->prepare($sql_produtos);
$stmt_produtos->execute([$id_loja]);
$produtos = $stmt_produtos->fetchAll(PDO::FETCH_ASSOC);

// Busca os Produtos Rápidos (Agora puxando os 12 mais novos, incluindo as rações!)
$sql_rapidos = "SELECT * FROM produtos WHERE instituicao_id = ? AND quantidade_estoque > 0 ORDER BY id DESC LIMIT 12";
$stmt_rapidos = $pdo->prepare($sql_rapidos);
$stmt_rapidos->execute([$id_loja]);
$produtosRapidos = $stmt_rapidos->fetchAll(PDO::FETCH_ASSOC);
// =========================================================================
?>

<?php include 'includes/_sidebar.php'; ?>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; ?>

        <main class="main-content">
            
            <div class="pdv-container">
                
                <div class="pdv-left-panel">
                    
                    <div class="product-search-area">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label><i class="fas fa-barcode"></i> Buscar Produto (Nome ou Código)</label>
                            <input type="text" id="pdv-search" class="form-control" placeholder="Digite ou bipe o código..." autofocus autocomplete="off">
                        </div>
                    </div>

                    <h4 style="margin-bottom: 5px; color: #555; padding-left: 5px;">Serviços & Produtos Rápidos</h4>
                    
                    <div class="services-container">
                        
                        <div class="service-list-item" onclick="adicionarServicoAvulso('Banho - Pequeno', 50.00)">
                            <div class="service-info">
                                <div class="service-icon"><i class="fas fa-bath"></i></div>
                                <div class="service-details">
                                    <h4>Banho (Pequeno Porte)</h4>
                                    <small>Serviço Avulso</small>
                                </div>
                            </div>
                            <div class="service-price">R$ 50,00</div>
                        </div>

                        <div class="service-list-item" onclick="adicionarServicoAvulso('Banho - Grande', 80.00)">
                            <div class="service-info">
                                <div class="service-icon"><i class="fas fa-shower"></i></div>
                                <div class="service-details">
                                    <h4>Banho (Grande Porte)</h4>
                                    <small>Serviço Avulso</small>
                                </div>
                            </div>
                            <div class="service-price">R$ 80,00</div>
                        </div>

                        <div class="service-list-item" onclick="adicionarServicoAvulso('Tosa Higiênica', 40.00)">
                            <div class="service-info">
                                <div class="service-icon"><i class="fas fa-cut"></i></div>
                                <div class="service-details">
                                    <h4>Tosa Higiênica</h4>
                                    <small>Serviço Avulso</small>
                                </div>
                            </div>
                            <div class="service-price">R$ 40,00</div>
                        </div>

                        <div class="service-list-item" onclick="adicionarServicoAvulso('Consulta Veterinária', 120.00)">
                            <div class="service-info">
                                <div class="service-icon" style="background:#ffebee; color:#e74c3c;"><i class="fas fa-user-md"></i></div>
                                <div class="service-details">
                                    <h4>Consulta Veterinária</h4>
                                    <small>Clínica</small>
                                </div>
                            </div>
                            <div class="service-price">R$ 120,00</div>
                        </div>

                        <hr style="border: 0; border-top: 1px dashed #ddd; margin: 10px 0;">

                        <?php foreach ($produtosRapidos as $prod): ?>
                            <div class="service-list-item" onclick="adicionarAoCarrinho(<?php echo $prod['id']; ?>, '<?php echo addslashes($prod['nome_produto']); ?>', <?php echo $prod['preco_venda']; ?>)">
                                <div class="service-info">
                                    <div class="service-icon" style="background:#e3f2fd; color:#3498db;"><i class="fas fa-box"></i></div>
                                    <div class="service-details">
                                        <h4><?php echo htmlspecialchars($prod['nome_produto']); ?></h4>
                                        <small>Estoque: <?php echo $prod['quantidade_estoque']; ?> un</small>
                                    </div>
                                </div>
                                <div class="service-price">R$ <?php echo number_format($prod['preco_venda'], 2, ',', '.'); ?></div>
                            </div>
                        <?php endforeach; ?>

                    </div>
                </div>

                <div class="pdv-right-panel">
                    <div class="ticket-header">
                        <div class="ticket-header-top">
                            <span><i class="fas fa-shopping-cart"></i> PDV</span>
                            <button class="btn-close-register" id="btn-fechar-caixa">
                                <i class="fas fa-file-invoice-dollar"></i> Fechar Caixa
                            </button>
                        </div>
                        <div class="client-selector">
                            <select id="pdv-cliente">
                                <option value="" data-telefone="" data-nome="">Cliente Balcão (Anônimo)</option>
                                <?php foreach ($clientes as $c): ?>
                                    <option value="<?php echo $c['id']; ?>" data-telefone="<?php echo htmlspecialchars($c['telefone'] ?? ''); ?>" data-nome="<?php echo htmlspecialchars($c['nome_completo']); ?>">
                                        <?php echo htmlspecialchars($c['nome_completo']); ?> <?php echo !empty($c['telefone']) ? ' (' . htmlspecialchars($c['telefone']) . ')' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="ticket-body">
                        <table class="ticket-table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th width="40">Qtd</th>
                                    <th width="70">Total</th>
                                    <th width="20"></th>
                                </tr>
                            </thead>
                            <tbody id="carrinho-lista"></tbody>
                        </table>
                    </div>

                    <div class="ticket-footer">
                        <div class="total-display">
                            <span class="total-label">TOTAL</span>
                            <span class="total-value" id="total-venda">R$ 0,00</span>
                        </div>
                        <div class="form-group-row" style="margin-bottom: 10px;">
                            <div class="form-group">
                                <label>Desconto (R$)</label>
                                <input type="number" id="desconto-valor" value="0.00" step="0.01" min="0">
                            </div>
                            <div class="form-group">
                                <label>Pagamento</label>
                                <select id="forma-pagamento">
                                    <option value="Dinheiro">Dinheiro</option>
                                    <option value="Pix">Pix</option>
                                    <option value="Cartão Crédito">Crédito</option>
                                    <option value="Cartão Débito">Débito</option>
                                </select>
                            </div>
                        </div>
                        <div class="payment-actions">
                            <button class="btn btn-secondary" onclick="limparCarrinho()">Cancelar</button>
                            <button class="btn btn-finalize">
                                <i class="fas fa-check"></i> Finalizar
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        </main>
    </div>
</div>

<div class="modal-overlay" id="closure-modal" style="display: none;">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h2>Fechamento de Caixa (Hoje)</h2>
            <button class="close-modal-btn">&times;</button>
        </div>
        
        <div class="modal-body">
            <div id="closure-content">Carregando dados...</div>
        </div>
        
        <div class="modal-footer" style="justify-content: space-between;">
            <button class="btn btn-secondary close-modal-btn">Fechar</button>
            
            <div class="print-actions">
                <button class="btn btn-primary" id="btn-print-thermal" title="Cupom para Cliente">
                    <i class="fas fa-receipt"></i> Ticket (58mm/80mm)
                </button>
                
                <button class="btn btn-secondary" id="btn-print-a4" title="Relatório Gerencial">
                    <i class="fas fa-file-pdf"></i> Relatório A4
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════ -->
<!-- MODAL AUTOMÁTICO DO PDV: ADESÃO AO CARTÃO FIDELIDADE -->
<!-- ════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-fidelidade-pdv" style="display: none; align-items: center; justify-content: center; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.75); z-index: 10000; backdrop-filter: blur(4px);">
    <div class="modal-content" style="background: #ffffff; border-radius: 20px; width: 100%; max-width: 520px; padding: 26px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35); position: relative; animation: slideUpModal 0.25s ease-out;">
        <button type="button" class="close-modal-btn" id="btn-fechar-modal-fid" style="position: absolute; top: 18px; right: 20px; background: none; border: none; font-size: 1.5rem; color: #94a3b8; cursor: pointer;">&times;</button>
        
        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 14px; background: linear-gradient(135deg, #f59e0b, #d97706); display: flex; align-items: center; justify-content: center; color: white; font-size: 1.4rem; box-shadow: 0 6px 14px rgba(217, 119, 6, 0.3);">
                <i class="fas fa-award"></i>
            </div>
            <div>
                <h3 style="margin: 0; font-size: 1.2rem; color: #0f172a; font-weight: 700;">Programa de Fidelidade VIP</h3>
                <small style="color: #64748b;">Itens da compra se qualificam para benefícios!</small>
            </div>
        </div>

        <!-- Banner com a regra/promoção elegível -->
        <div id="pdv-fid-banner" style="background: linear-gradient(135deg, #0d9488, #115e59); color: white; border-radius: 14px; padding: 16px; margin-bottom: 20px; box-shadow: 0 8px 18px rgba(13, 148, 136, 0.25);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                <span style="background: rgba(255,255,255,0.22); font-size: 0.75rem; font-weight: 700; padding: 3px 10px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.5px;">
                    <i class="fas fa-tag"></i> Promoção Ativa
                </span>
                <span id="pdv-fid-badge-meta" style="font-size: 0.8rem; font-weight: 600; opacity: 0.95;">+1 Selo nesta compra</span>
            </div>
            <h4 id="pdv-fid-nome-regra" style="margin: 0 0 4px 0; font-size: 1.05rem; font-weight: 700;">Cartão Fidelidade Pet VIP</h4>
            <p id="pdv-fid-beneficio" style="margin: 0; font-size: 0.88rem; opacity: 0.92; line-height: 1.3;">
                Acumule selos e ganhe 15% de desconto especial na conclusão do cartão!
            </p>
        </div>

        <p style="font-size: 1rem; color: #1e293b; font-weight: 600; margin-bottom: 14px;">
            Deseja pontuar o cliente no Cartão Fidelidade nesta compra?
        </p>

        <!-- Campo Nome do Cliente (se não selecionado) -->
        <div id="pdv-box-nome-cliente" style="margin-bottom: 12px; display: none;">
            <label style="display: block; font-size: 0.85rem; font-weight: 600; color: #475569; margin-bottom: 5px;">Nome do Cliente:</label>
            <input type="text" id="pdv-fid-nome" placeholder="Nome do cliente (ex: João Silva)" class="form-control" style="width: 100%; padding: 10px 14px; border-radius: 10px; border: 1px solid #cbd5e1; font-size: 0.95rem;">
        </div>

        <!-- Campo WhatsApp do Cliente -->
        <div style="margin-bottom: 18px;">
            <label style="display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem; font-weight: 600; color: #475569; margin-bottom: 5px;">
                <span><i class="fab fa-whatsapp" style="color: #22c55e;"></i> WhatsApp do Cliente (para envio do comprovante):</span>
            </label>
            <div style="position: relative;">
                <input type="text" id="pdv-fid-whatsapp" placeholder="(00) 00000-0000" maxlength="15" class="form-control" style="width: 100%; padding: 12px 14px 12px 42px; border-radius: 10px; border: 2px solid #22c55e; font-size: 1.05rem; font-weight: 600; color: #0f172a;">
                <i class="fab fa-whatsapp" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #22c55e; font-size: 1.2rem;"></i>
            </div>
            <small style="color: #64748b; font-size: 0.78rem; display: block; margin-top: 5px;">
                💡 Ao confirmar a venda, o comprovante e o link do cartão virtual serão gerados para envio no WhatsApp.
            </small>
        </div>

        <div style="display: flex; gap: 10px; margin-top: 22px;">
            <button type="button" class="btn" id="btn-pdv-recusar-fid" style="flex: 1; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; border-radius: 10px; padding: 13px; font-weight: 600; font-size: 0.9rem; cursor: pointer;">
                Não Participar
            </button>
            <button type="button" class="btn" id="btn-pdv-confirmar-fid" style="flex: 1.6; background: #22c55e; color: #ffffff; border: none; border-radius: 10px; padding: 13px; font-weight: 700; font-size: 0.95rem; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 14px rgba(34, 197, 94, 0.4); cursor: pointer;">
                <i class="fas fa-check-circle"></i> Confirmar & Finalizar
            </button>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════ -->
<!-- MODAL DE CONCLUSÃO DE VENDA COM ENVIO WHATSAPP & COMPROVANTE -->
<!-- ════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-sucesso-pdv" style="display: none; align-items: center; justify-content: center; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.8); z-index: 10001; backdrop-filter: blur(5px);">
    <div class="modal-content" style="background: #ffffff; border-radius: 22px; width: 100%; max-width: 480px; padding: 28px 24px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4); text-align: center; animation: slideUpModal 0.25s ease-out;">
        <div style="width: 68px; height: 68px; border-radius: 50%; background: #dcfce7; color: #16a34a; font-size: 2.3rem; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px auto;">
            <i class="fas fa-check"></i>
        </div>

        <h2 style="font-size: 1.4rem; color: #0f172a; margin-bottom: 4px; font-weight: 800;">Venda Concluída!</h2>
        <p style="color: #64748b; font-size: 0.92rem; margin-bottom: 18px;">
            Venda #<strong id="sucesso-venda-num">0</strong> finalizada no valor de <strong id="sucesso-venda-total" style="color: #0f172a;">R$ 0,00</strong>.
        </p>

        <!-- Box de Fidelidade (se o cliente participou) -->
        <div id="sucesso-box-fidelidade" style="background: #f8fafc; border: 1.5px dashed #cbd5e1; border-radius: 14px; padding: 14px; margin-bottom: 20px; text-align: left;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                <strong style="color: #0f172a; font-size: 0.9rem; display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-stamp" style="color: #d97706;"></i> Cartão Fidelidade Carimbado!
                </strong>
                <span id="sucesso-badge-selos" style="background: #fef3c7; color: #b45309; font-weight: 700; font-size: 0.75rem; padding: 2px 8px; border-radius: 12px;">+1 Selo</span>
            </div>
            <div style="font-size: 0.85rem; color: #475569;">
                Cliente: <strong id="sucesso-cli-nome" style="color: #0f172a;">Maria Silva</strong><br>
                Progresso: <span id="sucesso-cli-progresso" style="font-weight: 700; color: #0d9488;">3 de 10 selos</span>
            </div>
        </div>

        <!-- Botão Principal: Enviar Comprovante + Cartão no WhatsApp -->
        <div id="sucesso-box-whatsapp" style="margin-bottom: 12px;">
            <a href="#" id="btn-enviar-whatsapp-sucesso" target="_blank" class="btn" style="background: #22c55e; color: white; font-size: 1.05rem; font-weight: 700; padding: 14px; border-radius: 12px; display: flex; align-items: center; justify-content: center; gap: 10px; text-decoration: none; box-shadow: 0 6px 18px rgba(34, 197, 94, 0.4); transition: transform 0.2s;">
                <i class="fab fa-whatsapp" style="font-size: 1.4rem;"></i> Enviar Comprovante & Cartão VIP
            </a>
            <small style="color: #64748b; font-size: 0.78rem; display: block; margin-top: 6px;">
                Abre o WhatsApp com mensagem formatada e link direto do cartão virtual.
            </small>
        </div>

        <!-- Botão para abrir o cartão no navegador -->
        <a href="#" id="btn-ver-cartao-sucesso" target="_blank" style="display: inline-flex; align-items: center; gap: 6px; color: #0d9488; font-weight: 600; font-size: 0.88rem; text-decoration: none; margin-bottom: 18px;">
            <i class="fas fa-external-link-alt"></i> Visualizar Cartão Virtual do Cliente
        </a>

        <div style="border-top: 1px solid #f1f5f9; padding-top: 16px; display: flex; gap: 10px;">
            <button type="button" class="btn btn-secondary" id="btn-print-cupom-sucesso" style="flex: 1; border-radius: 10px; padding: 12px; font-weight: 600; font-size: 0.9rem;">
                <i class="fas fa-print"></i> Imprimir Cupom
            </button>
            <button type="button" class="btn btn-primary" id="btn-novo-atendimento" style="flex: 1.2; border-radius: 10px; padding: 12px; font-weight: 700; font-size: 0.9rem; background: #0f172a; border-color: #0f172a;">
                <i class="fas fa-plus"></i> Nova Venda
            </button>
        </div>
    </div>
</div>

<?php include 'includes/_footer.php'; ?>