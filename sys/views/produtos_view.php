<?php include 'includes/_sidebar.php'; ?>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; ?>

        <main class="main-content">
            <div class="page-header page-header-produtos">
                <div>
                    <h1 class="main-title">Estoque & Produtos</h1>
                    <p style="color: var(--text-muted);">Gerencie os itens disponíveis para venda no PDV e configure o CRM inteligente.</p>
                </div>
                <a href="cadastrar_produto.php" class="btn btn-primary" id="btn-novo-produto" style="text-decoration: none; display: inline-flex; align-items: center; gap: 8px;">
                    <i class="fas fa-plus-circle"></i> Cadastrar Produto
                </a>
            </div>

            <?php if (!empty($_GET['msg'])): ?>
                <div class="alert alert-success" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid #10b981; padding: 12px 18px; border-radius: 10px; margin-bottom: 18px; display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-check-circle" style="font-size: 1.2rem;"></i>
                        <span><?php echo htmlspecialchars($_GET['msg']); ?></span>
                    </div>
                    <button type="button" onclick="this.parentElement.remove()" style="background:none; border:none; color:inherit; font-size:1.2rem; cursor:pointer;">&times;</button>
                </div>
            <?php endif; ?>

            <div class="widget-card">
                
                <div class="quick-filter-bar">
                    <button class="btn-quick-filter" data-filter="todos" style="background: #34495e; color: white; border: none; padding: 8px 15px; border-radius: 20px; cursor: pointer; transition: 0.3s; opacity: 1;">
                        <i class="fas fa-list"></i> Todos
                    </button>
                    <button class="btn-quick-filter" data-filter="vencidos" style="background: #e74c3c; color: white; border: none; padding: 8px 15px; border-radius: 20px; cursor: pointer; opacity: 0.5; transition: 0.3s;">
                        <i class="fas fa-skull-crossbones"></i> Vencidos
                    </button>
                    <button class="btn-quick-filter" data-filter="baixo_estoque" style="background: #f39c12; color: white; border: none; padding: 8px 15px; border-radius: 20px; cursor: pointer; opacity: 0.5; transition: 0.3s;">
                        <i class="fas fa-exclamation-triangle"></i> Estoque Crítico (<= 5)
                    </button>
                    <button class="btn-quick-filter" data-filter="ok" style="background: #27ae60; color: white; border: none; padding: 8px 15px; border-radius: 20px; cursor: pointer; opacity: 0.5; transition: 0.3s;">
                        <i class="fas fa-check-circle"></i> Estoque Saudável
                    </button>
                </div>

                <div class="mobile-scroll-indicator" style="display:none;"><i class="fas fa-arrows-left-right"></i> Deslize a tabela para ver preços e ações</div>
                
                <div class="table-container mobile-table-scroll" style="overflow-x: auto; -webkit-overflow-scrolling: touch; width: 100%; border-radius: 8px;">
                <table class="data-table" style="width: 100%; border-collapse: collapse; min-width: 680px;">
                    <thead>
                        <tr style="text-align: left; border-bottom: 2px solid var(--border-color);">
                            <th style="padding: 12px 10px;">Produto</th>
                            <th style="padding: 12px 10px;">Código</th>
                            <th style="padding: 12px 10px;">Preço (R$)</th>
                            <th style="padding: 12px 10px;">Estoque</th>
                            <th style="padding: 12px 10px;">Validade</th>
                            <th style="padding: 12px 10px; text-align: center;">Ações</th>
                        </tr>
                        <tr style="background: rgba(0,0,0,0.02); border-bottom: 2px solid var(--border-color);">
                            <th style="padding: 6px 10px;"><input type="text" class="filtro-coluna" data-col="0" placeholder="Buscar nome..." style="width: 100%; padding: 6px 10px; border: 1px solid var(--border-color); border-radius: 6px; font-weight: normal; font-size: 0.85rem;"></th>
                            <th style="padding: 6px 10px;"><input type="text" class="filtro-coluna" data-col="1" placeholder="Buscar cód..." style="width: 100%; padding: 6px 10px; border: 1px solid var(--border-color); border-radius: 6px; font-weight: normal; font-size: 0.85rem;"></th>
                            <th style="padding: 6px 10px;"><input type="text" class="filtro-coluna" data-col="2" placeholder="Filtrar..." style="width: 100%; padding: 6px 10px; border: 1px solid var(--border-color); border-radius: 6px; font-weight: normal; font-size: 0.85rem;"></th>
                            <th style="padding: 6px 10px;"><input type="text" class="filtro-coluna" data-col="3" placeholder="Filtrar..." style="width: 100%; padding: 6px 10px; border: 1px solid var(--border-color); border-radius: 6px; font-weight: normal; font-size: 0.85rem;"></th>
                            <th style="padding: 6px 10px;"><input type="text" class="filtro-coluna" data-col="4" placeholder="Filtrar..." style="width: 100%; padding: 6px 10px; border: 1px solid var(--border-color); border-radius: 6px; font-weight: normal; font-size: 0.85rem;"></th>
                            <th style="padding: 6px 10px;"></th> 
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($produtos as $prod): 
                            $cor_estoque = '';
                            $alerta_estoque = '';
                            $is_baixo = 'nao';
                            
                            if ($prod['quantidade_estoque'] <= 5) {
                                $cor_estoque = 'color: #e67e22; font-weight: bold;';
                                $alerta_estoque = ' <i class="fas fa-exclamation-triangle" title="Estoque Baixo"></i>';
                                $is_baixo = 'sim';
                            }

                            $cor_validade = '';
                            $is_vencido = 'nao';
                            
                            if (!empty($prod['data_validade']) && $prod['data_validade'] < $hoje) {
                                $cor_validade = 'color: #e74c3c; font-weight: bold;';
                                $is_vencido = 'sim';
                            }
                        ?>
                            <tr class="produto-row" data-vencido="<?php echo $is_vencido; ?>" data-baixo="<?php echo $is_baixo; ?>" style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 10px;">
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <?php if (!empty($prod['imagem']) && file_exists(__DIR__ . '/../' . $prod['imagem'])): ?>
                                            <img src="<?php echo htmlspecialchars($prod['imagem']); ?>" alt="Foto" style="width: 44px; height: 44px; object-fit: cover; border-radius: 8px; border: 1px solid var(--border-color); flex-shrink: 0;">
                                        <?php else: 
                                            $cat = $prod['categoria'] ?? 'Geral';
                                            $icon = ($cat === 'Ração') ? 'fa-bone' : (($cat === 'Medicamento' || $cat === 'Vacina') ? 'fa-pills' : (($cat === 'Higiene & Estética') ? 'fa-shower' : 'fa-box'));
                                        ?>
                                            <div style="width: 44px; height: 44px; border-radius: 8px; background: rgba(26, 188, 156, 0.12); color: var(--color-primary); display: flex; align-items: center; justify-content: center; font-size: 1.15rem; flex-shrink: 0;">
                                                <i class="fas <?php echo $icon; ?>"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <strong><?php echo htmlspecialchars($prod['nome_produto']); ?></strong><br>
                                            <small style="color: var(--text-muted);"><?php echo htmlspecialchars($prod['marca'] ?: ($prod['fornecedor'] ?? '')); ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td style="padding: 10px; color: var(--text-muted); font-family: monospace; font-size: 0.9rem;">
                                    <?php echo htmlspecialchars($prod['codigo_barras'] ?? '-'); ?>
                                </td>
                                <td style="padding: 10px; color: #10b981; font-weight: bold; font-size: 0.95rem;">
                                    R$ <?php echo number_format($prod['preco_venda'], 2, ',', '.'); ?>
                                </td>
                                <td style="padding: 10px; <?php echo $cor_estoque; ?>">
                                    <?php echo $prod['quantidade_estoque'] . $alerta_estoque; ?>
                                </td>
                                <td style="padding: 10px; <?php echo $cor_validade; ?>">
                                    <?php echo !empty($prod['data_validade']) ? date('d/m/Y', strtotime($prod['data_validade'])) : '-'; ?>
                                </td>
                                <td style="padding: 10px; text-align: center; white-space: nowrap;">
                                    <a href="cadastrar_produto.php?id=<?php echo $prod['id']; ?>" class="btn-icon" style="color: #3b82f6; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; font-weight: 500; padding: 6px 10px; border-radius: 6px; background: rgba(59, 130, 246, 0.1);">
                                        <i class="fas fa-edit"></i> Editar
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if(empty($produtos)): ?>
                            <tr><td colspan="6" style="text-align:center; padding:30px; color: var(--text-muted);">Nenhum produto cadastrado no estoque. Clique em <strong>+ Cadastrar Produto</strong> acima.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ----------------------------------------------------
    // LÓGICA DE FILTRAGEM COMBINADA (BOTÕES + TEXTO)
    // ----------------------------------------------------
    const botoesRapidos = document.querySelectorAll('.btn-quick-filter');
    const inputsFiltro = document.querySelectorAll('.filtro-coluna');
    const linhasTabela = document.querySelectorAll('.data-table tbody tr.produto-row');
    
    let filtroAtivo = 'todos';

    botoesRapidos.forEach(btn => {
        btn.addEventListener('click', function() {
            botoesRapidos.forEach(b => b.style.opacity = '0.5');
            this.style.opacity = '1';
            
            filtroAtivo = this.getAttribute('data-filter');
            aplicarFiltros();
        });
    });

    inputsFiltro.forEach(input => {
        input.addEventListener('keyup', aplicarFiltros);
    });

    function aplicarFiltros() {
        linhasTabela.forEach(linha => {
            let mostrarLinha = true;
            
            const est_vencido = linha.getAttribute('data-vencido');
            const est_baixo = linha.getAttribute('data-baixo');

            if (filtroAtivo === 'vencidos' && est_vencido !== 'sim') mostrarLinha = false;
            if (filtroAtivo === 'baixo_estoque' && est_baixo !== 'sim') mostrarLinha = false;
            if (filtroAtivo === 'ok' && (est_vencido === 'sim' || est_baixo === 'sim')) mostrarLinha = false;

            if (mostrarLinha) {
                inputsFiltro.forEach(input => {
                    const colIndex = input.getAttribute('data-col');
                    const termoBusca = input.value.toLowerCase().trim();
                    
                    if (termoBusca !== '') {
                        const celula = linha.querySelectorAll('td')[colIndex];
                        if (celula) {
                            const textoCelula = celula.textContent.toLowerCase();
                            if (!textoCelula.includes(termoBusca)) {
                                mostrarLinha = false;
                            }
                        }
                    }
                });
            }

            linha.style.display = mostrarLinha ? '' : 'none';
        });
    }
});
</script>

<?php include 'includes/_footer.php'; ?>
