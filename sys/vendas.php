<?php
$pageTitle = 'PDV - Vendas';
$paginaAtiva = 'vendas';
include 'includes/_head.php';
require 'conexao.php';
require 'includes/verifica_login.php';

// Busca PRODUTOS
$stmt_produtos = $pdo->query("SELECT * FROM produtos WHERE quantidade > 0 AND instituicao_id = {$_SESSION['instituicao_id']} ORDER BY nome LIMIT 20");
$produtosRapidos = $stmt_produtos->fetchAll();

// Busca CLIENTES
$stmt_cli = $pdo->prepare("SELECT id, nome_completo FROM clientes WHERE instituicao_id = ? ORDER BY nome_completo");
$stmt_cli->execute([$_SESSION['instituicao_id']]);
$clientes = $stmt_cli->fetchAll();
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
                            <div class="service-list-item" onclick="adicionarAoCarrinho(<?php echo $prod['id']; ?>, '<?php echo addslashes($prod['nome']); ?>', <?php echo $prod['preco']; ?>)">
                                <div class="service-info">
                                    <div class="service-icon" style="background:#e3f2fd; color:#3498db;"><i class="fas fa-box"></i></div>
                                    <div class="service-details">
                                        <h4><?php echo htmlspecialchars($prod['nome']); ?></h4>
                                        <small>Estoque: <?php echo $prod['quantidade']; ?> <?php echo $prod['unidade']; ?></small>
                                    </div>
                                </div>
                                <div class="service-price">R$ <?php echo number_format($prod['preco'], 2, ',', '.'); ?></div>
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
                                <option value="">Cliente Balcão (Anônimo)</option>
                                <?php foreach ($clientes as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nome_completo']); ?></option>
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

<?php include 'includes/_footer.php'; ?>