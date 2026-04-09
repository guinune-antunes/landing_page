<?php
    // PARTE 1: LÓGICA PHP COMPLETA (CONEXÃO E BUSCA)
    ini_set('display_errors', 1);
    error_reporting(E_ALL);

    // --- CONEXÃO COM O BANCO DE DADOS (ANTIGO conexao.php) ---
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'petcrm');
    define('DB_USER', 'root');
    define('DB_PASS', '');

    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        die("ERRO DE CONEXÃO COM O BANCO DE DADOS: " . $e->getMessage());
    }

    // --- BUSCA DOS CLIENTES ---
    $sql = "SELECT c.*, COUNT(p.id) AS total_pets FROM clientes AS c LEFT JOIN pets AS p ON c.id = p.cliente_id GROUP BY c.id ORDER BY c.nome_completo ASC";
    $stmt = $pdo->query($sql);
    $clientes = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PÁGINA DE TESTE - Clientes</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">

    <style>
        /* COLOQUE AQUI TODO O CONTEÚDO DO SEU ARQUIVO style.css */
        /* Exemplo: */
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background-color: #f4f7fa; color: #333; }
        .dashboard-container { display: flex; }
        .sidebar { width: 260px; background-color: #2c3e50; color: #ecf0f1; height: 100vh; position: fixed; display: flex; flex-direction: column; z-index: 1000;}
        .sidebar-header { padding: 20px; font-size: 1.5rem; font-weight: 600; text-align: center; border-bottom: 1px solid #34495e; }
        .sidebar-nav { display: flex; flex-direction: column; flex-grow: 1; padding-top: 20px; }
        .nav-item { color: #ecf0f1; text-decoration: none; padding: 15px 25px; display: block; transition: background-color 0.3s ease; }
        .nav-item.active { background-color: #1abc9c; }
        .nav-item i { margin-right: 15px; width: 20px; text-align: center; }
        .main-area { margin-left: 260px; width: calc(100% - 260px); display: flex; flex-direction: column; position: relative;}
        .content-wrapper { width: 100%; display: flex; flex-direction: column; flex-grow: 1; }
        .header { background-color: #ffffff; padding: 15px 30px; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 999;}
        .main-content { padding: 30px; flex-grow: 1; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .main-title { font-size: 1.8rem; margin: 0; }
        .page-actions { display: flex; gap: 15px; align-items: center; }
        .btn { padding: 8px 16px; border: none; border-radius: 6px; font-size: 0.9rem; font-weight: 500; cursor: pointer; }
        .btn-primary { background-color: #1abc9c; color: #fff; }
        .table-container { background-color: #fff; border-radius: 8px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .client-table { width: 100%; border-collapse: collapse; }
        /* ... e assim por diante com todo o resto do seu CSS ... */
    </style>
</head>
<body>
    <div class="dashboard-container">
        <aside class="sidebar">
            <div class="sidebar-header"><i class="fas fa-paw"></i><span>PetCRM</span></div>
            <nav class="sidebar-nav">
                <a href="#" class="nav-item">Dashboard</a>
                <a href="#" class="nav-item">Agenda</a>
                <a href="#" class="nav-item active">Clientes</a>
                </nav>
        </aside>

        <div class="main-area">
            <div class="content-wrapper">
                <header class="header">
                    <div class="page-actions"><h1 class="main-title">PÁGINA DE DEBUG</h1></div>
                    <button class="btn" id="add-client-btn">Testar Botão</button>
                </header>
                <main class="main-content">
                    <div class="table-container">
                        <table class="client-table">
                            <thead><tr><th>Nome</th><th>Email</th><th>Pets</th></tr></thead>
                            <tbody>
                                <?php foreach ($clientes as $cliente): ?>
                                <tr>
                                    <td><?= htmlspecialchars($cliente['nome_completo']) ?></td>
                                    <td><?= htmlspecialchars($cliente['email']) ?></td>
                                    <td><?= $cliente['total_pets'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </main>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Teste simples do botão
            const testButton = document.getElementById('add-client-btn');
            if(testButton) {
                testButton.addEventListener('click', function() {
                    alert('O JavaScript está funcionando!');
                });
            }
        });
    </script>
</body>
</html>