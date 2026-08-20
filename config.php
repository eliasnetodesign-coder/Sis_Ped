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
define('AEM_LOGIN', 'i003');
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
 *          igual ao recurso de desconto Pix), 'itens'=>[['codigoAEM','nomeProduto',
 *          'qtd','descComercial','descDiretoria'],...]].
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
    $formaPagto = '';
    preg_match_all('/<TR[^>]*>(.*?)<\/TR>/is', $buscaHtml, $rowsBusca);
    foreach ($rowsBusca[1] as $rowHtml) {
        if (strpos($rowHtml, 'SidPed=' . $sidPed) === false) continue;
        preg_match_all('/<TD[^>]*>(.*?)<\/TD>/is', $rowHtml, $cellsBuscaM);
        $cellsBusca = array_map(function ($c) {
            return trim(html_entity_decode(strip_tags($c), ENT_QUOTES, 'UTF-8'));
        }, $cellsBuscaM[1] ?? []);
        if (isset($cellsBusca[14])) $formaPagto = $cellsBusca[14];
        break;
    }
    preg_match('/^(\d+)/', $formaPagto, $mfp);
    $isAVista = (($mfp[1] ?? '') === '00') && (stripos($formaPagto, 'vista') !== false);

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
    foreach ($rows[1] as $rowHtml) {
        preg_match_all('/<TD[^>]*>(.*?)<\/TD>/is', $rowHtml, $cellsM);
        $cells = array_map(function ($c) {
            return trim(html_entity_decode(strip_tags($c), ENT_QUOTES, 'UTF-8'));
        }, $cellsM[1] ?? []);
        if (count($cells) < 13) continue;
        $itens[] = [
            'codigoAEM' => $cells[1],
            'nomeProduto' => $cells[3],
            'qtd'         => (int)preg_replace('/\D/', '', $cells[12]),
            // %Negociação (col 9) = nosso "Desc. Comercial"; %Diretoria (col 11) = nosso "Desc. Diretoria".
            'descComercial' => $parsePct($cells[9]),
            'descDiretoria' => $parsePct($cells[11]),
        ];
    }
    if (!$itens) return ['ok' => false, 'erro' => 'Pedido encontrado, mas sem itens no A&M.'];

    return [
        'ok'            => true,
        'erro'          => null,
        'clienteNome'   => $clienteNome,
        'clienteCnpj'   => $clienteCnpj,
        'tipoVenda'     => $tipoVenda,
        'pedidoInterno' => $pedidoInterno,
        'numero'        => $numero,
        'formaPagto'    => $formaPagto,
        'isAVista'      => $isAVista,
        'itens'         => $itens,
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
function criarPedidoImportadoAEM(int $clienteId, string $tipoVenda, array $itens, ?string $numeroBusca = null, ?string $formaPagto = null, bool $descontoAVista = false): array {
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
    $obs           = $numeroBusca ? ('Importado do sistema A&M — Pedido Nº ' . $numeroBusca) : 'Importado do sistema A&M';
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
            ->execute([$criados[0], $numero_pedido, usuario()['nome'] ?? 'Sistema', usuario()['tipo'] ?? 'sistema', 'Pedido importado do sistema A&M', null, 'comercial', $obs]);
    } catch (PDOException $e) {}

    return ['numero_pedido' => $numero_pedido, 'primeiro_id' => $criados[0], 'criados' => count($criados)];
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
