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

function flash($type, $msg) {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function getFlash() {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function moedaBR($v) {
    return 'R$ ' . number_format((float)$v, 2, ',', '.');
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
 * Gera um pedido bonificado a partir das campanhas do tipo "bonificacao"
 * acionadas pelos itens de uma venda. A quantidade bonificada multiplica
 * conforme o total comprado: mult = floor(qtd_alvo / quantidade_minima).
 *
 * @param array $itensVenda  itens da venda, cada um: ['produto_id','qtd','linha','grupo','subgrupo']
 * @return array  itens bonificados criados: ['produto_id','descricao','quantidade','pedido_id']
 */
function gerarBonificacaoCampanha(int $clienteId, int $canalVendaId, string $supervisor, string $dataPedido, array $itensVenda, ?string $refNumero = null): array {
    $camps = db()->query("SELECT codigo_campanha, produto_id, linha, grupo, subgrupo, canal_venda_id, quantidade
                          FROM campanhas WHERE tipo = 'bonificacao'")->fetchAll();
    if (!$camps) return [];

    // Totais da venda por produto e por categoria (mesma lógica do desconto)
    $totProd = $totL = $totG = $totS = [];
    foreach ($itensVenda as $it) {
        $q = (int)($it['qtd'] ?? 0); if ($q <= 0) continue;
        $pid = (int)($it['produto_id'] ?? 0);
        if ($pid) $totProd[$pid] = ($totProd[$pid] ?? 0) + $q;
        $l = trim($it['linha']    ?? ''); if ($l) $totL[$l] = ($totL[$l] ?? 0) + $q;
        $g = trim($it['grupo']    ?? ''); if ($g) $totG[$g] = ($totG[$g] ?? 0) + $q;
        $s = trim($it['subgrupo'] ?? ''); if ($s) $totS[$s] = ($totS[$s] ?? 0) + $q;
    }

    $byCode = [];
    foreach ($camps as $c) $byCode[$c['codigo_campanha']][] = $c;

    $bonusAcc = [];          // produto_id => quantidade bonificada
    $codigosAcionados = [];
    foreach ($byCode as $code => $rows) {
        $min = (int)$rows[0]['quantidade']; if ($min <= 0) continue;
        $canal = $rows[0]['canal_venda_id'];
        if ($canal && (int)$canal !== $canalVendaId) continue;

        $prodIds = array_values(array_unique(array_filter(array_map(fn($r) => (int)$r['produto_id'], $rows))));
        $trigger = 0;
        if ($prodIds) {
            foreach ($prodIds as $pid) $trigger += $totProd[$pid] ?? 0;
        } else {
            foreach ($rows as $r) {
                $cL = trim(preg_replace('/\d+/', '', $r['linha']    ?? ''));
                $cG = trim(preg_replace('/\d+/', '', $r['grupo']    ?? ''));
                $cS = trim(preg_replace('/\d+/', '', $r['subgrupo'] ?? ''));
                if ($cL)     $trigger = max($trigger, $totL[$cL] ?? 0);
                elseif ($cG) $trigger = max($trigger, $totG[$cG] ?? 0);
                elseif ($cS) $trigger = max($trigger, $totS[$cS] ?? 0);
            }
        }
        $mult = intdiv($trigger, $min);
        if ($mult < 1) continue;

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

    // Cria o pedido bonificado (lote separado; valor pelo preço Network)
    $lote = uniqid('LB', true);
    $num  = 'PED-' . date('Y') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
    $obs  = 'Bonificação automática de campanha' . ($refNumero ? ' (ref. ' . $refNumero . ')' : '')
          . ($codigosAcionados ? ' — ' . implode(', ', $codigosAcionados) : '');
    $ins  = db()->prepare('INSERT INTO pedidos (numero_pedido,tipo_venda,data_pedido,cliente_id,produto_id,supervisor,codigo_barra,descricao_produto,quantidade_total,valor_total,status,observacoes,lote_id) VALUES (?,?,?,?,?,?,?,?,?,?,"comercial",?,?)');
    $criados = [];
    foreach ($bonusAcc as $pid => $q) {
        // Pedido bonificado usa o preço Network (fallback: venda varejo)
        $pr = db()->prepare('SELECT p.descricao_pt, p.codigo_barra, COALESCE(t.preco_network, p.vendas_varejo, 0) AS preco
                             FROM produtos p LEFT JOIN tabela_precos t ON t.produto_id = p.id
                             WHERE p.id = ? AND p.status = "ativo"');
        $pr->execute([$pid]); $pr = $pr->fetch();
        if (!$pr) continue;
        $valor = $q * (float)$pr['preco'];
        $ins->execute([$num, 'bonificacao', $dataPedido, $clienteId, $pid, $supervisor, $pr['codigo_barra'], $pr['descricao_pt'], $q, $valor, $obs, $lote]);
        $criados[] = ['produto_id' => $pid, 'descricao' => $pr['descricao_pt'], 'quantidade' => $q, 'pedido_id' => (int)db()->lastInsertId()];
    }
    if (!$criados) return [];

    try {
        db()->prepare('INSERT INTO pedido_logs (pedido_id,numero_pedido,usuario_nome,usuario_tipo,acao,status_antes,status_depois,detalhes) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$criados[0]['pedido_id'], $num, 'Sistema', 'sistema', 'Bonificação gerada por campanha', null, 'comercial', $obs]);
    } catch (PDOException $e) {}

    return $criados;
}
