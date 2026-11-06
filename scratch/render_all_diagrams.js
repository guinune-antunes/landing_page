const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const edgePath = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
const outDir = path.resolve(__dirname, '../img/fluxogramas');
if (!fs.existsSync(outDir)) {
    fs.mkdirSync(outDir, { recursive: true });
}

const diagrams = [
    {
        id: '01_visao_geral_arquitetura',
        title: '1. Visão Geral da Arquitetura em 4 Camadas',
        width: 1600,
        height: 1100,
        code: `graph TD
    subgraph Camada_Apresentacao["1. Apresentação & Navegação"]
        LP["Landing Page Institucional (index.php)"]
        AUTH_UI["Tela de Autenticação (login.php)"]
        DASH_SAAS["Painel Super Admin SaaS (dashboard_super.php)"]
        DASH_REDE["Painel Consolidado Rede (dashboard_rede.php)"]
        DASH_GER["Dashboard Gerencial Loja (dashboard_gerente.php)"]
        DASH_OP["Dashboard Operacional (dashboard_atendente.php)"]
    end

    subgraph Camada_Controle["2. Controle de Sessão & RBAC"]
        VERIF["Verificação de Login & Permissões (verifica_login.php)"]
        UNID_CTX["Contexto da Unidade Ativa (trocar_unidade.php)"]
        MOD_CHECK["Checagem de Módulos Contratados (temPermissao)"]
    end

    subgraph Camada_Negocio["3. Motores de Negócio (Módulos Core)"]
        MOD_AGENDA["Agenda & Serviços (Banho, Tosa, Clínica)"]
        MOD_PDV["PDV / Caixa Rápido & Vendas"]
        MOD_FIDEL["Fidelidade, Cashback & Promoções"]
        MOD_CRM["CRM Preditivo & Reposição de Ração"]
        MOD_ESTOQUE["Estoque Local & Inventário"]
        MOD_TRANSF["Motor de Transferências ERP (Alçadas & Audit Trail)"]
    end

    subgraph Camada_Dados["4. Base de Dados Relacional (MySQL)"]
        DB_TENANT["redes, instituicoes, usuario_unidades"]
        DB_USERS["usuarios, permissoes JSON"]
        DB_CORE["clientes, pets, servicos, produtos"]
        DB_FIN["vendas, venda_itens, oportunidades_venda"]
        DB_FIDEL["fidelidade_config, cliente_fidelidade, promocoes"]
        DB_ERP["transferencias_estoque, transferencias_auditoria"]
    end

    LP --> AUTH_UI
    AUTH_UI --> VERIF
    VERIF --> UNID_CTX
    UNID_CTX --> MOD_CHECK
    MOD_CHECK --> DASH_SAAS
    MOD_CHECK --> DASH_REDE
    MOD_CHECK --> DASH_GER
    MOD_CHECK --> DASH_OP

    DASH_OP --> MOD_AGENDA
    DASH_OP --> MOD_PDV
    DASH_GER --> MOD_CRM
    DASH_GER --> MOD_ESTOQUE
    DASH_REDE --> MOD_TRANSF

    MOD_AGENDA --> DB_CORE
    MOD_PDV --> DB_FIN
    MOD_FIDEL --> DB_FIDEL
    MOD_CRM --> DB_CORE
    MOD_ESTOQUE --> DB_CORE
    MOD_TRANSF --> DB_ERP`
    },
    {
        id: '02_autenticacao_rbac_unidades',
        title: '2. Ciclo de Autenticação, RBAC & Seleção de Unidade',
        width: 1500,
        height: 1250,
        code: `flowchart TD
    Inicio([Usuário acessa login.php]) --> InputCred[Digita Email e Senha]
    InputCred --> PostAuth[POST sys/auth.php]
    
    PostAuth --> ChecaExiste{Usuário existe & ativo?}
    ChecaExiste -- Não --> ErroLogin[Exibe erro: Credenciais Inválidas] --> Inicio
    ChecaExiste -- Sim --> ValidaSenha{password_verify confere?}
    
    ValidaSenha -- Não --> ErroLogin
    ValidaSenha -- Sim --> ChecaLoja{Instituição Ativa?}
    
    ChecaLoja -- Bloqueada --> ErroBloq[Exibe aviso: Instituição suspensa] --> Inicio
    ChecaLoja -- Ativa --> ChecaReset{precisa_mudar_senha == 1?}
    
    ChecaReset -- Sim --> RedirectReset[Redireciona para trocar_senha_obrigatorio.php]
    RedirectReset --> SalvaNovaSenha[Atualiza hash e zera flag] --> MontaSessao
    
    ChecaReset -- Não --> MontaSessao[Inicializa Sessão PHP]
    MontaSessao --> CarregaUnidades[Carrega unidades vinculadas em usuario_unidades]
    CarregaUnidades --> DefineAtiva[Define instituicao_id padrão na sessão]
    
    DefineAtiva --> RotaCargo{Qual o Cargo do Usuário?}
    
    RotaCargo -- Admin --> DashSuper[dashboard_super.php - Gestão SaaS]
    RotaCargo -- Gerente --> ChecaMulti{Tem acesso a 2+ unidades?}
    RotaCargo -- Atendente / Tosador --> DashOp[dashboard_atendente.php / agenda.php]
    RotaCargo -- Veterinário --> DashVet[agenda.php com Prontuários]
    
    ChecaMulti -- Sim --> TemAcessoRede{acesso_rede == 1?}
    ChecaMulti -- Não --> DashGerLoja[dashboard_gerente.php - Loja Única]
    TemAcessoRede -- Sim --> DashRede[dashboard_rede.php - Gerente Geral da Rede]
    TemAcessoRede -- Não --> DashGerLoja`
    },
    {
        id: '03_ciclo_agendamentos_recepcao',
        title: '3. Ciclo Operacional de Agendamentos & Recepção',
        width: 1650,
        height: 550,
        code: `flowchart LR
    subgraph Solicitacao["1. Recepção & Agendamento"]
        A1[Cliente solicita serviço] --> A2[Busca ou Cadastra Cliente e Pet]
        A2 --> A3[Seleciona Serviço, Profissional e Horário]
        A3 --> A4[Valida conflito na grade de agenda.php]
        A4 --> A5[Salva com Status 'Agendado']
    end

    subgraph Execucao["2. Chegada & Execução do Serviço"]
        A5 --> B1[Pet chega à loja]
        B1 --> B2[Atualiza Status para 'Confirmado' / 'Aguardando']
        B2 --> B3[Profissional inicia banho/tosa/consulta]
        B3 --> B4[Status 'Em Andamento']
        B4 --> B5[Finaliza procedimento -> Status 'Concluído']
    end

    subgraph Pos_Servico["3. Checkout & Retenção"]
        B5 --> C1[Dispara notificação de retirada ao tutor]
        C1 --> C2[Envia itens para o Caixa / PDV]
        C2 --> C3[Gera Oportunidade no CRM se vacina/ração próxima]
    end`
    },
    {
        id: '04_pdv_caixa_fidelidade',
        title: '4. Ponto de Venda (PDV), Caixa & Motor de Fidelidade',
        width: 1500,
        height: 1100,
        code: `flowchart TD
    PDV_Inicio([Operador abre PDV - vendas.php]) --> AddItem[Adiciona Produto via Código de Barras ou Serviço]
    AddItem --> IdentificaCli{Tutor identificado?}
    
    IdentificaCli -- Sim --> ConsultaFid[api_fidelidade.php: Consulta Saldo de Selos / Cashback]
    ConsultaFid --> AplicaRecompensa{Cliente possui recompensa disponível?}
    AplicaRecompensa -- Sim --> AbateValor[Aplica Desconto / Resgate de Recompensa] --> FormaPag
    AplicaRecompensa -- Não --> FormaPag
    IdentificaCli -- Não --> FormaPag[Seleciona Forma de Pagamento: Dinheiro, PIX, Cartão]
    
    FormaPag --> ConfirmaVenda[POST salvar_venda.php]
    
    subgraph Transacao_BD["Transação Atômica no Banco de Dados"]
        ConfirmaVenda --> InsereVenda[1. INSERT INTO vendas]
        InsereVenda --> InsereItens[2. INSERT INTO venda_itens]
        InsereItens --> BaixaEstoque[3. UPDATE produtos: decrementa quantidade_estoque]
        BaixaEstoque --> ProcessaFid[4. Acumula Selos/Pontos em cliente_fidelidade]
        ProcessaFid --> GravaTransacFid[5. INSERT INTO fidelidade_transacoes]
    end
    
    Transacao_BD --> SucessoVenda[Emite Comprovante / Cupom Não Fiscal]
    SucessoVenda --> PDV_Inicio`
    },
    {
        id: '05_transferencias_estoque_erp',
        title: '5. Motor de Transferências de Estoque ERP (Alçadas & Audit Trail)',
        width: 1650,
        height: 1450,
        code: `flowchart TD
    ReqInicio([Loja Destino necessita de produto]) --> ConsultaOrigem[Consulta saldo em tempo real na Fornecedora]
    ConsultaOrigem --> AbreReq[Abre Requisição: Produto + Quantidade]
    
    AbreReq --> AvaliaRegras{api_transferir_estoque.php: Avalia Matriz de Alçadas}
    
    AvaliaRegras -- Categoria = Vacina/Medicamento E exige_aprovacao_medicamentos=1 --> TravaSuperior[Exige Liberação do Gerente Geral]
    AvaliaRegras -- Valor Total > limite_alcada_loja --> TravaSuperior
    AvaliaRegras -- Valor <= Limite E Categoria Normal --> AlçadaLoja[Alçada Comum: Gestor Local pode autorizar]
    
    TravaSuperior --> StatusSuperior[Status: 'aguardando_aprovacao_superior']
    AlçadaLoja --> StatusTransito[Status: 'em_transito']
    
    StatusSuperior --> ReterEstoque[Estoque sai do saldo disponível e fica 'Retido' na Origem]
    StatusTransito --> ReterEstoque
    
    ReterEstoque --> LogAudit1[Audit Trail: Registra 'solicitacao_criada' com IP e Responsável]
    
    LogAudit1 --> AnaliseDecisao{Quem está analisando?}
    
    subgraph Decisao_Aprovacao["Decisão do Aprovador"]
        AnaliseDecisao -- Gestor Local --> ValidaPermLocal{Transferência exige superior?}
        ValidaPermLocal -- Sim --> BloqueioLocal[Bloqueado: Requer Gerente Geral da Rede]
        ValidaPermLocal -- Não --> GestorOpcoes{Autorizar ou Negar?}
        
        AnaliseDecisao -- Gerente Geral --> GestorOpcoes
        
        GestorOpcoes -- NEGAR --> PedeJustif[Obriga preenchimento de Justificativa Plausível]
        PedeJustif --> EstornoEstoque[Estorna quantidade retida de volta ao saldo da Origem]
        EstornoEstoque --> StatusNegada[Status: 'negada']
        StatusNegada --> LogAuditNeg[Audit Trail: Registra 'requisicao_negada' + Motivo]
        
        GestorOpcoes -- AUTORIZAR --> LiberaCarga[Debita estoque retido na Origem]
        LiberaCarga --> CreditaDestino[Credita quantidade no estoque da Loja Destino]
        CreditaDestino --> StatusConcluido[Status: 'recebida']
        StatusConcluido --> LogAuditOk[Audit Trail: Registra 'aprovacao_concluida']
    end
    
    StatusNegada --> FimProcesso([Processo Finalizado])
    StatusConcluido --> FimProcesso`
    },
    {
        id: '06_delegacao_ausencia_ferias',
        title: '6. Gestão de Ausência & Delegação Temporária (Férias)',
        width: 1450,
        height: 900,
        code: `flowchart TD
    GeralGeral([Gerente Geral no dashboard_rede.php]) --> ClicaDelegar[Clica em 'Programar Ausência / Substituto']
    ClicaDelegar --> SelecionaColab[Seleciona Colaborador Substituto]
    SelecionaColab --> DefineDatas[Define Data de Início e Data de Fim]
    DefineDatas --> SalvaDelegação[POST api_definir_gestor_unidade.php: tipo 'substituto']
    
    SalvaDelegação --> GravaBanco[(Grava substituto_id, ausente_inicio e ausente_fim em instituicoes)]
    
    GravaBanco --> ChecagemDiaria{Durante o login / operação: Hoje está entre Início e Fim?}
    
    ChecagemDiaria -- Sim --> ConcedeGestor[usuarioEhGestorUnidade retorna TRUE para o substituto]
    ConcedeGestor --> SubstitutoAprova[Substituto pode autorizar/negar requisições daquela loja]
    
    ChecagemDiaria -- Não --> ExpiraGestor[usuarioEhGestorUnidade retorna FALSE para o substituto]
    ExpiraGestor --> ApenasTitular[Apenas o Gestor Titular possui autoridade]`
    },
    {
        id: '07_diagrama_entidade_relacionamento',
        title: '7. Diagrama Entidade-Relacionamento (ER)',
        width: 1600,
        height: 1050,
        code: `erDiagram
    redes ||--o{ instituicoes : "agrupa unidades"
    instituicoes ||--o{ usuarios : "possui colaboradores"
    instituicoes ||--o{ usuario_unidades : "acessos permitidos"
    usuarios ||--o{ usuario_unidades : "possui vinculos"
    
    instituicoes ||--o{ clientes : "cadastra"
    clientes ||--o{ pets : "possui"
    
    instituicoes ||--o{ produtos : "estoca"
    instituicoes ||--o{ servicos : "oferece"
    
    instituicoes ||--o{ agendamentos : "agenda"
    pets ||--o{ agendamentos : "recebe"
    usuarios ||--o{ agendamentos : "atende"
    
    instituicoes ||--o{ vendas : "registra"
    clientes ||--o{ vendas : "compra"
    vendas ||--o{ venda_itens : "contem"
    produtos ||--o{ venda_itens : "fornece"
    
    instituicoes ||--o{ transferencias_estoque : "origem e destino"
    transferencias_estoque ||--o{ transferencias_auditoria : "possui trilha"
    
    clientes ||--o| cliente_fidelidade : "acumula beneficios"
    instituicoes ||--o{ promocoes : "divulga"`
    }
];

console.log('Iniciando renderização de ' + diagrams.length + ' diagramas para imagens PNG...');

for (let i = 0; i < diagrams.length; i++) {
    const d = diagrams[i];
    const htmlContent = `<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>${d.title}</title>
    <script src="https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js"></script>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 40px;
            background: #ffffff;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .header {
            margin-bottom: 25px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            color: #0f172a;
        }
        .header p {
            margin: 6px 0 0 0;
            font-size: 14px;
            color: #64748b;
        }
        .card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            display: inline-block;
        }
        .mermaid {
            display: flex;
            justify-content: center;
        }
        .node rect, .node circle, .node ellipse, .node polygon {
            stroke-width: 1.5px !important;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>PetCRM — ${d.title}</h1>
        <p>Documentação Arquitetural e Fluxos de Sistema</p>
    </div>
    <div class="card">
        <div class="mermaid">
${d.code}
        </div>
    </div>
    <script>
        mermaid.initialize({
            startOnLoad: true,
            theme: 'base',
            themeVariables: {
                primaryColor: '#e0f2fe',
                primaryTextColor: '#0369a1',
                primaryBorderColor: '#38bdf8',
                lineColor: '#64748b',
                secondaryColor: '#f1f5f9',
                tertiaryColor: '#ffffff'
            }
        });
    </script>
</body>
</html>`;

    const tempHtml = path.resolve(__dirname, `temp_${d.id}.html`);
    const outPng = path.resolve(outDir, `${d.id}.png`);

    fs.writeFileSync(tempHtml, htmlContent, 'utf8');

    console.log(`Renderizando [${i + 1}/${diagrams.length}]: ${d.title}...`);
    try {
        const fileUrl = 'file:///' + tempHtml.replace(/\\/g, '/');
        const args = [
            '--headless=new',
            '--force-device-scale-factor=2',
            '--virtual-time-budget=6000',
            `--window-size=${d.width},${d.height}`,
            `--screenshot=${outPng}`,
            fileUrl
        ];

        spawnSync(edgePath, args);
        if (fs.existsSync(outPng)) {
            const size = (fs.statSync(outPng).size / 1024).toFixed(1);
            console.log(`  -> Gerado com sucesso: ${d.id}.png (${size} KB)`);
        } else {
            console.warn(`  -> Aviso: arquivo ${d.id}.png não foi criado.`);
        }
    } catch (err) {
        console.error(`  -> Erro ao renderizar ${d.id}:`, err.message);
    } finally {
        if (fs.existsSync(tempHtml)) {
            fs.unlinkSync(tempHtml);
        }
    }
}

console.log('\nProcesso finalizado com sucesso! Todas as imagens estão salvas em img/fluxogramas/');
