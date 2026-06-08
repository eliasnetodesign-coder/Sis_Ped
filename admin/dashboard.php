<?php
require_once __DIR__ . '/../config.php';
requireAdmin();
$u = usuario();

if ($u['tipo'] === 'vendedor') {
    $vNome = $u['nome'];
    $totais = db()->prepare('SELECT
        COUNT(*) AS total,
        SUM(p.status="comercial")  AS comercial,
        SUM(p.status="financeiro") AS financeiro,
        SUM(p.status="faturado")   AS faturados,
        SUM(p.status="reprovado")  AS reprovados,
        COALESCE(SUM(CASE WHEN p.status="faturado" THEN p.valor_total ELSE 0 END),0) AS valor_faturado
    FROM pedidos p JOIN clientes c ON c.id = p.cliente_id WHERE c.vendedor = ?');
    $totais->execute([$vNome]);
    $totais = $totais->fetch();

    $recentes = db()->prepare('SELECT p.*, c.razao_social FROM pedidos p
        LEFT JOIN clientes c ON c.id = p.cliente_id
        WHERE c.vendedor = ?
        ORDER BY p.created_at DESC LIMIT 10');
    $recentes->execute([$vNome]);
    $recentes = $recentes->fetchAll();

    $emComercial = 0;
} else {
    $totais = db()->query('SELECT
        COUNT(*) AS total,
        SUM(status="comercial")  AS comercial,
        SUM(status="financeiro") AS financeiro,
        SUM(status="faturado")   AS faturados,
        SUM(status="reprovado")  AS reprovados,
        COALESCE(SUM(CASE WHEN status="faturado" THEN valor_total ELSE 0 END),0) AS valor_faturado
    FROM pedidos')->fetch();

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
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="card shadow-sm border-0 border-start border-4 border-primary">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Aguardando Comercial</div>
                <div class="display-5 fw-bold text-primary"><?= $totais['comercial'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card shadow-sm border-0 border-start border-4 border-warning">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">No Financeiro</div>
                <div class="display-5 fw-bold" style="color:#c8880a"><?= $totais['financeiro'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card shadow-sm border-0 border-start border-4 border-success">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Faturados</div>
                <div class="display-5 fw-bold text-success"><?= $totais['faturados'] ?></div>
            </div>
        </div>
    </div>
</div>
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="card shadow-sm border-0 border-start border-4 border-danger">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Reprovados</div>
                <div class="display-5 fw-bold text-danger"><?= $totais['reprovados'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card shadow-sm border-0 border-start border-4 border-info">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Total Pedidos</div>
                <div class="display-5 fw-bold text-info"><?= $totais['total'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow-sm border-0 border-start border-4 border-success">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Valor Total Faturado</div>
                <div class="fw-bold text-success" style="font-size:1.8rem"><?= moedaBR($totais['valor_faturado']) ?></div>
            </div>
        </div>
    </div>
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
