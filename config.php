<?php
if (session_status() === PHP_SESSION_NONE) session_start();

define('DB_HOST', 'localhost');
define('DB_NAME', 'sis_ped');
define('DB_USER', 'root');
define('DB_PASS', '');
define('BASE_URL', '/Sis_Ped');
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
    if (!$u || !in_array($u['tipo'], ['comercial', 'financeiro', 'supervisor', 'tecnologia da informacao', 'vendedor'])) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function requireComercial() {
    $u = usuario();
    if (!$u || !in_array($u['tipo'], ['comercial', 'supervisor', 'tecnologia da informacao', 'vendedor'])) {
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
