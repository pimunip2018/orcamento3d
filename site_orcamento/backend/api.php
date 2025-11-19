<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Tratar requisições OPTIONS (preflight CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Configuração do banco de dados Railway
$host = 'trolley.proxy.rlwy.net';
$port = '29900';
$dbname = 'railway';
$username = 'root';
$password = 'plfRtRyVMgYCBPMBDCBXgoNPofcwaEXj';

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $pdo->setAttribute(PDO::ATTR_TIMEOUT, 10);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao conectar ao banco de dados',
        'message' => $e->getMessage(),
        'details' => 'Verifique se o banco de dados Railway está ativo'
    ]);
    exit;
}

// Capturar método HTTP e ação
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Log para debug (remover em produção)
error_log("API Request - Method: $method, Action: $action");

// Roteamento
try {
    switch ($action) {
        case 'listar_orcamentos':
            listarOrcamentos($pdo);
            break;

        case 'obter_orcamento':
            obterOrcamento($pdo);
            break;

        case 'criar_orcamento':
            criarOrcamento($pdo);
            break;

        case 'atualizar_orcamento':
            atualizarOrcamento($pdo);
            break;

        case 'deletar_orcamento':
            deletarOrcamento($pdo);
            break;

        case 'test_connection':
            testConnection($pdo);
            break;

        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Ação inválida',
                'action_received' => $action
            ]);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro no servidor',
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}

// ========== FUNÇÕES DA API ==========

function testConnection($pdo) {
    try {
        $stmt = $pdo->query("SELECT VERSION() as version, DATABASE() as db");
        $info = $stmt->fetch();

        echo json_encode([
            'success' => true,
            'message' => 'Conexão com banco de dados OK!',
            'mysql_version' => $info['version'],
            'database' => $info['db'],
            'server_time' => date('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Erro ao testar conexão',
            'message' => $e->getMessage()
        ]);
    }
}

function listarOrcamentos($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT 
                o.id,
                o.nome_cliente,
                o.data_criacao,
                o.data_atualizacao,
                o.total,
                COUNT(p.id) as total_produtos
            FROM orcamentos o
            LEFT JOIN produtos_orcamento p ON o.id = p.orcamento_id
            GROUP BY o.id
            ORDER BY o.data_criacao DESC
        ");

        $orcamentos = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'data' => $orcamentos,
            'total' => count($orcamentos)
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Erro ao listar orçamentos',
            'message' => $e->getMessage()
        ]);
    }
}

function obterOrcamento($pdo) {
    try {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        if ($id <= 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'ID inválido'
            ]);
            return;
        }

        // Buscar orçamento
        $stmt = $pdo->prepare("SELECT * FROM orcamentos WHERE id = ?");
        $stmt->execute([$id]);
        $orcamento = $stmt->fetch();

        if (!$orcamento) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'error' => 'Orçamento não encontrado'
            ]);
            return;
        }

        // Buscar produtos
        $stmt = $pdo->prepare("SELECT * FROM produtos_orcamento WHERE orcamento_id = ? ORDER BY id ASC");
        $stmt->execute([$id]);
        $produtos = $stmt->fetchAll();

        $orcamento['produtos'] = $produtos;

        echo json_encode([
            'success' => true,
            'data' => $orcamento
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Erro ao obter orçamento',
            'message' => $e->getMessage()
        ]);
    }
}

function criarOrcamento($pdo) {
    try {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['nome_cliente']) || !isset($data['produtos']) || !isset($data['total'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Dados incompletos',
                'received' => array_keys($data)
            ]);
            return;
        }

        if (empty($data['produtos'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Nenhum produto informado'
            ]);
            return;
        }

        $pdo->beginTransaction();

        try {
            // Inserir orçamento
            $stmt = $pdo->prepare("
                INSERT INTO orcamentos (nome_cliente, total) 
                VALUES (?, ?)
            ");
            $stmt->execute([
                $data['nome_cliente'],
                $data['total']
            ]);

            $orcamentoId = $pdo->lastInsertId();

            // Inserir produtos
            $stmt = $pdo->prepare("
                INSERT INTO produtos_orcamento (
                    orcamento_id, nome_produto, quantidade, valor_filamento, 
                    material_gasto, tempo_impressao, taxa_energia, consumo_impressora,
                    custo_energia_por_peca, custo_filamento_por_peca, custos_extras,
                    extra_argola, extra_tag, preco_unitario, preco_total
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($data['produtos'] as $produto) {
                $stmt->execute([
                    $orcamentoId,
                    $produto['nomeProduto'],
                    $produto['quantidade'],
                    $produto['valorFilamento'],
                    $produto['materialGasto'],
                    $produto['tempoImpressao'],
                    $produto['taxaEnergia'],
                    $produto['consumoImpressora'],
                    $produto['custoEnergiaPorPeca'],
                    $produto['custoFilamentoPorPeca'],
                    $produto['custosExtras'],
                    $produto['extraArgola'] ? 1 : 0,
                    $produto['extraTag'] ? 1 : 0,
                    $produto['precoUnitario'],
                    $produto['precoTotal']
                ]);
            }

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Orçamento criado com sucesso',
                'orcamento_id' => $orcamentoId
            ]);

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Erro ao criar orçamento',
            'message' => $e->getMessage()
        ]);
    }
}

function atualizarOrcamento($pdo) {
    try {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['id']) || !isset($data['nome_cliente']) || !isset($data['produtos'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Dados incompletos para atualização'
            ]);
            return;
        }

        $pdo->beginTransaction();

        try {
            // Atualizar orçamento
            $stmt = $pdo->prepare("
                UPDATE orcamentos 
                SET nome_cliente = ?, total = ?, data_atualizacao = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $data['nome_cliente'],
                $data['total'],
                $data['id']
            ]);

            // Deletar produtos antigos
            $stmt = $pdo->prepare("DELETE FROM produtos_orcamento WHERE orcamento_id = ?");
            $stmt->execute([$data['id']]);

            // Inserir produtos atualizados
            $stmt = $pdo->prepare("
                INSERT INTO produtos_orcamento (
                    orcamento_id, nome_produto, quantidade, valor_filamento, 
                    material_gasto, tempo_impressao, taxa_energia, consumo_impressora,
                    custo_energia_por_peca, custo_filamento_por_peca, custos_extras,
                    extra_argola, extra_tag, preco_unitario, preco_total
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($data['produtos'] as $produto) {
                $stmt->execute([
                    $data['id'],
                    $produto['nomeProduto'],
                    $produto['quantidade'],
                    $produto['valorFilamento'],
                    $produto['materialGasto'],
                    $produto['tempoImpressao'],
                    $produto['taxaEnergia'],
                    $produto['consumoImpressora'],
                    $produto['custoEnergiaPorPeca'],
                    $produto['custoFilamentoPorPeca'],
                    $produto['custosExtras'],
                    $produto['extraArgola'] ? 1 : 0,
                    $produto['extraTag'] ? 1 : 0,
                    $produto['precoUnitario'],
                    $produto['precoTotal']
                ]);
            }

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Orçamento atualizado com sucesso'
            ]);

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Erro ao atualizar orçamento',
            'message' => $e->getMessage()
        ]);
    }
}

function deletarOrcamento($pdo) {
    try {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        if ($id <= 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'ID inválido'
            ]);
            return;
        }

        // Verificar se orçamento existe
        $stmt = $pdo->prepare("SELECT id FROM orcamentos WHERE id = ?");
        $stmt->execute([$id]);

        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'error' => 'Orçamento não encontrado'
            ]);
            return;
        }

        // Deletar orçamento (produtos são deletados automaticamente por CASCADE)
        $stmt = $pdo->prepare("DELETE FROM orcamentos WHERE id = ?");
        $stmt->execute([$id]);

        echo json_encode([
            'success' => true,
            'message' => 'Orçamento deletado com sucesso'
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Erro ao deletar orçamento',
            'message' => $e->getMessage()
        ]);
    }
}
?>
