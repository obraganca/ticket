<?php
/**
 * finance-sim: microsserviço mínimo que simula o sistema financeiro do
 * cliente (item 5.2 do desafio): 2-5s de latência, ~20% de falha, e uma
 * variante de falha DEPOIS de já ter persistido (para exercitar o cenário
 * "financeiro registrou mas a API achou que tinha falhado" -- é exatamente
 * esse cenário que a Idempotency-Key resolve do lado da API).
 * Armazena em SQLite local. Roda com `php -S 0.0.0.0:8080 index.php`.
 */

header('Content-Type: application/json');

$dbPath = getenv('FINANCE_DB_PATH') ?: __DIR__ . '/finance.sqlite';
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA busy_timeout = 5000');
$db->exec('PRAGMA journal_mode = WAL');
$db->exec('CREATE TABLE IF NOT EXISTS sales (
    idempotency_key TEXT PRIMARY KEY,
    order_id TEXT NOT NULL,
    amount_cents INTEGER NOT NULL,
    kind TEXT NOT NULL,
    created_at TEXT NOT NULL
)');

$latencyMin = (float) (getenv('LATENCY_MIN') ?: 2);
$latencyMax = (float) (getenv('LATENCY_MAX') ?: 5);
$failureRate = (float) (getenv('FAILURE_RATE') ?: 0.2);
$failAfterCommitRate = (float) (getenv('FAIL_AFTER_COMMIT_RATE') ?: 0.3); // fração das falhas que acontece DEPOIS de já ter gravado

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

// Latência simulada em TODA chamada, antes de decidir o resto.
usleep((int) (mt_rand((int) ($latencyMin * 1000), (int) ($latencyMax * 1000)) * 1000));

if ($method === 'POST' && $path === '/sales') {
    $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
    if (! $key) {
        respond(400, ['error' => ['code' => 'idempotency_key_required', 'message' => 'Idempotency-Key é obrigatório.']]);
    }

    $payload = json_decode(file_get_contents('php://input'), true) ?: [];

    // Dedup: se a venda já existe (chamada anterior "falhou" do lado da API
    // mas já tinha persistido aqui), responde sucesso sem duplicar.
    $stmt = $db->prepare('SELECT 1 FROM sales WHERE idempotency_key = ?');
    $stmt->execute([$key]);
    if ($stmt->fetch()) {
        respond(200, ['ok' => true, 'deduplicated' => true]);
    }

    $willFail = (mt_rand() / mt_getrandmax()) < $failureRate;
    $failAfterCommit = $willFail && (mt_rand() / mt_getrandmax()) < $failAfterCommitRate;

    if ($willFail && ! $failAfterCommit) {
        respond(503, ['error' => ['code' => 'service_unavailable', 'message' => 'Financeiro instável (simulado).']]);
    }

    // INSERT OR IGNORE: duas requisições simultâneas com a mesma chave (o dedupe acima é check-then-act)
    // não podem gerar erro nem linha dupla; a PK é a garantia real.
    $stmt = $db->prepare('INSERT OR IGNORE INTO sales (idempotency_key, order_id, amount_cents, kind, created_at) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$key, $payload['order_id'] ?? '', $payload['amount_cents'] ?? 0, $payload['kind'] ?? 'sale', date('c')]);

    if ($failAfterCommit) {
        // Já persistiu, mas a API vai achar que falhou e vai tentar de novo -
        // é exatamente esse caso que o dedup acima resolve no retry seguinte.
        respond(503, ['error' => ['code' => 'service_unavailable', 'message' => 'Financeiro instável (simulado, pós-commit).']]);
    }

    respond(201, ['ok' => true]);
}

if ($method === 'GET' && $path === '/sales') {
    $rows = $db->query('SELECT idempotency_key, order_id, amount_cents, kind, created_at FROM sales ORDER BY created_at')->fetchAll(PDO::FETCH_ASSOC);
    respond(200, ['data' => $rows]);
}

if ($method === 'GET' && $path === '/health') {
    respond(200, ['status' => 'ok']);
}

respond(404, ['error' => ['code' => 'not_found', 'message' => 'Rota não encontrada.']]);
