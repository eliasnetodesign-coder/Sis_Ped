<?php
if (session_status() === PHP_SESSION_NONE) session_start();

define('DB_HOST', 'mariadb');
define('DB_NAME', 'sis_ped');
define('DB_USER', 'sis_ped_user');
define('DB_PASS', 'WFZ9STR344a1EbRDVzZf');
define('BASE_URL', '');
define('EMPRESA_UF', 'SP'); // UF da empresa — define ICMS local x interestadual no detalhamento fiscal
define('ASSETS_URL', BASE_URL . '/assets');
define('LAYOUT_PATH', __DIR__ . '/layout');

// Segurança de acesso — usuários do tipo "Externo" só dispensam verificação
// quando logam a partir deste IP. Fora dele, exige código por WhatsApp (2FA).
define('IP_LIBERADO', '201.6.128.102');
define('WHATSAPP_REMETENTE', '11 99982-5523'); // número que envia a verificação
define('WHATSAPP_CODIGO_VALIDADE', 600);        // validade do código, em segundos (10 min)

// Sistema Itallian Hairtech (A&M) — usado por "Importa Pedido" para localizar
// pedidos já lançados lá e trazer os itens (Código A&M + Qtd) para o SisPed.
define('AEM_URL',   'https://sistema.itallianhairtech.com.br');
define('AEM_LOGIN', 'I003');
define('AEM_SENHA', 'Itallian142');

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
            try { $pdo->exec("ALTER TABLE configuracoes MODIFY COLUMN valor TEXT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("UPDATE clientes SET email = NULL WHERE email = ''"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE tabela_precos ADD COLUMN preco_network DECIMAL(10,2) NULL DEFAULT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE tabela_precos ADD COLUMN preco_auxiliar DECIMAL(10,2) NULL DEFAULT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE pedidos ADD COLUMN desconto_campanha DECIMAL(5,2) NULL DEFAULT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE produtos ADD COLUMN desc_cliente_pt TEXT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE produtos ADD COLUMN desc_cliente_en TEXT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE produtos ADD COLUMN desc_cliente_es TEXT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS bonus_ma_logs (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                cliente_id      INT NOT NULL,
                mes             TINYINT NOT NULL,
                ano             SMALLINT NOT NULL,
                acao            ENUM('aprovado','reprovado') NOT NULL,
                usuario_nome    VARCHAR(100),
                created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_cliente_mes_ano (cliente_id, mes, ano)
            )"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE bonus_ma_logs ADD COLUMN valor_utilizado DECIMAL(10,2) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE pedidos ADD COLUMN forma_pagamento VARCHAR(60) NULL DEFAULT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS creditos (
                id                  INT AUTO_INCREMENT PRIMARY KEY,
                cliente_id          INT NOT NULL,
                descricao           VARCHAR(255) NOT NULL,
                observacao_interna  TEXT,
                valor               DECIMAL(12,2) NOT NULL,
                data                DATE NOT NULL,
                usuario_id          INT NULL,
                created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_cliente (cliente_id)
            )"); } catch (PDOException $e) {}
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS creditos_logs (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                credito_id   INT NOT NULL,
                acao         ENUM('aprovado','reprovado') NOT NULL,
                usuario_nome VARCHAR(100),
                created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_credito (credito_id)
            )"); } catch (PDOException $e) {}
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
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS impostos_empresas (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                nome       VARCHAR(120) NOT NULL,
                cnpj       VARCHAR(20)  NOT NULL,
                irpj       DECIMAL(5,2) NOT NULL DEFAULT 0,
                csll       DECIMAL(5,2) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE impostos_empresas ADD COLUMN iss DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER csll"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE ncm ADD COLUMN pis_accademia DECIMAL(10,4) NULL DEFAULT 0 AFTER cofins"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE ncm ADD COLUMN cofins_accademia DECIMAL(10,4) NULL DEFAULT 0 AFTER pis_accademia"); } catch (PDOException $e) {}
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
            // Campanhas — reestruturação: status ativo, condição por quantidade OU valor,
            // condição por produto, alvo explícito do desconto e pool selecionável por categoria.
            try { $pdo->exec("ALTER TABLE campanhas ADD COLUMN ativo TINYINT NOT NULL DEFAULT 1"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE campanhas ADD COLUMN bonif_selec_modo VARCHAR(20) NOT NULL DEFAULT 'produtos'"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE campanha_condicoes ADD COLUMN criterio_modo VARCHAR(20) NOT NULL DEFAULT 'quantidade'"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE campanha_condicoes ADD COLUMN valor_min DECIMAL(12,2) NULL DEFAULT NULL"); } catch (PDOException $e) {}
            // Condição = filtro composto (linha E grupo E subgrupo E produto). Ex.: "linha X do grupo Y".
            try { $pdo->exec("ALTER TABLE campanha_condicoes ADD COLUMN cond_linha VARCHAR(100) NULL DEFAULT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE campanha_condicoes ADD COLUMN cond_grupo VARCHAR(100) NULL DEFAULT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE campanha_condicoes ADD COLUMN cond_subgrupo VARCHAR(100) NULL DEFAULT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE campanha_condicoes ADD COLUMN cond_produto_id INT NULL DEFAULT NULL"); } catch (PDOException $e) {}
            // Margem de negociação do canal = teto do Desconto Comercial aplicável no pedido.
            try { $pdo->exec("ALTER TABLE canal_venda ADD COLUMN margem_negociacao DECIMAL(10,4) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
            // Classificação do canal: Network ou Network / Accademia.
            try { $pdo->exec("ALTER TABLE canal_venda ADD COLUMN network_tipo VARCHAR(30) NOT NULL DEFAULT 'Network'"); } catch (PDOException $e) {}
            // Descontos extras por item do pedido: Comercial (limitado pela margem de negociação) e Diretoria (sem limite).
            try { $pdo->exec("ALTER TABLE pedidos ADD COLUMN desconto_comercial DECIMAL(10,4) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE pedidos ADD COLUMN desconto_diretoria DECIMAL(10,4) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS campanha_desconto_alvo (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                codigo_campanha VARCHAR(50)  NOT NULL,
                alvo_tipo       VARCHAR(20)  NOT NULL,
                alvo_valor      VARCHAR(100) NOT NULL,
                KEY idx_camp_desc_alvo (codigo_campanha)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (PDOException $e) {}
            // Alvo do desconto = filtro composto (linha E grupo E subgrupo E produto), igual às condições.
            try { $pdo->exec("ALTER TABLE campanha_desconto_alvo ADD COLUMN alvo_linha VARCHAR(100) NULL DEFAULT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE campanha_desconto_alvo ADD COLUMN alvo_grupo VARCHAR(100) NULL DEFAULT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE campanha_desconto_alvo ADD COLUMN alvo_subgrupo VARCHAR(100) NULL DEFAULT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE campanha_desconto_alvo ADD COLUMN alvo_produto_id INT NULL DEFAULT NULL"); } catch (PDOException $e) {}
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS campanha_bonif_pool (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                codigo_campanha VARCHAR(50)  NOT NULL,
                alvo_tipo       VARCHAR(20)  NOT NULL,
                alvo_valor      VARCHAR(100) NOT NULL,
                KEY idx_camp_bonif_pool (codigo_campanha)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE metas ADD UNIQUE KEY cliente_trimestre_ano_unique (cliente_id, trimestre, ano)"); } catch (PDOException $e) {}
            // Regime tributário do cliente, usado no cálculo de impostos/margens.
            try { $pdo->exec("ALTER TABLE clientes ADD COLUMN regime_tributario ENUM('Simples Nacional','Lucro Real','Lucro Presumido') NULL DEFAULT NULL"); } catch (PDOException $e) {}

            // Log de liberações de pedido feitas pelo SisPed no sistema A&M (botão "Liberar" na
            // Análise Financeira — ver liberarPedidoAEM() em config.php).
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS liberacoes_am_logs (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                sid_ped        VARCHAR(20) NOT NULL,
                numero_pedido  VARCHAR(20) NULL,
                codigo_cliente VARCHAR(20) NULL,
                cliente_nome   VARCHAR(180) NULL,
                usuario_id     INT NULL,
                usuario_nome   VARCHAR(100) NULL,
                status         ENUM('liberado','erro','pulado_avista') NOT NULL,
                resposta       TEXT NULL,
                created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_sid_ped (sid_ped),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (PDOException $e) {}

            // Log de gravação do % Diretoria de itens direto no pedido do A&M
            // (botão "Importa Pedido BF" — ver aplicarDescontoDiretoriaAEM() em config.php).
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS descontos_diretoria_am_logs (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                numero_pedido  VARCHAR(20) NULL,
                sid_ped        VARCHAR(20) NULL,
                pedido_interno VARCHAR(20) NULL,
                pedido_sisped  INT NULL,
                usuario_id     INT NULL,
                usuario_nome   VARCHAR(100) NULL,
                itens          TEXT NULL,
                status         ENUM('gravado','parcial','erro') NOT NULL,
                mensagem       TEXT NULL,
                resposta       MEDIUMTEXT NULL,
                created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_numero (numero_pedido),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (PDOException $e) {}

            // Campanhas "Beauty" do check 5 da Análise Financeira A&M — independente do
            // módulo de campanhas do pedido local (tabela `campanhas`); ver campanhas-am-dados.php.
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS campanhas_am (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                nome          VARCHAR(120) NOT NULL,
                tipo          ENUM('desconto','bonificacao') NOT NULL DEFAULT 'desconto',
                criterio      ENUM('quantidade','valor') NOT NULL DEFAULT 'quantidade',
                unidade       VARCHAR(20) NULL,
                observacoes   TEXT NULL,
                ativo         TINYINT NOT NULL DEFAULT 1,
                ordem         INT NOT NULL DEFAULT 0,
                created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (PDOException $e) {}
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS campanhas_am_produtos (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                campanha_id    INT NOT NULL,
                codigo_produto VARCHAR(30) NOT NULL,
                produto_nome   VARCHAR(180) NULL,
                KEY idx_campanha (campanha_id),
                KEY idx_codigo (codigo_produto)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (PDOException $e) {}
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS campanhas_am_faixas (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                campanha_id  INT NOT NULL,
                minimo       DECIMAL(12,2) NOT NULL DEFAULT 0,
                maximo       DECIMAL(12,2) NULL,
                percentual   DECIMAL(6,2) NOT NULL DEFAULT 0,
                KEY idx_campanha (campanha_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (PDOException $e) {}
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS campanhas_am_bonificacao (
                id                   INT AUTO_INCREMENT PRIMARY KEY,
                campanha_id          INT NOT NULL,
                qtd_base             INT NOT NULL DEFAULT 1,
                produto_bonus_codigo VARCHAR(30) NOT NULL,
                produto_bonus_nome   VARCHAR(180) NULL,
                qtd_bonus            INT NOT NULL DEFAULT 1,
                KEY idx_campanha (campanha_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (PDOException $e) {}
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS campanhas_am_fora (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                codigo_produto VARCHAR(30) NOT NULL,
                produto_nome   VARCHAR(180) NULL,
                UNIQUE KEY uq_codigo (codigo_produto)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (PDOException $e) {}
            try {
                if ((int)$pdo->query('SELECT COUNT(*) FROM campanhas_am')->fetchColumn() === 0) {
                    campanhasAmSeedInicial($pdo);
                }
            } catch (PDOException $e) {}
        } catch (PDOException $e) {
            die('Erro de conexão com o banco de dados. <a href="' . BASE_URL . '/install.php">Clique aqui para configurar.</a>');
        }
    }
    return $pdo;
}

function e($s) {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Idioma corrente da ÁREA DO CLIENTE (pt|en|es). Resolve a partir do idioma
 * cadastrado no cliente logado; para admin/visitante retorna sempre 'pt'.
 * Cacheado por requisição (uma consulta por cliente). Os produtos têm tradução
 * própria nos cadastros e NÃO usam este idioma.
 */
function idiomaAtual(): string {
    static $cache = [];
    $u = usuario();
    if (!$u || ($u['tipo'] ?? '') !== 'cliente') return 'pt';
    $id = (int)($u['id'] ?? 0);
    if (!array_key_exists($id, $cache)) {
        $idi = 'pt';
        try {
            $st = db()->prepare('SELECT idioma FROM clientes WHERE id = ?');
            $st->execute([$id]);
            $v = strtolower(substr((string)$st->fetchColumn(), 0, 2));
            if ($v === 'en' || $v === 'es') $idi = $v;
        } catch (PDOException $e) { /* mantém pt */ }
        $cache[$id] = $idi;
    }
    return $cache[$id];
}

/** Código de idioma para o atributo <html lang="..."> da área do cliente. */
function htmlLang(): string {
    switch (idiomaAtual()) {
        case 'en': return 'en';
        case 'es': return 'es';
        default:   return 'pt-BR';
    }
}

/**
 * Traduz uma string da área do cliente. A própria frase em PT é a chave:
 * em 'pt' retorna a frase original; em 'en'/'es' busca no dicionário lang.php
 * e cai de volta para a frase PT quando não há tradução. Aceita placeholders
 * sprintf opcionais: t('Olá, %s', $nome).
 */
function t(string $pt, ...$args): string {
    static $dict = null;
    if ($dict === null) {
        $f = __DIR__ . '/lang.php';
        $dict = is_file($f) ? (require $f) : [];
    }
    $lang = idiomaAtual();
    $s = ($lang !== 'pt' && isset($dict[$lang][$pt])) ? $dict[$lang][$pt] : $pt;
    if ($args) $s = vsprintf($s, $args);
    return $s;
}

/** Igual a t(), mas já escapa para HTML (htmlspecialchars). */
function et(string $pt, ...$args): string {
    return e(t($pt, ...$args));
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
 * Localiza, no sistema Itallian Hairtech (A&M), o pedido cujo campo "Número
 * do Pedido do Cliente" bate com $numero (módulo Vendas > Consulta/Reimprime),
 * e devolve o cliente, o tipo de venda e os itens (Código A&M + Qtd já final,
 * %Negociação = nosso Desc. Comercial, %Diretoria = nosso Desc. Diretoria)
 * lançados lá. Usado por "Importa Pedido" (admin/pedidos.php).
 * Retorna ['ok'=>bool,'erro'=>?string,'clienteNome','clienteCnpj','tipoVenda',
 *          'pedidoInterno','numero','formaPagto' (coluna "Forma Pagto" do grid de
 *          busca), 'isAVista' (true quando "00 - A Vista" — aciona 5% de desconto,
 *          igual ao recurso de desconto Pix), 'descontoCanalAEM' (coluna "%Descto"
 *          do detalhe do pedido = nosso "Desconto do Canal"), 'descontoClienteAEM'
 *          (coluna "%Descto ST" = nosso "Desconto do Cliente"), 'pedidoAccademia'
 *          (coluna "Pedido Accademia" do Cadastro de Distribuidores — 'SIM'|'NAO'|null —
 *          SIM define o Canal de Venda do cliente como "Distribuidor", NAO como "Varejo"),
 *          'creditoUtilizadoAEM' (coluna "Credito Utilizado" do mesmo grid de busca —
 *          crédito do cliente já abatido do pedido lá no A&M; a importação lança essa
 *          quantia como concessão de crédito do cliente, já consumida por este pedido),
 *          'obs' (coluna "Obs" do grid Consulta/Reimprime), 'ehBf' (true quando o Obs
 *          começa com "BF" — pedido de campanha, usado pelo "Importa Pedido BF"),
 *          'itens'=>[['codigoAEM','nomeProduto','qtd','descComercial','descDiretoria','valorTotal'],...]].
 */
function buscarPedidoAEM(string $numero): array {
    $numero = preg_replace('/\D/', '', $numero);
    if ($numero === '') return ['ok' => false, 'erro' => 'Informe um número de pedido válido.'];
    if (!function_exists('curl_init')) return ['ok' => false, 'erro' => 'Extensão cURL indisponível no servidor.'];

    $chamar = function (string $path, ?array $postFields) {
        $ch = curl_init(AEM_URL . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if ($postFields !== null) {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($postFields);
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        curl_close($ch);
        return $resp === false ? '' : $resp;
    };

    // 1) Login — a sessão do A&M trafega via o parâmetro LNKTRANSPORTE, não por cookie.
    $loginHtml = $chamar('/cgi-bin/ITF/ITF.EXE', [
        'SubMenu'           => 'FROTA',
        'TxtLgloginUsuario' => AEM_LOGIN,
        'PwdLgloginSenha'   => AEM_SENHA,
    ]);
    if (!preg_match('/LNKTRANSPORTE=([0-9A-Za-z]+)/', $loginHtml, $m)) {
        return ['ok' => false, 'erro' => 'Não foi possível autenticar no sistema A&M.'];
    }
    $token = $m[1];

    // 2) Busca no grid "Consulta/Reimprime Pedidos" (Vendas) pelo Número do Pedido do Cliente.
    // O período padrão da tela é só o mês corrente — abrimos para 01/01/2000 até hoje.
    $buscaHtml = $chamar('/cgi-bin/ITF/PD0301.EXE', [
        'LNKTRANSPORTE' => $token,
        'SubOpcao'      => '',
        'SubForm'       => '',
        'TxtPedCliente' => $numero,
        'TxtNumero'     => '',
        'TxtDiaInicio'  => '01', 'TxtMesInicio' => '01', 'TxtAnoInicio' => '2000',
        'TxtDiaFim'     => date('d'), 'TxtMesFim' => date('m'), 'TxtAnoFim' => date('Y'),
        'SelVendedor'   => '', 'TxtCodDist' => '', 'TxtDistrib' => '', 'TxtProduto' => '',
    ]);
    preg_match_all('/PD0303\.EXE\?LNKTRANSPORTE=[0-9A-Za-z]+&SidPed=(\d+)/', $buscaHtml, $mm);
    $sids = array_values(array_unique($mm[1] ?? []));
    if (count($sids) === 0) return ['ok' => false, 'erro' => 'Pedido não encontrado no sistema A&M.'];
    if (count($sids) > 1)  return ['ok' => false, 'erro' => 'Mais de um pedido encontrado com esse número — confira no sistema A&M.'];
    $sidPed = $sids[0];

    // Coluna "Forma Pagto" (14ª <TD> da linha) do próprio grid de busca — "00 - A Vista"
    // significa pagamento à vista e aciona o desconto de 5% (mesmo recurso do "Pix").
    // Coluna "Codigo" (8ª <TD>) identifica o cliente/distribuidor — usada a seguir para
    // consultar o Cadastro de Distribuidores. Coluna "Credito Utilizado" (11ª <TD>) traz
    // o valor de crédito do cliente já abatido do pedido lá no A&M.
    $parseValorBR = function ($cell) {
        preg_match('/[\d.,]+/', (string)$cell, $mm);
        return isset($mm[0]) ? (float)str_replace(['.', ','], ['', '.'], $mm[0]) : 0.0;
    };
    $formaPagto = '';
    $codigoClienteAEM = '';
    $creditoUtilizadoAEM = 0.0;
    $obsPedido = '';
    preg_match_all('/<TR[^>]*>(.*?)<\/TR>/is', $buscaHtml, $rowsBusca);
    foreach ($rowsBusca[1] as $rowHtml) {
        if (strpos($rowHtml, 'SidPed=' . $sidPed) === false) continue;
        preg_match_all('/<TD[^>]*>(.*?)<\/TD>/is', $rowHtml, $cellsBuscaM);
        $cellsBusca = array_map(function ($c) {
            return trim(html_entity_decode(strip_tags($c), ENT_QUOTES, 'UTF-8'));
        }, $cellsBuscaM[1] ?? []);
        if (isset($cellsBusca[14])) $formaPagto = $cellsBusca[14];
        if (isset($cellsBusca[7]))  $codigoClienteAEM = $cellsBusca[7];
        if (isset($cellsBusca[10])) $creditoUtilizadoAEM = $parseValorBR($cellsBusca[10]);
        // "Obs" do pedido = célula logo após a "Forma Pagto" ("00 - A Vista", "71B - 30/60/90DD"…).
        // Mesmo critério do módulo Análise Financeira: pedido de campanha começa com "BF".
        foreach ($cellsBusca as $ix => $cel) {
            if (preg_match('/^\d+[A-Za-z]?\s*-\s*\S/', $cel)) { $obsPedido = $cellsBusca[$ix + 1] ?? ''; break; }
        }
        break;
    }
    preg_match('/^(\d+)/', $formaPagto, $mfp);
    $isAVista = (($mfp[1] ?? '') === '00') && (stripos($formaPagto, 'vista') !== false);

    // Cadastro de Distribuidores (Cadastro > Cadastro de Distribuidores, CL200.EXE): localiza
    // a linha pelo "Codigo" (2ª <TD>) e lê a coluna "Pedido Accademia" (última <TD>, SIM/NAO) —
    // usada para definir o Canal de Venda do cliente no SisPed (SIM=Distribuidor, NAO=Varejo).
    $pedidoAccademia = null;
    if ($codigoClienteAEM !== '') {
        $distribHtml = $chamar('/cgi-bin/ITF/CL200.EXE', [
            'LNKTRANSPORTE' => $token,
            'HidMenu'       => 'CLMENU.EXE',
            'SubMenu'       => 'FROTA',
        ]);
        // Página com milhares de linhas — localiza os limites do <TBODY> com strpos (não regex,
        // que estoura o backtrack limit do PCRE ao varrer um documento desse tamanho).
        $posTabela = strpos($distribHtml, 'id="tabela"');
        if ($posTabela === false) $posTabela = strpos($distribHtml, "id='tabela'");
        $posTBody = $posTabela !== false ? strpos($distribHtml, '<TBODY>', $posTabela) : false;
        $posTBodyFim = $posTBody !== false ? strpos($distribHtml, '</TBODY>', $posTBody) : false;
        if ($posTBody !== false && $posTBodyFim !== false) {
            $tbodyDist = substr($distribHtml, $posTBody + 7, $posTBodyFim - $posTBody - 7);
            preg_match_all('/<TR[^>]*>(.*?)<\/TR>/is', $tbodyDist, $distRows);
            foreach ($distRows[1] as $rowHtml) {
                preg_match_all('/<TD[^>]*>(.*?)<\/TD>/is', $rowHtml, $cellsDistM);
                $cellsDist = array_map(function ($c) {
                    return trim(html_entity_decode(strip_tags($c), ENT_QUOTES, 'UTF-8'));
                }, $cellsDistM[1] ?? []);
                if (count($cellsDist) < 18) continue;
                // O último dígito do "Codigo" identifica a loja (mesmo cliente pode ter
                // mais de uma) — compara só os 5 primeiros dígitos dos dois lados.
                if (substr($cellsDist[1], 0, 5) === substr($codigoClienteAEM, 0, 5)) {
                    $pedidoAccademia = strtoupper($cellsDist[17]);
                    break;
                }
            }
        }
    }

    // 3) Detalhe do pedido (coluna "Pedido Interno" do grid de busca).
    $raw = $chamar('/cgi-bin/ITF/PD0303.EXE?LNKTRANSPORTE=' . $token . '&SidPed=' . $sidPed, null);
    if ($raw === '') return ['ok' => false, 'erro' => 'Falha ao consultar o detalhe do pedido no A&M.'];
    $html = @mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
    if ($html === false || $html === '') $html = $raw;

    preg_match('/Cliente:\s*<\/FONT>\s*<\/TD>\s*<TD[^>]*>\s*<FONT[^>]*>\s*<b>\s*([^<]*?)\s*<\/b>/is', $html, $mc);
    $clienteNome = trim(preg_replace('/^\d+\s+/', '', trim($mc[1] ?? '')));

    preg_match('/CNPJ:\s*<\/FONT>\s*<\/TD>\s*<TD[^>]*>\s*<FONT[^>]*>\s*<b>\s*([^<]*?)\s*<\/b>/is', $html, $mj);
    $clienteCnpj = preg_replace('/\D/', '', trim($mj[1] ?? ''));

    preg_match('/Pedido Interno\s+([\d.]+)\s+(\d{2}\/\d{2}\/\d{4})\s+([A-Z])-/is', $html, $mt);
    $pedidoInterno = $mt[1] ?? null;
    $tipoVenda = (isset($mt[3]) && strtoupper($mt[3]) === 'B') ? 'bonificacao' : 'venda';

    // Tabela de itens (div id="itens"): Seq, Código DZYON (rótulo da própria coluna no A&M), Catalogo, Nome do Produto, ST,
    // Preço Tabela, %Descto, %Descto ST, Valor Unitário, %Negociação, Valor Negociado,
    // %Diretoria, Qtd, Valor Líquido, Valor Total.
    if (!preg_match('/id=["\']itens["\'].*?<TBODY>(.*?)<\/TBODY>/is', $html, $mBody)) {
        return ['ok' => false, 'erro' => 'Não foi possível ler os itens do pedido no A&M.'];
    }
    preg_match_all('/<TR[^>]*>(.*?)<\/TR>/is', $mBody[1], $rows);
    $parsePct = function ($cell) {
        preg_match('/[\d,]+/', $cell, $mm);
        return isset($mm[0]) ? (float)str_replace(',', '.', $mm[0]) : 0.0;
    };
    $itens = [];
    $descontoCanalAEM = 0.0;
    $descontoClienteAEM = 0.0;
    foreach ($rows[1] as $rowHtml) {
        preg_match_all('/<TD[^>]*>(.*?)<\/TD>/is', $rowHtml, $cellsM);
        $cells = array_map(function ($c) {
            return trim(html_entity_decode(strip_tags($c), ENT_QUOTES, 'UTF-8'));
        }, $cellsM[1] ?? []);
        if (count($cells) < 13) continue;
        if (!$itens) {
            // %Descto (col 6) = nosso "Desconto do Canal"; %Descto ST (col 7) = nosso "Desconto do Cliente".
            $descontoCanalAEM   = $parsePct($cells[6]);
            $descontoClienteAEM = $parsePct($cells[7]);
        }
        $itens[] = [
            'codigoAEM' => $cells[1],
            'nomeProduto' => $cells[3],
            'qtd'         => (int)preg_replace('/\D/', '', $cells[12]),
            // %Negociação (col 9) = nosso "Desc. Comercial"; %Diretoria (col 11) = nosso "Desc. Diretoria".
            'descComercial' => $parsePct($cells[9]),
            'descDiretoria' => $parsePct($cells[11]),
            // Valor Total do item (col 14) — usado pela análise de campanha do "Importa Pedido BF".
            'valorTotal'  => $parseValorBR($cells[14] ?? ''),
        ];
    }
    if (!$itens) return ['ok' => false, 'erro' => 'Pedido encontrado, mas sem itens no A&M.'];

    return [
        'ok'                 => true,
        'erro'               => null,
        'clienteNome'        => $clienteNome,
        'clienteCnpj'        => $clienteCnpj,
        'tipoVenda'          => $tipoVenda,
        'pedidoInterno'      => $pedidoInterno,
        'numero'             => $numero,
        'formaPagto'         => $formaPagto,
        'isAVista'           => $isAVista,
        'descontoCanalAEM'   => $descontoCanalAEM,
        'descontoClienteAEM' => $descontoClienteAEM,
        'pedidoAccademia'    => $pedidoAccademia,
        'creditoUtilizadoAEM' => $creditoUtilizadoAEM,
        'obs'                => $obsPedido,
        'ehBf'               => (strtoupper(substr(trim($obsPedido), 0, 2)) === 'BF'),
        'itens'              => $itens,
    ];
}

/**
 * Tabela "Estados com ST" (percentual de ST por UF) usada na análise financeira.
 * Guardada em `configuracoes` (chave `st_estados`, JSON) e editável pela tela
 * Financeiro > Análise Financeira. Cada item: UF => [nome, com_academia, sem_academia].
 * O percentual só se aplica "quando o DI ou Loja reclamam"; caso contrário fica sem ST.
 */
function stEstadosPadrao(): array {
    $base = [
        'AC' => ['Acre', 0, 0],                'AL' => ['Alagoas', 13, 13],
        'AP' => ['Amapá', 5, 13],              'AM' => ['Amazonas', 0, 0],
        'BA' => ['Bahia', 0, 0],               'CE' => ['Ceará', 0, 0],
        'ES' => ['Espírito Santo', 0, 0],      'GO' => ['Goiás', 0, 0],
        'MA' => ['Maranhão', 0, 0],            'MT' => ['Mato Grosso', 5, 13],
        'MS' => ['Mato Grosso do Sul', 0, 0],  'MG' => ['Minas Gerais', 8, 13],
        'PA' => ['Pará', 0, 0],                'PB' => ['Paraíba', 0, 0],
        'PR' => ['Paraná', 5, 13],             'PE' => ['Pernambuco', 0, 0],
        'PI' => ['Piauí', 0, 0],               'RJ' => ['Rio de Janeiro', 8, 13],
        'RN' => ['Rio Grande do Norte', 0, 0], 'RS' => ['Rio Grande do Sul', 8, 13],
        'RO' => ['Rondônia', 0, 0],            'RR' => ['Roraima', 0, 0],
        'SC' => ['Santa Catarina', 0, 0],      'SP' => ['São Paulo', 5, 13],
        'SE' => ['Sergipe', 0, 0],             'TO' => ['Tocantins', 0, 0],
        'DF' => ['Distrito Federal', 0, 0],
    ];
    $out = [];
    foreach ($base as $uf => $r) {
        $out[$uf] = ['nome' => $r[0], 'com_academia' => (float)$r[1], 'sem_academia' => (float)$r[2]];
    }
    return $out;
}

function stEstadosTabela(): array {
    $out  = stEstadosPadrao();
    $json = getConfig('st_estados');
    if ($json) {
        $d = json_decode($json, true);
        if (is_array($d)) {
            foreach ($out as $uf => &$def) {
                // formato compacto salvo: {"UF":[com,sem]}; aceita também {"UF":{"com_academia":..}}
                if (isset($d[$uf])) {
                    $v = $d[$uf];
                    if (array_key_exists(0, (array)$v)) {
                        $def['com_academia'] = (float)$v[0];
                        $def['sem_academia'] = (float)$v[1];
                    } else {
                        if (isset($v['com_academia'])) $def['com_academia'] = (float)$v['com_academia'];
                        if (isset($v['sem_academia'])) $def['sem_academia'] = (float)$v['sem_academia'];
                    }
                }
            }
            unset($def);
        }
    }
    return $out;
}

function stEstadosSalvar(array $tabela): void {
    $pad = stEstadosPadrao();
    $delta = [];
    foreach ($pad as $uf => $def) {
        $c = isset($tabela[$uf]['com_academia']) ? max(0.0, (float)str_replace(',', '.', $tabela[$uf]['com_academia'])) : $def['com_academia'];
        $s = isset($tabela[$uf]['sem_academia']) ? max(0.0, (float)str_replace(',', '.', $tabela[$uf]['sem_academia'])) : $def['sem_academia'];
        // guarda só o que difere do padrão (mantém o JSON pequeno)
        if (abs($c - $def['com_academia']) > 0.0001 || abs($s - $def['sem_academia']) > 0.0001) {
            $delta[$uf] = [$c + 0, $s + 0];
        }
    }
    setConfig('st_estados', $delta ? json_encode($delta) : '');
}

/**
 * Popula campanhas_am/campanhas_am_produtos/campanhas_am_faixas/campanhas_am_bonificacao/
 * campanhas_am_fora a partir de campanhas-am-dados.php — só roda quando campanhas_am está
 * vazia (chamado de db(), logo depois de criar as tabelas). Depois disso a edição é feita
 * pela tela (botão "Campanhas" em admin/financeiro/analise-financeira.php).
 */
function campanhasAmSeedInicial(PDO $pdo): void {
    $arq = __DIR__ . '/campanhas-am-dados.php';
    if (!is_file($arq)) return;
    $dados = require $arq;
    if (!is_array($dados) || empty($dados['campanhas'])) return;

    $insCamp  = $pdo->prepare('INSERT INTO campanhas_am (nome,tipo,criterio,unidade,observacoes,ativo,ordem) VALUES (?,?,?,?,?,1,?)');
    $insFaixa = $pdo->prepare('INSERT INTO campanhas_am_faixas (campanha_id,minimo,maximo,percentual) VALUES (?,?,?,?)');
    $insProd  = $pdo->prepare('INSERT INTO campanhas_am_produtos (campanha_id,codigo_produto,produto_nome) VALUES (?,?,?)');
    $insBonif = $pdo->prepare('INSERT INTO campanhas_am_bonificacao (campanha_id,qtd_base,produto_bonus_codigo,produto_bonus_nome,qtd_bonus) VALUES (?,?,?,?,?)');

    foreach ($dados['campanhas'] as $ordem => $c) {
        $insCamp->execute([$c['nome'], $c['tipo'], $c['criterio'], $c['unidade'] ?? null, $c['observacoes'] ?? null, $ordem]);
        $campId = (int)$pdo->lastInsertId();
        foreach ($c['faixas'] ?? [] as $f) $insFaixa->execute([$campId, $f[0], $f[1], $f[2]]);
        foreach ($c['produtos'] ?? [] as $p) $insProd->execute([$campId, $p[0], $p[1]]);
        foreach ($c['bonificacao'] ?? [] as $b) $insBonif->execute([$campId, $b['qtd_base'], $b['produto_bonus_codigo'], $b['produto_bonus_nome'], $b['qtd_bonus']]);
    }

    $insFora = $pdo->prepare('INSERT IGNORE INTO campanhas_am_fora (codigo_produto,produto_nome) VALUES (?,?)');
    foreach ($dados['fora_campanha'] ?? [] as $p) $insFora->execute([$p[0], $p[1]]);
}

/** Lista todas as campanhas_am com faixas/produtos/bonificação aninhados (para a tela editável e para campanhasAmAvaliarPedido()). */
function campanhasAmListar(): array {
    $rows = db()->query('SELECT * FROM campanhas_am ORDER BY ordem, id')->fetchAll();
    $ids = array_column($rows, 'id');
    $faixasPor = []; $produtosPor = []; $bonifPor = [];
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $fq = db()->prepare("SELECT * FROM campanhas_am_faixas WHERE campanha_id IN ($ph) ORDER BY minimo");
        $fq->execute($ids);
        foreach ($fq->fetchAll() as $f) $faixasPor[$f['campanha_id']][] = $f;

        $pq = db()->prepare("SELECT * FROM campanhas_am_produtos WHERE campanha_id IN ($ph) ORDER BY id");
        $pq->execute($ids);
        foreach ($pq->fetchAll() as $p) $produtosPor[$p['campanha_id']][] = $p;

        $bq = db()->prepare("SELECT * FROM campanhas_am_bonificacao WHERE campanha_id IN ($ph) ORDER BY id");
        $bq->execute($ids);
        foreach ($bq->fetchAll() as $b) $bonifPor[$b['campanha_id']][] = $b;
    }
    foreach ($rows as &$r) {
        $r['faixas']      = $faixasPor[$r['id']] ?? [];
        $r['produtos']    = $produtosPor[$r['id']] ?? [];
        $r['bonificacao'] = $bonifPor[$r['id']] ?? [];
    }
    unset($r);
    return $rows;
}

/** Lista os produtos cadastrados como "fora de campanha" (campanhas_am_fora). */
function campanhasAmForaLista(): array {
    return db()->query('SELECT * FROM campanhas_am_fora ORDER BY produto_nome')->fetchAll();
}

/** Acha, entre as faixas de uma campanha ([id,minimo,maximo,percentual]), a de maior "minimo" que $valor ainda atinge (null = nenhuma faixa atingida). */
function campanhasAmFaixaPara(array $faixas, float $valor): ?array {
    $ordenadas = $faixas;
    usort($ordenadas, fn($a, $b) => (float)$a['minimo'] <=> (float)$b['minimo']);
    $achou = null;
    foreach ($ordenadas as $f) {
        if ($valor + 0.0001 >= (float)$f['minimo']) $achou = $f; else break;
    }
    return $achou;
}

/**
 * Check 5 (Campanhas): confere os itens de UM pedido do A&M contra as campanhas "Beauty"
 * cadastradas em campanhas_am. Regra combinada com o usuário:
 *  - Campanhas tipo "desconto" têm faixas por quantidade OU valor agregado dos produtos da
 *    sua lista presentes no pedido; a faixa atingida define o %Diretoria "esperado" — só o
 *    campo %Diretoria do item é comparado (não %Negociação).
 *  - Item que não pertence a nenhuma campanha e tem %Diretoria > 0, ou que pertence a uma
 *    campanha mas tem %Diretoria acima do esperado pela faixa atingida, é sinalizado como
 *    "fora de campanha".
 *  - Campanhas tipo "bonificacao" (ex.: Camp 6): a cada N unidades do produto gatilho no
 *    MESMO pedido, confere se a quantidade correspondente do produto de bonificação também
 *    está presente no pedido.
 *
 * @param array $itens itens do pedido (PD0303): [['codigo','nome','qtd','pct_diretoria','valor_total',...], ...]
 * @return array ['campanhas_atingidas'=>[...], 'itens_fora_campanha'=>[...], 'tem_item_fora_campanha'=>bool,
 *                'bonificacoes'=>[...], 'check_campanha'=>bool]
 */
function campanhasAmAvaliarPedido(array $itens): array {
    static $campanhas = null, $mapaCampanha = null;
    if ($campanhas === null) {
        $campanhas = campanhasAmListar();
        $mapaCampanha = [];
        foreach ($campanhas as $c) {
            if (empty($c['ativo'])) continue;
            foreach ($c['produtos'] as $p) $mapaCampanha[$p['codigo_produto']] = $c['id'];
        }
    }
    $porId = [];
    foreach ($campanhas as $c) $porId[$c['id']] = $c;

    // 1) Agrega qtd/valor por campanha, a partir dos itens deste pedido.
    $agQtd = []; $agValor = []; $itensPorCampanha = [];
    foreach ($itens as $it) {
        $campId = $mapaCampanha[$it['codigo'] ?? ''] ?? null;
        if ($campId === null || $porId[$campId]['tipo'] !== 'desconto') continue;
        $agQtd[$campId]   = ($agQtd[$campId] ?? 0) + (int)($it['qtd'] ?? 0);
        $agValor[$campId] = ($agValor[$campId] ?? 0) + (float)($it['valor_total'] ?? 0);
        $itensPorCampanha[$campId][] = $it;
    }

    // 2) Para cada campanha com item presente, acha a faixa atingida e o % esperado.
    $percEsperado = []; $campanhasAtingidas = [];
    foreach ($itensPorCampanha as $campId => $itensC) {
        $c = $porId[$campId];
        $agregado = $c['criterio'] === 'valor' ? ($agValor[$campId] ?? 0) : ($agQtd[$campId] ?? 0);
        $faixa = campanhasAmFaixaPara($c['faixas'], $agregado);
        $percEsperado[$campId] = $faixa ? (float)$faixa['percentual'] : 0.0;
        $campanhasAtingidas[] = [
            'campanha_id' => $campId, 'nome' => $c['nome'], 'unidade' => $c['unidade'], 'criterio' => $c['criterio'],
            'agregado' => $agregado, 'percentual_esperado' => $percEsperado[$campId], 'faixa' => $faixa, 'itens' => $itensC,
        ];
    }

    // 3) Sinaliza itens com %Diretoria acima do esperado (sem campanha = esperado 0).
    $itensForaCampanha = [];
    foreach ($itens as $it) {
        $diretoria = (float)($it['pct_diretoria'] ?? 0);
        if ($diretoria <= 0.005) continue;
        $campId = $mapaCampanha[$it['codigo'] ?? ''] ?? null;
        if ($campId === null) {
            $itensForaCampanha[] = $it + ['motivo' => 'sem_campanha', 'percentual_esperado' => 0.0, 'campanha_nome' => null];
            continue;
        }
        $c = $porId[$campId];
        if ($c['tipo'] !== 'desconto') continue;
        $pctEsp = $percEsperado[$campId] ?? 0.0;
        if ($diretoria > $pctEsp + 0.01) {
            $itensForaCampanha[] = $it + ['motivo' => 'acima_da_faixa', 'percentual_esperado' => $pctEsp, 'campanha_nome' => $c['nome']];
        }
    }

    // 4) Bonificação (ex.: Camp 6): a cada N un. do produto gatilho, confere a qtd do produto de bonificação no mesmo pedido.
    $qtdPorCodigo = [];
    foreach ($itens as $it) $qtdPorCodigo[$it['codigo'] ?? ''] = ($qtdPorCodigo[$it['codigo'] ?? ''] ?? 0) + (int)($it['qtd'] ?? 0);
    $bonificacoes = [];
    foreach ($campanhas as $c) {
        if ($c['tipo'] !== 'bonificacao' || empty($c['ativo'])) continue;
        $qtdTrigger = 0;
        foreach ($c['produtos'] as $p) $qtdTrigger += $qtdPorCodigo[$p['codigo_produto']] ?? 0;
        if ($qtdTrigger <= 0) continue;
        foreach ($c['bonificacao'] as $b) {
            $base = max(1, (int)$b['qtd_base']);
            $mult = intdiv($qtdTrigger, $base);
            if ($mult <= 0) continue;
            $esperado   = $mult * (int)$b['qtd_bonus'];
            $encontrado = $qtdPorCodigo[$b['produto_bonus_codigo']] ?? 0;
            $bonificacoes[] = [
                'campanha_id' => $c['id'], 'nome' => $c['nome'], 'qtd_trigger' => $qtdTrigger, 'mult' => $mult,
                'produto_bonus_codigo' => $b['produto_bonus_codigo'], 'produto_bonus_nome' => $b['produto_bonus_nome'],
                'qtd_bonus_esperada' => $esperado, 'qtd_bonus_encontrada' => $encontrado, 'ok' => $encontrado >= $esperado,
            ];
        }
    }
    $bonifOk = true;
    foreach ($bonificacoes as $b) if (!$b['ok']) $bonifOk = false;

    return [
        'campanhas_atingidas'    => $campanhasAtingidas,
        'itens_fora_campanha'    => $itensForaCampanha,
        'tem_item_fora_campanha' => (bool)$itensForaCampanha,
        'bonificacoes'           => $bonificacoes,
        'check_campanha'         => !$itensForaCampanha && $bonifOk,
    ];
}

/**
 * Análise financeira (sob demanda, sem gravar nada) dos pedidos aguardando
 * liberação no sistema Itallian Hairtech (A&M). Reproduz o roteiro manual:
 *
 *   Vendas > Aprovação > "Pedidos Aguardando liberação" > Buscar Pedidos
 *     -> lê o bloco "Pedidos" (colunas Numero, Tipo, Data, Codigo, Cliente,
 *        Valor, Credito Utilizado, Saldo A Pagar, Simulador Network,
 *        Simulador Accademia, %Dscto Avista, Simulador Descto).
 *
 *   Para cada "Codigo" do grid: Cheques Recebidos > "Detalhe do Conta Corrente",
 *     campo "Codigo Distribuidor" = Codigo sem o 1o e o ultimo digito (o ultimo
 *     digito e a filial; a conta corrente/limite e no nivel do distribuidor).
 *     -> le "Limite de Credito" e a tabela de Atrasos (linhas 01-Network,
 *        04-Accademia e Total).
 *
 *   Para cada pedido (link "Numero" -> PD0303): lê o cabeçalho (Cidade/UF) e o
 *     grid de itens (%Descto, %Descto ST, %Negociação, %Diretoria, Qtd).
 *
 * Conferências (avaliação individual por cliente/Codigo):
 *   1) Atrasos no Conta Corrente -> linha Total da coluna "Atrasos" == 0.
 *   2) Limite de Crédito         -> [soma (Simulador Network + Simulador Descto) das
 *                                   linhas Tipo "V" que NÃO são À Vista]
 *                                   + ["Total Geral" do cliente — painel Pedidos + Cheques +
 *                                   Acordos + Cursos que a própria tela "Pedidos Aguardando
 *                                   liberação" (PD0506->PD050P) mostra quando filtrada pelo
 *                                   Codigo Distribuidor]
 *                                   deve caber em "Limite de Credito". Pedido com Forma
 *                                   "00 - ... A Vista" não consome limite (passa direto).
 *   3) ST por Estado             -> % Descto ST aplicado no pedido (maior entre os
 *                                   itens) NÃO pode ser maior que o da tabela
 *                                   stEstadosTabela() para a UF do pedido. Coluna
 *                                   "com Academia" quando o Cadastro de Distribuidores
 *                                   (CL200) marca PedidoAccademia = SIM para o Codigo;
 *                                   senão "sem Academia". null = UF fora da tabela.
 *   4) Campanhas                 -> gate pela coluna "Obs" do pedido no grid "Consulta/
 *                                   Reimprime" (PD0301): só roda a análise de campanha
 *                                   (campanhasAmAvaliarPedido()) quando Obs começa com "BF" — aí
 *                                   o %Diretoria de cada item não pode passar do % esperado pela
 *                                   faixa de quantidade/valor atingida na campanha (sem campanha
 *                                   = esperado 0); campanhas de bonificação (ex.: Camp 6)
 *                                   conferem se a qtd do produto de brinde bate com a qtd
 *                                   comprada do produto gatilho. SEM "BF" nenhuma campanha se
 *                                   aplica, então qualquer %Diretoria > 0 já é desconto não
 *                                   previsto — pedido não passa (absorveu o antigo check
 *                                   "Desconto Diretoria", removido por ficar redundante).
 * Um pedido fica "conforme" (marcado por padrão) quando passa nos 4 requisitos.
 *
 * @param string|null $dataInicio dd/mm/aaaa (padrao: 01/01 do ano corrente)
 * @param string|null $dataFim    dd/mm/aaaa (padrao: 31/12 do ano corrente)
 * @return array ['ok'=>bool,'erro'=>?string,'gerado_em'=>string,'periodo'=>[ini,fim],
 *                'linhas'=>[...grid...],'analises'=>[codigo=>[...dados+checks...]]]
 */
function analiseFinanceiraAEM(?string $dataInicio = null, ?string $dataFim = null): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'erro' => 'Extensão cURL indisponível no servidor.'];
    }

    $ano        = (int)date('Y');
    $dataInicio = $dataInicio ?: ('01/01/' . $ano);
    $dataFim    = $dataFim    ?: ('31/12/' . $ano);
    $datPix     = date('01/m/Y', strtotime('first day of next month'));

    $chamar = function (string $path, ?array $postFields) {
        $ch = curl_init(AEM_URL . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ];
        if ($postFields !== null) {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($postFields);
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        curl_close($ch);
        return $resp === false ? '' : $resp;
    };
    $txt = function ($h) {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string)$h), ENT_QUOTES, 'UTF-8')));
    };
    $num = function ($s) {
        $s = preg_replace('/[^\d.,-]/', '', (string)$s);
        return $s === '' ? 0.0 : (float)str_replace(['.', ','], ['', '.'], $s);
    };

    // 1) Login — a sessão do A&M trafega via o parâmetro LNKTRANSPORTE.
    $loginHtml = $chamar('/cgi-bin/ITF/ITF.EXE', [
        'SubMenu'           => 'FROTA',
        'TxtLgloginUsuario' => AEM_LOGIN,
        'PwdLgloginSenha'   => AEM_SENHA,
    ]);
    if (!preg_match('/LNKTRANSPORTE=([0-9A-Za-z]+)/', $loginHtml, $m)) {
        return ['ok' => false, 'erro' => 'Não foi possível autenticar no sistema A&M.'];
    }
    $token = $m[1];

    // 2) Grid "Pedidos Aguardando liberação" (Vendas > Aprovação, PD0506 -> PD050P).
    $chamar('/cgi-bin/ITF/LOGIN.EXE',  ['LNKTRANSPORTE' => $token, 'TxtTransac' => '0277']);
    $chamar('/cgi-bin/ITF/PD0506.EXE', ['LNKTRANSPORTE' => $token, 'HidMenu' => 'VDMENU.EXE', 'SubMenu' => 'FROTA']);
    $gridHtml = $chamar('/cgi-bin/ITF/PD050P.EXE', [
        'LNKTRANSPORTE' => $token,
        'TxtPedido'     => '', 'TxtCodDist' => '', 'TxtDistrib' => '', 'status' => '',
        'TxtDataInicio' => $dataInicio, 'TxtDataFim' => $dataFim, 'TxtDatPix' => $datPix,
    ]);
    if ($gridHtml === '') return ['ok' => false, 'erro' => 'Falha ao consultar o grid de pedidos no A&M.'];
    $gridHtml = @mb_convert_encoding($gridHtml, 'UTF-8', 'ISO-8859-1') ?: $gridHtml;

    // Só o bloco "Pedidos": linhas com o link javascript:monta(pedidoInterno,codigo).
    preg_match_all('/<TBODY>(.*?)<\/TBODY>/is', $gridHtml, $tbodies);
    $rowsHtml = '';
    foreach ($tbodies[1] as $chunk) {
        if (stripos($chunk, 'javascript:monta(') !== false) $rowsHtml .= $chunk;
    }
    preg_match_all('/<TR\b[^>]*>(.*?)<\/TR>/is', $rowsHtml, $trs);

    $cols = ['numero', 'acao', 'tipo', 'data', 'codigo', 'cliente', 'valor', 'credito_utilizado',
             'saldo_a_pagar', 'sim_network', 'sim_accademia', 'pct_dscto_avista', 'sim_descto', 'atraso_col', 'forma'];
    $linhas = [];
    foreach ($trs[1] as $trHtml) {
        preg_match_all('/<TD\b[^>]*>(.*?)<\/TD>/is', $trHtml, $tds);
        if (count($tds[1]) < 13) continue;
        $row = [];
        foreach ($tds[1] as $i => $cell) {
            $row[$cols[$i] ?? ('c' . $i)] = $txt($cell);
        }
        // nome completo do cliente vem no title="" da 6a célula (a visível é truncada).
        if (preg_match('/title="([^"]*)"/i', $tds[1][5] ?? '', $mt)) {
            $row['cliente_completo'] = $txt($mt[1]);
        } else {
            $row['cliente_completo'] = $row['cliente'] ?? '';
        }
        if (preg_match("/monta\('(\d+)','(\d+)'\)/", $trHtml, $mm)) $row['pedido_interno'] = $mm[1];
        $row['tipo'] = strtoupper($row['tipo'] ?? '');
        // Checagem 4: forma de pagamento "00 - A Vista" — no grid de "Aguardando liberação" a
        // célula vem com um traço antes do "00" (ex.: "- 00 - A Vista"), por isso o [\s-]* inicial.
        $row['forma'] = $row['forma'] ?? '';
        $row['is_a_vista'] = (bool)preg_match('/^[\s-]*00\s*-/', $row['forma'])
                             && stripos($row['forma'], 'vista') !== false;
        $linhas[] = $row;
    }

    $porCodigo = [];
    foreach ($linhas as $row) $porCodigo[$row['codigo']][] = $row;

    // Cadastro de Distribuidores (CL200) — coluna "PedidoAccademia" (SIM/NAO) por
    // Codigo. Página grande (>800 KB): baixa uma vez e monta o mapa first5 => SIM/NAO.
    // O último dígito do Codigo é a filial, então a chave é os 5 primeiros dígitos.
    $mapAccademia = [];
    $distribHtml = $chamar('/cgi-bin/ITF/CL200.EXE', [
        'LNKTRANSPORTE' => $token, 'HidMenu' => 'CLMENU.EXE', 'SubMenu' => 'FROTA',
    ]);
    $distribHtml = @mb_convert_encoding($distribHtml, 'UTF-8', 'ISO-8859-1') ?: $distribHtml;
    $posTab = strpos($distribHtml, 'id="tabela"');
    if ($posTab === false) $posTab = strpos($distribHtml, "id='tabela'");
    $posB = $posTab !== false ? strpos($distribHtml, '<TBODY>', $posTab) : false;
    $posE = $posB !== false ? strpos($distribHtml, '</TBODY>', $posB) : false;
    if ($posB !== false && $posE !== false) {
        preg_match_all('/<TR[^>]*>(.*?)<\/TR>/is', substr($distribHtml, $posB + 7, $posE - $posB - 7), $dRows);
        foreach ($dRows[1] as $rowHtml) {
            preg_match_all('/<TD[^>]*>(.*?)<\/TD>/is', $rowHtml, $dc);
            $cd = array_map($txt, $dc[1]);
            if (count($cd) < 18) continue;
            $key = substr($cd[1], 0, 5);
            if ($key !== '') $mapAccademia[$key] = (strtoupper($cd[17]) === 'SIM');
        }
    }

    // 3) "Detalhe do Conta Corrente" (Cheques Recebidos, CX130) por Codigo.
    $chamar('/cgi-bin/ITF/LOGIN.EXE', ['LNKTRANSPORTE' => $token, 'TxtTransac' => '0632']);
    $chamar('/cgi-bin/ITF/CX130.EXE', ['LNKTRANSPORTE' => $token, 'HidMenu' => 'CKMENU.EXE', 'SubMenu' => 'FROTA']);
    // "Consulta/Reimprime" (Vendas, PD030 -> PD0301) — usado por pedido pra ler a coluna "Obs"
    // do grid (gate do Check 5: só roda a análise de campanha quando Obs começa com "BF").
    $chamar('/cgi-bin/ITF/LOGIN.EXE', ['LNKTRANSPORTE' => $token, 'TxtTransac' => '0163']);
    $chamar('/cgi-bin/ITF/PD030.EXE', ['LNKTRANSPORTE' => $token, 'HidMenu' => 'VDMENU.EXE', 'SubMenu' => 'FROTA']);

    $stTab = stEstadosTabela();
    $campanhasAmPorId = array_column(campanhasAmListar(), null, 'id');
    $analises = [];
    foreach ($porCodigo as $codigo => $rows) {
        // "Codigo Distribuidor" = Codigo sem o 1o e o ultimo digito (ex.: 141801 -> 4180).
        $codDist = strlen((string)$codigo) > 2 ? substr((string)$codigo, 1, -1) : (string)$codigo;

        // "Total Geral" do cliente (Pedidos + Cheques + Acordos + Cursos) — painel que a própria
        // tela "Pedidos Aguardando liberação" (PD0506->PD050P) mostra quando filtrada pelo Codigo
        // Distribuidor (mesma tela/sessão já aberta acima; só refaz o POST com TxtCodDist).
        $totGeralHtml = $chamar('/cgi-bin/ITF/PD050P.EXE', [
            'LNKTRANSPORTE' => $token,
            'TxtPedido' => '', 'TxtCodDist' => $codDist, 'TxtDistrib' => '', 'status' => '',
            'TxtDataInicio' => $dataInicio, 'TxtDataFim' => $dataFim, 'TxtDatPix' => $datPix,
        ]);
        $totGeralHtml = @mb_convert_encoding($totGeralHtml, 'UTF-8', 'ISO-8859-1') ?: $totGeralHtml;
        $totalGeral = 0.0;
        if (preg_match('/id="?total"?[^>]*>\s*Total Geral.*?<b>\s*R?\$?\s*([\d.,]+)\s*<\/b>/is', $totGeralHtml, $mtg)) {
            $totalGeral = $num($mtg[1]);
        }
        // "Supervisor" e "Segmento" do cliente — só aparecem no painel dessa mesma tela filtrada
        // (não vêm no Detalhe do Conta Corrente abaixo).
        preg_match('/Supervisor:\s*<b>(.*?)<\/b>/is', $totGeralHtml, $msup);
        preg_match('/Segmento\.?:\s*<b>(.*?)<\/b>/is', $totGeralHtml, $mseg);
        $supervisor = $txt($msup[1] ?? '');
        $segmento   = $txt($mseg[1] ?? '');

        $cc = $chamar('/cgi-bin/ITF/CX130.EXE', [
            'LNKTRANSPORTE' => $token,
            'SubCampo' => '', 'SubOpcao' => 'detalhe', 'SubKey' => '', 'SubKeyTitulos' => '',
            'TxtCodDist' => $codDist, 'TxtDistrib' => '', 'status' => '',
            'TxtDataInicio' => '01/01/2020', 'TxtDataFim' => $dataFim, 'ChkZerado' => 'on',
        ]);
        $cc = @mb_convert_encoding($cc, 'UTF-8', 'ISO-8859-1') ?: $cc;

        preg_match('/id=TxtDistrib[^>]*value="([^"]*)"/i', $cc, $md);
        preg_match('/Canal Venda:\s*<b>(.*?)<\/b>/is', $cc, $mcv);
        preg_match('/Limite de Credito:\s*<br>\s*<b>(.*?)<\/b>/is', $cc, $ml);
        $limiteTxt  = $txt($ml[1] ?? '');
        $limiteNum  = $num($limiteTxt);
        $distribuidorCc = $txt($md[1] ?? '');

        // Grid "Instrução"/"Histórico de Instruções" (Vendas > ..., VD0302) — mesmo link
        // "Consultar Instruções" da tela "Pedidos Aguardando liberação"; acessível direto na
        // sessão CX130 já aberta acima, sem handshake de LOGIN.EXE extra.
        $instrucoes = [];
        if ($distribuidorCc !== '') {
            $vdHtml = $chamar('/cgi-bin/ITF/VD0302.EXE', [
                'LNKTRANSPORTE' => $token, 'SidCodigo' => $distribuidorCc,
            ]);
            $vdHtml = @mb_convert_encoding($vdHtml, 'UTF-8', 'ISO-8859-1') ?: $vdHtml;
            if (preg_match('/id="historico"[^>]*>.*?<TABLE[^>]*>(.*?)<\/TABLE>/is', $vdHtml, $mhist)) {
                preg_match_all('/<TR[^>]*onClick="hi\(this\)"[^>]*>(.*?)<\/TR>/is', $mhist[1], $hrows);
                foreach ($hrows[1] as $hrow) {
                    preg_match_all('/<TD[^>]*>(.*?)<\/TD>/is', $hrow, $hc);
                    $hcell = array_map($txt, $hc[1]);
                    if (count($hcell) < 3) continue;
                    $instrucoes[] = ['data' => $hcell[0], 'texto' => $hcell[1], 'usuario' => $hcell[2]];
                }
            }
        }

        $atrasos = ['network' => null, 'accademia' => null, 'total' => 0.0];
        $boletosRows = [];
        if (preg_match('/<div id=boletos>(.*?)<\/div>/is', $cc, $mb)) {
            preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $mb[1], $btr);
            foreach ($btr[1] as $rowHtml) {
                preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $rowHtml, $bc);
                $cells = array_map($txt, $bc[1]);
                if (!$cells || $cells[0] === '' || stripos($cells[0], 'empresa') !== false) continue;
                $boletosRows[] = $cells;
                $emp = strtolower($cells[0]);
                if (strpos($emp, 'network') !== false)  $atrasos['network']   = $num($cells[2] ?? 0);
                if (strpos($emp, 'accademia') !== false) $atrasos['accademia'] = $num($cells[2] ?? 0);
                if (strpos($emp, 'total') !== false)     $atrasos['total']     = $num($cells[2] ?? 0);
            }
        }

        $somaNetworkV = 0.0; $somaDesctoV = 0.0; $somaVNaoAvista = 0.0; $tipos = [];
        foreach ($rows as $r) {
            $tipos[] = $r['tipo'];
            if ($r['tipo'] === 'V') {
                $sn = $num($r['sim_network']);
                $sd = $num($r['sim_descto']);
                $somaNetworkV += $sn;
                $somaDesctoV  += $sd;
                // Check 2: pedido À Vista não consome limite de crédito.
                if (empty($r['is_a_vista'])) $somaVNaoAvista += $sn + $sd;
            }
        }
        $somaV = $somaNetworkV + $somaDesctoV;

        // 1) Atrasos no Conta Corrente.
        $checkSemAtraso = ($atrasos['total'] <= 0.005);
        // 2) Limite de Crédito — (pedidos V aguardando que NÃO são À Vista) + (Total Geral do
        //    cliente: Pedidos + Cheques + Acordos + Cursos) deve caber no limite.
        $check2Base        = $somaVNaoAvista + $totalGeral;
        $semFinanciar      = ($check2Base <= 0.005);
        $checkDentroLimite = $semFinanciar ? true : ($limiteNum > 0 && $check2Base <= $limiteNum + 0.005);

        // "Com Academia" vem do Cadastro de Distribuidores (coluna PedidoAccademia SIM/NAO),
        // no nível do Codigo (5 primeiros dígitos). null = Codigo não achado no cadastro.
        $accademiaFlag = $mapAccademia[substr((string)$codigo, 0, 5)] ?? null;
        $comAcademia   = ($accademiaFlag === true);
        $stCol         = $comAcademia ? 'com_academia' : 'sem_academia';

        // 3) ST por Estado e 4) Campanhas — detalhe de cada pedido (PD0303).
        $qtdAVista = 0; $qtdStOk = 0; $qtdStAval = 0;
        $temItemDesctoST = false; $temItemForaCampanha = false;
        $resumoCampanhas = []; // campanha_id => ['nome','criterio','unidade','agregado'] — soma entre os pedidos do código
        foreach ($rows as $i => $r) {
            $rows[$i]['com_academia']       = $comAcademia;
            $rows[$i]['accademia_cadastro'] = $accademiaFlag === null ? null : ($accademiaFlag ? 'SIM' : 'NAO');

            $det = $chamar('/cgi-bin/ITF/PD0303.EXE', [
                'LNKTRANSPORTE' => $token, 'SidPed' => $r['pedido_interno'] ?? '',
            ]);
            $det = @mb_convert_encoding($det, 'UTF-8', 'ISO-8859-1') ?: $det;

            $uf = ''; $cidade = '';
            if (preg_match('/CEP\/Cidade\/UF:\s*<\/FONT>\s*<\/TD>\s*<TD[^>]*>\s*<FONT[^>]*>\s*<b>\s*(\d*)\s*(.*?)\s+([A-Z]{2})\s*<\/b>/is', $det, $mu)) {
                $cidade = $txt($mu[2]); $uf = strtoupper($mu[3]);
            }
            $rows[$i]['uf'] = $uf;
            $rows[$i]['cidade'] = $cidade;

            $itensTodos = []; $itensDiretoria = []; $itensDesctoST = []; $maxDesctoST = 0.0;
            if (preg_match('/id=["\']itens["\'].*?<TBODY>(.*?)<\/TBODY>/is', $det, $mBody)) {
                preg_match_all('/<TR[^>]*>(.*?)<\/TR>/is', $mBody[1], $itrs);
                foreach ($itrs[1] as $itrHtml) {
                    preg_match_all('/<TD[^>]*>(.*?)<\/TD>/is', $itrHtml, $itds);
                    $ic = array_map($txt, $itds[1]);
                    if (count($ic) < 13) continue;
                    $item = [
                        'codigo'         => $ic[1],
                        'nome'           => $ic[3],
                        'pct_descto'     => $num($ic[6]),
                        'pct_descto_st'  => $num($ic[7]),
                        'pct_negociacao' => $num($ic[9]),
                        'pct_diretoria'  => $num($ic[11]),
                        'qtd'            => (int)preg_replace('/\D/', '', $ic[12]),
                        'valor_total'    => $num($ic[14] ?? 0),
                    ];
                    $itensTodos[] = $item;
                    if ($item['pct_descto_st'] > $maxDesctoST) $maxDesctoST = $item['pct_descto_st'];
                    if ($item['pct_diretoria'] > 0.005) $itensDiretoria[] = $item;
                    if ($item['pct_descto_st'] > 0.005) $itensDesctoST[]  = $item;
                }
            }
            $rows[$i]['itens_am']        = $itensTodos;
            $rows[$i]['itens_diretoria'] = $itensDiretoria;
            $rows[$i]['itens_descto_st'] = $itensDesctoST;
            $rows[$i]['tem_diretoria']   = (bool)$itensDiretoria;
            $rows[$i]['tem_descto_st']   = (bool)$itensDesctoST;
            if ($itensDesctoST)  $temItemDesctoST  = true;

            // Coluna "Obs" do grid "Consulta/Reimprime" (PD0301) para ESTE pedido — gate do
            // Check 5: só roda a análise de campanha quando os 2 primeiros caracteres são "BF".
            // Sem "BF", o pedido não é de campanha; o Check 5 vira informativo (mostra os mesmos
            // itens com %Diretoria do Check 4, sem comparar contra faixa nenhuma).
            $obsPedido = ''; $ehBf = false;
            $numeroBusca = preg_replace('/\D/', '', $r['numero'] ?? '');
            if ($numeroBusca !== '') {
                $obsHtml = $chamar('/cgi-bin/ITF/PD0301.EXE', [
                    'LNKTRANSPORTE' => $token, 'SubOpcao' => '', 'SubForm' => '',
                    'TxtPedCliente' => $numeroBusca, 'TxtNumero' => '',
                    'TxtDiaInicio' => '01', 'TxtMesInicio' => '01', 'TxtAnoInicio' => '2000',
                    'TxtDiaFim' => date('d'), 'TxtMesFim' => date('m'), 'TxtAnoFim' => date('Y'),
                    'SelVendedor' => '', 'TxtCodDist' => '', 'TxtDistrib' => '', 'status' => '',
                    'TxtProduto' => '',
                ]);
                $obsHtml = @mb_convert_encoding($obsHtml, 'UTF-8', 'ISO-8859-1') ?: $obsHtml;
                if (preg_match('/<TBODY>(.*?)<\/TBODY>/is', $obsHtml, $mob)) {
                    preg_match_all('/<TR[^>]*>(.*?)<\/TR>/is', $mob[1], $orows);
                    $achou = null;
                    foreach ($orows[1] as $orow) {
                        preg_match_all('/<TD[^>]*>(.*?)<\/TD>/is', $orow, $oc);
                        $ocell = array_map($txt, $oc[1]);
                        if (count($ocell) < 13) continue;
                        // Confere pelo SidPed (link "Pedido Interno") quando houver mais de um
                        // resultado pro mesmo número; senão fica com a primeira linha achada.
                        if (!empty($r['pedido_interno']) && strpos($orow, 'SidPed=' . $r['pedido_interno']) === false) {
                            if ($achou === null) $achou = $ocell;
                            continue;
                        }
                        $achou = $ocell;
                        break;
                    }
                    // "Obs" é a célula logo após a "Forma Pagto" (ex.: "00 - A Vista", "71B -
                    // 30/60/90DD") — a posição fixa a partir do fim varia conforme "Situação"/
                    // "Altera"/"MA" renderizam ou não célula própria em cada linha.
                    if ($achou !== null) {
                        foreach ($achou as $ix => $cel) {
                            if (preg_match('/^\d+[A-Za-z]?\s*-\s*\S/', $cel)) { $obsPedido = $achou[$ix + 1] ?? ''; break; }
                        }
                    }
                }
                $ehBf = (strtoupper(substr(trim($obsPedido), 0, 2)) === 'BF');
            }
            $rows[$i]['obs']    = $obsPedido;
            $rows[$i]['eh_bf']  = $ehBf;

            // Check 5: itens do pedido x campanhas "Beauty" cadastradas (campanhas_am) — só
            // quando o pedido é marcado "BF" no Obs. Sem "BF" nenhuma campanha se aplica, então
            // qualquer %Diretoria > 0 é desconto não previsto — sinalizado em vermelho igual ao
            // check 4 (não fica "certo"), não fica neutro/informativo.
            if ($ehBf) {
                $avCampanha = campanhasAmAvaliarPedido($itensTodos);
            } else {
                $itensSemBf = array_map(fn($it) => $it + ['motivo' => 'sem_bf', 'percentual_esperado' => 0.0, 'campanha_nome' => null], $itensDiretoria);
                $avCampanha = [
                    'campanhas_atingidas'    => [],
                    'itens_fora_campanha'    => $itensSemBf,
                    'tem_item_fora_campanha' => (bool)$itensSemBf,
                    'bonificacoes'           => [],
                    'check_campanha'         => !$itensSemBf,
                ];
            }
            $rows[$i]['campanhas_atingidas']    = $avCampanha['campanhas_atingidas'];
            $rows[$i]['itens_fora_campanha']    = $avCampanha['itens_fora_campanha'];
            $rows[$i]['tem_item_fora_campanha'] = $avCampanha['tem_item_fora_campanha'];
            $rows[$i]['bonificacoes_campanha']  = $avCampanha['bonificacoes'];
            $rows[$i]['check_campanha']         = $avCampanha['check_campanha'];
            // Conta como "problema" tanto item com %Diretoria fora da faixa quanto bonificação
            // (ex.: Camp 6) que não bateu — os dois fazem o check 5 falhar.
            if (!$avCampanha['check_campanha']) $temItemForaCampanha = true;
            foreach ($avCampanha['campanhas_atingidas'] as $ca) {
                $cid = $ca['campanha_id'];
                if (!isset($resumoCampanhas[$cid])) {
                    $resumoCampanhas[$cid] = ['nome' => $ca['nome'], 'criterio' => $ca['criterio'], 'unidade' => $ca['unidade'], 'agregado' => 0.0];
                }
                $resumoCampanhas[$cid]['agregado'] += $ca['agregado'];
            }

            $stEsperado = ($uf && isset($stTab[$uf])) ? $stTab[$uf][$stCol] : null;
            $rows[$i]['st_coluna']       = $comAcademia ? 'com' : 'sem';
            $rows[$i]['st_esperado']     = $stEsperado;
            $rows[$i]['st_estado_nome']  = ($uf && isset($stTab[$uf])) ? $stTab[$uf]['nome'] : '';
            $rows[$i]['descto_st_pedido'] = $maxDesctoST;
            // Check 3: % Descto ST aplicado no pedido não pode ser MAIOR que o da tabela do estado.
            $checkStPedido = ($stEsperado === null) ? null : ($maxDesctoST <= $stEsperado + 0.01);
            $rows[$i]['check_st'] = $checkStPedido;
            if ($checkStPedido !== null) { $qtdStAval++; if ($checkStPedido) $qtdStOk++; }

            if (!empty($r['is_a_vista'])) $qtdAVista++;
            // Check 2 por pedido: À Vista sempre passa; senão depende do limite do Codigo.
            $check2Pedido = !empty($r['is_a_vista']) ? true : $checkDentroLimite;
            $rows[$i]['check_limite'] = $check2Pedido;
            // "conforme" (marcado por padrão) = atende os 4 requisitos.
            $rows[$i]['conforme'] = ($checkSemAtraso && $check2Pedido && $checkStPedido !== false && $avCampanha['check_campanha']);
        }

        // ST no nível do Codigo: ok se todos os pedidos avaliáveis passaram.
        $checkStCodigo  = ($qtdStAval === 0) ? true : ($qtdStOk === $qtdStAval);
        $aprovadoCodigo = ($checkSemAtraso && $checkDentroLimite && $checkStCodigo && !$temItemForaCampanha);

        // Faixa/benefício de cada campanha no resumo, recalculada sobre o total somado entre os
        // pedidos do código (não a de um pedido isolado — a soma pode cruzar pra uma faixa maior).
        foreach ($resumoCampanhas as $cid => &$rc) {
            $faixas = $campanhasAmPorId[$cid]['faixas'] ?? [];
            $faixa = campanhasAmFaixaPara($faixas, $rc['agregado']);
            $rc['percentual']  = $faixa ? (float)$faixa['percentual'] : 0.0;
            $rc['faixa_min']   = $faixa['minimo'] ?? null;
            $rc['faixa_max']   = $faixa['maximo'] ?? null;
        }
        unset($rc);

        $analises[(string)$codigo] = [
            'codigo'              => (string)$codigo,
            'codigo_distribuidor' => $codDist,
            'distribuidor_cc'     => $distribuidorCc,
            'canal_venda'         => $txt($mcv[1] ?? ''),
            'supervisor'          => $supervisor,
            'segmento'            => $segmento,
            'instrucoes'          => $instrucoes,
            'cliente'             => $rows[0]['cliente_completo'] ?? '',
            'com_academia'        => $comAcademia,
            'accademia_cadastro'  => $accademiaFlag === null ? null : ($accademiaFlag ? 'SIM' : 'NAO'),
            'linhas'              => $rows,
            'tipos'               => $tipos,
            'limite_txt'          => $limiteTxt,
            'limite_num'          => $limiteNum,
            'soma_network_v'      => $somaNetworkV,
            'soma_descto_v'       => $somaDesctoV,
            'soma_v'              => $somaV,
            'soma_v_nao_avista'   => $somaVNaoAvista,
            'total_geral_cliente' => $totalGeral,
            'check2_base'         => $check2Base,
            'sem_financiar'       => $semFinanciar,
            'atrasos'             => $atrasos,
            'boletos_rows'        => $boletosRows,
            'check_sem_atraso'    => $checkSemAtraso,
            'check_dentro_limite' => $checkDentroLimite,
            'check_st_codigo'     => $checkStCodigo,
            'pedidos_total'       => count($rows),
            'pedidos_a_vista'     => $qtdAVista,
            'pedidos_st_ok'       => $qtdStOk,
            'pedidos_st_avaliados'=> $qtdStAval,
            'tem_item_descto_st'  => $temItemDesctoST,
            'tem_item_fora_campanha' => $temItemForaCampanha,
            'campanhas_resumo'    => array_values($resumoCampanhas),
            'aprovado'            => $aprovadoCodigo,
        ];
    }

    return [
        'ok'        => true,
        'erro'      => null,
        'gerado_em' => date('d/m/Y H:i'),
        'periodo'   => [$dataInicio, $dataFim],
        'linhas'    => $linhas,
        'analises'  => $analises,
    ];
}

/**
 * Libera UM pedido no sistema A&M (Vendas > Pedidos Aguardando liberação, botão "Liberar
 * Pedido" da coluna Ação — reproduz o mesmo endpoint/parâmetros do clique manual no A&M):
 *  1) Login (ITF.EXE) -> token curto.
 *  2) Abre a tela "Pedidos Aguardando liberação" (PD0506 -> PD050P) — necessário pra capturar,
 *     do próprio HTML renderizado, o LNKTRANSPORTE "longo" que o A&M embute no JS da tela
 *     (diferente do token curto usado em links de navegação — os endpoints de ação exigem esse
 *     valor tal como veio) e o campo AreaPedido (textarea somente-leitura com a lista de SidPed
 *     do grid, que a tela também envia nesse POST).
 *  3) POST em PD0509.EXE {LNKTRANSPORTE=<longo>, SubKey=$sidPed, SubOpcao='individual',
 *     AreaPedido, AreaTexto=''} — mesma chamada da função JS `libera(ky,'individual',obj)`.
 * Sempre refaz login + reabre a tela antes de liberar (não usa cache), pra garantir um
 * LNKTRANSPORTE válido no momento da chamada, e confere que o SidPed está mesmo no grid atual
 * antes de enviar (evita liberar em cima de uma tela desatualizada).
 *
 * IMPORTANTE: essa função grava de verdade no A&M (ação real, não é simulação). O formato exato
 * da resposta de sucesso/erro do PD0509.EXE não foi validado em produção — 'ok'=>true aqui
 * significa só que a chamada foi enviada e respondeu algo; 'resposta' guarda o corpo cru
 * devolvido pelo A&M para conferência manual (fica gravado no log de liberações).
 *
 * @return array ['ok'=>bool,'erro'=>?string,'resposta'=>?string]
 */
function liberarPedidoAEM(string $sidPed): array {
    $sidPed = trim($sidPed);
    if ($sidPed === '') return ['ok' => false, 'erro' => 'SidPed vazio.', 'resposta' => null];
    if (!function_exists('curl_init')) return ['ok' => false, 'erro' => 'Extensão cURL indisponível no servidor.', 'resposta' => null];

    $chamar = function (string $path, ?array $postFields) {
        $ch = curl_init(AEM_URL . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ];
        if ($postFields !== null) {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($postFields);
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        curl_close($ch);
        return $resp === false ? '' : $resp;
    };

    $loginHtml = $chamar('/cgi-bin/ITF/ITF.EXE', [
        'SubMenu' => 'FROTA', 'TxtLgloginUsuario' => AEM_LOGIN, 'PwdLgloginSenha' => AEM_SENHA,
    ]);
    if (!preg_match('/LNKTRANSPORTE=([0-9A-Za-z]+)/', $loginHtml, $m)) {
        return ['ok' => false, 'erro' => 'Não foi possível autenticar no sistema A&M.', 'resposta' => null];
    }
    $token = $m[1];

    $chamar('/cgi-bin/ITF/LOGIN.EXE',  ['LNKTRANSPORTE' => $token, 'TxtTransac' => '0277']);
    $chamar('/cgi-bin/ITF/PD0506.EXE', ['LNKTRANSPORTE' => $token, 'HidMenu' => 'VDMENU.EXE', 'SubMenu' => 'FROTA']);
    $gridHtml = $chamar('/cgi-bin/ITF/PD050P.EXE', [
        'LNKTRANSPORTE' => $token,
        'TxtPedido'     => '', 'TxtCodDist' => '', 'TxtDistrib' => '', 'status' => '',
        'TxtDataInicio' => date('01/01/Y'), 'TxtDataFim' => date('31/12/Y'),
        'TxtDatPix'     => date('01/m/Y', strtotime('first day of next month')),
    ]);
    if ($gridHtml === '') return ['ok' => false, 'erro' => 'Falha ao abrir a tela de pedidos aguardando liberação no A&M.', 'resposta' => null];
    $gridHtml = @mb_convert_encoding($gridHtml, 'UTF-8', 'ISO-8859-1') ?: $gridHtml;

    // LNKTRANSPORTE "longo" embutido no JS da própria tela (diferente do token curto do login) —
    // os endpoints de ação (PD0509.EXE) exigem exatamente esse valor.
    if (!preg_match('/function\s+libera\(ky,opc,obj\)\s*\{\s*\$\.post\("\/cgi-bin\/ITF\/PD0509\.EXE",\{\s*LNKTRANSPORTE:\s*"([^"]*)"/s', $gridHtml, $mlt)) {
        return ['ok' => false, 'erro' => 'Não foi possível localizar o token de sessão da tela de liberação no A&M (layout da tela pode ter mudado).', 'resposta' => null];
    }
    $longToken = $mlt[1];

    $areaPedido = '';
    if (preg_match('/id=AreaPedido[^>]*>([^<]*)</i', $gridHtml, $map)) $areaPedido = html_entity_decode($map[1], ENT_QUOTES, 'UTF-8');

    // Confere que o SidPed realmente está no grid atual antes de liberar.
    if (strpos($gridHtml, "libera('" . $sidPed . "'") === false) {
        return ['ok' => false, 'erro' => 'Pedido não encontrado na tela de "Aguardando liberação" do A&M no momento (já liberado, cancelado, ou fora do período padrão da tela).', 'resposta' => null];
    }

    $resp = $chamar('/cgi-bin/ITF/PD0509.EXE', [
        'LNKTRANSPORTE' => $longToken, 'SubKey' => $sidPed, 'SubOpcao' => 'individual',
        'AreaPedido' => $areaPedido, 'AreaTexto' => '',
    ]);
    if ($resp === '') return ['ok' => false, 'erro' => 'Sem resposta do A&M ao liberar o pedido.', 'resposta' => null];
    $resp = @mb_convert_encoding($resp, 'UTF-8', 'ISO-8859-1') ?: $resp;

    return ['ok' => true, 'erro' => null, 'resposta' => $resp];
}

/**
 * Grava o % Diretoria de itens direto no pedido do sistema A&M, reproduzindo o
 * caminho manual: Vendas > "Consulta/Reimprime" (flag "Exibir os Pedidos Aguardando
 * no Comercial") > "Altera" na linha do pedido > aba "Itens" (edita a coluna
 * "% Diretoria" de cada item) > aba "Validar" > "Conclui Pedido".
 *
 * Endpoints revertidos da tela (2026-09):
 *   ITF.EXE (login) -> LOGIN.EXE {TxtTransac=0163} -> PD030.EXE (abre a tela).
 *   PD0301.EXE {TxtPedCliente=<numero>, RdbSel=V, Chk01=on} -> grid; a linha traz o
 *     SidPed (link PD0303), o "Pedido Cliente" (PED nnn) e o link javascript:altera(SidPed).
 *   altera(): POST CT0051.EXE {SidPed, + campos do form PD0301} -> abre o editor (form VD350).
 *   aba Itens:  POST VD350.EXE {SubOpcao=SubTela=itens, SubKeyPedcads=SidPed, SubPedido=PED,...}
 *     -> div#itens; cada célula editável tem onClick="alteravalor('<SidPed><seq3>','<cpo>',this)"
 *        (cpo 01=Quantidade, 05=%Negociação, 06=%Diretoria). O HTML traz o LNKTRANSPORTE "longo".
 *   editar campo: POST VD35012.EXE {LNKTRANSPORTE=<longo>, TxtValorAlt=<valor BR>, SubKey=<SidPed><seq3>, SubCampo='06'}.
 *   aba Validar: POST VD350.EXE {SubOpcao=SubTela=validar,...} -> "Pedido NNN Validada com Sucesso" | erros.
 *   Conclui Pedido: onClick="impressao('<SidPed>','02')" -> POST PD0306.EXE {+ campos VD350, HidKey=SidPed, SubLogo='02'}.
 *
 * IMPORTANTE: grava de verdade no A&M. `$concluir=false` para antes do "Conclui Pedido"
 * (as edições de item já ficam salvas; só não re-submete o pedido para aprovação).
 *
 * @param string $numero       "Número do Pedido do Cliente" (o mesmo do Importa Pedido).
 * @param array  $pctPorCodigo [codigoAEM => %Diretoria]; item não citado recebe 0.
 * @param bool   $concluir     se true, executa "Conclui Pedido" ao final (quando a validação passa).
 * @return array ['ok'=>bool,'erro'=>?string,'status'=>'gravado'|'parcial'|'erro','sid_ped'=>?string,
 *                'pedido_interno'=>?string,'pedido_cliente'=>?string,'itens'=>[...],'validacao'=>?string,
 *                'concluido'=>bool,'resposta'=>string]
 */
function aplicarDescontoDiretoriaAEM(string $numero, array $pctPorCodigo, bool $concluir = true): array {
    $numero = preg_replace('/\D/', '', $numero);
    $falha = fn($msg, $extra = []) => array_merge(['ok' => false, 'erro' => $msg, 'status' => 'erro', 'sid_ped' => null,
        'pedido_interno' => null, 'pedido_cliente' => null, 'itens' => [], 'validacao' => null, 'concluido' => false, 'resposta' => ''], $extra);
    if ($numero === '') return $falha('Número de pedido inválido.');
    if (!function_exists('curl_init')) return $falha('Extensão cURL indisponível no servidor.');

    $log = '';
    $chamar = function (string $path, ?array $post) use (&$log) {
        $ch = curl_init(AEM_URL . $path);
        $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'Mozilla/5.0'];
        if ($post !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = http_build_query($post); }
        curl_setopt_array($ch, $o);
        $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $r = $r === false ? '' : (@mb_convert_encoding($r, 'UTF-8', 'ISO-8859-1') ?: $r);
        $log .= "\n### $path [$code] " . strlen($r) . "b\n";
        return $r;
    };

    // 1) Login curto.
    $loginHtml = $chamar('/cgi-bin/ITF/ITF.EXE', ['SubMenu' => 'FROTA', 'TxtLgloginUsuario' => AEM_LOGIN, 'PwdLgloginSenha' => AEM_SENHA]);
    if (!preg_match('/LNKTRANSPORTE=([0-9A-Za-z]+)/', $loginHtml, $m)) return $falha('Falha ao autenticar no A&M.', ['resposta' => $log]);
    $tok = $m[1];

    // 2) Abre Vendas > Consulta/Reimprime e busca o pedido com o flag "Aguardando no Comercial" (Chk01).
    $anoIni = (string)((int)date('Y') - 1);
    $chamar('/cgi-bin/ITF/LOGIN.EXE', ['LNKTRANSPORTE' => $tok, 'TxtTransac' => '0163']);
    $chamar('/cgi-bin/ITF/PD030.EXE', ['LNKTRANSPORTE' => $tok, 'HidMenu' => 'VDMENU.EXE', 'SubMenu' => 'FROTA']);
    $grid = $chamar('/cgi-bin/ITF/PD0301.EXE', [
        'LNKTRANSPORTE' => $tok, 'SubOpcao' => '', 'SubForm' => '',
        'TxtPedCliente' => $numero, 'TxtNumero' => '',
        'TxtDiaInicio' => '01', 'TxtMesInicio' => '01', 'TxtAnoInicio' => $anoIni,
        'TxtDiaFim' => date('d'), 'TxtMesFim' => date('m'), 'TxtAnoFim' => date('Y'),
        'SelVendedor' => '', 'TxtCodDist' => '', 'TxtDistrib' => '', 'status' => '',
        'TxtProduto' => '', 'RdbSel' => 'V', 'Chk01' => 'on',
    ]);
    if (!preg_match('/<TBODY>(.*?)<\/TBODY>/is', $grid, $mb)) return $falha('Não foi possível ler o grid Consulta/Reimprime do A&M.', ['resposta' => $log]);
    preg_match_all('/<TR\b[^>]*>(.*?)<\/TR>/is', $mb[1], $trs);
    $sidPed = ''; $pedInterno = ''; $pedCliente = ''; $subCliente = ''; $linhasCom = 0;
    foreach ($trs[1] as $tr) {
        if (!preg_match("/javascript:altera\('(\d+)'\)/", $tr, $ma)) continue;   // só linhas editáveis (Aguardando Comercial)
        $linhasCom++;
        $sidPed = $ma[1];
        $plain  = trim(preg_replace('/\s+/', ' ', strip_tags($tr)));
        if (preg_match('#>\s*([\d.]+)\s*<IMG#i', $tr, $mpi)) $pedInterno = preg_replace('/\D/', '', $mpi[1]);
        if (preg_match('/PED\s+(\d+)/i', $plain, $mpc)) $pedCliente = $mpc[1];
        if (preg_match('/fichacadastral\(\x27?(\d+)/', $tr, $mfc)) $subCliente = $mfc[1];
    }
    if ($linhasCom === 0) return $falha('Pedido não está entre os "Aguardando no Comercial" do A&M (já aprovado/cancelado ou fora do período).', ['resposta' => $log]);
    if ($linhasCom > 1) return $falha('Mais de um pedido "Aguardando no Comercial" com esse número no A&M — ajuste manualmente.', ['resposta' => $log]);
    if ($pedCliente === '') $pedCliente = $numero;

    // 3) "Altera" -> abre o editor (form VD350).
    $chamar('/cgi-bin/ITF/CT0051.EXE', [
        'LNKTRANSPORTE' => $tok, 'HidKey' => '', 'HidCliente' => '', 'SidPed' => $sidPed, 'SidCodigo' => '',
        'SubKey' => '', 'SubKey2' => '', 'SubKeyCliente' => '', 'SubTela' => '', 'SubOpcao' => '',
        'TxtDiaInicio' => '01', 'TxtMesInicio' => '01', 'TxtAnoInicio' => $anoIni,
        'TxtDiaFim' => date('d'), 'TxtMesFim' => date('m'), 'TxtAnoFim' => date('Y'),
        'TxtNumero' => '', 'TxtPedCliente' => $numero, 'TxtDistrib' => '', 'RdbSel' => 'V',
        'Chk01' => 'on', 'Chk02' => '', 'Chk03' => '', 'Chk04' => '',
        'TxtCliente' => '', 'TxtProduto' => '', 'SelVendedor' => '',
    ]);

    // 4) Aba "Itens".
    $vd350base = [
        'LNKTRANSPORTE' => $tok, 'SubCampo' => '', 'SubTelaAnterior' => '', 'SubCliente' => $subCliente,
        'SubKeyAlt' => '', 'SubKeyPedcads' => $sidPed, 'SubPedido' => $pedCliente, 'SubKeyProduto' => '',
        'SubKeyPeditem' => '', 'SubLogo' => '', 'HidKey' => '', 'SubTipo' => 'V', 'status' => '',
    ];
    $itensHtml = $chamar('/cgi-bin/ITF/VD350.EXE', $vd350base + ['SubOpcao' => 'itens', 'SubTela' => 'itens']);
    if (!preg_match('/id=LNKTRANSPORTE\s+name=LNKTRANSPORTE\s+size=800\s+type=hidden\s+value="([^"]*)"/i', $itensHtml, $mlt)) {
        return $falha('Não foi possível obter o token de sessão do editor de itens no A&M.', ['sid_ped' => $sidPed, 'pedido_interno' => $pedInterno, 'pedido_cliente' => $pedCliente, 'resposta' => $log]);
    }
    $longTok = $mlt[1];

    // Itens: div#itens; a célula de %Diretoria tem onClick="alteravalor('<SidPed><seq3>','06',this)".
    // Colunas: Seq,Código,Descrição,PreçoTabela,%Descto,%DesctoST,VrUnit,%Negociação,VrNegociado,%Diretoria,Qtd,...
    $itensGrid = [];
    $regiao = $itensHtml;
    if (preg_match('/id="itens".*/is', $itensHtml, $mreg)) $regiao = $mreg[0];
    preg_match_all('/<TR\b[^>]*onmouseover[^>]*>(.*?)<\/TR>/is', $regiao, $itr);
    foreach ($itr[1] as $rowH) {
        if (!preg_match("/alteravalor\('(\d+)','06'/", $rowH, $mk)) continue;
        preg_match_all('/<TD\b[^>]*>(.*?)<\/TD>/is', $rowH, $tds);
        $cells = array_map(fn($c) => trim(html_entity_decode(strip_tags($c), ENT_QUOTES, 'UTF-8')), $tds[1]);
        $seq = '';
        if (preg_match('/value="(\d+)"/', $tds[1][0] ?? '', $ms)) $seq = $ms[1];
        $diretAtual = 0.0;
        if (isset($cells[9]) && preg_match('/([\d,]+)/', $cells[9], $md)) $diretAtual = (float)str_replace(',', '.', $md[1]);
        $itensGrid[] = ['key' => $mk[1], 'seq' => $seq, 'codigo' => $cells[1] ?? '', 'diretoria_atual' => $diretAtual];
    }
    if (!$itensGrid) return $falha('Não foi possível ler os itens do pedido no editor do A&M.', ['sid_ped' => $sidPed, 'pedido_interno' => $pedInterno, 'pedido_cliente' => $pedCliente, 'resposta' => $log]);

    // 5) Edita o % Diretoria de cada item que precisa mudar.
    $detItens = []; $erros = []; $mudancas = 0;
    foreach ($itensGrid as $ig) {
        $alvo  = round((float)($pctPorCodigo[$ig['codigo']] ?? 0), 2);
        $mudou = abs($alvo - $ig['diretoria_atual']) > 0.01;
        $item  = ['codigo' => $ig['codigo'], 'seq' => $ig['seq'], 'de' => $ig['diretoria_atual'], 'para' => $alvo, 'aplicado' => !$mudou, 'resposta' => $mudou ? null : 'sem alteração'];
        if ($mudou) {
            $mudancas++;
            $r = $chamar('/cgi-bin/ITF/VD35012.EXE', [
                'LNKTRANSPORTE' => $longTok, 'TxtValorAlt' => number_format($alvo, 2, ',', ''),
                'SubKey' => $ig['key'], 'SubCampo' => '06', 'ChkAplica' => '', 'Selecionados' => '',
            ]);
            $rTxt   = trim(preg_replace('/\s+/', ' ', strip_tags($r)));
            $falhou = ($r === '') || (bool)preg_match('/erro|inv[aá]lid|permiss|negad|n[aã]o\s+pode|excede|ultrapass/i', $rTxt);
            $item['aplicado'] = !$falhou;
            $item['resposta'] = mb_substr($rTxt, 0, 300);
            if ($falhou) $erros[] = "Item {$ig['codigo']}: " . ($rTxt === '' ? 'sem resposta' : mb_substr($rTxt, 0, 120));
        }
        $detItens[] = $item;
    }

    // 6) Aba "Validar".
    $valHtml   = $chamar('/cgi-bin/ITF/VD350.EXE', $vd350base + ['SubOpcao' => 'validar', 'SubTela' => 'validar']);
    $validado  = (bool)preg_match('/Validad[ao]\s+com\s+Sucesso/i', $valHtml);
    $valResumo = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($valHtml))), 0, 500);

    // 7) "Conclui Pedido" (só se pediu, a validação passou e nenhuma edição falhou).
    $concluido = false; $concluiResp = '';
    if ($concluir && $validado && !$erros) {
        $concluiResp = $chamar('/cgi-bin/ITF/PD0306.EXE', $vd350base + [
            'SubOpcao' => 'validar', 'SubTela' => 'validar', 'HidKey' => $sidPed, 'SubLogo' => '02',
        ]);
        $concluido = ($concluiResp !== '') && !preg_match('/erro|inv[aá]lid|permiss/i', trim(strip_tags($concluiResp)));
    }

    $aplicadosOk = count(array_filter($detItens, fn($d) => $d['de'] != $d['para'] && $d['aplicado']));
    if (!$erros && $validado) $status = 'gravado';
    elseif ($aplicadosOk > 0)  $status = 'parcial';
    else                       $status = 'erro';

    return [
        'ok'             => ($status === 'gravado'),
        'erro'           => $erros ? implode(' | ', $erros) : ($validado ? null : 'Validação do pedido no A&M não passou: ' . $valResumo),
        'status'         => $status,
        'sid_ped'        => $sidPed,
        'pedido_interno' => $pedInterno,
        'pedido_cliente' => $pedCliente,
        'itens'          => $detItens,
        'validacao'      => $valResumo,
        'concluido'      => $concluido,
        'resposta'       => $log . "\n\n=== VALIDAR ===\n" . $valResumo . "\n\n=== CONCLUI ===\n" . mb_substr(trim(strip_tags($concluiResp)), 0, 3000),
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
 * Cotação para EXIBIÇÃO da conversão de um pedido em moeda estrangeira.
 * Usa a cotação gravada no pedido; se ausente (pedidos antigos criados quando a
 * API falhou), cai para a cotação do dia / última conhecida. Retorna
 * ['taxa'=>float, 'fallback'=>bool] ou null quando não se aplica
 * (moeda BRL ou nenhuma taxa disponível).
 */
function cotacaoExibicaoPedido($moeda, $cotacaoPedido): ?array {
    if (strtoupper((string)$moeda) === 'BRL') return null;
    $c = (float)$cotacaoPedido;
    if ($c > 0) return ['taxa' => $c, 'fallback' => false];
    $fb = (float)(cotacaoDia($moeda) ?? 0);
    return $fb > 0 ? ['taxa' => $fb, 'fallback' => true] : null;
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
    return '<span class="badge bg-' . $cls . '">' . e(t($label)) . '</span>';
}

/**
 * Carrega todas as campanhas agrupadas por código, já com suas condições (E).
 * @return array  codigo => ['rows'=>[...campanhas], 'conds'=>[...campanha_condicoes]]
 */
function campanhasAgrupadas(): array {
    // Só campanhas ativas participam da avaliação (ativo ausente = legado = ativo).
    try {
        $rows = db()->query("SELECT codigo_campanha, produto_id, linha, grupo, subgrupo, canal_venda_id,
                                    quantidade, desconto, tipo, valor_alvo, bonif_modo, bonif_limite_tipo,
                                    bonif_limite_valor, bonif_selec_modo
                             FROM campanhas WHERE COALESCE(ativo,1) = 1")->fetchAll();
    } catch (PDOException $e) {
        $rows = db()->query("SELECT codigo_campanha, produto_id, linha, grupo, subgrupo, canal_venda_id,
                                    quantidade, desconto, tipo, valor_alvo, bonif_modo, bonif_limite_tipo, bonif_limite_valor
                             FROM campanhas")->fetchAll();
    }
    $conds = [];
    try {
        $conds = db()->query("SELECT codigo_campanha, criterio_tipo, criterio_valor, quantidade, criterio_modo, valor_min,
                                     cond_linha, cond_grupo, cond_subgrupo, cond_produto_id
                              FROM campanha_condicoes")->fetchAll();
    } catch (PDOException $e) {
        try {
            $conds = db()->query("SELECT codigo_campanha, criterio_tipo, criterio_valor, quantidade, criterio_modo, valor_min
                                  FROM campanha_condicoes")->fetchAll();
        } catch (PDOException $e2) {
            try { $conds = db()->query("SELECT codigo_campanha, criterio_tipo, criterio_valor, quantidade FROM campanha_condicoes")->fetchAll(); }
            catch (PDOException $e3) { $conds = []; }
        }
    }

    $g = [];
    foreach ($rows as $r) $g[$r['codigo_campanha']]['rows'][] = $r;
    foreach ($g as $k => &$v) $v['conds'] = [];
    unset($v);
    foreach ($conds as $c) if (isset($g[$c['codigo_campanha']])) $g[$c['codigo_campanha']]['conds'][] = $c;
    return $g;
}

/**
 * Alvos onde o desconto da campanha incide. Usa os alvos explícitos de
 * campanha_desconto_alvo se houver; senão, devolve os grupos do gatilho.
 * @param array $gruposAlvo  fallback ['tipo','valor']
 * @return array  [['tipo','valor'], ...]  (formato consumido por itemBateGruposAlvo)
 */
function alvosDescontoCampanha(string $codigo, array $gruposAlvo): array {
    try {
        $st = db()->prepare("SELECT alvo_tipo, alvo_valor, alvo_linha, alvo_grupo, alvo_subgrupo, alvo_produto_id
                              FROM campanha_desconto_alvo WHERE codigo_campanha = ?");
        $st->execute([$codigo]);
        $alvos = array_map(fn($a) => alvoFiltro($a), $st->fetchAll());
        if ($alvos) return $alvos;
    } catch (PDOException $e) {
        try {
            $st = db()->prepare("SELECT alvo_tipo, alvo_valor FROM campanha_desconto_alvo WHERE codigo_campanha = ?");
            $st->execute([$codigo]);
            $alvos = array_map(fn($a) => ['tipo' => $a['alvo_tipo'], 'valor' => trim($a['alvo_valor'])], $st->fetchAll());
            if ($alvos) return $alvos;
        } catch (PDOException $e2) { /* tabela ainda não existe */ }
    }
    return $gruposAlvo;
}

/**
 * Constrói o contexto de avaliação de campanhas a partir dos itens de uma venda.
 * Cada item: ['produto_id','qtd','linha','grupo','subgrupo','preco'(unit, opcional)].
 */
function ctxCampanha(array $itensVenda, int $canalVendaId): array {
    $totL = $totG = $totS = $totProd = []; $valorTotal = 0.0;
    $valL = $valG = $valS = $valProd = [];
    $itens = [];
    foreach ($itensVenda as $it) {
        $q = (int)($it['qtd'] ?? 0); if ($q <= 0) continue;
        $preco = (float)($it['preco'] ?? 0);
        $val   = $q * $preco;
        $pid = (int)($it['produto_id'] ?? 0);
        $l = trim($it['linha'] ?? ''); $g = trim($it['grupo'] ?? ''); $s = trim($it['subgrupo'] ?? '');
        if ($pid) { $totProd[$pid] = ($totProd[$pid] ?? 0) + $q; $valProd[$pid] = ($valProd[$pid] ?? 0) + $val; }
        if ($l) { $totL[$l] = ($totL[$l] ?? 0) + $q; $valL[$l] = ($valL[$l] ?? 0) + $val; }
        if ($g) { $totG[$g] = ($totG[$g] ?? 0) + $q; $valG[$g] = ($valG[$g] ?? 0) + $val; }
        if ($s) { $totS[$s] = ($totS[$s] ?? 0) + $q; $valS[$s] = ($valS[$s] ?? 0) + $val; }
        $valorTotal += $val;
        // item normalizado para avaliar condições compostas (linha E grupo E ...)
        $itens[] = ['id' => $pid, 'qtd' => $q, 'linha' => $l, 'grupo' => $g, 'subgrupo' => $s, 'valor' => $val];
    }
    return [
        'totaisLinha'    => $totL,
        'totaisGrupo'    => $totG,
        'totaisSubgrupo' => $totS,
        'qtdPorProduto'  => $totProd,
        'valoresLinha'    => $valL,
        'valoresGrupo'    => $valG,
        'valoresSubgrupo' => $valS,
        'valorPorProduto' => $valProd,
        'valorTotal'     => $valorTotal,
        'itens'          => $itens,
        'canalVendaId'   => $canalVendaId,
    ];
}

/**
 * Avalia o gatilho de UMA campanha contra o contexto da venda.
 * Novo modelo (tem condições): TODAS as condições precisam ser atingidas (E).
 * Cada condição pode exigir uma quantidade mínima OU um valor mínimo (somado
 * dentro da própria categoria/produto da condição). Modelo legado (sem
 * condições): produtos somados / categoria, como historicamente.
 *
 * @return array ['acionada'=>bool, 'mult'=>int, 'gruposAlvo'=>[['tipo','valor'],...]]
 */
function avaliarCampanhaTrigger(array $rows, array $conds, array $ctx): array {
    $vazio = ['acionada' => false, 'mult' => 0, 'gruposAlvo' => []];
    if (!$rows) return $vazio;
    $canal = (int)($rows[0]['canal_venda_id'] ?? 0);
    if ($canal && $canal !== (int)($ctx['canalVendaId'] ?? 0)) return $vazio;

    // ---- Novo modelo: condições combinadas (E); cada condição é um filtro
    //      composto (linha E grupo E subgrupo E produto) com mínimo por qtd OU valor ----
    if ($conds) {
        $itens = $ctx['itens'] ?? [];
        $gruposAlvo = [];
        $allMet = true;
        $minMult = PHP_INT_MAX;
        foreach ($conds as $c) {
            $f = condFiltro($c);
            if (!filtroValido($f)) { $allMet = false; continue; }
            $gruposAlvo[] = $f;
            // Soma qtd/valor dos itens que satisfazem TODO o filtro da condição
            $totQ = 0; $totV = 0.0;
            foreach ($itens as $it) {
                if (!itemMatchFiltro($it, $f)) continue;
                $totQ += (int)$it['qtd']; $totV += (float)$it['valor'];
            }
            if (($c['criterio_modo'] ?? 'quantidade') === 'valor') {
                $alvoMin = (float)($c['valor_min'] ?? 0);
                if ($alvoMin > 0 && $totV >= $alvoMin) $minMult = min($minMult, (int)floor($totV / $alvoMin));
                else $allMet = false;
            } else {
                $q = (int)$c['quantidade'];
                if ($q > 0 && $totQ >= $q) $minMult = min($minMult, intdiv($totQ, $q));
                else $allMet = false;
            }
        }
        if ($allMet && $gruposAlvo && $minMult >= 1) {
            return ['acionada' => true, 'mult' => $minMult, 'gruposAlvo' => $gruposAlvo];
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

/**
 * Filtro composto de uma condição: ['linha','grupo','subgrupo','produto'] (cada um
 * opcional). Usa as colunas cond_* novas; cai para criterio_tipo/criterio_valor (legado).
 */
function condFiltro(array $c): array {
    $f = [
        'linha'    => isset($c['cond_linha'])    && trim($c['cond_linha'])    !== '' ? trim($c['cond_linha'])    : null,
        'grupo'    => isset($c['cond_grupo'])    && trim($c['cond_grupo'])    !== '' ? trim($c['cond_grupo'])    : null,
        'subgrupo' => isset($c['cond_subgrupo']) && trim($c['cond_subgrupo']) !== '' ? trim($c['cond_subgrupo']) : null,
        'produto'  => !empty($c['cond_produto_id']) ? (int)$c['cond_produto_id'] : null,
    ];
    if (!filtroValido($f)) {  // legado (sem colunas cond_*): usa criterio único
        $tipo = $c['criterio_tipo'] ?? ''; $val = trim($c['criterio_valor'] ?? '');
        if ($tipo === 'produto') $f['produto'] = (int)$val;
        elseif (in_array($tipo, ['linha', 'grupo', 'subgrupo'], true)) $f[$tipo] = $val;
    }
    return $f;
}

/**
 * Filtro composto de um alvo de desconto: ['linha','grupo','subgrupo','produto'] (cada um
 * opcional). Usa as colunas alvo_* novas; cai para alvo_tipo/alvo_valor (legado, critério único).
 */
function alvoFiltro(array $a): array {
    $f = [
        'linha'    => isset($a['alvo_linha'])    && trim($a['alvo_linha'])    !== '' ? trim($a['alvo_linha'])    : null,
        'grupo'    => isset($a['alvo_grupo'])    && trim($a['alvo_grupo'])    !== '' ? trim($a['alvo_grupo'])    : null,
        'subgrupo' => isset($a['alvo_subgrupo']) && trim($a['alvo_subgrupo']) !== '' ? trim($a['alvo_subgrupo']) : null,
        'produto'  => !empty($a['alvo_produto_id']) ? (int)$a['alvo_produto_id'] : null,
    ];
    if (!filtroValido($f)) {  // legado (sem colunas alvo_*): usa tipo/valor único
        $tipo = $a['alvo_tipo'] ?? ''; $val = trim($a['alvo_valor'] ?? '');
        if ($tipo === 'produto') $f['produto'] = (int)$val;
        elseif (in_array($tipo, ['linha', 'grupo', 'subgrupo'], true)) $f[$tipo] = $val;
    }
    return $f;
}

/** Normaliza um alvo {tipo,valor} (legado) OU filtro composto para o formato de filtro. */
function alvoParaFiltro(array $a): array {
    if (isset($a['tipo'])) {
        $f = ['linha' => null, 'grupo' => null, 'subgrupo' => null, 'produto' => null];
        if ($a['tipo'] === 'produto') $f['produto'] = (int)$a['valor'];
        elseif (in_array($a['tipo'], ['linha', 'grupo', 'subgrupo'], true)) $f[$a['tipo']] = trim($a['valor']);
        return $f;
    }
    return [
        'linha'    => isset($a['linha'])    && $a['linha']    !== '' ? trim($a['linha'])    : null,
        'grupo'    => isset($a['grupo'])    && $a['grupo']    !== '' ? trim($a['grupo'])    : null,
        'subgrupo' => isset($a['subgrupo']) && $a['subgrupo'] !== '' ? trim($a['subgrupo']) : null,
        'produto'  => !empty($a['produto']) ? (int)$a['produto'] : null,
    ];
}

/** Um filtro é válido se ao menos um campo está definido. */
function filtroValido(array $f): bool {
    return $f['linha'] !== null || $f['grupo'] !== null || $f['subgrupo'] !== null || $f['produto'] !== null;
}

/** Item satisfaz TODOS os campos definidos do filtro (E). O id do produto pode vir como 'id' ou 'produto_id'. */
function itemMatchFiltro(array $prod, array $f): bool {
    if (!filtroValido($f)) return false;
    $pid = (int)($prod['produto_id'] ?? $prod['id'] ?? 0);
    if ($f['produto']  !== null && $pid !== $f['produto']) return false;
    if ($f['linha']    !== null && trim($prod['linha']    ?? '') !== $f['linha'])    return false;
    if ($f['grupo']    !== null && trim($prod['grupo']    ?? '') !== $f['grupo'])    return false;
    if ($f['subgrupo'] !== null && trim($prod['subgrupo'] ?? '') !== $f['subgrupo']) return false;
    return true;
}

/** Indica se um produto bate em algum alvo (filtro composto ou critério legado). */
function itemBateGruposAlvo(array $prod, array $gruposAlvo): bool {
    foreach ($gruposAlvo as $ga) {
        if (itemMatchFiltro($prod, alvoParaFiltro($ga))) return true;
    }
    return false;
}

/** Rótulo legível de um filtro composto (linha · grupo · subgrupo · produto). */
function rotuloFiltro(array $f, array $prodNome = []): string {
    $parts = [];
    if (!empty($f['linha']))    $parts[] = 'Linha '    . $f['linha'];
    if (!empty($f['grupo']))    $parts[] = 'Grupo '    . $f['grupo'];
    if (!empty($f['subgrupo'])) $parts[] = 'Subgrupo ' . $f['subgrupo'];
    if (!empty($f['produto']))  $parts[] = 'Produto '  . ($prodNome[(int)$f['produto']] ?? ('#' . $f['produto']));
    return implode(' · ', $parts);
}

/**
 * Resumo das campanhas ATINGIDAS por um contexto de venda (para exibição em
 * telas de pedido — cliente e admin). Reúne desconto/bônus, alvos e detalhe do
 * gatilho de cada campanha acionada.
 * @return array [['codigo','tipo','desconto','bonus'=>[],'alvo','detalhe','mult'], ...]
 */
function campanhasAtingidasResumo(array $ctxCamp): array {
    $prodNome = [];
    foreach (db()->query('SELECT id, codigo_produto, descricao_pt FROM produtos')->fetchAll() as $p) {
        $prodNome[(int)$p['id']] = $p['descricao_pt'] ?: $p['codigo_produto'];
    }
    $bonifMap = [];
    foreach (db()->query("SELECT cb.codigo_campanha, cb.quantidade, p.descricao_pt, p.codigo_produto
        FROM campanha_bonificacao cb JOIN produtos p ON p.id = cb.produto_id ORDER BY cb.id")->fetchAll() as $b) {
        $bonifMap[$b['codigo_campanha']][] = (int)$b['quantidade'] . 'x ' . ($b['descricao_pt'] ?: $b['codigo_produto']);
    }

    $itens = $ctxCamp['itens'] ?? [];
    $out = [];
    foreach (campanhasAgrupadas() as $code => $g) {
        $rows = $g['rows']; $conds = $g['conds'];
        $res  = avaliarCampanhaTrigger($rows, $conds, $ctxCamp);
        if (!$res['acionada']) continue;

        $alvos = [];
        foreach ($res['gruposAlvo'] as $ga) $alvos[] = rotuloFiltro(alvoParaFiltro($ga), $prodNome);

        if ($conds) {
            $partes = [];
            foreach ($conds as $c) {
                $f = condFiltro($c);
                $tq = 0; $tv = 0.0;
                foreach ($itens as $it) { if (itemMatchFiltro($it, $f)) { $tq += (int)$it['qtd']; $tv += (float)$it['valor']; } }
                $partes[] = ($c['criterio_modo'] ?? 'quantidade') === 'valor'
                    ? rotuloFiltro($f, $prodNome) . ': ' . moedaBR($tv) . '/' . moedaBR((float)($c['valor_min'] ?? 0))
                    : rotuloFiltro($f, $prodNome) . ': ' . $tq . '/' . (int)$c['quantidade'] . ' un.';
            }
            $detalhe = implode(' · E · ', $partes);
        } else {
            $min = (int)($rows[0]['quantidade'] ?? 0);
            $detalhe = 'atingido ' . ($res['mult'] * $min) . ' un. (mín. ' . $min . ')';
        }

        $out[] = [
            'codigo'   => $code,
            'tipo'     => $rows[0]['tipo'] ?? 'desconto',
            'desconto' => (float)$rows[0]['desconto'],
            'bonus'    => $bonifMap[$code] ?? [],
            'alvo'     => implode(', ', array_unique($alvos)),
            'detalhe'  => $detalhe,
            'mult'     => (int)$res['mult'],
        ];
    }
    return $out;
}

/**
 * Campanhas de DESCONTO do NOVO modelo (com condições) que foram acionadas.
 * O modelo legado continua sendo avaliado item a item nas telas de pedido.
 * @return array  [['desconto'=>float,'gruposAlvo'=>[['tipo','valor'],...]], ...]
 */
function avaliarCampanhasDescontoAvancadas(array $ctx): array {
    $out = [];
    foreach (campanhasAgrupadas() as $code => $g) {
        if (empty($g['conds'])) continue;                                  // só novo modelo
        if (($g['rows'][0]['tipo'] ?? 'desconto') !== 'desconto') continue;
        $r = avaliarCampanhaTrigger($g['rows'], $g['conds'], $ctx);
        if (!$r['acionada']) continue;
        $out[] = [
            'desconto'   => (float)$g['rows'][0]['desconto'],
            'gruposAlvo' => alvosDescontoCampanha((string)$code, $r['gruposAlvo']),
        ];
    }
    return $out;
}

/**
 * Cria um pedido bonificado (lote separado, sem cotação).
 * @param array $bonusAcc  produto_id => quantidade
 * @param array $precoById produto_id => preço unitário usado na seleção (na moeda do
 *                         cliente). Quando informado, grava o item com esse preço — o
 *                         MESMO mostrado ao cliente; senão usa o preço Network (bônus
 *                         automático/MA).
 * @return array  itens criados: ['produto_id','descricao','quantidade','pedido_id']
 */
function criarPedidoBonificado(int $clienteId, string $supervisor, string $dataPedido, array $bonusAcc, string $obs, array $precoById = []): array {
    if (!$bonusAcc) return [];
    $lote = uniqid('LB', true);
    $num  = 'PED-' . date('Y') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
    $moedaCli = db()->prepare('SELECT moeda FROM clientes WHERE id = ?');
    $moedaCli->execute([$clienteId]);
    $moedaCli = $moedaCli->fetchColumn() ?: 'BRL';
    $cotacaoCli = null; // não converte: o valor já é gravado na moeda do cliente.
    $ins  = db()->prepare('INSERT INTO pedidos (numero_pedido,tipo_venda,data_pedido,cliente_id,produto_id,supervisor,codigo_barra,descricao_produto,quantidade_total,valor_total,status,observacoes,lote_id,moeda,cotacao) VALUES (?,?,?,?,?,?,?,?,?,?,"comercial",?,?,?,?)');
    $criados = [];
    foreach ($bonusAcc as $pid => $q) {
        $q = (int)$q; if ($q <= 0) continue;
        $pr = db()->prepare('SELECT p.descricao_pt, p.codigo_barra, COALESCE(t.preco_network, p.vendas_varejo, 0) AS preco
                             FROM produtos p LEFT JOIN tabela_precos t ON t.produto_id = p.id
                             WHERE p.id = ? AND p.status = "ativo"');
        $pr->execute([(int)$pid]); $pr = $pr->fetch();
        if (!$pr) continue;
        // Usa o preço efetivamente exibido na seleção (moeda do cliente) quando informado.
        $precoUnit = array_key_exists((int)$pid, $precoById) ? (float)$precoById[(int)$pid] : (float)$pr['preco'];
        $valor = $q * $precoUnit;
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
 * Cria um pedido novo a partir de itens localizados no sistema A&M (Itallian)
 * — usado por "Importa Pedido" (admin/pedidos.php). Espera itens já mapeados
 * (produto_id resolvido batendo o Código A&M com produtos.codigo_produto) e a quantidade já
 * final (o A&M já considera os múltiplos — não multiplica de novo aqui).
 * O preço/desconto usado é o do próprio SisPed (mesma fórmula de "Adicionar
 * Produto" em admin/pedido.php), não o valor mostrado no A&M — exceto o Desc.
 * Comercial/Diretoria, trazidos do A&M (%Negociação/%Diretoria) e aplicados
 * em cascata sobre o desconto de cliente/canal, igual a recalcularValorItem().
 * Quando $descontoAVista é true (Forma Pagto "00 - A Vista" no grid do A&M), aplica
 * o mesmo desconto de 5% sobre o total do pedido usado para pagamento via Pix
 * (ver recalcularDescontoPix() em admin/pedido.php), gravado em desconto_pagamento
 * no primeiro item do lote.
 * @param array $itens [['produto_id'=>int,'qtd'=>int,'desconto_comercial'=>float,'desconto_diretoria'=>float], ...]
 * @return array ['numero_pedido'=>string,'primeiro_id'=>int,'criados'=>int]
 */
function criarPedidoImportadoAEM(int $clienteId, string $tipoVenda, array $itens, ?string $numeroBusca = null, ?string $formaPagto = null, bool $descontoAVista = false, bool $ehBF = false): array {
    if (!$itens) throw new Exception('Nenhum item para importar.');
    $cli = db()->prepare('SELECT supervisor, vendedor, moeda, desconto_cliente, desconto_canal FROM clientes WHERE id = ?');
    $cli->execute([$clienteId]);
    $cli = $cli->fetch();
    if (!$cli) throw new Exception('Cliente não encontrado.');

    $tipoVenda  = $tipoVenda === 'bonificacao' ? 'bonificacao' : 'venda';
    $moeda      = $cli['moeda'] ?: 'BRL';
    $dCliente   = (float)($cli['desconto_cliente'] ?? 0);
    $dCanal     = (float)($cli['desconto_canal'] ?? 0);
    $descCliCanal = min(100, $dCliente + $dCanal);
    $supervisor = $cli['supervisor'] ?: ($cli['vendedor'] ?: '');
    $colPreco   = colPrecoMoeda($moeda, $tipoVenda === 'bonificacao');

    $lote_id       = uniqid('LA', true);
    $numero_pedido = 'PED-' . date('Y') . '-' . str_pad((string)rand(1000, 9999), 4, '0', STR_PAD_LEFT);
    $data          = date('Y-m-d');
    $tagBF         = $ehBF ? ' (BF)' : '';
    $obs           = $numeroBusca ? ('Importado do sistema A&M' . $tagBF . ' — Pedido Nº ' . $numeroBusca) : ('Importado do sistema A&M' . $tagBF);
    $multiLote     = count($itens) > 1;

    $prodStmt = db()->prepare("SELECT p.descricao_pt, p.codigo_barra, COALESCE($colPreco, p.vendas_varejo, 0) AS preco
                                FROM produtos p LEFT JOIN tabela_precos t ON t.produto_id = p.id
                                WHERE p.id = ? AND p.status = 'ativo'");
    $ins = db()->prepare('INSERT INTO pedidos (numero_pedido,tipo_venda,data_pedido,cliente_id,produto_id,supervisor,codigo_barra,descricao_produto,quantidade_total,valor_total,status,observacoes,lote_id,moeda,desconto_comercial,desconto_diretoria,forma_pagamento) VALUES (?,?,?,?,?,?,?,?,?,?,"comercial",?,?,?,?,?,?)');

    $criados = [];
    foreach ($itens as $it) {
        $produtoId = (int)($it['produto_id'] ?? 0);
        $qtd       = max(1, (int)($it['qtd'] ?? 0));
        if (!$produtoId) continue;
        $prodStmt->execute([$produtoId]);
        $prod = $prodStmt->fetch();
        if (!$prod) continue;
        $dComercial  = (float)($it['desconto_comercial'] ?? 0);
        $dDiretoria  = (float)($it['desconto_diretoria'] ?? 0);
        $descComDir  = min(100, $dComercial + $dDiretoria);
        $valor_total = $tipoVenda === 'bonificacao' ? 0.0
                     : $qtd * (float)$prod['preco'] * (1 - $descCliCanal / 100) * (1 - $descComDir / 100);
        $ins->execute([$numero_pedido, $tipoVenda, $data, $clienteId, $produtoId, $supervisor, $prod['codigo_barra'], $prod['descricao_pt'], $qtd, $valor_total, $obs, $multiLote ? $lote_id : null, $moeda, $dComercial, $dDiretoria, $formaPagto]);
        $criados[] = (int)db()->lastInsertId();
    }
    if (!$criados) throw new Exception('Nenhum item pôde ser importado.');

    if ($descontoAVista) {
        $totalStmt = db()->prepare('SELECT valor_total, tipo_venda FROM pedidos WHERE ' . ($multiLote ? 'lote_id = ?' : 'id = ?'));
        $totalStmt->execute([$multiLote ? $lote_id : $criados[0]]);
        $totalPedido = 0.0;
        foreach ($totalStmt->fetchAll() as $tp) {
            if ($tp['tipo_venda'] === 'bonificacao') continue;
            $totalPedido += (float)$tp['valor_total'];
        }
        $descontoPagamento = round($totalPedido * 0.05, 2);
        if ($descontoPagamento > 0) {
            db()->prepare('UPDATE pedidos SET desconto_pagamento = ? WHERE id = ?')->execute([$descontoPagamento, $criados[0]]);
        }
    }

    try {
        db()->prepare('INSERT INTO pedido_logs (pedido_id,numero_pedido,usuario_nome,usuario_tipo,acao,status_antes,status_depois,detalhes) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$criados[0], $numero_pedido, usuario()['nome'] ?? 'Sistema', usuario()['tipo'] ?? 'sistema', 'Pedido importado do sistema A&M' . $tagBF, null, 'comercial', $obs]);
    } catch (PDOException $e) {}

    return ['numero_pedido' => $numero_pedido, 'primeiro_id' => $criados[0], 'criados' => count($criados)];
}

/**
 * Cálculo consolidado da MARGEM de um pedido (waterfall: preço padrão -> descontos -> crédito
 * -> impostos por empresa -> custo MP -> custos fixos) + detalhamento fiscal.
 *
 * Extraído de admin/pedido.php (era código inline dos modais "Detalhamento Fiscal" e "Margem")
 * para virar fonte única, reutilizada por admin/relatorios/margem-am.php. O consumidor faz
 * `extract(calcularMargemPedido($id))` e continua usando os mesmos nomes de variável.
 *
 * Chaves úteis para relatórios:
 *   impTotalBase   = Σ (preço padrão × qtd)  — valor de tabela dos produtos
 *   impTotalFinal  = margem final (R$)        impMargemPct = impTotalFinal / impTotalBase × 100
 *   impDeltaDescontos / impDeltaCredito / impDeltaNet / impDeltaImpostos / impDeltaMP /
 *   impDeltaDespesas = dedução de cada etapa do waterfall (valores negativos)
 *   impItens[] = waterfall por item;  fiscalItens[] / fiscalTot = detalhamento fiscal
 *
 * @return array  vazio se o pedido não existir.
 */
function calcularMargemPedido(int $pedidoId): array {
    $pedido = db()->prepare("
        SELECT p.*, c.razao_social, c.email AS cliente_email,
               c.desconto_cliente, c.desconto_canal, c.estado AS cliente_uf, c.cidade AS cliente_cidade,
               c.canal_venda_id AS cliente_canal_id, c.regime_tributario AS cliente_regime,
               cv.canal AS canal_venda, cv.margem_negociacao, cv.network_tipo,
               pr.codigo_produto, pr.multiplo, pr.linha, pr.grupo, pr.subgrupo,
               COALESCE(t.preco_padrao, pr.vendas_varejo) AS preco_unit
        FROM pedidos p
        LEFT JOIN clientes c      ON c.id  = p.cliente_id
        LEFT JOIN canal_venda cv  ON cv.id = c.canal_venda_id
        LEFT JOIN produtos pr     ON pr.id = p.produto_id
        LEFT JOIN tabela_precos t ON t.produto_id = pr.id
        WHERE p.id = ?");
    $pedido->execute([$pedidoId]);
    $pedido = $pedido->fetch();
    if (!$pedido) return [];

    $colPreco = colPrecoMoeda($pedido['moeda'] ?? 'BRL');
    $loteId = $pedido['lote_id'] ?: null;
    $stmtItens = db()->prepare("
        SELECT p.*, pr.codigo_produto, pr.multiplo, COALESCE($colPreco, pr.vendas_varejo) AS preco_unit
        FROM pedidos p
        LEFT JOIN produtos pr ON pr.id = p.produto_id
        LEFT JOIN tabela_precos t ON t.produto_id = pr.id
        WHERE " . ($loteId ? 'p.lote_id = ?' : 'p.id = ?') . " ORDER BY p.id");
    $stmtItens->execute([$loteId ?: $pedidoId]);
    $itensPedido = $stmtItens->fetchAll();
    $valorTotalGeral = array_sum(array_column($itensPedido, 'valor_total'));
    $margemNegociacao = (float)($pedido['margem_negociacao'] ?? 0);

    // ===== Detalhamento fiscal (preço Network + impostos do NCM) =====
    $UF_NOME = [
        'AC'=>'Acre','AL'=>'Alagoas','AM'=>'Amazonas','AP'=>'Amapa','BA'=>'Bahia','CE'=>'Ceará',
        'DF'=>'Distrito Federal','ES'=>'Espirito Santo','GO'=>'Goias','MA'=>'Maranhão','MG'=>'Minas Gerais',
        'MS'=>'Mato Grosso Sul','MT'=>'Mato Grosso','PA'=>'Para','PB'=>'Paraíba','PE'=>'Pernanbuco',
        'PI'=>'Piauí','PR'=>'Paraná','RJ'=>'Rio de Janeiro','RN'=>'Rio Grande Norte','RO'=>'Rondônia',
        'RR'=>'Roraima','RS'=>'Rio Grande Sul','SC'=>'Santa Catarina','SE'=>'Sergipe','SP'=>'São Paulo','TO'=>'Tocantins',
    ];
    $clienteUF     = strtoupper(trim($pedido['cliente_uf'] ?? ''));
    $clienteRegime = $pedido['cliente_regime'] ?? '';
    $ufNome        = $UF_NOME[$clienteUF] ?? null;
    // SP com regime Lucro Real/Presumido usa a alíquota de ICMS específica "São Paulo (LR/LP)".
    if ($clienteUF === 'SP' && in_array($clienteRegime, ['Lucro Real', 'Lucro Presumido'], true)) {
        $ufNome = 'São Paulo (LR/LP)';
    }
    $ehLocal    = ($clienteUF !== '' && $clienteUF === EMPRESA_UF);
    $icmsTipoLabel = $clienteUF === '' ? '—' : ($ehLocal ? 'Local (' . $clienteUF . ')' : 'Interestadual (' . EMPRESA_UF . '→' . $clienteUF . ')');

    $fiscalSql = "SELECT p.descricao_produto, p.quantidade_total, pr.codigo_produto, pr.ncm_id,
                         COALESCE(t.preco_network, 0) AS preco_unit,
                         n.ipi, n.pis, n.cofins, n.ncm AS ncm_codigo
                  FROM pedidos p
                  LEFT JOIN produtos pr     ON pr.id = p.produto_id
                  LEFT JOIN tabela_precos t ON t.produto_id = pr.id
                  LEFT JOIN ncm n           ON n.id = pr.ncm_id
                  WHERE " . ($loteId ? 'p.lote_id = ?' : 'p.id = ?') . " ORDER BY p.id";
    $fq = db()->prepare($fiscalSql);
    $fq->execute([$loteId ?: $pedidoId]);
    $fiscalRaw = $fq->fetchAll();

    // ICMS (por ncm) para o estado do cliente
    $icmsByNcm = [];
    if ($ufNome) {
        $ncmIds = array_values(array_filter(array_unique(array_column($fiscalRaw, 'ncm_id'))));
        if ($ncmIds) {
            $ph = implode(',', array_fill(0, count($ncmIds), '?'));
            $iq = db()->prepare("SELECT ncm_id, icms_local, icms_interestadual FROM ncm_estados WHERE estado = ? AND ncm_id IN ($ph)");
            $iq->execute(array_merge([$ufNome], $ncmIds));
            foreach ($iq->fetchAll() as $ir) $icmsByNcm[$ir['ncm_id']] = $ir;
        }
    }

    $fiscalItens = [];
    $fiscalTot   = ['item'=>0,'icms'=>0,'ipi'=>0,'pis'=>0,'cofins'=>0];
    foreach ($fiscalRaw as $r) {
        $qtd   = (int)$r['quantidade_total'];
        $unit  = (float)$r['preco_unit'];
        $total = $qtd * $unit;
        $ipiA  = (float)($r['ipi'] ?? 0);
        $pisA  = (float)($r['pis'] ?? 0);
        $cofA  = (float)($r['cofins'] ?? 0);
        $icmsRow = $icmsByNcm[$r['ncm_id']] ?? null;
        $icmsA = $icmsRow ? (float)($ehLocal ? $icmsRow['icms_local'] : $icmsRow['icms_interestadual']) : 0;
        $vIcms = $total * $icmsA / 100;
        $vIpi  = $total * $ipiA  / 100;
        $vPis  = $total * $pisA  / 100;
        $vCof  = $total * $cofA  / 100;
        $fiscalItens[] = [
            'codigo' => $r['codigo_produto'], 'descricao' => $r['descricao_produto'],
            'ncm' => $r['ncm_codigo'], 'qtd' => $qtd, 'unit' => $unit, 'total' => $total,
            'icms_v' => $vIcms, 'icms_a' => $icmsA, 'ipi_v' => $vIpi, 'ipi_a' => $ipiA,
            'pis_v' => $vPis, 'pis_a' => $pisA, 'cofins_v' => $vCof, 'cofins_a' => $cofA,
        ];
        $fiscalTot['item']  += $total; $fiscalTot['icms'] += $vIcms; $fiscalTot['ipi'] += $vIpi;
        $fiscalTot['pis']   += $vPis;  $fiscalTot['cofins'] += $vCof;
    }

    // ===== Descontos aplicados e campanhas atingidas (informativo) =====
    $descCliente   = (float)($pedido['desconto_cliente'] ?? 0);
    $descCanal     = (float)($pedido['desconto_canal'] ?? 0);
    $pedidoCanalId = (int)($pedido['cliente_canal_id'] ?? 0);
    $ehBonifPedido = ($pedido['tipo_venda'] ?? 'venda') === 'bonificacao';

    // Itens do pedido com categoria e preço (para checar gatilho das campanhas).
    $ci = db()->prepare("SELECT p.produto_id, p.quantidade_total,
                                COALESCE($colPreco, pr.vendas_varejo) AS preco_unit,
                                pr.linha, pr.grupo, pr.subgrupo
                         FROM pedidos p LEFT JOIN produtos pr ON pr.id = p.produto_id
                         LEFT JOIN tabela_precos t ON t.produto_id = pr.id
                         WHERE " . ($loteId ? 'p.lote_id = ?' : 'p.id = ?'));
    $ci->execute([$loteId ?: $pedidoId]);
    $itensCamp = [];
    foreach ($ci->fetchAll() as $it) {
        $itensCamp[] = [
            'produto_id' => (int)$it['produto_id'],
            'qtd'        => (int)$it['quantidade_total'],
            'linha'      => $it['linha'],
            'grupo'      => $it['grupo'],
            'subgrupo'   => $it['subgrupo'],
            'preco'      => (float)$it['preco_unit'],
        ];
    }
    $ctxCamp = ctxCampanha($itensCamp, $pedidoCanalId);
    $campanhasAtingidas = campanhasAtingidasResumo($ctxCamp);

    $creditoUsadoAdmin = 0.0;
    if ($pedido['lote_id']) {
        $cuAdm = db()->prepare('SELECT credito_utilizado FROM pedidos WHERE lote_id = ? AND credito_utilizado > 0 LIMIT 1');
        $cuAdm->execute([$pedido['lote_id']]);
        $creditoUsadoAdmin = (float)($cuAdm->fetchColumn() ?: 0);
    } else {
        $creditoUsadoAdmin = (float)($pedido['credito_utilizado'] ?? 0);
    }

    // Desconto de pagamento (Pix 5%)
    $descontoPixAdmin = 0.0;
    if ($pedido['lote_id']) {
        $dpAdm = db()->prepare('SELECT desconto_pagamento FROM pedidos WHERE lote_id = ? AND desconto_pagamento > 0 LIMIT 1');
        $dpAdm->execute([$pedido['lote_id']]);
        $descontoPixAdmin = (float)($dpAdm->fetchColumn() ?: 0);
    } else {
        $descontoPixAdmin = (float)($pedido['desconto_pagamento'] ?? 0);
    }
    $totalAPagarAdmin = max(0, $valorTotalGeral - $descontoPixAdmin - $creditoUsadoAdmin);

    // ===== Impostos por Empresa (waterfall: preço padrão → descontos → impostos por empresa → custo MP → custos fixos) =====
    $impSql = "SELECT p.id, p.produto_id, p.descricao_produto, p.quantidade_total, pr.codigo_produto, pr.ncm_id,
                      p.desconto_comercial, p.desconto_diretoria, p.desconto_campanha,
                      COALESCE(t.preco_padrao, 0) AS preco_padrao,
                      COALESCE(t.preco_network, 0) AS preco_network,
                      n.ipi, n.pis, n.cofins, n.pis_accademia, n.cofins_accademia
               FROM pedidos p
               LEFT JOIN produtos pr     ON pr.id = p.produto_id
               LEFT JOIN tabela_precos t ON t.produto_id = pr.id
               LEFT JOIN ncm n           ON n.id = pr.ncm_id
               WHERE " . ($loteId ? 'p.lote_id = ?' : 'p.id = ?') . " ORDER BY p.id";
    $impQ = db()->prepare($impSql);
    $impQ->execute([$loteId ?: $pedidoId]);
    $impRaw = $impQ->fetchAll();
    $impEmpresas = db()->query('SELECT nome, irpj, csll, iss FROM impostos_empresas ORDER BY nome')->fetchAll();

    // Custo MP: busca no módulo "Custos dos Produtos" pela competência (mês/ano) da criação do pedido.
    $competenciaPedido = date('Y-m-01', strtotime($pedido['data_pedido']));
    $custosMP = [];
    $produtoIdsImp = array_values(array_unique(array_filter(array_column($impRaw, 'produto_id'))));
    if ($produtoIdsImp) {
        $ph = implode(',', array_fill(0, count($produtoIdsImp), '?'));
        $cmpStmt = db()->prepare("SELECT produto_id, custo FROM custos_produtos WHERE competencia = ? AND produto_id IN ($ph)");
        $cmpStmt->execute(array_merge([$competenciaPedido], $produtoIdsImp));
        foreach ($cmpStmt->fetchAll() as $cm) $custosMP[(int)$cm['produto_id']] = (float)$cm['custo'];
    }

    // Custo Fixo (%): cadastrado no módulo "Custos dos Produtos" pela competência (mês/ano) da criação do pedido.
    $cfStmt = db()->prepare('SELECT percentual FROM custos_fixos WHERE competencia = ?');
    $cfStmt->execute([$competenciaPedido]);
    $custoFixoPct = (float)($cfStmt->fetchColumn() ?: 0);

    // Empresa "Network" (recebe os impostos do NCM do produto + seus próprios IRPJ/CSLL/ISS);
    // as demais empresas cadastradas entram em blocos subsequentes, só com seus próprios impostos.
    $empNet = null;
    foreach ($impEmpresas as $ie) { if (stripos($ie['nome'], 'net') !== false) { $empNet = $ie; break; } }
    $outrasEmpresas = array_values(array_filter($impEmpresas, function ($ie) use ($empNet) {
        return !$empNet || $ie['nome'] !== $empNet['nome'];
    }));
    if (!$empNet && $impEmpresas) { $empNet = $impEmpresas[0]; $outrasEmpresas = array_slice($impEmpresas, 1); }

    // Canal "Network / Accademia" calcula também o bloco das demais empresas (ex.: Accademia);
    // canal só "Network" não tem esse desdobramento e o imposto Network passa a incidir sobre o Resultado após Descontos.
    $temAccademia = stripos((string)($pedido['network_tipo'] ?? 'Network'), 'accademia') !== false;
    if (!$temAccademia) $outrasEmpresas = [];

    // 1ª passada: descontos em cascata (mesma lógica de recalcularValorItem) de cada item, para
    // achar o "Total após Descontos" do pedido — base do percentual de Crédito Aplicado (abaixo).
    $impPre = [];
    foreach ($impRaw as $r) {
        $precoPadrao = (float)$r['preco_padrao'];
        $qtd = (int)($r['quantidade_total'] ?? 0);

        // Descontos em cascata (mesma lógica de recalcularValorItem): canal + cliente primeiro
        // (base = valor por produto), depois comercial + diretoria sobre esse resultado; campanha é multiplicativa por último.
        $vCanal   = $precoPadrao * $descCanal / 100;
        $vCliente = $precoPadrao * $descCliente / 100;
        $resAposCliCanal = $precoPadrao - $vCanal - $vCliente;
        $descPedidoPct = (float)$r['desconto_comercial'] + (float)$r['desconto_diretoria'];
        $vPedido  = $resAposCliCanal * $descPedidoPct / 100;
        $resDescCascata = $resAposCliCanal - $vPedido;
        $descCampanhaPct = (float)($r['desconto_campanha'] ?? 0);
        $vCampanha = $descCampanhaPct > 0 ? $resDescCascata * $descCampanhaPct / 100 : 0;
        $resAposDescontos = $resDescCascata - $vCampanha;

        $impPre[] = compact('r', 'precoPadrao', 'qtd', 'vCanal', 'vCliente', 'descPedidoPct', 'vPedido', 'descCampanhaPct', 'vCampanha', 'resAposDescontos');
    }

    // Crédito Aplicado (concessão de crédito do cliente, importada do A&M ou do módulo Concessão de
    // Créditos): valor absoluto do pedido, convertido em percentual sobre o Total após Descontos e
    // aplicado a cada item nessa mesma proporção — mesma mecânica do Desconto Financeiro (% sobre o
    // Resultado após Descontos), já que o crédito não tem vínculo com nenhum produto específico.
    $impTotalAposDescontos = array_sum(array_map(fn($p) => $p['resAposDescontos'] * $p['qtd'], $impPre));
    $pctCreditoGeral = ($creditoUsadoAdmin > 0 && $impTotalAposDescontos > 0)
        ? $creditoUsadoAdmin / $impTotalAposDescontos * 100 : 0.0;

    $impItens = [];
    foreach ($impPre as $p) {
        extract($p);

        // Crédito Aplicado do item = % Crédito Geral sobre o Resultado após Descontos deste item.
        // O resultado (Resultado após Crédito) passa a ser a base dos impostos a seguir — a base
        // tributável do item já sai reduzida do crédito concedido ao cliente.
        $vCredito = $resAposDescontos * $pctCreditoGeral / 100;
        $resAposCredito = $resAposDescontos - $vCredito;

        // Bloco da empresa Network: ICMS (por NCM + UF do cliente) + impostos do NCM do produto + impostos próprios da empresa.
        // Canal "Network / Accademia": percentuais sobre o "Preço Network" da tabela de preços (independente dos descontos do pedido).
        // Canal só "Network" (sem desdobramento para outras empresas): percentuais sobre o Resultado após Crédito.
        $icmsRow = $icmsByNcm[$r['ncm_id']] ?? null;
        $icmsPct = $icmsRow ? (float)($ehLocal ? $icmsRow['icms_local'] : $icmsRow['icms_interestadual']) : 0;
        $netTaxes  = [];
        $ipiNetPct = (float)($r['ipi'] ?? 0);
        // Canal só "Network": base não é mais direto o Resultado após Crédito, e sim esse valor
        // "por dentro" do IPI do NCM (Resultado após Crédito / (1 + IPI%)) — isola o valor sem o IPI embutido.
        $netBaseLabel = $temAccademia ? 'Preço Network' : 'Resultado após Crédito ÷ (1 + IPI)';
        $netBase   = $temAccademia ? (float)($r['preco_network'] ?? 0) : $resAposCredito / (1 + $ipiNetPct / 100);
        $ipiNetVal = $netBase * $ipiNetPct / 100;
        $icmsNetVal = $netBase * $icmsPct / 100;
        // PIS/COFINS incidem sobre a base do imposto Network já deduzida do ICMS (mesmo cálculo do canal "Network"
        // aplicado sobre a base do canal "Network / Accademia", que continua sendo o Preço Network).
        $pisCofinsBase = $netBase - $icmsNetVal;
        if ($empNet) {
            $netTaxes[] = ['label' => 'ICMS ' . ($clienteUF ?: '—') . ' ' . ($ehLocal ? 'Local' : 'Interestadual'), 'pct' => $icmsPct, 'val' => $icmsNetVal];
            $netTaxes[] = ['label' => 'IPI',    'pct' => $ipiNetPct,                   'val' => $ipiNetVal];
            $netTaxes[] = ['label' => 'PIS',    'pct' => (float)($r['pis'] ?? 0),      'val' => $pisCofinsBase * (float)($r['pis'] ?? 0) / 100];
            $netTaxes[] = ['label' => 'COFINS', 'pct' => (float)($r['cofins'] ?? 0),   'val' => $pisCofinsBase * (float)($r['cofins'] ?? 0) / 100];
            $netTaxes[] = ['label' => 'IRPJ',   'pct' => (float)$empNet['irpj'],       'val' => $netBase * (float)$empNet['irpj'] / 100];
            $netTaxes[] = ['label' => 'CSLL',   'pct' => (float)$empNet['csll'],       'val' => $netBase * (float)$empNet['csll'] / 100];
            $netTaxes[] = ['label' => 'ISS',    'pct' => (float)$empNet['iss'],        'val' => $netBase * (float)$empNet['iss'] / 100];
        }
        $netTotal   = array_sum(array_column($netTaxes, 'val'));
        $resAposNet = $resAposCredito - $netTotal;

        // Base de cálculo das demais empresas (ex.: Accademia) = Resultado após Crédito - Preço Network - IPI Network.
        // Se o resultado for negativo, desconsidera o cálculo (base = 0, sem impostos nesse bloco).
        $baseOutras = $resAposCredito - $netBase - $ipiNetVal;
        if ($baseOutras < 0) $baseOutras = 0;

        // Blocos das demais empresas (em sequência): impostos próprios + PIS/COFINS específicos da empresa (cadastrados no NCM)
        $blocosOutros = [];
        $baseAtual = $resAposNet;
        foreach ($outrasEmpresas as $oe) {
            // PIS/COFINS por empresa: Network usa n.pis/n.cofins; as demais usam n.pis_accademia/n.cofins_accademia
            // (únicos campos de PIS/COFINS cadastrados no NCM além dos da Network).
            $pisPct  = (float)($r['pis_accademia'] ?? 0);
            $cofPct  = (float)($r['cofins_accademia'] ?? 0);
            $taxes = [
                ['label' => 'PIS',    'pct' => $pisPct,             'val' => $baseOutras * $pisPct / 100],
                ['label' => 'COFINS', 'pct' => $cofPct,             'val' => $baseOutras * $cofPct / 100],
                ['label' => 'IRPJ',   'pct' => (float)$oe['irpj'],  'val' => $baseOutras * (float)$oe['irpj'] / 100],
                ['label' => 'CSLL',   'pct' => (float)$oe['csll'],  'val' => $baseOutras * (float)$oe['csll'] / 100],
                ['label' => 'ISS',    'pct' => (float)$oe['iss'],   'val' => $baseOutras * (float)$oe['iss'] / 100],
            ];
            $t = array_sum(array_column($taxes, 'val'));
            $blocosOutros[] = ['nome' => $oe['nome'], 'taxes' => $taxes, 'total' => $t];
            $baseAtual -= $t;
        }
        $resAposImpostos = $baseAtual;

        // Base para Custos Fixos = valor por produto - desconto canal - desconto cliente (sem o desconto do pedido)
        $baseCF = $precoPadrao - $vCanal - $vCliente;

        // Custo MP (matéria-prima) = custo cadastrado no módulo "Custos dos Produtos" para a competência do pedido.
        $custoMPAchado = isset($custosMP[(int)($r['produto_id'] ?? 0)]);
        $custoMP       = $custosMP[(int)($r['produto_id'] ?? 0)] ?? 0.0;
        $resAposMP     = $resAposImpostos - $custoMP;

        // Custos Fixos (%) = percentual cadastrado no módulo "Custos dos Produtos" aplicado sobre a baseCF.
        $vCF = $baseCF * $custoFixoPct / 100;

        // Desconto Financeiro: só para canal "Network / Accademia" com pedido à vista (Pix, desconto de 5%).
        // Percentual sobre o Resultado após Crédito.
        $descFinanceiroPct = ($temAccademia && $descontoPixAdmin > 0) ? 5.0 : 0.0;
        $vDescFinanceiro    = $resAposCredito * $descFinanceiroPct / 100;
        $resultadoIni       = $resAposMP - $vCF - $vDescFinanceiro;

        $impItens[] = [
            'codigo' => $r['codigo_produto'], 'descricao' => $r['descricao_produto'],
            'qtd' => $qtd,
            'custoMP' => $custoMP, 'custoMPAchado' => $custoMPAchado, 'resAposMP' => $resAposMP, 'resultadoIni' => $resultadoIni,
            'custoFixoPct' => $custoFixoPct, 'vCF' => $vCF,
            'descFinanceiroPct' => $descFinanceiroPct, 'vDescFinanceiro' => $vDescFinanceiro,
            'precoPadrao' => $precoPadrao,
            'descCanalPct' => $descCanal, 'vCanal' => $vCanal,
            'descClientePct' => $descCliente, 'vCliente' => $vCliente,
            'descPedidoPct' => $descPedidoPct, 'vPedido' => $vPedido,
            'descCampanhaPct' => $descCampanhaPct, 'vCampanha' => $vCampanha,
            'resAposDescontos' => $resAposDescontos,
            'pctCredito' => $pctCreditoGeral, 'vCredito' => $vCredito, 'resAposCredito' => $resAposCredito,
            'precoNetwork' => $netBase, 'netBaseLabel' => $netBaseLabel, 'ipiNetPct' => $ipiNetPct,
            'icmsNetVal' => $icmsNetVal, 'pisCofinsBase' => $pisCofinsBase, 'temAccademia' => $temAccademia,
            'netNome' => $empNet['nome'] ?? null, 'netTaxes' => $netTaxes, 'netTotal' => $netTotal,
            'resAposNet' => $resAposNet,
            'baseOutras' => $baseOutras,
            'blocosOutros' => $blocosOutros,
            'resAposImpostos' => $resAposImpostos,
            'baseCF' => $baseCF,
        ];
    }
    $impTotalFinal = array_sum(array_map(fn($it) => $it['resultadoIni'] * $it['qtd'], $impItens));
    $impTotalBase  = array_sum(array_map(fn($it) => $it['precoPadrao']    * $it['qtd'], $impItens));
    $impMargemPct  = $impTotalBase > 0 ? $impTotalFinal / $impTotalBase * 100 : 0;

    // Totais por etapa do waterfall (soma de todos os produtos), para o resumo no topo do modal de Margem.
    // Cada linha (exceto a primeira e a Margem Total) mostra a soma dos negativos (deduções) daquela etapa.
    $impTotalProdutos  = $impTotalBase;
    $impDeltaDescontos = -array_sum(array_map(fn($it) => ($it['vCanal'] + $it['vCliente'] + $it['vPedido'] + $it['vCampanha']) * $it['qtd'], $impItens));
    $impDeltaCredito   = -array_sum(array_map(fn($it) => $it['vCredito'] * $it['qtd'], $impItens));
    $impDeltaNet       = -array_sum(array_map(fn($it) => $it['netTotal'] * $it['qtd'], $impItens));
    $impDeltaImpostos  = -array_sum(array_map(fn($it) => array_sum(array_column($it['blocosOutros'], 'total')) * $it['qtd'], $impItens));
    $impDeltaMP        = -array_sum(array_map(fn($it) => $it['custoMP'] * $it['qtd'], $impItens));
    $impDeltaDespesas  = -array_sum(array_map(fn($it) => ($it['vCF'] + $it['vDescFinanceiro']) * $it['qtd'], $impItens));

    return compact(
        'clienteUF', 'clienteRegime', 'ufNome', 'ehLocal', 'icmsTipoLabel', 'UF_NOME',
        'fiscalRaw', 'icmsByNcm', 'fiscalItens', 'fiscalTot',
        'descCliente', 'descCanal', 'pedidoCanalId', 'ehBonifPedido', 'itensCamp', 'ctxCamp', 'campanhasAtingidas',
        'creditoUsadoAdmin', 'descontoPixAdmin', 'totalAPagarAdmin', 'competenciaPedido',
        'impRaw', 'impEmpresas', 'custosMP', 'custoFixoPct', 'empNet', 'outrasEmpresas', 'temAccademia',
        'impPre', 'impTotalAposDescontos', 'pctCreditoGeral', 'impItens',
        'impTotalFinal', 'impTotalBase', 'impMargemPct',
        'impTotalProdutos', 'impDeltaDescontos', 'impDeltaCredito', 'impDeltaNet', 'impDeltaImpostos', 'impDeltaMP', 'impDeltaDespesas'
    );
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

/** Formata uma linha de produto para o pool de bonificação selecionável. */
function _bonifProdRow(array $p): array {
    return [
        'id'        => (int)$p['id'],
        'codigo'    => $p['codigo_produto'],
        'descricao' => $p['descricao_pt'],
        'preco'     => (float)$p['preco'],
        'multiplo'  => max(1.0, (float)($p['multiplo'] ?? 1)),
    ];
}

/** Pool de bônus selecionável pela lista fixa (campanha_bonificacao). */
function poolProdutosBonifLista(string $codigo): array {
    $lp = db()->prepare("SELECT p.id, p.codigo_produto, p.descricao_pt, p.multiplo,
                                COALESCE(t.preco_network, p.vendas_varejo, 0) AS preco
                         FROM campanha_bonificacao cb
                         JOIN produtos p ON p.id = cb.produto_id
                         LEFT JOIN tabela_precos t ON t.produto_id = p.id
                         WHERE cb.codigo_campanha = ? AND p.status = 'ativo' ORDER BY p.descricao_pt");
    $lp->execute([$codigo]);
    return array_map('_bonifProdRow', $lp->fetchAll());
}

/** Pool de bônus selecionável por categoria/produto (campanha_bonif_pool). */
function poolProdutosBonifCategoria(string $codigo): array {
    try {
        $ap = db()->prepare("SELECT alvo_tipo, alvo_valor FROM campanha_bonif_pool WHERE codigo_campanha = ?");
        $ap->execute([$codigo]);
        $alvos = $ap->fetchAll();
    } catch (PDOException $e) { return []; }
    if (!$alvos) return [];

    $cond = []; $params = [];
    foreach ($alvos as $a) {
        $val = trim($a['alvo_valor']);
        switch ($a['alvo_tipo']) {
            case 'linha':    $cond[] = 'p.linha = ?';    $params[] = $val; break;
            case 'grupo':    $cond[] = 'p.grupo = ?';    $params[] = $val; break;
            case 'subgrupo': $cond[] = 'p.subgrupo = ?'; $params[] = $val; break;
            case 'produto':  $cond[] = 'p.id = ?';       $params[] = (int)$val; break;
        }
    }
    if (!$cond) return [];
    $sql = "SELECT p.id, p.codigo_produto, p.descricao_pt, p.multiplo,
                   COALESCE(t.preco_network, p.vendas_varejo, 0) AS preco
            FROM produtos p
            LEFT JOIN tabela_precos t ON t.produto_id = p.id
            WHERE p.status = 'ativo' AND (" . implode(' OR ', $cond) . ") ORDER BY p.descricao_pt";
    $st = db()->prepare($sql);
    $st->execute($params);
    return array_map('_bonifProdRow', $st->fetchAll());
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

        // Pool de produtos elegíveis: por categoria (campanha_bonif_pool) ou
        // pela lista fixa de produtos (campanha_bonificacao).
        $selModo = ($g['rows'][0]['bonif_selec_modo'] ?? 'produtos') === 'categoria' ? 'categoria' : 'produtos';
        $produtos = $selModo === 'categoria'
            ? poolProdutosBonifCategoria((string)$code)
            : poolProdutosBonifLista((string)$code);
        if (!$produtos) continue;

        $limiteTipo = ($g['rows'][0]['bonif_limite_tipo'] ?? 'quantidade') === 'valor' ? 'valor' : 'quantidade';
        $limite     = (float)($g['rows'][0]['bonif_limite_valor'] ?? 0) * $mult;
        if ($limite <= 0) continue;
        $out[] = [
            'codigo'      => $code,
            'mult'        => $mult,
            'limite_tipo' => $limiteTipo,
            'limite'      => $limite,
            'moeda'       => 'BRL', // campanhas regulares usam preço Network (BRL)
            'produtos'    => $produtos,
        ];
    }
    return $out;
}

/** Percentual do bonus de exportacao sobre o valor da venda. */
define('BONUS_EXPORTACAO_PCT', 5.0);

/** Indica se o canal de venda informado e o de Exportacao (pelo nome do canal). */
function canalEhExportacao(int $canalVendaId): bool {
    if ($canalVendaId <= 0) return false;
    static $cache = [];
    if (!array_key_exists($canalVendaId, $cache)) {
        $nome = '';
        try {
            $st = db()->prepare('SELECT canal FROM canal_venda WHERE id = ?');
            $st->execute([$canalVendaId]);
            $nome = (string)$st->fetchColumn();
        } catch (PDOException $e) { /* sem canal */ }
        $cache[$canalVendaId] = (stripos($nome, 'export') !== false);
    }
    return $cache[$canalVendaId];
}

/**
 * Bonus de exportacao: 5% (BONUS_EXPORTACAO_PCT) do valor da venda para o cliente
 * escolher entre TODOS os produtos ativos. Respeita a MOEDA do cliente: o valor base
 * e os precos dos produtos sao na moeda informada (BRL/USD/EUR), usando a coluna de
 * preco correspondente (colPrecoMoeda). Retorna uma "campanha selecionavel" no mesmo
 * formato de detectarBonificacaoSelecionavel() (com a chave 'moeda'), ou [] quando o
 * limite e zero ou nao ha produtos com preco.
 */
function bonusExportacaoSelecionavel(float $valorBase, string $moeda = 'BRL'): array {
    $limite = round($valorBase * (BONUS_EXPORTACAO_PCT / 100), 2);
    if ($limite <= 0) return [];
    $col = colPrecoMoeda($moeda); // t.preco_padrao | t.preco_dolar | t.preco_euro
    $rows = db()->query("SELECT p.id, p.codigo_produto, p.descricao_pt, p.multiplo,
                                COALESCE($col, t.preco_network, p.vendas_varejo, 0) AS preco
                         FROM produtos p
                         LEFT JOIN tabela_precos t ON t.produto_id = p.id
                         WHERE p.status = 'ativo' ORDER BY p.descricao_pt")->fetchAll();
    $produtos = [];
    foreach ($rows as $p) {
        $preco = (float)$p['preco'];
        if ($preco <= 0) continue;   // sem preco nao da para bonificar por valor
        $produtos[] = [
            'id'        => (int)$p['id'],
            'codigo'    => $p['codigo_produto'],
            'descricao' => $p['descricao_pt'],
            'preco'     => $preco,
            'multiplo'  => max(1.0, (float)($p['multiplo'] ?? 1)),
        ];
    }
    if (!$produtos) return [];
    return [
        'codigo'      => 'EXPORTACAO ' . rtrim(rtrim(number_format(BONUS_EXPORTACAO_PCT, 2, ',', '.'), '0'), ',') . '%',
        'mult'        => 1,
        'limite_tipo' => 'valor',
        'limite'      => $limite,
        'moeda'       => strtoupper($moeda),
        'produtos'    => $produtos,
    ];
}
