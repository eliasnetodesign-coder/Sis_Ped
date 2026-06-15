<?php
require_once __DIR__ . '/../config.php';
requireAdmin();
$u = usuario();

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

$filtro    = $_GET['status']  ?? '';
$busca_cli = trim($_GET['cli'] ?? '');
$dt_inicio = isset($_GET['dt_ini']) ? $_GET['dt_ini'] : date('Y-m-01');
$dt_fim    = isset($_GET['dt_fim']) ? $_GET['dt_fim'] : date('Y-m-t');
$isFinanceiro = $u['tipo'] === 'financeiro';

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
           pf.tipo_venda, g.valor_total,
           g.status, g.num_itens, g.lote_key,
           g.razao_social, g.codigo_cliente, g.cliente_id, g.supervisor, pf.observacoes
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

$totais_stmt = db()->prepare("
    SELECT status, COUNT(*) AS qtd, SUM(valor_total) AS total
    FROM (
        SELECT MIN(p.status) AS status,
               SUM(p.valor_total) AS valor_total
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
               SUM(p.valor_total) AS valor_total
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

$pageTitle = 'Pedidos';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-list-check me-2"></i>Pedidos</h4>
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
                    $isC         = $u['tipo'] === 'comercial';
                    $isF         = $u['tipo'] === 'financeiro';
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
                    <td class="fw-semibold"><?= moedaBR($p['valor_total']) ?></td>
                    <?php if ($isFinanceiro):
                        $fisc      = $fiscalMap[$p['lote_key']] ?? ['credito' => 0, 'nf_total' => 0, 'desc_pgto' => 0];
                        $credito   = (float)$fisc['credito'];
                        $nfTotal   = (float)$fisc['nf_total'];
                        $descPgto  = (float)($fisc['desc_pgto'] ?? 0);
                        $accademia = (float)$p['valor_total'] - $nfTotal;
                    ?>
                    <td class="text-end"><?= $credito > 0 ? moedaBR($credito) : '<span class="text-muted">—</span>' ?></td>
                    <td class="text-end"><?= moedaBR($nfTotal) ?></td>
                    <?php if ($p['tipo_venda'] === 'bonificacao'): ?>
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

<?php require_once LAYOUT_PATH . '/footer.php'; ?>
