document.addEventListener('DOMContentLoaded', function() {
    console.log("Main.js carregado com sucesso.");

    // ===================================================
    // 0. MOTOR GLOBAL DE SELECT2 & PESQUISA NO CABEÇALHO
    // ===================================================
    const PetCrmSelect2 = (function() {
        const hasJq = (typeof window.jQuery !== 'undefined' && typeof window.jQuery.fn.select2 === 'function');
        const observedModals = new WeakSet();

        function resolveEl(elOrSelector) {
            if (!elOrSelector) return null;
            if (typeof elOrSelector === 'string') return document.querySelector(elOrSelector);
            return elOrSelector;
        }

        function getDropdownParent(el) {
            const modalBodyOrContent = el.closest('.modal-content, .modal-box, .modal-overlay');
            return modalBodyOrContent ? window.jQuery(modalBodyOrContent) : window.jQuery(document.body);
        }

        function bindObserver(selectEl) {
            if (selectEl._petcrmS2Observed) return;
            selectEl._petcrmS2Observed = true;
            let timer = null;
            const obs = new MutationObserver(() => {
                clearTimeout(timer);
                timer = setTimeout(() => {
                    sync(selectEl);
                }, 0);
            });
            obs.observe(selectEl, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled'] });

            const form = selectEl.closest('form');
            if (form && !form._petcrmS2ResetBound) {
                form._petcrmS2ResetBound = true;
                form.addEventListener('reset', () => {
                    setTimeout(() => {
                        form.querySelectorAll('select').forEach(s => sync(s));
                    }, 0);
                });
            }

            const modalOverlay = selectEl.closest('.modal-overlay, .modal-box');
            if (modalOverlay && !observedModals.has(modalOverlay)) {
                observedModals.add(modalOverlay);
                const modalObs = new MutationObserver(() => {
                    if (modalOverlay.style.display && modalOverlay.style.display !== 'none') {
                        setTimeout(() => {
                            modalOverlay.querySelectorAll('select').forEach(s => sync(s));
                        }, 10);
                    }
                });
                modalObs.observe(modalOverlay, { attributes: true, attributeFilter: ['style', 'class'] });
            }
        }

        function enhance(elOrSelector, customOpts = {}) {
            if (!hasJq) return null;
            const el = resolveEl(elOrSelector);
            if (!el || el.tagName !== 'SELECT') return null;
            if (el.dataset.noAutoSelect2 === '1' && !customOpts._force) return null;

            const $ = window.jQuery;
            const $el = $(el);

            if ($el.hasClass('select2-hidden-accessible')) {
                if (Object.keys(customOpts).length > 0 && !customOpts._skipReinit) {
                    $el.select2('destroy');
                } else {
                    return $el;
                }
            }

            const placeholderAttr = el.getAttribute('data-placeholder') || '';
            const firstOpt = el.options && el.options.length > 0 ? el.options[0] : null;
            const firstEmptyText = (!placeholderAttr && firstOpt && firstOpt.value === '') ? firstOpt.textContent.trim() : '';

            const opts = Object.assign({
                language: 'pt-BR',
                width: el.dataset.s2Width || '100%',
                dropdownParent: getDropdownParent(el),
                placeholder: placeholderAttr || firstEmptyText || 'Pesquisar...',
                allowClear: el.dataset.s2AllowClear === '1',
                tags: el.dataset.s2Tags === '1',
                minimumResultsForSearch: el.dataset.s2MinSearch !== undefined ? parseInt(el.dataset.s2MinSearch, 10) : 0,
            }, customOpts);
            delete opts._force;
            delete opts._skipReinit;

            $el.select2(opts);

            if (!el._petcrmS2EventsBound) {
                el._petcrmS2EventsBound = true;
                $el.on('select2:select select2:clear select2:unselect', function() {
                    el.dispatchEvent(new Event('change', { bubbles: true }));
                    el.dispatchEvent(new Event('input', { bubbles: true }));
                });
            }

            bindObserver(el);
            return $el;
        }

        function sync(elOrSelector, newVal = undefined) {
            const el = resolveEl(elOrSelector);
            if (!el) return;
            if (newVal !== undefined) {
                el.value = newVal === null ? '' : String(newVal);
            }
            if (!hasJq) return;
            const $el = window.jQuery(el);
            if ($el.hasClass('select2-hidden-accessible')) {
                $el.prop('disabled', !!el.disabled);
                $el.trigger('change.select2');
            }
        }

        function initAll(root = document) {
            if (!hasJq) return;
            const selects = root.querySelectorAll('select');
            selects.forEach(sel => {
                if (sel.dataset.noAutoSelect2 === '1') return;
                enhance(sel, { _skipReinit: true });
            });
        }

        function initHeaderGlobalSearch() {
            if (!hasJq) return;
            const el = document.getElementById('header-global-search');
            if (!el) return;
            const $ = window.jQuery;
            const $el = $(el);
            if ($el.hasClass('select2-hidden-accessible')) return;

            const iconesTipo = {
                cliente: '<i class="fas fa-user" style="color:#1abc9c;margin-right:6px;"></i>',
                pet: '<i class="fas fa-paw" style="color:#f59e0b;margin-right:6px;"></i>',
                produto: '<i class="fas fa-box-open" style="color:#3b82f6;margin-right:6px;"></i>',
                servico: '<i class="fas fa-cut" style="color:#a855f7;margin-right:6px;"></i>',
                loja: '<i class="fas fa-store" style="color:#10b981;margin-right:6px;"></i>'
            };

            $el.select2({
                language: 'pt-BR',
                width: '100%',
                placeholder: 'Buscar cliente, pet, produto ou serviço...',
                allowClear: true,
                minimumInputLength: 0,
                ajax: {
                    url: 'api_busca_global.php',
                    dataType: 'json',
                    delay: 220,
                    data: params => ({ q: params.term || '', tipo: 'all' }),
                    processResults: data => ({ results: data.results || [] }),
                    cache: true
                },
                templateResult: function(item) {
                    if (!item.id) return item.text;
                    const ic = iconesTipo[item.tipo] || '';
                    const $node = $('<div class="s2-global-item"></div>');
                    $node.append($('<div class="s2-global-item-title"></div>').html(ic).append(document.createTextNode(item.text || '')));
                    if (item.subtitulo) {
                        $node.append($('<div class="s2-global-item-sub"></div>').text(item.subtitulo));
                    }
                    return $node;
                }
            });

            $el.on('select2:select', function(e) {
                const data = e.params && e.params.data;
                if (data && data.url) {
                    window.location.href = data.url;
                }
            });
        }

        return { enhance, sync, initAll, initHeaderGlobalSearch };
    })();

    window.PetCrmSelect2 = PetCrmSelect2;
    PetCrmSelect2.initHeaderGlobalSearch();
    PetCrmSelect2.initAll();

    // ===================================================
    // 1. LÓGICA DO MODAL DA AGENDA (RETRABALHO COMPLETO ERP)
    // ===================================================
    const addAppointmentBtn = document.getElementById('add-appointment-btn');
    const appointmentModal = document.getElementById('appointment-modal');
    
    if (addAppointmentBtn && appointmentModal) {
        console.log("Módulo Agenda moderna inicializado com sucesso.");

        const calendarGrid     = document.querySelector('.calendar-grid');
        const modalTitle       = document.getElementById('modal-app-title') || appointmentModal.querySelector('.modal-header h2');
        const appointmentForm  = document.getElementById('appointment-form');
        const appIdInput       = document.getElementById('app-id');
        const clientSelect     = document.getElementById('app-client');
        const petSelect        = document.getElementById('app-pet');
        const serviceSelect    = document.getElementById('app-service');
        const dateInput        = document.getElementById('app-date');
        const timeInput        = document.getElementById('app-time');
        const profSelect       = document.getElementById('app-professional');
        const notesInput       = document.getElementById('app-notes');
        
        const statusContainer  = document.getElementById('app-status-container');
        const statusPill       = document.getElementById('app-status-pill');
        const actionsLeft      = document.getElementById('modal-app-actions-left');
        const btnConcluir      = document.getElementById('btn-concluir-app');
        const btnCancelar      = document.getElementById('btn-cancelar-app');
        const btnRemarcar      = document.getElementById('btn-remarcar-app');
        const saveBtn          = document.getElementById('save-app-btn');
        const cancelBtn        = document.getElementById('cancel-app-btn');

        // Alternador de Visões: Grade Semanal vs Fila do Dia
        const tabSemana = document.getElementById('tab-view-semana');
        const tabFila   = document.getElementById('tab-view-fila');
        const containerSemana = document.getElementById('container-visao-semana');
        const containerFila   = document.getElementById('container-visao-fila');

        function setAgendaView(view) {
            if (view === 'fila') {
                if (tabFila) tabFila.classList.add('active');
                if (tabSemana) tabSemana.classList.remove('active');
                if (containerFila) containerFila.style.display = 'block';
                if (containerSemana) containerSemana.style.display = 'none';
                localStorage.setItem('petcrm_agenda_view', 'fila');
            } else {
                if (tabSemana) tabSemana.classList.add('active');
                if (tabFila) tabFila.classList.remove('active');
                if (containerSemana) containerSemana.style.display = 'block';
                if (containerFila) containerFila.style.display = 'none';
                localStorage.setItem('petcrm_agenda_view', 'semana');
            }
        }

        if (tabSemana && tabFila) {
            tabSemana.addEventListener('click', () => setAgendaView('semana'));
            tabFila.addEventListener('click', () => setAgendaView('fila'));

            const savedView = localStorage.getItem('petcrm_agenda_view');
            if (savedView === 'fila') {
                setAgendaView('fila');
            }
        }

        // Filtros em tempo real na Agenda (Profissional, Serviço, Status, Busca)
        const filtroProf  = document.getElementById('filtro-agenda-profissional');
        const filtroServ  = document.getElementById('filtro-agenda-servico');
        const filtroStat  = document.getElementById('filtro-agenda-status');
        const filtroBusca = document.getElementById('filtro-agenda-busca');

        function aplicarFiltrosAgenda() {
            const valProf  = filtroProf ? filtroProf.value.toLowerCase().trim() : '';
            const valServ  = filtroServ ? filtroServ.value.toLowerCase().trim() : '';
            const valStat  = filtroStat ? filtroStat.value.toLowerCase().trim() : '';
            const valBusca = filtroBusca ? filtroBusca.value.toLowerCase().trim() : '';

            // Filtra cards na grade semanal
            document.querySelectorAll('.modern-appointment-card, .appointment-card').forEach(card => {
                let match = true;
                const cProfId  = (card.dataset.profissionalId || '').toLowerCase();
                const cServ    = (card.dataset.servico || '').toLowerCase();
                const cStat    = (card.dataset.status || '').toLowerCase();
                const cPet     = (card.dataset.petNome || '').toLowerCase();
                const cCliente = (card.dataset.clienteNome || '').toLowerCase();
                const cRaca    = (card.dataset.petRaca || '').toLowerCase();

                if (valProf && cProfId !== valProf) match = false;
                if (valServ && !cServ.includes(valServ)) match = false;
                if (valStat && cStat !== valStat) match = false;
                if (valBusca && !cPet.includes(valBusca) && !cCliente.includes(valBusca) && !cRaca.includes(valBusca)) match = false;

                card.style.display = match ? '' : 'none';
            });

            // Filtra itens na fila diária
            document.querySelectorAll('.fila-card-item').forEach(item => {
                let match = true;
                const iProfId  = (item.dataset.profissionalId || '').toLowerCase();
                const iServ    = (item.dataset.servico || '').toLowerCase();
                const iStat    = (item.dataset.status || '').toLowerCase();
                const iPet     = (item.dataset.petNome || '').toLowerCase();
                const iCliente = (item.dataset.clienteNome || '').toLowerCase();
                const iRaca    = (item.dataset.petRaca || '').toLowerCase();

                if (valProf && iProfId !== valProf) match = false;
                if (valServ && !iServ.includes(valServ)) match = false;
                if (valStat && iStat !== valStat) match = false;
                if (valBusca && !iPet.includes(valBusca) && !iCliente.includes(valBusca) && !iRaca.includes(valBusca)) match = false;

                item.style.display = match ? '' : 'none';
            });
        }

        if (filtroProf) filtroProf.addEventListener('change', aplicarFiltrosAgenda);
        if (filtroServ) filtroServ.addEventListener('change', aplicarFiltrosAgenda);
        if (filtroStat) filtroStat.addEventListener('change', aplicarFiltrosAgenda);
        if (filtroBusca) {
            if (filtroBusca.tagName === 'SELECT') {
                const termosAgenda = new Set();
                document.querySelectorAll('.modern-appointment-card, .appointment-card, .fila-card-item').forEach(el => {
                    if (el.dataset.petNome) termosAgenda.add(el.dataset.petNome.trim());
                    if (el.dataset.clienteNome) termosAgenda.add(el.dataset.clienteNome.trim());
                });
                Array.from(termosAgenda).sort().forEach(nome => {
                    if (!nome) return;
                    const opt = document.createElement('option');
                    opt.value = nome;
                    opt.textContent = nome;
                    filtroBusca.appendChild(opt);
                });
                PetCrmSelect2.enhance(filtroBusca, {
                    placeholder: 'Buscar pet ou tutor...',
                    allowClear: true,
                    tags: true
                });
                filtroBusca.addEventListener('change', aplicarFiltrosAgenda);
            } else {
                filtroBusca.addEventListener('input', aplicarFiltrosAgenda);
            }
        }

        // Carrega pets do cliente via API
        function carregarPets(clienteId, petIdSelecionado = null) {
            petSelect.innerHTML = '<option value="">Carregando pets...</option>';
            petSelect.disabled = true;

            if (!clienteId) {
                petSelect.innerHTML = '<option value="">Selecione um cliente primeiro</option>';
                return Promise.resolve();
            }

            return fetch(`buscar_pets_cliente.php?cliente_id=${encodeURIComponent(clienteId)}`)
                .then(res => res.json())
                .then(data => {
                    petSelect.innerHTML = '';
                    const pets = data.pets || (Array.isArray(data) ? data : []);

                    if (pets && pets.length > 0) {
                        petSelect.innerHTML = '<option value="">Selecione o pet...</option>';
                        pets.forEach(pet => {
                            const opt = document.createElement('option');
                            opt.value = pet.id;
                            opt.textContent = pet.nome + (pet.especie ? ` (${pet.especie})` : '');
                            if (petIdSelecionado && String(pet.id) === String(petIdSelecionado)) {
                                opt.selected = true;
                            }
                            petSelect.appendChild(opt);
                        });
                        petSelect.disabled = false;
                        if (petIdSelecionado) {
                            petSelect.value = String(petIdSelecionado);
                        }
                        PetCrmSelect2.sync(petSelect);
                    } else {
                        petSelect.innerHTML = '<option value="">Este cliente não possui pets cadastrados</option>';
                        petSelect.disabled = true;
                        PetCrmSelect2.sync(petSelect);
                    }
                })
                .catch(err => {
                    console.error("Erro ao carregar pets:", err);
                    petSelect.innerHTML = '<option value="">Erro ao carregar pets</option>';
                    petSelect.disabled = true;
                    PetCrmSelect2.sync(petSelect);
                });
        }

        // Listener para troca de cliente
        if (clientSelect) {
            clientSelect.addEventListener('change', function() {
                carregarPets(this.value);
            });
        }

        // Abrir Modal para NOVO Agendamento (permite pré-preencher data e hora ao clicar na grade)
        function openModalNovo(defaultDate = null, defaultTime = null) {
            if (appointmentForm) appointmentForm.reset();
            if (appIdInput) appIdInput.value = '';

            if (modalTitle) modalTitle.textContent = 'Novo Agendamento';
            if (statusContainer) statusContainer.style.display = 'none';
            if (actionsLeft) actionsLeft.style.display = 'none';
            if (btnRemarcar) btnRemarcar.style.display = 'none';

            if (saveBtn) {
                saveBtn.innerText = 'Salvar Agendamento';
                saveBtn.disabled = false;
            }
            if (cancelBtn) cancelBtn.innerText = 'Cancelar';

            if (petSelect) {
                petSelect.innerHTML = '<option value="">Selecione um cliente primeiro</option>';
                petSelect.disabled = true;
            }
            [clientSelect, petSelect, serviceSelect, profSelect].forEach(s => PetCrmSelect2.sync(s));

            const hoje = new Date().toISOString().split('T')[0];
            if (dateInput) {
                dateInput.removeAttribute('min');
                dateInput.value = defaultDate || hoje;
            }
            if (timeInput) {
                timeInput.value = defaultTime || '09:00';
            }

            appointmentModal.style.display = 'flex';
        }

        // Abrir Modal para EDITAR / VISUALIZAR
        function openModalEditar(card) {
            const dados = card.dataset;
            const appId = dados.id;

            if (appIdInput) appIdInput.value = appId;
            if (modalTitle) modalTitle.textContent = 'Detalhes do Atendimento';

            if (clientSelect) clientSelect.value = dados.clienteId || '';
            if (serviceSelect) serviceSelect.value = dados.servico || '';
            if (dateInput) {
                dateInput.removeAttribute('min');
                dateInput.value = dados.date || '';
            }
            if (timeInput) timeInput.value = dados.time || '';
            if (profSelect) profSelect.value = dados.profissionalId || '0';
            [clientSelect, serviceSelect, profSelect].forEach(s => PetCrmSelect2.sync(s));
            if (notesInput) notesInput.value = dados.obs || '';

            // Status Badge
            const status = dados.status || 'Agendado';
            if (statusContainer && statusPill) {
                statusContainer.style.display = 'flex';
                statusPill.textContent = status;
                if (status === 'Concluído') {
                    statusPill.style.backgroundColor = '#d4edda';
                    statusPill.style.color = '#155724';
                } else if (status === 'Remarcado') {
                    statusPill.style.backgroundColor = '#e2e3e5';
                    statusPill.style.color = '#383d41';
                } else if (status === 'Cancelado') {
                    statusPill.style.backgroundColor = '#f8d7da';
                    statusPill.style.color = '#721c24';
                } else if (status === 'Em Andamento') {
                    statusPill.style.backgroundColor = '#fef3c7';
                    statusPill.style.color = '#92400e';
                } else {
                    statusPill.style.backgroundColor = '#cce5ff';
                    statusPill.style.color = '#004085';
                }
            }

            // Atalho direto de WhatsApp do Tutor
            const btnWhatsDirect = document.getElementById('btn-modal-whats-direct');
            if (btnWhatsDirect) {
                const rawTel = (dados.clienteTel || '').replace(/\D/g, '');
                if (rawTel.length >= 10) {
                    const petNome = dados.petNome || 'seu pet';
                    const msg = encodeURIComponent(`Olá! Estamos entrando em contato referente ao agendamento de ${petNome} no Pet Shop.`);
                    btnWhatsDirect.href = `https://api.whatsapp.com/send?phone=55${rawTel}&text=${msg}`;
                    btnWhatsDirect.style.display = 'inline-flex';
                } else {
                    btnWhatsDirect.style.display = 'none';
                }
            }

            // Exibe botões de ação contextuais
            if (actionsLeft) {
                actionsLeft.style.display = (status !== 'Cancelado' && status !== 'Concluído') ? 'flex' : 'none';
            }
            if (btnRemarcar) {
                btnRemarcar.style.display = (status !== 'Cancelado' && status !== 'Concluído') ? 'inline-block' : 'none';
            }

            if (saveBtn) {
                saveBtn.innerText = 'Salvar Alterações';
                saveBtn.disabled = false;
            }
            if (cancelBtn) cancelBtn.innerText = 'Fechar';

            // Carrega pets e seleciona o correto
            carregarPets(dados.clienteId, dados.petId);

            appointmentModal.style.display = 'flex';
        }

        // Fechar Modal
        function closeModal() {
            appointmentModal.style.display = 'none';
        }

        // Botões de fechar
        appointmentModal.querySelectorAll('.close-modal-btn').forEach(btn => {
            btn.addEventListener('click', closeModal);
        });

        // Clique no fundo escuro para fechar
        appointmentModal.addEventListener('click', function(e) {
            if (e.target === appointmentModal) closeModal();
        });

        // Botão Novo Agendamento do Cabeçalho
        addAppointmentBtn.addEventListener('click', () => openModalNovo());

        // Delegação de clique nos Cards de Agendamento (Grade Semanal e Fila do Dia)
        document.addEventListener('click', function(e) {
            const card = e.target.closest('.modern-appointment-card, .appointment-card, .btn-abrir-modal-editar');
            if (card) {
                if (e.target.closest('a, .btn-mudar-status-rapido')) return;
                openModalEditar(card);
            }
        });

        // Clique em horário livre na grade semanal para criar agendamento pré-preenchido
        document.querySelectorAll('.appointments-stage').forEach(stage => {
            stage.addEventListener('click', function(e) {
                if (e.target.closest('.modern-appointment-card, .appointment-card')) return;
                const dayCol = this.closest('.day-column');
                const clickedDate = dayCol ? dayCol.dataset.date : null;

                const rect = this.getBoundingClientRect();
                const offsetY = e.clientY - rect.top;
                const slotHour = Math.min(18, Math.max(8, Math.floor(offsetY / 64) + 8));
                const slotMin = Math.floor((offsetY % 64) / 16) * 15;
                const clickedTime = `${String(slotHour).padStart(2, '0')}:${String(slotMin).padStart(2, '0')}`;

                openModalNovo(clickedDate, clickedTime);
            });
        });

        // Ação rápida de mudança de status (Iniciar / Concluir direto na Fila)
        document.addEventListener('click', function(e) {
            const btnRapido = e.target.closest('.btn-mudar-status-rapido');
            if (!btnRapido) return;

            const appId = btnRapido.dataset.id;
            const novoStatus = btnRapido.dataset.status;
            if (!appId || !novoStatus) return;

            if (!confirm(`Deseja alterar o status para "${novoStatus}"?`)) return;

            btnRapido.disabled = true;
            btnRapido.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            fetch('cancelar_agendamento.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: appId, status: novoStatus })
            })
            .then(res => res.json())
            .then(res => {
                if (res.success) {
                    location.reload();
                } else {
                    alert(res.message || 'Erro ao atualizar status.');
                    btnRapido.disabled = false;
                }
            })
            .catch(err => {
                console.error(err);
                alert('Erro de comunicação com o servidor.');
                btnRapido.disabled = false;
            });
        });

        // SALVAR (Novo ou Atualizar)
        if (saveBtn) {
            saveBtn.addEventListener('click', function() {
                const appId = appIdInput ? appIdInput.value : '';
                const payload = {
                    id: appId || undefined,
                    cliente_id: clientSelect ? clientSelect.value : '',
                    pet_id: petSelect ? petSelect.value : '',
                    servico: serviceSelect ? serviceSelect.value : '',
                    date: dateInput ? dateInput.value : '',
                    time: timeInput ? timeInput.value : '',
                    profissional_id: profSelect ? profSelect.value : '0',
                    observacoes: notesInput ? notesInput.value : ''
                };

                if (!payload.cliente_id || !payload.pet_id || !payload.servico || !payload.date || !payload.time) {
                    alert('Por favor, preencha todos os campos obrigatórios (*).');
                    return;
                }

                saveBtn.disabled = true;
                saveBtn.innerText = 'Salvando...';

                fetch('salvar_agendamento.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                })
                .then(res => res.json())
                .then(res => {
                    alert(res.message);
                    if (res.success) {
                        closeModal();
                        location.reload();
                    } else {
                        saveBtn.disabled = false;
                        saveBtn.innerText = appId ? 'Salvar Alterações' : 'Salvar Agendamento';
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Erro de comunicação com o servidor.');
                    saveBtn.disabled = false;
                    saveBtn.innerText = appId ? 'Salvar Alterações' : 'Salvar Agendamento';
                });
            });
        }

        // REMARCAR (Gera novo agendamento e marca anterior como Remarcado)
        if (btnRemarcar) {
            btnRemarcar.addEventListener('click', function() {
                const appId = appIdInput ? appIdInput.value : '';
                if (!appId) return;

                const novosDados = {
                    cliente_id: clientSelect ? clientSelect.value : '',
                    pet_id: petSelect ? petSelect.value : '',
                    servico: serviceSelect ? serviceSelect.value : '',
                    date: dateInput ? dateInput.value : '',
                    time: timeInput ? timeInput.value : '',
                    profissional_id: profSelect ? profSelect.value : '0',
                    observacoes: notesInput ? notesInput.value : ''
                };

                if (!novosDados.date || !novosDados.time) {
                    alert('Informe a nova data e o novo horário para remarcar.');
                    return;
                }

                if (!confirm(`Confirmar remarcação para ${novosDados.date} às ${novosDados.time}?`)) {
                    return;
                }

                btnRemarcar.disabled = true;
                btnRemarcar.innerText = 'Remarcando...';

                fetch('remarcar_agendamento.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id_antigo: appId, novos_dados: novosDados })
                })
                .then(res => res.json())
                .then(res => {
                    alert(res.message);
                    if (res.success) {
                        closeModal();
                        location.reload();
                    } else {
                        btnRemarcar.disabled = false;
                        btnRemarcar.innerHTML = '<i class="fas fa-calendar-alt"></i> Remarcar';
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Erro ao remarcar agendamento.');
                    btnRemarcar.disabled = false;
                    btnRemarcar.innerHTML = '<i class="fas fa-calendar-alt"></i> Remarcar';
                });
            });
        }

        // CONCLUIR ATENDIMENTO
        if (btnConcluir) {
            btnConcluir.addEventListener('click', function() {
                const appId = appIdInput ? appIdInput.value : '';
                if (!appId) return;

                if (!confirm('Deseja marcar este agendamento como CONCLUÍDO?')) return;

                btnConcluir.disabled = true;

                fetch('cancelar_agendamento.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: appId, status: 'Concluído' })
                })
                .then(res => res.json())
                .then(res => {
                    alert(res.message);
                    if (res.success) {
                        closeModal();
                        location.reload();
                    } else {
                        btnConcluir.disabled = false;
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Erro de comunicação ao concluir agendamento.');
                    btnConcluir.disabled = false;
                });
            });
        }

        // CANCELAR AGENDAMENTO
        if (btnCancelar) {
            btnCancelar.addEventListener('click', function() {
                const appId = appIdInput ? appIdInput.value : '';
                if (!appId) return;

                if (!confirm('Tem certeza de que deseja CANCELAR este agendamento?')) return;

                btnCancelar.disabled = true;

                fetch('cancelar_agendamento.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: appId, status: 'Cancelado' })
                })
                .then(res => res.json())
                .then(res => {
                    alert(res.message);
                    if (res.success) {
                        closeModal();
                        location.reload();
                    } else {
                        btnCancelar.disabled = false;
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Erro de comunicação ao cancelar agendamento.');
                    btnCancelar.disabled = false;
                });
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
                            PetCrmSelect2.sync('#product-unidade');
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
                const filtrarEstoqueTabela = function() {
                    const searchTerm = (productSearchInput.value || '').toLowerCase().trim();
                    for (const row of tableRows) {
                        const rowText = row.textContent.toLowerCase();
                        row.style.display = (searchTerm === '' || rowText.includes(searchTerm)) ? "" : "none";
                    }
                };
                productSearchInput.addEventListener('input', filtrarEstoqueTabela);
                productSearchInput.addEventListener('change', filtrarEstoqueTabela);
                if (productSearchInput.tagName === 'SELECT') {
                    PetCrmSelect2.enhance(productSearchInput, {
                        placeholder: 'Buscar produto ou marca...',
                        allowClear: true,
                        tags: true
                    });
                }
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
                PetCrmSelect2.sync('#pet-species');
                PetCrmSelect2.sync('#pet-breed');
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
                PetCrmSelect2.sync(petBreedSelect);
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
                PetCrmSelect2.sync(petSpeciesSelect);
                PetCrmSelect2.sync(petBreedSelect);
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
                const filtrarClientesTabela = function() {
                    const searchTerm = (searchInput.value || '').toLowerCase().trim();
                    for (const row of tableRows) {
                        const rowText = row.textContent.toLowerCase();
                        row.style.display = (searchTerm === '' || rowText.includes(searchTerm)) ? "" : "none";
                    }
                };
                searchInput.addEventListener('input', filtrarClientesTabela);
                searchInput.addEventListener('change', filtrarClientesTabela);
                if (searchInput.tagName === 'SELECT') {
                    PetCrmSelect2.enhance(searchInput, {
                        placeholder: 'Buscar cliente, telefone ou e-mail...',
                        allowClear: true,
                        tags: true
                    });
                }
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
        let crmPreviewTimer = null;
        function renderizarResumoCrmBox(boxEl, data) {
            if (!boxEl || !data || !data.success) return;
            const linhas = [];
            if (data.promocoes && data.promocoes.aplicaveis) {
                data.promocoes.aplicaveis.forEach(pr => {
                    linhas.push(`<div style="color:#047857;">🏷️ <strong>${pr.titulo}</strong>: -R$ ${parseFloat(pr.desconto_valor || 0).toFixed(2).replace('.', ',')} (${pr.desconto_percentual}%)</div>`);
                });
            }
            if (data.regras_modulares) {
                data.regras_modulares.forEach(r => {
                    const descTxt = r.desconto_aplicado > 0 ? `(-R$ ${parseFloat(r.desconto_aplicado).toFixed(2).replace('.', ',')})` : '';
                    const cbTxt = r.cashback_gerado > 0 ? `(+R$ ${parseFloat(r.cashback_gerado).toFixed(2).replace('.', ',')} cashback)` : '';
                    const ptsTxt = r.pontos_gerados > 0 ? `(+${r.pontos_gerados} pts)` : '';
                    const progTxt = r.meta > 0 ? `${r.progresso_depois}/${r.meta}` : '';
                    linhas.push(`<div style="color:#0f766e;">⭐ <strong>${r.nome_regra}</strong>: ${r.status_label} ${progTxt} ${descTxt}${cbTxt}${ptsTxt}</div>`);
                });
            }
            if (data.promocoes && data.promocoes.bloqueadas) {
                data.promocoes.bloqueadas.forEach(b => {
                    linhas.push(`<div style="color:#dc2626;">⛔ <strong>${b.titulo}</strong>: ${b.motivo}</div>`);
                });
            }
            if (data.promocoes && data.promocoes.pendentes_identificacao) {
                data.promocoes.pendentes_identificacao.forEach(p => {
                    linhas.push(`<div style="color:#d97706;">🪪 <strong>${p.titulo}</strong>: ${p.motivo}</div>`);
                });
            }
            if (data.regras_bloqueadas) {
                data.regras_bloqueadas.forEach(rb => {
                    linhas.push(`<div style="color:#b91c1c;">⛔ <strong>${rb.nome_regra}</strong>: ${rb.motivo}</div>`);
                });
            }

            if (linhas.length === 0) {
                boxEl.style.display = 'none';
                boxEl.innerHTML = '';
                return;
            }
            const descTotalCrm = (parseFloat(data.desconto_promocao || 0) + parseFloat(data.desconto_fidelidade || 0));
            if (descTotalCrm > 0) {
                linhas.push(`<div style="margin-top:4px;padding-top:4px;border-top:1px dashed #99f6e4;font-weight:700;color:#065f46;">Total Estimado c/ Descontos CRM: R$ ${parseFloat(data.total_liquido || 0).toFixed(2).replace('.', ',')}</div>`);
            }
            boxEl.innerHTML = linhas.join('');
            boxEl.style.display = 'block';
        }

        function agendarPreviewCrm() {
            const previewBox = document.getElementById('pdv-crm-live-preview');
            if (!previewBox) return;
            if (carrinho.length === 0) {
                previewBox.style.display = 'none';
                previewBox.innerHTML = '';
                return;
            }
            clearTimeout(crmPreviewTimer);
            crmPreviewTimer = setTimeout(() => {
                const cliId = pdvClienteSelect ? (pdvClienteSelect.value || null) : null;
                const descManual = parseFloat(descontoInput.value.replace(',', '.')) || 0;
                fetch('api_fidelidade.php?acao=verificar_regras_carrinho', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        itens: carrinho,
                        cliente_id: cliId,
                        desconto_manual: descManual
                    })
                })
                .then(r => r.json())
                .then(data => renderizarResumoCrmBox(previewBox, data))
                .catch(() => {});
            }, 180);
        }

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
            agendarPreviewCrm();
        }

        function adicionarItem(id, nome, preco, tipo = 'produto') {
            const idInt = parseInt(id);
            const existente = carrinho.find(i => i.id == idInt && (i.tipo || 'produto') === tipo);
            if (existente) {
                existente.qtd++;
            } else {
                const obj = { id: idInt, tipo: tipo, nome: nome, preco: parseFloat(preco), qtd: 1 };
                if (tipo === 'servico' && idInt > 0) {
                    obj.servico_id = idInt;
                }
                carrinho.push(obj);
            }
            atualizarInterface();
        }

        // --- EXPORTAÇÃO PARA O HTML (ONCLICK) ---
        window.adicionarAoCarrinho = function(id, nome, preco) { adicionarItem(id, nome, preco, 'produto'); };
        window.adicionarServicoCatalogo = function(id, nome, preco) { adicionarItem(id, nome, preco, 'servico'); };
        window.adicionarServicoAvulso = function(nome, preco) { const idTemp = -Date.now(); adicionarItem(idTemp, nome, preco, 'servico'); };
        window.removerItem = function(index) { carrinho.splice(index, 1); atualizarInterface(); };

        // --- EVENTOS DE VENDAS ---

        // Busca Select2 AJAX (Produtos & Serviços do Catálogo) no PDV
        const pdvSearchSelect2 = document.getElementById('pdv-search-select2');
        if (pdvSearchSelect2 && typeof window.jQuery !== 'undefined' && typeof window.jQuery.fn.select2 === 'function') {
            const $pdvS2 = window.jQuery(pdvSearchSelect2);
            $pdvS2.select2({
                language: 'pt-BR',
                width: '100%',
                placeholder: 'Pesquisar produto ou serviço para adicionar...',
                allowClear: true,
                minimumInputLength: 0,
                ajax: {
                    url: 'buscar_produto_pdv.php',
                    dataType: 'json',
                    delay: 180,
                    data: params => ({ select2: 1, q: params.term || '' }),
                    processResults: data => ({ results: data.results || [] }),
                    cache: true
                }
            });
            $pdvS2.on('select2:select', function(e) {
                const item = e.params && e.params.data;
                if (item && item.raw_id) {
                    adicionarItem(item.raw_id, item.nome, item.preco, item.tipo || 'produto');
                }
                $pdvS2.val(null).trigger('change.select2');
            });
        }

        // Busca por ENTER (leitor de código de barras físico)
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

        // --- ELEMENTOS DOS MODAIS DE FIDELIZAÇÃO E WHATSAPP ---
        const modalFidelidadePdv = document.getElementById('modal-fidelidade-pdv');
        const modalSucessoPdv = document.getElementById('modal-sucesso-pdv');
        const pdvClienteSelect = document.getElementById('pdv-cliente');
        const pdvFidWhatsapp = document.getElementById('pdv-fid-whatsapp');
        const pdvFidNome = document.getElementById('pdv-fid-nome');
        const pdvBoxNomeCliente = document.getElementById('pdv-box-nome-cliente');
        const btnFecharModalFid = document.getElementById('btn-fechar-modal-fid');
        const btnPdvRecusarFid = document.getElementById('btn-pdv-recusar-fid');
        const btnPdvConfirmarFid = document.getElementById('btn-pdv-confirmar-fid');
        const btnNovoAtendimento = document.getElementById('btn-novo-atendimento');
        const btnPrintCupomSucesso = document.getElementById('btn-print-cupom-sucesso');

        // Máscara de Telefone WhatsApp: (00) 00000-0000
        if (pdvFidWhatsapp) {
            pdvFidWhatsapp.addEventListener('input', function(e) {
                let v = e.target.value.replace(/\D/g, '');
                if (v.length > 11) v = v.substring(0, 11);
                if (v.length > 10) {
                    v = v.replace(/^(\d{2})(\d{5})(\d{4})$/, '($1) $2-$3');
                } else if (v.length > 5) {
                    v = v.replace(/^(\d{2})(\d{4})(\d{0,4})$/, '($1) $2-$3');
                } else if (v.length > 2) {
                    v = v.replace(/^(\d{2})(\d{0,5})$/, '($1) $2');
                } else if (v.length > 0) {
                    v = v.replace(/^(\d*)$/, '($1');
                }
                e.target.value = v;
            });
        }

        // Atualiza WhatsApp e preview CRM automaticamente quando o operador troca o cliente no select
        if (pdvClienteSelect) {
            pdvClienteSelect.addEventListener('change', function() {
                const opt = this.selectedOptions[0];
                if (pdvFidWhatsapp && opt && opt.dataset.telefone) {
                    pdvFidWhatsapp.value = opt.dataset.telefone;
                }
                agendarPreviewCrm();
            });
        }

        if (pdvFidWhatsapp) {
            pdvFidWhatsapp.addEventListener('blur', function() {
                if (modalFidelidadePdv && modalFidelidadePdv.style.display === 'flex' && carrinho.length > 0) {
                    const cliId = pdvClienteSelect.value || null;
                    const descManual = parseFloat(descontoInput.value.replace(',', '.')) || 0;
                    fetch('api_fidelidade.php?acao=verificar_regras_carrinho', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            itens: carrinho,
                            cliente_id: cliId,
                            cliente_whatsapp: this.value.trim(),
                            desconto_manual: descManual
                        })
                    })
                    .then(r => r.json())
                    .then(data => {
                        const listaModal = document.getElementById('pdv-fid-lista-beneficios');
                        renderizarResumoCrmBox(listaModal, data);
                    })
                    .catch(() => {});
                }
            });
        }

        // Executa a finalização da venda enviando os dados para salvar_venda.php
        function executarFinalizacaoVenda(participarFidelidade, whatsappCliente, nomeCliente) {
            const originalText = btnFinalizar.innerHTML;
            btnFinalizar.disabled = true;
            btnFinalizar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...';

            const totalVendaFinal = totalGlobal;
            const dados = {
                cliente_id: pdvClienteSelect.value || null,
                total_venda: totalVendaFinal,
                desconto: parseFloat(descontoInput.value) || 0,
                forma_pagamento: document.getElementById('forma-pagamento').value,
                itens: carrinho,
                participar_fidelidade: participarFidelidade ? 1 : 0,
                cliente_whatsapp: whatsappCliente || '',
                cliente_nome: nomeCliente || ''
            };

            fetch('salvar_venda.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(dados)
            })
            .then(res => res.json())
            .then(res => {
                if (res.success) {
                    // Limpa carrinho da tela
                    carrinho = [];
                    descontoInput.value = "0.00";
                    atualizarInterface();

                    const valorPagoReal = (res.valor_total_pago !== undefined) ? parseFloat(res.valor_total_pago) : totalVendaFinal;
                    const descTotalReal = parseFloat(res.desconto_total || 0);
                    const descInfoEl = document.getElementById('sucesso-desconto-info');
                    if (descInfoEl) {
                        if (descTotalReal > 0) {
                            descInfoEl.innerHTML = `🏷️ Descontos aplicados: <strong>-R$ ${descTotalReal.toFixed(2).replace('.', ',')}</strong> (Promoções: R$ ${parseFloat(res.desconto_promocao || 0).toFixed(2).replace('.', ',')} | Regras: R$ ${parseFloat(res.desconto_fidelidade || 0).toFixed(2).replace('.', ',')})`;
                            descInfoEl.style.display = 'block';
                        } else {
                            descInfoEl.style.display = 'none';
                        }
                    }

                    // Se participou de fidelidade e gerou dados de WhatsApp
                    if (res.fidelidade && res.fidelidade.participou) {
                        const fid = res.fidelidade;
                        document.getElementById('sucesso-venda-num').textContent = res.venda_id;
                        document.getElementById('sucesso-venda-total').textContent = 'R$ ' + valorPagoReal.toFixed(2).replace('.', ',');
                        document.getElementById('sucesso-cli-nome').textContent = fid.cliente_nome;

                        let extraProg = '';
                        if (fid.regras_processadas && fid.regras_processadas.length > 0) {
                            extraProg = ' | ' + fid.regras_processadas.map(r => `${r.nome_regra}: ${r.progresso_depois}/${r.meta || '-'} (${r.status_label})`).join('; ');
                        }
                        document.getElementById('sucesso-cli-progresso').textContent = fid.selos_atuais + ' de ' + fid.meta_selos + ' selos' + extraProg;
                        
                        const badgeSelos = document.getElementById('sucesso-badge-selos');
                        if (fid.recompensa_desbloqueada) {
                            badgeSelos.textContent = '🎉 RECOMPENSA LIBERADA!';
                            badgeSelos.style.background = '#dcfce7';
                            badgeSelos.style.color = '#15803d';
                        } else {
                            badgeSelos.textContent = '✓ CRM Atualizado';
                            badgeSelos.style.background = '#fef3c7';
                            badgeSelos.style.color = '#b45309';
                        }

                        // Configura botão do WhatsApp
                        const btnWhats = document.getElementById('btn-enviar-whatsapp-sucesso');
                        const boxWhats = document.getElementById('sucesso-box-whatsapp');
                        if (fid.whatsapp_url) {
                            btnWhats.href = fid.whatsapp_url;
                            boxWhats.style.display = 'block';
                        } else {
                            boxWhats.style.display = 'none';
                        }

                        // Configura link do cartão virtual
                        const btnCartao = document.getElementById('btn-ver-cartao-sucesso');
                        if (fid.cartao_url) {
                            btnCartao.href = fid.cartao_url;
                            btnCartao.style.display = 'inline-flex';
                        } else {
                            btnCartao.style.display = 'none';
                        }

                        document.getElementById('sucesso-box-fidelidade').style.display = 'block';
                        modalSucessoPdv.style.display = 'flex';
                    } else {
                        // Venda comum sem fidelidade
                        document.getElementById('sucesso-venda-num').textContent = res.venda_id;
                        document.getElementById('sucesso-venda-total').textContent = 'R$ ' + valorPagoReal.toFixed(2).replace('.', ',');
                        document.getElementById('sucesso-box-fidelidade').style.display = 'none';
                        document.getElementById('sucesso-box-whatsapp').style.display = 'none';
                        document.getElementById('btn-ver-cartao-sucesso').style.display = 'none';
                        modalSucessoPdv.style.display = 'flex';
                    }

                    pdvClienteSelect.value = "";
                    PetCrmSelect2.sync(pdvClienteSelect);
                } else {
                    alert('Erro ao finalizar venda: ' + res.message);
                }
            })
            .catch(err => {
                console.error(err);
                alert('Erro de conexão ao salvar venda.');
            })
            .finally(() => {
                btnFinalizar.disabled = false;
                btnFinalizar.innerHTML = originalText;
            });
        }

        // Finalizar Venda com Checagem Automática de Fidelidade
        btnFinalizar.addEventListener('click', function() {
            if (carrinho.length === 0) { alert('Carrinho vazio!'); return; }

            const opt = pdvClienteSelect.selectedOptions[0];
            const clienteId = pdvClienteSelect.value || null;
            const descManual = parseFloat(descontoInput.value.replace(',', '.')) || 0;

            // Pré-preenche WhatsApp se o cliente já tiver cadastrado
            if (pdvFidWhatsapp) {
                pdvFidWhatsapp.value = (opt && opt.dataset.telefone) ? opt.dataset.telefone : '';
            }
            if (pdvBoxNomeCliente && pdvFidNome) {
                if (!clienteId) {
                    pdvBoxNomeCliente.style.display = 'block';
                    pdvFidNome.value = '';
                } else {
                    pdvBoxNomeCliente.style.display = 'none';
                    pdvFidNome.value = opt ? (opt.dataset.nome || '') : '';
                }
            }

            // Verifica se o carrinho é elegível para fidelidade e promoções
            fetch('api_fidelidade.php?acao=verificar_regras_carrinho', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    itens: carrinho,
                    cliente_id: clienteId,
                    desconto_manual: descManual
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.elegivel && modalFidelidadePdv) {
                    const regra = (data.regras && data.regras.length > 0) ? data.regras[0] : null;
                    const promo = (data.promocoes && data.promocoes.aplicaveis && data.promocoes.aplicaveis.length > 0) ? data.promocoes.aplicaveis[0] : null;
                    const nomeRegraEl = document.getElementById('pdv-fid-nome-regra');
                    const beneficioEl = document.getElementById('pdv-fid-beneficio');
                    const badgeMetaEl = document.getElementById('pdv-fid-badge-meta');
                    const bannerEl = document.getElementById('pdv-fid-banner');
                    const listaModalEl = document.getElementById('pdv-fid-lista-beneficios');

                    if (regra) {
                        if (nomeRegraEl) nomeRegraEl.textContent = regra.nome_regra || 'Cartão Fidelidade Pet VIP';
                        if (beneficioEl) beneficioEl.textContent = regra.beneficio ? ('Benefício: ' + regra.beneficio + ' (' + (regra.status_label || '') + ')') : 'Ganhe selos especiais nesta compra!';
                        if (badgeMetaEl) badgeMetaEl.textContent = regra.meta > 0 ? (`${regra.progresso_depois || 1} de ${regra.meta}`) : (regra.status_label || 'Regra Ativa');
                        if (bannerEl && regra.cor_hex) {
                            bannerEl.style.background = `linear-gradient(135deg, ${regra.cor_hex}, ${regra.cor_secundaria || '#2c3e50'})`;
                        }
                    } else if (promo) {
                        if (nomeRegraEl) nomeRegraEl.textContent = promo.titulo;
                        if (beneficioEl) beneficioEl.textContent = `Desconto de ${promo.desconto_percentual}% aplicado automaticamente no PDV!`;
                        if (badgeMetaEl) badgeMetaEl.textContent = `-R$ ${parseFloat(promo.desconto_valor || 0).toFixed(2).replace('.', ',')}`;
                    } else {
                        if (nomeRegraEl) nomeRegraEl.textContent = 'Política de Promoções / CRM';
                        if (beneficioEl) beneficioEl.textContent = 'Veja abaixo os detalhes e bloqueios de reentrada para este cliente.';
                        if (badgeMetaEl) badgeMetaEl.textContent = 'Verificação CRM';
                    }

                    renderizarResumoCrmBox(listaModalEl, data);
                    modalFidelidadePdv.style.display = 'flex';
                } else {
                    // Não tem regra ativa aplicável, finaliza direto
                    executarFinalizacaoVenda(false, '', '');
                }
            })
            .catch(err => {
                // Se falhar a verificação, finaliza sem travar o operador de caixa
                console.warn('Erro ao verificar fidelidade:', err);
                executarFinalizacaoVenda(false, '', '');
            });
        });

        // Eventos dos botões do Modal de Fidelidade
        if (btnPdvConfirmarFid && modalFidelidadePdv) {
            btnPdvConfirmarFid.addEventListener('click', function() {
                const whats = pdvFidWhatsapp ? pdvFidWhatsapp.value.trim() : '';
                const nome = pdvFidNome ? pdvFidNome.value.trim() : '';
                modalFidelidadePdv.style.display = 'none';
                executarFinalizacaoVenda(true, whats, nome);
            });
        }

        if (btnPdvRecusarFid && modalFidelidadePdv) {
            btnPdvRecusarFid.addEventListener('click', function() {
                modalFidelidadePdv.style.display = 'none';
                executarFinalizacaoVenda(false, '', '');
            });
        }

        if (btnFecharModalFid && modalFidelidadePdv) {
            btnFecharModalFid.addEventListener('click', function() {
                modalFidelidadePdv.style.display = 'none';
            });
        }

        // Eventos do Modal de Sucesso
        if (btnNovoAtendimento && modalSucessoPdv) {
            btnNovoAtendimento.addEventListener('click', function() {
                modalSucessoPdv.style.display = 'none';
                if (pdvSearchInput) pdvSearchInput.focus();
            });
        }

        if (btnPrintCupomSucesso) {
            btnPrintCupomSucesso.addEventListener('click', function() {
                window.print();
            });
        }

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

    // ===================================================
    // 6. GERENCIAMENTO DE TEMA ESCURO / CLARO (DARK MODE)
    // ===================================================
    const initThemeToggle = () => {
        const toggleBtn = document.getElementById('theme-toggle-btn');
        if (toggleBtn && !toggleBtn.onclick && window.toggleDarkMode) {
            toggleBtn.addEventListener('click', window.toggleDarkMode);
        }
    };

    initThemeToggle();


    // ===================================================
    // 7. MENU LATERAL RESPONSIVO (DRAWER MOBILE)
    // ===================================================
    const sidebarToggleBtn = document.getElementById('sidebar-toggle-btn');
    const sidebarCloseBtn = document.getElementById('sidebar-close-btn');
    const sidebarMenu = document.getElementById('sidebar-menu') || document.querySelector('.sidebar');
    const sidebarOverlay = document.getElementById('sidebar-overlay');

    const openSidebar = () => {
        if (sidebarMenu) sidebarMenu.classList.add('open');
        if (sidebarOverlay) sidebarOverlay.classList.add('active');
        document.body.classList.add('sidebar-open');
    };

    const closeSidebar = () => {
        if (sidebarMenu) sidebarMenu.classList.remove('open');
        if (sidebarOverlay) sidebarOverlay.classList.remove('active');
        document.body.classList.remove('sidebar-open');
    };

    if (sidebarToggleBtn) sidebarToggleBtn.addEventListener('click', openSidebar);
    if (sidebarCloseBtn) sidebarCloseBtn.addEventListener('click', closeSidebar);
    if (sidebarOverlay) sidebarOverlay.addEventListener('click', closeSidebar);

    // Fecha ao clicar em um link no mobile
    document.querySelectorAll('.sidebar-nav .nav-item').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 768) closeSidebar();
        });
    });

});
