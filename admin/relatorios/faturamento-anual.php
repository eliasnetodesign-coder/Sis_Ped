<?php
require_once __DIR__ . '/../../config.php';
requireComercial();

$rows = db()->query("SELECT YEAR(created_at) AS ano,
    COUNT(*) AS pedidos, COALESCE(SUM(valor_total),0) AS valor,
    COUNT(DISTINCT cliente_id) AS clientes
FROM pedidos WHERE status='faturado'
GROUP BY ano ORDER BY ano DESC")->fetchAll();

$total = array_sum(array_column($rows, 'valor'));
$pageTitle = 'Faturamento Anual';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-calendar me-2"></i>Faturamento Anual</h4>
    <p class="text-muted small">Evolução do faturamento por ano (pedidos faturados)</p>
</div>
<div class="card shadow-sm border-0"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
    <thead class="table-light"><tr><th>Ano</th><th>Pedidos</th><th>Clientes</th><th>Valor Faturado</th><th>Participação</th></tr></thead>
    <tbody>
    <?php if ($rows): foreach ($rows as $r): ?>
        <tr>
            <td><strong><?= $r['ano'] ?></strong></td>
            <td><?= $r['pedidos'] ?></td>
            <td><?= $r['clientes'] ?></td>
            <td class="fw-semibold text-success"><?= moedaBR($r['valor']) ?></td>
            <td>
                <div class="d-flex align-items-center gap-2">
                    <div class="progress flex-grow-1" style="height:8px">
                        <div class="progress-bar bg-primary" style="width:<?= $total>0?round($r['valor']/$total*100):0 ?>%"></div>
                    </div>
                    <span><?= $total>0?round($r['valor']/$total*100):0 ?>%</span>
                </div>
            </td>
        </tr>
    <?php endforeach; else: ?><tr><td colspan="5" class="text-center text-muted py-4">Nenhum faturamento registrado.</td></tr><?php endif; ?>
    </tbody>
    <?php if ($rows): ?><tfoot class="table-light"><tr><th>Total</th><th><?= array_sum(array_column($rows,'pedidos')) ?></th><th>—</th><th><?= moedaBR($total) ?></th><th>100%</th></tr></tfoot><?php endif; ?>
</table></div></div></div>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
