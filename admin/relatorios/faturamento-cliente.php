<?php
require_once __DIR__ . '/../../config.php';
requireComercial();

$ano = (int)($_GET['ano'] ?? date('Y'));

$rows = db()->prepare("SELECT c.razao_social, c.cidade, c.estado, COALESCE(c.supervisor, c.vendedor) AS supervisor,
    COUNT(p.id) AS pedidos, COALESCE(SUM(p.valor_total * (CASE WHEN p.moeda <> 'BRL' AND p.cotacao > 0 THEN p.cotacao ELSE 1 END)),0) AS valor
FROM pedidos p
JOIN clientes c ON c.id = p.cliente_id
WHERE p.status = 'faturado' AND YEAR(p.data_pedido) = ?
GROUP BY c.id ORDER BY valor DESC");
$rows->execute([$ano]);
$rows = $rows->fetchAll();
$total = array_sum(array_column($rows, 'valor'));

$pageTitle = 'Faturamento por Cliente';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-person-lines-fill me-2"></i>Faturamento por Cliente</h4>
</div>
<form class="card shadow-sm border-0 mb-4 p-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label fw-semibold small">Ano</label>
            <select name="ano" class="form-select form-select-sm">
                <?php for ($y=date('Y'); $y>=date('Y')-5; $y--): ?><option value="<?=$y?>" <?=$y==$ano?'selected':''?>><?=$y?></option><?php endfor; ?>
            </select>
        </div>
        <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Filtrar</button></div>
        <div class="col-md-7 text-end"><strong class="text-success"><?= moedaBR($total) ?> total</strong></div>
    </div>
</form>
<div class="card shadow-sm border-0"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
    <thead class="table-light"><tr><th>Rank</th><th>Cliente</th><th>Cidade/UF</th><th>Supervisor</th><th>Pedidos</th><th>Valor</th><th>Participação</th></tr></thead>
    <tbody>
    <?php if ($rows): $i=1; foreach ($rows as $r): ?>
        <tr>
            <td><span class="badge bg-<?= $i<=3?'warning':'secondary' ?>">#<?= $i++ ?></span></td>
            <td><strong><?= e($r['razao_social']) ?></strong></td>
            <td><?= e($r['cidade'])?><?= $r['estado']?' /'.e($r['estado']):'' ?></td>
            <td><?= e($r['supervisor']) ?></td>
            <td><?= $r['pedidos'] ?></td>
            <td class="fw-semibold"><?= moedaBR($r['valor']) ?></td>
            <td>
                <div class="d-flex align-items-center gap-2">
                    <div class="progress flex-grow-1" style="height:6px"><div class="progress-bar bg-success" style="width:<?= $total>0?round($r['valor']/$total*100):0 ?>%"></div></div>
                    <span class="small"><?= $total>0?round($r['valor']/$total*100):0 ?>%</span>
                </div>
            </td>
        </tr>
    <?php endforeach; else: ?><tr><td colspan="7" class="text-center text-muted py-4">Nenhum dado.</td></tr><?php endif; ?>
    </tbody>
    <?php if ($rows): ?><tfoot class="table-light"><tr><th colspan="4">Total</th><th><?= array_sum(array_column($rows,'pedidos')) ?></th><th><?= moedaBR($total) ?></th><th>100%</th></tr></tfoot><?php endif; ?>
</table></div></div></div>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
