<?php
require_once __DIR__ . '/../config.php';
requireCliente();
$u = usuario();

$filtro = $_GET['status'] ?? '';
$having = '';
$params = [$u['id']];
if ($filtro) { $having = 'HAVING MIN(p.status) = ?'; $params[] = $filtro; }

$pedidos = db()->prepare("
    SELECT g.min_id AS id, pf.numero_pedido,
           pf.data_pedido, g.created_at,
           pf.tipo_venda, g.valor_total, pf.moeda,
           g.status, g.num_itens, g.lote_key
    FROM (
        SELECT MIN(p.id) AS min_id,
               COALESCE(p.lote_id, CAST(p.id AS CHAR)) AS lote_key,
               MAX(p.created_at) AS created_at,
               SUM(p.valor_total) AS valor_total,
               MIN(p.status) AS status,
               COUNT(*) AS num_itens
        FROM pedidos p
        WHERE p.cliente_id = ?
        GROUP BY COALESCE(p.lote_id, CAST(p.id AS CHAR))
        $having
    ) g
    JOIN pedidos pf ON pf.id = g.min_id
    ORDER BY g.created_at DESC");
$pedidos->execute($params);
$pedidos = $pedidos->fetchAll();

$pageTitle = 'Meus Pedidos';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-list-ul me-2"></i>Meus Pedidos</h4>
    <a href="<?= BASE_URL ?>/cliente/novo-pedido.php" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Novo Pedido
    </a>
</div>

<!-- Filtros -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-2">
            <?php
            $filtros = [''=>'Todos','comercial'=>'Aguardando Comercial','financeiro'=>'Aguardando Financeiro','faturado'=>'Aguardando Faturamento','reprovado'=>'Cancelado'];
            $cores   = [''=>'secondary','comercial'=>'primary','financeiro'=>'warning','faturado'=>'success','reprovado'=>'danger'];
            foreach ($filtros as $val => $label):
                $ativo = $filtro === $val;
                $cls   = $ativo ? 'btn-' . $cores[$val] : 'btn-outline-secondary';
            ?>
            <a href="?status=<?= $val ?>" class="btn btn-sm <?= $cls ?>"><?= $label ?></a>
            <?php endforeach; ?>
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
                        <th>Data</th>
                        <th>Tipo</th>
                        <th>Valor</th>
                        <th>Status</th>
                        <th class="text-end pe-3">Ação</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($pedidos): foreach ($pedidos as $p): ?>
                <tr>
                    <td class="ps-3">
                        <strong class="text-primary"><?= e($p['numero_pedido']) ?></strong>
                        <?php if ($p['num_itens'] > 1): ?>
                        <span class="badge bg-secondary ms-1"><?= $p['num_itens'] ?> itens</span>
                        <?php endif; ?>
                    </td>
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
                    <td class="fw-semibold"><?= moedaBR($p['valor_total'], $p['moeda'] ?? 'BRL') ?></td>
                    <td><?= statusBadge($p['status']) ?></td>
                    <td class="text-end pe-3">
                        <a href="<?= BASE_URL ?>/cliente/pedido.php?id=<?= $p['id'] ?>"
                           class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-eye me-1"></i>Detalhes
                        </a>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="6" class="text-center text-muted py-5">
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
