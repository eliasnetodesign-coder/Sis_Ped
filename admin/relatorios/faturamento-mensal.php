<?php
require_once __DIR__ . '/../../config.php';
requireComercial();

$ano = (int)($_GET['ano'] ?? date('Y'));

$rows = db()->prepare("SELECT MONTH(created_at) AS mes, MONTHNAME(created_at) AS mes_nome,
    COUNT(*) AS pedidos, COALESCE(SUM(valor_total),0) AS valor,
    COUNT(DISTINCT cliente_id) AS clientes
FROM pedidos WHERE status='faturado' AND YEAR(created_at) = ?
GROUP BY mes ORDER BY mes");
$rows->execute([$ano]);
$rows = $rows->fetchAll();

$total = array_sum(array_column($rows, 'valor'));
$meses_pt = ['','Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];

$pageTitle = 'Faturamento Mensal';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-calendar-month me-2"></i>Faturamento Mensal</h4>
</div>
<form class="card shadow-sm border-0 mb-4 p-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label fw-semibold small">Ano</label>
            <select name="ano" class="form-select form-select-sm">
                <?php for ($y = date('Y'); $y >= date('Y')-5; $y--): ?>
                <option value="<?= $y ?>" <?= $y==$ano?'selected':'' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Filtrar</button></div>
        <div class="col-md-7 text-end"><span class="fw-bold text-success">Total <?= $ano ?>: <?= moedaBR($total) ?></span></div>
    </div>
</form>
<div class="card shadow-sm border-0"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
    <thead class="table-light"><tr><th>Mês</th><th>Pedidos</th><th>Clientes</th><th>Valor Faturado</th><th>% do Total</th></tr></thead>
    <tbody>
    <?php if ($rows): foreach ($rows as $r): ?>
        <tr>
            <td><strong><?= $meses_pt[$r['mes']] . ' / ' . $ano ?></strong></td>
            <td><?= $r['pedidos'] ?></td>
            <td><?= $r['clientes'] ?></td>
            <td class="fw-semibold"><?= moedaBR($r['valor']) ?></td>
            <td>
                <div class="d-flex align-items-center gap-2">
                    <div class="progress flex-grow-1" style="height:6px">
                        <div class="progress-bar bg-success" style="width:<?= $total>0?round($r['valor']/$total*100):0 ?>%"></div>
                    </div>
                    <span class="small"><?= $total>0?round($r['valor']/$total*100):0 ?>%</span>
                </div>
            </td>
        </tr>
    <?php endforeach; else: ?><tr><td colspan="5" class="text-center text-muted py-4">Nenhum faturamento em <?= $ano ?>.</td></tr><?php endif; ?>
    </tbody>
    <?php if ($rows): ?>
    <tfoot class="table-light"><tr><th>Total</th><th colspan="2"><?= array_sum(array_column($rows,'pedidos')) ?> pedidos</th><th><?= moedaBR($total) ?></th><th>100%</th></tr></tfoot>
    <?php endif; ?>
</table></div></div></div>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
