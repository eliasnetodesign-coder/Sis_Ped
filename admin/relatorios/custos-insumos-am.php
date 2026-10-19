<?php
require_once __DIR__ . '/../../config.php';
requireComercial();

$self = BASE_URL . '/admin/relatorios/custos-insumos-am.php';
$tiposNome = ['mp' => 'Matéria-prima', 'emb' => 'Material de embalagem'];

// ── Importação: "Listagem Resumida" do A&M (.xlsx), um ou vários arquivos de uma vez ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['arquivos'])) {
    $f = $_FILES['arquivos'];
    $ok = []; $erros = []; $ultTipo = null;
    foreach ((array)$f['name'] as $i => $nome) {
        if ($nome === '') continue;
        if ($f['error'][$i] !== UPLOAD_ERR_OK) { $erros[] = "$nome: falha no envio (código {$f['error'][$i]})."; continue; }
        try {
            $d = custosInsumosLerXlsx($f['tmp_name'][$i]);
            $r = custosInsumosImportar($d, $nome);
            $ultTipo = $d['tipo'];
            $ok[] = "$nome: {$tiposNome[$d['tipo']]}, {$r['linhas']} compras de " . dataBR($d['ini']) . ' a ' . dataBR($d['fim'])
                  . ($r['substituidas'] ? " (substituiu {$r['substituidas']} já importadas no período)" : '') . '.';
        } catch (Throwable $e) {
            $erros[] = "$nome: " . $e->getMessage();
        }
    }
    if (!$ok && !$erros) $erros[] = 'Nenhum arquivo selecionado.';
    flash($erros ? ($ok ? 'warning' : 'danger') : 'success', implode(' ', array_merge($ok ? ['Importado — ' . implode(' ', $ok)] : [], $erros)));
    header('Location: ' . $self . ($ultTipo ? '?tipo=' . $ultTipo : ''));
    exit;
}

// ── Filtros ──
$dataOk = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
$tipo  = ($_GET['tipo'] ?? 'mp') === 'emb' ? 'emb' : 'mp';
$ini   = $dataOk($_GET['ini'] ?? null) ?: date('Y-01-01');
$fim   = $dataOk($_GET['fim'] ?? null) ?: date('Y-m-d');
if ($fim < $ini) [$ini, $fim] = [$fim, $ini];
$forn  = trim($_GET['fornecedor'] ?? '');
$q     = trim($_GET['q'] ?? '');
$visao = in_array($_GET['visao'] ?? '', ['resumo', 'periodo', 'mensal', 'fornecedor', 'compras'], true) ? $_GET['visao'] : 'resumo';
// Comparativo Período A × B (independe do período principal). Padrão: B = 3 últimos meses até o fim; A = 3 meses antes.
$iniB = $dataOk($_GET['ini_b'] ?? null) ?: date('Y-m-01', strtotime(date('Y-m-01', strtotime($fim)) . ' -2 months'));
$fimB = $dataOk($_GET['fim_b'] ?? null) ?: $fim;
$iniA = $dataOk($_GET['ini_a'] ?? null) ?: date('Y-m-d', strtotime($iniB . ' -3 months'));
$fimA = $dataOk($_GET['fim_a'] ?? null) ?: date('Y-m-d', strtotime($iniB . ' -1 day'));
$soAmbos = ($_GET['ambos'] ?? '1') === '1';

// Opções dos filtros (do tipo escolhido, todas as datas)
$st = db()->prepare('SELECT fornecedor_codigo, MAX(fornecedor_nome) AS nome FROM custos_insumos_compras WHERE tipo = ?
                     GROUP BY fornecedor_codigo ORDER BY nome');
$st->execute([$tipo]);
$fornecedores = $st->fetchAll();
$st = db()->prepare('SELECT insumo_codigo, MAX(insumo_nome) AS nome FROM custos_insumos_compras WHERE tipo = ?
                     GROUP BY insumo_codigo ORDER BY nome');
$st->execute([$tipo]);
$insumosOpc = $st->fetchAll();

$buscar = function (string $de, string $ate) use ($tipo, $forn, $q): array {
    $sql = 'SELECT * FROM custos_insumos_compras WHERE tipo = ? AND data BETWEEN ? AND ?';
    $par = [$tipo, $de, $ate];
    if ($forn !== '') { $sql .= ' AND fornecedor_codigo = ?'; $par[] = $forn; }
    if ($q !== '')    { $sql .= ' AND (insumo_codigo = ? OR insumo_nome LIKE ?)'; $par[] = $q; $par[] = '%' . $q . '%'; }
    $st = db()->prepare($sql . ' ORDER BY data, id');
    $st->execute($par);
    return $st->fetchAll();
};
$compras = $buscar($ini, $fim);

// Agrupamento por insumo + unidade (o mesmo insumo pode vir em unidades diferentes — ex.: fita em M e PC —
// e preço por metro não se compara com preço por peça). Preço médio = ponderado pela quantidade.
$chave = fn($c) => $c['insumo_codigo'] . '|' . $c['unidade'];
$novoAcc = fn() => ['n' => 0, 'qtd' => 0.0, 'valor' => 0.0, 'min' => null, 'max' => null];
$acumula = function (array &$a, array $c) {
    $vu = (float)$c['valor_unitario'];
    $a['n']++;
    $a['qtd']   += (float)$c['quantidade'];
    $a['valor'] += (float)$c['quantidade'] * $vu;
    // Data do menor/maior preço: compras vêm em ordem de data, então no empate fica a mais recente.
    if ($a['min'] === null || $vu <= $a['min']) { $a['min'] = $vu; $a['min_data'] = $c['data']; }
    if ($a['max'] === null || $vu >= $a['max']) { $a['max'] = $vu; $a['max_data'] = $c['data']; }
};
$pm = fn(array $a) => $a['qtd'] > 0 ? $a['valor'] / $a['qtd'] : ($a['n'] ? $a['min'] : null);

$insumos = []; $porForn = []; $meses = []; $totForn = []; $total = $novoAcc();
foreach ($compras as $c) {
    $k = $chave($c);
    if (!isset($insumos[$k])) {
        $insumos[$k] = ['codigo' => $c['insumo_codigo'], 'nome' => $c['insumo_nome'], 'un' => $c['unidade'], 'acc' => $novoAcc(),
                        'primeiro' => $c, 'ultimo' => $c, 'forn' => [], 'mes' => []];
    }
    $I = &$insumos[$k];
    $acumula($I['acc'], $c);
    $I['ultimo'] = $c;                                     // ordenado por data, id
    $fk = $c['fornecedor_codigo'];
    if (!isset($I['forn'][$fk])) $I['forn'][$fk] = ['nome' => $c['fornecedor_nome'], 'acc' => $novoAcc(), 'ultimo' => $c];
    $acumula($I['forn'][$fk]['acc'], $c);
    $I['forn'][$fk]['ultimo'] = $c;
    $m = substr($c['data'], 0, 7);
    if (!isset($I['mes'][$m])) $I['mes'][$m] = $novoAcc();
    $acumula($I['mes'][$m], $c);
    unset($I);
    if (!isset($totForn[$fk])) $totForn[$fk] = ['nome' => $c['fornecedor_nome'], 'acc' => $novoAcc(), 'insumos' => []];
    $acumula($totForn[$fk]['acc'], $c);
    $totForn[$fk]['insumos'][$k] = 1;
    $acumula($total, $c);
}
uasort($insumos, fn($a, $b) => strcmp($a['nome'], $b['nome']));
uasort($totForn, fn($a, $b) => $b['acc']['valor'] <=> $a['acc']['valor']);
for ($m = substr($ini, 0, 7); $m <= substr($fim, 0, 7); $m = date('Y-m', strtotime($m . '-01 +1 month'))) $meses[] = $m;

// Período A × B
$comprasAB = $buscar(min($iniA, $iniB), max($fimA, $fimB));
$ab = [];
foreach ($comprasAB as $c) {
    $k = $chave($c);
    if (!isset($ab[$k])) $ab[$k] = ['codigo' => $c['insumo_codigo'], 'nome' => $c['insumo_nome'], 'un' => $c['unidade'], 'A' => $novoAcc(), 'B' => $novoAcc()];
    if ($c['data'] >= $iniA && $c['data'] <= $fimA) $acumula($ab[$k]['A'], $c);
    if ($c['data'] >= $iniB && $c['data'] <= $fimB) $acumula($ab[$k]['B'], $c);
}
$ab = array_filter($ab, fn($r) => $soAmbos ? ($r['A']['n'] && $r['B']['n']) : ($r['A']['n'] || $r['B']['n']));
uasort($ab, fn($a, $b) => strcmp($a['nome'], $b['nome']));
$abTot = ['impacto' => 0.0, 'baseA' => 0.0, 'baseB' => 0.0, 'sobe' => 0, 'desce' => 0, 'igual' => 0];
foreach ($ab as $k => $r) {
    if ($r['A']['n'] && $r['B']['n']) {
        $pa = $pm($r['A']); $pb = $pm($r['B']);
        $ab[$k]['var'] = $pa > 0 ? ($pb / $pa - 1) * 100 : null;
        $ab[$k]['impacto'] = $r['B']['qtd'] * ($pb - $pa);
        $abTot['impacto'] += $ab[$k]['impacto'];
        $abTot['baseA'] += $r['B']['qtd'] * $pa;           // cesta = quantidades compradas no período B
        $abTot['baseB'] += $r['B']['qtd'] * $pb;
        $abTot[abs($pb - $pa) < 0.00005 ? 'igual' : ($pb > $pa ? 'sobe' : 'desce')]++;
    } else {
        $ab[$k]['var'] = null; $ab[$k]['impacto'] = null;
    }
}
$abIndice = $abTot['baseA'] > 0 ? ($abTot['baseB'] / $abTot['baseA'] - 1) * 100 : null;

// Últimas importações
$st = db()->query('SELECT * FROM custos_insumos_importacoes ORDER BY id DESC LIMIT 10');
$importacoes = $st->fetchAll();
$st = db()->prepare('SELECT MIN(data), MAX(data), COUNT(*) FROM custos_insumos_compras WHERE tipo = ?');
$st->execute([$tipo]);
[$dadosIni, $dadosFim, $dadosQtd] = $st->fetch(PDO::FETCH_NUM);

// ── Formatação ──
function precoUn($v): string {
    if ($v === null) return '<span class="text-muted">—</span>';
    $s = number_format((float)$v, 4, ',', '.');
    $s = preg_replace('/(,\d\d)(\d*?)0+$/', '$1$2', $s);      // 4 casas só quando precisa (12,50 / 0,0975)
    return 'R$ ' . $s;
}
function qtdFmt($v): string {
    $v = (float)$v;
    return number_format($v, floor($v) == $v ? 0 : 3, ',', '.');
}
function varPct($v, bool $custo = true): string {
    if ($v === null) return '<span class="text-muted">—</span>';
    if (abs($v) < 0.005) return '<span class="text-muted">0,00%</span>';
    // Para custo, subir é ruim (vermelho) e cair é bom (verde).
    $cls = $v > 0 ? 'text-danger' : 'text-success';
    $ico = $v > 0 ? 'bi-arrow-up-short' : 'bi-arrow-down-short';
    return '<span class="' . $cls . ' fw-semibold text-nowrap"><i class="bi ' . $ico . '"></i>' . ($v > 0 ? '+' : '') . number_format($v, 2, ',', '.') . '%</span>';
}
$mesNome = function (string $ym): string {
    static $n = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    return $n[(int)substr($ym, 5, 2) - 1] . '/' . substr($ym, 2, 2);
};
$linkInsumo = function (string $codigo, string $nome, string $visaoDest = 'compras') use ($self, $tipo, $ini, $fim, $forn): string {
    $u = $self . '?' . http_build_query(['tipo' => $tipo, 'ini' => $ini, 'fim' => $fim, 'fornecedor' => $forn, 'q' => $codigo, 'visao' => $visaoDest]);
    return '<a href="' . e($u) . '" class="text-reset text-decoration-none" title="Ver as compras deste insumo">' . e($nome) . '</a>';
};
$ordAttrs = function (array $o): string {
    $s = '';
    foreach ($o as $k => $v) $s .= ' data-o-' . $k . '="' . e($v) . '"';
    return $s;
};
$thOrd = function (array $cols): string {
    $h = '';
    foreach ($cols as $k => [$rot, $cls, $tp]) {
        $h .= '<th class="col-ord ' . $cls . '" data-col="' . $k . '" data-tipo="' . $tp . '" tabindex="0" title="Clique para ordenar">' . $rot . '<i class="bi bi-arrow-down-up ord-ico"></i></th>';
    }
    return $h;
};

$pageTitle = 'Custos de MP e Embalagem';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <a href="<?= BASE_URL ?>/admin/relatorios/am.php" class="small text-muted text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Relatórios A&amp;M</a>
        <h4 class="fw-bold mb-0"><i class="bi bi-box2 me-2"></i>Custos de Matéria-prima e Embalagem</h4>
        <p class="text-muted small mb-0">
            Preço pago em cada compra (Listagem Resumida do A&amp;M), com comparativos por período e por fornecedor.
            <?php if ($dadosQtd): ?>
            Base de <?= e(mb_strtolower($tiposNome[$tipo])) ?>: <?= number_format($dadosQtd, 0, ',', '.') ?> compras de <?= dataBR($dadosIni) ?> a <?= dataBR($dadosFim) ?>.
            <?php endif; ?>
        </p>
    </div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalImportar">
        <i class="bi bi-upload me-1"></i>Importar planilhas
    </button>
</div>

<form method="GET" class="card shadow-sm border-0 mb-3" id="frmFiltro">
    <input type="hidden" name="visao" id="inpVisao" value="<?= e($visao) ?>">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-lg-auto">
                <label class="form-label small fw-semibold mb-1">Tipo</label>
                <div class="btn-group w-100" role="group">
                    <?php foreach ($tiposNome as $tk => $tn): ?>
                    <input type="radio" class="btn-check" name="tipo" id="tipo-<?= $tk ?>" value="<?= $tk ?>" <?= $tipo === $tk ? 'checked' : '' ?>
                           onchange="this.form.fornecedor.value='';this.form.q.value='';this.form.submit()">
                    <label class="btn btn-outline-primary" for="tipo-<?= $tk ?>"><i class="bi <?= $tk === 'mp' ? 'bi-droplet' : 'bi-box-seam' ?> me-1"></i><?= $tn ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label small fw-semibold mb-1">De</label>
                <input type="date" name="ini" class="form-control" value="<?= e($ini) ?>">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label small fw-semibold mb-1">Até</label>
                <input type="date" name="fim" class="form-control" value="<?= e($fim) ?>">
            </div>
            <div class="col-12 col-md-6 col-lg">
                <label class="form-label small fw-semibold mb-1">Insumo <span class="text-muted fw-normal">(código ou parte do nome)</span></label>
                <input type="search" name="q" class="form-control" list="lstInsumos" value="<?= e($q) ?>" placeholder="Todos">
                <datalist id="lstInsumos">
                    <?php foreach ($insumosOpc as $o): ?><option value="<?= e($o['insumo_codigo']) ?>"><?= e($o['nome']) ?></option><?php endforeach; ?>
                </datalist>
            </div>
            <div class="col-12 col-md-6 col-lg">
                <label class="form-label small fw-semibold mb-1">Fornecedor</label>
                <select name="fornecedor" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach ($fornecedores as $o): ?>
                    <option value="<?= e($o['fornecedor_codigo']) ?>" <?= $forn === $o['fornecedor_codigo'] ? 'selected' : '' ?>><?= e($o['fornecedor_codigo'] . ' - ' . $o['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-lg-auto d-flex gap-2">
                <button class="btn btn-primary flex-grow-1"><i class="bi bi-funnel me-1"></i>Filtrar</button>
                <a href="<?= $self ?>?tipo=<?= $tipo ?>" class="btn btn-outline-secondary" title="Limpar filtros"><i class="bi bi-x-lg"></i></a>
            </div>
        </div>
        <!-- campos do comparativo A × B (vivem no mesmo formulário para manter os filtros) -->
        <input type="hidden" name="ini_a" value="<?= e($iniA) ?>"><input type="hidden" name="fim_a" value="<?= e($fimA) ?>">
        <input type="hidden" name="ini_b" value="<?= e($iniB) ?>"><input type="hidden" name="fim_b" value="<?= e($fimB) ?>">
        <input type="hidden" name="ambos" value="<?= $soAmbos ? '1' : '0' ?>">
    </div>
</form>

<?php if (!$dadosQtd): ?>
<div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>Nenhuma compra de <?= e(mb_strtolower($tiposNome[$tipo])) ?> importada ainda. Use <b>Importar planilhas</b>.</div>
<?php endif; ?>

<style>
.tbl-fixa-wrap{max-height:70vh;overflow:auto}
.tbl-fixa thead th{position:sticky;top:0;z-index:3;background:var(--bs-tertiary-bg)!important;box-shadow:inset 0 -1px 0 var(--bs-border-color);white-space:nowrap}
.tbl-fixa tfoot td{position:sticky;bottom:0;z-index:3;background:var(--bs-tertiary-bg)!important;box-shadow:inset 0 1px 0 var(--bs-border-color)}
.tbl-fixa thead th.col-ord{cursor:pointer;user-select:none}
.tbl-fixa thead th.col-ord:hover{color:var(--bs-primary)}
.tbl-fixa thead th.col-ord .ord-ico{font-size:.7rem;opacity:.3;margin-left:.25rem}
.tbl-fixa thead th.col-ord.ord-ativa .ord-ico{opacity:1;color:var(--bs-primary)}
.tbl-fixa .col-fixa{position:sticky;left:0;z-index:2;background:var(--bs-body-bg)}
.tbl-fixa thead th.col-fixa{z-index:4}
.grp-ini td{border-top:2px solid var(--bs-border-color)}
.badge-menor{background:#eaf5ef;color:#1e6b3a;border:1px solid #9ed3b1}
.badge-maior{background:#fdecec;color:#a12828;border:1px solid #f1a9a9}
[data-bs-theme="dark"] .badge-menor{background:rgba(86,141,102,.22);color:#9ed3b1;border-color:rgba(158,211,177,.35)}
[data-bs-theme="dark"] .badge-maior{background:rgba(200,80,80,.2);color:#f1a9a9;border-color:rgba(241,169,169,.35)}
</style>

<div class="row g-3 mb-3">
    <?php
    $cards = [
        ['Total comprado', moedaBR($total['valor']), 'primary', dataBR($ini) . ' a ' . dataBR($fim)],
        ['Compras', number_format($total['n'], 0, ',', '.'), 'info', 'linhas de nota fiscal'],
        ['Insumos', number_format(count($insumos), 0, ',', '.'), 'info', 'insumo + unidade com compra'],
        ['Fornecedores', number_format(count($totForn), 0, ',', '.'), 'info', 'com compra no período'],
    ];
    foreach ($cards as [$rot, $val, $cor, $sub]): ?>
    <div class="col-6 col-xl-3">
        <div class="card shadow-sm border-0 border-start border-4 border-<?= $cor ?> h-100">
            <div class="card-body py-3">
                <div class="text-muted small fw-semibold text-uppercase"><?= e($rot) ?></div>
                <div class="fs-4 fw-bold"><?= $val ?></div>
                <div class="small text-muted"><?= e($sub) ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php
$nMultiForn = count(array_filter($insumos, fn($I) => count($I['forn']) > 1));
$abas = [
    'resumo'     => ['bi-list-ul', 'Resumo por insumo', count($insumos)],
    'periodo'    => ['bi-arrow-left-right', 'Período A × B', count($ab)],
    'mensal'     => ['bi-calendar3', 'Evolução mensal', count($insumos)],
    'fornecedor' => ['bi-truck', 'Por fornecedor', count($totForn)],
    'compras'    => ['bi-receipt', 'Compras', count($compras)],
];
?>
<ul class="nav nav-tabs mb-0" role="tablist">
    <?php foreach ($abas as $k => [$ico, $rot, $n]): ?>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $visao === $k ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#vis-<?= $k ?>" data-visao="<?= $k ?>" type="button" role="tab">
            <i class="bi <?= $ico ?> me-1"></i><?= $rot ?> <span class="badge text-bg-light border ms-1"><?= $n ?></span>
        </button>
    </li>
    <?php endforeach; ?>
</ul>
<div class="tab-content">

<!-- ══ Resumo por insumo ══ -->
<div class="tab-pane fade <?= $visao === 'resumo' ? 'show active' : '' ?>" id="vis-resumo" role="tabpanel">
<div class="card shadow-sm border-0"><div class="card-body p-0"><div class="table-responsive tbl-fixa-wrap">
<table class="table table-hover table-sm align-middle mb-0 tbl-fixa" style="font-size:.85rem">
    <thead class="table-light"><tr><?= $thOrd([
        'codigo' => ['Código', '', 'num'], 'nome' => ['Insumo', '', 'txt'], 'un' => ['Un.', '', 'txt'], 'n' => ['Compras', 'text-end', 'num'],
        'qtd' => ['Qtd. total', 'text-end', 'num'], 'valor' => ['Valor total', 'text-end', 'num'], 'pm' => ['Preço médio', 'text-end', 'num'],
        'min' => ['Menor', 'text-end', 'num'], 'max' => ['Maior', 'text-end', 'num'], 'pri' => ['1º preço', 'text-end', 'num'],
        'ult' => ['Último preço', 'text-end', 'num'], 'var' => ['Var. 1º → último', 'text-end', 'num'], 'nforn' => ['Forn.', 'text-end', 'num'],
    ]) ?></tr></thead>
    <tbody>
    <?php foreach ($insumos as $I):
        $a = $I['acc']; $p1 = (float)$I['primeiro']['valor_unitario']; $pu = (float)$I['ultimo']['valor_unitario'];
        $var = $I['primeiro']['id'] !== $I['ultimo']['id'] && $p1 > 0 ? ($pu / $p1 - 1) * 100 : null; ?>
        <tr class="lin-ord"<?= $ordAttrs(['codigo' => $I['codigo'], 'nome' => $I['nome'], 'un' => $I['un'], 'n' => $a['n'], 'qtd' => $a['qtd'],
            'valor' => round($a['valor'], 2), 'pm' => $pm($a), 'min' => $a['min'], 'max' => $a['max'], 'pri' => $p1, 'ult' => $pu,
            'var' => $var ?? -99999, 'nforn' => count($I['forn'])]) ?>>
            <td class="text-muted"><?= e($I['codigo']) ?></td>
            <td class="fw-semibold"><?= $linkInsumo($I['codigo'], $I['nome']) ?></td>
            <td><?= e($I['un']) ?></td>
            <td class="text-end"><?= $a['n'] ?></td>
            <td class="text-end"><?= qtdFmt($a['qtd']) ?></td>
            <td class="text-end"><?= moedaBR($a['valor']) ?></td>
            <td class="text-end fw-semibold"><?= precoUn($pm($a)) ?></td>
            <td class="text-end"><?= precoUn($a['min']) ?><div class="small text-muted"><?= dataBR($a['min_data']) ?></div></td>
            <td class="text-end"><?= precoUn($a['max']) ?><div class="small text-muted"><?= dataBR($a['max_data']) ?></div></td>
            <td class="text-end" title="<?= dataBR($I['primeiro']['data']) ?>"><?= precoUn($p1) ?><div class="small text-muted"><?= dataBR($I['primeiro']['data']) ?></div></td>
            <td class="text-end" title="<?= e($I['ultimo']['fornecedor_nome']) ?>"><?= precoUn($pu) ?><div class="small text-muted"><?= dataBR($I['ultimo']['data']) ?></div></td>
            <td class="text-end"><?= varPct($var) ?></td>
            <td class="text-end"><?= count($I['forn']) ?></td>
        </tr>
    <?php endforeach; if (!$insumos): ?>
        <tr><td colspan="13" class="text-center text-muted py-4">Nenhuma compra com estes filtros.</td></tr>
    <?php endif; ?>
    </tbody>
    <?php if ($insumos): ?>
    <tfoot><tr class="fw-bold"><td colspan="3">Total</td><td class="text-end"><?= $total['n'] ?></td><td></td>
        <td class="text-end"><?= moedaBR($total['valor']) ?></td><td colspan="7"></td></tr></tfoot>
    <?php endif; ?>
</table>
</div></div></div>
<p class="small text-muted mt-2 mb-0">Preço médio = ponderado pela quantidade (Σ qtd × valor unitário ÷ Σ qtd). “Var. 1º → último” compara o preço da primeira
    e da última compra do período. O mesmo insumo em unidades diferentes (ex.: M e PC) aparece em linhas separadas. Clique no nome para ver as compras.</p>
</div>

<!-- ══ Período A × B ══ -->
<div class="tab-pane fade <?= $visao === 'periodo' ? 'show active' : '' ?>" id="vis-periodo" role="tabpanel">
<div class="card shadow-sm border-0 mb-3"><div class="card-body">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-3 col-xl-2"><label class="form-label small fw-semibold mb-1">Período A — de</label>
            <input type="date" class="form-control form-control-sm" data-ab="ini_a" value="<?= e($iniA) ?>"></div>
        <div class="col-6 col-md-3 col-xl-2"><label class="form-label small fw-semibold mb-1">até</label>
            <input type="date" class="form-control form-control-sm" data-ab="fim_a" value="<?= e($fimA) ?>"></div>
        <div class="col-6 col-md-3 col-xl-2"><label class="form-label small fw-semibold mb-1">Período B — de</label>
            <input type="date" class="form-control form-control-sm" data-ab="ini_b" value="<?= e($iniB) ?>"></div>
        <div class="col-6 col-md-3 col-xl-2"><label class="form-label small fw-semibold mb-1">até</label>
            <input type="date" class="form-control form-control-sm" data-ab="fim_b" value="<?= e($fimB) ?>"></div>
        <div class="col-12 col-xl-auto">
            <div class="form-check mb-1">
                <input class="form-check-input" type="checkbox" id="chkAmbos" <?= $soAmbos ? 'checked' : '' ?>>
                <label class="form-check-label small" for="chkAmbos">Só insumos comprados nos dois períodos</label>
            </div>
        </div>
        <div class="col-12 col-xl-auto"><button type="button" class="btn btn-sm btn-primary" onclick="aplicarAB()"><i class="bi bi-arrow-repeat me-1"></i>Comparar</button></div>
    </div>
    <div class="small text-muted mt-2">Os períodos A e B substituem o “De/Até” do filtro nesta aba; tipo, insumo e fornecedor continuam valendo.</div>
</div></div>

<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3"><div class="card shadow-sm border-0 border-start border-4 border-<?= $abIndice === null ? 'secondary' : ($abIndice > 0 ? 'danger' : 'success') ?> h-100"><div class="card-body py-3">
        <div class="text-muted small fw-semibold text-uppercase">Variação ponderada</div>
        <div class="fs-4 fw-bold"><?= varPct($abIndice) ?></div>
        <div class="small text-muted">cesta = quantidades compradas em B</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card shadow-sm border-0 border-start border-4 border-<?= $abTot['impacto'] > 0 ? 'danger' : 'success' ?> h-100"><div class="card-body py-3">
        <div class="text-muted small fw-semibold text-uppercase">Impacto em B</div>
        <div class="fs-4 fw-bold <?= $abTot['impacto'] > 0 ? 'text-danger' : 'text-success' ?>"><?= ($abTot['impacto'] > 0 ? '+' : '') . moedaBR($abTot['impacto']) ?></div>
        <div class="small text-muted">gasto a mais (+) ou a menos (−) pelos preços de A</div></div></div></div>
    <div class="col-12 col-xl-6"><div class="card shadow-sm border-0 border-start border-4 border-info h-100"><div class="card-body py-3">
        <div class="text-muted small fw-semibold text-uppercase">Insumos comparáveis</div>
        <div class="d-flex gap-4 flex-wrap mt-1">
            <span class="text-danger fw-semibold"><i class="bi bi-arrow-up"></i> <?= $abTot['sobe'] ?> subiram</span>
            <span class="text-success fw-semibold"><i class="bi bi-arrow-down"></i> <?= $abTot['desce'] ?> caíram</span>
            <span class="text-muted fw-semibold"><i class="bi bi-dash"></i> <?= $abTot['igual'] ?> sem variação</span>
        </div>
        <div class="small text-muted">A: <?= dataBR($iniA) ?> a <?= dataBR($fimA) ?> · B: <?= dataBR($iniB) ?> a <?= dataBR($fimB) ?></div></div></div></div>
</div>

<div class="card shadow-sm border-0"><div class="card-body p-0"><div class="table-responsive tbl-fixa-wrap">
<table class="table table-hover table-sm align-middle mb-0 tbl-fixa" style="font-size:.85rem">
    <thead class="table-light"><tr><?= $thOrd([
        'codigo' => ['Código', '', 'num'], 'nome' => ['Insumo', '', 'txt'], 'un' => ['Un.', '', 'txt'],
        'na' => ['Compras A', 'text-end', 'num'], 'qa' => ['Qtd. A', 'text-end', 'num'], 'pa' => ['Preço médio A', 'text-end', 'num'],
        'nb' => ['Compras B', 'text-end', 'num'], 'qb' => ['Qtd. B', 'text-end', 'num'], 'pb' => ['Preço médio B', 'text-end', 'num'],
        'dif' => ['Dif. R$/un.', 'text-end', 'num'], 'var' => ['Var. %', 'text-end', 'num'], 'imp' => ['Impacto em B', 'text-end', 'num'],
    ]) ?></tr></thead>
    <tbody>
    <?php foreach ($ab as $r):
        $pa = $r['A']['n'] ? $pm($r['A']) : null; $pb = $r['B']['n'] ? $pm($r['B']) : null;
        $dif = $pa !== null && $pb !== null ? $pb - $pa : null; ?>
        <tr class="lin-ord"<?= $ordAttrs(['codigo' => $r['codigo'], 'nome' => $r['nome'], 'un' => $r['un'], 'na' => $r['A']['n'], 'qa' => $r['A']['qtd'],
            'pa' => $pa ?? -1, 'nb' => $r['B']['n'], 'qb' => $r['B']['qtd'], 'pb' => $pb ?? -1, 'dif' => $dif ?? -99999,
            'var' => $r['var'] ?? -99999, 'imp' => $r['impacto'] ?? -9999999]) ?>>
            <td class="text-muted"><?= e($r['codigo']) ?></td>
            <td class="fw-semibold"><?= $linkInsumo($r['codigo'], $r['nome']) ?></td>
            <td><?= e($r['un']) ?></td>
            <td class="text-end"><?= $r['A']['n'] ?: '<span class="text-muted">—</span>' ?></td>
            <td class="text-end"><?= $r['A']['n'] ? qtdFmt($r['A']['qtd']) : '' ?></td>
            <td class="text-end"><?= precoUn($pa) ?></td>
            <td class="text-end"><?= $r['B']['n'] ?: '<span class="text-muted">—</span>' ?></td>
            <td class="text-end"><?= $r['B']['n'] ? qtdFmt($r['B']['qtd']) : '' ?></td>
            <td class="text-end fw-semibold"><?= precoUn($pb) ?></td>
            <td class="text-end"><?= $dif === null ? '<span class="text-muted">—</span>' : ($dif > 0 ? '+' : '') . precoUn($dif) ?></td>
            <td class="text-end"><?= varPct($r['var']) ?></td>
            <td class="text-end <?= $r['impacto'] > 0 ? 'text-danger' : ($r['impacto'] < 0 ? 'text-success' : '') ?>"><?= $r['impacto'] === null ? '<span class="text-muted">—</span>' : moedaBR($r['impacto']) ?></td>
        </tr>
    <?php endforeach; if (!$ab): ?>
        <tr><td colspan="12" class="text-center text-muted py-4">Nenhum insumo para comparar nesses períodos.</td></tr>
    <?php endif; ?>
    </tbody>
    <?php if ($ab): ?>
    <tfoot><tr class="fw-bold"><td colspan="10">Total</td><td class="text-end"><?= varPct($abIndice) ?></td>
        <td class="text-end <?= $abTot['impacto'] > 0 ? 'text-danger' : 'text-success' ?>"><?= moedaBR($abTot['impacto']) ?></td></tr></tfoot>
    <?php endif; ?>
</table>
</div></div></div>
<p class="small text-muted mt-2 mb-0">Preço médio ponderado pela quantidade em cada período. Impacto em B = Qtd. B × (Preço B − Preço A): quanto se pagou a mais (+, vermelho)
    ou a menos (−, verde) em B em relação aos preços de A. Variação ponderada = Σ Qtd. B × Preço B ÷ Σ Qtd. B × Preço A − 1, só com insumos dos dois períodos.</p>
</div>

<!-- ══ Evolução mensal ══ -->
<div class="tab-pane fade <?= $visao === 'mensal' ? 'show active' : '' ?>" id="vis-mensal" role="tabpanel">
<div class="card shadow-sm border-0"><div class="card-body p-0"><div class="table-responsive tbl-fixa-wrap">
<table class="table table-hover table-sm align-middle mb-0 tbl-fixa" style="font-size:.82rem">
    <thead class="table-light"><tr>
        <th class="col-ord col-fixa" data-col="nome" data-tipo="txt" tabindex="0" title="Clique para ordenar">Insumo<i class="bi bi-arrow-down-up ord-ico"></i></th>
        <th>Un.</th>
        <?php foreach ($meses as $m): ?><th class="text-end"><?= $mesNome($m) ?></th><?php endforeach; ?>
        <th class="col-ord text-end" data-col="var" data-tipo="num" tabindex="0" title="Clique para ordenar">Var. 1º → último mês<i class="bi bi-arrow-down-up ord-ico"></i></th>
    </tr></thead>
    <tbody>
    <?php foreach ($insumos as $I):
        $ant = null; $pPri = null; $pUlt = null; $cel = '';
        foreach ($meses as $m) {
            if (!isset($I['mes'][$m])) { $cel .= '<td class="text-end text-muted">·</td>'; continue; }
            $p = $pm($I['mes'][$m]);
            $pPri = $pPri ?? $p; $pUlt = $p;
            $cls = $ant === null || abs($p - $ant) < 0.00005 ? '' : ($p > $ant ? 'text-danger' : 'text-success');
            $tit = $I['mes'][$m]['n'] . ' compra(s), ' . qtdFmt($I['mes'][$m]['qtd']) . ' ' . $I['un'];
            if ($ant !== null && $ant > 0) $tit .= ', ' . ($p >= $ant ? '+' : '') . number_format(($p / $ant - 1) * 100, 2, ',', '.') . '% vs mês anterior com compra';
            $cel .= '<td class="text-end text-nowrap ' . $cls . '" title="' . e($tit) . '">' . precoUn($p) . '</td>';
            $ant = $p;
        }
        $varM = $pPri && $pUlt !== null && count($I['mes']) > 1 ? ($pUlt / $pPri - 1) * 100 : null; ?>
        <tr class="lin-ord"<?= $ordAttrs(['nome' => $I['nome'], 'var' => $varM ?? -99999]) ?>>
            <td class="fw-semibold col-fixa"><?= $linkInsumo($I['codigo'], $I['nome']) ?> <span class="text-muted fw-normal small"><?= e($I['codigo']) ?></span></td>
            <td><?= e($I['un']) ?></td>
            <?= $cel ?>
            <td class="text-end"><?= varPct($varM) ?></td>
        </tr>
    <?php endforeach; if (!$insumos): ?>
        <tr><td colspan="<?= count($meses) + 3 ?>" class="text-center text-muted py-4">Nenhuma compra com estes filtros.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div></div></div>
<p class="small text-muted mt-2 mb-0">Preço médio ponderado de cada mês. Vermelho = mais caro que o mês anterior com compra; verde = mais barato; “·” = sem compra no mês.
    Passe o mouse no valor para ver as compras e a variação.</p>
</div>

<!-- ══ Por fornecedor ══ -->
<div class="tab-pane fade <?= $visao === 'fornecedor' ? 'show active' : '' ?>" id="vis-fornecedor" role="tabpanel">
<div class="row g-3">
<div class="col-12 col-xxl-4">
    <h6 class="fw-bold mt-3 mb-2">Totais por fornecedor</h6>
    <div class="card shadow-sm border-0"><div class="card-body p-0"><div class="table-responsive tbl-fixa-wrap" style="max-height:60vh">
    <table class="table table-hover table-sm align-middle mb-0 tbl-fixa" style="font-size:.85rem">
        <thead class="table-light"><tr><?= $thOrd([
            'nome' => ['Fornecedor', '', 'txt'], 'ins' => ['Insumos', 'text-end', 'num'], 'n' => ['Compras', 'text-end', 'num'],
            'valor' => ['Valor', 'text-end', 'num'], 'pct' => ['%', 'text-end', 'num'],
        ]) ?></tr></thead>
        <tbody>
        <?php foreach ($totForn as $fk => $F): $pct = $total['valor'] > 0 ? $F['acc']['valor'] / $total['valor'] * 100 : 0; ?>
            <tr class="lin-ord"<?= $ordAttrs(['nome' => $F['nome'], 'ins' => count($F['insumos']), 'n' => $F['acc']['n'], 'valor' => round($F['acc']['valor'], 2), 'pct' => $pct]) ?>>
                <td><span class="fw-semibold"><?= e($F['nome']) ?></span> <span class="text-muted small"><?= e($fk) ?></span></td>
                <td class="text-end"><?= count($F['insumos']) ?></td>
                <td class="text-end"><?= $F['acc']['n'] ?></td>
                <td class="text-end"><?= moedaBR($F['acc']['valor']) ?></td>
                <td class="text-end"><?= number_format($pct, 1, ',', '.') ?>%</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div></div></div>
</div>
<div class="col-12 col-xxl-8">
    <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 mb-2 gap-2">
        <h6 class="fw-bold mb-0">Mesmo insumo em fornecedores diferentes</h6>
        <div class="form-check mb-0">
            <input class="form-check-input" type="checkbox" id="chkMulti" checked onchange="filtraMulti()">
            <label class="form-check-label small" for="chkMulti">Só insumos com 2 ou mais fornecedores (<?= $nMultiForn ?>)</label>
        </div>
    </div>
    <div class="card shadow-sm border-0"><div class="card-body p-0"><div class="table-responsive tbl-fixa-wrap" style="max-height:60vh">
    <table class="table table-sm align-middle mb-0 tbl-fixa" id="tblMulti" style="font-size:.85rem">
        <thead class="table-light"><tr>
            <th>Insumo</th><th>Fornecedor</th><th class="text-end">Compras</th><th class="text-end">Qtd.</th>
            <th class="text-end">Preço médio</th><th class="text-end">Menor</th><th class="text-end">Último preço</th><th class="text-end">vs. mais barato</th>
        </tr></thead>
        <tbody>
        <?php foreach ($insumos as $I):
            $fs = $I['forn'];
            uasort($fs, fn($a, $b) => $pm($a['acc']) <=> $pm($b['acc']));
            $menor = $pm(reset($fs)['acc']); $maior = $pm(end($fs)['acc']);
            $multi = count($fs) > 1; $j = 0;
            foreach ($fs as $fk => $F): $p = $pm($F['acc']); ?>
            <tr class="<?= $j === 0 ? 'grp-ini' : '' ?> <?= $multi ? 'multi' : 'unico d-none' ?>">
                <?php if ($j++ === 0): ?>
                <td rowspan="<?= count($fs) ?>" class="fw-semibold"><?= $linkInsumo($I['codigo'], $I['nome']) ?>
                    <div class="small text-muted fw-normal"><?= e($I['codigo']) ?> · <?= e($I['un']) ?></div></td>
                <?php endif; ?>
                <td><?= e($F['nome']) ?>
                    <?php if ($multi && abs($p - $menor) < 0.00005): ?><span class="badge badge-menor ms-1">menor preço</span>
                    <?php elseif ($multi && abs($p - $maior) < 0.00005): ?><span class="badge badge-maior ms-1">maior preço</span><?php endif; ?></td>
                <td class="text-end"><?= $F['acc']['n'] ?></td>
                <td class="text-end"><?= qtdFmt($F['acc']['qtd']) ?></td>
                <td class="text-end fw-semibold"><?= precoUn($p) ?></td>
                <td class="text-end"><?= precoUn($F['acc']['min']) ?><div class="small text-muted"><?= dataBR($F['acc']['min_data']) ?></div></td>
                <td class="text-end"><?= precoUn($F['ultimo']['valor_unitario']) ?><div class="small text-muted"><?= dataBR($F['ultimo']['data']) ?></div></td>
                <td class="text-end"><?= $multi && $menor > 0 ? varPct(($p / $menor - 1) * 100) : '<span class="text-muted">—</span>' ?></td>
            </tr>
            <?php endforeach;
        endforeach; ?>
        <tr id="multiVazio" class="<?= $nMultiForn ? 'd-none' : '' ?>"><td colspan="8" class="text-center text-muted py-4">Nenhum insumo comprado de mais de um fornecedor nestes filtros.</td></tr>
        </tbody>
    </table>
    </div></div></div>
    <p class="small text-muted mt-2 mb-0">Fornecedores ordenados do menor para o maior preço médio no período. “vs. mais barato” = quanto o preço médio do fornecedor está acima do mais barato.</p>
</div>
</div>
</div>

<!-- ══ Compras ══ -->
<div class="tab-pane fade <?= $visao === 'compras' ? 'show active' : '' ?>" id="vis-compras" role="tabpanel">
<div class="card shadow-sm border-0"><div class="card-body p-0"><div class="table-responsive tbl-fixa-wrap">
<table class="table table-hover table-sm align-middle mb-0 tbl-fixa" style="font-size:.85rem">
    <thead class="table-light"><tr><?= $thOrd([
        'data' => ['Data', '', 'txt'], 'codigo' => ['Código', '', 'num'], 'nome' => ['Insumo', '', 'txt'], 'forn' => ['Fornecedor', '', 'txt'],
        'nf' => ['NF', '', 'num'], 'qtd' => ['Qtd.', 'text-end', 'num'], 'un' => ['Un.', '', 'txt'], 'vu' => ['Valor unitário', 'text-end', 'num'],
        'tot' => ['Total', 'text-end', 'num'],
    ]) ?></tr></thead>
    <tbody>
    <?php foreach (array_reverse($compras) as $c): $tot = (float)$c['quantidade'] * (float)$c['valor_unitario']; ?>
        <tr class="lin-ord"<?= $ordAttrs(['data' => $c['data'] . str_pad($c['id'], 9, '0', STR_PAD_LEFT), 'codigo' => $c['insumo_codigo'], 'nome' => $c['insumo_nome'],
            'forn' => $c['fornecedor_nome'], 'nf' => $c['nf'], 'qtd' => $c['quantidade'], 'un' => $c['unidade'], 'vu' => $c['valor_unitario'], 'tot' => round($tot, 2)]) ?>>
            <td class="text-nowrap"><?= dataBR($c['data']) ?></td>
            <td class="text-muted"><?= e($c['insumo_codigo']) ?></td>
            <td class="fw-semibold"><?= e($c['insumo_nome']) ?></td>
            <td><?= e($c['fornecedor_nome']) ?> <span class="text-muted small"><?= e($c['fornecedor_codigo']) ?></span></td>
            <td><?= e($c['nf']) ?></td>
            <td class="text-end"><?= qtdFmt($c['quantidade']) ?></td>
            <td><?= e($c['unidade']) ?></td>
            <td class="text-end"><?= precoUn($c['valor_unitario']) ?></td>
            <td class="text-end"><?= moedaBR($tot) ?></td>
        </tr>
    <?php endforeach; if (!$compras): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">Nenhuma compra com estes filtros.</td></tr>
    <?php endif; ?>
    </tbody>
    <?php if ($compras): ?>
    <tfoot><tr class="fw-bold"><td colspan="8">Total (<?= count($compras) ?> compras)</td><td class="text-end"><?= moedaBR($total['valor']) ?></td></tr></tfoot>
    <?php endif; ?>
</table>
</div></div></div>
</div>

</div><!-- /tab-content -->

<!-- ══ Modal de importação ══ -->
<div class="modal fade" id="modalImportar" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form method="POST" enctype="multipart/form-data" class="modal-content" onsubmit="this.querySelector('[type=submit]').disabled=true;document.getElementById('impCarregando').classList.remove('d-none')">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Importar compras do A&amp;M</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small mb-2">Selecione a <b>Listagem Resumida</b> exportada do A&amp;M em Excel (.xlsx), de <b>Matéria Prima</b> e/ou de <b>Materiais de Embalagem</b>.
                    Pode enviar os dois arquivos juntos — o tipo, a empresa e o período são lidos do cabeçalho de cada planilha.</p>
                <input type="file" name="arquivos[]" class="form-control" accept=".xlsx" multiple required>
                <div class="alert alert-light border small mt-3 mb-0">
                    <i class="bi bi-arrow-repeat me-1"></i>Reimportar não duplica: as compras já gravadas do mesmo tipo e empresa
                    <b>dentro do período do arquivo</b> (“De … Até …”) são substituídas pelas do arquivo novo. Compras de fora desse período ficam como estão.
                </div>
                <div id="impCarregando" class="d-none text-center text-muted small mt-3"><span class="spinner-border spinner-border-sm me-2"></span>Importando…</div>
                <?php if ($importacoes): ?>
                <h6 class="fw-bold mt-4 mb-2">Últimas importações</h6>
                <div class="table-responsive">
                <table class="table table-sm small mb-0">
                    <thead><tr><th>Quando</th><th>Tipo</th><th>Período</th><th class="text-end">Compras</th><th>Arquivo</th><th>Usuário</th></tr></thead>
                    <tbody>
                    <?php foreach ($importacoes as $im): ?>
                    <tr>
                        <td class="text-nowrap"><?= date('d/m/Y H:i', strtotime($im['created_at'])) ?></td>
                        <td><?= e($tiposNome[$im['tipo']] ?? $im['tipo']) ?></td>
                        <td class="text-nowrap"><?= dataBR($im['periodo_ini']) ?> a <?= dataBR($im['periodo_fim']) ?></td>
                        <td class="text-end"><?= (int)$im['linhas'] ?><?= $im['substituidas'] ? ' <span class="text-muted" title="compras anteriores substituídas">(' . (int)$im['substituidas'] . ' subst.)</span>' : '' ?></td>
                        <td><?= e($im['arquivo']) ?></td>
                        <td><?= e($im['usuario_nome'] ?: '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Importar</button>
            </div>
        </form>
    </div>
</div>

<script>
// Ordenação por clique no título (mesmo comportamento do relatório de margem dos faturados):
// 1º clique = crescente, 2º = decrescente; usa os valores brutos de data-o-<coluna>.
Array.prototype.forEach.call(document.querySelectorAll('.tbl-fixa'), function (tbl) {
    if (!tbl.tBodies[0] || !tbl.tHead) return;
    var corpo = tbl.tBodies[0];
    var linhas = Array.prototype.filter.call(corpo.rows, function (tr) { return tr.classList.contains('lin-ord'); });
    if (linhas.length < 2) return;
    linhas.forEach(function (tr, i) { tr.dataset.pos = i; });
    var ths = tbl.tHead.querySelectorAll('th.col-ord');
    var colAtual = null, asc = true;
    function ordena(th) {
        var col = th.dataset.col, num = th.dataset.tipo === 'num';
        asc = (col === colAtual) ? !asc : true;
        colAtual = col;
        linhas.sort(function (a, b) {
            var va = a.getAttribute('data-o-' + col), vb = b.getAttribute('data-o-' + col), r;
            r = num ? (parseFloat(va) || 0) - (parseFloat(vb) || 0)
                    : String(va).localeCompare(String(vb), 'pt-BR', {sensitivity: 'base', numeric: true});
            if (!asc) r = -r;
            return r || (a.dataset.pos - b.dataset.pos);
        });
        linhas.forEach(function (tr) { corpo.appendChild(tr); });
        Array.prototype.forEach.call(ths, function (t) {
            var ico = t.querySelector('.ord-ico'), ativa = t === th;
            t.classList.toggle('ord-ativa', ativa);
            t.setAttribute('aria-sort', ativa ? (asc ? 'ascending' : 'descending') : 'none');
            ico.className = 'bi ord-ico ' + (ativa ? (asc ? 'bi-caret-up-fill' : 'bi-caret-down-fill') : 'bi-arrow-down-up');
        });
    }
    Array.prototype.forEach.call(ths, function (th) {
        th.addEventListener('click', function () { ordena(th); });
        th.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); ordena(th); } });
    });
});
// Aba escolhida vai junto na próxima busca e fica na URL.
Array.prototype.forEach.call(document.querySelectorAll('[data-visao]'), function (bt) {
    bt.addEventListener('shown.bs.tab', function () {
        document.getElementById('inpVisao').value = bt.dataset.visao;
        var u = new URL(location.href); u.searchParams.set('visao', bt.dataset.visao);
        history.replaceState(null, '', u);
    });
});
// Comparativo A × B: copia as datas para o formulário principal e busca de novo nesta aba.
function aplicarAB() {
    var f = document.getElementById('frmFiltro');
    Array.prototype.forEach.call(document.querySelectorAll('[data-ab]'), function (inp) { f.elements[inp.dataset.ab].value = inp.value; });
    f.elements.ambos.value = document.getElementById('chkAmbos').checked ? '1' : '0';
    f.elements.visao.value = 'periodo';
    f.submit();
}
function filtraMulti() {
    var so = document.getElementById('chkMulti').checked;
    Array.prototype.forEach.call(document.querySelectorAll('#tblMulti tr.unico'), function (tr) { tr.classList.toggle('d-none', so); });
    var tem = document.querySelector(so ? '#tblMulti tr.multi' : '#tblMulti tr.multi, #tblMulti tr.unico') !== null;
    document.getElementById('multiVazio').classList.toggle('d-none', tem);
}
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
