<?php
if (session_status() === PHP_SESSION_NONE) session_start();

define('DB_HOST', 'localhost');
define('DB_NAME', 'sis_ped');
define('DB_USER', 'root');
define('DB_PASS', '');
define('BASE_URL', '/Sis_Ped');
define('EMPRESA_UF', 'SP'); // UF da empresa — define ICMS local x interestadual no detalhamento fiscal
define('ASSETS_URL', BASE_URL . '/assets');
define('LAYOUT_PATH', __DIR__ . '/layout');

// Segurança de acesso — usuários do tipo "Externo" só dispensam verificação
// quando logam a partir deste IP. Fora dele, exige código por WhatsApp (2FA).
define('IP_LIBERADO', '201.6.128.102');
define('WHATSAPP_REMETENTE', '11 99982-5523'); // número que envia a verificação
define('WHATSAPP_CODIGO_VALIDADE', 600);        // validade do código, em segundos (10 min)

function db() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
            try { $pdo->exec("ALTER TABLE pedidos ADD COLUMN lote_id VARCHAR(40) NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE clientes MODIFY COLUMN email VARCHAR(255) NULL DEFAULT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("UPDATE clientes SET email = NULL WHERE email = ''"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE tabela_precos ADD COLUMN preco_network DECIMAL(10,2) NULL DEFAULT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE tabela_precos ADD COLUMN preco_auxiliar DECIMAL(10,2) NULL DEFAULT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE pedidos ADD COLUMN desconto_campanha DECIMAL(5,2) NULL DEFAULT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE bonus_ma_logs ADD COLUMN valor_utilizado DECIMAL(10,2) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE pedidos ADD COLUMN forma_pagamento VARCHAR(60) NULL DEFAULT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE creditos ADD COLUMN valor_utilizado DECIMAL(12,2) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE pedidos ADD COLUMN credito_utilizado DECIMAL(12,2) NULL DEFAULT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE pedidos ADD COLUMN desconto_pagamento DECIMAL(12,2) NULL DEFAULT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE clientes ADD COLUMN email VARCHAR(120) NULL DEFAULT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE clientes ADD COLUMN senha VARCHAR(255) NULL DEFAULT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE pedidos MODIFY COLUMN status ENUM('comercial','financeiro','faturamento','faturado','cancelado','reprovado') DEFAULT 'comercial'"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE usuarios MODIFY COLUMN tipo_acesso ENUM('comercial','financeiro','supervisor','tecnologia da informacao','recursos humanos','marketing','diretoria','centro tecnico','contabilidade','recepcao','expedicao') NOT NULL DEFAULT 'comercial'"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE clientes ADD COLUMN desconto_canal DECIMAL(10,4) DEFAULT 0"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE campanhas ADD COLUMN canal_venda_id INT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE clientes ADD COLUMN supervisor VARCHAR(100) NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("UPDATE clientes SET supervisor = vendedor WHERE supervisor IS NULL AND vendedor IS NOT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE pedidos ADD COLUMN supervisor VARCHAR(100) NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("UPDATE pedidos SET supervisor = vendedor WHERE supervisor IS NULL AND vendedor IS NOT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS grupo_empresas (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                nome       VARCHAR(120) NOT NULL,
                descricao  TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )"); } catch (PDOException $e) {}
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS grupo_empresas_clientes (
                id        INT AUTO_INCREMENT PRIMARY KEY,
                grupo_id  INT NOT NULL,
                cliente_id INT NOT NULL,
                UNIQUE KEY uq_grupo_cliente (grupo_id, cliente_id)
            )"); } catch (PDOException $e) {}
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS webhook_logs (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                origem     VARCHAR(50)  NOT NULL,
                evento     VARCHAR(100) NOT NULL,
                status     VARCHAR(20)  NOT NULL,
                detalhe    TEXT,
                cliente_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )"); } catch (PDOException $e) {}
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS pedido_logs (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                pedido_id     INT          NOT NULL,
                numero_pedido VARCHAR(50)  NOT NULL,
                usuario_nome  VARCHAR(100),
                usuario_tipo  VARCHAR(30),
                acao          VARCHAR(120) NOT NULL,
                status_antes  VARCHAR(30),
                status_depois VARCHAR(30),
                detalhes      TEXT,
                created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_pedido (pedido_id),
                INDEX idx_numero (numero_pedido)
            )"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE campanhas ADD COLUMN tipo VARCHAR(20) NOT NULL DEFAULT 'desconto'"); } catch (PDOException $e) {}
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS campanha_bonificacao (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                codigo_campanha VARCHAR(50) NOT NULL,
                produto_id      INT NOT NULL,
                quantidade      INT NOT NULL DEFAULT 1,
                KEY idx_camp_bonif (codigo_campanha)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (PDOException $e) {}
            // Normaliza tipo_usuario para os valores Interno/Externo do controle de acesso
            try { $pdo->exec("UPDATE usuarios SET tipo_usuario = 'Interno' WHERE tipo_usuario IS NULL OR tipo_usuario NOT IN ('Interno','Externo')"); } catch (PDOException $e) {}
            // Renomeia a coluna de contato da tabela de usuários: telefone -> celular
            try { $pdo->exec("ALTER TABLE usuarios CHANGE COLUMN telefone celular VARCHAR(20)"); } catch (PDOException $e) {}
            // Migra a tabela de log de verificação: sms_logs -> whatsapp_logs (antes do CREATE abaixo)
            try { $pdo->exec("RENAME TABLE sms_logs TO whatsapp_logs"); } catch (PDOException $e) {}
            // Log das mensagens de verificação de acesso (2FA) enviadas via WhatsApp
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_logs (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                usuario_id  INT NULL,
                destino     VARCHAR(30) NOT NULL,
                remetente   VARCHAR(30) NOT NULL,
                mensagem    TEXT NOT NULL,
                ip_origem   VARCHAR(45) NULL,
                status      VARCHAR(20) NOT NULL DEFAULT 'enviado',
                created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_wa_usuario (usuario_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE pedidos ADD COLUMN moeda VARCHAR(10) NOT NULL DEFAULT 'BRL'"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE pedidos ADD COLUMN cotacao DECIMAL(10,4) NULL DEFAULT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE tabela_precos ADD COLUMN preco_dolar DECIMAL(10,2) NULL DEFAULT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE tabela_precos ADD COLUMN preco_euro DECIMAL(10,2) NULL DEFAULT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS configuracoes (
                chave      VARCHAR(60) PRIMARY KEY,
                valor      VARCHAR(255) NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (PDOException $e) {}
            // Campanhas avançadas: condições combinadas (E), valor-alvo (OU) e bonificação selecionável
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS campanha_condicoes (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                codigo_campanha VARCHAR(50)  NOT NULL,
                criterio_tipo   VARCHAR(20)  NOT NULL,
                criterio_valor  VARCHAR(100) NOT NULL,
                quantidade      INT NOT NULL DEFAULT 0,
                KEY idx_camp_cond (codigo_campanha)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE campanhas ADD COLUMN valor_alvo DECIMAL(12,2) NULL DEFAULT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE campanhas ADD COLUMN bonif_modo VARCHAR(20) NOT NULL DEFAULT 'fixo'"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE campanhas ADD COLUMN bonif_limite_tipo VARCHAR(20) NULL DEFAULT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE campanhas ADD COLUMN bonif_limite_valor DECIMAL(12,2) NULL DEFAULT NULL"); } catch (PDOException $e) {}
        } catch (PDOException $e) {
            die('Erro de conexão com o banco de dados. <a href="' . BASE_URL . '/install.php">Clique aqui para configurar.</a>');
        }
    }
    return $pdo;
}

function e($s) {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function usuario() {
    return $_SESSION['usuario'] ?? null;
}

function requireLogin() {
    if (!usuario()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function requireAdmin() {
    $u = usuario();
    if (!$u || !in_array($u['tipo'], ['comercial', 'financeiro', 'supervisor', 'tecnologia da informacao'])) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function requireComercial() {
    $u = usuario();
    if (!$u || !in_array($u['tipo'], ['comercial', 'supervisor', 'tecnologia da informacao'])) {
        flash('danger', 'Acesso restrito ao módulo Comercial.');
        header('Location: ' . BASE_URL . '/admin/dashboard.php');
        exit;
    }
}

function requireCliente() {
    $u = usuario();
    if (!$u || $u['tipo'] !== 'cliente') {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function getConfig($chave, $default = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT chave, valor FROM configuracoes')->fetchAll() as $r) {
                $cache[$r['chave']] = $r['valor'];
            }
        } catch (PDOException $e) { /* tabela ainda não existe */ }
    }
    return array_key_exists($chave, $cache) && $cache[$chave] !== null && $cache[$chave] !== ''
        ? $cache[$chave] : $default;
}

function setConfig($chave, $valor) {
    db()->prepare('INSERT INTO configuracoes (chave, valor) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE valor = VALUES(valor)')
        ->execute([$chave, $valor]);
}

/**
 * Busca a cotação USD/EUR (em BRL) na AwesomeAPI. Retorna
 * ['usd'=>float, 'eur'=>float, 'data'=>string] ou null em caso de falha.
 */
function buscarCotacaoAPI() {
    $url = 'https://economia.awesomeapi.com.br/json/last/USD-BRL,EUR-BRL';
    $raw = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
    }
    if ($raw === false) $raw = @file_get_contents($url);
    $d = $raw ? json_decode($raw, true) : null;
    if (!$d || empty($d['USDBRL']['bid']) || empty($d['EURBRL']['bid'])) return null;
    return [
        'usd'  => (float)$d['USDBRL']['bid'],
        'eur'  => (float)$d['EURBRL']['bid'],
        'data' => $d['USDBRL']['create_date'] ?? null,
    ];
}

/**
 * Cotação "do dia" para a moeda informada (em BRL). Registra/usa um cache
 * diário em `configuracoes` (cotacao_usd, cotacao_eur, cotacao_data) para não
 * consultar a API repetidamente. Retorna null para BRL/moeda desconhecida.
 */
function cotacaoDia($moeda) {
    static $loaded = false, $usd = 0.0, $eur = 0.0;
    $moeda = strtoupper((string)$moeda);
    if ($moeda !== 'USD' && $moeda !== 'EUR') return null;

    if (!$loaded) {
        $loaded = true;
        $hoje = date('Y-m-d');
        if (getConfig('cotacao_data') === $hoje) {
            $usd = (float)getConfig('cotacao_usd', 0);
            $eur = (float)getConfig('cotacao_eur', 0);
        }
        if ($usd <= 0 || $eur <= 0) {
            $api = buscarCotacaoAPI();
            if ($api) {
                $usd = $api['usd'];
                $eur = $api['eur'];
                setConfig('cotacao_usd', $usd);
                setConfig('cotacao_eur', $eur);
                setConfig('cotacao_data', $hoje);
                setConfig('cotacao_atualizado', $api['data'] ?: date('Y-m-d H:i:s'));
            } else {
                // Falha na API: usa o último valor conhecido (mesmo de outro dia)
                if ($usd <= 0) $usd = (float)getConfig('cotacao_usd', 0);
                if ($eur <= 0) $eur = (float)getConfig('cotacao_eur', 0);
            }
        }
    }
    $r = $moeda === 'USD' ? $usd : $eur;
    return $r > 0 ? $r : null;
}

/**
 * Coluna de preço da tabela_precos a usar conforme a moeda do cliente.
 * Bonificação sempre usa preco_network (independente de moeda).
 */
function colPrecoMoeda($moeda, $bonificacao = false) {
    if ($bonificacao) return 't.preco_network';
    switch (strtoupper((string)$moeda)) {
        case 'USD': return 't.preco_dolar';
        case 'EUR': return 't.preco_euro';
        default:    return 't.preco_padrao';
    }
}

function flash($type, $msg) {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function getFlash() {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/**
 * Retorna o IP real do cliente, considerando proxies/balanceadores.
 */
function ipCliente() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]); // primeiro IP da cadeia
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

/**
 * Mascara um telefone para exibição, deixando visíveis apenas os 2 últimos dígitos.
 * Ex.: (11) 99982-5523 -> (••) •••••-••23
 */
function mascararTelefone($tel) {
    $d = preg_replace('/\D+/', '', (string)$tel);
    if (strlen($d) < 2) return '•••••';
    $fim = substr($d, -2);
    return str_repeat('•', max(0, strlen($d) - 2)) . $fim;
}

/**
 * Envia um código de verificação por WhatsApp para o número informado.
 *
 * Ponto único de integração com a API de WhatsApp. Hoje registra o envio em
 * `whatsapp_logs` (e em logs/whatsapp.log) para funcionar em ambiente sem
 * provedor. Para ativar de verdade (WhatsApp Cloud API da Meta, Twilio,
 * Z-API, etc.), implemente a chamada HTTP no bloco indicado e retorne
 * true/false conforme o resultado.
 *
 * @return bool true se o envio foi aceito.
 */
function enviarWhatsappCodigo($telefone, string $codigo, ?int $usuarioId = null): bool {
    $destino = preg_replace('/\D+/', '', (string)$telefone);
    if (strlen($destino) < 10) return false; // número inválido
    // Normaliza para padrão internacional do Brasil (55 + DDD + número)
    $destinoIntl = (strpos($destino, '55') === 0 && strlen($destino) >= 12) ? $destino : '55' . $destino;

    $mensagem = "Sis_Ped: seu codigo de verificacao de acesso e {$codigo}. "
              . "Valido por " . (WHATSAPP_CODIGO_VALIDADE / 60) . " minutos. Nao compartilhe.";

    $ok = false;
    try {
        // ───────────────────────────────────────────────────────────────
        // INTEGRAÇÃO COM A API DE WHATSAPP (substituir pelo provedor real)
        // Remetente configurado: WHATSAPP_REMETENTE (= '11 99982-5523').
        // Ex. (WhatsApp Cloud API):
        //   $ch = curl_init("https://graph.facebook.com/v20.0/{PHONE_NUMBER_ID}/messages");
        //   curl_setopt_array($ch, [
        //     CURLOPT_RETURNTRANSFER => true,
        //     CURLOPT_POST => true,
        //     CURLOPT_HTTPHEADER => ["Authorization: Bearer {TOKEN}", "Content-Type: application/json"],
        //     CURLOPT_POSTFIELDS => json_encode([
        //       "messaging_product" => "whatsapp",
        //       "to" => $destinoIntl,
        //       "type" => "text",
        //       "text" => ["body" => $mensagem],
        //     ]),
        //   ]);
        //   $resp = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        //   $ok = ($http >= 200 && $http < 300);
        // Enquanto não há provedor, consideramos o envio aceito e registramos
        // o código no log para permitir o teste do fluxo em desenvolvimento.
        // ───────────────────────────────────────────────────────────────
        $ok = true;

        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
        @file_put_contents(
            $logDir . '/whatsapp.log',
            date('Y-m-d H:i:s') . " | de " . WHATSAPP_REMETENTE . " | para {$destinoIntl} | {$mensagem}" . PHP_EOL,
            FILE_APPEND
        );
    } catch (\Throwable $e) {
        $ok = false;
    }

    try {
        db()->prepare('INSERT INTO whatsapp_logs (usuario_id,destino,remetente,mensagem,ip_origem,status) VALUES (?,?,?,?,?,?)')
            ->execute([$usuarioId, $destinoIntl, WHATSAPP_REMETENTE, $mensagem, ipCliente(), $ok ? 'enviado' : 'falha']);
    } catch (PDOException $e) {}

    return $ok;
}

function simboloMoeda($moeda) {
    switch (strtoupper((string)$moeda)) {
        case 'USD': return 'US$';
        case 'EUR': return '€';
        default:    return 'R$';
    }
}

/**
 * Moeda "corrente" da requisição. Páginas que exibem um único pedido podem
 * chamar moedaCorrente('USD') uma vez para que todos os moedaBR() seguintes
 * usem o símbolo correto sem precisar passar a moeda em cada chamada.
 */
function moedaCorrente($set = null) {
    static $cur = 'BRL';
    if ($set !== null) $cur = strtoupper((string)$set) ?: 'BRL';
    return $cur;
}

function moedaBR($v, $moeda = null) {
    return simboloMoeda($moeda ?? moedaCorrente()) . ' ' . number_format((float)$v, 2, ',', '.');
}

function dataBR($d) {
    if (!$d) return '-';
    return date('d/m/Y', strtotime($d));
}

function statusBadge($s) {
    $map = [
        'comercial'   => ['primary',   'Aguardando Comercial'],
        'financeiro'  => ['warning',   'Aguardando Financeiro'],
        'faturamento' => ['info',      'Aguardando Faturamento'],
        'faturado'    => ['success',   'Faturado'],
        'cancelado'   => ['danger',    'Cancelado'],
        'reprovado'   => ['danger',    'Cancelado'],
        'ativo'       => ['success',   'Ativo'],
        'inativo'     => ['secondary', 'Inativo'],
        'aberto'      => ['warning',   'Aberto'],
        'pago'        => ['success',   'Pago'],
        'vencido'     => ['danger',    'Vencido'],
        'aprovado'    => ['success',   'Aprovado'],
        'pendente'    => ['secondary', 'Pendente'],
    ];
    [$cls, $label] = $map[$s] ?? ['secondary', ucfirst($s)];
    return '<span class="badge bg-' . $cls . '">' . $label . '</span>';
}

/**
 * Carrega todas as campanhas agrupadas por código, já com suas condições (E).
 * @return array  codigo => ['rows'=>[...campanhas], 'conds'=>[...campanha_condicoes]]
 */
function campanhasAgrupadas(): array {
    $rows = db()->query("SELECT codigo_campanha, produto_id, linha, grupo, subgrupo, canal_venda_id,
                                quantidade, desconto, tipo, valor_alvo, bonif_modo, bonif_limite_tipo, bonif_limite_valor
                         FROM campanhas")->fetchAll();
    $conds = [];
    try {
        $conds = db()->query("SELECT codigo_campanha, criterio_tipo, criterio_valor, quantidade
                              FROM campanha_condicoes")->fetchAll();
    } catch (PDOException $e) { $conds = []; }

    $g = [];
    foreach ($rows as $r) $g[$r['codigo_campanha']]['rows'][] = $r;
    foreach ($g as $k => &$v) $v['conds'] = [];
    unset($v);
    foreach ($conds as $c) if (isset($g[$c['codigo_campanha']])) $g[$c['codigo_campanha']]['conds'][] = $c;
    return $g;
}

/**
 * Constrói o contexto de avaliação de campanhas a partir dos itens de uma venda.
 * Cada item: ['produto_id','qtd','linha','grupo','subgrupo','preco'(unit, opcional)].
 */
function ctxCampanha(array $itensVenda, int $canalVendaId): array {
    $totL = $totG = $totS = $totProd = []; $valorTotal = 0.0;
    foreach ($itensVenda as $it) {
        $q = (int)($it['qtd'] ?? 0); if ($q <= 0) continue;
        $pid = (int)($it['produto_id'] ?? 0);
        if ($pid) $totProd[$pid] = ($totProd[$pid] ?? 0) + $q;
        $l = trim($it['linha']    ?? ''); if ($l) $totL[$l] = ($totL[$l] ?? 0) + $q;
        $g = trim($it['grupo']    ?? ''); if ($g) $totG[$g] = ($totG[$g] ?? 0) + $q;
        $s = trim($it['subgrupo'] ?? ''); if ($s) $totS[$s] = ($totS[$s] ?? 0) + $q;
        $valorTotal += $q * (float)($it['preco'] ?? 0);
    }
    return [
        'totaisLinha'    => $totL,
        'totaisGrupo'    => $totG,
        'totaisSubgrupo' => $totS,
        'qtdPorProduto'  => $totProd,
        'valorTotal'     => $valorTotal,
        'canalVendaId'   => $canalVendaId,
    ];
}

/**
 * Avalia o gatilho de UMA campanha contra o contexto da venda.
 * Novo modelo (tem condições): TODAS as condições de quantidade precisam ser
 * atingidas (E); OU o valor-alvo sozinho aciona a campanha. Modelo legado
 * (sem condições): produtos somados / categoria, como historicamente.
 *
 * @return array ['acionada'=>bool, 'mult'=>int, 'gruposAlvo'=>[['tipo','valor'],...]]
 */
function avaliarCampanhaTrigger(array $rows, array $conds, array $ctx): array {
    $vazio = ['acionada' => false, 'mult' => 0, 'gruposAlvo' => []];
    if (!$rows) return $vazio;
    $canal = (int)($rows[0]['canal_venda_id'] ?? 0);
    if ($canal && $canal !== (int)($ctx['canalVendaId'] ?? 0)) return $vazio;

    // ---- Novo modelo: condições combinadas (E) + valor-alvo (OU) ----
    if ($conds) {
        $gruposAlvo = [];
        $allMet = true;
        $minMult = PHP_INT_MAX;
        foreach ($conds as $c) {
            $tipo = $c['criterio_tipo']; $val = trim($c['criterio_valor']);
            $gruposAlvo[] = ['tipo' => $tipo, 'valor' => $val];
            $tot = 0;
            if ($tipo === 'linha')    $tot = $ctx['totaisLinha'][$val]    ?? 0;
            if ($tipo === 'grupo')    $tot = $ctx['totaisGrupo'][$val]    ?? 0;
            if ($tipo === 'subgrupo') $tot = $ctx['totaisSubgrupo'][$val] ?? 0;
            $q = (int)$c['quantidade'];
            if ($q > 0 && $tot >= $q) $minMult = min($minMult, intdiv($tot, $q));
            else $allMet = false;
        }
        if ($allMet && $minMult >= 1) {
            return ['acionada' => true, 'mult' => $minMult, 'gruposAlvo' => $gruposAlvo];
        }
        $valorAlvo = (float)($rows[0]['valor_alvo'] ?? 0);
        if ($valorAlvo > 0 && (float)($ctx['valorTotal'] ?? 0) >= $valorAlvo) {
            return ['acionada' => true, 'mult' => max(1, (int)floor($ctx['valorTotal'] / $valorAlvo)), 'gruposAlvo' => $gruposAlvo];
        }
        return ['acionada' => false, 'mult' => 0, 'gruposAlvo' => $gruposAlvo];
    }

    // ---- Modelo legado ----
    $min = (int)($rows[0]['quantidade'] ?? 0);
    if ($min <= 0) return $vazio;
    $prodIds = array_values(array_unique(array_filter(array_map(fn($r) => (int)$r['produto_id'], $rows))));
    if ($prodIds) {
        $qtdRef = 0;
        foreach ($prodIds as $pid) $qtdRef += $ctx['qtdPorProduto'][$pid] ?? 0;
        if ($qtdRef < $min) return $vazio;
        $ga = array_map(fn($pid) => ['tipo' => 'produto', 'valor' => $pid], $prodIds);
        return ['acionada' => true, 'mult' => intdiv($qtdRef, $min), 'gruposAlvo' => $ga];
    }
    $mult = 0; $ga = [];
    foreach ($rows as $r) {
        $cL = trim(preg_replace('/\d+/', '', $r['linha']    ?? ''));
        $cG = trim(preg_replace('/\d+/', '', $r['grupo']    ?? ''));
        $cS = trim(preg_replace('/\d+/', '', $r['subgrupo'] ?? ''));
        if ($cL)      { $t = $ctx['totaisLinha'][$cL]    ?? 0; $crit = ['tipo' => 'linha',    'valor' => $cL]; }
        elseif ($cG)  { $t = $ctx['totaisGrupo'][$cG]    ?? 0; $crit = ['tipo' => 'grupo',    'valor' => $cG]; }
        elseif ($cS)  { $t = $ctx['totaisSubgrupo'][$cS] ?? 0; $crit = ['tipo' => 'subgrupo', 'valor' => $cS]; }
        else continue;
        if ($t >= $min) { $mult = max($mult, intdiv($t, $min)); $ga[] = $crit; }
    }
    if ($mult >= 1) return ['acionada' => true, 'mult' => $mult, 'gruposAlvo' => $ga];
    return $vazio;
}

/** Indica se um produto (linha/grupo/subgrupo/id) bate em algum critério-alvo. */
function itemBateGruposAlvo(array $prod, array $gruposAlvo): bool {
    foreach ($gruposAlvo as $ga) {
        switch ($ga['tipo']) {
            case 'linha':    if (trim($prod['linha']    ?? '') === $ga['valor']) return true; break;
            case 'grupo':    if (trim($prod['grupo']    ?? '') === $ga['valor']) return true; break;
            case 'subgrupo': if (trim($prod['subgrupo'] ?? '') === $ga['valor']) return true; break;
            case 'produto':  if ((int)($prod['id'] ?? 0) === (int)$ga['valor'])  return true; break;
        }
    }
    return false;
}

/**
 * Campanhas de DESCONTO do NOVO modelo (com condições) que foram acionadas.
 * O modelo legado continua sendo avaliado item a item nas telas de pedido.
 * @return array  [['desconto'=>float,'gruposAlvo'=>[['tipo','valor'],...]], ...]
 */
function avaliarCampanhasDescontoAvancadas(array $ctx): array {
    $out = [];
    foreach (campanhasAgrupadas() as $g) {
        if (empty($g['conds'])) continue;                                  // só novo modelo
        if (($g['rows'][0]['tipo'] ?? 'desconto') !== 'desconto') continue;
        $r = avaliarCampanhaTrigger($g['rows'], $g['conds'], $ctx);
        if (!$r['acionada']) continue;
        $out[] = ['desconto' => (float)$g['rows'][0]['desconto'], 'gruposAlvo' => $r['gruposAlvo']];
    }
    return $out;
}

/**
 * Cria um pedido bonificado (lote separado, preço Network, sem cotação).
 * @param array $bonusAcc  produto_id => quantidade
 * @return array  itens criados: ['produto_id','descricao','quantidade','pedido_id']
 */
function criarPedidoBonificado(int $clienteId, string $supervisor, string $dataPedido, array $bonusAcc, string $obs): array {
    if (!$bonusAcc) return [];
    $lote = uniqid('LB', true);
    $num  = 'PED-' . date('Y') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
    $moedaCli = db()->prepare('SELECT moeda FROM clientes WHERE id = ?');
    $moedaCli->execute([$clienteId]);
    $moedaCli = $moedaCli->fetchColumn() ?: 'BRL';
    $cotacaoCli = null; // bonificação usa preço network (BRL): não converte.
    $ins  = db()->prepare('INSERT INTO pedidos (numero_pedido,tipo_venda,data_pedido,cliente_id,produto_id,supervisor,codigo_barra,descricao_produto,quantidade_total,valor_total,status,observacoes,lote_id,moeda,cotacao) VALUES (?,?,?,?,?,?,?,?,?,?,"comercial",?,?,?,?)');
    $criados = [];
    foreach ($bonusAcc as $pid => $q) {
        $q = (int)$q; if ($q <= 0) continue;
        $pr = db()->prepare('SELECT p.descricao_pt, p.codigo_barra, COALESCE(t.preco_network, p.vendas_varejo, 0) AS preco
                             FROM produtos p LEFT JOIN tabela_precos t ON t.produto_id = p.id
                             WHERE p.id = ? AND p.status = "ativo"');
        $pr->execute([(int)$pid]); $pr = $pr->fetch();
        if (!$pr) continue;
        $valor = $q * (float)$pr['preco'];
        $ins->execute([$num, 'bonificacao', $dataPedido, $clienteId, (int)$pid, $supervisor, $pr['codigo_barra'], $pr['descricao_pt'], $q, $valor, $obs, $lote, $moedaCli, $cotacaoCli]);
        $criados[] = ['produto_id' => (int)$pid, 'descricao' => $pr['descricao_pt'], 'quantidade' => $q, 'pedido_id' => (int)db()->lastInsertId()];
    }
    if (!$criados) return [];

    try {
        db()->prepare('INSERT INTO pedido_logs (pedido_id,numero_pedido,usuario_nome,usuario_tipo,acao,status_antes,status_depois,detalhes) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$criados[0]['pedido_id'], $num, 'Sistema', 'sistema', 'Bonificação gerada por campanha', null, 'comercial', $obs]);
    } catch (PDOException $e) {}

    return $criados;
}

/**
 * Gera o pedido bonificado das campanhas de bonificação FIXA acionadas pela venda.
 * Bonificação selecionável é tratada à parte (ver detectarBonificacaoSelecionavel()).
 *
 * @param array $itensVenda  itens da venda: ['produto_id','qtd','linha','grupo','subgrupo','preco']
 * @return array  itens bonificados criados
 */
function gerarBonificacaoCampanha(int $clienteId, int $canalVendaId, string $supervisor, string $dataPedido, array $itensVenda, ?string $refNumero = null): array {
    $ctx = ctxCampanha($itensVenda, $canalVendaId);
    $bonusAcc = [];
    $codigosAcionados = [];
    foreach (campanhasAgrupadas() as $code => $g) {
        if (($g['rows'][0]['tipo'] ?? 'desconto') !== 'bonificacao') continue;
        if (($g['rows'][0]['bonif_modo'] ?? 'fixo') !== 'fixo') continue;   // selecionável é à parte
        $r = avaliarCampanhaTrigger($g['rows'], $g['conds'], $ctx);
        if (!$r['acionada']) continue;
        $mult = max(1, (int)$r['mult']);

        $bp = db()->prepare("SELECT produto_id, quantidade FROM campanha_bonificacao WHERE codigo_campanha = ?");
        $bp->execute([$code]);
        $temBonus = false;
        foreach ($bp->fetchAll() as $b) {
            $pid = (int)$b['produto_id']; $q = (int)$b['quantidade'] * $mult;
            if ($pid && $q > 0) { $bonusAcc[$pid] = ($bonusAcc[$pid] ?? 0) + $q; $temBonus = true; }
        }
        if ($temBonus) $codigosAcionados[] = $code;
    }
    if (!$bonusAcc) return [];

    $obs = 'Bonificação automática de campanha' . ($refNumero ? ' (ref. ' . $refNumero . ')' : '')
         . ($codigosAcionados ? ' — ' . implode(', ', $codigosAcionados) : '');
    return criarPedidoBonificado($clienteId, $supervisor, $dataPedido, $bonusAcc, $obs);
}

/**
 * Detecta campanhas de bonificação SELECIONÁVEL acionadas pela venda. Não cria
 * pedido: retorna a lista de campanhas para o cliente escolher os bônus no
 * fechamento (até o limite de quantidade ou valor, multiplicado por mult).
 *
 * @return array  [['codigo'=>, 'mult'=>, 'limite_tipo'=>'quantidade'|'valor',
 *                  'limite'=>float, 'produtos'=>[['id','codigo','descricao','preco']...]], ...]
 */
function detectarBonificacaoSelecionavel(array $itensVenda, int $canalVendaId): array {
    $ctx = ctxCampanha($itensVenda, $canalVendaId);
    $out = [];
    foreach (campanhasAgrupadas() as $code => $g) {
        if (($g['rows'][0]['tipo'] ?? 'desconto') !== 'bonificacao') continue;
        if (($g['rows'][0]['bonif_modo'] ?? 'fixo') !== 'selecionavel') continue;
        $r = avaliarCampanhaTrigger($g['rows'], $g['conds'], $ctx);
        if (!$r['acionada']) continue;
        $mult = max(1, (int)$r['mult']);

        $lp = db()->prepare("SELECT cb.produto_id, p.codigo_produto, p.descricao_pt,
                                    COALESCE(t.preco_network, p.vendas_varejo, 0) AS preco
                             FROM campanha_bonificacao cb
                             JOIN produtos p ON p.id = cb.produto_id
                             LEFT JOIN tabela_precos t ON t.produto_id = p.id
                             WHERE cb.codigo_campanha = ? AND p.status = 'ativo' ORDER BY p.descricao_pt");
        $lp->execute([$code]);
        $produtos = array_map(fn($p) => [
            'id'        => (int)$p['produto_id'],
            'codigo'    => $p['codigo_produto'],
            'descricao' => $p['descricao_pt'],
            'preco'     => (float)$p['preco'],
        ], $lp->fetchAll());
        if (!$produtos) continue;

        $limiteTipo = ($g['rows'][0]['bonif_limite_tipo'] ?? 'quantidade') === 'valor' ? 'valor' : 'quantidade';
        $limite     = (float)($g['rows'][0]['bonif_limite_valor'] ?? 0) * $mult;
        if ($limite <= 0) continue;
        $out[] = [
            'codigo'      => $code,
            'mult'        => $mult,
            'limite_tipo' => $limiteTipo,
            'limite'      => $limite,
            'produtos'    => $produtos,
        ];
    }
    return $out;
}
