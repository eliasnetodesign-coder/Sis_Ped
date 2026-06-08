<?php
require_once __DIR__ . '/../../config.php';
requireComercial();

$ano = (int)($_GET['ano'] ?? date('Y'));

// Mapeamento UF → Região
$regioes = [
    'Centro-Oeste' => ['DF','GO','MT','MS'],
    'Norte'        => ['AC','AP','AM','PA','RO','RR','TO'],
    'Nordeste'     => ['AL','BA','CE','MA','PB','PE','PI','RN','SE'],
    'Sul'          => ['PR','RS','SC'],
    'Sudeste'      => ['ES','MG','RJ','SP'],
];
$uf_regiao = [];
foreach ($regioes as $reg => $ufs) {
    foreach ($ufs as $uf) $uf_regiao[$uf] = $reg;
}

$stmt = db()->prepare("SELECT c.estado, COALESCE(SUM(p.valor_total),0) AS valor, COUNT(p.id) AS pedidos
FROM pedidos p JOIN clientes c ON c.id=p.cliente_id
WHERE p.status='faturado' AND YEAR(p.data_pedido)=?
GROUP BY c.estado");
$stmt->execute([$ano]);
$por_estado = $stmt->fetchAll();

$por_regiao = [];
foreach ($por_estado as $row) {
    $reg = $uf_regiao[$row['estado']] ?? 'Outros';
    if (!isset($por_regiao[$reg])) $por_regiao[$reg] = ['valor'=>0,'pedidos'=>0];
    $por_regiao[$reg]['valor']   += $row['valor'];
    $por_regiao[$reg]['pedidos'] += $row['pedidos'];
}
arsort($por_regiao);
$total = array_sum(array_column($por_regiao, 'valor'));

$pageTitle = 'Faturamento por Região';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-map me-2"></i>Faturamento por Região</h4>
</div>
<form class="card shadow-sm border-0 mb-4 p-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label fw-semibold small">Ano</label><select name="ano" class="form-select form-select-sm"><?php for($y=date('Y');$y>=date('Y')-5;$y--):?><option value="<?=$y?>" <?=$y==$ano?'selected':''?>><?=$y?></option><?php endfor;?></select></div>
        <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Filtrar</button></div>
        <div class="col-md-7 text-end"><strong class="text-success"><?= moedaBR($total) ?></strong></div>
    </div>
</form>
<div class="card shadow-sm border-0"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
    <thead class="table-light"><tr><th>Região</th><th>Pedidos</th><th>Valor</th><th>Participação</th></tr></thead>
    <tbody>
    <?php if ($por_regiao): foreach ($por_regiao as $reg => $r): ?>
        <tr>
            <td><strong><?= e($reg) ?></strong></td>
            <td><?= $r['pedidos'] ?></td>
            <td class="fw-semibold"><?= moedaBR($r['valor']) ?></td>
            <td>
                <div class="d-flex align-items-center gap-2">
                    <div class="progress flex-grow-1" style="height:10px"><div class="progress-bar bg-primary" style="width:<?= $total>0?round($r['valor']/$total*100):0 ?>%"></div></div>
                    <span><?= $total>0?round($r['valor']/$total*100):0 ?>%</span>
                </div>
            </td>
        </tr>
    <?php endforeach; else: ?><tr><td colspan="4" class="text-center text-muted py-4">Nenhum dado.</td></tr><?php endif; ?>
    </tbody>
    <?php if ($por_regiao): ?><tfoot class="table-light"><tr><th>Total</th><th><?= array_sum(array_column($por_regiao,'pedidos')) ?></th><th><?= moedaBR($total) ?></th><th>100%</th></tr></tfoot><?php endif; ?>
</table></div></div></div>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
