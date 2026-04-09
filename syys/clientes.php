<?php
// Define o título da página e qual item do menu deve ficar ativo
$pageTitle = 'Clientes';
$paginaAtiva = 'clientes';

// Inclui o cabeçalho do HTML
include 'includes/_head.php';
require 'includes/verifica_login.php'; // Garante que está logado

// --- LÓGICA DE BUSCA DE DADOS (FILTRADA POR INSTITUIÇÃO) ---
require 'conexao.php'; // Usa $pdo

// 1. Pega o ID da instituição da sessão do usuário
$instituicao_id = $_SESSION['instituicao_id'];

// 2. Busca APENAS os clientes desta instituição
$sql = "SELECT * FROM clientes WHERE instituicao_id = ? ORDER BY nome_completo";
$stmt_clientes = $pdo->prepare($sql);
$stmt_clientes->execute([$instituicao_id]);
$clientes = $stmt_clientes->fetchAll();
?>

<?php include 'includes/_sidebar.php'; // Inclui a barra lateral de navegação ?>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; // Inclui o cabeçalho superior ?>

        <main class="main-content">
            <div class="page-header">
                <h1 class="main-title">Clientes</h1>
                <div class="page-controls">
                    <div class="search-container">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Buscar cliente...">
                    </div>
                    <button class="btn btn-primary" id="add-client-btn">
                        <i class="fas fa-plus"></i> Adicionar Cliente
                    </button>
                </div>
            </div>

            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Telefone</th>
                            <th>Email</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody id="clientTableBody">
                        <?php if (count($clientes) > 0): ?>
                            <?php foreach ($clientes as $cliente): ?>
                                <tr data-id="<?php echo $cliente['id']; ?>">
                                    <td><?php echo htmlspecialchars($cliente['nome_completo']); ?></td>
                                    <td><?php echo htmlspecialchars($cliente['telefone']); ?></td>
                                    <td><?php echo htmlspecialchars($cliente['email']); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-icon btn-edit" data-id="<?php echo $cliente['id']; ?>" title="Editar">
                                                <i class="fas fa-pencil-alt"></i>
                                            </button>
                                            <button class="btn-icon btn-delete" data-id="<?php echo $cliente['id']; ?>" title="Excluir">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4">Nenhum cliente cadastrado nesta loja.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </main>
    </div>
</div>

<div class="modal-overlay" id="client-modal" style="display: none;">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h2 id="client-modal-title">Novo Cliente</h2>
            <button class="close-modal-btn">&times;</button>
        </div>
        
        <div class="modal-body">
            
            <form id="client-form" class="client-form-grid">
                <h3 class="form-section-title">Dados do Tutor</h3>
                
                <div class="form-group span-2">
                    <label for="client-name">Nome Completo *</label>
                    <input type="text" id="client-name" required>
                </div>
                
                <div class="form-group span-2">
                    <label for="client-cpf">CPF</label>
                    <input type="text" id="client-cpf" placeholder="000.000.000-00">
                </div>

                <div class="form-group span-2">
                    <label for="client-phone">Telefone *</label>
                    <input type="tel" id="client-phone" required placeholder="(00) 00000-0000">
                </div>
                <div class="form-group span-4">
                    <label for="client-email">Email</label>
                    <input type="email" id="client-email" name="client-email">
                </div>
                
                <h3 class="form-section-title">Endereço</h3>
                
                <div class="form-group span-2">
                    <label for="client-cep">CEP</label>
                    <input type="text" id="client-cep" name="client-cep">
                </div>
                <div class="form-group span-2">
                    <label for="client-street">Logradouro (Rua/Av)</label>
                    <input type="text" id="client-street" name="client-street">
                </div>
                <div class="form-group span-1">
                    <label for="client-number">Número</label>
                    <input type="text" id="client-number" name="client-number">
                </div>
                <div class="form-group span-3">
                    <label for="client-neighborhood">Bairro</label>
                    <input type="text" id="client-neighborhood" name="client-neighborhood">
                </div>
                <div class="form-group span-3">
                    <label for="client-city">Cidade</label>
                    <input type="text" id="client-city" name="client-city">
                </div>
                <div class="form-group span-1">
                    <label for="client-state">Estado (UF)</label>
                    <input type="text" id="client-state" name="client-state" maxlength="2">
                </div>
            </form>

            <div class="pet-section">
                <h3 class="form-section-title">Pets</h3>
                <form id="pet-form" class="pet-form-grid">
                    <div class="form-group">
                        <label for="pet-name">Nome do Pet *</label>
                        <input type="text" id="pet-name" name="pet-name">
                    </div>
                    <div class="form-group">
                        <label for="pet-species">Espécie</label>
                        <select id="pet-species" name="pet-species">
                            <option value="">Selecione...</option>
                            <option value="Cão">Cão</option>
                            <option value="Gato">Gato</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="pet-breed">Raça</label>
                        <select id="pet-breed" name="pet-breed" disabled>
                            <option value="">Selecione a espécie</option>
                        </select>
                    </div>
                     <div class="form-group">
                        <label for="pet-birthdate">Nascimento</label>
                        <input type="date" id="pet-birthdate" name="pet-birthdate">
                    </div>
                    <button type="submit" class="btn btn-secondary btn-add-pet">Adicionar Pet</button>
                </form>

                <ul class="pet-list-container" id="pet-list">
                </ul>
            </div>

        </div>
        
        <div class="modal-footer">
            <button class="btn btn-secondary cancel-btn">Cancelar</button>
            <button class="btn btn-primary" id="saveClientBtn">Salvar Cliente</button>
        </div>
    </div>
</div>

<?php 
// Inclui o final do HTML (onde o main.js é chamado)
include 'includes/_footer.php'; 
?>