<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teste de Isolamento do Modal</title>
    <style>
        /* CSS MÍNIMO PARA O TESTE FUNCIONAR */
        body { font-family: sans-serif; padding: 20px; }
        .btn { padding: 10px 15px; cursor: pointer; }
        .modal-overlay {
            display: none; /* Começa escondido */
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background-color: rgba(0,0,0,0.6);
            justify-content: center;
            align-items: center;
        }
        .modal-content { background-color: white; padding: 20px; border-radius: 8px; width: 500px; }
        .modal-header { display: flex; justify-content: space-between; border-bottom: 1px solid #ccc; padding-bottom: 10px; margin-bottom: 10px; }
        .close-modal-btn { background: none; border: none; font-size: 1.5rem; cursor: pointer; }
        .modal-footer { border-top: 1px solid #ccc; padding-top: 10px; margin-top: 10px; text-align: right; }
    </style>
</head>
<body>

    <h1>Página de Teste de Isolamento</h1>
    <p>O único propósito desta página é testar o botão e o modal.</p>
    
    <button class="btn" id="add-client-btn">Abrir Modal de Cliente</button>

    <div class="modal-overlay" id="client-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Formulário de Teste</h2>
                <button class="close-modal-btn">&times;</button>
            </div>
            <div class="modal-body">
                <p>Este é o conteúdo do modal.</p>
            </div>
            <div class="modal-footer">
                <button class="btn cancel-btn">Cancelar</button>
                <button class="btn">Salvar</button>
            </div>
        </div>
    </div>

    <script>
        // Espera o HTML carregar
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Script iniciado. Procurando elementos...");

            // Pega os 4 elementos cruciais
            const addClientBtn = document.getElementById('add-client-btn');
            const clientModal = document.getElementById('client-modal');
            // IMPORTANTE: Busca os botões DENTRO do elemento do modal
            const closeModalBtn = clientModal.querySelector('.close-modal-btn');
            const cancelBtn = clientModal.querySelector('.cancel-btn');

            // Imprime no console o que foi encontrado
            console.log("Botão 'Abrir Modal' encontrado:", addClientBtn);
            console.log("Div do Modal encontrada:", clientModal);
            console.log("Botão 'X' encontrado:", closeModalBtn);
            console.log("Botão 'Cancelar' encontrado:", cancelBtn);

            // Adiciona os eventos de clique
            if (addClientBtn && clientModal) {
                addClientBtn.addEventListener('click', () => {
                    console.log("Botão 'Abrir Modal' clicado!");
                    clientModal.style.display = 'flex';
                });
            }

            if (closeModalBtn && clientModal) {
                closeModalBtn.addEventListener('click', () => {
                    console.log("Botão 'X' clicado!");
                    clientModal.style.display = 'none';
                });
            }
            
            if (cancelBtn && clientModal) {
                cancelBtn.addEventListener('click', () => {
                    console.log("Botão 'Cancelar' clicado!");
                    clientModal.style.display = 'none';
                });
            }

            console.log("Script finalizado.");
        });
    </script>

</body>
</html>