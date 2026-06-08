<?php
require_once __DIR__ . '/../config.php';
requireCliente();
$u = usuario();

$filtro = $_GET['situacao'] ?? '';
$where  = 'WHERE cr.cliente_id = ?';
$params = [$u['id']];
if ($filtro) { $where .= ' AND cr.situacao = ?'; $params[] = $filtro; }

$titulos = db()->prepare("SELECT cr.* FROM contas_receber cr $where ORDER BY cr.data_vencimento ASC");
$titulos->execute($params);
$titulos = $titulos->fetchAll();

$resumo = db()->prepare('SELECT
    COALESCE(SUM(valor_receber - descontos),0) AS total,
    COALESCE(SUM(CASE WHEN situacao="aberto" THEN valor_receber-descontos ELSE 0 END),0) AS aberto,
    COALESCE(SUM(CASE WHEN situacao="vencido" THEN valor_receber-descontos ELSE 0 END),0) AS vencido,
    COALESCE(SUM(CASE WHEN situacao="pago" THEN valor_receber-descontos ELSE 0 END),0) AS pago
FROM contas_receber WHERE cliente_id = ?');
$resumo->execute([$u['id']]);
$r = $resumo->fetch();

$pageTitle = 'Financeiro';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-receipt me-2"></i>Financeiro</h4>
    <p class="text-muted small">Seus títulos e boletos a receber</p>
</div>

<!-- Cards resumo -->
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="card shadow-sm border-0 border-start border-4 border-primary">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Total</div>
                <div class="fw-bold text-primary" style="font-size:1.3rem"><?= moedaBR($r['total']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card shadow-sm border-0 border-start border-4 border-warning">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Em Aberto</div>
                <div class="fw-bold" style="color:#c8880a;font-size:1.3rem"><?= moedaBR($r['aberto']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card shadow-sm border-0 border-start border-4 border-danger">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Vencido</div>
                <div class="fw-bold text-danger" style="font-size:1.3rem"><?= moedaBR($r['vencido']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card shadow-sm border-0 border-start border-4 border-success">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Pago</div>
                <div class="fw-bold text-success" style="font-size:1.3rem"><?= moedaBR($r['pago']) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Filtros -->
<div class="mb-3 d-flex flex-wrap gap-2">
    <?php
    $filtros = [''=>'Todos','aberto'=>'Em Aberto','vencido'=>'Vencido','pago'=>'Pago','cancelado'=>'Cancelado'];
    foreach ($filtros as $val => $label):
        $active = $filtro === $val;
    ?>
    <a href="?situacao=<?= $val ?>" class="btn btn-sm btn-<?= $active ? 'primary' : 'outline-primary' ?>"><?= $label ?></a>
    <?php endforeach; ?>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Documento</th>
                    <th>Valor</th>
                    <th>Desconto</th>
                    <th>Líquido</th>
                    <th>Emissão</th>
                    <th>Vencimento</th>
                    <th>Pagamento</th>
                    <th>Situação</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($titulos): foreach ($titulos as $t): ?>
                <tr class="<?= $t['situacao'] === 'vencido' ? 'table-danger' : '' ?>">
                    <td><strong><?= e($t['numero_documento'] ?: '—') ?></strong></td>
                    <td><?= moedaBR($t['valor_receber']) ?></td>
                    <td><?= moedaBR($t['descontos']) ?></td>
                    <td class="fw-semibold"><?= moedaBR($t['valor_receber'] - $t['descontos']) ?></td>
                    <td><?= dataBR($t['data_emissao']) ?></td>
                    <td><?= dataBR($t['data_vencimento']) ?></td>
                    <td><?= dataBR($t['data_pagamento']) ?></td>
                    <td><?= statusBadge($t['situacao']) ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="8" class="text-center text-muted py-4">
                    <i class="bi bi-inbox display-6 d-block mb-2"></i>Nenhum título encontrado.
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
