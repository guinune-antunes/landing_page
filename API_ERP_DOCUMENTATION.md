# 📚 Documentação Técnica da API REST - PetCRM para ERPs

Esta API permite a integração bidirecional entre o **PetCRM** e sistemas externos de gestão e faturamento (**Bling, Tiny, Omie, Protheus/TOTVS, SAP, Linx, e-commerce ou PDVs legados**).

---

## 🔐 1. Autenticação

Todas as chamadas à API devem incluir a chave de autenticação da unidade no cabeçalho HTTP (**Header**):

```http
X-API-Key: SUA_CHAVE_DE_API
```
*Ou via Bearer Token:*
```http
Authorization: Bearer SUA_CHAVE_DE_API
```

> **Chave de Teste da Unidade VittaPet Jardins:**
> `petcrm_live_vittapet_jardins_a98f7e1b`

---

## 📡 2. Endpoints Disponíveis

* **URL Base Local:** `http://localhost/landing_page/sys/api_erp.php`
* **URL Base Docker / Servidor:** `http://IP_DO_SERVIDOR:8081/sys/api_erp.php`

---

### 1️⃣ `GET /sys/api_erp.php?action=status`
Verifica a conexão e retorna os dados cadastrais da loja autenticada.

**Exemplo cURL:**
```bash
curl -X GET "http://localhost/landing_page/sys/api_erp.php?action=status" \
     -H "X-API-Key: petcrm_live_vittapet_jardins_a98f7e1b"
```

**Resposta:**
```json
{
    "sucesso": true,
    "api": "PetCRM ERP REST API",
    "versao": "1.0.0",
    "timestamp": "2026-11-08T08:44:36-03:00",
    "instituicao": {
        "id": 255,
        "nome": "VittaPet Hospital & Mega Store - Jardins",
        "cnpj": "28.914.502/0001-99",
        "cidade": "São Paulo",
        "uf": "SP"
    },
    "metricas_crm": {
        "total_oportunidades": 8,
        "pendentes": 7,
        "concluidas": 1
    }
}
```

---

### 2️⃣ `GET /sys/api_erp.php?action=oportunidades`
Retorna a lista de reposições calculadas pelo **Motor Nutricional**, pronta para ser consumida por centrais de atendimento, televendas ou robôs de WhatsApp.

**Parâmetros de Query (Opcionais):**
* `filtro`: 
  * `quentes` *(padrão - rações que acabam em até 7 dias ou já esgotaram)*
  * `criticas` *(acabam em até 3 dias)*
  * `esgotadas` *(já terminaram - dias negativos)*
  * `em_uso` *(acima de 7 dias de consumo)*
  * `todas` *(todas as ativas)*
  * `historico` *(oportunidades já baixadas)*
* `dias_max`: Limite máximo de dias restantes (ex: `dias_max=5`).
* `busca`: Termo de busca (nome do tutor, pet ou ração).
* `limite`: Quantidade máxima de registros (padrão 50, máx 200).

**Exemplo cURL:**
```bash
curl -X GET "http://localhost/landing_page/sys/api_erp.php?action=oportunidades&filtro=quentes" \
     -H "X-API-Key: petcrm_live_vittapet_jardins_a98f7e1b"
```

**Resposta:**
```json
{
    "sucesso": true,
    "filtro_aplicado": "quentes",
    "total_encontrado": 1,
    "oportunidades": [
        {
            "oportunidade_id": 12,
            "status": "Pendente",
            "classificacao_urgencia": "ESGOTADA",
            "tutor": {
                "id": 1010,
                "nome": "Beatriz Mendonça",
                "telefone": "(11) 98711-2233",
                "email": "beatriz.mendonca@gmail.com",
                "cpf": "214.587.963-01"
            },
            "pet": {
                "id": 1201,
                "nome": "Apollo",
                "especie": "Canina",
                "raca": "Golden Retriever",
                "peso_kg": 34.0,
                "fator_manutencao": 1.6,
                "porte": "Grande"
            },
            "produto": {
                "id": 1030,
                "nome": "Ração Royal Canin Golden Retriever Adult 12kg",
                "marca": "Royal Canin",
                "tamanho_embalagem_kg": 12.0,
                "energia_metabolizavel_kg": 3900.0,
                "preco_venda": 299.90,
                "codigo_barras": "7891001000028"
            },
            "calculo_nutricional": {
                "consumo_diario_g": 396,
                "dias_duracao_total": 30,
                "dias_decorridos": 38,
                "dias_restantes": -8,
                "percentual_consumido": 100,
                "data_compra": "2026-10-01 11:00:00",
                "data_fim_prevista": "2026-10-31"
            },
            "link_whatsapp": "https://wa.me/5511987112233?text=Ol%C3%A1+Beatriz..."
        }
    ]
}
```

---

### 3️⃣ `POST /sys/api_erp.php?action=registrar_venda`
Disparado pelo ERP quando uma venda de ração é concluída (faturamento / emissão de NF-e). O PetCRM cadastra ou atualiza o cliente e o pet, registra a venda e inicia o ciclo do motor nutricional.

**Exemplo cURL:**
```bash
curl -X POST "http://localhost/landing_page/sys/api_erp.php?action=registrar_venda" \
     -H "X-API-Key: petcrm_live_vittapet_jardins_a98f7e1b" \
     -H "Content-Type: application/json" \
     -d '{
       "cliente": {
         "nome": "Marcos Vinicius",
         "cpf": "999.888.777-66",
         "telefone": "11988889999",
         "email": "marcos@email.com"
       },
       "pet": {
         "nome": "Maximus",
         "especie": "Canina",
         "raca": "Rottweiler",
         "peso_kg": 45.0,
         "fator_manutencao": 1.7
       },
       "produto": {
         "nome_produto": "Ração Royal Canin Golden Retriever Adult 12kg",
         "codigo_barras": "7891001000028",
         "preco_venda": 299.90,
         "tamanho_embalagem_kg": 12.0,
         "energia_metabolizavel_kg": 3900.0
       },
       "forma_pagamento": "Cartão Crédito"
     }'
```

**Resposta:**
```json
{
    "sucesso": true,
    "mensagem": "Venda ingerida com sucesso e ciclo preditivo do CRM iniciado!",
    "venda_id": 27,
    "oportunidade_crm": {
        "id": 27,
        "status": "Pendente",
        "cliente_nome": "Marcos Vinicius",
        "pet_nome": "Maximus",
        "produto_nome": "Ração Royal Canin Golden Retriever Adult 12kg",
        "tamanho_kg": 12,
        "dias_previstos": 22,
        "data_fim_prevista": "2026-11-30"
    }
}
```

---

### 4️⃣ `POST /sys/api_erp.php?action=dar_baixa`
Informa que a reposição foi realizada ou descartada, finalizando a oportunidade no CRM.

**Exemplo cURL:**
```bash
curl -X POST "http://localhost/landing_page/sys/api_erp.php?action=dar_baixa" \
     -H "X-API-Key: petcrm_live_vittapet_jardins_a98f7e1b" \
     -H "Content-Type: application/json" \
     -d '{
       "oportunidade_id": 27,
       "status": "Venda Fechada",
       "motivo": "Recompra faturada no ERP sob Pedido #8912"
     }'
```

**Resposta:**
```json
{
    "sucesso": true,
    "mensagem": "Oportunidade #27 atualizada para 'Venda Fechada' com sucesso!",
    "oportunidade_id": 27,
    "novo_status": "Venda Fechada"
}
```

---

### 5️⃣ `POST /sys/api_erp.php?action=atualizar_pet`
Atualiza o peso corporal e/ou o fator de manutenção do pet (ex: após pesagem na balança do consultório veterinário).

**Exemplo cURL:**
```bash
curl -X POST "http://localhost/landing_page/sys/api_erp.php?action=atualizar_pet" \
     -H "X-API-Key: petcrm_live_vittapet_jardins_a98f7e1b" \
     -H "Content-Type: application/json" \
     -d '{
       "cpf_cliente": "999.888.777-66",
       "nome_pet": "Maximus",
       "peso_kg": 47.2
     }'
```

---

### 6️⃣ `GET /sys/api_erp.php?action=produtos`
Lista todas as rações cadastradas na loja com suas especificações calóricas e peso da embalagem, permitindo que o ERP mapeie os códigos de barras e SKUs.
