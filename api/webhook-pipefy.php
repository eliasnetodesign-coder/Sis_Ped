<?php
/**
 * Webhook — Pipefy → Sis_Ped
 *
 * URL:    POST /Sis_Ped/api/webhook-pipefy.php
 * Header: X-Webhook-Token: <WEBHOOK_SECRET abaixo>
 *
 * Cada campo do Pipefy tem um "ID interno" (visível em Configurações do Pipe → Campos).
 * Ajuste FIELD_MAP para corresponder aos IDs do seu pipe.
 */

require_once __DIR__ . '/../config.php';

// ─── Configurações ────────────────────────────────────────────────────────────

// Token de segurança — coloque o mesmo valor em Pipefy > Webhook > Header customizado
// Header: X-Webhook-Token   Valor: o token abaixo
define('WEBHOOK_SECRET', 'SIS_PED_PIPEFY_TOKEN_2024');

// Mapeamento: ID do campo no Pipefy => coluna na tabela clientes
// Para descobrir o ID de cada campo: Pipefy > Configurações do Pipe > Campos > inspecionar URL ou usar API do Pipefy
const FIELD_MAP = [
    // Identificação
    'razao_social'   => 'razao_social',
    'cnpj'           => 'cnpj',
    'cpf'            => 'cpf',
    'codigo_cliente' => 'codigo_cliente',

    // Endereço
    'cep'            => 'cep',
    'endereco'       => 'endereco',
    'numero'         => 'numero',
    'complemento'    => 'complemento',
    'bairro'         => 'bairro',
    'cidade'         => 'cidade',
    'estado'         => 'estado',
    'pais'           => 'pais',

    // Contato
    'telefone1'      => 'telefone1',
    'telefone2'      => 'telefone2',
    'email'          => 'email',

    // Comercial
    'supervisor'     => 'supervisor',
];

// ─── Helpers ──────────────────────────────────────────────────────────────────

function jsonResponse(int $status, string $msg, array $extra = []): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(array_merge(['status' => $status, 'message' => $msg], $extra));
    exit;
}

function logWebhook(string $origem, string $evento, string $status, string $detalhe, ?int $clienteId = null): void {
    try {
        db()->prepare('INSERT INTO webhook_logs (origem, evento, status, detalhe, cliente_id) VALUES (?,?,?,?,?)')
            ->execute([$origem, $evento, $status, $detalhe, $clienteId]);
    } catch (Exception $e) {
        // log silencioso — não quebra o fluxo
    }
}

// ─── Só aceita POST ───────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, 'Método não permitido.');
}

// ─── Valida token ─────────────────────────────────────────────────────────────

$tokenRecebido = $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? '';
if ($tokenRecebido !== WEBHOOK_SECRET) {
    logWebhook('pipefy', 'auth', 'erro', 'Token inválido: ' . substr($tokenRecebido, 0, 20));
    jsonResponse(401, 'Token inválido.');
}

// ─── Lê e valida o payload ────────────────────────────────────────────────────

$raw     = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!$payload) {
    logWebhook('pipefy', 'parse', 'erro', 'JSON inválido: ' . substr($raw, 0, 200));
    jsonResponse(400, 'Payload JSON inválido.');
}

// Estrutura esperada: { "data": { "action": "card.create", "card": { "id": "...", "fields": [...] } } }
$action = $payload['data']['action'] ?? '';
$card   = $payload['data']['card']   ?? null;

if (!$card) {
    logWebhook('pipefy', $action, 'erro', 'Payload sem card.');
    jsonResponse(400, 'Payload sem dados de card.');
}

$pipefyCardId = $card['id'] ?? 'desconhecido';

// ─── Extrai campos do card ────────────────────────────────────────────────────

$campos = [];
foreach ($card['fields'] ?? [] as $f) {
    $id    = $f['field']['id']    ?? ($f['field']['label'] ?? '');
    $valor = $f['value']          ?? $f['array_value'][0] ?? null;
    if ($id !== '') $campos[$id] = $valor;
}

// ─── Monta dados para o banco ─────────────────────────────────────────────────

$dados = [];
foreach (FIELD_MAP as $pipefyId => $coluna) {
    if (isset($campos[$pipefyId]) && $campos[$pipefyId] !== null && $campos[$pipefyId] !== '') {
        $dados[$coluna] = trim((string)$campos[$pipefyId]);
    }
}

if (empty($dados)) {
    logWebhook('pipefy', $action, 'erro', "Card {$pipefyCardId}: nenhum campo mapeado encontrado.", null);
    jsonResponse(422, 'Nenhum campo mapeado encontrado no payload.', ['campos_recebidos' => array_keys($campos)]);
}

// Razão social é obrigatória
if (empty($dados['razao_social'])) {
    logWebhook('pipefy', $action, 'erro', "Card {$pipefyCardId}: razao_social ausente.");
    jsonResponse(422, 'Campo razao_social é obrigatório.');
}

// ─── Upsert na tabela clientes ────────────────────────────────────────────────
// Chave de deduplicação: CNPJ (se informado) ou código_cliente; senão insere novo.

$clienteId  = null;
$operacao   = 'insert';
$existente  = null;

if (!empty($dados['cnpj'])) {
    $s = db()->prepare('SELECT id FROM clientes WHERE cnpj = ? LIMIT 1');
    $s->execute([$dados['cnpj']]);
    $existente = $s->fetchColumn();
} elseif (!empty($dados['codigo_cliente'])) {
    $s = db()->prepare('SELECT id FROM clientes WHERE codigo_cliente = ? LIMIT 1');
    $s->execute([$dados['codigo_cliente']]);
    $existente = $s->fetchColumn();
}

if ($existente) {
    // UPDATE — atualiza apenas os campos recebidos
    $operacao  = 'update';
    $clienteId = (int)$existente;
    $setCols   = implode(', ', array_map(fn($c) => "$c = ?", array_keys($dados)));
    $vals      = array_values($dados);
    $vals[]    = $clienteId;
    db()->prepare("UPDATE clientes SET $setCols WHERE id = ?")->execute($vals);
} else {
    // INSERT — status padrão "ativo"
    if (empty($dados['status']))  $dados['status'] = 'ativo';
    if (empty($dados['idioma']))  $dados['idioma']  = 'pt';
    if (empty($dados['moeda']))   $dados['moeda']   = 'BRL';
    $cols = implode(', ', array_keys($dados));
    $ph   = implode(', ', array_fill(0, count($dados), '?'));
    $stmt = db()->prepare("INSERT INTO clientes ($cols) VALUES ($ph)");
    $stmt->execute(array_values($dados));
    $clienteId = (int)db()->lastInsertId();
}

logWebhook('pipefy', $action, 'ok', "Card {$pipefyCardId} → cliente #{$clienteId} ({$operacao})", $clienteId);

$palavraOp = $operacao === 'insert' ? 'criado' : 'atualizado';
jsonResponse(200, "Cliente {$palavraOp} com sucesso.", [
    'operacao'   => $operacao,
    'cliente_id' => $clienteId,
    'card_id'    => $pipefyCardId,
]);
