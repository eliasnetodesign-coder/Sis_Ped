<?php
require_once __DIR__ . '/../config.php';
requireAdmin();
$u = usuario();
$isComercial = in_array($u['tipo'], ['comercial', 'tecnologia da informacao']);

// AJAX: dados do cliente para popup
if (isset($_GET['ajax_cliente'])) {
    $cid = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT c.*, cv.canal FROM clientes c LEFT JOIN canal_venda cv ON cv.id = c.canal_venda_id WHERE c.id = ?');
    $stmt->execute([$cid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($row ?: null);
    exit;
}

// AJAX: prévia da importação "Importa Pedido" — busca no sistema A&M (somente leitura, não grava nada).
if (isset($_GET['ajax_aem_preview'])) {
    header('Content-Type: application/json');
    if (!$isComercial) { echo json_encode(['ok' => false, 'erro' => 'Acesso restrito.']); exit; }
    $r = buscarPedidoAEM($_GET['numero'] ?? '');
    if (!$r['ok']) { echo json_encode($r); exit; }

    $cliente = null;
    if ($r['clienteCnpj']) {
        $cq = db()->prepare("SELECT id, razao_social, codigo_cliente, moeda FROM clientes
                              WHERE REPLACE(REPLACE(REPLACE(cnpj,'.',''),'/',''),'-','') = ? LIMIT 1");
        $cq->execute([$r['clienteCnpj']]);
        $cliente = $cq->fetch() ?: null;
    }

    // Bloqueia a importação se algum produto não tiver Valor (tabela_precos) ou Custo
    // (módulo Custos dos Produtos, competência do mês corrente) cadastrado.
    $competencia = date('Y-m-01');
    $colPreco = colPrecoMoeda($cliente['moeda'] ?? 'BRL', $r['tipoVenda'] === 'bonificacao');

    $itens = [];
    foreach ($r['itens'] as $it) {
        $pq = db()->prepare("SELECT id, descricao_pt FROM produtos WHERE codigo_produto = ? AND status = 'ativo' LIMIT 1");
        $pq->execute([$it['codigoAEM']]);
        $prod = $pq->fetch();

        $temValor = true;
        $temCusto = true;
        if ($prod) {
            if ($r['tipoVenda'] !== 'bonificacao') {
                $pv = db()->prepare("SELECT $colPreco FROM tabela_precos t WHERE t.produto_id = ?");
                $pv->execute([$prod['id']]);
                $preco = $pv->fetchColumn();
                $temValor = $preco !== false && $preco !== null && (float)$preco > 0;
            }
            $cv = db()->prepare('SELECT custo FROM custos_produtos WHERE produto_id = ? AND competencia = ?');
            $cv->execute([$prod['id'], $competencia]);
            $custo = $cv->fetchColumn();
            $temCusto = $custo !== false && $custo !== null;
        }

        $itens[] = [
            'codigoAEM' => $it['codigoAEM'],
            'nomeProduto' => $it['nomeProduto'],
            'qtd'         => $it['qtd'],
            'descComercial' => $it['descComercial'] ?? 0,
            'descDiretoria' => $it['descDiretoria'] ?? 0,
            'produtoId'   => $prod['id'] ?? null,
            'produtoDesc' => $prod['descricao_pt'] ?? null,
            'temValor'    => $temValor,
            'temCusto'    => $temCusto,
        ];
    }

    $resp = [
        'ok'            => true,
        'numero'        => $r['numero'],
        'pedidoInterno' => $r['pedidoInterno'],
        'tipoVenda'     => $r['tipoVenda'],
        'formaPagto'    => $r['formaPagto'],
        'isAVista'      => $r['isAVista'],
        'clienteNomeAEM' => $r['clienteNome'],
        'clienteCnpjAEM' => $r['clienteCnpj'],
        'clienteId'     => $cliente['id'] ?? null,
        'clienteLabel'  => $cliente ? ('[' . $cliente['codigo_cliente'] . '] ' . $cliente['razao_social']) : null,
        'descontoCanal'   => $r['descontoCanalAEM'],
        'descontoCliente' => $r['descontoClienteAEM'],
        'pedidoAccademia' => $r['pedidoAccademia'],
        'creditoUtilizado' => $r['creditoUtilizadoAEM'],
        'itens'         => $itens,
    ];

    // "Importa Pedido BF" (&bf=1): confere o Obs do pedido e, quando começa com "BF",
    // roda a análise de campanha (campanhasAmAvaliarPedido) para trazer, por item, o
    // %Diretoria esperado pela faixa de campanha atingida.
    if (($_GET['bf'] ?? '') === '1') {
        $resp['ehBf'] = !empty($r['ehBf']);
        $resp['obs']  = $r['obs'] ?? '';
        $campItens = [];
        foreach ($r['itens'] as $it) {
            $campItens[] = [
                'codigo'      => $it['codigoAEM'],
                'nome'        => $it['nomeProduto'],
                'qtd'         => $it['qtd'],
                'pct_diretoria' => $it['descDiretoria'] ?? 0,
                'valor_total' => $it['valorTotal'] ?? 0,
            ];
        }
        $av = $resp['ehBf'] ? campanhasAmAvaliarPedido($campItens) : null;
        $expByCod = [];
        if ($av) {
            foreach ($av['campanhas_atingidas'] as $ca) {
                foreach ($ca['itens'] as $ci) $expByCod[$ci['codigo']] = (float)$ca['percentual_esperado'];
            }
        }
        foreach ($resp['itens'] as &$ri) {
            $ri['pctDiretoriaCampanha'] = $resp['ehBf'] ? (float)($expByCod[$ri['codigoAEM']] ?? 0) : null;
        }
        unset($ri);
        $resp['campanhasAtingidas'] = $av ? array_map(function ($ca) {
            return [
                'nome'              => $ca['nome'],
                'criterio'          => $ca['criterio'],
                'unidade'           => $ca['unidade'],
                'agregado'          => $ca['agregado'],
                'percentualEsperado' => $ca['percentual_esperado'],
            ];
        }, $av['campanhas_atingidas']) : [];
        $resp['temForaCampanha'] = $av ? $av['tem_item_fora_campanha'] : false;
    }

    echo json_encode($resp);
    exit;
}

// Confirmação da importação "Importa Pedido" — refaz a busca no A&M no servidor
// (não confia em produto/preço vindos do browser) e cria o pedido novo.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'importar_aem') {
    if (!$isComercial) { flash('danger', 'Acesso restrito ao módulo Comercial.'); header('Location: ' . BASE_URL . '/admin/pedidos.php'); exit; }
    try {
        $r = buscarPedidoAEM($_POST['numero'] ?? '');
        if (!$r['ok']) throw new Exception($r['erro']);

        $clienteId = (int)($_POST['cliente_id'] ?? 0);
        if ($clienteId) {
            $cq = db()->prepare('SELECT id FROM clientes WHERE id = ?');
            $cq->execute([$clienteId]);
            if (!$cq->fetch()) $clienteId = 0;
        }
        if (!$clienteId && $r['clienteCnpj']) {
            $cq = db()->prepare("SELECT id FROM clientes WHERE REPLACE(REPLACE(REPLACE(cnpj,'.',''),'/',''),'-','') = ? LIMIT 1");
            $cq->execute([$r['clienteCnpj']]);
            $clienteId = (int)($cq->fetchColumn() ?: 0);
        }
        if (!$clienteId) throw new Exception('Não foi possível identificar o cliente do pedido — selecione manualmente.');

        // Desconto do Cliente/Canal não é mais digitado no modal — vem sempre do próprio
        // pedido no A&M ("%Descto" = Desconto do Canal; "%Descto ST" = Desconto do Cliente)
        // e atualiza o cadastro do cliente.
        db()->prepare('UPDATE clientes SET desconto_cliente = ?, desconto_canal = ? WHERE id = ?')
            ->execute([$r['descontoClienteAEM'], $r['descontoCanalAEM'], $clienteId]);

        // "Pedido Accademia" (Cadastro de Distribuidores no A&M) define o Canal de Venda
        // do cliente no SisPed: SIM = Distribuidor, NAO = Varejo.
        if ($r['pedidoAccademia'] === 'SIM' || $r['pedidoAccademia'] === 'NAO') {
            $canalNome = $r['pedidoAccademia'] === 'SIM' ? 'Distribuidor' : 'Varejo';
            $cvStmt = db()->prepare('SELECT id FROM canal_venda WHERE canal = ? LIMIT 1');
            $cvStmt->execute([$canalNome]);
            $canalVendaId = $cvStmt->fetchColumn();
            if ($canalVendaId) {
                db()->prepare('UPDATE clientes SET canal_venda_id = ? WHERE id = ?')->execute([$canalVendaId, $clienteId]);
            }
        }

        $moedaCliente = db()->prepare('SELECT moeda FROM clientes WHERE id = ?');
        $moedaCliente->execute([$clienteId]);
        $moedaCliente = $moedaCliente->fetchColumn() ?: 'BRL';
        $colPreco = colPrecoMoeda($moedaCliente, $r['tipoVenda'] === 'bonificacao');
        $competencia = date('Y-m-01');

        // "Importa Pedido BF": quando marcado e o pedido tem "BF" no Obs, substitui o
        // %Diretoria de cada item pelo percentual da faixa de campanha atingida
        // (campanhasAmAvaliarPedido — mesma regra do módulo Análise Financeira).
        // Item que não pertence a nenhuma campanha atingida fica com 0.
        $bfOverrides = null;
        if (($_POST['modo_bf'] ?? '') === '1' && ($_POST['aplicar_campanha'] ?? '') === '1' && !empty($r['ehBf'])) {
            $campItens = [];
            foreach ($r['itens'] as $it) {
                $campItens[] = [
                    'codigo'        => $it['codigoAEM'],
                    'nome'          => $it['nomeProduto'],
                    'qtd'           => $it['qtd'],
                    'pct_diretoria' => $it['descDiretoria'] ?? 0,
                    'valor_total'   => $it['valorTotal'] ?? 0,
                ];
            }
            $av = campanhasAmAvaliarPedido($campItens);
            $bfOverrides = [];
            foreach ($av['campanhas_atingidas'] as $ca) {
                foreach ($ca['itens'] as $ci) $bfOverrides[$ci['codigo']] = (float)$ca['percentual_esperado'];
            }
        }

        $itens = [];
        $naoMapeados = [];
        $semValor = [];
        $semCusto = [];
        foreach ($r['itens'] as $it) {
            $pq = db()->prepare("SELECT id, descricao_pt FROM produtos WHERE codigo_produto = ? AND status = 'ativo' LIMIT 1");
            $pq->execute([$it['codigoAEM']]);
            $prod = $pq->fetch();
            if (!$prod) { $naoMapeados[] = $it['codigoAEM'] . ' - ' . $it['nomeProduto']; continue; }

            if ($r['tipoVenda'] !== 'bonificacao') {
                $pv = db()->prepare("SELECT $colPreco FROM tabela_precos t WHERE t.produto_id = ?");
                $pv->execute([$prod['id']]);
                $preco = $pv->fetchColumn();
                if ($preco === false || $preco === null || (float)$preco <= 0) {
                    $semValor[] = $it['codigoAEM'] . ' - ' . $prod['descricao_pt'];
                }
            }
            $cv = db()->prepare('SELECT custo FROM custos_produtos WHERE produto_id = ? AND competencia = ?');
            $cv->execute([$prod['id'], $competencia]);
            $custo = $cv->fetchColumn();
            if ($custo === false || $custo === null) {
                $semCusto[] = $it['codigoAEM'] . ' - ' . $prod['descricao_pt'];
            }

            $descDiretoria = $it['descDiretoria'] ?? 0;
            if ($bfOverrides !== null) {
                $descDiretoria = $bfOverrides[$it['codigoAEM']] ?? 0.0;
            }
            $itens[] = [
                'produto_id' => (int)$prod['id'], 'qtd' => $it['qtd'],
                'desconto_comercial' => $it['descComercial'] ?? 0,
                'desconto_diretoria' => $descDiretoria,
            ];
        }
        if ($naoMapeados) throw new Exception('Produto(s) não encontrado(s) no SisPed pelo Código A&M: ' . implode('; ', $naoMapeados));
        if ($semValor) throw new Exception('Produto(s) sem Valor cadastrado: ' . implode('; ', $semValor));
        if ($semCusto) throw new Exception('Produto(s) sem Custo cadastrado (competência ' . date('m/Y', strtotime($competencia)) . '): ' . implode('; ', $semCusto));

        // Mantém a mesma descrição já usada dentro do pedido para essa condição
        // (cliente/novo-pedido.php) em vez do texto bruto "00 - A Vista" do A&M.
        $formaPagamentoPedido = $r['isAVista'] ? 'A Vista' : null;
        $res = criarPedidoImportadoAEM($clienteId, $r['tipoVenda'], $itens, $r['numero'], $formaPagamentoPedido, $r['isAVista']);

        // Coluna "Credito Utilizado" do grid de busca do A&M: crédito do cliente já
        // abatido do pedido lá no A&M. Lança essa quantia como concessão de crédito
        // (mesmo mecanismo do módulo "Concessão de Créditos"), já aprovada e já
        // consumida por este pedido, e grava o valor no próprio pedido importado.
        $creditoMsg = '';
        if ($r['creditoUtilizadoAEM'] > 0.001) {
            $descCredito = 'Crédito utilizado no pedido A&M nº ' . $r['numero']
                . ($r['pedidoInterno'] ? ' (Pedido Interno ' . $r['pedidoInterno'] . ')' : '');
            db()->prepare('INSERT INTO creditos (cliente_id, descricao, observacao_interna, valor, data, usuario_id, valor_utilizado) VALUES (?,?,?,?,?,?,?)')
                ->execute([$clienteId, $descCredito, 'Importado automaticamente via Importa Pedido (A&M)', $r['creditoUtilizadoAEM'], date('Y-m-d'), $u['id'], $r['creditoUtilizadoAEM']]);
            $creditoId = (int)db()->lastInsertId();
            db()->prepare('INSERT INTO creditos_logs (credito_id, acao, usuario_nome) VALUES (?,?,?)')
                ->execute([$creditoId, 'aprovado', $u['nome']]);
            db()->prepare('UPDATE pedidos SET credito_utilizado = ? WHERE id = ?')
                ->execute([$r['creditoUtilizadoAEM'], $res['primeiro_id']]);
            $creditoMsg = ' Crédito de ' . number_format($r['creditoUtilizadoAEM'], 2, ',', '.') . ' importado do A&M e registrado em Concessão de Créditos.';
        }

        $bfMsg = $bfOverrides !== null ? ' Descontos de campanha (BF) aplicados no % Diretoria dos itens.' : '';

        // "Importa Pedido BF" com "Gravar no A&M": aplica o % Diretoria da campanha nos itens
        // do próprio pedido no A&M (Consulta/Reimprime > Altera > aba Itens > Validar > Conclui).
        $amMsg = '';
        if ($bfOverrides !== null && ($_POST['gravar_am'] ?? '') === '1') {
            $pctPorCodigo = [];
            foreach ($r['itens'] as $it) $pctPorCodigo[$it['codigoAEM']] = $bfOverrides[$it['codigoAEM']] ?? 0.0;
            $am = aplicarDescontoDiretoriaAEM($_POST['numero'] ?? '', $pctPorCodigo, true);
            try {
                db()->prepare('INSERT INTO descontos_diretoria_am_logs (numero_pedido,sid_ped,pedido_interno,pedido_sisped,usuario_id,usuario_nome,itens,status,mensagem,resposta) VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute([
                        preg_replace('/\D/', '', (string)($_POST['numero'] ?? '')), $am['sid_ped'] ?? null, $am['pedido_interno'] ?? null,
                        $res['primeiro_id'], $u['id'], $u['nome'],
                        json_encode($am['itens'] ?? [], JSON_UNESCAPED_UNICODE), $am['status'] ?? 'erro',
                        $am['erro'] ?: ($am['validacao'] ?? null), mb_substr((string)($am['resposta'] ?? ''), 0, 60000),
                    ]);
            } catch (Exception $eLog) { /* log é best-effort */ }
            $amMsg = $am['ok']
                ? ' % Diretoria gravado no pedido do A&M' . (!empty($am['concluido']) ? ' e "Conclui Pedido" executado.' : ' (sem concluir — conferir no A&M).')
                : ' ATENÇÃO: não foi possível gravar o % Diretoria no A&M — ' . ($am['erro'] ?: 'ver Log de descontos') . '. O pedido no SisPed foi criado normalmente.';
        }

        flash('success', 'Pedido ' . $res['numero_pedido'] . ' importado do A&M com ' . $res['criados'] . ' item(ns)!' . $creditoMsg . $bfMsg . $amMsg);
        header('Location: ' . BASE_URL . '/admin/pedido.php?id=' . $res['primeiro_id']);
        exit;
    } catch (Exception $e) {
        flash('danger', $e->getMessage());
        header('Location: ' . BASE_URL . '/admin/pedidos.php');
        exit;
    }
}

$filtro    = $_GET['status']  ?? '';
$busca_cli = trim($_GET['cli'] ?? '');
$dt_inicio = isset($_GET['dt_ini']) ? $_GET['dt_ini'] : date('Y-m-01');
$dt_fim    = isset($_GET['dt_fim']) ? $_GET['dt_fim'] : date('Y-m-t');
// Tecnologia da Informação atua como Financeiro (acesso total): vê as colunas extras do Financeiro.
$isFinanceiro = in_array($u['tipo'], ['financeiro', 'tecnologia da informacao']);

// Financeiro: ao filtrar por cliente, expande para todos os membros do mesmo grupo de empresas.
// $cliFilterIds = null -> usa LIKE (perfis não-financeiro); array -> usa IN (pode ser vazio).
$cliFilterIds = null;
if ($busca_cli !== '' && $isFinanceiro) {
    $mq = db()->prepare("SELECT id FROM clientes WHERE razao_social LIKE ? OR codigo_cliente LIKE ? OR cnpj LIKE ?");
    $mq->execute(["%$busca_cli%", "%$busca_cli%", "%$busca_cli%"]);
    $matched = array_map('intval', $mq->fetchAll(PDO::FETCH_COLUMN));
    if ($matched) {
        $phm = implode(',', array_fill(0, count($matched), '?'));
        $gq = db()->prepare("SELECT DISTINCT gec2.cliente_id
            FROM grupo_empresas_clientes gec1
            JOIN grupo_empresas_clientes gec2 ON gec2.grupo_id = gec1.grupo_id
            WHERE gec1.cliente_id IN ($phm)");
        $gq->execute($matched);
        $grp = array_map('intval', $gq->fetchAll(PDO::FETCH_COLUMN));
        $cliFilterIds = array_values(array_unique(array_merge($matched, $grp)));
    } else {
        $cliFilterIds = [];
    }
}

// Monta WHERE e HAVING dinâmicos
$where  = '';
$having = '';
$params = [];
$where_parts = [];

if ($dt_inicio) { $where_parts[] = 'DATE(p.data_pedido) >= ?'; $params[] = $dt_inicio; }
if ($dt_fim)    { $where_parts[] = 'DATE(p.data_pedido) <= ?'; $params[] = $dt_fim;    }
if ($u['tipo'] === 'supervisor') { $where_parts[] = 'COALESCE(c.supervisor, c.vendedor) = ?'; $params[] = $u['nome']; }
if ($busca_cli !== '') {
    if ($cliFilterIds !== null) {
        if ($cliFilterIds) {
            $ph = implode(',', array_fill(0, count($cliFilterIds), '?'));
            $where_parts[] = "p.cliente_id IN ($ph)";
            foreach ($cliFilterIds as $cid) $params[] = $cid;
        } else {
            $where_parts[] = '1=0';
        }
    } else {
        $where_parts[] = '(c.razao_social LIKE ? OR c.codigo_cliente LIKE ? OR c.cnpj LIKE ?)';
        $params[] = "%$busca_cli%"; $params[] = "%$busca_cli%"; $params[] = "%$busca_cli%";
    }
}
if ($where_parts) $where = 'WHERE ' . implode(' AND ', $where_parts);
if ($filtro)      { $having = 'HAVING MIN(p.status) = ?'; $params[] = $filtro; }

$pedidos = db()->prepare("
    SELECT g.min_id AS id, pf.numero_pedido,
           pf.data_pedido, g.created_at,
           pf.tipo_venda, g.valor_total, pf.moeda, pf.cotacao,
           g.status, g.num_itens, g.lote_key,
           g.razao_social, g.codigo_cliente, g.cliente_id, g.canal_venda_id, g.supervisor, pf.observacoes
    FROM (
        SELECT MIN(p.id) AS min_id,
               COALESCE(p.lote_id, CAST(p.id AS CHAR)) AS lote_key,
               MAX(p.created_at) AS created_at,
               SUM(p.valor_total) AS valor_total,
               MIN(p.status) AS status,
               COUNT(*) AS num_itens,
               MIN(c.razao_social) AS razao_social,
               MIN(c.codigo_cliente) AS codigo_cliente,
               MIN(p.cliente_id) AS cliente_id,
               MIN(c.canal_venda_id) AS canal_venda_id,
               COALESCE(MIN(p.supervisor), MIN(p.vendedor)) AS supervisor
        FROM pedidos p
        LEFT JOIN clientes c ON c.id = p.cliente_id
        $where
        GROUP BY COALESCE(p.lote_id, CAST(p.id AS CHAR))
        $having
    ) g
    JOIN pedidos pf ON pf.id = g.min_id
    ORDER BY g.created_at DESC");
$pedidos->execute($params);
$pedidos = $pedidos->fetchAll();

// Colunas extras do perfil Financeiro: crédito aplicado, detalhamento fiscal (NF) e "Accademia"
$fiscalMap = [];
if ($isFinanceiro && $pedidos) {
    $loteKeys = array_column($pedidos, 'lote_key');
    $ph = implode(',', array_fill(0, count($loteKeys), '?'));
    $fm = db()->prepare("
        SELECT COALESCE(p.lote_id, CAST(p.id AS CHAR)) AS lote_key,
               SUM(COALESCE(p.credito_utilizado, 0)) AS credito,
               SUM(COALESCE(p.desconto_pagamento, 0)) AS desc_pgto,
               SUM(p.quantidade_total * COALESCE(t.preco_network, 0)
                   * (1 + COALESCE(n.ipi, 0) / 100)) AS nf_total
        FROM pedidos p
        LEFT JOIN produtos pr     ON pr.id = p.produto_id
        LEFT JOIN tabela_precos t ON t.produto_id = pr.id
        LEFT JOIN ncm n           ON n.id = pr.ncm_id
        WHERE COALESCE(p.lote_id, CAST(p.id AS CHAR)) COLLATE utf8mb4_unicode_ci IN ($ph)
        GROUP BY COALESCE(p.lote_id, CAST(p.id AS CHAR)) COLLATE utf8mb4_unicode_ci
    ");
    $fm->execute($loteKeys);
    foreach ($fm->fetchAll() as $r) $fiscalMap[$r['lote_key']] = $r;
}

// Totais por status respeitando o filtro de período
$t_params = [];
$t_where_parts = [];
if ($dt_inicio) { $t_where_parts[] = 'DATE(p.data_pedido) >= ?'; $t_params[] = $dt_inicio; }
if ($dt_fim)    { $t_where_parts[] = 'DATE(p.data_pedido) <= ?'; $t_params[] = $dt_fim;    }
$t_join = '';
if ($u['tipo'] === 'supervisor') {
    $t_join = 'LEFT JOIN clientes c ON c.id = p.cliente_id';
    $t_where_parts[] = 'COALESCE(c.supervisor, c.vendedor) = ?';
    $t_params[] = $u['nome'];
}
if ($busca_cli !== '') {
    if ($cliFilterIds !== null) {
        if ($cliFilterIds) {
            $ph = implode(',', array_fill(0, count($cliFilterIds), '?'));
            $t_where_parts[] = "p.cliente_id IN ($ph)";
            foreach ($cliFilterIds as $cid) $t_params[] = $cid;
        } else {
            $t_where_parts[] = '1=0';
        }
    } else {
        if ($t_join === '') $t_join = 'LEFT JOIN clientes c ON c.id = p.cliente_id';
        $t_where_parts[] = '(c.razao_social LIKE ? OR c.codigo_cliente LIKE ? OR c.cnpj LIKE ?)';
        $t_params[] = "%$busca_cli%"; $t_params[] = "%$busca_cli%"; $t_params[] = "%$busca_cli%";
    }
}
$t_where = $t_where_parts ? 'WHERE ' . implode(' AND ', $t_where_parts) : '';

// Valor de cada pedido convertido para BRL (USD/EUR usam a cotação gravada no pedido)
$valBRLexpr = "p.valor_total * (CASE WHEN p.moeda <> 'BRL' AND p.cotacao > 0 THEN p.cotacao ELSE 1 END)";

$totais_stmt = db()->prepare("
    SELECT status, COUNT(*) AS qtd, SUM(valor_total) AS total
    FROM (
        SELECT MIN(p.status) AS status,
               SUM($valBRLexpr) AS valor_total
        FROM pedidos p
        $t_join
        $t_where
        GROUP BY COALESCE(p.lote_id, CAST(p.id AS CHAR))
    ) g
    GROUP BY status
");
$totais_stmt->execute($t_params);
$totais_rows = $totais_stmt->fetchAll();
$totais = [];
foreach ($totais_rows as $t) $totais[$t['status']] = $t;
$total_geral_qtd = array_sum(array_column($totais_rows, 'qtd'));
$total_geral_val = array_sum(array_column($totais_rows, 'total'));

// Breakdown por tipo_venda (venda / bonificacao) por status
$tipo_stmt = db()->prepare("
    SELECT g.status, g.tipo_venda, SUM(g.valor_total) AS total
    FROM (
        SELECT MIN(p.status) AS status,
               MIN(p.tipo_venda) AS tipo_venda,
               SUM($valBRLexpr) AS valor_total
        FROM pedidos p
        $t_join
        $t_where
        GROUP BY COALESCE(p.lote_id, CAST(p.id AS CHAR))
    ) g
    GROUP BY g.status, g.tipo_venda
");
$tipo_stmt->execute($t_params);
$totais_tipo = []; // [status][tipo_venda] => total
$totais_tipo_geral = []; // [tipo_venda] => total (para o card Total Geral)
foreach ($tipo_stmt->fetchAll() as $tt) {
    $totais_tipo[$tt['status']][$tt['tipo_venda']] = (float)$tt['total'];
    $totais_tipo_geral[$tt['tipo_venda']] = ($totais_tipo_geral[$tt['tipo_venda']] ?? 0) + (float)$tt['total'];
}

// Lista de clientes para o fallback de seleção manual em "Importa Pedido"
// (quando o CNPJ retornado pelo A&M não bate com nenhum cliente cadastrado).
$clientesParaImport = $isComercial
    ? db()->query("SELECT id, codigo_cliente, razao_social FROM clientes WHERE status='ativo' ORDER BY razao_social")->fetchAll()
    : [];

$pageTitle = 'Pedidos';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-list-check me-2"></i>Pedidos</h4>
    <?php if ($isComercial): ?>
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalImportaAEM">
            <i class="bi bi-cloud-download me-1"></i>Importa Pedido
        </button>
        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalImportaAEMBF">
            <i class="bi bi-tags me-1"></i>Importa Pedido BF
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- Cards de resumo -->
<?php
$cardDefs = [
    '' => [
        'label' => 'Total Geral',
        'icon'  => 'bi-list-check',
        'cor'   => 'secondary',
        'qtd'   => $total_geral_qtd,
        'val'   => $total_geral_val,
        'tipo'  => $totais_tipo_geral,
    ],
    'comercial' => [
        'label' => 'Ag. Comercial',
        'icon'  => 'bi-clock-history',
        'cor'   => 'primary',
        'qtd'   => $totais['comercial']['qtd']  ?? 0,
        'val'   => $totais['comercial']['total'] ?? 0,
        'tipo'  => $totais_tipo['comercial'] ?? [],
    ],
    'financeiro' => [
        'label' => 'Ag. Financeiro',
        'icon'  => 'bi-bank',
        'cor'   => 'warning',
        'qtd'   => $totais['financeiro']['qtd']  ?? 0,
        'val'   => $totais['financeiro']['total'] ?? 0,
        'tipo'  => $totais_tipo['financeiro'] ?? [],
    ],
    'faturamento' => [
        'label' => 'Ag. Faturamento',
        'icon'  => 'bi-box-seam',
        'cor'   => 'info',
        'qtd'   => $totais['faturamento']['qtd']  ?? 0,
        'val'   => $totais['faturamento']['total'] ?? 0,
        'tipo'  => $totais_tipo['faturamento'] ?? [],
    ],
    'faturado' => [
        'label' => 'Faturado',
        'icon'  => 'bi-check2-all',
        'cor'   => 'success',
        'qtd'   => $totais['faturado']['qtd']  ?? 0,
        'val'   => $totais['faturado']['total'] ?? 0,
        'tipo'  => $totais_tipo['faturado'] ?? [],
    ],
    'reprovado' => [
        'label' => 'Cancelados',
        'icon'  => 'bi-x-circle',
        'cor'   => 'danger',
        'qtd'   => $totais['reprovado']['qtd']  ?? 0,
        'val'   => $totais['reprovado']['total'] ?? 0,
        'tipo'  => $totais_tipo['reprovado'] ?? [],
    ],
];
?>
<div class="row g-3 mb-4">
<?php foreach ($cardDefs as $val => $cd):
    $ativo = $filtro === $val;
    $borda = $ativo ? "border-{$cd['cor']} border-2" : 'border-0';
?>
    <div class="col-6 col-md">
        <a href="?status=<?= $val ?>&cli=<?= urlencode($busca_cli) ?>&dt_ini=<?= urlencode($dt_inicio) ?>&dt_fim=<?= urlencode($dt_fim) ?>" class="text-decoration-none">
            <div class="card shadow-sm h-100 <?= $borda ?>">
                <div class="card-body py-3 px-3">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <i class="bi <?= $cd['icon'] ?> text-<?= $cd['cor'] ?> fs-5"></i>
                        <span class="small fw-semibold text-muted text-uppercase" style="font-size:.7rem;letter-spacing:.05em"><?= $cd['label'] ?></span>
                    </div>
                    <div class="fw-bold fs-5 text-<?= $cd['cor'] ?>"><?= $cd['qtd'] ?> <span class="fw-normal fs-6 text-muted">pedido<?= $cd['qtd'] != 1 ? 's' : '' ?></span></div>
                    <div class="small text-muted"><?= moedaBR($cd['val']) ?></div>
                    <?php if (!empty($cd['tipo'])): ?>
                    <div class="mt-1 pt-1 border-top d-flex flex-column gap-0" style="font-size:.72rem">
                        <?php if (isset($cd['tipo']['venda'])): ?>
                        <span class="text-muted"><span class="text-primary fw-semibold">Venda:</span> <?= moedaBR($cd['tipo']['venda']) ?></span>
                        <?php endif; ?>
                        <?php if (isset($cd['tipo']['bonificacao'])): ?>
                        <span class="text-muted"><span class="text-success fw-semibold">Bonif.:</span> <?= moedaBR($cd['tipo']['bonificacao']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </a>
    </div>
<?php endforeach; ?>
</div>

<!-- Filtros -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-2 px-3">
        <div class="d-flex flex-wrap align-items-center gap-3">

            <!-- Separador vertical -->
            <div class="vr d-none d-md-block"></div>

            <!-- Filtro de cliente + período -->
            <form method="GET" class="d-flex flex-wrap align-items-center gap-2">
                <?php if ($filtro): ?><input type="hidden" name="status" value="<?= e($filtro) ?>"><?php endif; ?>
                <label class="small fw-semibold text-muted mb-0">Cliente:</label>
                <input type="text" name="cli" class="form-control form-control-sm"
                       style="width:230px" value="<?= e($busca_cli) ?>"
                       placeholder="Nome, código ou CNPJ">
                <div class="vr d-none d-md-block"></div>
                <label class="small fw-semibold text-muted mb-0">Período:</label>
                <input type="date" name="dt_ini" class="form-control form-control-sm"
                       style="width:150px" value="<?= e($dt_inicio) ?>"
                       placeholder="De">
                <span class="text-muted small">até</span>
                <input type="date" name="dt_fim" class="form-control form-control-sm"
                       style="width:150px" value="<?= e($dt_fim) ?>"
                       placeholder="Até">
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-funnel me-1"></i>Filtrar
                </button>
                <?php if ($busca_cli !== '' || $dt_inicio || $dt_fim): ?>
                <a href="?status=<?= e($filtro) ?>" class="btn btn-sm btn-outline-secondary"
                   title="Limpar filtros">
                    <i class="bi bi-x-lg"></i>
                </a>
                <?php endif; ?>
            </form>

        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Nº Pedido</th>
                        <th style="white-space:nowrap">Código</th>
                        <th>Cliente</th>
                        <th>Supervisor</th>
                        <th>Data</th>
                        <th>Tipo</th>
                        <th>Valor</th>
                        <?php if ($isFinanceiro): ?>
                        <th class="text-end">Crédito Aplicado</th>
                        <th class="text-end">Detalhamento Fiscal</th>
                        <th class="text-end">Accademia</th>
                        <th class="text-end">% Desconto</th>
                        <th class="text-end">Valor Desconto</th>
                        <?php endif; ?>
                        <th>Status</th>
                        <th>Observações</th>
                        <th class="text-end pe-3">Ação</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($pedidos): foreach ($pedidos as $p):
                    $s           = $p['status'];
                    $isC         = in_array($u['tipo'], ['comercial', 'tecnologia da informacao']);
                    $isF         = in_array($u['tipo'], ['financeiro', 'tecnologia da informacao']);
                    $isS         = $u['tipo'] === 'supervisor';
                    $canAprovar  = (($isC || $isS) && $s === 'comercial') || ($isF && $s === 'financeiro') || (($isC || $isF) && $s === 'faturamento');
                    $canReprovar = (($isC || $isS) && $s === 'comercial') || ($isF && ($s === 'financeiro' || $s === 'faturamento'));
                    $canRetornar = $isF && ($s === 'financeiro' || $s === 'faturamento');
                    $temAcao     = $canAprovar || $canReprovar || $canRetornar;
                ?>
                <tr>
                    <td class="ps-3">
                        <strong class="text-primary"><?= e($p['numero_pedido']) ?></strong>
                        <?php if ($p['num_itens'] > 1): ?>
                        <span class="badge bg-secondary ms-1"><?= $p['num_itens'] ?> itens</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($p['cliente_id'])): ?>
                        <button type="button"
                                class="badge bg-secondary border-0 btn-cliente-popup"
                                data-id="<?= (int)$p['cliente_id'] ?>"
                                title="Ver cadastro do cliente" style="cursor:pointer">
                            <?= e($p['codigo_cliente'] ?? '—') ?>
                        </button>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($p['razao_social'] ?? '—') ?></td>
                    <td class="text-muted small"><?= e($p['supervisor'] ?: '—') ?></td>
                    <td>
                        <?= dataBR($p['data_pedido']) ?>
                        <?php if (!empty($p['created_at'])): ?>
                        <br><small class="text-muted"><?= date('H:i', strtotime($p['created_at'])) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-<?= $p['tipo_venda'] === 'venda' ? 'primary' : 'info' ?>">
                            <?= ucfirst($p['tipo_venda']) ?>
                        </span>
                    </td>
                    <td class="fw-semibold">
                        <?= moedaBR($p['valor_total'], $p['moeda'] ?? 'BRL') ?>
                        <?php $conv = cotacaoExibicaoPedido($p['moeda'] ?? 'BRL', $p['cotacao'] ?? 0); if ($conv): ?>
                        <div class="text-success small" title="Cotação<?= $conv['fallback'] ? ' atual de referência (pedido sem cotação gravada)' : ' do dia' ?>: R$ <?= number_format($conv['taxa'], 4, ',', '.') ?>">≈ <?= moedaBR((float)$p['valor_total'] * $conv['taxa'], 'BRL') ?><?= $conv['fallback'] ? ' *' : '' ?></div>
                        <?php endif; ?>
                    </td>
                    <?php if ($isFinanceiro):
                        $fisc      = $fiscalMap[$p['lote_key']] ?? ['credito' => 0, 'nf_total' => 0, 'desc_pgto' => 0];
                        $credito   = (float)$fisc['credito'];
                        $nfTotal   = (float)$fisc['nf_total'];
                        $descPgto  = (float)($fisc['desc_pgto'] ?? 0);
                        $accademia = (float)$p['valor_total'] - $nfTotal;
                    ?>
                    <td class="text-end"><?= $credito > 0 ? moedaBR($credito) : '<span class="text-muted">—</span>' ?></td>
                    <td class="text-end"><?= moedaBR($nfTotal) ?></td>
                    <?php // Canal de Exportação e bonificação não têm cálculo de Accademia
                    if ($p['tipo_venda'] === 'bonificacao' || canalEhExportacao((int)($p['canal_venda_id'] ?? 0))): ?>
                    <td class="text-end text-muted">—</td>
                    <?php else: ?>
                    <td class="text-end fw-semibold <?= $accademia < 0 ? 'text-danger' : 'text-success' ?>"><?= moedaBR($accademia) ?></td>
                    <?php endif; ?>
                    <td class="text-end"><?= $descPgto > 0 ? '5%' : '<span class="text-muted">—</span>' ?></td>
                    <td class="text-end"><?= $descPgto > 0 ? '− ' . moedaBR($descPgto) : '<span class="text-muted">—</span>' ?></td>
                    <?php endif; ?>
                    <td><?= statusBadge($p['status']) ?></td>
                    <td style="max-width:220px">
                        <?php if (!empty(trim($p['observacoes'] ?? ''))): ?>
                        <span class="text-muted small" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden"
                              title="<?= e($p['observacoes']) ?>">
                            <?= e($p['observacoes']) ?>
                        </span>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end pe-3">
                        <a href="<?= BASE_URL ?>/admin/pedido.php?id=<?= $p['id'] ?>"
                           class="btn btn-sm btn-outline-primary me-1">
                            <i class="bi bi-eye me-1"></i>Detalhes
                        </a>
                        <?php if ($temAcao): ?>
                        <div class="dropdown d-inline-block">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle"
                                    data-bs-toggle="dropdown" aria-expanded="false">
                                Ações
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                <?php if ($canAprovar): ?>
                                <li>
                                    <form method="POST" action="<?= BASE_URL ?>/admin/pedido.php"
                                          onsubmit="return confirm('Aprovar pedido <?= e($p['numero_pedido']) ?>?')">
                                        <input type="hidden" name="action" value="aprovar">
                                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="_from" value="list">
                                        <input type="hidden" name="_filtro" value="<?= e($filtro) ?>">
                                        <input type="hidden" name="_cli" value="<?= e($busca_cli) ?>">
                                        <input type="hidden" name="_dt_ini" value="<?= e($dt_inicio) ?>">
                                        <input type="hidden" name="_dt_fim" value="<?= e($dt_fim) ?>">
                                        <button class="dropdown-item text-success">
                                            <i class="bi bi-check-circle me-2"></i>
                                            <?= $s === 'faturamento' ? 'Confirmar Faturamento' : ($isF ? 'Aprovar → Faturamento' : 'Aprovar → Financeiro') ?>
                                        </button>
                                    </form>
                                </li>
                                <?php endif; ?>
                                <?php if ($canRetornar): ?>
                                <li>
                                    <form method="POST" action="<?= BASE_URL ?>/admin/pedido.php"
                                          onsubmit="return confirm('Retornar ao Comercial?')">
                                        <input type="hidden" name="action" value="retornar">
                                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="_from" value="list">
                                        <input type="hidden" name="_filtro" value="<?= e($filtro) ?>">
                                        <input type="hidden" name="_cli" value="<?= e($busca_cli) ?>">
                                        <input type="hidden" name="_dt_ini" value="<?= e($dt_inicio) ?>">
                                        <input type="hidden" name="_dt_fim" value="<?= e($dt_fim) ?>">
                                        <button class="dropdown-item text-warning">
                                            <i class="bi bi-arrow-counterclockwise me-2"></i>Retornar ao Comercial
                                        </button>
                                    </form>
                                </li>
                                <?php endif; ?>
                                <?php if ($canReprovar): ?>
                                <li>
                                    <form method="POST" action="<?= BASE_URL ?>/admin/pedido.php"
                                          onsubmit="return confirm('Cancelar pedido <?= e($p['numero_pedido']) ?>?')">
                                        <input type="hidden" name="action" value="reprovar">
                                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="_from" value="list">
                                        <input type="hidden" name="_filtro" value="<?= e($filtro) ?>">
                                        <input type="hidden" name="_cli" value="<?= e($busca_cli) ?>">
                                        <input type="hidden" name="_dt_ini" value="<?= e($dt_inicio) ?>">
                                        <input type="hidden" name="_dt_fim" value="<?= e($dt_fim) ?>">
                                        <button class="dropdown-item text-danger">
                                            <i class="bi bi-x-circle me-2"></i>Cancelar
                                        </button>
                                    </form>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="<?= $isFinanceiro ? 15 : 10 ?>" class="text-center text-muted py-5">
                    <i class="bi bi-inbox display-5 d-block mb-2"></i>Nenhum pedido encontrado.
                </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if (!empty($pedidos)): ?>
    <div class="card-footer bg-white text-muted small py-2 ps-3">
        <?= count($pedidos) ?> pedido(s) encontrado(s).
    </div>
    <?php endif; ?>
</div>

<!-- Modal: Dados do Cliente -->
<div class="modal fade" id="modalCliente" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-person-circle me-2 text-primary"></i><span id="mcTitulo">Dados do Cliente</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="mcBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                    <div class="mt-2 text-muted">Carregando...</div>
                </div>
            </div>
            <div class="modal-footer">
                <a id="mcLinkCadastro" href="#" class="btn btn-outline-primary btn-sm me-auto">
                    <i class="bi bi-box-arrow-up-right me-1"></i>Abrir Cadastro Completo
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Importa Pedido (busca no sistema A&M / Itallian) -->
<div class="modal fade" id="modalImportaAEM" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-cloud-download me-2 text-primary"></i>Importa Pedido</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 align-items-end mb-3">
                    <div class="col">
                        <label class="form-label fw-semibold">Número do Pedido</label>
                        <input type="text" id="aemNumero" class="form-control" maxlength="6"
                               placeholder="Número do pedido lançado no sistema A&amp;M" inputmode="numeric">
                    </div>
                    <div class="col-auto">
                        <button type="button" class="btn btn-primary" id="aemBuscarBtn">
                            <i class="bi bi-search me-1"></i>Buscar
                        </button>
                    </div>
                </div>
                <div id="aemResultado"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                <button type="button" class="btn btn-success" id="aemConfirmarBtn" disabled>
                    <i class="bi bi-check2-circle me-1"></i>Confirmar Importação
                </button>
            </div>
        </div>
    </div>
    <form method="POST" action="<?= BASE_URL ?>/admin/pedidos.php" id="aemForm" style="display:none">
        <input type="hidden" name="action" value="importar_aem">
        <input type="hidden" name="numero" id="aemFormNumero">
        <input type="hidden" name="cliente_id" id="aemFormClienteId">
    </form>
</div>

<!-- Modal: Importa Pedido BF (mesma importação do A&M + descontos de campanha "BF") -->
<div class="modal fade" id="modalImportaAEMBF" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-tags me-2 text-primary"></i>Importa Pedido BF</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 align-items-end mb-3">
                    <div class="col">
                        <label class="form-label fw-semibold">Número do Pedido</label>
                        <input type="text" id="aembfNumero" class="form-control" maxlength="6"
                               placeholder="Número do pedido lançado no sistema A&amp;M" inputmode="numeric">
                    </div>
                    <div class="col-auto">
                        <button type="button" class="btn btn-primary" id="aembfBuscarBtn">
                            <i class="bi bi-search me-1"></i>Buscar
                        </button>
                    </div>
                </div>
                <p class="text-muted small mb-2">
                    Confere o campo <strong>Obs</strong> do pedido no A&amp;M — se começar com <strong>“BF”</strong>,
                    verifica quais campanhas se aplicam e permite gravar o <strong>% Diretoria</strong> de cada item
                    pela faixa de campanha atingida.
                </p>
                <div id="aembfResultado"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                <button type="button" class="btn btn-success" id="aembfConfirmarBtn" disabled>
                    <i class="bi bi-check2-circle me-1"></i>Confirmar Importação
                </button>
            </div>
        </div>
    </div>
    <form method="POST" action="<?= BASE_URL ?>/admin/pedidos.php" id="aembfForm" style="display:none">
        <input type="hidden" name="action" value="importar_aem">
        <input type="hidden" name="modo_bf" value="1">
        <input type="hidden" name="aplicar_campanha" id="aembfFormAplicar" value="0">
        <input type="hidden" name="gravar_am" id="aembfFormGravarAm" value="0">
        <input type="hidden" name="numero" id="aembfFormNumero">
        <input type="hidden" name="cliente_id" id="aembfFormClienteId">
    </form>
</div>

<script>
(function() {
    function campo(label, valor, wide) {
        var v = valor || '—';
        var col = wide ? 'col-md-12' : 'col-md-4';
        return '<div class="' + col + '">'
            + '<div class="text-muted small fw-semibold text-uppercase mb-1">' + label + '</div>'
            + '<div class="form-control bg-light" style="min-height:38px">' + v + '</div>'
            + '</div>';
    }
    function renderCliente(c) {
        var html = '<div class="row g-3">'
            + campo('Código', c.codigo_cliente)
            + campo('CNPJ', c.cnpj)
            + campo('CPF', c.cpf)
            + campo('Status', c.status ? c.status.charAt(0).toUpperCase() + c.status.slice(1) : '—')
            + campo('Razão Social', c.razao_social, true)
            + campo('CEP', c.cep)
            + campo('Endereço', c.endereco, true)
            + campo('Número', c.numero)
            + campo('Complemento', c.complemento)
            + campo('Bairro', c.bairro)
            + campo('Cidade', c.cidade)
            + campo('UF', c.estado)
            + campo('País', c.pais || 'Brasil')
            + campo('Telefone 1', c.telefone1)
            + campo('Telefone 2', c.telefone2)
            + campo('E-mail (login)', c.email)
            + campo('Supervisor', c.supervisor || c.vendedor)
            + campo('Canal de Venda', c.canal)
            + campo('Desconto do Cliente %', c.desconto_cliente)
            + campo('Desconto do Canal %', c.desconto_canal)
            + campo('Bônus Desempenho %', c.bonus_desempenho)
            + campo('Bônus Mat. Apoio %', c.material_apoio)
            + campo('Limite Créd.', c.limite_credito)
            + campo('Idioma', c.idioma ? c.idioma.toUpperCase() : '—')
            + campo('Moeda', c.moeda)
            + '</div>';
        return html;
    }

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-cliente-popup');
        if (!btn) return;
        var id = btn.dataset.id;
        var modal = new bootstrap.Modal(document.getElementById('modalCliente'));
        document.getElementById('mcTitulo').textContent = 'Dados do Cliente';
        document.getElementById('mcBody').innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><div class="mt-2 text-muted">Carregando...</div></div>';
        modal.show();
        fetch('<?= BASE_URL ?>/admin/pedidos.php?ajax_cliente=1&id=' + id)
            .then(function(r) { return r.json(); })
            .then(function(c) {
                if (!c) {
                    document.getElementById('mcBody').innerHTML = '<div class="alert alert-warning">Cliente não encontrado.</div>';
                    return;
                }
                document.getElementById('mcTitulo').textContent = c.razao_social || 'Dados do Cliente';
                document.getElementById('mcBody').innerHTML = renderCliente(c);
                if (c.codigo_cliente) {
                    document.getElementById('mcLinkCadastro').href = '<?= BASE_URL ?>/admin/cadastros/clientes.php?q=' + encodeURIComponent(c.codigo_cliente);
                }
            })
            .catch(function() {
                document.getElementById('mcBody').innerHTML = '<div class="alert alert-danger">Erro ao carregar dados.</div>';
            });
    });
})();
</script>

<?php if ($isComercial): ?>
<script>
(function() {
    var CLIENTES_IMPORT = <?= json_encode(array_map(function ($c) {
        return ['id' => (int)$c['id'], 'label' => '[' . $c['codigo_cliente'] . '] ' . $c['razao_social']];
    }, $clientesParaImport)) ?>;
    var itensAtual = [];
    var clienteIdAtual = null;

    // Desconto do Cliente/Canal vem sempre do próprio pedido no A&M (%Descto ST / %Descto)
    // — apenas informativo aqui, não é mais editável.
    function atualizarCaixaDesconto(descCliente, descCanal) {
        var box = document.getElementById('aemDescontoBox');
        if (!box) return;
        box.innerHTML = '<div class="row g-2 mt-1">'
            + '<div class="col-6"><label class="form-label small mb-0">Desconto do Canal %</label>'
            + '<input type="text" class="form-control form-control-sm" value="' + (parseFloat(descCanal) || 0) + '" readonly disabled></div>'
            + '<div class="col-6"><label class="form-label small mb-0">Desconto do Cliente %</label>'
            + '<input type="text" class="form-control form-control-sm" value="' + (parseFloat(descCliente) || 0) + '" readonly disabled></div>'
            + '</div>';
    }

    function escapeHtml(s) {
        return (s === null || s === undefined ? '' : String(s)).replace(/[&<>"']/g, function(c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    function atualizarConfirmar() {
        var algumProblema = itensAtual.some(function(it) {
            return !it.produtoId || it.temValor === false || it.temCusto === false;
        });
        document.getElementById('aemConfirmarBtn').disabled = !clienteIdAtual || algumProblema || itensAtual.length === 0;
    }

    function renderResultado(d) {
        var el = document.getElementById('aemResultado');
        if (!d.ok) {
            el.innerHTML = '<div class="alert alert-danger">' + escapeHtml(d.erro || 'Erro ao buscar pedido.') + '</div>';
            itensAtual = []; clienteIdAtual = null;
            document.getElementById('aemConfirmarBtn').disabled = true;
            return;
        }
        itensAtual = d.itens;
        clienteIdAtual = d.clienteId;

        var canalAEM = d.pedidoAccademia === 'SIM' ? 'Distribuidor' : (d.pedidoAccademia === 'NAO' ? 'Varejo' : '—');
        var html = '<div class="mb-3">';
        html += '<div class="small text-muted">Pedido Interno A&amp;M: <strong>' + escapeHtml(d.pedidoInterno || '—')
              + '</strong> &middot; Tipo: <strong>' + (d.tipoVenda === 'bonificacao' ? 'Bonificação' : 'Venda')
              + '</strong> &middot; Forma Pagto: <strong>' + escapeHtml(d.formaPagto || '—')
              + '</strong> &middot; Canal (A&amp;M): <strong>' + canalAEM + '</strong></div>';
        if (d.isAVista) {
            html += '<div class="alert alert-info py-2 mb-0 mt-2"><i class="bi bi-percent me-1"></i>'
                  + 'Pagamento à vista — será aplicado desconto de 5% sobre o total do pedido.</div>';
        }
        if (d.creditoUtilizado > 0.001) {
            html += '<div class="alert alert-info py-2 mb-0 mt-2"><i class="bi bi-coin me-1"></i>'
                  + 'Crédito Utilizado no A&amp;M: <strong>' + d.creditoUtilizado.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2})
                  + '</strong> — será lançado como concessão de crédito do cliente ao confirmar.</div>';
        }
        if (d.clienteId) {
            html += '<div class="alert alert-success py-2 mb-0 mt-2"><i class="bi bi-check-circle me-1"></i>Cliente identificado: '
                  + escapeHtml(d.clienteLabel) + '</div>';
        } else {
            html += '<div class="alert alert-warning py-2 mb-2 mt-2"><i class="bi bi-exclamation-triangle me-1"></i>'
                  + 'Cliente do A&amp;M (' + escapeHtml(d.clienteNomeAEM) + ' &mdash; CNPJ ' + escapeHtml(d.clienteCnpjAEM)
                  + ') não encontrado no SisPed. Selecione manualmente:</div>';
            html += '<select class="form-select" id="aemClienteSelect"><option value="">Selecione o cliente...</option>';
            CLIENTES_IMPORT.forEach(function(c) {
                html += '<option value="' + c.id + '">' + escapeHtml(c.label) + '</option>';
            });
            html += '</select>';
        }
        html += '<div id="aemDescontoBox" class="mt-2"></div>';
        html += '</div>';

        html += '<div class="table-responsive"><table class="table table-sm align-middle">'
              + '<thead><tr><th>Código A&amp;M</th><th>Produto (A&amp;M)</th><th class="text-end">Qtd</th>'
              + '<th class="text-end">Desc.Com%</th><th class="text-end">Desc.Dir%</th><th>Produto no SisPed</th></tr></thead><tbody>';
        itensAtual.forEach(function(it) {
            var temProblema = !it.produtoId || it.temValor === false || it.temCusto === false;
            var statusCol;
            if (!it.produtoId) {
                statusCol = '<span class="text-danger fw-semibold">Não mapeado</span>';
            } else {
                statusCol = escapeHtml(it.produtoDesc);
                var avisos = [];
                if (it.temValor === false) avisos.push('sem Valor');
                if (it.temCusto === false) avisos.push('sem Custo');
                if (avisos.length) {
                    statusCol += ' <span class="text-danger fw-semibold">(' + avisos.join(', ') + ')</span>';
                }
            }
            html += '<tr' + (temProblema ? ' class="table-danger"' : '') + '>'
                + '<td>' + escapeHtml(it.codigoAEM) + '</td>'
                + '<td>' + escapeHtml(it.nomeProduto) + '</td>'
                + '<td class="text-end">' + it.qtd + '</td>'
                + '<td class="text-end">' + (it.descComercial || 0) + '</td>'
                + '<td class="text-end">' + (it.descDiretoria || 0) + '</td>'
                + '<td>' + statusCol + '</td>'
                + '</tr>';
        });
        html += '</tbody></table></div>';
        el.innerHTML = html;

        atualizarCaixaDesconto(d.descontoCliente, d.descontoCanal);

        var sel = document.getElementById('aemClienteSelect');
        if (sel) {
            sel.addEventListener('change', function() {
                clienteIdAtual = sel.value ? parseInt(sel.value, 10) : null;
                atualizarConfirmar();
            });
        }
        atualizarConfirmar();
    }

    document.getElementById('aemBuscarBtn').addEventListener('click', function() {
        var numero = document.getElementById('aemNumero').value.trim();
        if (!numero) return;
        document.getElementById('aemResultado').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></div>';
        document.getElementById('aemConfirmarBtn').disabled = true;
        fetch('<?= BASE_URL ?>/admin/pedidos.php?ajax_aem_preview=1&numero=' + encodeURIComponent(numero))
            .then(function(r) { return r.json(); })
            .then(renderResultado)
            .catch(function() {
                document.getElementById('aemResultado').innerHTML = '<div class="alert alert-danger">Erro ao buscar pedido.</div>';
            });
    });

    document.getElementById('aemConfirmarBtn').addEventListener('click', function() {
        if (this.disabled) return;
        document.getElementById('aemFormNumero').value = document.getElementById('aemNumero').value.trim();
        document.getElementById('aemFormClienteId').value = clienteIdAtual || '';
        document.getElementById('aemForm').submit();
    });

    document.getElementById('modalImportaAEM').addEventListener('hidden.bs.modal', function() {
        document.getElementById('aemNumero').value = '';
        document.getElementById('aemResultado').innerHTML = '';
        document.getElementById('aemConfirmarBtn').disabled = true;
        itensAtual = []; clienteIdAtual = null;
    });
})();
</script>

<script>
// "Importa Pedido BF": mesma importação do A&M, mas confere o Obs do pedido ("BF") e
// permite gravar o % Diretoria de cada item pela faixa de campanha atingida.
(function() {
    var CLIENTES_IMPORT = <?= json_encode(array_map(function ($c) {
        return ['id' => (int)$c['id'], 'label' => '[' . $c['codigo_cliente'] . '] ' . $c['razao_social']];
    }, $clientesParaImport)) ?>;
    var bfItens = [];
    var bfClienteId = null;
    var bfData = null;
    var bfAplicar = false;

    function escapeHtml(s) {
        return (s === null || s === undefined ? '' : String(s)).replace(/[&<>"']/g, function(c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }
    function pctBR(v) {
        if (v === null || v === undefined) return '—';
        return (Math.round(parseFloat(v) * 100) / 100).toLocaleString('pt-BR') + '%';
    }
    function temProblema(it) {
        return !it.produtoId || it.temValor === false || it.temCusto === false;
    }
    function atualizarConfirmar() {
        var algum = bfItens.some(temProblema);
        document.getElementById('aembfConfirmarBtn').disabled = !bfClienteId || algum || bfItens.length === 0;
    }

    function render(d) {
        var el = document.getElementById('aembfResultado');
        if (!d.ok) {
            el.innerHTML = '<div class="alert alert-danger">' + escapeHtml(d.erro || 'Erro ao buscar pedido.') + '</div>';
            bfItens = []; bfClienteId = null; bfData = null; bfAplicar = false;
            document.getElementById('aembfConfirmarBtn').disabled = true;
            return;
        }
        bfData = d;
        bfItens = d.itens;
        bfClienteId = d.clienteId;

        var canalAEM = d.pedidoAccademia === 'SIM' ? 'Distribuidor' : (d.pedidoAccademia === 'NAO' ? 'Varejo' : '—');
        var html = '<div class="mb-3">';
        html += '<div class="small text-muted">Pedido Interno A&amp;M: <strong>' + escapeHtml(d.pedidoInterno || '—')
              + '</strong> &middot; Tipo: <strong>' + (d.tipoVenda === 'bonificacao' ? 'Bonificação' : 'Venda')
              + '</strong> &middot; Forma Pagto: <strong>' + escapeHtml(d.formaPagto || '—')
              + '</strong> &middot; Canal (A&amp;M): <strong>' + canalAEM + '</strong></div>';
        if (d.isAVista) {
            html += '<div class="alert alert-info py-2 mb-0 mt-2"><i class="bi bi-percent me-1"></i>'
                  + 'Pagamento à vista — será aplicado desconto de 5% sobre o total do pedido.</div>';
        }
        if (d.creditoUtilizado > 0.001) {
            html += '<div class="alert alert-info py-2 mb-0 mt-2"><i class="bi bi-coin me-1"></i>'
                  + 'Crédito Utilizado no A&amp;M: <strong>' + d.creditoUtilizado.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2})
                  + '</strong> — será lançado como concessão de crédito do cliente ao confirmar.</div>';
        }
        if (d.clienteId) {
            html += '<div class="alert alert-success py-2 mb-0 mt-2"><i class="bi bi-check-circle me-1"></i>Cliente identificado: '
                  + escapeHtml(d.clienteLabel) + '</div>';
        } else {
            html += '<div class="alert alert-warning py-2 mb-2 mt-2"><i class="bi bi-exclamation-triangle me-1"></i>'
                  + 'Cliente do A&amp;M (' + escapeHtml(d.clienteNomeAEM) + ' &mdash; CNPJ ' + escapeHtml(d.clienteCnpjAEM)
                  + ') não encontrado no SisPed. Selecione manualmente:</div>';
            html += '<select class="form-select" id="aembfClienteSelect"><option value="">Selecione o cliente...</option>';
            CLIENTES_IMPORT.forEach(function(c) {
                html += '<option value="' + c.id + '">' + escapeHtml(c.label) + '</option>';
            });
            html += '</select>';
        }
        html += '</div>';

        // Painel BF / campanhas
        if (d.ehBf === false) {
            html += '<div class="alert alert-warning py-2"><i class="bi bi-info-circle me-1"></i>'
                  + 'Obs do pedido' + (d.obs ? ': “' + escapeHtml(d.obs) + '”' : ' vazio') + ' — não começa com “BF”. '
                  + 'Será importado <strong>sem</strong> desconto de campanha.</div>';
        } else {
            html += '<div class="alert alert-info py-2"><i class="bi bi-tags me-1"></i>'
                  + 'Pedido de campanha (Obs: “' + escapeHtml(d.obs || 'BF') + '”).</div>';
            var camps = d.campanhasAtingidas || [];
            if (camps.length) {
                html += '<div class="table-responsive mb-2"><table class="table table-sm mb-1">'
                      + '<thead class="table-light"><tr><th>Campanha</th><th>Critério</th><th class="text-end">Atingido</th>'
                      + '<th class="text-end">% Diretoria esperado</th></tr></thead><tbody>';
                camps.forEach(function(c) {
                    var atg = c.criterio === 'valor'
                        ? parseFloat(c.agregado || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2})
                        : (parseInt(c.agregado || 0, 10) + ' ' + escapeHtml(c.unidade || ''));
                    html += '<tr><td>' + escapeHtml(c.nome) + '</td>'
                          + '<td>' + (c.criterio === 'valor' ? 'Valor' : 'Quantidade') + '</td>'
                          + '<td class="text-end">' + atg + '</td>'
                          + '<td class="text-end fw-semibold ' + (parseFloat(c.percentualEsperado) > 0 ? 'text-success' : 'text-muted') + '">'
                          + pctBR(c.percentualEsperado) + '</td></tr>';
                });
                html += '</tbody></table></div>';
            } else {
                html += '<div class="small text-muted mb-2">Nenhuma faixa de campanha atingida pelos itens deste pedido — '
                      + 'aplicar zera o % Diretoria de todos os itens.</div>';
            }
            html += '<button type="button" class="btn btn-sm ' + (bfAplicar ? 'btn-success' : 'btn-outline-primary') + '" id="aembfAplicarBtn"'
                  + (bfAplicar ? ' disabled' : '') + '>'
                  + (bfAplicar ? '<i class="bi bi-check2 me-1"></i>Descontos de campanha aplicados' : '<i class="bi bi-percent me-1"></i>Aplicar descontos de campanha no % Diretoria')
                  + '</button>';
            html += '<div class="form-text mb-2">Substitui o % Diretoria de cada item pelo percentual da faixa de campanha atingida (itens fora de campanha ficam com 0). O valor é recalculado no servidor ao confirmar.</div>';
            if (bfAplicar) {
                html += '<div class="form-check mb-2">'
                      + '<input class="form-check-input" type="checkbox" id="aembfGravarAm" checked>'
                      + '<label class="form-check-label small" for="aembfGravarAm">'
                      + 'Gravar o % Diretoria também no pedido do A&amp;M (Consulta/Reimprime → Altera → Itens → Validar → <strong>Conclui Pedido</strong>).'
                      + '</label>'
                      + '<div class="form-text text-danger">Grava de verdade no A&amp;M e conclui o pedido. Registrado no log de descontos.</div>'
                      + '</div>';
            }
        }

        // Tabela de itens
        html += '<div class="table-responsive"><table class="table table-sm align-middle">'
              + '<thead><tr><th>Código A&amp;M</th><th>Produto (A&amp;M)</th><th class="text-end">Qtd</th>'
              + '<th class="text-end">Desc.Com%</th><th class="text-end">% Diretoria (A&amp;M)</th>'
              + '<th class="text-end">% Diretoria campanha</th><th>Produto no SisPed</th></tr></thead><tbody>';
        bfItens.forEach(function(it) {
            var statusCol;
            if (!it.produtoId) {
                statusCol = '<span class="text-danger fw-semibold">Não mapeado</span>';
            } else {
                statusCol = escapeHtml(it.produtoDesc);
                var avisos = [];
                if (it.temValor === false) avisos.push('sem Valor');
                if (it.temCusto === false) avisos.push('sem Custo');
                if (avisos.length) statusCol += ' <span class="text-danger fw-semibold">(' + avisos.join(', ') + ')</span>';
            }
            var campCell = (it.pctDiretoriaCampanha === null || it.pctDiretoriaCampanha === undefined)
                ? '<span class="text-muted">—</span>'
                : pctBR(it.pctDiretoriaCampanha);
            html += '<tr' + (temProblema(it) ? ' class="table-danger"' : (bfAplicar ? ' class="table-warning"' : '')) + '>'
                + '<td>' + escapeHtml(it.codigoAEM) + '</td>'
                + '<td>' + escapeHtml(it.nomeProduto) + '</td>'
                + '<td class="text-end">' + it.qtd + '</td>'
                + '<td class="text-end">' + (it.descComercial || 0) + '</td>'
                + '<td class="text-end">' + pctBR(it.descDiretoria || 0) + '</td>'
                + '<td class="text-end ' + (bfAplicar ? 'fw-bold text-success' : '') + '">' + campCell + '</td>'
                + '<td>' + statusCol + '</td>'
                + '</tr>';
        });
        html += '</tbody></table></div>';
        el.innerHTML = html;

        var sel = document.getElementById('aembfClienteSelect');
        if (sel) {
            sel.addEventListener('change', function() {
                bfClienteId = sel.value ? parseInt(sel.value, 10) : null;
                atualizarConfirmar();
            });
        }
        var apl = document.getElementById('aembfAplicarBtn');
        if (apl) {
            apl.addEventListener('click', function() {
                bfAplicar = true;
                render(bfData);
            });
        }
        atualizarConfirmar();
    }

    document.getElementById('aembfBuscarBtn').addEventListener('click', function() {
        var numero = document.getElementById('aembfNumero').value.trim();
        if (!numero) return;
        bfAplicar = false;
        document.getElementById('aembfResultado').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></div>';
        document.getElementById('aembfConfirmarBtn').disabled = true;
        fetch('<?= BASE_URL ?>/admin/pedidos.php?ajax_aem_preview=1&bf=1&numero=' + encodeURIComponent(numero))
            .then(function(r) { return r.json(); })
            .then(render)
            .catch(function() {
                document.getElementById('aembfResultado').innerHTML = '<div class="alert alert-danger">Erro ao buscar pedido.</div>';
            });
    });

    document.getElementById('aembfConfirmarBtn').addEventListener('click', function() {
        if (this.disabled) return;
        document.getElementById('aembfFormNumero').value = document.getElementById('aembfNumero').value.trim();
        document.getElementById('aembfFormClienteId').value = bfClienteId || '';
        var aplicar = (bfAplicar && bfData && bfData.ehBf);
        document.getElementById('aembfFormAplicar').value = aplicar ? '1' : '0';
        var gAm = document.getElementById('aembfGravarAm');
        document.getElementById('aembfFormGravarAm').value = (aplicar && gAm && gAm.checked) ? '1' : '0';
        if (aplicar && gAm && gAm.checked &&
            !confirm('Isto vai gravar o % Diretoria nos itens do pedido no sistema A&M e concluir o pedido. Confirmar?')) return;
        document.getElementById('aembfForm').submit();
    });

    document.getElementById('modalImportaAEMBF').addEventListener('hidden.bs.modal', function() {
        document.getElementById('aembfNumero').value = '';
        document.getElementById('aembfResultado').innerHTML = '';
        document.getElementById('aembfConfirmarBtn').disabled = true;
        bfItens = []; bfClienteId = null; bfData = null; bfAplicar = false;
    });
})();
</script>
<?php endif; ?>

<?php require_once LAYOUT_PATH . '/footer.php'; ?>
