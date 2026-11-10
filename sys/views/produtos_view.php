<?php include 'includes/_sidebar.php'; ?>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; ?>

        <main class="main-content">
            <div class="page-header" style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1 class="main-title">Estoque & Produtos</h1>
                    <p style="color: #777;">Gerencie os itens disponíveis para venda no PDV.</p>
                </div>
                <button class="btn btn-primary" id="btn-novo-produto">
                    <i class="fas fa-box-open"></i> Cadastrar Produto
                </button>
            </div>

            <div class="widget-card">
                
                <div style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
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

                <table class="data-table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="text-align: left; border-bottom: 2px solid #eee;">
                            <th style="padding: 10px;">Produto</th>
                            <th style="padding: 10px;">Código</th>
                            <th style="padding: 10px;">Preço (R$)</th>
                            <th style="padding: 10px;">Estoque</th>
                            <th style="padding: 10px;">Validade</th>
                            <th style="padding: 10px;">Ações</th>
                        </tr>
                        <tr style="background: #fcfcfc; border-bottom: 2px solid #ddd;">
                            <th style="padding: 5px 10px;"><input type="text" class="filtro-coluna" data-col="0" placeholder="Buscar nome..." style="width: 100%; padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-weight: normal; font-size: 0.85rem;"></th>
                            <th style="padding: 5px 10px;"><input type="text" class="filtro-coluna" data-col="1" placeholder="Buscar cód..." style="width: 100%; padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-weight: normal; font-size: 0.85rem;"></th>
                            <th style="padding: 5px 10px;"><input type="text" class="filtro-coluna" data-col="2" placeholder="Filtrar..." style="width: 100%; padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-weight: normal; font-size: 0.85rem;"></th>
                            <th style="padding: 5px 10px;"><input type="text" class="filtro-coluna" data-col="3" placeholder="Filtrar..." style="width: 100%; padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-weight: normal; font-size: 0.85rem;"></th>
                            <th style="padding: 5px 10px;"><input type="text" class="filtro-coluna" data-col="4" placeholder="Filtrar..." style="width: 100%; padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-weight: normal; font-size: 0.85rem;"></th>
                            <th style="padding: 5px 10px;"></th> 
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
                            <tr class="produto-row" data-vencido="<?php echo $is_vencido; ?>" data-baixo="<?php echo $is_baixo; ?>" style="border-bottom: 1px solid #eee;">
                                <td style="padding: 10px;">
                                    <strong><?php echo htmlspecialchars($prod['nome_produto']); ?></strong><br>
                                    <small style="color:#777;"><?php echo htmlspecialchars($prod['fornecedor'] ?? ''); ?></small>
                                </td>
                                <td style="padding: 10px; color:#555;"><?php echo htmlspecialchars($prod['codigo_barras']); ?></td>
                                <td style="padding: 10px; color:#27ae60; font-weight:bold;">
                                    R$ <?php echo number_format($prod['preco_venda'], 2, ',', '.'); ?>
                                </td>
                                <td style="padding: 10px; <?php echo $cor_estoque; ?>">
                                    <?php echo $prod['quantidade_estoque'] . $alerta_estoque; ?>
                                </td>
                                <td style="padding: 10px; <?php echo $cor_validade; ?>">
                                    <?php echo !empty($prod['data_validade']) ? date('d/m/Y', strtotime($prod['data_validade'])) : '-'; ?>
                                </td>
                                <td style="padding: 10px;">
                                    <button class="btn-icon btn-edit-prod" 
                                            data-produto='<?php echo json_encode($prod); ?>'
                                            style="color: #3498db; border: none; background: none; cursor: pointer;">
                                        <i class="fas fa-edit"></i> Editar
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if(empty($produtos)): ?>
                            <tr><td colspan="6" style="text-align:center; padding:20px;">Nenhum produto cadastrado no estoque.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</div>

<div class="modal-overlay" id="produto-modal" style="display: none;">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h2 id="modal-titulo">Cadastrar Produto</h2>
            <button class="close-modal-btn" style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
        </div>
        <div class="modal-body">
            <form id="produto-form">
                <input type="hidden" id="prod-id">
                
                <h4 style="margin-bottom:10px; border-bottom:1px solid #eee; padding-bottom:5px;">Dados Básicos</h4>
                <div class="form-group-row">
                    <div class="form-group" style="flex: 2;">
                        <label>Nome do Produto *</label>
                        <input type="text" id="prod-nome" required placeholder="Ex: Ração Premier Raças Grandes 15kg">
                    </div>
                    <div class="form-group">
                        <label>Categoria *</label>
                        <select id="prod-categoria">
                            <option value="Geral">Geral</option>
                            <option value="Ração">Ração / Alimento Pet</option>
                            <option value="Medicamento">Medicamento</option>
                            <option value="Vacina">Vacina</option>
                            <option value="Higiene & Estética">Higiene & Estética</option>
                            <option value="Acessórios">Acessórios</option>
                            <option value="Outros">Outros</option>
                        </select>
                    </div>
                </div>

                <div class="form-group-row">
                    <div class="form-group">
                        <label>Marca / Fabricante</label>
                        <input type="text" id="prod-marca" placeholder="Ex: Premier Pet, Royal Canin, Zoetis">
                    </div>
                    <div class="form-group">
                        <label>Código de Barras</label>
                        <input type="text" id="prod-codigo" placeholder="Bipe ou digite o código...">
                    </div>
                </div>

                <!-- BOX INTELIGENTE DO CRM DE RAÇÃO (DESIGN SIMPLES PARA USUÁRIOS LEIGOS) -->
                <div id="box-racao-crm" style="display: none; background: #f0fdf4; border: 1.5px solid #86efac; border-radius: 12px; padding: 16px; margin: 15px 0;">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                        <span style="font-size: 1.2rem; color: #16a34a;"><i class="fas fa-bone"></i></span>
                        <strong style="color: #14532d; font-size: 1rem;">Configuração Automática para o CRM de Reposição</strong>
                    </div>
                    <p style="color: #475569; font-size: 0.85rem; margin: 0 0 12px 0;">
                        O sistema calculará sozinho quando a ração vai acabar para alertar a sua equipe no momento certo.
                    </p>

                    <div class="form-group-row" style="margin-bottom: 10px;">
                        <div class="form-group" style="flex: 1.2;">
                            <label style="font-weight: 600; color: #166534;">Peso da Embalagem (Kg) *</label>
                            <input type="number" step="0.1" id="prod-peso" placeholder="Ex: 15.0" style="border: 2px solid #86efac; border-radius: 8px; padding: 8px 10px;">
                            
                            <!-- Botões de 1 clique para pessoas leigas -->
                            <div style="display: flex; gap: 5px; flex-wrap: wrap; margin-top: 6px;">
                                <button type="button" class="btn-chip-peso" data-kg="1" style="background:#e2e8f0; border:none; padding:3px 8px; border-radius:12px; font-size:0.75rem; cursor:pointer;">1 kg</button>
                                <button type="button" class="btn-chip-peso" data-kg="3" style="background:#e2e8f0; border:none; padding:3px 8px; border-radius:12px; font-size:0.75rem; cursor:pointer;">3 kg</button>
                                <button type="button" class="btn-chip-peso" data-kg="7.5" style="background:#e2e8f0; border:none; padding:3px 8px; border-radius:12px; font-size:0.75rem; cursor:pointer;">7.5 kg</button>
                                <button type="button" class="btn-chip-peso" data-kg="10" style="background:#e2e8f0; border:none; padding:3px 8px; border-radius:12px; font-size:0.75rem; cursor:pointer;">10 kg</button>
                                <button type="button" class="btn-chip-peso" data-kg="12" style="background:#e2e8f0; border:none; padding:3px 8px; border-radius:12px; font-size:0.75rem; cursor:pointer;">12 kg</button>
                                <button type="button" class="btn-chip-peso" data-kg="15" style="background:#16a34a; color:white; border:none; padding:3px 8px; border-radius:12px; font-size:0.75rem; cursor:pointer; font-weight:bold;">15 kg</button>
                                <button type="button" class="btn-chip-peso" data-kg="20" style="background:#e2e8f0; border:none; padding:3px 8px; border-radius:12px; font-size:0.75rem; cursor:pointer;">20 kg</button>
                            </div>
                        </div>

                        <div class="form-group" style="flex: 1.5;">
                            <label style="font-weight: 600; color: #166534;">Linha / Qualidade da Ração</label>
                            <select id="prod-linha-racao" style="border: 2px solid #86efac; border-radius: 8px; padding: 8px 10px;">
                                <option value="3850">⭐ Super Premium (Premier, Royal Canin, Hills)</option>
                                <option value="3700">✨ Premium Especial (Golden, Special Dog)</option>
                                <option value="3800">🐱 Ração para Gatos</option>
                                <option value="3200">🍃 Ração Light / Obesidade / Sênior</option>
                                <option value="3500">🥩 Standard / Manutenção Econômica</option>
                                <option value="manual">⚙️ Digitar Calorias Manualmente</option>
                            </select>
                            <small style="color: #64748b; font-size: 0.78rem; display: block; margin-top: 4px;">
                                💡 As calorias são calculadas sozinhas. Usuários leigos não precisam consultar tabela nutricional!
                            </small>
                        </div>
                    </div>

                    <div id="box-calorias-manual" style="display: none; margin-top: 8px;">
                        <label style="font-size: 0.82rem; color: #475569;">Energia Metabolizável Manual (kcal/kg):</label>
                        <input type="number" step="10" id="prod-energia" value="3850" style="width: 150px; padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 0.85rem;">
                    </div>
                </div>

                <div class="form-group">
                    <label>Descrição</label>
                    <textarea id="prod-descricao" rows="2"></textarea>
                </div>

                <h4 style="margin-bottom:10px; margin-top:20px; border-bottom:1px solid #eee; padding-bottom:5px;">Financeiro e Estoque</h4>
                <div class="form-group-row">
                    <div class="form-group">
                        <label>Custo de Compra (R$)</label>
                        <input type="number" step="0.01" id="prod-custo" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>Preço de Venda (R$) *</label>
                        <input type="number" step="0.01" id="prod-preco" required placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>Qtd. em Estoque *</label>
                        <input type="number" id="prod-estoque" required value="0">
                    </div>
                </div>

                <h4 style="margin-bottom:10px; margin-top:20px; border-bottom:1px solid #eee; padding-bottom:5px;">Logística & Validade</h4>
                <div class="form-group-row">
                    <div class="form-group" style="flex: 2;">
                        <label>Fornecedor</label>
                        <input type="text" id="prod-fornecedor" placeholder="Nome do distribuidor/fabricante">
                    </div>
                    <div class="form-group">
                        <label>Data de Fabricação</label>
                        <input type="date" id="prod-fabricacao">
                    </div>
                    <div class="form-group">
                        <label>Data de Validade</label>
                        <input type="date" id="prod-validade">
                    </div>
                </div>

            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary close-modal-btn">Cancelar</button>
            <button class="btn btn-primary" id="saveProdBtn">Salvar Produto</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ----------------------------------------------------
    // LÓGICA DO MODAL E SALVAMENTO
    // ----------------------------------------------------
    const modal = document.getElementById('produto-modal');
    const btnNovo = document.getElementById('btn-novo-produto');
    const btnSave = document.getElementById('saveProdBtn');
    const selectCat = document.getElementById('prod-categoria');
    const boxRacao = document.getElementById('box-racao-crm');
    const selectLinhaRacao = document.getElementById('prod-linha-racao');
    const boxCaloriasManual = document.getElementById('box-calorias-manual');
    const inputEnergia = document.getElementById('prod-energia');

    // Alterna visualização do box de Ração
    function alternarBoxRacao(categoria) {
        if (categoria === 'Ração') {
            boxRacao.style.display = 'block';
        } else {
            boxRacao.style.display = 'none';
        }
    }

    if (selectCat) {
        selectCat.addEventListener('change', function() {
            alternarBoxRacao(this.value);
        });
    }

    // Botões de clique rápido de peso para pessoas leigas
    document.querySelectorAll('.btn-chip-peso').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('prod-peso').value = this.dataset.kg;
            document.querySelectorAll('.btn-chip-peso').forEach(b => {
                b.style.background = '#e2e8f0';
                b.style.color = '#334155';
                b.style.fontWeight = 'normal';
            });
            this.style.background = '#16a34a';
            this.style.color = 'white';
            this.style.fontWeight = 'bold';
        });
    });

    // Ajuste automático de calorias por linha de ração
    if (selectLinhaRacao) {
        selectLinhaRacao.addEventListener('change', function() {
            if (this.value === 'manual') {
                boxCaloriasManual.style.display = 'block';
            } else {
                boxCaloriasManual.style.display = 'none';
                if (inputEnergia) inputEnergia.value = this.value;
            }
        });
    }
    
    btnNovo.onclick = () => {
        document.getElementById('produto-form').reset();
        document.getElementById('prod-id').value = '';
        document.getElementById('modal-titulo').innerText = 'Cadastrar Produto';
        alternarBoxRacao('Geral');
        modal.style.display = 'flex';
    };

    document.querySelectorAll('.close-modal-btn').forEach(btn => btn.onclick = () => modal.style.display = 'none');

    document.querySelectorAll('.btn-edit-prod').forEach(btn => {
        btn.addEventListener('click', function() {
            const p = JSON.parse(this.getAttribute('data-produto'));
            
            document.getElementById('prod-id').value = p.id;
            document.getElementById('prod-nome').value = p.nome_produto;
            document.getElementById('prod-categoria').value = p.categoria || 'Geral';
            document.getElementById('prod-marca').value = p.marca || '';
            document.getElementById('prod-codigo').value = p.codigo_barras || '';
            document.getElementById('prod-descricao').value = p.descricao || '';
            document.getElementById('prod-custo').value = p.preco_custo || '';
            document.getElementById('prod-preco').value = p.preco_venda;
            document.getElementById('prod-estoque').value = p.quantidade_estoque;
            document.getElementById('prod-peso').value = p.tamanho_embalagem_kg || '';
            document.getElementById('prod-fornecedor').value = p.fornecedor || '';
            document.getElementById('prod-fabricacao').value = p.data_fabricacao || '';
            document.getElementById('prod-validade').value = p.data_validade || '';

            alternarBoxRacao(p.categoria);

            if (p.energia_metabolizavel_kg) {
                if (inputEnergia) inputEnergia.value = p.energia_metabolizavel_kg;
                const optMatch = Array.from(selectLinhaRacao.options).find(o => Math.abs(parseFloat(o.value) - parseFloat(p.energia_metabolizavel_kg)) < 1);
                if (optMatch) {
                    selectLinhaRacao.value = optMatch.value;
                    boxCaloriasManual.style.display = 'none';
                } else {
                    selectLinhaRacao.value = 'manual';
                    boxCaloriasManual.style.display = 'block';
                }
            } else {
                selectLinhaRacao.value = '3850';
                if (inputEnergia) inputEnergia.value = '3850';
                boxCaloriasManual.style.display = 'none';
            }

            document.getElementById('modal-titulo').innerText = 'Editar Produto';
            modal.style.display = 'flex';
        });
    });

    btnSave.addEventListener('click', function() {
        const cat = document.getElementById('prod-categoria').value;
        const pesoVal = document.getElementById('prod-peso').value;
        let energiaVal = null;
        if (cat === 'Ração') {
            energiaVal = inputEnergia ? inputEnergia.value : 3850;
        }

        const data = {
            id: document.getElementById('prod-id').value,
            nome_produto: document.getElementById('prod-nome').value,
            categoria: cat,
            marca: document.getElementById('prod-marca').value,
            codigo_barras: document.getElementById('prod-codigo').value,
            descricao: document.getElementById('prod-descricao').value,
            valor_compra: document.getElementById('prod-custo').value,
            preco_venda: document.getElementById('prod-preco').value,
            quantidade_estoque: document.getElementById('prod-estoque').value,
            tamanho_embalagem_kg: pesoVal,
            energia_metabolizavel_kg: energiaVal,
            fornecedor: document.getElementById('prod-fornecedor').value,
            data_fabricacao: document.getElementById('prod-fabricacao').value,
            data_validade: document.getElementById('prod-validade').value
        };

        if(!data.nome_produto || !data.preco_venda || !data.quantidade_estoque) {
            alert("Preencha Nome, Preço e Estoque."); return;
        }

        btnSave.innerText = "Salvando...";
        btnSave.disabled = true;

        fetch('api_salvar_produto.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        })
        .then(res => res.json())
        .then(res => {
            alert(res.message);
            if(res.success) location.reload();
            else { btnSave.innerText = "Salvar Produto"; btnSave.disabled = false; }
        })
        .catch(err => {
            alert("Erro de conexão.");
            btnSave.innerText = "Salvar Produto"; btnSave.disabled = false;
        });
    });

    // ----------------------------------------------------
    // LÓGICA DE FILTRAGEM COMBINADA (BOTÕES + TEXTO)
    // ----------------------------------------------------
    const botoesRapidos = document.querySelectorAll('.btn-quick-filter');
    const inputsFiltro = document.querySelectorAll('.filtro-coluna');
    const linhasTabela = document.querySelectorAll('.data-table tbody tr.produto-row');
    
    let filtroAtivo = 'todos'; // Estado global do filtro rápido

    // 1. Escuta cliques nos botões coloridos
    botoesRapidos.forEach(btn => {
        btn.addEventListener('click', function() {
            botoesRapidos.forEach(b => b.style.opacity = '0.5'); // Desliga visualmente os outros
            this.style.opacity = '1'; // Liga o clicado
            
            filtroAtivo = this.getAttribute('data-filter');
            aplicarFiltros(); // Roda a função principal
        });
    });

    // 2. Escuta digitação nas caixinhas de texto
    inputsFiltro.forEach(input => {
        input.addEventListener('keyup', aplicarFiltros);
    });

    // 3. A Função que cruza os dados
    function aplicarFiltros() {
        linhasTabela.forEach(linha => {
            let mostrarLinha = true;
            
            // Passo A: Verifica a regra do Botão Rápido
            const est_vencido = linha.getAttribute('data-vencido');
            const est_baixo = linha.getAttribute('data-baixo');

            if (filtroAtivo === 'vencidos' && est_vencido !== 'sim') mostrarLinha = false;
            if (filtroAtivo === 'baixo_estoque' && est_baixo !== 'sim') mostrarLinha = false;
            if (filtroAtivo === 'ok' && (est_vencido === 'sim' || est_baixo === 'sim')) mostrarLinha = false;

            // Passo B: Se a linha sobreviveu ao botão, verifica o Texto digitado
            if (mostrarLinha) {
                inputsFiltro.forEach(input => {
                    const colIndex = input.getAttribute('data-col');
                    const termoBusca = input.value.toLowerCase().trim();
                    
                    if (termoBusca !== '') {
                        const celula = linha.querySelectorAll('td')[colIndex];
                        if (celula) {
                            const textoCelula = celula.textContent.toLowerCase();
                            if (!textoCelula.includes(termoBusca)) {
                                mostrarLinha = false; // Reprovado no texto
                            }
                        }
                    }
                });
            }

            // Aplica o veredito final na tela
            linha.style.display = mostrarLinha ? '' : 'none';
        });
    }
});
</script>

<?php include 'includes/_footer.php'; ?>
