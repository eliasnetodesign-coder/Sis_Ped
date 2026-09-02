<?php
require_once __DIR__ . '/../../config.php';
requireComercial();

$ini = $_GET['ini'] ?? date('Y-m-01');
$fim = $_GET['fim'] ?? date('Y-m-t');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ini)) $ini = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fim)) $fim = date('Y-m-t');
$clienteId = (int)($_GET['cliente_id'] ?? 0);

// Clientes que já tiveram algum pedido importado do A&M (para o filtro).
$clientesFiltro = db()->query("
    SELECT DISTINCT c.id, c.razao_social
    FROM pedidos p JOIN clientes c ON c.id = p.cliente_id
    WHERE p.observacoes LIKE 'Importado do sistema A&M%'
    ORDER BY c.razao_social")->fetchAll();

// Pedidos importados do A&M no período (um por lote).
$sql = "
    SELECT COALESCE(p.lote_id, CAST(p.id AS CHAR)) AS grp,
           MIN(p.id) AS pedido_id, p.numero_pedido, p.observacoes,
           p.cliente_id, c.razao_social, MIN(p.data_pedido) AS data_pedido
    FROM pedidos p JOIN clientes c ON c.id = p.cliente_id
    WHERE p.observacoes LIKE 'Importado do sistema A&M%'
      AND DATE(p.data_pedido) BETWEEN ? AND ?";
$params = [$ini, $fim];
if ($clienteId) { $sql .= " AND p.cliente_id = ?"; $params[] = $clienteId; }
$sql .= " GROUP BY grp, p.numero_pedido, p.observacoes, p.cliente_id, c.razao_social
          ORDER BY data_pedido DESC, p.numero_pedido DESC";
$q = db()->prepare($sql);
$q->execute($params);
$pedidos = $q->fetchAll();

$linhas = [];
$tot = ['produtos'=>0,'descontos'=>0,'credito'=>0,'impostos'=>0,'mp'=>0,'despesas'=>0,'margem'=>0];
foreach ($pedidos as $p) {
    $m = calcularMargemPedido((int)$p['pedido_id']);
    if (!$m) continue;

    $produtos  = (float)$m['impTotalBase'];
    $descontos = -(float)$m['impDeltaDescontos'];
    $credito   = -(float)$m['impDeltaCredito'];
    $impostos  = -((float)$m['impDeltaNet'] + (float)$m['impDeltaImpostos']);
    $mp        = -(float)$m['impDeltaMP'];
    $despesas  = -(float)$m['impDeltaDespesas'];
    $margem    = (float)$m['impTotalFinal'];
    $margemPct = (float)$m['impMargemPct'];

    $numAM = preg_match('/Pedido N[ºo°]\s*([^\s—-]+)/u', (string)$p['observacoes'], $mm) ? $mm[1] : '—';

    $linhas[] = [
        'pedido_id' => (int)$p['pedido_id'],
        'numero'    => $p['numero_pedido'],
        'num_am'    => $numAM,
        'eh_bf'     => strpos((string)$p['observacoes'], '(BF)') !== false,
        'cliente'   => $p['razao_social'],
        'data'      => $p['data_pedido'],
        'produtos'  => $produtos,
        'descontos' => $descontos,
        'credito'   => $credito,
        'impostos'  => $impostos,
        'impostos_pct' => $produtos > 0 ? $impostos / $produtos * 100 : 0,
        'mp'        => $mp,
        'despesas'  => $despesas,
        'margem'    => $margem,
        'margem_pct'=> $margemPct,
    ];
    $tot['produtos']  += $produtos;
    $tot['descontos'] += $descontos;
    $tot['credito']   += $credito;
    $tot['impostos']  += $impostos;
    $tot['mp']        += $mp;
    $tot['despesas']  += $despesas;
    $tot['margem']    += $margem;
}
$totMargemPct   = $tot['produtos'] > 0 ? $tot['margem'] / $tot['produtos'] * 100 : 0;
$totImpostosPct = $tot['produtos'] > 0 ? $tot['impostos'] / $tot['produtos'] * 100 : 0;

$pctFmt = fn($v) => rtrim(rtrim(number_format((float)$v, 2, ',', '.'), '0'), ',') . '%';
// Margem: vermelho só quando negativa; caso contrário, verde.
$corMargem = fn($v) => $v < 0 ? 'danger' : 'success';

$pageTitle = 'Margem dos Pedidos A&M';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-graph-up-arrow me-2"></i>Margem dos Pedidos A&amp;M</h4>
        <p class="text-muted small mb-0">
            Waterfall por pedido (mesmo cálculo do modal “Margem” da tela do pedido): preço de tabela →
            descontos → crédito → impostos por empresa → custo MP → custos fixos.
        </p>
    </div>
    <a href="<?= BASE_URL ?>/admin/relatorios/am.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Relatórios A&amp;M
    </a>
</div>

<form class="card shadow-sm border-0 mb-4 p-3">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-3">
            <label class="form-label fw-semibold small mb-1">Data inicial</label>
            <input type="date" name="ini" value="<?= e($ini) ?>" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label fw-semibold small mb-1">Data final</label>
            <input type="date" name="fim" value="<?= e($fim) ?>" class="form-control form-control-sm">
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label fw-semibold small mb-1">Cliente</label>
            <select name="cliente_id" class="form-select form-select-sm">
                <option value="0">Todos</option>
                <?php foreach ($clientesFiltro as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $clienteId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['razao_social']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-2">
            <button class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filtrar</button>
        </div>
    </div>
</form>

<div class="row g-3 mb-4">
    <?php
    $resumo = [
        ['Valor de Tabela', $tot['produtos'], 'secondary'],
        ['Carga de Impostos', $tot['impostos'], 'warning', $totImpostosPct],
        ['Custo MP + Despesas', $tot['mp'] + $tot['despesas'], 'info'],
        ['Margem Final', $tot['margem'], $corMargem($totMargemPct), $totMargemPct],
    ];
    foreach ($resumo as $r): ?>
    <div class="col-6 col-xl-3">
        <div class="card shadow-sm border-0 border-start border-4 border-<?= $r[2] ?> h-100">
            <div class="card-body py-3">
                <div class="text-muted small fw-semibold text-uppercase"><?= e($r[0]) ?></div>
                <div class="fs-4 fw-bold text-<?= $r[2] ?>"><?= moedaBR($r[1]) ?></div>
                <?php if (isset($r[3])): ?><div class="small text-muted"><?= $pctFmt($r[3]) ?> sobre o valor de tabela</div><?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<style>
.tbl-fixa-wrap{max-height:70vh;overflow:auto}
.tbl-fixa thead th{position:sticky;top:0;z-index:3;background:var(--bs-tertiary-bg)!important;box-shadow:inset 0 -1px 0 var(--bs-border-color)}
.tbl-fixa tfoot td{position:sticky;bottom:0;z-index:3;background:var(--bs-tertiary-bg)!important;box-shadow:inset 0 1px 0 var(--bs-border-color)}
</style>
<div class="card shadow-sm border-0">
    <div class="card-body p-0"><div class="table-responsive tbl-fixa-wrap">
    <table class="table table-hover table-sm align-middle mb-0 tbl-fixa" style="font-size:.85rem">
        <thead class="table-light">
            <tr>
                <th>Nº A&amp;M</th>
                <th>Pedido</th>
                <th>Cliente</th>
                <th>Data</th>
                <th class="text-end">Valor Tabela</th>
                <th class="text-end">Descontos</th>
                <th class="text-end">Crédito</th>
                <th class="text-end">Carga Impostos</th>
                <th class="text-end">Custo MP</th>
                <th class="text-end">Despesas</th>
                <th class="text-end">Margem</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php if ($linhas): foreach ($linhas as $l): ?>
            <tr>
                <td class="fw-semibold">
                    <?= e($l['num_am']) ?>
                    <?php if ($l['eh_bf']): ?><span class="badge bg-primary ms-1">BF</span><?php endif; ?>
                </td>
                <td><?= e($l['numero']) ?></td>
                <td class="text-truncate" style="max-width:190px" title="<?= e($l['cliente']) ?>"><?= e($l['cliente']) ?></td>
                <td class="text-nowrap"><?= dataBR($l['data']) ?></td>
                <td class="text-end"><?= moedaBR($l['produtos']) ?></td>
                <td class="text-end text-danger"><?= $l['descontos'] ? '− ' . moedaBR($l['descontos']) : '—' ?></td>
                <td class="text-end text-danger"><?= $l['credito'] ? '− ' . moedaBR($l['credito']) : '—' ?></td>
                <td class="text-end text-danger">
                    − <?= moedaBR($l['impostos']) ?>
                    <span class="text-muted d-block" style="font-size:.75rem"><?= $pctFmt($l['impostos_pct']) ?></span>
                </td>
                <td class="text-end text-danger"><?= $l['mp'] ? '− ' . moedaBR($l['mp']) : '—' ?></td>
                <td class="text-end text-danger"><?= $l['despesas'] ? '− ' . moedaBR($l['despesas']) : '—' ?></td>
                <td class="text-end fw-bold">
                    <span class="text-<?= $corMargem($l['margem_pct']) ?>"><?= moedaBR($l['margem']) ?></span>
                    <span class="badge bg-<?= $corMargem($l['margem_pct']) ?> d-block mt-1"><?= $pctFmt($l['margem_pct']) ?></span>
                </td>
                <td class="text-end">
                    <a href="<?= BASE_URL ?>/admin/pedido.php?id=<?= $l['pedido_id'] ?>" class="btn btn-sm btn-outline-secondary" title="Abrir pedido">
                        <i class="bi bi-eye"></i>
                    </a>
                </td>
            </tr>
        <?php endforeach; else: ?>
            <tr><td colspan="12" class="text-center text-muted py-4">Nenhum pedido importado do A&amp;M no período.</td></tr>
        <?php endif; ?>
        </tbody>
        <?php if ($linhas): ?>
        <tfoot class="table-light fw-semibold">
            <tr>
                <td colspan="4">Total — <?= count($linhas) ?> pedido(s)</td>
                <td class="text-end"><?= moedaBR($tot['produtos']) ?></td>
                <td class="text-end text-danger"><?= $tot['descontos'] ? '− ' . moedaBR($tot['descontos']) : '—' ?></td>
                <td class="text-end text-danger"><?= $tot['credito'] ? '− ' . moedaBR($tot['credito']) : '—' ?></td>
                <td class="text-end text-danger">− <?= moedaBR($tot['impostos']) ?> <span class="text-muted d-block" style="font-size:.75rem"><?= $pctFmt($totImpostosPct) ?></span></td>
                <td class="text-end text-danger"><?= $tot['mp'] ? '− ' . moedaBR($tot['mp']) : '—' ?></td>
                <td class="text-end text-danger"><?= $tot['despesas'] ? '− ' . moedaBR($tot['despesas']) : '—' ?></td>
                <td class="text-end">
                    <span class="text-<?= $corMargem($totMargemPct) ?>"><?= moedaBR($tot['margem']) ?></span>
                    <span class="badge bg-<?= $corMargem($totMargemPct) ?> d-block mt-1"><?= $pctFmt($totMargemPct) ?></span>
                </td>
                <td></td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
    </div></div>
</div>
<p class="text-muted small mt-2">
    <i class="bi bi-info-circle me-1"></i>“Carga de Impostos” = impostos da empresa Network + demais empresas
    (ICMS, IPI, PIS, COFINS, IRPJ, CSLL, ISS). “Despesas” = custos fixos (%) + desconto financeiro.
    O cálculo é o mesmo do modal “Margem” do pedido — clique em <i class="bi bi-eye"></i> para conferir o detalhamento.
</p>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
