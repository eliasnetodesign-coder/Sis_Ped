<?php
require_once __DIR__ . '/../../config.php';
requireComercial();

// Contadores do mês corrente (pedidos distintos, agrupados por lote).
$ini = date('Y-m-01');
$fim = date('Y-m-t');

function _contaPedidosAM(string $likeObs, string $ini, string $fim): int {
    $q = db()->prepare("
        SELECT COUNT(*) FROM (
            SELECT 1
            FROM pedidos p
            WHERE p.observacoes LIKE ?
              AND DATE(p.data_pedido) BETWEEN ? AND ?
            GROUP BY COALESCE(p.lote_id, CAST(p.id AS CHAR)), p.numero_pedido
        ) t");
    $q->execute([$likeObs, $ini, $fim]);
    return (int)$q->fetchColumn();
}

$qtdMargem = _contaPedidosAM('Importado do sistema A&M%', $ini, $fim);
$qtdBf     = _contaPedidosAM('Importado do sistema A&M (BF)%', $ini, $fim);

$quadros = [
    [
        'slug'  => 'margem-am',
        'titulo'=> 'Margem dos Pedidos A&M',
        'desc'  => 'Resultado (margem) de cada pedido importado do sistema A&M, com a carga de impostos, custo de matéria-prima e demais deduções calculadas em cima do pedido.',
        'icon'  => 'bi-graph-up-arrow',
        'cor'   => 'success',
        'contador' => $qtdMargem,
        'contador_label' => 'pedidos A&M neste mês',
    ],
    [
        'slug'  => 'bf-am',
        'titulo'=> 'Pedidos BF — Campanhas',
        'desc'  => 'Pedidos importados do A&M com a característica “BF”: valor real (sem campanha) x desconto de campanha aplicado no % Diretoria dos itens, com as campanhas atingidas.',
        'icon'  => 'bi-tags',
        'cor'   => 'primary',
        'contador' => $qtdBf,
        'contador_label' => 'pedidos BF neste mês',
    ],
];

$pageTitle = 'Relatórios A&M';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-clipboard-data me-2"></i>Relatórios A&M</h4>
    <p class="text-muted small mb-0">Relatórios sobre os pedidos importados do sistema A&amp;M (Itallian Hairtech).</p>
</div>

<div class="row g-3">
<?php foreach ($quadros as $q): ?>
    <div class="col-md-6 col-xl-4">
        <div class="card shadow-sm border-0 h-100 position-relative">
            <div class="card-body d-flex flex-column">
                <div class="d-flex align-items-center gap-3 mb-2">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-<?= $q['cor'] ?> bg-opacity-10 text-<?= $q['cor'] ?>"
                          style="width:44px;height:44px;font-size:1.4rem;flex-shrink:0">
                        <i class="bi <?= $q['icon'] ?>"></i>
                    </span>
                    <h5 class="fw-bold mb-0"><?= e($q['titulo']) ?></h5>
                </div>
                <p class="text-muted small flex-grow-1 mb-3"><?= e($q['desc']) ?></p>
                <div class="d-flex justify-content-between align-items-end">
                    <span class="small text-muted">
                        <span class="badge bg-<?= $q['cor'] ?> rounded-pill"><?= (int)$q['contador'] ?></span>
                        <?= e($q['contador_label']) ?>
                    </span>
                    <span class="btn btn-sm btn-outline-<?= $q['cor'] ?>">Abrir <i class="bi bi-arrow-right ms-1"></i></span>
                </div>
            </div>
            <a href="<?= BASE_URL ?>/admin/relatorios/<?= $q['slug'] ?>.php" class="stretched-link" aria-label="<?= e($q['titulo']) ?>"></a>
        </div>
    </div>
<?php endforeach; ?>
</div>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
