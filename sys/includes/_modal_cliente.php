<div class="modal-overlay" id="client-modal" style="display: none;">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h2 id="client-modal-title">Novo Cliente</h2>
            <button class="close-modal-btn">&times;</button>
        </div>
        <div class="modal-body">
            <div class="client-form-section">
                <h4>Dados do Tutor</h4>
                <form class="appointment-form" id="client-form" onsubmit="return false;">
                    <div class="form-group"><label for="client-name">Nome Completo *</label><input type="text" id="client-name"></div>
                    <div class="form-group-row">
                        <div class="form-group"><label for="client-phone">Telefone</label><input type="tel" id="client-phone"></div>
                        <div class="form-group"><label for="client-email">Email</label><input type="email" id="client-email"></div>
                    </div>
                    <div class="form-group-row">
                         <div class="form-group" style="flex: 0 0 120px;"><label for="client-cep">CEP</label><input type="text" id="client-cep"></div>
                         <div class="form-group"><label for="client-street">Rua/Logradouro</label><input type="text" id="client-street"></div>
                         <div class="form-group" style="flex: 0 0 80px;"><label for="client-number">Nº</label><input type="text" id="client-number"></div>
                    </div>
                     <div class="form-group-row">
                        <div class="form-group"><label for="client-neighborhood">Bairro</label><input type="text" id="client-neighborhood"></div>
                        <div class="form-group"><label for="client-city">Cidade</label><input type="text" id="client-city"></div>
                         <div class="form-group" style="flex: 0 0 70px;"><label for="client-state">UF</label><input type="text" id="client-state" maxlength="2"></div>
                    </div>
                </form>
            </div>
            <div class="pet-management-section">
                <h4>Pets do Tutor</h4>
                <div class="pet-list-container"><ul id="pet-list"></ul></div>
                <form class="pet-form" id="pet-form" onsubmit="return false;">
                    <h5>Adicionar Novo Pet</h5>
                    <div class="form-group"><label for="pet-name">Nome do Pet *</label><input type="text" id="pet-name"></div>
                    <div class="form-group-row">
                        <div class="form-group">
                            <label for="pet-species">Espécie</label>
                            <select id="pet-species"><option value="">Selecione</option><option value="Cão">Cão</option><option value="Gato">Gato</option></select>
                        </div>
                        <div class="form-group"><label for="pet-breed">Raça</label>
                            <select id="pet-breed" disabled><option value="">Selecione a espécie primeiro</option></select>
                        </div>
                    </div>
                    <div class="form-group"><label for="pet-birthdate">Data de Nascimento</label><input type="date" id="pet-birthdate"></div>
                    <button type="submit" class="btn btn-secondary" style="width: auto; font-size: 0.8rem; padding: 6px 12px;"><i class="fas fa-plus"></i> Adicionar Pet à Lista</button>
                </form>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary cancel-btn">Cancelar</button>
            <button class="btn btn-primary" id="saveClientBtn">Salvar Cliente e Pets</button>
        </div>
    </div>
</div>