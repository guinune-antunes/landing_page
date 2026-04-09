<?php
// Define o título da página e qual item do menu deve ficar ativo
$pageTitle = 'Estoque';
$paginaAtiva = 'estoque';

// Inclui o cabeçalho do HTML
include 'includes/_head.php';

// --- LÓGICA DE BUSCA DE DADOS ---
require 'conexao.php'; // Usa $pdo

// Pega os dados dos produtos para a tabela
$stmt_produtos = $pdo->query("SELECT * FROM produtos ORDER BY nome");
$produtos = $stmt_produtos->fetchAll();
?>

<?php include 'includes/_sidebar.php'; // Inclui a barra lateral de navegação ?>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; // Inclui o cabeçalho superior ?>

        <main class="main-content">
            <div class="page-header">
                <h1 class="main-title">Estoque de Produtos</h1>
                <div class="page-controls">
                    <div class="search-container">
                        <i class="fas fa-search"></i>
                        <input type="text" id="productSearchInput" placeholder="Buscar produto...">
                    </div>
                    <button class="btn btn-primary" id="add-product-btn">
                        <i class="fas fa-plus"></i> Adicionar Produto
                    </button>
                </div>
            </div>

            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Marca</th>
                            <th>Estoque</th>
                            <th>Data de Chegada</th>
                            <th>Data de Vencimento</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody id="productTableBody">
                        <?php if (count($produtos) > 0): ?>
                            <?php foreach ($produtos as $produto): ?>
                                <tr data-id="<?php echo $produto['id']; ?>">
                                    <td><?php echo htmlspecialchars($produto['nome']); ?></td>
                                    <td><?php echo htmlspecialchars($produto['marca']); ?></td>
                                    <td><?php echo $produto['quantidade'] . ' ' . $produto['unidade']; ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($produto['data_chegada'])); ?></td>
                                    <td>
                                        <?php 
                                        if (!empty($produto['data_vencimento'])) {
                                            echo date('d/m/Y', strtotime($produto['data_vencimento']));
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-icon btn-edit" data-id="<?php echo $produto['id']; ?>" title="Editar">
                                                <i class="fas fa-pencil-alt"></i>
                                            </button>
                                            <button class="btn-icon btn-delete" data-id="<?php echo $produto['id']; ?>" title="Excluir">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6">Nenhum produto cadastrado no estoque.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </main>
    </div>
</div>

<div class="modal-overlay" id="product-modal" style="display: none;">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h2 id="product-modal-title">Novo Produto</h2>
            <button class="close-modal-btn">&times;</button>
        </div>
        
        <div class="modal-body">
            <form id="product-form" class="client-form-grid"> <div class="form-group span-3">
                    <label for="product-nome">Nome do Produto *</label>
                    <input type="text" id="product-nome" required>
                </div>
                <div class="form-group span-1">
                    <label for="product-marca">Marca</label>
                    <input type="text" id="product-marca">
                </div>
                
                <div class="form-group span-4">
                    <label for="product-descricao">Descrição (Opcional)</label>
                    <textarea id="product-descricao" rows="3"></textarea>
                </div>
                <div class="form-group span-2">
                    <label for="product-quantidade">Quantidade *</label>
                    <input type="number" id="product-quantidade" step="0.01" required>
                </div>
                <div class="form-group span-2">
                    <label for="product-unidade">Unidade (Peso/Qtd) *</label>
                    <select id="product-unidade" required>
                        <option value="">Selecione...</option>
                        <option value="un">Unidade (un)</option>
                        <option value="kg">Quilograma (kg)</option>
                        <option value="g">Grama (g)</option>
                        <option value="L">Litro (L)</option>
                        <option value="mL">Mililitro (mL)</option>
                        <option value="pacote">Pacote (pct)</option>
                    </select>
                </div>

                <div class="form-group span-2">
                    <label for="product-chegada">Data de Chegada *</label>
                    <input type="date" id="product-chegada" required>
                </div>
                <div class="form-group span-2">
                    <label for="product-vencimento">Data de Vencimento (Opcional)</label>
                    <input type="date" id="product-vencimento">
                </div>
            </form>
        </div>
        
        <div class="modal-footer">
            <button class="btn btn-secondary cancel-btn">Cancelar</button>
            <button class="btn btn-primary" id="saveProductBtn">Salvar Produto</button>
        </div>
    </div>
</div>

<?php 
// Inclui o final do HTML (onde o main.js é chamado)
include 'includes/_footer.php'; 
?>