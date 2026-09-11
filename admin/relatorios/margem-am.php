<?php
require_once __DIR__ . '/../../config.php';
requireComercial();

$ini = $_GET['ini'] ?? date('Y-m-01');
$fim = $_GET['fim'] ?? date('Y-m-t');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ini)) $ini = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fim)) $fim = date('Y-m-t');
// Busca de cliente: sugestão escolhida ("código — razão social") = código exato;
// texto livre = LIKE em código, razão social ou CNPJ.
$buscaCli = trim($_GET['cli'] ?? '');
$codCli   = preg_match('/^(\S+) — /u', $buscaCli, $mc) ? $mc[1] : '';
$bf = in_array($_GET['bf'] ?? '', ['1', '0'], true) ? $_GET['bf'] : '';
// Status do pedido: por padrão só os que estão aguardando o comercial ('' = todos).
$statusLabels = [
    'comercial'   => 'Aguardando Comercial',
    'financeiro'  => 'Aguardando Financeiro',
    'faturamento' => 'Aguardando Faturamento',
    'faturado'    => 'Faturado',
    'reprovado'   => 'Cancelado',
];
$status = $_GET['status'] ?? 'comercial';
if ($status === 'cancelado') $status = 'reprovado';           // enum legado, mesmo rótulo
if (!isset($statusLabels[$status])) $status = '';

// Clientes que já tiveram algum pedido importado do A&M (para o filtro).
$clientesFiltro = db()->query("
    SELECT DISTINCT c.codigo_cliente, c.razao_social
    FROM pedidos p JOIN clientes c ON c.id = p.cliente_id
    WHERE p.observacoes LIKE 'Importado do sistema A&M%'
    ORDER BY c.razao_social")->fetchAll();

// Pedidos importados do A&M no período (um por lote).
$sql = "
    SELECT COALESCE(p.lote_id, CAST(p.id AS CHAR)) AS grp,
           MIN(p.id) AS pedido_id, p.numero_pedido, p.observacoes,
           p.cliente_id, c.razao_social, c.codigo_cliente, MIN(p.data_pedido) AS data_pedido,
           MIN(p.status) AS status
    FROM pedidos p JOIN clientes c ON c.id = p.cliente_id
    WHERE p.observacoes LIKE 'Importado do sistema A&M%'
      AND DATE(p.data_pedido) BETWEEN ? AND ?";
$params = [$ini, $fim];
if ($codCli !== '') {
    $sql .= " AND c.codigo_cliente = ?"; $params[] = $codCli;
} elseif ($buscaCli !== '') {
    $sql .= " AND (c.razao_social LIKE ? OR c.codigo_cliente LIKE ? OR c.cnpj LIKE ?)";
    $params[] = "%$buscaCli%"; $params[] = "%$buscaCli%"; $params[] = "%$buscaCli%";
}
$sql .= " GROUP BY grp, p.numero_pedido, p.observacoes, p.cliente_id, c.razao_social, c.codigo_cliente";
if ($status === 'reprovado') {                                  // 'cancelado' e 'reprovado' = mesmo status
    $sql .= " HAVING MIN(p.status) IN ('cancelado','reprovado')";
} elseif ($status) {
    $sql .= " HAVING MIN(p.status) = ?"; $params[] = $status;
}
$sql .= " ORDER BY data_pedido DESC, p.numero_pedido DESC";
$q = db()->prepare($sql);
$q->execute($params);
$pedidos = $q->fetchAll();

$linhas = [];
$tot = ['produtos'=>0,'descontos'=>0,'credito'=>0,'impostos'=>0,'mp'=>0,'despesas'=>0,'margem'=>0,
        'canal'=>0,'cliente'=>0,'comercial'=>0,'campanha'=>0,'financeiro'=>0];
foreach ($pedidos as $p) {
    $ehBf = strpos((string)$p['observacoes'], '(BF)') !== false;
    if ($bf === '1' && !$ehBf) continue;
    if ($bf === '0' && $ehBf) continue;

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
    $canal      = -(float)$m['impDeltaCanal'];
    $cliente    = -(float)$m['impDeltaCliente'];
    $comercial  = -(float)$m['impDeltaComercial'];
    $campanha   = -(float)$m['impDeltaCampanha'];
    $financeiro = -(float)$m['impDeltaFinanceiro'];

    $numAM = preg_match('/Pedido N[ºo°]\s*([^\s—-]+)/u', (string)$p['observacoes'], $mm) ? $mm[1] : '—';

    $linhas[] = [
        'pedido_id' => (int)$p['pedido_id'],
        'numero'    => $p['numero_pedido'],
        'num_am'    => $numAM,
        'eh_bf'     => $ehBf,
        'status'    => $p['status'],
        'cliente'   => $p['razao_social'],
        'cod_cli'   => (string)$p['codigo_cliente'],
        'data'    => $p['data_pedido'],
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
    $tot['canal']      += $canal;
    $tot['cliente']    += $cliente;
    $tot['comercial']  += $comercial;
    $tot['campanha']   += $campanha;
    $tot['financeiro'] += $financeiro;
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
        <div class="col-6 col-md-2">
            <label class="form-label fw-semibold small mb-1">Data inicial</label>
            <input type="date" name="ini" value="<?= e($ini) ?>" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label fw-semibold small mb-1">Data final</label>
            <input type="date" name="fim" value="<?= e($fim) ?>" class="form-control form-control-sm">
        </div>
        <div class="col-12 col-md-3">
            <label class="form-label fw-semibold small mb-1">Cliente</label>
            <input type="search" name="cli" id="filtroCli" value="<?= e($buscaCli) ?>" list="listaClientes"
                   class="form-control form-control-sm" autocomplete="off"
                   placeholder="Todos — digite código, nome ou CNPJ" title="Código, razão social ou CNPJ">
            <datalist id="listaClientes">
                <?php foreach ($clientesFiltro as $c): if ((string)$c['codigo_cliente'] === '') continue; ?>
                <option value="<?= e($c['codigo_cliente'] . ' — ' . $c['razao_social']) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label fw-semibold small mb-1">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="" <?= $status === '' ? 'selected' : '' ?>>Todos</option>
                <?php foreach ($statusLabels as $st => $lbl): ?>
                <option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label fw-semibold small mb-1">BF</label>
            <select name="bf" class="form-select form-select-sm">
                <option value="" <?= $bf === '' ? 'selected' : '' ?>>Todos</option>
                <option value="1" <?= $bf === '1' ? 'selected' : '' ?>>Só BF</option>
                <option value="0" <?= $bf === '0' ? 'selected' : '' ?>>Só não-BF</option>
            </select>
        </div>
        <div class="col-6 col-md-1">
            <button class="btn btn-primary btn-sm w-100" title="Filtrar"><i class="bi bi-funnel"></i></button>
        </div>
    </div>
</form>

<div class="row g-3 mb-4">
    <?php
    // "Descontos Aplicados" = descontos em cascata (canal + cliente + comercial/diretoria +
    // campanha) + crédito — as duas etapas do waterfall entre o preço de tabela e os impostos.
    // O desconto financeiro (Pix) fica em "Custo MP + Despesas", junto com o custo fixo.
    $totDescontos    = $tot['descontos'] + $tot['credito'];
    $totDescontosPct = $tot['produtos'] > 0 ? $totDescontos / $tot['produtos'] * 100 : 0;
    // Abertura por tipo, no title do card (passe o mouse para ver).
    $descontosDet = [
        'Canal'               => $tot['canal'],
        'Cliente'             => $tot['cliente'],
        'Comercial/Diretoria' => $tot['comercial'],
        'Campanha'            => $tot['campanha'],
        'Crédito aplicado'    => $tot['credito'],
    ];
    $tituloDescontos = implode("\n", array_map(
        fn($k, $v) => $k . ': ' . moedaBR($v),
        array_keys($descontosDet), $descontosDet
    ));
    $resumo = [
        ['Valor de Tabela', $tot['produtos'], 'secondary'],
        ['Descontos Aplicados', $totDescontos, 'danger', $totDescontosPct, $tituloDescontos],
        ['Carga de Impostos', $tot['impostos'], 'warning', $totImpostosPct],
        ['Custo MP + Despesas', $tot['mp'] + $tot['despesas'], 'info'],
        ['Margem Final', $tot['margem'], $corMargem($totMargemPct), $totMargemPct],
    ];
    foreach ($resumo as $r): ?>
    <div class="col-6 col-md-4 col-xl">
        <div class="card shadow-sm border-0 border-start border-4 border-<?= $r[2] ?> h-100"<?= isset($r[4]) ? ' title="' . e($r[4]) . '"' : '' ?>>
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
.th-sort{cursor:pointer;user-select:none;white-space:nowrap}
</style>
<div class="card shadow-sm border-0">
    <div class="card-body p-0"><div class="table-responsive tbl-fixa-wrap">
    <table class="table table-hover table-sm align-middle mb-0 tbl-fixa" style="font-size:.85rem">
        <thead class="table-light">
            <tr>
                <th class="th-sort" data-tipo="num">Nº A&amp;M</th>
                <th class="th-sort">Pedido</th>
                <th class="th-sort">Cód. Cliente</th>
                <th class="th-sort">Cliente</th>
                <th class="th-sort">Data</th>
                <th class="th-sort text-end" data-tipo="num">Valor Tabela</th>
                <th class="th-sort text-end" data-tipo="num">Descontos</th>
                <th class="th-sort text-end" data-tipo="num">Crédito</th>
                <th class="th-sort text-end" data-tipo="num">Carga Impostos</th>
                <th class="th-sort text-end" data-tipo="num">Custo MP</th>
                <th class="th-sort text-end" data-tipo="num">Despesas</th>
                <th class="th-sort text-end" data-tipo="num">Margem</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php if ($linhas): foreach ($linhas as $l): ?>
            <tr>
                <td class="fw-semibold" data-v="<?= e($l['num_am']) ?>">
                    <?= e($l['num_am']) ?>
                    <?php if ($l['eh_bf']): ?><span class="badge bg-primary ms-1">BF</span><?php endif; ?>
                </td>
                <td data-v="<?= e($l['numero']) ?>">
                    <?= e($l['numero']) ?>
                    <span class="d-block mt-1" style="font-size:.7rem"><?= statusBadge($l['status']) ?></span>
                </td>
                <td class="text-nowrap"><?= $l['cod_cli'] !== '' ? e($l['cod_cli']) : '—' ?></td>
                <td class="text-truncate" style="max-width:190px" title="<?= e($l['cliente']) ?>"><?= e($l['cliente']) ?></td>
                <td class="text-nowrap" data-v="<?= e($l['data']) ?>"><?= dataBR($l['data']) ?></td>
                <td class="text-end" data-v="<?= $l['produtos'] ?>"><?= moedaBR($l['produtos']) ?></td>
                <td class="text-end text-danger" data-v="<?= $l['descontos'] ?>"><?= $l['descontos'] ? '− ' . moedaBR($l['descontos']) : '—' ?></td>
                <td class="text-end text-danger" data-v="<?= $l['credito'] ?>"><?= $l['credito'] ? '− ' . moedaBR($l['credito']) : '—' ?></td>
                <td class="text-end text-danger" data-v="<?= $l['impostos'] ?>">
                    − <?= moedaBR($l['impostos']) ?>
                    <span class="text-muted d-block" style="font-size:.75rem"><?= $pctFmt($l['impostos_pct']) ?></span>
                </td>
                <td class="text-end text-danger" data-v="<?= $l['mp'] ?>"><?= $l['mp'] ? '− ' . moedaBR($l['mp']) : '—' ?></td>
                <td class="text-end text-danger" data-v="<?= $l['despesas'] ?>"><?= $l['despesas'] ? '− ' . moedaBR($l['despesas']) : '—' ?></td>
                <td class="text-end fw-bold" data-v="<?= $l['margem'] ?>">
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
            <tr><td colspan="13" class="text-center text-muted py-4">Nenhum pedido importado do A&amp;M no período<?= $status ? ' com status “' . e($statusLabels[$status]) . '”' : '' ?>.</td></tr>
        <?php endif; ?>
        </tbody>
        <?php if ($linhas): ?>
        <tfoot class="table-light fw-semibold">
            <tr>
                <td colspan="5">Total — <?= count($linhas) ?> pedido(s)</td>
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
<script>
// Filtro de cliente: escolher uma sugestão já aplica o filtro.
(function () {
    var inp = document.getElementById('filtroCli');
    var opcoes = Array.prototype.map.call(document.querySelectorAll('#listaClientes option'), function (o) { return o.value; });
    inp.addEventListener('input', function () {
        if (opcoes.indexOf(inp.value) !== -1) inp.form.submit();
    });
})();

// Cabeçalho clicável = ordena as linhas do tbody (o tfoot de totais fica fixo).
// Usa data-v da célula quando existe (valores numéricos/datas crus); senão, o texto.
(function () {
    var tabela = document.querySelector('.tbl-fixa');
    if (!tabela) return;
    var ths = Array.prototype.slice.call(tabela.querySelectorAll('thead th'));
    var col = null, dir = 1;
    ths.forEach(function (th, i) {
        if (!th.classList.contains('th-sort')) return;
        th.title = 'Clique para ordenar';
        th.insertAdjacentHTML('beforeend', ' <i class="bi bi-arrow-down-up text-muted opacity-50" style="font-size:.7em"></i>');
        th.addEventListener('click', function () {
            dir = (col === i) ? -dir : 1;
            col = i;
            var num = th.dataset.tipo === 'num';
            var tbody = tabela.tBodies[0];
            var linhas = Array.prototype.slice.call(tbody.rows).filter(function (r) { return r.cells.length > 1; });
            var val = function (r) {
                var c = r.cells[i];
                var v = c.dataset.v !== undefined ? c.dataset.v : c.textContent.trim();
                return num ? (parseFloat(v) || 0) : v.toLocaleLowerCase('pt-BR');
            };
            linhas.sort(function (a, b) {
                var va = val(a), vb = val(b);
                return (num ? va - vb : va.localeCompare(vb, 'pt-BR', { numeric: true })) * dir;
            });
            linhas.forEach(function (r) { tbody.appendChild(r); });
            ths.forEach(function (h) {
                var ic = h.querySelector('i.bi');
                if (!ic) return;
                h.classList.toggle('table-active', h === th);
                ic.className = h === th
                    ? 'bi bi-caret-' + (dir > 0 ? 'up' : 'down') + '-fill'
                    : 'bi bi-arrow-down-up text-muted opacity-50';
            });
        });
    });
})();
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
