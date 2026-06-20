<?php
require_once __DIR__ . '/../config.php';
requireCliente();
$u = usuario();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'editar') {
            $ped = db()->prepare('SELECT p.*, c.desconto_cliente FROM pedidos p LEFT JOIN clientes c ON c.id = p.cliente_id WHERE p.id = ? AND p.cliente_id = ?');
            $ped->execute([$id, $u['id']]);
            $ped = $ped->fetch();
            if ($ped && $ped['status'] === 'comercial') {
                $produto_id = (int)$_POST['produto_id'];
                $qtd        = max(1, (int)$_POST['quantidade_total']);
                $tipo       = $_POST['tipo_venda'] === 'bonificacao' ? 'bonificacao' : 'venda';
                $obs        = trim($_POST['observacoes'] ?? '');
                $prod = db()->prepare('SELECT p.*, COALESCE(t.preco_padrao, p.vendas_varejo) as preco FROM produtos p LEFT JOIN tabela_precos t ON t.produto_id = p.id WHERE p.id = ?');
                $prod->execute([$produto_id]);
                $prod = $prod->fetch();
                if (!$prod) throw new Exception(t('Produto inválido.'));
                $desconto    = (float)($ped['desconto_cliente'] ?? 0) / 100;
                $valor_total = $qtd * (float)$prod['preco'] * (1 - $desconto);
                if ($tipo === 'bonificacao') $valor_total = 0;
                db()->prepare('UPDATE pedidos SET produto_id=?,descricao_produto=?,codigo_barra=?,quantidade_total=?,tipo_venda=?,observacoes=?,valor_total=? WHERE id=?')
                    ->execute([$produto_id, $prod['descricao_pt'], $prod['codigo_barra'], $qtd, $tipo, $obs, $valor_total, $id]);
                flash('success', t('Pedido atualizado com sucesso!'));
            } else {
                flash('warning', t('Edição não permitida para este pedido.'));
            }
        }
    } catch (Exception $e) {
        flash('danger', t('Erro:') . ' ' . $e->getMessage());
    }
    header('Location: ' . BASE_URL . '/cliente/pedido.php?id=' . $id);
    exit;
}

$pedidoId = (int)($_GET['id'] ?? 0);
if ($pedidoId < 1) {
    flash('warning', t('Pedido inválido.'));
    header('Location: ' . BASE_URL . '/cliente/meus-pedidos.php'); exit;
}

$pedido = db()->prepare("
    SELECT p.*, pr.codigo_produto, COALESCE(t.preco_padrao, pr.vendas_varejo) AS preco_unit
    FROM pedidos p
    LEFT JOIN produtos pr ON pr.id = p.produto_id
    LEFT JOIN tabela_precos t ON t.produto_id = pr.id
    WHERE p.id = ? AND p.cliente_id = ?");
$pedido->execute([$pedidoId, $u['id']]);
$pedido = $pedido->fetch();

if (!$pedido) {
    flash('warning', t('Pedido não encontrado.'));
    header('Location: ' . BASE_URL . '/cliente/meus-pedidos.php'); exit;
}

// Coluna de preço conforme a moeda do pedido (a moeda "corrente" para os
// símbolos é definida após o header.php, para não afetar o saldo do sidebar)
$colPreco = colPrecoMoeda($pedido['moeda'] ?? 'BRL');

$loteId = $pedido['lote_id'] ?: null;
$stmtItens = db()->prepare("
    SELECT p.*, pr.codigo_produto, COALESCE($colPreco, pr.vendas_varejo) AS preco_unit
    FROM pedidos p
    LEFT JOIN produtos pr ON pr.id = p.produto_id
    LEFT JOIN tabela_precos t ON t.produto_id = pr.id
    WHERE " . ($loteId ? 'p.lote_id = ?' : 'p.id = ?') . " ORDER BY p.id");
$stmtItens->execute([$loteId ?: $pedidoId]);
$itensPedido = $stmtItens->fetchAll();
$valorTotalGeral = array_sum(array_column($itensPedido, 'valor_total'));

// Campanhas atingidas neste pedido (informativo)
$canalCli = db()->prepare('SELECT canal_venda_id FROM clientes WHERE id = ?');
$canalCli->execute([$u['id']]);
$pedidoCanalId = (int)($canalCli->fetchColumn() ?: 0);
$ciCamp = db()->prepare("SELECT p.produto_id, p.quantidade_total,
                                COALESCE($colPreco, pr.vendas_varejo) AS preco_unit,
                                pr.linha, pr.grupo, pr.subgrupo
                         FROM pedidos p
                         LEFT JOIN produtos pr ON pr.id = p.produto_id
                         LEFT JOIN tabela_precos t ON t.produto_id = pr.id
                         WHERE " . ($loteId ? 'p.lote_id = ?' : 'p.id = ?'));
$ciCamp->execute([$loteId ?: $pedidoId]);
$itensCamp = [];
foreach ($ciCamp->fetchAll() as $it) {
    $itensCamp[] = [
        'produto_id' => (int)$it['produto_id'],
        'qtd'        => (int)$it['quantidade_total'],
        'linha'      => $it['linha'], 'grupo' => $it['grupo'], 'subgrupo' => $it['subgrupo'],
        'preco'      => (float)$it['preco_unit'],
    ];
}
$campanhasAtingidas = campanhasAtingidasResumo(ctxCampanha($itensCamp, $pedidoCanalId));

// Crédito utilizado no pedido
$creditoUsado = 0.0;
if ($pedido['lote_id']) {
    $cuStmt = db()->prepare('SELECT credito_utilizado FROM pedidos WHERE lote_id = ? AND credito_utilizado > 0 LIMIT 1');
    $cuStmt->execute([$pedido['lote_id']]);
    $creditoUsado = (float)($cuStmt->fetchColumn() ?: 0);
} else {
    $creditoUsado = (float)($pedido['credito_utilizado'] ?? 0);
}

// Desconto de pagamento (Pix 5%)
$descontoPix = 0.0;
if ($pedido['lote_id']) {
    $dpStmt = db()->prepare('SELECT desconto_pagamento FROM pedidos WHERE lote_id = ? AND desconto_pagamento > 0 LIMIT 1');
    $dpStmt->execute([$pedido['lote_id']]);
    $descontoPix = (float)($dpStmt->fetchColumn() ?: 0);
} else {
    $descontoPix = (float)($pedido['desconto_pagamento'] ?? 0);
}
$totalAPagar = max(0, $valorTotalGeral - $descontoPix - $creditoUsado);

$canEdit = $pedido['status'] === 'comercial';

$statusInfo = [
    'comercial'  => ['icon' => 'bi-hourglass-split', 'color' => 'primary',   'msg' => t('Aguardando análise do time Comercial.')],
    'financeiro' => ['icon' => 'bi-currency-dollar', 'color' => 'warning',   'msg' => t('Em análise pelo time Financeiro.')],
    'faturado'   => ['icon' => 'bi-check-circle',    'color' => 'success',   'msg' => t('Pedido aprovado e faturado.')],
    'cancelado'  => ['icon' => 'bi-x-circle',        'color' => 'danger',    'msg' => t('Pedido cancelado. Entre em contato com seu supervisor.')],
    'reprovado'  => ['icon' => 'bi-x-circle',        'color' => 'danger',    'msg' => t('Pedido cancelado. Entre em contato com seu supervisor.')],
];
$si = $statusInfo[$pedido['status']] ?? ['icon' => 'bi-question-circle', 'color' => 'secondary', 'msg' => ''];

$pageTitle = t('Pedido') . ' ' . e($pedido['numero_pedido']);
require_once LAYOUT_PATH . '/header.php';
// A partir daqui os valores do pedido usam o símbolo da moeda do cliente
moedaCorrente($pedido['moeda'] ?? 'BRL');
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><?= e($pedido['numero_pedido']) ?></h4>
        <div><?= statusBadge($pedido['status']) ?></div>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/cliente/pedido-pdf.php?id=<?= $pedidoId ?>" target="_blank"
           class="btn btn-outline-secondary">
            <i class="bi bi-file-earmark-pdf me-1"></i>PDF
        </a>
        <a href="<?= BASE_URL ?>/cliente/meus-pedidos.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i><?= et('Voltar') ?>
        </a>
    </div>
</div>

<div class="row g-4">

    <!-- Coluna principal -->
    <div class="col-lg-8">

        <!-- Informações do pedido -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="bi bi-info-circle me-2 text-primary"></i><?= et('Informações') ?></h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-4">
                        <div class="text-muted small"><?= et('Tipo de Venda') ?></div>
                        <div class="fw-semibold">
                            <span class="badge bg-<?= $pedido['tipo_venda'] === 'venda' ? 'primary' : 'info' ?> fs-6">
                                <?= et(ucfirst($pedido['tipo_venda'])) ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="text-muted small"><?= et('Data do Pedido') ?></div>
                        <div class="fw-semibold"><?= dataBR($pedido['data_pedido']) ?></div>
                        <div class="text-muted small"><?= $pedido['created_at'] ? date('H:i', strtotime($pedido['created_at'])) : '' ?></div>
                    </div>
                    <div class="col-sm-4">
                        <div class="text-muted small"><?= ($descontoPix > 0 || $creditoUsado > 0) ? et('Total a Pagar') : et('Valor Total') ?></div>
                        <div class="fw-bold fs-5 text-primary"><?= moedaBR($totalAPagar) ?></div>
                        <?php if ($descontoPix > 0 || $creditoUsado > 0): ?>
                        <div class="text-muted small text-decoration-line-through"><?= moedaBR($valorTotalGeral) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($pedido['forma_pagamento'])): ?>
                    <div class="col-sm-6">
                        <div class="text-muted small"><?= et('Forma de Pagamento') ?></div>
                        <div class="fw-semibold"><i class="bi bi-credit-card-2-front me-1 text-primary"></i><?= e($pedido['forma_pagamento']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($creditoUsado > 0): ?>
                    <div class="col-sm-<?= !empty($pedido['forma_pagamento']) ? '6' : '12' ?>">
                        <div class="text-muted small"><?= et('Crédito Aplicado') ?></div>
                        <div class="fw-semibold text-success"><i class="bi bi-coin me-1"></i><?= moedaBR($creditoUsado) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($descontoPix > 0): ?>
                    <div class="col-sm-6">
                        <div class="text-muted small"><?= et('Desconto Pix (5%)') ?></div>
                        <div class="fw-semibold text-success"><i class="bi bi-qr-code-scan me-1"></i>− <?= moedaBR($descontoPix) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($pedido['observacoes'])): ?>
                    <div class="col-sm-<?= !empty($pedido['forma_pagamento']) ? '6' : '12' ?>">
                        <div class="text-muted small"><?= et('Observações') ?></div>
                        <div><?= e($pedido['observacoes']) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Campanhas atingidas -->
        <?php if ($campanhasAtingidas): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="bi bi-megaphone me-2 text-primary"></i><?= et('Campanhas Atingidas') ?></h5>
            </div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($campanhasAtingidas as $ca):
                        $ehB = $ca['tipo'] === 'bonificacao';
                        $pct = rtrim(rtrim(number_format($ca['desconto'], 2, ',', '.'), '0'), ',');
                    ?>
                    <div class="border rounded-3 px-3 py-2" style="background:#f8fffe">
                        <div class="d-flex align-items-center gap-2">
                            <?php if ($ehB): ?>
                            <span class="badge bg-warning text-dark"><i class="bi bi-gift"></i> <?= et('Bonificação') ?></span>
                            <?php else: ?>
                            <span class="badge bg-success">−<?= $pct ?>%</span>
                            <?php endif; ?>
                            <span class="fw-semibold small"><?= e($ca['codigo']) ?></span>
                        </div>
                        <div class="text-muted" style="font-size:.76rem">
                            <?php if ($ca['alvo']): ?><?= e($ca['alvo']) ?> &middot; <?php endif; ?><?= e($ca['detalhe']) ?>
                            <?php if ($ehB && $ca['bonus']): ?>
                            <br><span class="text-warning fw-semibold"><i class="bi bi-gift-fill me-1"></i><?= et('Brinde') ?> ×<?= (int)$ca['mult'] ?>: <?= e(implode(', ', $ca['bonus'])) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Itens do pedido -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-cart me-2 text-primary"></i><?= et('Itens do Pedido') ?></h5>
                <?php if (count($itensPedido) > 1): ?>
                <span class="badge bg-primary"><?= count($itensPedido) ?> <?= et('itens') ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3"><?= et('Produto') ?></th>
                            <th><?= et('Código') ?></th>
                            <th class="text-center"><?= et('Qtd') ?></th>
                            <th class="text-end"><?= et('Preço Unit.') ?></th>
                            <th class="text-center"><?= et('Desconto') ?></th>
                            <th class="text-end pe-3"><?= et('Total') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($itensPedido as $item):
                            $descPct   = (float)($item['desconto_campanha'] ?? 0);
                            $qtd       = (int)$item['quantidade_total'];
                            $precoUnit = $qtd > 0 ? (float)$item['valor_total'] / $qtd : 0;
                        ?>
                        <tr>
                            <td class="ps-3 fw-semibold"><?= e($item['descricao_produto'] ?? '—') ?></td>
                            <td><code><?= e($item['codigo_produto'] ?? $item['codigo_barra'] ?? '—') ?></code></td>
                            <td class="text-center"><?= $qtd ?></td>
                            <td class="text-end"><?= $precoUnit > 0 ? moedaBR($precoUnit) : '—' ?></td>
                            <td class="text-center">
                                <?php if ($descPct > 0): ?>
                                <span class="badge bg-success">-<?= number_format($descPct, 2, ',', '.') ?>%</span>
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-semibold pe-3"><?= moedaBR($item['valor_total']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <td colspan="5" class="text-end fw-semibold pe-3"><?= et('Subtotal:') ?></td>
                            <td class="fw-semibold text-end pe-3"><?= moedaBR($valorTotalGeral) ?></td>
                        </tr>
                        <?php if ($creditoUsado > 0): ?>
                        <tr class="text-success">
                            <td colspan="5" class="text-end fw-semibold pe-3"><i class="bi bi-coin me-1"></i><?= et('Crédito aplicado:') ?></td>
                            <td class="fw-semibold text-end pe-3">− <?= moedaBR($creditoUsado) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($descontoPix > 0): ?>
                        <tr class="text-success">
                            <td colspan="5" class="text-end fw-semibold pe-3"><i class="bi bi-qr-code-scan me-1"></i><?= et('Desconto Pix (5%):') ?></td>
                            <td class="fw-semibold text-end pe-3">− <?= moedaBR($descontoPix) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($descontoPix > 0 || $creditoUsado > 0): ?>
                        <tr>
                            <td colspan="5" class="text-end fw-bold pe-3"><?= et('Total a Pagar:') ?></td>
                            <td class="fw-bold text-primary fs-5 text-end pe-3"><?= moedaBR($totalAPagar) ?></td>
                        </tr>
                        <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-end fw-bold pe-3"><?= et('Total Geral:') ?></td>
                            <td class="fw-bold text-primary fs-5 text-end pe-3"><?= moedaBR($valorTotalGeral) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tfoot>
                </table>
            </div>
        </div>

    </div>

    <!-- Coluna lateral -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm sticky-top" style="top:1rem">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="bi bi-info-circle me-2 text-primary"></i><?= et('Status do Pedido') ?></h5>
            </div>
            <div class="card-body <?= $canEdit ? '' : 'text-center py-4' ?>">
                <?php if ($canEdit): ?>
                <div class="d-grid gap-2">
                    <a href="<?= BASE_URL ?>/cliente/novo-pedido.php?editar=<?= $pedido['id'] ?>"
                       class="btn btn-warning">
                        <i class="bi bi-pencil-square me-1"></i><?= et('Editar Pedido') ?>
                    </a>
                </div>
                <hr class="my-3">
                <div class="text-center">
                    <i class="bi <?= $si['icon'] ?> text-<?= $si['color'] ?>" style="font-size:2rem"></i>
                    <div class="mt-2"><?= statusBadge($pedido['status']) ?></div>
                    <p class="text-muted small mt-2 mb-0"><?= e($si['msg']) ?></p>
                </div>
                <?php else: ?>
                <i class="bi <?= $si['icon'] ?> text-<?= $si['color'] ?>" style="font-size:2.5rem"></i>
                <div class="mt-3"><?= statusBadge($pedido['status']) ?></div>
                <p class="text-muted small mt-3 mb-0"><?= e($si['msg']) ?></p>
                <?php endif; ?>
            </div>
            <div class="card-footer bg-white border-top-0">
                <small class="text-muted">
                    <strong><?= et('Criado em:') ?></strong><br>
                    <?= $pedido['created_at'] ? date('d/m/Y H:i', strtotime($pedido['created_at'])) : dataBR($pedido['data_pedido']) ?>
                </small>
            </div>
        </div>
    </div>

</div>

<?php require_once LAYOUT_PATH . '/footer.php'; ?>
