<?php
require_once __DIR__ . '/../config.php';
requireAdmin();
$u = usuario();

$d_join  = '';
$d_where = '';
$d_params = [];
if ($u['tipo'] === 'vendedor') {
    $d_join  = 'LEFT JOIN clientes c ON c.id = p.cliente_id';
    $d_where = 'WHERE c.vendedor = ?';
    $d_params[] = $u['nome'];
}

// Totais agrupados por lote (mesmo modelo de pedidos.php)
$totais_stmt = db()->prepare("
    SELECT status, COUNT(*) AS qtd, SUM(valor_total) AS total
    FROM (
        SELECT MIN(p.status) AS status, SUM(p.valor_total) AS valor_total
        FROM pedidos p $d_join $d_where
        GROUP BY COALESCE(p.lote_id, CAST(p.id AS CHAR))
    ) g
    GROUP BY status
");
$totais_stmt->execute($d_params);
$totais_rows = $totais_stmt->fetchAll();
$totais = [];
foreach ($totais_rows as $t) $totais[$t['status']] = $t;
$total_geral_qtd = array_sum(array_column($totais_rows, 'qtd'));
$total_geral_val = array_sum(array_column($totais_rows, 'total'));

// Breakdown venda/bonificação por status
$tipo_stmt = db()->prepare("
    SELECT g.status, g.tipo_venda, SUM(g.valor_total) AS total
    FROM (
        SELECT MIN(p.status) AS status, MIN(p.tipo_venda) AS tipo_venda, SUM(p.valor_total) AS valor_total
        FROM pedidos p $d_join $d_where
        GROUP BY COALESCE(p.lote_id, CAST(p.id AS CHAR))
    ) g
    GROUP BY g.status, g.tipo_venda
");
$tipo_stmt->execute($d_params);
$totais_tipo = [];
$totais_tipo_geral = [];
foreach ($tipo_stmt->fetchAll() as $tt) {
    $totais_tipo[$tt['status']][$tt['tipo_venda']] = (float)$tt['total'];
    $totais_tipo_geral[$tt['tipo_venda']] = ($totais_tipo_geral[$tt['tipo_venda']] ?? 0) + (float)$tt['total'];
}

// Últimos pedidos
if ($u['tipo'] === 'vendedor') {
    $recentes = db()->prepare('SELECT p.*, c.razao_social FROM pedidos p
        LEFT JOIN clientes c ON c.id = p.cliente_id
        WHERE c.vendedor = ? ORDER BY p.created_at DESC LIMIT 10');
    $recentes->execute([$u['nome']]);
    $recentes = $recentes->fetchAll();
    $emComercial = 0;
} else {
    $recentes = db()->query('SELECT p.*, c.razao_social FROM pedidos p
        LEFT JOIN clientes c ON c.id = p.cliente_id
        ORDER BY p.created_at DESC LIMIT 10')->fetchAll();
    $emComercial = db()->query('SELECT COUNT(*) FROM pedidos WHERE status = "comercial"')->fetchColumn();
}

$pageTitle = 'Dashboard Admin';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-0">Dashboard</h4>
        <p class="text-muted small mb-0"><?= ucfirst($u['tipo']) ?> — <?= e($u['nome']) ?></p>
    </div>
    <?php if ($emComercial > 0): ?>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/admin/pedidos.php?status=comercial" class="btn btn-primary btn-sm">
            <i class="bi bi-inbox me-1"></i><?= $emComercial ?> Aguardando Comercial
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Resumo de pedidos -->
<?php
$cardDefs = [
    ''           => ['label'=>'Total Geral',     'icon'=>'bi-list-check',    'cor'=>'secondary', 'qtd'=>$total_geral_qtd,                    'val'=>$total_geral_val,                    'tipo'=>$totais_tipo_geral],
    'comercial'  => ['label'=>'Ag. Comercial',   'icon'=>'bi-clock-history', 'cor'=>'primary',   'qtd'=>$totais['comercial']['qtd']  ?? 0,   'val'=>$totais['comercial']['total']  ?? 0, 'tipo'=>$totais_tipo['comercial']  ?? []],
    'financeiro' => ['label'=>'Ag. Financeiro',  'icon'=>'bi-bank',          'cor'=>'warning',   'qtd'=>$totais['financeiro']['qtd'] ?? 0,   'val'=>$totais['financeiro']['total'] ?? 0, 'tipo'=>$totais_tipo['financeiro'] ?? []],
    'faturado'   => ['label'=>'Ag. Faturamento', 'icon'=>'bi-box-seam',      'cor'=>'success',   'qtd'=>$totais['faturado']['qtd']   ?? 0,   'val'=>$totais['faturado']['total']   ?? 0, 'tipo'=>$totais_tipo['faturado']   ?? []],
    'reprovado'  => ['label'=>'Reprovados',      'icon'=>'bi-x-circle',      'cor'=>'danger',    'qtd'=>$totais['reprovado']['qtd']  ?? 0,   'val'=>$totais['reprovado']['total']  ?? 0, 'tipo'=>$totais_tipo['reprovado']  ?? []],
];
?>
<div class="row g-3 mb-4">
<?php foreach ($cardDefs as $val => $cd): ?>
    <div class="col-6 col-md">
        <a href="<?= BASE_URL ?>/admin/pedidos.php<?= $val ? '?status='.$val : '' ?>" class="text-decoration-none">
            <div class="card shadow-sm border-0 h-100">
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

<!-- Últimos pedidos -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-list-check me-2"></i>Últimos Pedidos</span>
        <a href="<?= BASE_URL ?>/admin/pedidos.php" class="btn btn-sm btn-outline-primary">Ver todos</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Nº Pedido</th>
                    <th>Cliente</th>
                    <th>Produto</th>
                    <th>Data</th>
                    <th>Valor</th>
                    <th>Status</th>
                    <?php if (in_array($u['tipo'], ['comercial','financeiro'])): ?>
                    <th>Ação</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recentes as $p): ?>
                <tr>
                    <td><strong><?= e($p['numero_pedido']) ?></strong></td>
                    <td><?= e($p['razao_social'] ?? '—') ?></td>
                    <td class="text-truncate" style="max-width:180px"><?= e($p['descricao_produto'] ?? '—') ?></td>
                    <td><?= dataBR($p['data_pedido']) ?></td>
                    <td><?= moedaBR($p['valor_total']) ?></td>
                    <td><?= statusBadge($p['status']) ?></td>
                    <?php if (in_array($u['tipo'], ['comercial','financeiro'])): ?>
                    <td>
                        <a href="<?= BASE_URL ?>/admin/pedidos.php?id=<?= $p['id'] ?>" class="btn btn-xs btn-outline-primary" style="padding:.2rem .5rem;font-size:.75rem">
                            <i class="bi bi-eye"></i>
                        </a>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
