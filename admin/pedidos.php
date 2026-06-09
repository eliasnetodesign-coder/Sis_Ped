<?php
require_once __DIR__ . '/../config.php';
requireAdmin();
$u = usuario();

$filtro    = $_GET['status']  ?? '';
$dt_inicio = isset($_GET['dt_ini']) ? $_GET['dt_ini'] : date('Y-m-01');
$dt_fim    = isset($_GET['dt_fim']) ? $_GET['dt_fim'] : date('Y-m-t');

// Monta WHERE e HAVING dinâmicos
$where  = '';
$having = '';
$params = [];
$where_parts = [];

if ($dt_inicio) { $where_parts[] = 'DATE(p.data_pedido) >= ?'; $params[] = $dt_inicio; }
if ($dt_fim)    { $where_parts[] = 'DATE(p.data_pedido) <= ?'; $params[] = $dt_fim;    }
if ($u['tipo'] === 'vendedor') { $where_parts[] = 'c.vendedor = ?'; $params[] = $u['nome']; }
if ($where_parts) $where = 'WHERE ' . implode(' AND ', $where_parts);
if ($filtro)      { $having = 'HAVING MIN(p.status) = ?'; $params[] = $filtro; }

$pedidos = db()->prepare("
    SELECT g.min_id AS id, pf.numero_pedido,
           pf.data_pedido, g.created_at,
           pf.tipo_venda, g.valor_total,
           g.status, g.num_itens, g.lote_key,
           g.razao_social, pf.observacoes
    FROM (
        SELECT MIN(p.id) AS min_id,
               COALESCE(p.lote_id, CAST(p.id AS CHAR)) AS lote_key,
               MAX(p.created_at) AS created_at,
               SUM(p.valor_total) AS valor_total,
               MIN(p.status) AS status,
               COUNT(*) AS num_itens,
               MIN(c.razao_social) AS razao_social
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

// Totais por status respeitando o filtro de período
$t_params = [];
$t_where_parts = [];
if ($dt_inicio) { $t_where_parts[] = 'DATE(p.data_pedido) >= ?'; $t_params[] = $dt_inicio; }
if ($dt_fim)    { $t_where_parts[] = 'DATE(p.data_pedido) <= ?'; $t_params[] = $dt_fim;    }
$t_join = '';
if ($u['tipo'] === 'vendedor') {
    $t_join = 'LEFT JOIN clientes c ON c.id = p.cliente_id';
    $t_where_parts[] = 'c.vendedor = ?';
    $t_params[] = $u['nome'];
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
    'faturado' => [
        'label' => 'Ag. Faturamento',
        'icon'  => 'bi-box-seam',
        'cor'   => 'success',
        'qtd'   => $totais['faturado']['qtd']  ?? 0,
        'val'   => $totais['faturado']['total'] ?? 0,
        'tipo'  => $totais_tipo['faturado'] ?? [],
    ],
    'reprovado' => [
        'label' => 'Reprovados',
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
        <a href="?status=<?= $val ?>" class="text-decoration-none">
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

            <!-- Botões de status -->
            <div class="d-flex flex-wrap gap-2">
                <?php
                $filtros = [''=>'Todos','comercial'=>'Aguardando Comercial','financeiro'=>'Aguardando Financeiro','faturado'=>'Aguardando Faturamento','reprovado'=>'Reprovado'];
                $cores   = [''=>'secondary','comercial'=>'primary','financeiro'=>'warning','faturado'=>'success','reprovado'=>'danger'];
                foreach ($filtros as $val => $label):
                    $ativo = $filtro === $val;
                    $cls   = $ativo ? 'btn-' . $cores[$val] : 'btn-outline-secondary';
                    $href  = http_build_query(array_filter(['status'=>$val,'dt_ini'=>$dt_inicio,'dt_fim'=>$dt_fim]));
                ?>
                <a href="?<?= $href ?>" class="btn btn-sm <?= $cls ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </div>

            <!-- Separador vertical -->
            <div class="vr d-none d-md-block"></div>

            <!-- Filtro de período -->
            <form method="GET" class="d-flex flex-wrap align-items-center gap-2">
                <?php if ($filtro): ?><input type="hidden" name="status" value="<?= e($filtro) ?>"><?php endif; ?>
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
                <?php if ($dt_inicio || $dt_fim): ?>
                <a href="?status=<?= e($filtro) ?>&dt_ini=&dt_fim=" class="btn btn-sm btn-outline-secondary"
                   title="Limpar período">
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
                        <th>Cliente</th>
                        <th>Data</th>
                        <th>Tipo</th>
                        <th>Valor</th>
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
                    $canAprovar  = ($isC && $s === 'comercial') || ($isF && $s === 'financeiro');
                    $canReprovar = $canAprovar;
                    $canRetornar = $isF && $s === 'financeiro';
                    $temAcao     = $canAprovar || $canRetornar;
                ?>
                <tr>
                    <td class="ps-3">
                        <strong class="text-primary"><?= e($p['numero_pedido']) ?></strong>
                        <?php if ($p['num_itens'] > 1): ?>
                        <span class="badge bg-secondary ms-1"><?= $p['num_itens'] ?> itens</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($p['razao_social'] ?? '—') ?></td>
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
                                        <button class="dropdown-item text-success">
                                            <i class="bi bi-check-circle me-2"></i>
                                            <?= $isF ? 'Aprovar' : 'Aprovar → Financeiro' ?>
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
                                        <button class="dropdown-item text-warning">
                                            <i class="bi bi-arrow-counterclockwise me-2"></i>Retornar ao Comercial
                                        </button>
                                    </form>
                                </li>
                                <?php endif; ?>
                                <?php if ($canReprovar): ?>
                                <li>
                                    <form method="POST" action="<?= BASE_URL ?>/admin/pedido.php"
                                          onsubmit="return confirm('Reprovar pedido <?= e($p['numero_pedido']) ?>?')">
                                        <input type="hidden" name="action" value="reprovar">
                                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="_from" value="list">
                                        <button class="dropdown-item text-danger">
                                            <i class="bi bi-x-circle me-2"></i>Reprovar
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
                <tr><td colspan="8" class="text-center text-muted py-5">
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

<?php require_once LAYOUT_PATH . '/footer.php'; ?>
