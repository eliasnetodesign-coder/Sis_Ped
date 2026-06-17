<?php
require_once __DIR__ . '/../../config.php';
requireComercial();

$data_ini = $_GET['data_ini'] ?? date('Y-m-01');
$data_fim = $_GET['data_fim'] ?? date('Y-m-d');

$rows = db()->prepare("SELECT DATE(created_at) AS dia,
    COUNT(*) AS pedidos,
    COALESCE(SUM(valor_total * (CASE WHEN moeda <> 'BRL' AND cotacao > 0 THEN cotacao ELSE 1 END)),0) AS valor,
    COUNT(DISTINCT cliente_id) AS clientes
FROM pedidos
WHERE status = 'faturado' AND DATE(created_at) BETWEEN ? AND ?
GROUP BY dia ORDER BY dia DESC");
$rows->execute([$data_ini, $data_fim]);
$rows = $rows->fetchAll();

$total = array_sum(array_column($rows, 'valor'));
$pageTitle = 'Faturamento Diário';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-calendar-day me-2"></i>Faturamento Diário</h4>
</div>
<form class="card shadow-sm border-0 mb-4 p-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label fw-semibold small">Data Inicial</label><input type="date" name="data_ini" class="form-control form-control-sm" value="<?= e($data_ini) ?>"></div>
        <div class="col-md-3"><label class="form-label fw-semibold small">Data Final</label><input type="date" name="data_fim" class="form-control form-control-sm" value="<?= e($data_fim) ?>"></div>
        <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Filtrar</button></div>
        <div class="col-md-4 text-end"><span class="fw-bold text-success">Total: <?= moedaBR($total) ?></span></div>
    </div>
</form>
<div class="card shadow-sm border-0"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
    <thead class="table-light"><tr><th>Data</th><th>Pedidos Faturados</th><th>Clientes</th><th>Valor</th></tr></thead>
    <tbody>
    <?php if ($rows): foreach ($rows as $r): ?>
        <tr>
            <td><?= dataBR($r['dia']) ?></td>
            <td><?= $r['pedidos'] ?></td>
            <td><?= $r['clientes'] ?></td>
            <td class="fw-semibold"><?= moedaBR($r['valor']) ?></td>
        </tr>
    <?php endforeach; else: ?><tr><td colspan="4" class="text-center text-muted py-4">Nenhum faturamento no período.</td></tr><?php endif; ?>
    </tbody>
    <?php if ($rows): ?>
    <tfoot class="table-light"><tr><th colspan="3">Total</th><th><?= moedaBR($total) ?></th></tr></tfoot>
    <?php endif; ?>
</table></div></div></div>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
