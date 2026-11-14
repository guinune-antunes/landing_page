<?php
require 'includes/verifica_login.php';

// Impede que o Super Admin acesse o painel de clientes do Pet Shop
if ($_SESSION['usuario_cargo'] === 'Admin') {
    header("Location: super_admin_clientes.php");
    exit;
}
?>
<?php
// Define o título da página e qual item do menu deve ficar ativo
$pageTitle = 'Clientes';
$paginaAtiva = 'clientes';

// Inclui o cabeçalho do HTML
include 'includes/_head.php';
require 'includes/verifica_login.php'; // Garante que está logado

// --- LÓGICA DE BUSCA DE DADOS (FILTRADA POR INSTITUIÇÃO) ---
require 'conexao.php'; // Usa $pdo
require_once 'includes/unidades.php';

// 1. Pega o ID da instituição da sessão do usuário
$instituicao_id = (int)$_SESSION['instituicao_id'];

// 2. Busca paginada de clientes (suporta alto volume: 10k+ clientes)
$ids_clientes = obterInstituicoesClientes($pdo, $instituicao_id);
$in_cli = implode(',', array_fill(0, count($ids_clientes), '?'));
$busca = trim($_GET['busca'] ?? '');
$paginaAtual = max(1, (int)($_GET['p'] ?? 1));
$porPagina = 50;

$whereSql = "c.instituicao_id IN ($in_cli)";
$paramsBase = $ids_clientes;
if ($busca !== '') {
    $likeBusca = '%' . $busca . '%';
    $whereSql .= " AND (c.nome_completo LIKE ? OR c.telefone LIKE ? OR c.cpf LIKE ? OR c.email LIKE ?
                   OR EXISTS (SELECT 1 FROM pets px WHERE px.cliente_id = c.id AND px.nome LIKE ?))";
    array_push($paramsBase, $likeBusca, $likeBusca, $likeBusca, $likeBusca, $likeBusca);
}

$stTotalCli = $pdo->prepare("SELECT COUNT(*) FROM clientes c WHERE {$whereSql}");
$stTotalCli->execute($paramsBase);
$totalClientesFiltrados = (int)$stTotalCli->fetchColumn();
$totalPaginas = max(1, (int)ceil($totalClientesFiltrados / $porPagina));
if ($paginaAtual > $totalPaginas) {
    $paginaAtual = $totalPaginas;
}
$offset = ($paginaAtual - 1) * $porPagina;

$sql = "SELECT c.*, i.nome_unidade, i.nome_fantasia, i.cidade, i.uf 
        FROM clientes c
        LEFT JOIN instituicoes i ON i.id = c.instituicao_id
        WHERE {$whereSql} 
        ORDER BY c.nome_completo ASC
        LIMIT {$porPagina} OFFSET {$offset}";
$stmt_clientes = $pdo->prepare($sql);
$stmt_clientes->execute($paramsBase);
$clientes = $stmt_clientes->fetchAll();
?>

<?php include 'includes/_sidebar.php'; // Inclui a barra lateral de navegação ?>

<div class="main-area">
    <div class="content-wrapper">
        <?php include 'includes/_header.php'; // Inclui o cabeçalho superior ?>

        <main class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="main-title" style="margin-bottom:4px;">Clientes</h1>
                    <small style="color:var(--text-muted);font-weight:600;">
                        <?php echo number_format($totalClientesFiltrados, 0, ',', '.'); ?> tutor(es) encontrado(s)
                        <?php if ($busca !== ''): ?>
                            para "<?php echo htmlspecialchars($busca); ?>" · <a href="clientes.php" style="color:#0284c7;">Limpar filtro</a>
                        <?php endif; ?>
                    </small>
                </div>
                <div class="page-controls">
                    <div class="search-container">
                        <i class="fas fa-search"></i>
                        <select id="searchInput" data-no-auto-select2="1" data-placeholder="Buscar cliente, WhatsApp, CPF ou pet..." data-s2-allow-clear="1" data-s2-tags="1" style="width: 300px;">
                            <option value=""></option>
                            <?php if ($busca !== ''): ?>
                                <option value="<?php echo htmlspecialchars($busca); ?>" selected><?php echo htmlspecialchars($busca); ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <a href="relatorio_crm.php" class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;">
                        <i class="fas fa-address-card"></i> Relatório CRM
                    </a>
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
                                    <td>
                                        <strong><?php echo htmlspecialchars($cliente['nome_completo']); ?></strong>
                                        <?php if (count($ids_clientes) > 1): 
                                            $unidadeBadge = trim(($cliente['nome_unidade'] ?? '') ?: ($cliente['cidade'] ?? ''));
                                            if ($unidadeBadge !== ''):
                                        ?>
                                            <span style="background: #eef2f7; color: #4a5568; font-size: 0.75rem; padding: 2px 7px; border-radius: 4px; margin-left: 6px; font-weight: normal;">
                                                <i class="fas fa-store"></i> <?php echo htmlspecialchars($unidadeBadge); ?>
                                            </span>
                                        <?php endif; endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($cliente['telefone']); ?></td>
                                    <td><?php echo htmlspecialchars($cliente['email']); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="relatorio_crm.php?cliente_id=<?php echo (int)$cliente['id']; ?>" class="btn-icon" style="color:#0284c7;text-decoration:none;" title="Relatório CRM 360° & Histórico">
                                                <i class="fas fa-chart-pie"></i>
                                            </a>
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

            <?php if ($totalPaginas > 1):
                $qsBusca = $busca !== '' ? '&busca=' . urlencode($busca) : '';
                $inicioEx = $totalClientesFiltrados > 0 ? ($offset + 1) : 0;
                $fimEx = min($totalClientesFiltrados, $offset + count($clientes));
            ?>
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-top:16px;padding:12px 16px;background:var(--bg-widget);border:1px solid var(--border-color);border-radius:10px;">
                <small style="color:var(--text-muted);font-weight:600;">
                    Exibindo <?php echo number_format($inicioEx, 0, ',', '.'); ?>–<?php echo number_format($fimEx, 0, ',', '.'); ?> de <?php echo number_format($totalClientesFiltrados, 0, ',', '.'); ?> clientes (Página <?php echo $paginaAtual; ?> de <?php echo number_format($totalPaginas, 0, ',', '.'); ?>)
                </small>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <?php if ($paginaAtual > 1): ?>
                        <a href="clientes.php?p=1<?php echo $qsBusca; ?>" class="btn btn-secondary" style="padding:6px 10px;font-size:0.82rem;text-decoration:none;">« Primeira</a>
                        <a href="clientes.php?p=<?php echo $paginaAtual - 1; ?><?php echo $qsBusca; ?>" class="btn btn-secondary" style="padding:6px 10px;font-size:0.82rem;text-decoration:none;">‹ Anterior</a>
                    <?php endif; ?>
                    <?php if ($paginaAtual < $totalPaginas): ?>
                        <a href="clientes.php?p=<?php echo $paginaAtual + 1; ?><?php echo $qsBusca; ?>" class="btn btn-primary" style="padding:6px 12px;font-size:0.82rem;text-decoration:none;">Próxima ›</a>
                        <a href="clientes.php?p=<?php echo $totalPaginas; ?><?php echo $qsBusca; ?>" class="btn btn-secondary" style="padding:6px 10px;font-size:0.82rem;text-decoration:none;">Última »</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

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