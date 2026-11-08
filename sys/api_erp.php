<?php
/**
 * ============================================================================
 * API REST de Integração ERP & CRM Nutricional - PetCRM
 * ============================================================================
 * Permite que sistemas externos de ERP (Bling, Tiny, Omie, Protheus/TOTVS, SAP, etc.)
 * se comuniquem com o PetCRM para:
 *   1. Consultar oportunidades de reposição de ração (GET /oportunidades)
 *   2. Ingerir vendas de ração e disparar o motor preditivo (POST /registrar_venda)
 *   3. Dar baixa ou cancelar reposições (POST /dar_baixa)
 *   4. Consultar produtos e dados calóricos (GET /produtos)
 *   5. Atualizar ficha e peso do pet (POST /atualizar_pet)
 * 
 * Autenticação:
 *   Header 'X-API-Key: <token>' ou 'Authorization: Bearer <token>'
 * ============================================================================
 */

header('Content-Type: application/json; charset=utf-8');

// Configurações de exibição de erros
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/includes/motor_nutricional.php';

// Funções de resposta padronizada JSON
function responderJson(array $dados, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function responderErro(string $mensagem, int $statusCode = 400, ?array $detalhes = null): void {
    http_response_code($statusCode);
    $resposta = [
        'sucesso' => false,
        'erro' => $mensagem
    ];
    if ($detalhes !== null) {
        $resposta['detalhes'] = $detalhes;
    }
    echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ----------------------------------------------------------------------------
// 1. EXTRAÇÃO E VALIDAÇÃO DA CHAVE DE API (API KEY)
// ----------------------------------------------------------------------------
$apiToken = null;

// Tenta obter dos headers HTTP
$headers = function_exists('getallheaders') ? getallheaders() : [];

// Procura por X-API-Key (case-insensitive)
foreach ($headers as $nomeHeader => $valorHeader) {
    if (strtolower($nomeHeader) === 'x-api-key') {
        $apiToken = trim($valorHeader);
        break;
    }
    if (strtolower($nomeHeader) === 'authorization') {
        if (preg_match('/Bearer\s+(.*)$/i', $valorHeader, $matches)) {
            $apiToken = trim($matches[1]);
            break;
        }
    }
}

// Fallback para variáveis $_SERVER
if (!$apiToken) {
    if (!empty($_SERVER['HTTP_X_API_KEY'])) {
        $apiToken = trim($_SERVER['HTTP_X_API_KEY']);
    } elseif (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
            $apiToken = trim($matches[1]);
        }
    } elseif (!empty($_GET['api_key'])) {
        $apiToken = trim($_GET['api_key']);
    } elseif (!empty($_POST['api_key'])) {
        $apiToken = trim($_POST['api_key']);
    }
}

if (!$apiToken) {
    responderErro('Não autorizado. Envie a chave de API no header "X-API-Key" ou "Authorization: Bearer <TOKEN>".', 401);
}

// Valida token no banco de dados
try {
    $stmtInst = $pdo->prepare("SELECT id, nome_fantasia, cnpj, plano, ativo, rede_id, nome_unidade, cidade, uf FROM instituicoes WHERE api_token = ? LIMIT 1");
    $stmtInst->execute([$apiToken]);
    $instituicaoAutenticada = $stmtInst->fetch(PDO::FETCH_ASSOC);

    if (!$instituicaoAutenticada) {
        responderErro('Chave de API inválida ou revogada.', 401);
    }

    if ((int)$instituicaoAutenticada['ativo'] !== 1) {
        responderErro('Instituição vinculada a esta chave está inativa ou bloqueada.', 403);
    }

    $idLoja = (int)$instituicaoAutenticada['id'];

} catch (PDOException $e) {
    responderErro('Erro interno de validação: ' . $e->getMessage(), 500);
}

// ----------------------------------------------------------------------------
// 2. PARSE DA REQUISIÇÃO (MÉTODO, ACTION E PAYLOAD)
// ----------------------------------------------------------------------------
$metodoHttp = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Determina a action (via query param ?action=... ou PATH_INFO)
$action = $_GET['action'] ?? '';
if (empty($action) && !empty($_SERVER['PATH_INFO'])) {
    $action = trim($_SERVER['PATH_INFO'], '/');
}
if (empty($action)) {
    $action = 'status';
}

// Lê corpo da requisição JSON para POST / PUT / PATCH
$rawInput = file_get_contents('php://input');
if (empty($rawInput) && !empty($GLOBALS['ERP_API_RAW_INPUT'])) {
    $rawInput = $GLOBALS['ERP_API_RAW_INPUT'];
}
$bodyJson = [];
if (!empty($rawInput)) {
    $bodyJson = json_decode($rawInput, true);
    if ($bodyJson === null && json_last_error() !== JSON_ERROR_NONE && ($metodoHttp === 'POST' || $metodoHttp === 'PUT')) {
        responderErro('JSON malformado no corpo da requisição: ' . json_last_error_msg(), 400);
    }
}

// ----------------------------------------------------------------------------
// 3. ROTEAMENTO DOS ENDPOINTS DA API
// ----------------------------------------------------------------------------
try {
    switch ($action) {

        // ====================================================================
        // ENDPOINT: status (GET) - Informações da API e da Loja
        // ====================================================================
        case 'status':
        case 'info':
            if ($metodoHttp !== 'GET') responderErro('Método não permitido. Use GET.', 405);

            // Contadores rápidos de saúde do CRM
            $stCount = $pdo->prepare("SELECT 
                COUNT(*) as total_crm,
                SUM(CASE WHEN status = 'Pendente' THEN 1 ELSE 0 END) as pendentes,
                SUM(CASE WHEN status = 'Venda Fechada' THEN 1 ELSE 0 END) as fechadas
                FROM oportunidades_venda WHERE instituicao_id = ?");
            $stCount->execute([$idLoja]);
            $resumoCRM = $stCount->fetch(PDO::FETCH_ASSOC);

            responderJson([
                'sucesso' => true,
                'api' => 'PetCRM ERP REST API',
                'versao' => '1.0.0',
                'timestamp' => (new DateTime())->format(DateTime::ATOM),
                'instituicao' => [
                    'id' => $idLoja,
                    'nome' => $instituicaoAutenticada['nome_fantasia'],
                    'unidade' => $instituicaoAutenticada['nome_unidade'],
                    'cnpj' => $instituicaoAutenticada['cnpj'],
                    'cidade' => $instituicaoAutenticada['cidade'],
                    'uf' => $instituicaoAutenticada['uf'],
                    'rede_id' => $instituicaoAutenticada['rede_id']
                ],
                'metricas_crm' => [
                    'total_oportunidades' => (int)($resumoCRM['total_crm'] ?? 0),
                    'pendentes' => (int)($resumoCRM['pendentes'] ?? 0),
                    'concluidas' => (int)($resumoCRM['fechadas'] ?? 0)
                ]
            ]);
            break;

        // ====================================================================
        // ENDPOINT: oportunidades (GET) - Consulta de Reposições para Televendas/SAC
        // ====================================================================
        case 'oportunidades':
            if ($metodoHttp !== 'GET') responderErro('Método não permitido. Use GET.', 405);

            $filtro = $_GET['filtro'] ?? 'quentes'; // 'quentes', 'criticas', 'esgotadas', 'todas', 'historico'
            $diasMaxParam = isset($_GET['dias_max']) ? (int)$_GET['dias_max'] : null;
            $busca = trim($_GET['busca'] ?? '');
            $limite = isset($_GET['limite']) ? min(200, max(1, (int)$_GET['limite'])) : 50;

            $hoje = new DateTime();

            $sql = "SELECT o.id as oportunidade_id, o.data_compra, o.status, 
                           c.id as cliente_id, c.nome_completo as tutor_nome, c.telefone as tutor_telefone, c.email as tutor_email, c.cpf as tutor_cpf,
                           p.id as pet_id, p.nome as pet_nome, p.especie as pet_especie, p.raca as pet_raca, p.peso_kg as pet_peso, p.fator_manutencao as pet_fator, p.porte as pet_porte,
                           prod.id as produto_id, prod.nome_produto, prod.marca as produto_marca, prod.preco_venda, prod.tamanho_embalagem_kg, prod.energia_metabolizavel_kg, prod.codigo_barras
                    FROM oportunidades_venda o
                    JOIN clientes c ON o.cliente_id = c.id
                    JOIN pets p ON o.pet_id = p.id
                    JOIN produtos prod ON o.produto_id = prod.id 
                    WHERE o.instituicao_id = ?";

            $params = [$idLoja];

            if ($filtro === 'historico') {
                $sql .= " AND o.status != 'Pendente'";
            } else {
                $sql .= " AND o.status = 'Pendente'";
            }

            if (!empty($busca)) {
                $sql .= " AND (c.nome_completo LIKE ? OR p.nome LIKE ? OR prod.nome_produto LIKE ? OR c.telefone LIKE ?)";
                $termoBusca = "%$busca%";
                $params = array_merge($params, [$termoBusca, $termoBusca, $termoBusca, $termoBusca]);
            }

            $sql .= " ORDER BY o.data_compra ASC";

            $stmtOp = $pdo->prepare($sql);
            $stmtOp->execute($params);
            $registros = $stmtOp->fetchAll(PDO::FETCH_ASSOC);

            $oportunidadesRetorno = [];

            foreach ($registros as $row) {
                // Cálculo via Motor Nutricional
                $diasDuracao = calcularDiasRacao(
                    $row['pet_peso'],
                    $row['pet_fator'],
                    $row['energia_metabolizavel_kg'],
                    $row['tamanho_embalagem_kg']
                );

                if (!$diasDuracao || $diasDuracao <= 0) {
                    $diasDuracao = 30; // fallback se faltar dado
                }

                $dataCompra = new DateTime($row['data_compra']);
                $dataFimPrevista = clone $dataCompra;
                $dataFimPrevista->modify("+$diasDuracao days");

                $diff = $hoje->diff($dataFimPrevista);
                $diasRestantes = $diff->invert ? -$diff->days : $diff->days;

                // Consumo diário em gramas
                $ner = 70 * pow((float)$row['pet_peso'], 0.75);
                $ned = $ner * (float)$row['pet_fator'];
                $consumoDiaG = $row['energia_metabolizavel_kg'] > 0 ? round(($ned / (float)$row['energia_metabolizavel_kg']) * 1000) : 0;

                // Dias decorridos e percentual
                $diffCompra = $dataCompra->diff($hoje);
                $diasDecorridos = $diffCompra->invert ? 0 : $diffCompra->days;
                $percentualConsumido = min(100, max(0, round(($diasDecorridos / $diasDuracao) * 100)));

                // Classificação
                if ($diasRestantes < 0) {
                    $classificacao = 'ESGOTADA';
                } elseif ($diasRestantes === 0) {
                    $classificacao = 'CRITICA_HOJE';
                } elseif ($diasRestantes <= 3) {
                    $classificacao = 'CRITICA_72H';
                } elseif ($diasRestantes <= 7) {
                    $classificacao = 'JANELA_IDEAL';
                } else {
                    $classificacao = 'EM_USO_SEGURO';
                }

                // Filtros de negócio
                if ($filtro === 'quentes' && $diasRestantes > 7) continue;
                if ($filtro === 'criticas' && ($diasRestantes < 0 || $diasRestantes > 3)) continue;
                if ($filtro === 'esgotadas' && $diasRestantes >= 0) continue;
                if ($filtro === 'em_uso' && $diasRestantes <= 7) continue;
                if ($diasMaxParam !== null && $diasRestantes > $diasMaxParam) continue;

                // Mensagem formatada para WhatsApp
                $telefoneLimpo = preg_replace('/\D/', '', $row['tutor_telefone'] ?? '');
                if (!empty($telefoneLimpo) && substr($telefoneLimpo, 0, 2) !== '55') {
                    $telefoneLimpo = '55' . $telefoneLimpo;
                }
                $primeiroNome = explode(' ', trim($row['tutor_nome']))[0];
                $dataFimFormatada = $dataFimPrevista->format('d/m/Y');

                if ($diasRestantes < 0) {
                    $msgZap = "Olá $primeiroNome! 🐾 Notamos pelo nosso controle nutricional que a ração do(a) {$row['pet_nome']} ({$row['nome_produto']}) provavelmente já chegou ao fim. Gostaria de pedir um novo pacote para entrega?";
                } elseif ($diasRestantes === 0) {
                    $msgZap = "Olá $primeiroNome! 🐾 A ração do(a) {$row['pet_nome']} ({$row['nome_produto']}) tem previsão de acabar hoje! Gostaria que reservássemos um novo pacote?";
                } else {
                    $msgZap = "Olá $primeiroNome! 🐾 Tudo bem? A ração do(a) {$row['pet_nome']} ({$row['nome_produto']}) deve acabar por volta do dia $dataFimFormatada. Podemos adiantar seu pedido?";
                }
                $linkWhatsApp = !empty($telefoneLimpo) ? "https://wa.me/$telefoneLimpo?text=" . urlencode($msgZap) : null;

                $oportunidadesRetorno[] = [
                    'oportunidade_id' => (int)$row['oportunidade_id'],
                    'status' => $row['status'],
                    'classificacao_urgencia' => $classificacao,
                    'tutor' => [
                        'id' => (int)$row['cliente_id'],
                        'nome' => $row['tutor_nome'],
                        'telefone' => $row['tutor_telefone'],
                        'email' => $row['tutor_email'],
                        'cpf' => $row['tutor_cpf']
                    ],
                    'pet' => [
                        'id' => (int)$row['pet_id'],
                        'nome' => $row['pet_nome'],
                        'especie' => $row['pet_especie'],
                        'raca' => $row['pet_raca'],
                        'peso_kg' => (float)$row['pet_peso'],
                        'fator_manutencao' => (float)$row['pet_fator'],
                        'porte' => $row['pet_porte']
                    ],
                    'produto' => [
                        'id' => (int)$row['produto_id'],
                        'nome' => $row['nome_produto'],
                        'marca' => $row['produto_marca'],
                        'tamanho_embalagem_kg' => (float)$row['tamanho_embalagem_kg'],
                        'energia_metabolizavel_kg' => (float)$row['energia_metabolizavel_kg'],
                        'preco_venda' => (float)$row['preco_venda'],
                        'codigo_barras' => $row['codigo_barras']
                    ],
                    'calculo_nutricional' => [
                        'consumo_diario_g' => $consumoDiaG,
                        'dias_duracao_total' => $diasDuracao,
                        'dias_decorridos' => $diasDecorridos,
                        'dias_restantes' => $diasRestantes,
                        'percentual_consumido' => $percentualConsumido,
                        'data_compra' => $dataCompra->format('Y-m-d H:i:s'),
                        'data_fim_prevista' => $dataFimPrevista->format('Y-m-d')
                    ],
                    'link_whatsapp' => $linkWhatsApp
                ];

                if (count($oportunidadesRetorno) >= $limite) {
                    break;
                }
            }

            // Ordena: os que acabam antes ou já acabaram primeiro
            usort($oportunidadesRetorno, function($a, $b) {
                return $a['calculo_nutricional']['dias_restantes'] <=> $b['calculo_nutricional']['dias_restantes'];
            });

            responderJson([
                'sucesso' => true,
                'filtro_aplicado' => $filtro,
                'total_encontrado' => count($oportunidadesRetorno),
                'oportunidades' => $oportunidadesRetorno
            ]);
            break;

        // ====================================================================
        // ENDPOINT: registrar_venda (POST) - Ingestão de Venda do ERP
        // ====================================================================
        case 'registrar_venda':
            if ($metodoHttp !== 'POST') responderErro('Método não permitido. Use POST.', 405);

            $payload = !empty($bodyJson) ? $bodyJson : $_POST;

            // Validações básicas de entrada
            if (empty($payload['cliente']['nome'])) {
                responderErro('Campo obrigatório ausente: cliente.nome', 422);
            }
            if (empty($payload['pet']['nome'])) {
                responderErro('Campo obrigatório ausente: pet.nome', 422);
            }
            if (empty($payload['produto']['nome_produto'])) {
                responderErro('Campo obrigatório ausente: produto.nome_produto', 422);
            }

            $pdo->beginTransaction();

            // 1. Cadastra ou Localiza o Cliente
            $cliNome = trim($payload['cliente']['nome']);
            $cliCpf = trim($payload['cliente']['cpf'] ?? '');
            $cliTel = trim($payload['cliente']['telefone'] ?? '');
            $cliEmail = trim($payload['cliente']['email'] ?? '');
            $cliCidade = trim($payload['cliente']['cidade'] ?? $instituicaoAutenticada['cidade'] ?? 'São Paulo');

            $clienteId = null;
            if (!empty($cliCpf)) {
                $stBuscaCli = $pdo->prepare("SELECT id FROM clientes WHERE instituicao_id = ? AND cpf = ? LIMIT 1");
                $stBuscaCli->execute([$idLoja, $cliCpf]);
                $clienteId = $stBuscaCli->fetchColumn();
            }
            if (!$clienteId && !empty($cliTel)) {
                $digitsTel = preg_replace('/\D/', '', $cliTel);
                if (strlen($digitsTel) >= 8) {
                    $last8 = substr($digitsTel, -8);
                    $stBuscaTel = $pdo->prepare("SELECT id FROM clientes WHERE instituicao_id = ? AND REPLACE(REPLACE(REPLACE(REPLACE(telefone, '(', ''), ')', ''), '-', ''), ' ', '') LIKE ? LIMIT 1");
                    $stBuscaTel->execute([$idLoja, "%$last8%"]);
                    $clienteId = $stBuscaTel->fetchColumn();
                }
            }
            if (!$clienteId) {
                $stBuscaNome = $pdo->prepare("SELECT id FROM clientes WHERE instituicao_id = ? AND nome_completo = ? LIMIT 1");
                $stBuscaNome->execute([$idLoja, $cliNome]);
                $clienteId = $stBuscaNome->fetchColumn();
            }

            if ($clienteId) {
                // Atualiza telefone/email se fornecidos
                $stUpCli = $pdo->prepare("UPDATE clientes SET telefone = COALESCE(NULLIF(?, ''), telefone), email = COALESCE(NULLIF(?, ''), email) WHERE id = ?");
                $stUpCli->execute([$cliTel, $cliEmail, $clienteId]);
            } else {
                $stInCli = $pdo->prepare("INSERT INTO clientes (instituicao_id, nome_completo, cpf, telefone, email, cidade, data_cadastro) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $stInCli->execute([$idLoja, $cliNome, $cliCpf ?: null, $cliTel ?: null, $cliEmail ?: null, $cliCidade]);
                $clienteId = (int)$pdo->lastInsertId();
            }

            // 2. Cadastra ou Localiza o Pet
            $petNome = trim($payload['pet']['nome']);
            $petPeso = !empty($payload['pet']['peso_kg']) ? (float)$payload['pet']['peso_kg'] : 10.0;
            $petFator = !empty($payload['pet']['fator_manutencao']) ? (float)$payload['pet']['fator_manutencao'] : 1.5;
            $petEspecie = trim($payload['pet']['especie'] ?? 'Canina');
            $petRaca = trim($payload['pet']['raca'] ?? 'Não especificada');
            $petPorte = trim($payload['pet']['porte'] ?? ($petPeso > 25 ? 'Grande' : ($petPeso > 10 ? 'Médio' : 'Pequeno')));

            $stBuscaPet = $pdo->prepare("SELECT id FROM pets WHERE cliente_id = ? AND nome = ? LIMIT 1");
            $stBuscaPet->execute([$clienteId, $petNome]);
            $petId = $stBuscaPet->fetchColumn();

            if ($petId) {
                $stUpPet = $pdo->prepare("UPDATE pets SET peso_kg = ?, fator_manutencao = ?, porte = ?, instituicao_id = ? WHERE id = ?");
                $stUpPet->execute([$petPeso, $petFator, $petPorte, $idLoja, $petId]);
            } else {
                $stInPet = $pdo->prepare("INSERT INTO pets (instituicao_id, cliente_id, nome, especie, raca, peso_kg, fator_manutencao, porte) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stInPet->execute([$idLoja, $clienteId, $petNome, $petEspecie, $petRaca, $petPeso, $petFator, $petPorte]);
                $petId = (int)$pdo->lastInsertId();
            }

            // 3. Cadastra ou Localiza o Produto de Ração
            $prodData = $payload['produto'];
            $prodNome = trim($prodData['nome_produto']);
            $prodBarcode = trim($prodData['codigo_barras'] ?? '');
            $prodPreco = !empty($prodData['preco_venda']) ? (float)$prodData['preco_venda'] : 0.00;
            $prodEmbalagemKg = !empty($prodData['tamanho_embalagem_kg']) ? (float)$prodData['tamanho_embalagem_kg'] : 15.0;
            $prodEnergiaKcal = !empty($prodData['energia_metabolizavel_kg']) ? (float)$prodData['energia_metabolizavel_kg'] : 3800.0;
            $prodMarca = trim($prodData['marca'] ?? 'Marca Registrada');

            $produtoId = null;
            if (!empty($prodBarcode)) {
                $stProdBar = $pdo->prepare("SELECT id FROM produtos WHERE instituicao_id = ? AND codigo_barras = ? LIMIT 1");
                $stProdBar->execute([$idLoja, $prodBarcode]);
                $produtoId = $stProdBar->fetchColumn();
            }
            if (!$produtoId) {
                $stProdNome = $pdo->prepare("SELECT id FROM produtos WHERE instituicao_id = ? AND nome_produto = ? LIMIT 1");
                $stProdNome->execute([$idLoja, $prodNome]);
                $produtoId = $stProdNome->fetchColumn();
            }

            if ($produtoId) {
                $stUpProd = $pdo->prepare("UPDATE produtos SET tamanho_embalagem_kg = ?, energia_metabolizavel_kg = ?, preco_venda = COALESCE(NULLIF(?, 0), preco_venda) WHERE id = ?");
                $stUpProd->execute([$prodEmbalagemKg, $prodEnergiaKcal, $prodPreco, $produtoId]);
            } else {
                $stInProd = $pdo->prepare("INSERT INTO produtos (instituicao_id, nome_produto, marca, categoria, preco_venda, tamanho_embalagem_kg, energia_metabolizavel_kg, codigo_barras, quantidade_estoque) VALUES (?, ?, ?, 'Ração', ?, ?, ?, ?, 50)");
                $stInProd->execute([$idLoja, $prodNome, $prodMarca, $prodPreco, $prodEmbalagemKg, $prodEnergiaKcal, $prodBarcode ?: null]);
                $produtoId = (int)$pdo->lastInsertId();
            }

            // 4. Registra a Venda no Histórico
            $dataVendaStr = !empty($payload['data_venda']) ? $payload['data_venda'] : date('Y-m-d H:i:s');
            $formaPagamento = trim($payload['forma_pagamento'] ?? 'ERP');
            $valorVenda = $prodPreco ?: ($prodEmbalagemKg * 15.0);

            $stVenda = $pdo->prepare("INSERT INTO vendas (instituicao_id, cliente_id, data_venda, valor_total, forma_pagamento) VALUES (?, ?, ?, ?, ?)");
            $stVenda->execute([$idLoja, $clienteId, $dataVendaStr, $valorVenda, $formaPagamento]);
            $vendaId = (int)$pdo->lastInsertId();

            $stItem = $pdo->prepare("INSERT INTO venda_itens (venda_id, produto_id, nome_produto, quantidade, preco_unitario, subtotal) VALUES (?, ?, ?, 1, ?, ?)");
            $stItem->execute([$vendaId, $produtoId, $prodNome, $valorVenda, $valorVenda]);

            // 5. Motor Nutricional e Atualização da Esteira do CRM
            $diasDuracao = calcularDiasRacao($petPeso, $petFator, $prodEnergiaKcal, $prodEmbalagemKg);
            if (!$diasDuracao || $diasDuracao <= 0) {
                $diasDuracao = 30;
            }

            $dtCompraObj = new DateTime($dataVendaStr);
            $dtFimPrevista = clone $dtCompraObj;
            $dtFimPrevista->modify("+$diasDuracao days");

            // Fecha reposições pendentes anteriores do mesmo pet (ciclo renovado!)
            $pdo->prepare("UPDATE oportunidades_venda SET status = 'Venda Fechada' WHERE pet_id = ? AND status = 'Pendente'")
                ->execute([$petId]);

            // Cria nova oportunidade Pendente
            $stInOp = $pdo->prepare("INSERT INTO oportunidades_venda (instituicao_id, cliente_id, pet_id, produto_id, produto_nome, data_compra, status) VALUES (?, ?, ?, ?, ?, ?, 'Pendente')");
            $stInOp->execute([$idLoja, $clienteId, $petId, $produtoId, $prodNome, $dataVendaStr]);
            $oportunidadeId = (int)$pdo->lastInsertId();

            $pdo->commit();

            responderJson([
                'sucesso' => true,
                'mensagem' => 'Venda ingerida com sucesso e ciclo preditivo do CRM iniciado!',
                'venda_id' => $vendaId,
                'oportunidade_crm' => [
                    'id' => $oportunidadeId,
                    'status' => 'Pendente',
                    'cliente_id' => $clienteId,
                    'cliente_nome' => $cliNome,
                    'pet_id' => $petId,
                    'pet_nome' => $petNome,
                    'produto_nome' => $prodNome,
                    'tamanho_kg' => $prodEmbalagemKg,
                    'dias_previstos' => $diasDuracao,
                    'data_compra' => $dtCompraObj->format('Y-m-d H:i:s'),
                    'data_fim_prevista' => $dtFimPrevista->format('Y-m-d')
                ]
            ], 201);
            break;

        // ====================================================================
        // ENDPOINT: dar_baixa (POST) - Baixa / Encerramento de Oportunidade
        // ====================================================================
        case 'dar_baixa':
        case 'baixa':
            if ($metodoHttp !== 'POST') responderErro('Método não permitido. Use POST.', 405);

            $payload = !empty($bodyJson) ? $bodyJson : $_POST;

            $opId = (int)($payload['oportunidade_id'] ?? 0);
            $novoStatus = trim($payload['status'] ?? 'Venda Fechada');
            $motivo = trim($payload['motivo'] ?? 'Baixa via API ERP');

            $statusValidos = ['Venda Fechada', 'Cancelado', 'Pendente'];
            if (!in_array($novoStatus, $statusValidos, true)) {
                responderErro('Status inválido. Escolha entre: "Venda Fechada", "Cancelado" ou "Pendente".', 422);
            }

            if (!$opId) {
                // Tenta buscar por CPF + nome do Pet se não enviou o ID direto
                $cpfCli = trim($payload['cpf_cliente'] ?? '');
                $nomePet = trim($payload['nome_pet'] ?? '');

                if (!empty($cpfCli) && !empty($nomePet)) {
                    $stLocaliza = $pdo->prepare("SELECT o.id FROM oportunidades_venda o
                                                 JOIN clientes c ON o.cliente_id = c.id
                                                 JOIN pets p ON o.pet_id = p.id
                                                 WHERE o.instituicao_id = ? AND c.cpf = ? AND p.nome = ? AND o.status = 'Pendente'
                                                 ORDER BY o.id DESC LIMIT 1");
                    $stLocaliza->execute([$idLoja, $cpfCli, $nomePet]);
                    $opId = (int)$stLocaliza->fetchColumn();
                }
            }

            if (!$opId) {
                responderErro('Oportunidade não identificada. Forneça o campo "oportunidade_id" ou "cpf_cliente" + "nome_pet".', 404);
            }

            $stUp = $pdo->prepare("UPDATE oportunidades_venda SET status = ? WHERE id = ? AND instituicao_id = ?");
            $stUp->execute([$novoStatus, $opId, $idLoja]);

            if ($stUp->rowCount() > 0) {
                responderJson([
                    'sucesso' => true,
                    'mensagem' => "Oportunidade #$opId atualizada para '$novoStatus' com sucesso!",
                    'oportunidade_id' => $opId,
                    'novo_status' => $novoStatus,
                    'motivo' => $motivo
                ]);
            } else {
                // Verifica se já estava com esse status
                $stCheca = $pdo->prepare("SELECT id, status FROM oportunidades_venda WHERE id = ? AND instituicao_id = ?");
                $stCheca->execute([$opId, $idLoja]);
                $opExistente = $stCheca->fetch(PDO::FETCH_ASSOC);

                if (!$opExistente) {
                    responderErro("Oportunidade #$opId não encontrada para esta unidade.", 404);
                } else {
                    responderJson([
                        'sucesso' => true,
                        'mensagem' => "Oportunidade #$opId já se encontrava no status '{$opExistente['status']}'.",
                        'oportunidade_id' => $opId,
                        'status' => $opExistente['status']
                    ]);
                }
            }
            break;

        // ====================================================================
        // ENDPOINT: produtos (GET) - Catálogo de Rações com Ficha Técnica
        // ====================================================================
        case 'produtos':
            if ($metodoHttp !== 'GET') responderErro('Método não permitido. Use GET.', 405);

            $stProd = $pdo->prepare("SELECT id, nome_produto, marca, categoria, preco_venda, quantidade_estoque, tamanho_embalagem_kg, energia_metabolizavel_kg, codigo_barras 
                                     FROM produtos 
                                     WHERE instituicao_id = ? AND categoria = 'Ração'
                                     ORDER BY nome_produto ASC");
            $stProd->execute([$idLoja]);
            $prods = $stProd->fetchAll(PDO::FETCH_ASSOC);

            responderJson([
                'sucesso' => true,
                'total' => count($prods),
                'produtos' => array_map(function($p) {
                    return [
                        'id' => (int)$p['id'],
                        'nome_produto' => $p['nome_produto'],
                        'marca' => $p['marca'],
                        'codigo_barras' => $p['codigo_barras'],
                        'preco_venda' => (float)$p['preco_venda'],
                        'estoque_atual' => (int)$p['quantidade_estoque'],
                        'tamanho_embalagem_kg' => (float)$p['tamanho_embalagem_kg'],
                        'energia_metabolizavel_kg' => (float)$p['energia_metabolizavel_kg']
                    ];
                }, $prods)
            ]);
            break;

        // ====================================================================
        // ENDPOINT: atualizar_pet (POST) - Atualização do Peso do Pet
        // ====================================================================
        case 'atualizar_pet':
            if ($metodoHttp !== 'POST') responderErro('Método não permitido. Use POST.', 405);

            $payload = !empty($bodyJson) ? $bodyJson : $_POST;

            $petId = (int)($payload['pet_id'] ?? 0);
            $novoPeso = !empty($payload['peso_kg']) ? (float)$payload['peso_kg'] : null;
            $novoFator = !empty($payload['fator_manutencao']) ? (float)$payload['fator_manutencao'] : null;

            if (!$novoPeso || $novoPeso <= 0) {
                responderErro('Campo obrigatório inválido: "peso_kg" deve ser maior que zero.', 422);
            }

            if (!$petId) {
                $cpfCli = trim($payload['cpf_cliente'] ?? '');
                $nomePet = trim($payload['nome_pet'] ?? '');
                if (!empty($cpfCli) && !empty($nomePet)) {
                    $stP = $pdo->prepare("SELECT p.id FROM pets p JOIN clientes c ON p.cliente_id = c.id WHERE c.cpf = ? AND p.nome = ? AND p.instituicao_id = ? LIMIT 1");
                    $stP->execute([$cpfCli, $nomePet, $idLoja]);
                    $petId = (int)$stP->fetchColumn();
                }
            }

            if (!$petId) {
                responderErro('Pet não identificado. Envie "pet_id" ou "cpf_cliente" + "nome_pet".', 404);
            }

            $stUp = $pdo->prepare("UPDATE pets SET peso_kg = ?, fator_manutencao = COALESCE(?, fator_manutencao) WHERE id = ? AND instituicao_id = ?");
            $stUp->execute([$novoPeso, $novoFator, $petId, $idLoja]);

            responderJson([
                'sucesso' => true,
                'mensagem' => "Peso do pet atualizado para {$novoPeso}kg com sucesso!",
                'pet_id' => $petId,
                'novo_peso_kg' => $novoPeso,
                'novo_fator' => $novoFator
            ]);
            break;

        default:
            responderErro("Endpoint/Ação desconhecida: '$action'. Endpoints válidos: 'status', 'oportunidades', 'registrar_venda', 'dar_baixa', 'produtos', 'atualizar_pet'.", 404);
            break;
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responderErro('Erro interno no servidor: ' . $e->getMessage(), 500);
}
