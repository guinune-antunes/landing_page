document.addEventListener('DOMContentLoaded', function() {
    console.log("Main.js carregado com sucesso.");

    // ===================================================
    // 1. LÓGICA DO MODAL DA AGENDA
    // ===================================================
    const addAppointmentBtn = document.getElementById('add-appointment-btn');
    const appointmentModal = document.getElementById('appointment-modal');
    
    if (addAppointmentBtn && appointmentModal) {
        console.log("Agenda detectada.");

        const calendarGrid = document.querySelector('.calendar-grid');
        const modalTitle = appointmentModal.querySelector('.modal-header h2');
        const closeModalBtn = appointmentModal.querySelector('.close-modal-btn');
        const cancelBtn = document.getElementById('cancel-app-btn');
        const saveBtn = document.getElementById('save-app-btn');
        
        // Campos do Formulário
        const clientSelect = document.getElementById('app-client');
        const petSelect = document.getElementById('app-pet');
        const appointmentForm = document.getElementById('appointment-form-content');

        let modoModal = 'novo';
        let idAgendamentoAntigo = null;

        // --- Função para ABRIR modal (NOVO) ---
        function openModalNovo() {
            modoModal = 'novo';
            idAgendamentoAntigo = null;
            modalTitle.textContent = 'Novo Agendamento';
            
            if(appointmentForm) appointmentForm.reset(); 
            
            if(petSelect) {
                petSelect.innerHTML = '<option value="">Selecione um cliente primeiro</option>';
                petSelect.disabled = true;
            }
            appointmentModal.style.display = 'flex';
        }

        // --- Função para ABRIR modal (REMARCAR) ---
        function openModalRemarcar(card) {
            modoModal = 'remarcar';
            const dados = card.dataset;
            idAgendamentoAntigo = dados.id;
            modalTitle.textContent = 'Editar / Remarcar Agendamento';

            if(clientSelect) clientSelect.value = dados.clienteId;
            
            // Preenche os campos se existirem
            if(document.getElementById('app-service')) document.getElementById('app-service').value = dados.servico;
            if(document.getElementById('app-date')) document.getElementById('app-date').value = dados.date;
            if(document.getElementById('app-time')) document.getElementById('app-time').value = dados.time;
            if(document.getElementById('app-professional')) document.getElementById('app-professional').value = dados.profissional;
            if(document.getElementById('app-notes')) document.getElementById('app-notes').value = dados.obs;
            
            if(petSelect) {
                petSelect.innerHTML = '<option value="">Carregando pets...</option>';
                petSelect.disabled = true;

                fetch(`buscar_pets_cliente.php?cliente_id=${dados.clienteId}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.success && data.pets.length > 0) {
                            petSelect.innerHTML = '<option value="">Selecione um pet...</option>';
                            data.pets.forEach(pet => {
                                const option = document.createElement('option');
                                option.value = pet.id;
                                option.textContent = pet.nome;
                                petSelect.appendChild(option);
                            });
                            petSelect.value = dados.petId; 
                            petSelect.disabled = false;
                        } else {
                            petSelect.innerHTML = '<option value="">Cliente sem pets</option>';
                        }
                    })
                    .catch(err => petSelect.innerHTML = '<option value="">Erro ao buscar pets</option>');
            }

            appointmentModal.style.display = 'flex';
        }

        function closeModal() { appointmentModal.style.display = 'none'; }

        // Eventos de Abrir/Fechar
        addAppointmentBtn.addEventListener('click', openModalNovo);
        if(closeModalBtn) closeModalBtn.addEventListener('click', closeModal);
        if(cancelBtn) cancelBtn.addEventListener('click', closeModal);
        
        // Clique no Card (Remarcar) - Delegação de Eventos
        if (calendarGrid) {
            calendarGrid.addEventListener('click', function(e) {
                // Procura o card mais próximo do clique (pois pode clicar no texto dentro do card)
                const card = e.target.closest('.appointment-card');
                
                // Se achou um card e ele NÃO é "remarcado" (transparente)
                if (card && !card.classList.contains('status-remarcado')) {
                    openModalRemarcar(card);
                }
            });
        }

        // Buscar Pets ao trocar Cliente
        if(clientSelect) {
            clientSelect.addEventListener('change', function() {
                const clienteId = this.value;
                petSelect.innerHTML = '<option value="">Carregando...</option>';
                petSelect.disabled = true;

                if (!clienteId) {
                    petSelect.innerHTML = '<option value="">Selecione um cliente primeiro</option>';
                    return;
                }

                fetch(`buscar_pets_cliente.php?cliente_id=${clienteId}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.success && data.pets.length > 0) {
                            petSelect.innerHTML = '<option value="">Selecione um pet...</option>';
                            data.pets.forEach(pet => {
                                const option = document.createElement('option');
                                option.value = pet.id;
                                option.textContent = pet.nome;
                                petSelect.appendChild(option);
                            });
                            petSelect.disabled = false;
                        } else {
                            petSelect.innerHTML = '<option value="">Este cliente não tem pets</option>';
                        }
                    })
                    .catch(err => { console.error(err); petSelect.innerHTML = '<option value="">Erro de conexão</option>'; });
            });
        }

        // Salvar Agendamento
        if(saveBtn) {
            saveBtn.addEventListener('click', function() {
                const dadosFormulario = {
                    cliente_id: clientSelect.value,
                    pet_id: petSelect.value,
                    servico: document.getElementById('app-service').value,
                    date: document.getElementById('app-date').value,
                    time: document.getElementById('app-time').value,
                    profissional: document.getElementById('app-professional').value,
                    observacoes: document.getElementById('app-notes').value
                };

                if (!dadosFormulario.cliente_id || !dadosFormulario.pet_id || !dadosFormulario.servico || !dadosFormulario.date || !dadosFormulario.time) {
                    alert('Por favor, preencha os campos obrigatórios (Cliente, Pet, Serviço, Data, Hora).'); return;
                }
                
                let url = (modoModal === 'remarcar') ? 'remarcar_agendamento.php' : 'salvar_agendamento.php';
                let body = (modoModal === 'remarcar') ? { id_antigo: idAgendamentoAntigo, novos_dados: dadosFormulario } : dadosFormulario;

                fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                })
                .then(res => res.json())
                .then(result => {
                    alert(result.message);
                    if (result.success) { closeModal(); location.reload(); }
                })
                .catch(err => { console.error(err); alert('Erro de comunicação.'); });
            });
        }
    } 


    // ===================================================
    // 2. LÓGICA DO ESTOQUE (PRODUTOS)
    // ===================================================
    const addProductBtn = document.getElementById('add-product-btn');
    if (addProductBtn) { 
        const productModal = document.getElementById('product-modal');
        const productTableBody = document.getElementById('productTableBody');
        const productSearchInput = document.getElementById('productSearchInput');
        
        if (productModal && productTableBody) {
            const productForm = document.getElementById('product-form');
            const saveProductBtn = document.getElementById('saveProductBtn');
            const modalTitle = document.getElementById('product-modal-title');
            let editingProductId = null;

            const openModal = () => {
                editingProductId = null;
                modalTitle.textContent = 'Novo Produto';
                productForm.reset();
                productModal.style.display = 'flex';
            };
            const closeModal = () => { productModal.style.display = 'none'; };

            addProductBtn.addEventListener('click', openModal);
            productModal.querySelector('.close-modal-btn').addEventListener('click', closeModal);
            productModal.querySelector('.cancel-btn').addEventListener('click', closeModal);

            saveProductBtn.addEventListener('click', function() {
                const produtoData = {
                    id: editingProductId,
                    nome: document.getElementById('product-nome').value.trim(),
                    marca: document.getElementById('product-marca').value.trim(),
                    quantidade: document.getElementById('product-quantidade').value,
                    unidade: document.getElementById('product-unidade').value,
                    data_chegada: document.getElementById('product-chegada').value,
                    data_vencimento: document.getElementById('product-vencimento').value,
                    descricao: document.getElementById('product-descricao').value.trim()
                };

                if (!produtoData.nome || !produtoData.quantidade || !produtoData.unidade || !produtoData.data_chegada) {
                    alert('Por favor, preencha os campos obrigatórios (*).'); return;
                }

                fetch('salvar_produto.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(produtoData)
                })
                .then(res => res.json())
                .then(result => {
                    alert(result.message);
                    if (result.success) { location.reload(); }
                }).catch(err => { console.error(err); alert('Erro de comunicação.'); });
            });
            
            productTableBody.addEventListener('click', function(event) {
                const targetButton = event.target.closest('button');
                if (!targetButton) return;
                const productId = targetButton.dataset.id;

                if (targetButton.classList.contains('btn-delete')) {
                    if (confirm(`Tem certeza que deseja excluir o produto ID ${productId}?`)) {
                        fetch('excluir_produto.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ id: productId })
                        })
                        .then(response => response.json())
                        .then(result => {
                            alert(result.message);
                            if (result.success) location.reload();
                        })
                        .catch(error => { console.error(error); alert('Erro de comunicação.'); });
                    }
                }

                if (targetButton.classList.contains('btn-edit')) {
                    fetch(`buscar_produto.php?id=${productId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.produto) {
                            const p = data.produto;
                            editingProductId = p.id;
                            modalTitle.textContent = 'Editando Produto';
                            document.getElementById('product-nome').value = p.nome;
                            document.getElementById('product-marca').value = p.marca;
                            document.getElementById('product-descricao').value = p.descricao || '';
                            document.getElementById('product-quantidade').value = p.quantidade;
                            document.getElementById('product-unidade').value = p.unidade;
                            document.getElementById('product-chegada').value = p.data_chegada;
                            document.getElementById('product-vencimento').value = p.data_vencimento;
                            productModal.style.display = 'flex';
                        } else {
                            alert(data.message || 'Erro ao buscar dados.');
                        }
                    })
                    .catch(error => { console.error(error); alert('Erro ao carregar dados.'); });
                }
            });
            
            if (productSearchInput) {
                const tableRows = productTableBody.getElementsByTagName('tr');
                productSearchInput.addEventListener('input', function() {
                    const searchTerm = productSearchInput.value.toLowerCase();
                    for (const row of tableRows) {
                        const rowText = row.textContent.toLowerCase();
                        row.style.display = rowText.includes(searchTerm) ? "" : "none";
                    }
                });
            }
        }
    } 


    // ===================================================
    // 3. LÓGICA DE CLIENTES
    // ===================================================
    const addClientBtn = document.getElementById('add-client-btn');
    if (addClientBtn) { 
        const clientModal = document.getElementById('client-modal');
        const clientTableBody = document.getElementById('clientTableBody'); 

        if (clientModal && clientTableBody) {
            const petForm = document.getElementById('pet-form');
            const clientForm = document.getElementById('client-form');
            const petList = document.getElementById('pet-list');
            const saveClientBtn = document.getElementById('saveClientBtn');
            const modalTitle = document.getElementById('client-modal-title');
            let petsArray = [];
            let editingClientId = null; 

            // ============================================================
            // --- BUSCA AUTOMÁTICA DE CEP (ViaCEP) ---
            // ============================================================
            const cepInput = document.getElementById('client-cep');
            
            if (cepInput) {
                cepInput.addEventListener('blur', function() {
                    // 1. Limpa o CEP (remove traços e pontos)
                    const cep = this.value.replace(/\D/g, '');

                    // 2. Verifica se tem 8 dígitos
                    if (cep.length === 8) {
                        
                        // Feedback visual (opcional)
                        document.getElementById('client-street').value = "Buscando...";
                        document.getElementById('client-neighborhood').value = "...";
                        
                        // 3. Consulta a API do ViaCEP
                        fetch(`https://viacep.com.br/ws/${cep}/json/`)
                            .then(response => response.json())
                            .then(data => {
                                if (!data.erro) {
                                    // 4. Preenche os campos se achou
                                    document.getElementById('client-street').value = data.logradouro;
                                    document.getElementById('client-neighborhood').value = data.bairro;
                                    document.getElementById('client-city').value = data.localidade;
                                    document.getElementById('client-state').value = data.uf;
                                    
                                    // 5. Joga o foco para o número (único campo que falta)
                                    document.getElementById('client-number').focus();
                                } else {
                                    alert("CEP não encontrado na base de dados.");
                                    document.getElementById('client-street').value = "";
                                    document.getElementById('client-neighborhood').value = "";
                                }
                            })
                            .catch(error => {
                                console.error("Erro ViaCEP:", error);
                                alert("Erro ao buscar o endereço. Verifique sua internet.");
                            });
                    }
                });
            }
            // ============================================================

            clientModal.querySelector('.close-modal-btn').addEventListener('click', () => clientModal.style.display = 'none');
            clientModal.querySelector('.cancel-btn').addEventListener('click', () => clientModal.style.display = 'none');

            addClientBtn.addEventListener('click', () => {
                editingClientId = null; 
                modalTitle.textContent = 'Novo Cliente';
                clientForm.reset();
                petForm.reset();
                petList.innerHTML = '';
                petsArray = [];
                document.getElementById('pet-breed').innerHTML = '<option value="">Selecione a espécie primeiro</option>';
                document.getElementById('pet-breed').disabled = true;
                clientModal.style.display = 'flex';
            });

            const petSpeciesSelect = document.getElementById('pet-species');
            const petBreedSelect = document.getElementById('pet-breed');
            const breeds = {
                'Cão': ['SRD (Vira-lata)', 'Poodle', 'Golden Retriever', 'Labrador', 'Shih Tzu', 'Bulldog', 'Yorkshire', 'Outro'],
                'Gato': ['SRD (Vira-lata)', 'Siamês', 'Persa', 'Angorá', 'Sphynx', 'Outro']
            };
            
            petSpeciesSelect.addEventListener('change', function() {
                const selectedSpecies = this.value; 
                petBreedSelect.innerHTML = ''; 
                if (selectedSpecies && breeds[selectedSpecies]) {
                    petBreedSelect.disabled = false; 
                    const defaultOption = document.createElement('option');
                    defaultOption.value = ""; defaultOption.textContent = "Selecione a raça";
                    petBreedSelect.appendChild(defaultOption);
                    breeds[selectedSpecies].forEach(breedName => {
                        const option = document.createElement('option');
                        option.value = breedName; option.textContent = breedName;
                        petBreedSelect.appendChild(option);
                    });
                } else {
                    petBreedSelect.disabled = true; 
                    const defaultOption = document.createElement('option');
                    defaultOption.value = ""; defaultOption.textContent = "Selecione a espécie primeiro";
                    petBreedSelect.appendChild(defaultOption);
                }
            });

            petForm.addEventListener('submit', function(event) {
                event.preventDefault();
                const petData = { 
                    nome: document.getElementById('pet-name').value.trim(), 
                    especie: petSpeciesSelect.value, 
                    raca: petBreedSelect.value, 
                    nascimento: document.getElementById('pet-birthdate').value 
                };
                if (petData.nome === '') { alert('O nome do pet é obrigatório.'); return; }
                petsArray.push(petData); 
                renderPetList(); 
                petForm.reset();
                petSpeciesSelect.value = "";
                petBreedSelect.innerHTML = '<option value="">Selecione a espécie primeiro</option>';
                petBreedSelect.disabled = true;
            });

            petList.addEventListener('click', (e) => {
                const removeBtn = e.target.closest('.remove-pet-btn');
                if (removeBtn) {
                    const itemToRemove = removeBtn.closest('.pet-list-item');
                    if (itemToRemove.dataset.index) {
                        const indexToRemove = parseInt(itemToRemove.dataset.index, 10);
                        if (!isNaN(indexToRemove) && indexToRemove >= 0 && indexToRemove < petsArray.length) {
                            petsArray.splice(indexToRemove, 1); 
                            renderPetList(); 
                        }
                    } else {
                        itemToRemove.remove(); 
                    }
                }
            });

            function renderPetList() {
                petList.innerHTML = ''; 
                petsArray.forEach((petData, index) => {
                    const listItem = document.createElement('li');
                    listItem.className = 'pet-list-item';
                    if (petData.id) listItem.dataset.petId = petData.id;
                    else listItem.dataset.index = index;
                    
                    listItem.innerHTML = `
                        <div class="pet-list-item-info">
                            <strong>${petData.nome}</strong>
                            <small>${petData.especie || 'Espécie?'} - ${petData.raca || 'Raça?'}</small>
                        </div>
                        <button type="button" class="remove-pet-btn" title="Remover Pet"><i class="fas fa-times"></i></button>
                    `;
                    petList.appendChild(listItem);
                });
            }

            saveClientBtn.addEventListener('click', function() {
                const clienteData = {
                    id: editingClientId,
                    nome_completo: document.getElementById('client-name').value.trim(),
                    telefone: document.getElementById('client-phone').value.trim(),
                    email: document.getElementById('client-email').value.trim(),
                    cep: document.getElementById('client-cep').value.trim(),
                    logradouro: document.getElementById('client-street').value.trim(),
                    numero: document.getElementById('client-number').value.trim(),
                    bairro: document.getElementById('client-neighborhood').value.trim(),
                    cidade: document.getElementById('client-city').value.trim(),
                    estado: document.getElementById('client-state').value.trim().toUpperCase(),
                    cpf: document.getElementById('client-cpf') ? document.getElementById('client-cpf').value.trim() : '' // CPF Adicionado
                };

                if (clienteData.nome_completo === '') { alert('O nome do cliente é obrigatório.'); return; }

                const dataToSend = { cliente: clienteData, pets: petsArray };

                fetch('salvar_cliente.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(dataToSend)
                    })
                    .then(res => res.json())
                    .then(result => {
                        alert(result.message);
                        if (result.success) location.reload();
                    }).catch(err => { console.error('Fetch error:', err); alert('Erro de comunicação.'); });
            });

            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                const tableRows = clientTableBody.getElementsByTagName('tr');
                searchInput.addEventListener('input', function() {
                    const searchTerm = searchInput.value.toLowerCase();
                    for (const row of tableRows) {
                        const rowText = row.textContent.toLowerCase();
                        row.style.display = rowText.includes(searchTerm) ? "" : "none";
                    }
                });
            }

            clientTableBody.addEventListener('click', function(event) {
                const targetButton = event.target.closest('button'); 
                if (!targetButton) return; 
                const clientId = targetButton.dataset.id;

                if (targetButton.classList.contains('btn-delete')) {
                    if (confirm(`Tem certeza que deseja excluir o cliente ID ${clientId}?`)) {
                        fetch('excluir_cliente.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ id: clientId })
                        })
                        .then(response => response.json())
                        .then(result => {
                            alert(result.message);
                            if (result.success) {
                                const rowToRemove = clientTableBody.querySelector(`tr[data-id="${clientId}"]`);
                                if (rowToRemove) rowToRemove.remove();
                            }
                        })
                        .catch(error => { console.error(error); alert('Erro de comunicação.'); });
                    }
                }

                if (targetButton.classList.contains('btn-edit')) {
                    editingClientId = clientId; 
                    loadClientDataForEdit(clientId); 
                }
            });

            function loadClientDataForEdit(clientId) {
                fetch(`buscar_cliente.php?id=${clientId}`)
                .then(response => {
                    if (!response.ok) throw new Error(`Erro ${response.status}`);
                    return response.json();
                })
                .then(data => {
                    if (data.success && data.cliente) {
                        const cliente = data.cliente;
                        document.getElementById('client-name').value = cliente.nome_completo || '';
                        
                        // Preenche CPF se existir
                        if(document.getElementById('client-cpf')) {
                            document.getElementById('client-cpf').value = cliente.cpf || '';
                        }

                        document.getElementById('client-phone').value = cliente.telefone || '';
                        document.getElementById('client-email').value = cliente.email || '';
                        document.getElementById('client-cep').value = cliente.cep || '';
                        document.getElementById('client-street').value = cliente.logradouro || '';
                        document.getElementById('client-number').value = cliente.numero || '';
                        document.getElementById('client-neighborhood').value = cliente.bairro || '';
                        document.getElementById('client-city').value = cliente.cidade || '';
                        document.getElementById('client-state').value = cliente.estado || '';

                        petsArray = data.pets || [];
                        renderPetList();
                        modalTitle.textContent = `Editando Cliente: ${cliente.nome_completo}`;
                        clientModal.style.display = 'flex';
                    } else {
                        alert(data.message || 'Erro ao buscar dados.');
                    }
                })
                .catch(error => { console.error(error); alert('Erro ao carregar dados.'); });
            }
        } 
    } 


    // ===================================================
    // 4. LÓGICA DO PDV (VENDAS)
    // ===================================================
    const pdvSearchInput = document.getElementById('pdv-search');
    
    if (pdvSearchInput) { // Só roda na tela de vendas
        
        let carrinho = [];
        let totalGlobal = 0;
        
        // Elementos do PDV
        const carrinhoLista = document.getElementById('carrinho-lista');
        const totalVendaEl = document.getElementById('total-venda');
        const descontoInput = document.getElementById('desconto-valor');
        const btnFinalizar = document.querySelector('.btn-finalize');
        const btnCancelar = document.querySelector('.btn-secondary');
        
        // Elementos do Fechamento
        const btnFecharCaixa = document.getElementById('btn-fechar-caixa');
        const closureModal = document.getElementById('closure-modal');
        const btnPrintThermal = document.getElementById('btn-print-thermal');
        const btnPrintA4 = document.getElementById('btn-print-a4');

        // --- FUNÇÕES AUXILIARES DO CARRINHO ---
        function atualizarInterface() {
            carrinhoLista.innerHTML = '';
            let subtotal = 0;

            carrinho.forEach((item, index) => {
                const totalItem = item.preco * item.qtd;
                subtotal += totalItem;
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${item.nome}</td>
                    <td>${item.qtd}</td>
                    <td>R$ ${totalItem.toFixed(2)}</td>
                    <td><i class="fas fa-times ticket-item-remove" data-index="${index}"></i></td>
                `;
                carrinhoLista.appendChild(tr);
            });

            const desconto = parseFloat(descontoInput.value.replace(',', '.')) || 0;
            totalGlobal = subtotal - desconto;
            if(totalGlobal < 0) totalGlobal = 0;
            totalVendaEl.textContent = 'R$ ' + totalGlobal.toFixed(2);
        }

        function adicionarItem(id, nome, preco) {
            const existente = carrinho.find(i => i.id == id);
            if (existente) {
                existente.qtd++;
            } else {
                carrinho.push({ id: parseInt(id), nome: nome, preco: parseFloat(preco), qtd: 1 });
            }
            atualizarInterface();
        }

        // --- EXPORTAÇÃO PARA O HTML (ONCLICK) ---
        window.adicionarAoCarrinho = function(id, nome, preco) { adicionarItem(id, nome, preco); };
        window.adicionarServicoAvulso = function(nome, preco) { const idTemp = -Date.now(); adicionarItem(idTemp, nome, preco); };
        window.removerItem = function(index) { carrinho.splice(index, 1); atualizarInterface(); };

        // --- EVENTOS DE VENDAS ---

        // Busca por ENTER
        pdvSearchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                const termo = this.value;
                if(termo.length > 0) {
                    fetch(`buscar_produto_pdv.php?termo=${termo}`)
                    .then(res => res.json())
                    .then(produtos => {
                        if (produtos.length === 1) {
                            const p = produtos[0];
                            adicionarItem(p.id, p.nome, p.preco);
                            this.value = ''; this.focus();
                        } else if (produtos.length > 1) {
                            alert('Muitos produtos encontrados. Seja mais específico.');
                        } else {
                            alert('Produto não encontrado.');
                        }
                    });
                }
            }
        });

        // Remover item (X)
        carrinhoLista.addEventListener('click', function(e) {
            if(e.target.classList.contains('ticket-item-remove')) {
                const index = e.target.dataset.index;
                carrinho.splice(index, 1);
                atualizarInterface();
            }
        });

        // Desconto
        descontoInput.addEventListener('change', atualizarInterface);

        // Cancelar
        if (btnCancelar) {
            btnCancelar.addEventListener('click', function() {
                if(confirm('Limpar venda?')) {
                    carrinho = [];
                    descontoInput.value = "0.00";
                    atualizarInterface();
                }
            });
        }

        // Finalizar Venda
        btnFinalizar.addEventListener('click', function() {
            if (carrinho.length === 0) { alert('Carrinho vazio!'); return; }

            const originalText = this.innerHTML;
            this.disabled = true;
            this.innerHTML = 'Processando...';

            const dados = {
                cliente_id: document.getElementById('pdv-cliente').value || null,
                total_venda: totalGlobal,
                desconto: parseFloat(descontoInput.value) || 0,
                forma_pagamento: document.getElementById('forma-pagamento').value,
                itens: carrinho
            };

            fetch('salvar_venda.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(dados)
            })
            .then(res => res.json())
            .then(res => {
                if (res.success) {
                    alert('Venda realizada!');
                    carrinho = [];
                    descontoInput.value = "0.00";
                    atualizarInterface();
                    document.getElementById('pdv-cliente').value = "";
                } else {
                    alert('Erro: ' + res.message);
                }
            })
            .catch(err => alert('Erro de conexão.'))
            .finally(() => {
                this.disabled = false;
                this.innerHTML = originalText;
            });
        });

        // --- LÓGICA DE FECHAMENTO DE CAIXA ---
        if (btnFecharCaixa && closureModal) {
            const closureContent = document.getElementById('closure-content');
            const closeBtns = closureModal.querySelectorAll('.close-modal-btn');
            
            // Variável local para guardar os dados (não precisa ser global)
            let dadosFechamento = null; 

            btnFecharCaixa.addEventListener('click', function() {
                closureModal.style.display = 'flex';
                closureContent.innerHTML = '<p style="text-align:center">Carregando relatório...</p>';

                fetch('api_fechamento_caixa.php')
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        dadosFechamento = data; // Salva para impressão

                        let html = `
                            <div style="background:#f8f9fa; padding:10px; border-radius:6px; margin-bottom:15px; border:1px solid #eee;">
                                <strong>Operador:</strong> ${data.operador.nome} <br>
                                <strong>Cargo:</strong> ${data.operador.cargo} <br>
                                <small>Data: ${data.data_hora}</small>
                            </div>
                        `;
                        html += '<h4>Resumo Financeiro</h4><table class="closure-table"><thead><tr><th>Forma Pagto</th><th>Total</th></tr></thead><tbody>';
                        data.financeiro.forEach(f => { html += `<tr><td>${f.forma_pagamento}</td><td>R$ ${parseFloat(f.total).toFixed(2)}</td></tr>`; });
                        html += '</tbody></table>';

                        html += '<h4 style="margin-top:20px">Extrato de Itens</h4><table class="closure-table"><thead><tr><th>Item</th><th>Qtd</th><th>Subtotal</th></tr></thead><tbody>';
                        data.itens.forEach(i => { html += `<tr><td>${i.nome_item}</td><td>${parseFloat(i.qtd)}</td><td>R$ ${parseFloat(i.total).toFixed(2)}</td></tr>`; });
                        html += '</tbody></table>';
                        html += `<div class="closure-total">Total do Dia: R$ ${data.total_geral.toFixed(2)}</div>`;
                        
                        closureContent.innerHTML = html;
                    } else {
                        closureContent.innerHTML = '<p style="color:red">Erro: ' + data.message + '</p>';
                    }
                })
                .catch(err => { closureContent.innerHTML = '<p style="color:red">Erro de conexão.</p>'; });
            });

            closeBtns.forEach(btn => btn.addEventListener('click', () => closureModal.style.display = 'none'));

            // Impressões (Usando dadosFechamento)
            if(btnPrintThermal) {
                btnPrintThermal.addEventListener('click', () => {
                    if(dadosFechamento) imprimirTicket(dadosFechamento);
                });
            }
            if(btnPrintA4) {
                btnPrintA4.addEventListener('click', () => {
                    if(dadosFechamento) imprimirA4(dadosFechamento);
                });
            }
        }
    } 

    // --- FUNÇÕES DE IMPRESSÃO GLOBAIS ---
    function imprimirTicket(data) {
        const win = window.open('', '', 'width=350,height=600');
        let itemsHtml = '';
        data.itens.forEach(i => {
            itemsHtml += `<tr><td colspan="2">${i.nome_item}</td></tr>
                          <tr style="border-bottom:1px dashed #000;"><td align="right">${parseFloat(i.qtd)}x</td><td align="right">R$ ${parseFloat(i.total).toFixed(2)}</td></tr>`;
        });
        let pagtosHtml = '';
        data.financeiro.forEach(f => {
            pagtosHtml += `<tr><td>${f.forma_pagamento}</td><td align="right">R$ ${parseFloat(f.total).toFixed(2)}</td></tr>`;
        });

        win.document.write(`<html><head><style>body{font-family:monospace;font-size:12px;width:300px;margin:0;padding:5px;}.center{text-align:center;}.line{border-top:1px dashed #000;margin:5px 0;}table{width:100%;}</style></head><body>
            <div class="center"><b>${data.empresa.nome_fantasia}</b><br>FECHAMENTO<br>${data.data_hora}</div>
            <div class="line"></div><div>Op: ${data.operador.nome}</div><div class="line"></div>
            <table>${itemsHtml}</table>
            <div class="line"></div><b>RESUMO</b><table>${pagtosHtml}</table>
            <div class="line"></div><div class="center"><b>TOTAL: R$ ${data.total_geral.toFixed(2)}</b></div>
            <br><div class="center">.</div></body></html>`);
        win.document.close();
        win.focus();
        setTimeout(() => { win.print(); win.close(); }, 500);
    }

    function imprimirA4(data) {
        const win = window.open('', '', 'width=900,height=700');
        let itemsHtml = '';
        data.itens.forEach(i => {
            itemsHtml += `<tr><td>${i.nome_item}</td><td>${parseFloat(i.qtd)}</td><td>R$ ${parseFloat(i.total).toFixed(2)}</td></tr>`;
        });
        const content = `
            <html><head><title>Relatório</title><style>body{font-family:Arial;padding:40px;}table{width:100%;border-collapse:collapse;margin-bottom:20px;}th,td{border:1px solid #ddd;padding:10px;text-align:left;}th{background:#f4f4f4;}.total{text-align:right;font-size:1.5em;font-weight:bold;}.sig-box{margin-top:80px;text-align:center;width:300px;float:right;}.sig-line{border-top:1px solid #000;}</style></head><body>
            <h1>Relatório de Fechamento</h1>
            <p><strong>Empresa:</strong> ${data.empresa.nome_fantasia}</p><p><strong>Data:</strong> ${data.data_hora}</p>
            <h3>Itens Vendidos</h3><table><thead><tr><th>Item</th><th>Qtd</th><th>Total</th></tr></thead><tbody>${itemsHtml}</tbody></table>
            <h3>Pagamentos</h3><table style="width:50%"><thead><tr><th>Método</th><th>Valor</th></tr></thead><tbody>
            ${data.financeiro.map(f => `<tr><td>${f.forma_pagamento}</td><td>R$ ${parseFloat(f.total).toFixed(2)}</td></tr>`).join('')}
            </tbody></table>
            <div class="total">Total: R$ ${data.total_geral.toFixed(2)}</div>
            <div class="sig-box"><div class="sig-line"></div><strong>${data.operador.nome}</strong><br><small>${data.operador.cargo}</small></div>
            </body></html>`;
        win.document.write(content);
        win.document.close();
        win.focus();
        setTimeout(() => { win.print(); }, 500);
    }


    // ===================================================
    // 5. MÁSCARAS DE INPUT (CPF, CNPJ, TELEFONE)
    // ===================================================
    const maskInput = (input, format) => {
        input.addEventListener('input', (e) => {
            let value = e.target.value.replace(/\D/g, ""); 
            if (format === 'cpf') {
                if(value.length > 11) value = value.slice(0, 11);
                value = value.replace(/(\d{3})(\d)/, "$1.$2");
                value = value.replace(/(\d{3})(\d)/, "$1.$2");
                value = value.replace(/(\d{3})(\d{1,2})$/, "$1-$2");
            } 
            else if (format === 'cnpj') {
                if(value.length > 14) value = value.slice(0, 14);
                value = value.replace(/^(\d{2})(\d)/, "$1.$2");
                value = value.replace(/^(\d{2})\.(\d{3})(\d)/, "$1.$2.$3");
                value = value.replace(/\.(\d{3})(\d)/, ".$1/$2");
                value = value.replace(/(\d{4})(\d)/, "$1-$2");
            }
            else if (format === 'tel') {
                if(value.length > 11) value = value.slice(0, 11);
                value = value.replace(/^(\d{2})(\d)/, "($1) $2");
                value = value.replace(/(\d{5})(\d)/, "$1-$2");
            }
            e.target.value = value;
        });
    };

    const cpfInput = document.getElementById('client-cpf');
    const cnpjInput = document.getElementById('inst-cnpj');
    const phoneInput = document.getElementById('client-phone');
    const phoneInputUser = document.getElementById('m-phone'); 

    if (cpfInput) maskInput(cpfInput, 'cpf');
    if (cnpjInput) maskInput(cnpjInput, 'cnpj');
    if (phoneInput) maskInput(phoneInput, 'tel');
    if (phoneInputUser) maskInput(phoneInputUser, 'tel');

});