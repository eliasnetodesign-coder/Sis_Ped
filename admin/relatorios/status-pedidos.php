<?php
require_once __DIR__ . '/../../config.php';
requireComercial();

$status_list = ['comercial','financeiro','faturamento','faturado','cancelado','reprovado'];

$rows = db()->query("SELECT p.status,
    COUNT(*) AS qtd,
    COALESCE(SUM(p.valor_total * (CASE WHEN p.moeda <> 'BRL' AND p.cotacao > 0 THEN p.cotacao ELSE 1 END)),0) AS total,
    GROUP_CONCAT(DISTINCT c.razao_social ORDER BY c.razao_social SEPARATOR ', ') AS clientes
FROM pedidos p
LEFT JOIN clientes c ON c.id = p.cliente_id
GROUP BY p.status ORDER BY FIELD(p.status,'comercial','financeiro','faturamento','faturado','cancelado','reprovado')")->fetchAll();

$recentes = db()->query("SELECT p.*, c.razao_social FROM pedidos p
    LEFT JOIN clientes c ON c.id = p.cliente_id
    ORDER BY p.created_at DESC LIMIT 20")->fetchAll();

$pageTitle = 'Status dos Pedidos';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-kanban me-2"></i>Status dos Pedidos</h4>
    <p class="text-muted small">Visão geral de todos os pedidos por estágio</p>
</div>

<!-- Cards por status -->
<div class="row g-3 mb-4">
<?php
$cores = ['comercial'=>'primary','financeiro'=>'warning','faturamento'=>'info','faturado'=>'success','cancelado'=>'danger','reprovado'=>'danger'];
$total_geral = 0;
foreach ($rows as $r): $total_geral += $r['qtd']; ?>
<div class="col-6 col-xl">
    <div class="card shadow-sm border-0 border-start border-4 border-<?= $cores[$r['status']] ?>">
        <div class="card-body text-center py-3">
            <div class="text-muted small fw-semibold text-uppercase"><?= ucfirst($r['status']) ?></div>
            <div class="display-5 fw-bold text-<?= $cores[$r['status']] ?>"><?= $r['qtd'] ?></div>
            <div class="small text-muted"><?= moedaBR($r['total']) ?></div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Tabela detalhada -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white fw-semibold">Últimos 20 Pedidos</div>
    <div class="card-body p-0"><div class="table-responsive">
    <table class="table table-hover mb-0">
        <thead class="table-light"><tr><th>Pedido</th><th>Cliente</th><th>Produto</th><th>Supervisor</th><th>Data</th><th>Valor</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($recentes as $p): ?>
            <tr>
                <td><strong><?= e($p['numero_pedido']) ?></strong></td>
                <td><?= e($p['razao_social']?:'—') ?></td>
                <td class="text-truncate" style="max-width:160px"><?= e($p['descricao_produto']?:'—') ?></td>
                <td><?= e($p['supervisor'] ?? $p['vendedor'] ?? '—') ?></td>
                <td><?= dataBR($p['data_pedido']) ?></td>
                <td>
                    <?= moedaBR($p['valor_total'], $p['moeda'] ?? 'BRL') ?>
                    <?php $conv = cotacaoExibicaoPedido($p['moeda'] ?? 'BRL', $p['cotacao'] ?? 0); if ($conv): ?>
                    <div class="text-success small" title="Cotação<?= $conv['fallback'] ? ' atual de referência (pedido sem cotação gravada)' : ' do dia' ?>: R$ <?= number_format($conv['taxa'], 4, ',', '.') ?>">≈ <?= moedaBR((float)$p['valor_total'] * $conv['taxa'], 'BRL') ?><?= $conv['fallback'] ? ' *' : '' ?></div>
                    <?php endif; ?>
                </td>
                <td><?= statusBadge($p['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
</div>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
