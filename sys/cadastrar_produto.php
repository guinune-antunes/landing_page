<?php
require_once 'includes/verifica_login.php';
verificarPermissao(['Gerente', 'Admin']);

require_once 'conexao.php';
$id_loja = $_SESSION['instituicao_id'];

$id_produto = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$produto = null;

if ($id_produto > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM produtos WHERE id = ? AND instituicao_id = ?");
        $stmt->execute([$id_produto, $id_loja]);
        $produto = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $produto = null;
    }
}

$is_edicao = !empty($produto);
$pageTitle = $is_edicao ? 'Editar Produto' : 'Cadastrar Produto';
$paginaAtiva = 'produtos';

include 'includes/_head.php';
?>

<?php include 'includes/_sidebar.php'; ?>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; ?>

        <main class="main-content">
            <!-- Barra Superior com Voltar e Título -->
            <div class="cad-header-bar">
                <div>
                    <a href="produtos.php" class="btn-voltar-link">
                        <i class="fas fa-arrow-left"></i> Voltar ao Estoque
                    </a>
                    <h1 class="main-title" style="margin-top: 8px; margin-bottom: 4px;">
                        <?php echo $is_edicao ? 'Editar Produto' : 'Cadastrar Novo Produto'; ?>
                    </h1>
                    <p class="cad-header-sub">
                        <?php echo $is_edicao ? 'Atualize as informações do item no catálogo.' : 'Funil guiado para cadastrar com rapidez, vincular fotos e ativar o CRM automático.'; ?>
                    </p>
                </div>
            </div>

            <!-- LAYOUT DIVIDIDO (SPLIT SCREEN: FUNIL À ESQUERDA, LIVE PREVIEW À DIREITA) -->
            <div class="funil-grid-container">
                
                <!-- COLUNA ESQUERDA: O FORMULÁRIO DO FUNIL (WIZARD EM ETAPAS) -->
                <div class="funil-col-form">
                    
                    <!-- Barra de Navegação do Funil (Steps) -->
                    <div class="stepper-nav">
                        <button type="button" class="step-btn active" data-step="1">
                            <span class="step-num">1</span>
                            <span class="step-text">Identificação</span>
                        </button>
                        <button type="button" class="step-btn" data-step="2">
                            <span class="step-num">2</span>
                            <span class="step-text">Categoria & CRM</span>
                        </button>
                        <button type="button" class="step-btn" data-step="3">
                            <span class="step-num">3</span>
                            <span class="step-text">Preços & Estoque</span>
                        </button>
                        <button type="button" class="step-btn" data-step="4">
                            <span class="step-num">4</span>
                            <span class="step-text">Validade & Finalizar</span>
                        </button>
                    </div>

                    <form id="funil-produto-form" enctype="multipart/form-data">
                        <input type="hidden" id="prod-id" name="id" value="<?php echo htmlspecialchars($produto['id'] ?? ''); ?>">

                        <!-- ==============================================
                             ETAPA 1: IDENTIFICAÇÃO E FOTO DO PRODUTO
                             ============================================== -->
                        <div class="funil-step-pane active" id="step-pane-1">
                            <div class="step-pane-header">
                                <h3><i class="fas fa-camera"></i> Identificação e Foto do Produto</h3>
                                <p>Comece pela aparência e dados de identificação do item na loja.</p>
                            </div>

                            <!-- Área de Upload de Imagem Interativa -->
                            <div class="form-group">
                                <label style="font-weight: 600;">Foto do Produto (Opcional)</label>
                                <div class="upload-dropzone" id="upload-dropzone">
                                    <input type="file" id="prod-imagem-input" name="imagem" accept="image/jpeg,image/png,image/webp,image/gif" style="display: none;">
                                    
                                    <div class="dropzone-preview-box" id="dropzone-preview-box" style="<?php echo !empty($produto['imagem']) ? 'display:flex;' : 'display:none;'; ?>">
                                        <img id="img-preview" src="<?php echo htmlspecialchars($produto['imagem'] ?? ''); ?>" alt="Foto do Produto">
                                        <div class="dropzone-actions">
                                            <button type="button" class="btn-subtle" id="btn-trocar-foto">
                                                <i class="fas fa-sync-alt"></i> Trocar Foto
                                            </button>
                                            <button type="button" class="btn-subtle btn-danger-subtle" id="btn-remover-foto">
                                                <i class="fas fa-trash"></i> Remover
                                            </button>
                                        </div>
                                    </div>

                                    <div class="dropzone-placeholder" id="dropzone-placeholder" style="<?php echo !empty($produto['imagem']) ? 'display:none;' : 'display:flex;'; ?>">
                                        <div class="dropzone-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                                        <strong>Clique aqui para escolher a imagem</strong>
                                        <span>ou arraste o arquivo até aqui (JPG, PNG ou WEBP até 5MB)</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Nome do Produto -->
                            <div class="form-group">
                                <label for="prod-nome">Nome do Produto *</label>
                                <input type="text" id="prod-nome" name="nome_produto" required 
                                       placeholder="Ex: Ração Premier Formula Cães Adultos Raças Grandes 15kg"
                                       value="<?php echo htmlspecialchars($produto['nome_produto'] ?? ''); ?>"
                                       style="font-size: 1.05rem; font-weight: 500;">
                            </div>

                            <div class="form-group-row">
                                <div class="form-group">
                                    <label for="prod-codigo">Código de Barras (EAN)</label>
                                    <div style="display: flex; gap: 8px;">
                                        <input type="text" id="prod-codigo" name="codigo_barras" 
                                               placeholder="Bipe ou digite o código..."
                                               value="<?php echo htmlspecialchars($produto['codigo_barras'] ?? ''); ?>">
                                        <button type="button" class="btn btn-secondary" id="btn-gerar-ean" title="Gerar código aleatório" style="white-space: nowrap; padding: 0 14px;">
                                            <i class="fas fa-magic"></i> Gerar
                                        </button>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="prod-marca">Marca / Fabricante</label>
                                    <input type="text" id="prod-marca" name="marca" 
                                           placeholder="Ex: Premier Pet, Royal Canin, Bravecto"
                                           value="<?php echo htmlspecialchars($produto['marca'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="step-footer-actions">
                                <div></div>
                                <button type="button" class="btn btn-primary btn-next-step" data-target="2">
                                    Avançar: Categoria & CRM <i class="fas fa-arrow-right"></i>
                                </button>
                            </div>
                        </div>

                        <!-- ==============================================
                             ETAPA 2: CATEGORIA & INTELIGÊNCIA CRM
                             ============================================== -->
                        <div class="funil-step-pane" id="step-pane-2">
                            <div class="step-pane-header">
                                <h3><i class="fas fa-tags"></i> Categoria & Inteligência CRM</h3>
                                <p>Defina a categoria e configure o alerta automático de reposição.</p>
                            </div>

                            <!-- Seletor Rápido de Categorias com Pills Visuais -->
                            <div class="form-group">
                                <label style="font-weight: 600;">Selecione a Categoria do Produto *</label>
                                <input type="hidden" id="prod-categoria" name="categoria" value="<?php echo htmlspecialchars($produto['categoria'] ?? 'Geral'); ?>">
                                <div class="category-pills-grid">
                                    <?php 
                                    $cats = [
                                        ['val' => 'Ração', 'icon' => 'fa-bone', 'nome' => 'Ração / Alimento'],
                                        ['val' => 'Medicamento', 'icon' => 'fa-pills', 'nome' => 'Medicamentos'],
                                        ['val' => 'Vacina', 'icon' => 'fa-syringe', 'nome' => 'Vacinas'],
                                        ['val' => 'Higiene & Estética', 'icon' => 'fa-shower', 'nome' => 'Higiene & Banho'],
                                        ['val' => 'Acessórios', 'icon' => 'fa-gem', 'nome' => 'Acessórios / Brinquedos'],
                                        ['val' => 'Geral', 'icon' => 'fa-box', 'nome' => 'Geral / Outros']
                                    ];
                                    $catAtual = $produto['categoria'] ?? 'Geral';
                                    foreach ($cats as $c): 
                                        $activeClass = ($c['val'] === $catAtual) ? 'active' : '';
                                    ?>
                                        <button type="button" class="category-pill-card <?php echo $activeClass; ?>" data-cat="<?php echo $c['val']; ?>">
                                            <i class="fas <?php echo $c['icon']; ?>"></i>
                                            <span><?php echo $c['nome']; ?></span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- BOX INTELIGENTE DO CRM DE RAÇÃO -->
                            <div id="box-racao-crm" class="box-racao-crm" style="<?php echo ($catAtual === 'Ração') ? 'display:block;' : 'display:none;'; ?>">
                                <div class="box-racao-header">
                                    <span class="box-racao-icon"><i class="fas fa-brain"></i></span>
                                    <strong class="box-racao-title">Cálculo Automático para o CRM de Reposição</strong>
                                </div>
                                <p class="box-racao-subtitle">
                                    Ao vender este item no PDV, o sistema calculará em tempo real os dias até acabar e alertará o vendedor no momento exato de contatar o tutor.
                                </p>

                                <div class="form-group-row">
                                    <div class="form-group col-peso">
                                        <label class="box-racao-label">Peso da Embalagem (Kg) *</label>
                                        <input type="number" step="0.1" id="prod-peso" name="tamanho_embalagem_kg" 
                                               placeholder="Ex: 15.0" class="input-racao-highlight"
                                               value="<?php echo htmlspecialchars($produto['tamanho_embalagem_kg'] ?? ''); ?>">
                                        
                                        <!-- Chips de 1 clique -->
                                        <div class="chips-peso-container">
                                            <?php foreach (['1', '3', '7.5', '10', '12', '15', '20'] as $kg): 
                                                $chipActive = (isset($produto['tamanho_embalagem_kg']) && (float)$produto['tamanho_embalagem_kg'] == (float)$kg) ? 'active' : '';
                                            ?>
                                                <button type="button" class="btn-chip-peso <?php echo $chipActive; ?>" data-kg="<?php echo $kg; ?>"><?php echo $kg; ?> kg</button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <div class="form-group col-linha">
                                        <label class="box-racao-label">Linha / Qualidade Nutricional</label>
                                        <select id="prod-linha-racao" class="select-racao-highlight">
                                            <option value="3850">⭐ Super Premium (Premier, Royal Canin, Hills)</option>
                                            <option value="3700">✨ Premium Especial (Golden, Special Dog)</option>
                                            <option value="3800">🐱 Ração para Gatos</option>
                                            <option value="3200">🍃 Ração Light / Obesidade / Sênior</option>
                                            <option value="3500">🥩 Standard / Manutenção Econômica</option>
                                            <option value="manual">⚙️ Digitar Calorias Manualmente</option>
                                        </select>
                                        <small class="box-racao-hint">
                                            💡 Usuários leigos não precisam consultar tabela. As calorias são definidas automaticamente!
                                        </small>
                                    </div>
                                </div>

                                <div id="box-calorias-manual" style="display: none; margin-top: 10px;">
                                    <label class="box-racao-hint" style="font-weight: 600;">Energia Metabolizável Manual (kcal/kg):</label>
                                    <input type="number" step="10" id="prod-energia" name="energia_metabolizavel_kg" 
                                           value="<?php echo htmlspecialchars($produto['energia_metabolizavel_kg'] ?? '3850'); ?>" 
                                           class="input-racao-highlight" style="width: 170px; font-size: 0.9rem;">
                                </div>
                            </div>

                            <!-- Descrição do Produto -->
                            <div class="form-group" style="margin-top: 15px;">
                                <label for="prod-descricao">Descrição / Detalhes do Produto</label>
                                <textarea id="prod-descricao" name="descricao" rows="3" placeholder="Informações adicionais, sabor, indicação de porte ou modo de administração..."><?php echo htmlspecialchars($produto['descricao'] ?? ''); ?></textarea>
                            </div>

                            <div class="step-footer-actions">
                                <button type="button" class="btn btn-secondary btn-prev-step" data-target="1">
                                    <i class="fas fa-arrow-left"></i> Voltar
                                </button>
                                <button type="button" class="btn btn-primary btn-next-step" data-target="3">
                                    Avançar: Preços & Estoque <i class="fas fa-arrow-right"></i>
                                </button>
                            </div>
                        </div>

                        <!-- ==============================================
                             ETAPA 3: PREÇOS, MARGEM & ESTOQUE
                             ============================================== -->
                        <div class="funil-step-pane" id="step-pane-3">
                            <div class="step-pane-header">
                                <h3><i class="fas fa-hand-holding-usd"></i> Preços, Margem de Lucro & Estoque</h3>
                                <p>Gerencie os valores de compra, venda e a margem calculada automaticamente.</p>
                            </div>

                            <div class="form-group-row">
                                <div class="form-group">
                                    <label for="prod-custo">Preço de Custo (R$)</label>
                                    <input type="number" step="0.01" id="prod-custo" name="preco_custo" 
                                           placeholder="0.00" value="<?php echo htmlspecialchars($produto['preco_custo'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label for="prod-preco">Preço de Venda (R$) *</label>
                                    <input type="number" step="0.01" id="prod-preco" name="preco_venda" required 
                                           placeholder="0.00" value="<?php echo htmlspecialchars($produto['preco_venda'] ?? ''); ?>"
                                           style="font-size: 1.1rem; font-weight: 600; color: #10b981;">
                                </div>
                            </div>

                            <!-- CARD CALCULADORA DE MARGEM EM TEMPO REAL -->
                            <div class="margin-calculator-box" id="margin-calculator-box">
                                <div class="margin-metric">
                                    <span class="margin-label">Lucro Bruto Unitário:</span>
                                    <strong class="margin-value" id="calc-lucro-bruto">R$ 0,00</strong>
                                </div>
                                <div class="margin-metric">
                                    <span class="margin-label">Margem de Lucro:</span>
                                    <strong class="margin-value" id="calc-margem-pct">0%</strong>
                                </div>
                                <div class="margin-status" id="calc-margem-status">Preencha custo e venda</div>
                            </div>

                            <div class="form-group-row" style="margin-top: 15px;">
                                <div class="form-group">
                                    <label for="prod-estoque">Quantidade em Estoque *</label>
                                    <input type="number" id="prod-estoque" name="quantidade_estoque" required 
                                           value="<?php echo htmlspecialchars($produto['quantidade_estoque'] ?? '0'); ?>">
                                </div>
                                <div class="form-group">
                                    <label for="prod-unidade">Unidade de Medida</label>
                                    <select id="prod-unidade" name="unidade">
                                        <?php 
                                        $unidades = ['Unidade', 'Pacote', 'Frasco', 'Sachê', 'Kg', 'Caixa', 'Dose'];
                                        $unidadeAtual = $produto['unidade'] ?? 'Unidade';
                                        foreach ($unidades as $u):
                                        ?>
                                            <option value="<?php echo $u; ?>" <?php echo ($u === $unidadeAtual) ? 'selected' : ''; ?>>
                                                <?php echo $u; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="step-footer-actions">
                                <button type="button" class="btn btn-secondary btn-prev-step" data-target="2">
                                    <i class="fas fa-arrow-left"></i> Voltar
                                </button>
                                <button type="button" class="btn btn-primary btn-next-step" data-target="4">
                                    Avançar: Validade & Finalizar <i class="fas fa-arrow-right"></i>
                                </button>
                            </div>
                        </div>

                        <!-- ==============================================
                             ETAPA 4: VALIDADE & LOGÍSTICA (FINALIZAÇÃO)
                             ============================================== -->
                        <div class="funil-step-pane" id="step-pane-4">
                            <div class="step-pane-header">
                                <h3><i class="fas fa-truck-loading"></i> Logística, Validade & Conclusão</h3>
                                <p>Rastreie lotes, datas de fabricação e distribuidor antes de gravar no estoque.</p>
                            </div>

                            <div class="form-group">
                                <label for="prod-fornecedor">Distribuidor / Fornecedor</label>
                                <input type="text" id="prod-fornecedor" name="fornecedor" 
                                       placeholder="Nome do distribuidor ou fabricante principal..."
                                       value="<?php echo htmlspecialchars($produto['fornecedor'] ?? ''); ?>">
                            </div>

                            <div class="form-group-row">
                                <div class="form-group">
                                    <label for="prod-fabricacao">Data de Fabricação</label>
                                    <input type="date" id="prod-fabricacao" name="data_fabricacao"
                                           value="<?php echo htmlspecialchars($produto['data_fabricacao'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label for="prod-validade">Data de Validade</label>
                                    <input type="date" id="prod-validade" name="data_validade"
                                           value="<?php echo htmlspecialchars($produto['data_validade'] ?? ''); ?>">
                                </div>
                            </div>

                            <!-- Alerta Dinâmico de Validade -->
                            <div id="validade-alerta-box" style="display: none; padding: 10px 14px; border-radius: 8px; margin-top: 10px; font-size: 0.88rem;"></div>

                            <!-- Resumo dos Dados Principais antes de Gravar -->
                            <div class="resumo-conclusao-box">
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px; color: var(--color-primary); font-weight: 600;">
                                    <i class="fas fa-check-circle"></i> Tudo Pronto para Concluir!
                                </div>
                                <p style="font-size: 0.86rem; color: var(--text-muted); margin: 0;">
                                    Revise os dados no Card à direita. Ao salvar, o produto ficará imediatamente liberado para vendas no PDV e sincronizado com os alertas inteligentes de clientes.
                                </p>
                            </div>

                            <div class="step-footer-actions">
                                <button type="button" class="btn btn-secondary btn-prev-step" data-target="3">
                                    <i class="fas fa-arrow-left"></i> Voltar
                                </button>
                                <button type="submit" class="btn btn-primary btn-salvar-final" id="btn-salvar-funil">
                                    <i class="fas fa-save"></i> <?php echo $is_edicao ? 'Salvar Alterações' : 'Salvar Produto no Estoque'; ?>
                                </button>
                            </div>
                        </div>

                    </form>
                </div>

                <!-- COLUNA DIREITA: LIVE PREVIEW DO PRODUTO (STICKY NO DESKTOP) -->
                <div class="funil-col-preview">
                    
                    <div class="preview-sticky-card">
                        <div class="preview-header">
                            <span class="preview-badge"><i class="fas fa-eye"></i> Visualização em Tempo Real</span>
                            <span class="preview-tag-device">Visual do PDV</span>
                        </div>

                        <!-- Card de Vitrine do Produto -->
                        <div class="live-product-card" id="live-product-card">
                            <div class="live-card-media">
                                <img id="live-preview-img" src="<?php echo htmlspecialchars($produto['imagem'] ?? ''); ?>" alt="Foto" style="<?php echo !empty($produto['imagem']) ? 'display:block;' : 'display:none;'; ?>">
                                <div id="live-placeholder-icon" style="<?php echo !empty($produto['imagem']) ? 'display:none;' : 'display:flex;'; ?>">
                                    <i id="live-category-icon" class="fas fa-box"></i>
                                </div>
                                <span class="live-card-badge" id="live-badge-cat">Geral</span>
                            </div>

                            <div class="live-card-body">
                                <small class="live-card-brand" id="live-card-brand">Marca do Produto</small>
                                <h4 class="live-card-title" id="live-card-title">Nome do Produto...</h4>
                                
                                <div class="live-card-price-row">
                                    <div>
                                        <span class="live-price-label">Preço de Venda</span>
                                        <div class="live-price-val" id="live-price-val">R$ 0,00</div>
                                    </div>
                                    <div style="text-align: right;">
                                        <span class="live-price-label">Estoque</span>
                                        <div class="live-stock-val" id="live-stock-val">0 un</div>
                                    </div>
                                </div>

                                <!-- Badge Inteligente do CRM de Ração -->
                                <div class="live-crm-alert" id="live-crm-alert" style="display: none;">
                                    <i class="fas fa-robot"></i> CRM: Reposição calculada automaticamente
                                </div>
                            </div>
                        </div>

                        <!-- Barra de Progresso do Cadastro -->
                        <div class="funil-progress-tracker">
                            <div style="display: flex; justify-content: space-between; font-size: 0.82rem; margin-bottom: 6px;">
                                <span style="font-weight: 600;">Progresso do Cadastro:</span>
                                <span id="funil-progress-pct" style="font-weight: 700; color: var(--color-primary);">25%</span>
                            </div>
                            <div class="progress-bar-track">
                                <div class="progress-bar-fill" id="funil-progress-bar" style="width: 25%;"></div>
                            </div>
                        </div>

                        <!-- Dicas Contextuais Inteligentes -->
                        <div class="smart-funil-tip" id="smart-funil-tip">
                            <i class="fas fa-lightbulb"></i>
                            <div>
                                <strong>Dica do Funil:</strong>
                                <span id="smart-tip-text">Adicione uma foto de boa qualidade. Produtos com foto aumentam a velocidade no PDV em 40%!</span>
                            </div>
                        </div>

                    </div>

                </div>

            </div>
        </main>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ----------------------------------------------------
    // ESTADO E ELEMENTOS DO FUNIL
    // ----------------------------------------------------
    let currentStep = 1;
    const totalSteps = 4;
    const form = document.getElementById('funil-produto-form');
    const stepPanes = document.querySelectorAll('.funil-step-pane');
    const stepButtons = document.querySelectorAll('.step-btn');
    const progressBar = document.getElementById('funil-progress-bar');
    const progressPct = document.getElementById('funil-progress-pct');
    const tipText = document.getElementById('smart-tip-text');

    const tips = {
        1: "Adicione uma foto de boa qualidade. Produtos com foto aumentam a velocidade no PDV em 40%!",
        2: "Selecione a categoria. Se for Ração, escolha o peso para ativar o CRM de alerta automático!",
        3: "Preencha o custo para ver sua Margem de Lucro calculada em tempo real!",
        4: "Defina a data de validade para receber avisos antes que o lote expire!"
    };

    // Navegar entre etapas do funil
    function irParaPasso(step) {
        step = Math.max(1, Math.min(totalSteps, step));
        currentStep = step;

        stepPanes.forEach(pane => pane.classList.remove('active'));
        document.getElementById('step-pane-' + step).classList.add('active');

        stepButtons.forEach(btn => {
            const btnStep = parseInt(btn.dataset.step);
            if (btnStep === step) {
                btn.classList.add('active');
                btn.classList.remove('completed');
            } else if (btnStep < step) {
                btn.classList.add('completed');
                btn.classList.remove('active');
            } else {
                btn.classList.remove('active', 'completed');
            }
        });

        // Atualiza progresso
        const pct = Math.round((step / totalSteps) * 100);
        progressBar.style.width = pct + '%';
        progressPct.textContent = pct + '%';

        if (tips[step]) tipText.textContent = tips[step];

        // Rola suavemente ao topo no mobile
        if (window.innerWidth <= 768) {
            window.scrollTo({ top: 120, behavior: 'smooth' });
        }
    }

    // Botões Próximo / Voltar
    document.querySelectorAll('.btn-next-step').forEach(btn => {
        btn.addEventListener('click', function() {
            const target = parseInt(this.dataset.target);
            irParaPasso(target);
        });
    });

    document.querySelectorAll('.btn-prev-step').forEach(btn => {
        btn.addEventListener('click', function() {
            const target = parseInt(this.dataset.target);
            irParaPasso(target);
        });
    });

    // Clique direto nos Steps do topo
    stepButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            irParaPasso(parseInt(this.dataset.step));
        });
    });

    // ----------------------------------------------------
    // UPLOAD E PREVIEW DE IMAGEM
    // ----------------------------------------------------
    const dropzone = document.getElementById('upload-dropzone');
    const fileInput = document.getElementById('prod-imagem-input');
    const placeholder = document.getElementById('dropzone-placeholder');
    const previewBox = document.getElementById('dropzone-preview-box');
    const imgPreview = document.getElementById('img-preview');
    const liveImg = document.getElementById('live-preview-img');
    const livePlaceholder = document.getElementById('live-placeholder-icon');
    const btnTrocarFoto = document.getElementById('btn-trocar-foto');
    const btnRemoverFoto = document.getElementById('btn-remover-foto');

    placeholder.addEventListener('click', () => fileInput.click());
    if (btnTrocarFoto) btnTrocarFoto.addEventListener('click', () => fileInput.click());

    // Drag and Drop
    ['dragenter', 'dragover'].forEach(evt => {
        dropzone.addEventListener(evt, e => {
            e.preventDefault();
            dropzone.classList.add('dragover');
        });
    });

    ['dragleave', 'drop'].forEach(evt => {
        dropzone.addEventListener(evt, e => {
            e.preventDefault();
            dropzone.classList.remove('dragover');
        });
    });

    dropzone.addEventListener('drop', e => {
        if (e.dataTransfer.files && e.dataTransfer.files[0]) {
            fileInput.files = e.dataTransfer.files;
            handleFileSelect(e.dataTransfer.files[0]);
        }
    });

    fileInput.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            handleFileSelect(this.files[0]);
        }
    });

    function handleFileSelect(file) {
        if (!file.type.match('image.*')) {
            alert('Por favor, selecione um arquivo de imagem válido (JPG, PNG ou WEBP).');
            return;
        }
        const reader = new FileReader();
        reader.onload = function(e) {
            imgPreview.src = e.target.result;
            previewBox.style.display = 'flex';
            placeholder.style.display = 'none';

            // Atualiza live preview à direita
            liveImg.src = e.target.result;
            liveImg.style.display = 'block';
            livePlaceholder.style.display = 'none';
        };
        reader.readAsDataURL(file);
    }

    if (btnRemoverFoto) {
        btnRemoverFoto.addEventListener('click', function() {
            fileInput.value = '';
            imgPreview.src = '';
            previewBox.style.display = 'none';
            placeholder.style.display = 'flex';

            liveImg.src = '';
            liveImg.style.display = 'none';
            livePlaceholder.style.display = 'flex';
        });
    }

    // ----------------------------------------------------
    // ATUALIZAÇÃO EM TEMPO REAL DO LIVE PREVIEW
    // ----------------------------------------------------
    const inputNome = document.getElementById('prod-nome');
    const inputMarca = document.getElementById('prod-marca');
    const inputPreco = document.getElementById('prod-preco');
    const inputCusto = document.getElementById('prod-custo');
    const inputEstoque = document.getElementById('prod-estoque');
    const inputUnidade = document.getElementById('prod-unidade');

    const liveTitle = document.getElementById('live-card-title');
    const liveBrand = document.getElementById('live-card-brand');
    const livePrice = document.getElementById('live-price-val');
    const liveStock = document.getElementById('live-stock-val');
    const liveBadgeCat = document.getElementById('live-badge-cat');
    const liveCategoryIcon = document.getElementById('live-category-icon');
    const liveCrmAlert = document.getElementById('live-crm-alert');

    function syncLivePreview() {
        const nome = inputNome.value.trim();
        liveTitle.textContent = nome ? nome : 'Nome do Produto...';

        const marca = inputMarca.value.trim();
        liveBrand.textContent = marca ? marca : 'Marca / Fabricante';

        const preco = parseFloat(inputPreco.value) || 0;
        livePrice.textContent = 'R$ ' + preco.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        const estoque = parseInt(inputEstoque.value) || 0;
        const un = inputUnidade ? inputUnidade.value : 'un';
        liveStock.textContent = estoque + ' ' + un;

        // Calculadora de Margem de Lucro
        const custo = parseFloat(inputCusto.value) || 0;
        const calcLucro = document.getElementById('calc-lucro-bruto');
        const calcMargem = document.getElementById('calc-margem-pct');
        const calcStatus = document.getElementById('calc-margem-status');

        if (preco > 0) {
            const lucro = preco - custo;
            const margem = (lucro / preco) * 100;

            calcLucro.textContent = 'R$ ' + lucro.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            calcMargem.textContent = Math.round(margem) + '%';

            if (lucro > 0) {
                calcLucro.style.color = '#10b981';
                calcMargem.style.color = '#10b981';
                calcStatus.innerHTML = '<span style="color:#10b981;">✓ Margem positiva e saudável</span>';
            } else if (lucro === 0) {
                calcLucro.style.color = '#f59e0b';
                calcMargem.style.color = '#f59e0b';
                calcStatus.innerHTML = '<span style="color:#f59e0b;">Preço de venda igual ao custo</span>';
            } else {
                calcLucro.style.color = '#ef4444';
                calcMargem.style.color = '#ef4444';
                calcStatus.innerHTML = '<span style="color:#ef4444;">⚠️ Prejuízo: Preço abaixo do custo</span>';
            }
        }
    }

    [inputNome, inputMarca, inputPreco, inputCusto, inputEstoque, inputUnidade].forEach(el => {
        if (el) el.addEventListener('input', syncLivePreview);
    });

    // ----------------------------------------------------
    // CATEGORIAS & SELEÇÃO RÁPIDA
    // ----------------------------------------------------
    const catInput = document.getElementById('prod-categoria');
    const boxRacao = document.getElementById('box-racao-crm');
    const selectLinhaRacao = document.getElementById('prod-linha-racao');
    const boxCaloriasManual = document.getElementById('box-calorias-manual');
    const inputEnergia = document.getElementById('prod-energia');

    const catIcons = {
        'Ração': 'fa-bone',
        'Medicamento': 'fa-pills',
        'Vacina': 'fa-syringe',
        'Higiene & Estética': 'fa-shower',
        'Acessórios': 'fa-gem',
        'Geral': 'fa-box'
    };

    function selecionarCategoria(cat) {
        catInput.value = cat;
        liveBadgeCat.textContent = cat;

        // Atualiza ícone do preview
        if (catIcons[cat]) {
            liveCategoryIcon.className = 'fas ' + catIcons[cat];
        }

        // Alterna box de CRM de ração
        if (cat === 'Ração') {
            boxRacao.style.display = 'block';
            liveCrmAlert.style.display = 'flex';
        } else {
            boxRacao.style.display = 'none';
            liveCrmAlert.style.display = 'none';
        }

        document.querySelectorAll('.category-pill-card').forEach(card => {
            if (card.dataset.cat === cat) {
                card.classList.add('active');
            } else {
                card.classList.remove('active');
            }
        });
    }

    document.querySelectorAll('.category-pill-card').forEach(card => {
        card.addEventListener('click', function() {
            selecionarCategoria(this.dataset.cat);
        });
    });

    // Chips de peso de 1 clique
    document.querySelectorAll('.btn-chip-peso').forEach(btn => {
        btn.addEventListener('click', function() {
            const kg = this.dataset.kg;
            document.getElementById('prod-peso').value = kg;
            document.querySelectorAll('.btn-chip-peso').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
        });
    });

    // Linha de ração & calorias automáticas
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

    // Gerador de Código EAN Aleatório
    const btnGerarEan = document.getElementById('btn-gerar-ean');
    if (btnGerarEan) {
        btnGerarEan.addEventListener('click', function() {
            const randomEan = '789' + Math.floor(1000000000 + Math.random() * 9000000000);
            document.getElementById('prod-codigo').value = randomEan;
        });
    }

    // Alerta inteligente de validade
    const inputValidade = document.getElementById('prod-validade');
    const boxAlertaValidade = document.getElementById('validade-alerta-box');

    if (inputValidade) {
        inputValidade.addEventListener('change', function() {
            if (!this.value) {
                boxAlertaValidade.style.display = 'none';
                return;
            }
            const valDate = new Date(this.value);
            const hoje = new Date();
            hoje.setHours(0,0,0,0);

            const diffTime = valDate - hoje;
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

            if (diffDays < 0) {
                boxAlertaValidade.style.display = 'block';
                boxAlertaValidade.style.background = 'rgba(239, 68, 68, 0.15)';
                boxAlertaValidade.style.color = '#ef4444';
                boxAlertaValidade.style.border = '1px solid #ef4444';
                boxAlertaValidade.innerHTML = '<i class="fas fa-exclamation-circle"></i> <strong>Atenção:</strong> A data de validade informada já expirou!';
            } else if (diffDays <= 30) {
                boxAlertaValidade.style.display = 'block';
                boxAlertaValidade.style.background = 'rgba(245, 158, 11, 0.15)';
                boxAlertaValidade.style.color = '#f59e0b';
                boxAlertaValidade.style.border = '1px solid #f59e0b';
                boxAlertaValidade.innerHTML = `<i class="fas fa-exclamation-triangle"></i> <strong>Aviso:</strong> Este lote vencerá em ${diffDays} dias.`;
            } else {
                boxAlertaValidade.style.display = 'block';
                boxAlertaValidade.style.background = 'rgba(16, 185, 129, 0.15)';
                boxAlertaValidade.style.color = '#10b981';
                boxAlertaValidade.style.border = '1px solid #10b981';
                boxAlertaValidade.innerHTML = `<i class="fas fa-check-circle"></i> Validade regular (vence em ${diffDays} dias).`;
            }
        });
    }

    // ----------------------------------------------------
    // ENVIO FINAL DO FORMULÁRIO (SALVAR VIA AJAX COM FOTO)
    // ----------------------------------------------------
    form.addEventListener('submit', function(e) {
        e.preventDefault();

        const btnSave = document.getElementById('btn-salvar-funil');
        btnSave.disabled = true;
        btnSave.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gravando...';

        const formData = new FormData(form);

        fetch('api_salvar_produto.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                // Toast ou redirecionamento de sucesso
                btnSave.innerHTML = '<i class="fas fa-check"></i> Gravado!';
                setTimeout(() => {
                    window.location.href = 'produtos.php?msg=' + encodeURIComponent(data.message);
                }, 400);
            } else {
                alert('Erro: ' + (data.message || 'Falha ao salvar produto.'));
                btnSave.disabled = false;
                btnSave.innerHTML = '<i class="fas fa-save"></i> Salvar Produto no Estoque';
            }
        })
        .catch(err => {
            console.error(err);
            alert('Erro de comunicação com o servidor.');
            btnSave.disabled = false;
            btnSave.innerHTML = '<i class="fas fa-save"></i> Salvar Produto no Estoque';
        });
    });

    // Inicialização com valores já preenchidos (se edição)
    selecionarCategoria(catInput.value || 'Geral');
    syncLivePreview();
});
</script>

<?php include 'includes/_footer.php'; ?>
