<?php
require_once __DIR__ . '/../../config.php';
requireComercial();

$self = BASE_URL . '/admin/relatorios/custos-insumos-am.php';
$tiposNome = ['mp' => 'Matéria-prima', 'emb' => 'Material de embalagem'];

// ── Importação: "Listagem Resumida" do A&M (.xlsx), um ou vários arquivos de uma vez ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_FILES['arquivos']) || isset($_FILES['cadastro']))) {
    $f = $_FILES['arquivos'] ?? ['name' => []];
    $ok = []; $erros = []; $ultTipo = null;
    // Cadastro de materiais de embalagem: a coluna P vira o filtro "classificação" do relatório.
    $fc = $_FILES['cadastro'] ?? null;
    if ($fc && $fc['name'] !== '') {
        if ($fc['error'] !== UPLOAD_ERR_OK) $erros[] = "{$fc['name']}: falha no envio (código {$fc['error']}).";
        else try {
            $d = custosInsumosLerCadastroXlsx($fc['tmp_name']);
            $r = custosInsumosImportarCadastro('emb', $d, $fc['name']);
            $ultTipo = 'emb';
            $ok[] = "{$fc['name']}: cadastro de embalagem com {$r['linhas']} materiais e {$r['grupos']} classificações"
                  . ($d['rotulo'] ? " em “{$d['rotulo']}”" : '') . " (coluna P); {$r['com_compra']} deles têm compras importadas.";
        } catch (Throwable $e) {
            $erros[] = "{$fc['name']}: " . $e->getMessage();
        }
    }
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
$visao = in_array($_GET['visao'] ?? '', ['resumo', 'grupo', 'variacao', 'periodo', 'mensal', 'fornecedor', 'compras'], true) ? $_GET['visao'] : 'resumo';
// Comparativo Período A × B (independe do período principal). Padrão: B = 3 últimos meses até o fim; A = 3 meses antes.
$iniB = $dataOk($_GET['ini_b'] ?? null) ?: date('Y-m-01', strtotime(date('Y-m-01', strtotime($fim)) . ' -2 months'));
$fimB = $dataOk($_GET['fim_b'] ?? null) ?: $fim;
$iniA = $dataOk($_GET['ini_a'] ?? null) ?: date('Y-m-d', strtotime($iniB . ' -3 months'));
$fimA = $dataOk($_GET['fim_a'] ?? null) ?: date('Y-m-d', strtotime($iniB . ' -1 day'));
$soAmbos = ($_GET['ambos'] ?? '1') === '1';
$modoPreco = ($_GET['preco'] ?? 'fixo') === 'medio' ? 'medio' : 'fixo';   // padrão = Fixo (pedido do usuário)
// Filtro da "Var. %" do Resumo: nenhum marcado = todos; marcado = só os insumos que subiram (Positivos) e/ou caíram (Negativos).
$varPos = ($_GET['vpos'] ?? '') === '1';
$varNeg = ($_GET['vneg'] ?? '') === '1';
// Classificação = coluna P do cadastro de materiais (só aparece quando há cadastro importado para o tipo).
// '__sem__' = insumos comprados que não estão no cadastro ou estão com a coluna P vazia.
$grupo = trim($_GET['grupo'] ?? '');
$st = db()->prepare("SELECT grupo, COUNT(*) AS n, MAX(rotulo) AS rotulo FROM custos_insumos_cadastro WHERE tipo = ? AND grupo <> '' GROUP BY grupo ORDER BY grupo");
$st->execute([$tipo]);
$grupos = $st->fetchAll();
$rotGrupo = ($grupos ? array_values(array_filter(array_column($grupos, 'rotulo')))[0] ?? null : null) ?: 'Classificação';
if (!$grupos) { $grupo = ''; if ($visao === 'grupo') $visao = 'resumo'; }
$grupoDe = [];
if ($grupos) {
    $st = db()->prepare("SELECT insumo_codigo, grupo FROM custos_insumos_cadastro WHERE tipo = ? AND grupo <> ''");
    $st->execute([$tipo]);
    $grupoDe = $st->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Opções dos filtros (do tipo escolhido, todas as datas)
$st = db()->prepare('SELECT fornecedor_codigo, MAX(fornecedor_nome) AS nome FROM custos_insumos_compras WHERE tipo = ?
                     GROUP BY fornecedor_codigo ORDER BY nome');
$st->execute([$tipo]);
$fornecedores = $st->fetchAll();
$st = db()->prepare('SELECT insumo_codigo, MAX(insumo_nome) AS nome FROM custos_insumos_compras WHERE tipo = ?
                     GROUP BY insumo_codigo ORDER BY nome');
$st->execute([$tipo]);
$insumosOpc = $st->fetchAll();

$buscar = function (string $de, string $ate) use ($tipo, $forn, $q, $grupo): array {
    $sql = 'SELECT * FROM custos_insumos_compras WHERE tipo = ? AND data BETWEEN ? AND ?';
    $par = [$tipo, $de, $ate];
    if ($forn !== '') { $sql .= ' AND fornecedor_codigo = ?'; $par[] = $forn; }
    if ($q !== '')    { $sql .= ' AND (insumo_codigo = ? OR insumo_nome LIKE ?)'; $par[] = $q; $par[] = '%' . $q . '%'; }
    if ($grupo === '__sem__') {
        $sql .= " AND insumo_codigo NOT IN (SELECT insumo_codigo FROM custos_insumos_cadastro WHERE tipo = ? AND grupo <> '')"; $par[] = $tipo;
    } elseif ($grupo !== '') {
        $sql .= ' AND insumo_codigo IN (SELECT insumo_codigo FROM custos_insumos_cadastro WHERE tipo = ? AND grupo = ?)'; $par[] = $tipo; $par[] = $grupo;
    }
    $st = db()->prepare($sql . ' ORDER BY data, id');
    $st->execute($par);
    return $st->fetchAll();
};
$compras = $buscar($ini, $fim);

// Agrupamento por insumo + unidade (o mesmo insumo pode vir em unidades diferentes — ex.: fita em M e PC —
// e preço por metro não se compara com preço por peça). Preço médio = ponderado pela quantidade.
$chave = fn($c) => $c['insumo_codigo'] . '|' . $c['unidade'];
$novoAcc = fn() => ['n' => 0, 'qtd' => 0.0, 'valor' => 0.0, 'min' => null, 'max' => null, 'ult' => null];
$acumula = function (array &$a, array $c) {
    $vu = (float)$c['valor_unitario'];
    $a['ult'] = $vu;                                       // compras em ordem de data, id → fica a última
    $a['n']++;
    $a['qtd']   += (float)$c['quantidade'];
    $a['valor'] += (float)$c['quantidade'] * $vu;
    // Data do menor/maior preço: compras vêm em ordem de data, então no empate fica a mais recente.
    if ($a['min'] === null || $vu <= $a['min']) { $a['min'] = $vu; $a['min_data'] = $c['data']; }
    if ($a['max'] === null || $vu >= $a['max']) { $a['max'] = $vu; $a['max_data'] = $c['data']; }
};
// Preço considerado em todas as abas: "medio" = médio ponderado; "fixo" = valor da ÚLTIMA compra do mês
// (na evolução mensal) ou do período/fornecedor (no resumo, no A × B e por fornecedor).
$pmMedio = fn(array $a) => $a['qtd'] > 0 ? $a['valor'] / $a['qtd'] : ($a['n'] ? $a['min'] : null);
$pm = fn(array $a) => $modoPreco === 'fixo' ? $a['ult'] : $pmMedio($a);
$rotPreco = $modoPreco === 'fixo' ? 'Preço fixo' : 'Preço médio';

$insumos = []; $porForn = []; $meses = []; $totForn = []; $totMes = []; $total = $novoAcc();
foreach ($compras as $c) {
    $k = $chave($c);
    if (!isset($insumos[$k])) {
        $insumos[$k] = ['codigo' => $c['insumo_codigo'], 'nome' => $c['insumo_nome'], 'un' => $c['unidade'], 'acc' => $novoAcc(),
                        'primeiro' => $c, 'penultimo' => null, 'ultimo' => null, 'forn' => [], 'mes' => []];
    }
    $I = &$insumos[$k];
    $acumula($I['acc'], $c);
    $I['hist'][] = [$c['data'], $c['nf'], $c['fornecedor_nome'], (float)$c['quantidade'], (float)$c['valor_unitario']];
    // Ordenado por data, id. Linhas da mesma NF na mesma data são UMA compra: não viram "penúltima".
    $novaCompra = !$I['ultimo'] || $I['ultimo']['nf'] !== $c['nf'] || $I['ultimo']['data'] !== $c['data'];
    if ($novaCompra && $I['ultimo']) $I['penultimo'] = $I['ultimo'];
    $I['ult_qtd'] = ($novaCompra ? 0 : $I['ult_qtd']) + (float)$c['quantidade'];   // qtd da última compra (todas as linhas da NF)
    $I['ultimo'] = $c;
    $fk = $c['fornecedor_codigo'];
    if (!isset($I['forn'][$fk])) $I['forn'][$fk] = ['nome' => $c['fornecedor_nome'], 'acc' => $novoAcc(), 'ultimo' => $c];
    $acumula($I['forn'][$fk]['acc'], $c);
    $I['forn'][$fk]['ultimo'] = $c;
    $m = substr($c['data'], 0, 7);
    if (!isset($I['forn'][$fk]['mes'][$m])) $I['forn'][$fk]['mes'][$m] = $novoAcc();
    $acumula($I['forn'][$fk]['mes'][$m], $c);
    $totMes[$m] = ($totMes[$m] ?? 0) + (float)$c['quantidade'] * (float)$c['valor_unitario'];
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
    // Sempre 2 casas, arredondamento comercial (meio para cima: 49,6584 → 49,66; 0,0975 → 0,10).
    return 'R$ ' . number_format(round((float)$v, 2, PHP_ROUND_HALF_UP), 2, ',', '.');
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
$linkInsumo = function (string $codigo, string $nome, string $visaoDest = 'compras') use ($self, $tipo, $ini, $fim, $forn, $grupo, $modoPreco): string {
    $u = $self . '?' . http_build_query(['tipo' => $tipo, 'preco' => $modoPreco, 'ini' => $ini, 'fim' => $fim, 'fornecedor' => $forn, 'grupo' => $grupo, 'q' => $codigo, 'visao' => $visaoDest]);
    return '<a href="' . e($u) . '" class="text-reset text-decoration-none" title="Ver as compras deste insumo">' . e($nome) . '</a>';
};
// Sem coluna "Un.": a unidade só aparece (selo ao lado do nome) quando o mesmo insumo tem compras em mais de uma unidade.
$unsPorInsumo = [];
foreach (array_merge($compras, $comprasAB) as $c) $unsPorInsumo[$c['insumo_codigo']][$c['unidade']] = 1;
$tagUn = fn(string $codigo, string $un): string => count($unsPorInsumo[$codigo] ?? []) > 1
    ? ' <span class="badge text-bg-light border fw-normal" title="Unidade (insumo comprado em mais de uma unidade)">' . e($un) . '</span>' : '';
// Classificação do cadastro (coluna P) embaixo do nome, quando não há filtro por uma classificação só.
$tagGrupo = fn(string $codigo): string => isset($grupoDe[$codigo]) && ($grupo === '' || $grupo === '__sem__')
    ? '<div class="small text-muted fw-normal" title="' . e($rotGrupo) . ' (coluna P do cadastro)">' . e($grupoDe[$codigo]) . '</div>' : '';
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
$sidebarOculta = true;   // abre com o menu da esquerda oculto (o botão da navbar mostra de novo)
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <a href="<?= BASE_URL ?>/admin/relatorios/am.php" class="small text-muted text-decoration-none no-print"><i class="bi bi-arrow-left me-1"></i>Relatórios A&amp;M</a>
        <h4 class="fw-bold mb-0"><i class="bi bi-box2 me-2"></i>Custos de Matéria-prima e Embalagem</h4>
        <p class="text-muted small mb-0">
            Preço pago em cada compra (Listagem Resumida do A&amp;M), com comparativos por período e por fornecedor.
            <?php if ($dadosQtd): ?>
            Base de <?= e(mb_strtolower($tiposNome[$tipo])) ?>: <?= number_format($dadosQtd, 0, ',', '.') ?> compras de <?= dataBR($dadosIni) ?> a <?= dataBR($dadosFim) ?>.
            <?php endif; ?>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2 no-print">
        <div class="btn-group">
            <button type="button" class="btn btn-outline-danger" onclick="gerarPdf(false)" title="Salvar a aba aberta em PDF">
                <i class="bi bi-file-earmark-pdf me-1"></i>Gerar PDF
            </button>
            <button type="button" class="btn btn-outline-danger dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="visually-hidden">Opções do PDF</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><button type="button" class="dropdown-item" onclick="gerarPdf(false)"><i class="bi bi-file-earmark me-2"></i>Só a aba aberta</button></li>
                <li><button type="button" class="dropdown-item" onclick="gerarPdf(true)"><i class="bi bi-files me-2"></i>Todas as abas</button></li>
            </ul>
        </div>
        <button type="button" class="btn btn-outline-success" onclick="gerarExcel()" title="Baixar todas as abas em Excel (uma planilha por aba)">
            <i class="bi bi-file-earmark-excel me-1"></i>Gerar Excel
        </button>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalImportar">
            <i class="bi bi-upload me-1"></i>Importar planilhas
        </button>
    </div>
</div>

<!-- Cabeçalho que só aparece no PDF: o formulário de filtros não é impresso. -->
<div class="print-cab">
    <div class="small">
        <b>Tipo:</b> <?= e($tiposNome[$tipo]) ?> · <b>Preço:</b> <?= $modoPreco === 'fixo' ? 'Fixo (última compra)' : 'Médio ponderado' ?>
        · <b>Período:</b> <?= dataBR($ini) ?> a <?= dataBR($fim) ?>
        <?php if ($forn !== ''): ?>· <b>Fornecedor:</b> <?= e($forn . ' - ' . ($totForn[$forn]['nome'] ?? array_column($fornecedores, 'nome', 'fornecedor_codigo')[$forn] ?? '')) ?><?php endif; ?>
        <?php if ($grupo !== ''): ?>· <b><?= e($rotGrupo) ?>:</b> <?= $grupo === '__sem__' ? 'Sem classificação' : e($grupo) ?><?php endif; ?>
        <?php if ($q !== ''): ?>· <b>Insumo:</b> <?= e($q) ?><?php endif; ?>
        <?php if ($varPos || $varNeg): ?>· <b>Var. %:</b> <?= implode(' e ', array_filter([$varPos ? 'Positivos' : '', $varNeg ? 'Negativos' : ''])) ?><?php endif; ?>
    </div>
    <div class="small text-muted">Gerado em <?= date('d/m/Y H:i') ?><?= !empty(usuario()['nome']) ? ' por ' . e(usuario()['nome']) : '' ?></div>
</div>

<form method="GET" class="card shadow-sm border-0 mb-3" id="frmFiltro">
    <input type="hidden" name="visao" id="inpVisao" value="<?= e($visao) ?>">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-auto">
                <label class="form-label small fw-semibold mb-1">Tipo</label>
                <div class="btn-group w-100" role="group">
                    <?php foreach ($tiposNome as $tk => $tn): ?>
                    <input type="radio" class="btn-check" name="tipo" id="tipo-<?= $tk ?>" value="<?= $tk ?>" <?= $tipo === $tk ? 'checked' : '' ?>
                           onchange="this.form.fornecedor.value='';this.form.q.value='';if(this.form.grupo)this.form.grupo.value='';this.form.submit()">
                    <label class="btn btn-outline-primary" for="tipo-<?= $tk ?>"><i class="bi <?= $tk === 'mp' ? 'bi-droplet' : 'bi-box-seam' ?> me-1"></i><?= $tn ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-12 col-md-auto">
                <label class="form-label small fw-semibold mb-1">Preço</label>
                <div class="btn-group w-100" role="group" title="Médio = ponderado pela quantidade; Fixo = valor da última compra do mês/período">
                    <?php foreach (['medio' => 'Médio', 'fixo' => 'Fixo'] as $pk => $pn): ?>
                    <input type="radio" class="btn-check" name="preco" id="preco-<?= $pk ?>" value="<?= $pk ?>" <?= $modoPreco === $pk ? 'checked' : '' ?> onchange="this.form.submit()">
                    <label class="btn btn-outline-primary" for="preco-<?= $pk ?>"><?= $pn ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-12 col-md-auto">
                <label class="form-label small fw-semibold mb-1">Var. %</label>
                <div class="btn-group w-100" role="group" title="Resumo por insumo: mostra só os insumos com a Var. % positiva (subiu) e/ou negativa (caiu). Nenhum marcado = todos.">
                    <input type="checkbox" class="btn-check" name="vpos" id="vpos" value="1" <?= $varPos ? 'checked' : '' ?> onchange="this.form.submit()">
                    <label class="btn btn-outline-danger" for="vpos"><i class="bi bi-arrow-up-short"></i>Positivos</label>
                    <input type="checkbox" class="btn-check" name="vneg" id="vneg" value="1" <?= $varNeg ? 'checked' : '' ?> onchange="this.form.submit()">
                    <label class="btn btn-outline-success" for="vneg"><i class="bi bi-arrow-down-short"></i>Negativos</label>
                </div>
            </div>
            <div class="col-6 col-md col-xxl-2">
                <label class="form-label small fw-semibold mb-1">De</label>
                <input type="date" name="ini" class="form-control" value="<?= e($ini) ?>">
            </div>
            <div class="col-6 col-md col-xxl-2">
                <label class="form-label small fw-semibold mb-1">Até</label>
                <input type="date" name="fim" class="form-control" value="<?= e($fim) ?>">
            </div>
            <?php if ($grupos): ?>
            <div class="col-12 col-md col-xxl-2">
                <label class="form-label small fw-semibold mb-1" title="Coluna P do cadastro de materiais importado"><?= e($rotGrupo) ?></label>
                <select name="grupo" class="form-select" onchange="this.form.submit()">
                    <option value="">Todas</option>
                    <?php foreach ($grupos as $g): ?>
                    <option value="<?= e($g['grupo']) ?>" <?= $grupo === $g['grupo'] ? 'selected' : '' ?>><?= e($g['grupo']) ?></option>
                    <?php endforeach; ?>
                    <option value="__sem__" <?= $grupo === '__sem__' ? 'selected' : '' ?>>(sem classificação)</option>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-12 col-md-6 col-xxl">
                <label class="form-label small fw-semibold mb-1">Insumo</label>
                <input type="search" name="q" class="form-control" list="lstInsumos" value="<?= e($q) ?>" placeholder="Todos (código ou parte do nome)">
                <datalist id="lstInsumos">
                    <?php foreach ($insumosOpc as $o): ?><option value="<?= e($o['insumo_codigo']) ?>"><?= e($o['nome']) ?></option><?php endforeach; ?>
                </datalist>
            </div>
            <div class="col-12 col-md col-xxl">
                <label class="form-label small fw-semibold mb-1">Fornecedor</label>
                <select name="fornecedor" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach ($fornecedores as $o): ?>
                    <option value="<?= e($o['fornecedor_codigo']) ?>" <?= $forn === $o['fornecedor_codigo'] ? 'selected' : '' ?>><?= e($o['fornecedor_codigo'] . ' - ' . $o['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-auto d-flex gap-2">
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
main{min-width:0}   /* item flex do layout: sem isso a tabela larga estica a página e corta filtros/cards no zoom */
.tbl-fixa-wrap{max-height:max(320px,70vh);overflow:auto}
.tbl-fixa{font-size:.85rem}
.tbl-meses{font-size:.82rem}
.tbl-fixa .col-fixa{min-width:11rem;max-width:22rem;white-space:normal}
.abas-rel{flex-wrap:nowrap;overflow-x:auto;overflow-y:hidden;scrollbar-width:thin}
.abas-rel .nav-link{white-space:nowrap}
.kpi-val{overflow-wrap:anywhere}
@media (max-width:575.98px){.tbl-fixa,.tbl-meses{font-size:.78rem}.kpi-val{font-size:1.15rem!important}}
.tbl-fixa thead th{position:sticky;top:0;z-index:3;background:var(--bs-tertiary-bg)!important;box-shadow:inset 0 -1px 0 var(--bs-border-color);white-space:nowrap}
.tbl-fixa tfoot td{position:sticky;bottom:0;z-index:3;background:var(--bs-tertiary-bg)!important;box-shadow:inset 0 1px 0 var(--bs-border-color)}
.tbl-fixa thead th.col-ord{cursor:pointer;user-select:none}
.tbl-fixa thead th.col-ord:hover{color:var(--bs-primary)}
.tbl-fixa thead th.col-ord .ord-ico{font-size:.7rem;opacity:.3;margin-left:.25rem}
.tbl-fixa thead th.col-ord.ord-ativa .ord-ico{opacity:1;color:var(--bs-primary)}
.tbl-fixa .col-fixa{position:sticky;left:0;z-index:2;background:var(--bs-body-bg)}
.tbl-fixa thead th.col-fixa{z-index:4}
.grp-ini td{border-top:2px solid var(--bs-border-color)}
tr[data-hist],tr[data-grp],tr[data-ghist]{cursor:pointer}
/* ── PDF (impressão do navegador): some menu, filtros e abas; tabelas sem rolagem; cabeçalho repete a cada página ── */
.print-cab,.print-titulo{display:none}
@media print{
    @page{size:A4 landscape;margin:10mm}
    body{background:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    body>.navbar,.sidebar,.no-print,#frmFiltro,.nav-tabs,.modal,.alert-dismissible,#vis-periodo .card:has([data-ab]),
    .tbl-fixa .ord-ico,.bi-clock-history{display:none!important}
    main{padding:0!important}
    .print-cab{display:block;border-bottom:1px solid #ccc;padding-bottom:4px;margin-bottom:8px}
    .print-titulo{display:block;font-weight:700;margin:6px 0 6px}
    .card{box-shadow:none!important}
    .tbl-fixa-wrap{max-height:none!important;overflow:visible!important}
    .table-responsive{overflow:visible!important}
    .tbl-fixa{font-size:.68rem!important}
    .tbl-fixa thead th,.tbl-fixa tfoot td,.tbl-fixa .col-fixa{position:static!important}
    .tbl-fixa thead{display:table-header-group}
    .tbl-fixa tr{break-inside:avoid}
    .tab-pane:not(.active){display:none!important}
    body.pdf-todas .tab-pane{display:block!important;opacity:1!important}
    body.pdf-todas .tab-pane+.tab-pane{break-before:page}
}
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
                <div class="fs-4 fw-bold kpi-val"><?= $val ?></div>
                <div class="small text-muted"><?= e($sub) ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php
// Evolução mensal (usada nas abas "Evolução mensal" e "Por fornecedor"): preço de cada mês com a variação
// vs mês anterior com compra embaixo; variação geral = 1º × último mês com compra no período.
$celulasMeses = function (array $mes, string $un) use ($meses, $pm): array {
    $ant = null; $pPri = null; $pUlt = null; $nMes = 0; $cel = '';
    foreach ($meses as $m) {
        if (!isset($mes[$m])) { $cel .= '<td class="text-end text-muted">·</td>'; continue; }
        $p = $pm($mes[$m]);
        if ($pPri === null) $pPri = $p;
        $pUlt = $p; $nMes++;
        $cls = $ant === null || abs($p - $ant) < 0.00005 ? '' : ($p > $ant ? 'text-danger' : 'text-success');
        $tit = $mes[$m]['n'] . ' compra(s), ' . qtdFmt($mes[$m]['qtd']) . ' ' . $un;
        $vMes = '';
        if ($ant !== null && $ant > 0) {
            $tit .= ', ' . ($p >= $ant ? '+' : '') . number_format(($p / $ant - 1) * 100, 2, ',', '.') . '% vs mês anterior com compra';
            $vMes = '<div class="small">' . varPct(($p / $ant - 1) * 100) . '</div>';
        }
        $cel .= '<td class="text-end text-nowrap ' . $cls . '" title="' . e($tit) . '">' . precoUn($p) . $vMes . '</td>';
        $ant = $p;
    }
    return [$cel, $nMes > 1 && $pPri > 0 ? ($pUlt / $pPri - 1) * 100 : null];
};
// Rodapé: total comprado (R$) de cada mês, com a variação vs mês anterior com compra; na coluna Var. %, 1º × último mês.
$rodapeMeses = function () use ($meses, $totMes): string {
    $h = ''; $ant = null; $pri = null; $ult = null; $n = 0;
    foreach ($meses as $m) {
        if (!isset($totMes[$m])) { $h .= '<td class="text-end text-muted">·</td>'; continue; }
        $v = $totMes[$m];
        $h .= '<td class="text-end text-nowrap">' . moedaBR($v) . ($ant > 0 ? '<div class="small">' . varPct(($v / $ant - 1) * 100) . '</div>' : '') . '</td>';
        $pri ??= $v; $ult = $v; $ant = $v; $n++;
    }
    return $h . '<td class="text-end">' . varPct($n > 1 && $pri > 0 ? ($ult / $pri - 1) * 100 : null) . '</td>';
};
// Por fornecedor: uma linha por fornecedor × insumo (+ unidade), em ordem de fornecedor e insumo.
$linhasForn = [];
foreach ($insumos as $I) foreach ($I['forn'] as $fk => $F) $linhasForn[] = ['fk' => $fk, 'forn' => $F['nome'], 'I' => $I, 'mes' => $F['mes']];
usort($linhasForn, fn($a, $b) => strcmp($a['forn'], $b['forn']) ?: strcmp($a['I']['nome'], $b['I']['nome']));
// Linhas do Resumo: Var. % do período inteiro (1ª → última compra; só com 2+ compras), filtro Positivos/Negativos e ordem por valor (curva ABC).
// Os % de representatividade e o acumulado são sempre sobre o total do período (com ou sem o filtro da Var. %).
$linhasRes = [];
foreach ($insumos as $I) {
    $pu = (float)$I['ultimo']['valor_unitario'];
    $pp = $I['penultimo'] ? (float)$I['primeiro']['valor_unitario'] : null;   // 1ª compra do período
    $var = $pp > 0 ? ($pu / $pp - 1) * 100 : null;
    if (($varPos || $varNeg) && !($varPos && $var !== null && $var >= 0.005) && !($varNeg && $var !== null && $var <= -0.005)) continue;
    $linhasRes[] = ['I' => $I, 'pu' => $pu, 'pp' => $pp, 'var' => $var];
}
usort($linhasRes, fn($a, $b) => $b['I']['acc']['valor'] <=> $a['I']['acc']['valor'] ?: strcmp($a['I']['nome'], $b['I']['nome']));
// Por sub grupo (coluna P do cadastro): totais do período e Var. % ponderada como no Total do Resumo
// (Σ qtd da última compra × preço atual ÷ Σ qtd da última compra × preço da 1ª compra − 1).
$linhasGrupo = [];
if ($grupos) {
    foreach ($insumos as $I) {
        $gk = $grupoDe[$I['codigo']] ?? '';
        if (!isset($linhasGrupo[$gk])) $linhasGrupo[$gk] = ['nome' => $gk, 'ins' => [], 'lista' => [], 'n' => 0, 'valor' => 0.0, 'forn' => [], 'pen' => 0.0, 'ult' => 0.0, 'nvar' => 0, 'sobe' => 0, 'desce' => 0];
        $G = &$linhasGrupo[$gk];
        $G['ins'][$I['codigo']] = 1;
        $G['lista'][] = $I;
        $G['n'] += $I['acc']['n'];
        $G['valor'] += $I['acc']['valor'];
        $G['forn'] += $I['forn'];
        if ($I['penultimo'] && ($pp = (float)$I['primeiro']['valor_unitario']) > 0) {
            $pu = (float)$I['ultimo']['valor_unitario'];
            $G['pen'] += $I['ult_qtd'] * $pp; $G['ult'] += $I['ult_qtd'] * $pu; $G['nvar']++;
            if ($pu / $pp - 1 >= 0.00005) $G['sobe']++; elseif ($pu / $pp - 1 <= -0.00005) $G['desce']++;
        }
        unset($G);
    }
    usort($linhasGrupo, fn($a, $b) => $b['valor'] <=> $a['valor'] ?: strcmp($a['nome'], $b['nome']));
}
// Variação total: quanto subiu ou caiu no final, em R$. Para cada insumo, a quantidade total do período ao preço da
// 1ª compra × ao preço atual (última compra); "Pago − 1ª compra" = valor realmente pago × a mesma quantidade ao preço da 1ª.
// Compra única (sem 1ª compra para comparar) entra só no valor pago, para o total fechar com o comprado no período.
$varSit = ['sobe' => ['Subiram', 'text-danger', 'bi-arrow-up-circle'], 'desce' => ['Caíram', 'text-success', 'bi-arrow-down-circle'],
           'igual' => ['Sem variação', 'text-muted', 'bi-dash-circle'], 'unica' => ['Compra única', 'text-muted', 'bi-1-circle']];
$varTot = []; $linhasVar = [];
foreach (array_keys($varSit) + ['total' => 'total'] as $sk) $varTot[$sk] = ['ins' => 0, 'pago' => 0.0, 'v1' => 0.0, 'va' => 0.0];
foreach ($insumos as $I) {
    $pago = $I['acc']['valor'];
    if ($I['penultimo']) {
        $pp = (float)$I['primeiro']['valor_unitario']; $pu = (float)$I['ultimo']['valor_unitario'];
        $v1 = $I['acc']['qtd'] * $pp; $va = $I['acc']['qtd'] * $pu;
        $var = $pp > 0 ? ($pu / $pp - 1) * 100 : null;
        $sk = $var === null || abs($var) < 0.005 ? 'igual' : ($var > 0 ? 'sobe' : 'desce');
        $linhasVar[] = ['I' => $I, 'pp' => $pp, 'pu' => $pu, 'var' => $var, 'v1' => $v1, 'va' => $va, 'pago' => $pago];
    } else {
        $sk = 'unica'; $v1 = $va = $pago;
    }
    foreach ([$sk, 'total'] as $t) { $varTot[$t]['ins']++; $varTot[$t]['pago'] += $pago; $varTot[$t]['v1'] += $v1; $varTot[$t]['va'] += $va; }
}
usort($linhasVar, fn($a, $b) => ($b['va'] - $b['v1']) <=> ($a['va'] - $a['v1']) ?: strcmp($a['I']['nome'], $b['I']['nome']));
// R$ com sinal (subir = custo maior = vermelho; cair = verde).
$difR = function (float $v, bool $cor = true): string {
    if (abs($v) < 0.005) return '<span class="text-muted">R$ 0,00</span>';
    return '<span class="text-nowrap' . ($cor ? ' fw-semibold ' . ($v > 0 ? 'text-danger' : 'text-success') : '') . '">' . ($v > 0 ? '+' : '−') . moedaBR(abs($v)) . '</span>';
};
$colPm = $modoPreco !== 'fixo';   // no preço fixo a coluna repetiria o "Atual" (última compra)
$nColRes = $colPm ? 13 : 12;
$abas = [
    'resumo'     => ['bi-list-ul', 'Resumo por insumo', count($linhasRes)],
] + ($grupos ? ['grupo' => ['bi-tags', 'Por ' . mb_strtolower($rotGrupo), count($linhasGrupo)]] : []) + [
    'variacao'   => ['bi-graph-up-arrow', 'Variação total', count($linhasVar)],
    'periodo'    => ['bi-arrow-left-right', 'Período A × B', count($ab)],
    'mensal'     => ['bi-calendar3', 'Evolução mensal', count($insumos)],
    'fornecedor' => ['bi-truck', 'Por fornecedor', count($totForn)],
    'compras'    => ['bi-receipt', 'Compras', count($compras)],
];
?>
<ul class="nav nav-tabs mb-0 abas-rel" role="tablist">
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
<h5 class="print-titulo"><?= e($abas['resumo'][1]) ?></h5>
<div class="card shadow-sm border-0"><div class="card-body p-0"><div class="table-responsive tbl-fixa-wrap">
<table class="table table-hover table-sm align-middle mb-0 tbl-fixa" data-acum-total="<?= round($total['valor'], 2) ?>">
    <thead class="table-light"><tr><?= $thOrd([
        'codigo' => ['Código', '', 'num'], 'nome' => ['Insumo', '', 'txt'], 'n' => ['CP', 'text-end', 'num'], 'nforn' => ['Forn.', 'text-end', 'num'],
        'qtd' => ['Qtd. total', 'text-end', 'num'], 'valor' => ['Valor total', 'text-end', 'num'],
    ]) ?><th class="text-end" title="Soma da representatividade no total do período, na ordem em que as linhas estão">% acum.</th><?= $thOrd(($colPm ? ['pm' => [$rotPreco, 'text-end', 'num']] : []) + [
        'min' => ['Menor', 'text-end', 'num'], 'max' => ['Maior', 'text-end', 'num'], 'pen' => ['1ª compra', 'text-end', 'num'],
        'ult' => ['Atual', 'text-end', 'num'], 'var' => ['Var. %', 'text-end', 'num'],
    ]) ?></tr></thead>
    <tbody>
    <?php $histJs = []; $histIdx = []; $resN = 0; $resValor = 0.0; $acum = 0.0;
    // Total da "Var. %" (último → atual): ponderada pela quantidade da última compra de cada insumo
    // (Σ qtd × preço última ÷ Σ qtd × preço da 1ª − 1), só insumos com 2+ compras no período.
    $vtPen = 0.0; $vtUlt = 0.0; $vtN = 0; $vtSobe = 0; $vtDesce = 0;
    // Desconto pelo valor total (insumos com 2+ compras): quantidade total do período × preço da 1ª compra e × preço atual.
    $vlPri = 0.0; $vlUlt = 0.0;
    // Representatividade da quantidade: só faz sentido entre insumos da mesma unidade (KG com KG, PC com PC...).
    $qtdUn = [];
    foreach ($insumos as $I) $qtdUn[$I['un']] = ($qtdUn[$I['un']] ?? 0) + $I['acc']['qtd'];
    foreach ($linhasRes as ['I' => $I, 'pu' => $pu, 'pp' => $pp, 'var' => $var]):
        // Histórico de compras do insumo para o modal (abre ao clicar na linha).
        $hIdx = count($histJs);
        $histIdx[$I['codigo'] . '|' . $I['un']] = $hIdx;
        $histJs[] =['c' => $I['codigo'], 'n' => $I['nome'], 'u' => $I['un'], 'l' => $I['hist'],
                     'url' => $self . '?' . http_build_query(['tipo' => $tipo, 'preco' => $modoPreco, 'ini' => $ini, 'fim' => $fim,
                                                              'fornecedor' => $forn, 'grupo' => $grupo, 'q' => $I['codigo'], 'visao' => 'compras'])];
        $a = $I['acc'];
        $resN += $a['n']; $resValor += $a['valor']; $acum += $a['valor'];
        if ($var !== null) {
            $vtPen += $I['ult_qtd'] * $pp; $vtUlt += $I['ult_qtd'] * $pu; $vtN++;
            $vlPri += $a['qtd'] * $pp; $vlUlt += $a['qtd'] * $pu;
            if ($var >= 0.005) $vtSobe++; elseif ($var <= -0.005) $vtDesce++;
        } ?>
        <tr class="lin-ord"<?= $ordAttrs(['codigo' => $I['codigo'], 'nome' => $I['nome'], 'n' => $a['n'], 'qtd' => $a['qtd'],
            'valor' => round($a['valor'], 2), 'pm' => $pm($a), 'min' => $a['min'], 'max' => $a['max'], 'pen' => $pp ?? -1, 'ult' => $pu,
            'var' => $var ?? -99999, 'nforn' => count($I['forn'])]) ?> data-hist="<?= $hIdx ?>" tabindex="0" title="Clique para ver o histórico de compras">
            <td class="text-muted"><?= e($I['codigo']) ?></td>
            <td class="fw-semibold"><i class="bi bi-clock-history text-primary me-1 small"></i><?= e($I['nome']) ?><?= $tagUn($I['codigo'], $I['un']) ?><?= $tagGrupo($I['codigo']) ?></td>
            <td class="text-end"><?= $a['n'] ?></td>
            <td class="text-end"><?= count($I['forn']) ?></td>
            <td class="text-end"><?= qtdFmt($a['qtd']) ?><div class="small text-muted" data-xls="(% do período)" title="<?= e('Representatividade na quantidade total comprada em ' . $I['un'] . ' no período') ?>"><?= $qtdUn[$I['un']] > 0 ? number_format($a['qtd'] / $qtdUn[$I['un']] * 100, 2, ',', '.') . '%' : '—' ?></div></td>
            <td class="text-end"><?= moedaBR($a['valor']) ?><div class="small text-muted" data-xls="(% do período)" title="Representatividade no total comprado do período"><?= $total['valor'] > 0 ? number_format($a['valor'] / $total['valor'] * 100, 2, ',', '.') . '%' : '—' ?></div></td>
            <td class="text-end text-muted col-acum"><?= $total['valor'] > 0 ? number_format($acum / $total['valor'] * 100, 2, ',', '.') . '%' : '—' ?></td>
            <?php if ($colPm): ?><td class="text-end fw-semibold"><?= precoUn($pm($a)) ?></td><?php endif; ?>
            <td class="text-end"><?= precoUn($a['min']) ?><div class="small text-muted"><?= dataBR($a['min_data']) ?></div></td>
            <td class="text-end"><?= precoUn($a['max']) ?><div class="small text-muted"><?= dataBR($a['max_data']) ?></div></td>
            <td class="text-end"<?= $I['penultimo'] ? ' title="' . e($I['primeiro']['fornecedor_nome']) . '"' : '' ?>><?= precoUn($pp) ?><?php if ($I['penultimo']): ?><div class="small text-muted"><?= dataBR($I['primeiro']['data']) ?></div><?php endif; ?></td>
            <td class="text-end" title="<?= e($I['ultimo']['fornecedor_nome']) ?>"><?= precoUn($pu) ?><div class="small text-muted"><?= dataBR($I['ultimo']['data']) ?></div></td>
            <td class="text-end"><?= varPct($var) ?></td>
        </tr>
    <?php endforeach; if (!$linhasRes): ?>
        <tr><td colspan="<?= $nColRes ?>" class="text-center text-muted py-4">Nenhuma compra com estes filtros.</td></tr>
    <?php endif; ?>
    </tbody>
    <?php if ($linhasRes): ?>
    <tfoot><tr class="fw-bold"><td colspan="2">Total<?= count($linhasRes) < count($insumos) ? ' (' . count($linhasRes) . ' de ' . count($insumos) . ' insumos)' : '' ?></td><td class="text-end"><?= $resN ?></td><td></td><td></td>
        <td class="text-end"><?= moedaBR($resValor) ?><div class="small text-muted" data-xls="(% do período)"><?= $total['valor'] > 0 ? number_format($resValor / $total['valor'] * 100, 2, ',', '.') . '%' : '—' ?></div>
            <?php if ($vtN && $vlPri > 0): $vlDesc = $vlPri - $vlUlt; ?>
            <div class="small text-muted text-nowrap" title="<?= e("Quantidade total do período × preço da 1ª compra, somando os $vtN insumos com 2+ compras") ?>">1ª compra: <?= moedaBR($vlPri) ?></div>
            <div class="small text-muted text-nowrap" title="Mesma quantidade × preço atual (última compra)">Atual: <?= moedaBR($vlUlt) ?></div>
            <div class="small <?= $vlDesc >= 0.005 ? 'text-success' : ($vlDesc <= -0.005 ? 'text-danger' : 'text-muted') ?> text-nowrap" title="1ª compra − atual, em % da 1ª compra">
                <?= $vlDesc <= -0.005 ? 'Aumento' : 'Desconto' ?>: <?= moedaBR(abs($vlDesc)) ?> (<?= number_format(abs($vlDesc) / $vlPri * 100, 2, ',', '.') ?>%)</div>
            <?php endif; ?></td><td colspan="<?= $colPm ? 6 : 5 ?>"></td>
        <td class="text-end" title="<?= e("Ponderada pela quantidade da última compra; $vtN insumos com 2+ compras no período — $vtSobe subiram, $vtDesce caíram. "
            . 'Última compra: ' . moedaBR($vtUlt) . ' | mesma quantidade ao preço da 1ª compra: ' . moedaBR($vtPen)) ?>">
            <?= varPct($vtPen > 0 ? ($vtUlt / $vtPen - 1) * 100 : null) ?></td></tr></tfoot>
    <?php endif; ?>
</table>
</div></div></div>
<p class="small text-muted mt-2 mb-0"><?= $modoPreco === 'fixo'
        ? 'Preço fixo = valor unitário da última compra do período.'
        : 'Preço médio = ponderado pela quantidade (Σ qtd × valor unitário ÷ Σ qtd).' ?>
    CP = número de compras. Abaixo da quantidade e do valor total, a representatividade do insumo no período (a da quantidade é calculada entre os insumos da mesma unidade);
    “% acum.” soma essa representatividade na ordem das linhas (abre do maior para o menor valor — curva ABC — e recalcula ao reordenar). “1ª compra” e “Atual” = preços da primeira e da última compra do período; “Var. %” compara os dois, ou seja, a variação no período inteiro (só insumos com mais de uma compra; linhas da mesma NF contam como uma compra); no Total, abaixo do Valor total, a quantidade total do período ao preço da 1ª compra e ao preço atual (insumos com mais de uma compra) e o desconto (1ª compra − atual) em R$ e em % da 1ª compra; a “Var. %” do Total é ponderada pela quantidade da última compra de cada insumo
    (passe o mouse para ver os valores). O mesmo insumo em unidades diferentes (ex.: M e PC) aparece em linhas separadas, com a unidade ao lado do nome. Clique na linha para ver o histórico de compras do insumo.</p>
</div>

<?php if ($grupos): ?>
<!-- ══ Por sub grupo (coluna P do cadastro de materiais) ══ -->
<div class="tab-pane fade <?= $visao === 'grupo' ? 'show active' : '' ?>" id="vis-grupo" role="tabpanel">
<h5 class="print-titulo"><?= e($abas['grupo'][1]) ?></h5>
<div class="card shadow-sm border-0"><div class="card-body p-0"><div class="table-responsive tbl-fixa-wrap">
<table class="table table-hover table-sm align-middle mb-0 tbl-fixa" data-acum-total="<?= round($total['valor'], 2) ?>">
    <thead class="table-light"><tr><?= $thOrd([
        'nome' => [e($rotGrupo), '', 'txt'], 'ins' => ['Insumos', 'text-end', 'num'], 'n' => ['CP', 'text-end', 'num'],
        'nforn' => ['Forn.', 'text-end', 'num'], 'valor' => ['Valor total', 'text-end', 'num'],
    ]) ?><th class="text-end" title="Soma da representatividade no total do período, na ordem em que as linhas estão">% acum.</th><?= $thOrd([
        'var' => ['Var. %', 'text-end', 'num'],
    ]) ?></tr></thead>
    <tbody>
    <?php $acum = 0.0; $gPen = 0.0; $gUlt = 0.0; $grupoJs = [];
    foreach ($linhasGrupo as $G):
        $acum += $G['valor']; $gPen += $G['pen']; $gUlt += $G['ult'];
        $varG = $G['pen'] > 0 ? ($G['ult'] / $G['pen'] - 1) * 100 : null;
        $gVal = $G['nome'] !== '' ? $G['nome'] : '__sem__';
        $uG = $self . '?' . http_build_query(['tipo' => $tipo, 'preco' => $modoPreco, 'ini' => $ini, 'fim' => $fim, 'fornecedor' => $forn, 'q' => $q,
                                              'grupo' => $gVal, 'visao' => 'resumo', 'ini_a' => $iniA, 'fim_a' => $fimA, 'ini_b' => $iniB, 'fim_b' => $fimB, 'ambos' => $soAmbos ? '1' : '0']);
        // Insumos do sub grupo para o modal: [código, nome, un., CP, qtd, valor, 1ª compra, atual, data atual, índice no HIST]
        $itens = [];
        foreach ($G['lista'] as $I) {
            $hk = $I['codigo'] . '|' . $I['un'];
            if (!isset($histIdx[$hk])) {   // fora do Resumo pelo filtro da Var. %: entra no HIST só para o modal
                $histIdx[$hk] = count($histJs);
                $histJs[] = ['c' => $I['codigo'], 'n' => $I['nome'], 'u' => $I['un'], 'l' => $I['hist'],
                             'url' => $self . '?' . http_build_query(['tipo' => $tipo, 'preco' => $modoPreco, 'ini' => $ini, 'fim' => $fim,
                                                                      'fornecedor' => $forn, 'grupo' => $grupo, 'q' => $I['codigo'], 'visao' => 'compras'])];
            }
            $itens[] = [$I['codigo'], $I['nome'], count($unsPorInsumo[$I['codigo']] ?? []) > 1 ? $I['un'] : '', $I['acc']['n'], $I['acc']['qtd'], round($I['acc']['valor'], 2),
                        $I['penultimo'] ? (float)$I['primeiro']['valor_unitario'] : null, (float)$I['ultimo']['valor_unitario'], $I['ultimo']['data'], $histIdx[$hk]];
        }
        usort($itens, fn($a, $b) => $b[5] <=> $a[5]);
        $gIdx = count($grupoJs);
        $grupoJs[] = ['n' => $G['nome'] !== '' ? $G['nome'] : '(sem classificação)', 'url' => $uG, 'var' => $varG, 'i' => $itens]; ?>
        <tr class="lin-ord"<?= $ordAttrs(['nome' => $G['nome'] !== '' ? $G['nome'] : '~', 'ins' => count($G['ins']), 'n' => $G['n'], 'nforn' => count($G['forn']),
            'valor' => round($G['valor'], 2), 'var' => $varG ?? -99999]) ?> data-grp="<?= $gIdx ?>" tabindex="0" title="Clique para ver os insumos deste <?= e(mb_strtolower($rotGrupo)) ?>">
            <td class="fw-semibold"><i class="bi bi-list-ul text-primary me-1 small"></i><?= $G['nome'] !== '' ? e($G['nome']) : '<span class="text-muted fst-italic">(sem classificação)</span>' ?></td>
            <td class="text-end"><?= count($G['ins']) ?></td>
            <td class="text-end"><?= $G['n'] ?></td>
            <td class="text-end"><?= count($G['forn']) ?></td>
            <td class="text-end"><?= moedaBR($G['valor']) ?><div class="small text-muted" data-xls="(% do período)" title="Representatividade no total comprado do período"><?= $total['valor'] > 0 ? number_format($G['valor'] / $total['valor'] * 100, 2, ',', '.') . '%' : '—' ?></div></td>
            <td class="text-end text-muted col-acum"><?= $total['valor'] > 0 ? number_format($acum / $total['valor'] * 100, 2, ',', '.') . '%' : '—' ?></td>
            <td class="text-end" title="<?= e("{$G['nvar']} insumos com 2+ compras no período — {$G['sobe']} subiram, {$G['desce']} caíram") ?>"><?= varPct($varG) ?></td>
        </tr>
    <?php endforeach; if (!$linhasGrupo): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">Nenhuma compra com estes filtros.</td></tr>
    <?php endif; ?>
    </tbody>
    <?php if ($linhasGrupo): ?>
    <tfoot><tr class="fw-bold"><td>Total</td><td class="text-end"><?= count($insumos) ?></td><td class="text-end"><?= $total['n'] ?></td><td class="text-end"><?= count($totForn) ?></td>
        <td class="text-end"><?= moedaBR($total['valor']) ?></td><td></td><td class="text-end"><?= varPct($gPen > 0 ? ($gUlt / $gPen - 1) * 100 : null) ?></td></tr></tfoot>
    <?php endif; ?>
</table>
</div></div></div>
<p class="small text-muted mt-2 mb-0"><?= e($rotGrupo) ?> = coluna P do cadastro de materiais importado. Insumos = materiais com compra no período; CP = número de compras.
    Abaixo do valor, a representatividade no total do período; “% acum.” soma essa representatividade na ordem das linhas (curva ABC).
    “Var. %” = variação da 1ª para a última compra do período, ponderada pela quantidade da última compra de cada insumo (só insumos com mais de uma compra), igual ao Total do Resumo.
    Clique no <?= e(mb_strtolower($rotGrupo)) ?> para ver os insumos que o compõem.</p>
</div>
<?php endif; ?>

<!-- ══ Variação total: quanto subiu / caiu no final ══ -->
<div class="tab-pane fade <?= $visao === 'variacao' ? 'show active' : '' ?>" id="vis-variacao" role="tabpanel">
<h5 class="print-titulo"><?= e($abas['variacao'][1]) ?></h5>
<?php $vS = $varTot['sobe']; $vD = $varTot['desce']; $vT = $varTot['total']; $saldo = $vT['va'] - $vT['v1']; ?>
<div class="row g-3 mb-3">
    <?php foreach ([
        ['Subiu no final', $difR($vS['va'] - $vS['v1']), 'danger', $vS['ins'] . ' insumos · ' . ($vS['v1'] > 0 ? '+' . number_format(($vS['va'] / $vS['v1'] - 1) * 100, 2, ',', '.') . '%' : '—') . ' sobre a 1ª compra'],
        ['Diminuiu no final', $difR($vD['va'] - $vD['v1']), 'success', $vD['ins'] . ' insumos · ' . ($vD['v1'] > 0 ? number_format(($vD['va'] / $vD['v1'] - 1) * 100, 2, ',', '.') . '%' : '—') . ' sobre a 1ª compra'],
        ['Saldo no final', $difR($saldo), $saldo > 0.005 ? 'danger' : 'success', ($vT['v1'] > 0 ? ($saldo > 0 ? '+' : '') . number_format(($vT['va'] / $vT['v1'] - 1) * 100, 2, ',', '.') . '%' : '—') . ' · ' . ($saldo > 0.005 ? 'custo subiu' : 'custo caiu') . ' no período'],
        ['Pago − preço da 1ª compra', $difR($vT['pago'] - $vT['v1']), 'secondary', 'realizado: pago ' . moedaBR($vT['pago']) . ' × ' . moedaBR($vT['v1'])],
    ] as [$rot, $val, $cor, $sub]): ?>
    <div class="col-6 col-xl-3">
        <div class="card shadow-sm border-0 border-start border-4 border-<?= $cor ?> h-100">
            <div class="card-body py-3">
                <div class="text-muted small fw-semibold text-uppercase"><?= e($rot) ?></div>
                <div class="fs-4 fw-bold kpi-val"><?= $val ?></div>
                <div class="small text-muted"><?= e($sub) ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<div class="card shadow-sm border-0 mb-3"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm align-middle mb-0" id="tblVarResumo">
    <thead class="table-light"><tr><th>Situação</th><th class="text-end">Insumos</th><th class="text-end">Valor pago</th>
        <th class="text-end" title="Quantidade total do período × preço da 1ª compra">Qtd. × 1ª compra</th><th class="text-end" title="Quantidade total do período × preço atual (última compra)">Qtd. × atual</th>
        <th class="text-end" title="Qtd. × atual − Qtd. × 1ª compra">Variação final</th><th class="text-end">Var. %</th>
        <th class="text-end" title="Valor pago − Qtd. × 1ª compra">Pago − 1ª compra</th><th class="text-end">%</th></tr></thead>
    <tbody>
    <?php foreach ($varSit + ['total' => ['Total', '', 'bi-sigma']] as $sk => [$rot, $cls, $ico]): $T = $varTot[$sk]; ?>
        <tr class="<?= $sk === 'total' ? 'fw-bold table-light' : '' ?>">
            <td class="fw-semibold"><i class="bi <?= $ico ?> <?= $cls ?> me-1"></i><?= e($rot) ?></td>
            <td class="text-end"><?= $T['ins'] ?></td>
            <td class="text-end"><?= moedaBR($T['pago']) ?></td>
            <td class="text-end"><?= moedaBR($T['v1']) ?></td>
            <td class="text-end"><?= moedaBR($T['va']) ?></td>
            <td class="text-end"><?= $difR($T['va'] - $T['v1']) ?></td>
            <td class="text-end"><?= varPct($T['v1'] > 0 && $sk !== 'unica' ? ($T['va'] / $T['v1'] - 1) * 100 : null) ?></td>
            <td class="text-end"><?= $difR($T['pago'] - $T['v1']) ?></td>
            <td class="text-end"><?= varPct($T['v1'] > 0 && $sk !== 'unica' ? ($T['pago'] / $T['v1'] - 1) * 100 : null) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div></div></div>
<div class="card shadow-sm border-0"><div class="card-body p-0"><div class="table-responsive tbl-fixa-wrap">
<table class="table table-hover table-sm align-middle mb-0 tbl-fixa" id="tblVarInsumos">
    <thead class="table-light"><tr><?= $thOrd([
        'codigo' => ['Código', '', 'num'], 'nome' => ['Insumo', '', 'txt'], 'qtd' => ['Qtd. total', 'text-end', 'num'],
        'pen' => ['1ª compra', 'text-end', 'num'], 'ult' => ['Atual', 'text-end', 'num'], 'var' => ['Var. %', 'text-end', 'num'],
        'v1' => ['Qtd. × 1ª compra', 'text-end', 'num'], 'va' => ['Qtd. × atual', 'text-end', 'num'], 'dif' => ['Variação final', 'text-end', 'num'],
        'pago' => ['Valor pago', 'text-end', 'num'], 'real' => ['Pago − 1ª compra', 'text-end', 'num'],
    ]) ?></tr></thead>
    <tbody>
    <?php foreach ($linhasVar as $L): $I = $L['I'];
        $hk = $I['codigo'] . '|' . $I['un'];
        if (!isset($histIdx[$hk])) {   // fora do Resumo pelo filtro da Var. %: entra no HIST só para o modal
            $histIdx[$hk] = count($histJs);
            $histJs[] = ['c' => $I['codigo'], 'n' => $I['nome'], 'u' => $I['un'], 'l' => $I['hist'],
                         'url' => $self . '?' . http_build_query(['tipo' => $tipo, 'preco' => $modoPreco, 'ini' => $ini, 'fim' => $fim,
                                                                  'fornecedor' => $forn, 'grupo' => $grupo, 'q' => $I['codigo'], 'visao' => 'compras'])];
        }
        $dif = $L['va'] - $L['v1']; $real = $L['pago'] - $L['v1']; ?>
        <tr class="lin-ord"<?= $ordAttrs(['codigo' => $I['codigo'], 'nome' => $I['nome'], 'qtd' => $I['acc']['qtd'], 'pen' => $L['pp'], 'ult' => $L['pu'],
            'var' => $L['var'] ?? -99999, 'v1' => round($L['v1'], 2), 'va' => round($L['va'], 2), 'dif' => round($dif, 2), 'pago' => round($L['pago'], 2), 'real' => round($real, 2)]) ?>
            data-hist="<?= $histIdx[$hk] ?>" tabindex="0" title="Clique para ver o histórico de compras">
            <td class="text-muted"><?= e($I['codigo']) ?></td>
            <td class="fw-semibold"><i class="bi bi-clock-history text-primary me-1 small"></i><?= e($I['nome']) ?><?= $tagUn($I['codigo'], $I['un']) ?><?= $tagGrupo($I['codigo']) ?></td>
            <td class="text-end"><?= qtdFmt($I['acc']['qtd']) ?></td>
            <td class="text-end"><?= precoUn($L['pp']) ?><div class="small text-muted"><?= dataBR($I['primeiro']['data']) ?></div></td>
            <td class="text-end"><?= precoUn($L['pu']) ?><div class="small text-muted"><?= dataBR($I['ultimo']['data']) ?></div></td>
            <td class="text-end"><?= varPct($L['var']) ?></td>
            <td class="text-end"><?= moedaBR($L['v1']) ?></td>
            <td class="text-end"><?= moedaBR($L['va']) ?></td>
            <td class="text-end"><?= $difR($dif) ?></td>
            <td class="text-end"><?= moedaBR($L['pago']) ?></td>
            <td class="text-end"><?= $difR($real) ?></td>
        </tr>
    <?php endforeach; if (!$linhasVar): ?>
        <tr><td colspan="11" class="text-center text-muted py-4">Nenhum insumo com mais de uma compra com estes filtros.</td></tr>
    <?php endif; ?>
    </tbody>
    <?php if ($linhasVar): $t1 = $vS['v1'] + $vD['v1'] + $varTot['igual']['v1']; $tA = $vS['va'] + $vD['va'] + $varTot['igual']['va']; $tP = $vS['pago'] + $vD['pago'] + $varTot['igual']['pago']; ?>
    <tfoot><tr class="fw-bold"><td colspan="5">Total (<?= count($linhasVar) ?> insumos com 2+ compras)</td>
        <td class="text-end"><?= varPct($t1 > 0 ? ($tA / $t1 - 1) * 100 : null) ?></td>
        <td class="text-end"><?= moedaBR($t1) ?></td><td class="text-end"><?= moedaBR($tA) ?></td><td class="text-end"><?= $difR($tA - $t1) ?></td>
        <td class="text-end"><?= moedaBR($tP) ?></td><td class="text-end"><?= $difR($tP - $t1) ?></td></tr></tfoot>
    <?php endif; ?>
</table>
</div></div></div>
<p class="small text-muted mt-2 mb-0">Para cada insumo, a quantidade total comprada no período é valorizada ao preço da 1ª compra e ao preço atual (última compra do período).
    “Variação final” = Qtd. × atual − Qtd. × 1ª compra: quanto a mesma compra custaria a mais (vermelho) ou a menos (verde) hoje. “Pago − 1ª compra” = valor realmente pago
    menos a mesma quantidade ao preço da 1ª compra: quanto se pagou a mais ou a menos de fato ao longo do período.
    Insumos com uma única compra não têm com o que comparar: entram só no valor pago (linha “Compra única”), para o Total fechar com o total comprado.
    A lista abre da maior alta para a maior queda em R$; clique na linha para ver o histórico de compras.</p>
</div>

<!-- ══ Período A × B ══ -->
<div class="tab-pane fade <?= $visao === 'periodo' ? 'show active' : '' ?>" id="vis-periodo" role="tabpanel">
<h5 class="print-titulo"><?= e($abas['periodo'][1]) ?></h5>
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
        <div class="fs-4 fw-bold kpi-val"><?= varPct($abIndice) ?></div>
        <div class="small text-muted">cesta = quantidades compradas em B</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card shadow-sm border-0 border-start border-4 border-<?= $abTot['impacto'] > 0 ? 'danger' : 'success' ?> h-100"><div class="card-body py-3">
        <div class="text-muted small fw-semibold text-uppercase">Impacto em B</div>
        <div class="fs-4 fw-bold kpi-val <?= $abTot['impacto'] > 0 ? 'text-danger' : 'text-success' ?>"><?= ($abTot['impacto'] > 0 ? '+' : '') . moedaBR($abTot['impacto']) ?></div>
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
<table class="table table-hover table-sm align-middle mb-0 tbl-fixa">
    <thead class="table-light"><tr><?= $thOrd([
        'codigo' => ['Código', '', 'num'], 'nome' => ['Insumo', '', 'txt'],
        'na' => ['Compras A', 'text-end', 'num'], 'qa' => ['Qtd. A', 'text-end', 'num'], 'pa' => [$rotPreco . ' A', 'text-end', 'num'],
        'nb' => ['Compras B', 'text-end', 'num'], 'qb' => ['Qtd. B', 'text-end', 'num'], 'pb' => [$rotPreco . ' B', 'text-end', 'num'],
        'dif' => ['Dif. R$/un.', 'text-end', 'num'], 'var' => ['Var. %', 'text-end', 'num'], 'imp' => ['Impacto em B', 'text-end', 'num'],
    ]) ?></tr></thead>
    <tbody>
    <?php foreach ($ab as $r):
        $pa = $r['A']['n'] ? $pm($r['A']) : null; $pb = $r['B']['n'] ? $pm($r['B']) : null;
        $dif = $pa !== null && $pb !== null ? $pb - $pa : null; ?>
        <tr class="lin-ord"<?= $ordAttrs(['codigo' => $r['codigo'], 'nome' => $r['nome'], 'na' => $r['A']['n'], 'qa' => $r['A']['qtd'],
            'pa' => $pa ?? -1, 'nb' => $r['B']['n'], 'qb' => $r['B']['qtd'], 'pb' => $pb ?? -1, 'dif' => $dif ?? -99999,
            'var' => $r['var'] ?? -99999, 'imp' => $r['impacto'] ?? -9999999]) ?>>
            <td class="text-muted"><?= e($r['codigo']) ?></td>
            <td class="fw-semibold"><?= $linkInsumo($r['codigo'], $r['nome']) ?><?= $tagUn($r['codigo'], $r['un']) ?></td>
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
        <tr><td colspan="11" class="text-center text-muted py-4">Nenhum insumo para comparar nesses períodos.</td></tr>
    <?php endif; ?>
    </tbody>
    <?php if ($ab): ?>
    <tfoot><tr class="fw-bold"><td colspan="9">Total</td><td class="text-end"><?= varPct($abIndice) ?></td>
        <td class="text-end <?= $abTot['impacto'] > 0 ? 'text-danger' : 'text-success' ?>"><?= moedaBR($abTot['impacto']) ?></td></tr></tfoot>
    <?php endif; ?>
</table>
</div></div></div>
<p class="small text-muted mt-2 mb-0"><?= $modoPreco === 'fixo' ? 'Preço fixo = valor da última compra de cada período.' : 'Preço médio ponderado pela quantidade em cada período.' ?> Impacto em B = Qtd. B × (Preço B − Preço A): quanto se pagou a mais (+, vermelho)
    ou a menos (−, verde) em B em relação aos preços de A. Variação ponderada = Σ Qtd. B × Preço B ÷ Σ Qtd. B × Preço A − 1, só com insumos dos dois períodos.</p>
</div>

<!-- ══ Evolução mensal ══ -->
<div class="tab-pane fade <?= $visao === 'mensal' ? 'show active' : '' ?>" id="vis-mensal" role="tabpanel">
<h5 class="print-titulo"><?= e($abas['mensal'][1]) ?></h5>
<div class="card shadow-sm border-0"><div class="card-body p-0"><div class="table-responsive tbl-fixa-wrap">
<table class="table table-hover table-sm align-middle mb-0 tbl-fixa tbl-meses">
    <thead class="table-light"><tr>
        <th class="col-ord col-fixa" data-col="nome" data-tipo="txt" tabindex="0" title="Clique para ordenar">Insumo<i class="bi bi-arrow-down-up ord-ico"></i></th>
        <?php foreach ($meses as $m): ?><th class="text-end"><?= $mesNome($m) ?></th><?php endforeach; ?>
        <th class="col-ord text-end" data-col="var" data-tipo="num" tabindex="0" title="Primeiro × último mês com compra no período">Var. %<i class="bi bi-arrow-down-up ord-ico"></i></th>
    </tr></thead>
    <tbody>
    <?php foreach ($insumos as $I): [$cel, $varM] = $celulasMeses($I['mes'], $I['un']); ?>
        <tr class="lin-ord"<?= $ordAttrs(['nome' => $I['nome'], 'var' => $varM ?? -99999]) ?>>
            <td class="fw-semibold col-fixa"><?= $linkInsumo($I['codigo'], $I['nome']) ?><?= $tagUn($I['codigo'], $I['un']) ?> <span class="text-muted fw-normal small"><?= e($I['codigo']) ?></span></td>
            <?= $cel ?>
            <td class="text-end"><?= varPct($varM) ?></td>
        </tr>
    <?php endforeach; if (!$insumos): ?>
        <tr><td colspan="<?= count($meses) + 2 ?>" class="text-center text-muted py-4">Nenhuma compra com estes filtros.</td></tr>
    <?php endif; ?>
    </tbody>
    <?php if ($insumos): ?>
    <tfoot><tr class="fw-bold"><td class="col-fixa">Total comprado</td><?= $rodapeMeses() ?></tr></tfoot>
    <?php endif; ?>
</table>
</div></div></div>
<p class="small text-muted mt-2 mb-0"><?= $modoPreco === 'fixo' ? 'Preço fixo = valor da última compra de cada mês.' : 'Preço médio ponderado de cada mês.' ?>
    “Var. %” é a variação geral: primeiro × último mês com compra dentro do De/Até. Embaixo de cada preço, a variação em relação ao mês anterior com compra.
    Vermelho = mais caro que o mês anterior com compra; verde = mais barato; “·” = sem compra no mês.
    Total comprado = soma do valor (qtd × valor unitário) das compras do mês; embaixo, a variação vs o mês anterior com compra.</p>
</div>

<!-- ══ Por fornecedor: evolução mensal com o fornecedor na 1ª coluna ══ -->
<div class="tab-pane fade <?= $visao === 'fornecedor' ? 'show active' : '' ?>" id="vis-fornecedor" role="tabpanel">
<h5 class="print-titulo"><?= e($abas['fornecedor'][1]) ?></h5>
<div class="card shadow-sm border-0"><div class="card-body p-0"><div class="table-responsive tbl-fixa-wrap">
<table class="table table-hover table-sm align-middle mb-0 tbl-fixa tbl-meses">
    <thead class="table-light"><tr>
        <th class="col-ord col-fixa" data-col="forn" data-tipo="txt" tabindex="0" title="Clique para ordenar">Fornecedor<i class="bi bi-arrow-down-up ord-ico"></i></th>
        <th class="col-ord" data-col="nome" data-tipo="txt" tabindex="0" title="Clique para ordenar">Insumo<i class="bi bi-arrow-down-up ord-ico"></i></th>
        <?php foreach ($meses as $m): ?><th class="text-end"><?= $mesNome($m) ?></th><?php endforeach; ?>
        <th class="col-ord text-end" data-col="var" data-tipo="num" tabindex="0" title="Primeiro × último mês com compra no período">Var. %<i class="bi bi-arrow-down-up ord-ico"></i></th>
    </tr></thead>
    <tbody>
    <?php foreach ($linhasForn as $L): $I = $L['I']; [$cel, $varM] = $celulasMeses($L['mes'], $I['un']); ?>
        <tr class="lin-ord"<?= $ordAttrs(['forn' => $L['forn'] . ' ' . $I['nome'], 'nome' => $I['nome'] . ' ' . $L['forn'], 'var' => $varM ?? -99999]) ?>>
            <td class="fw-semibold col-fixa"><?= e($L['forn']) ?> <span class="text-muted fw-normal small"><?= e($L['fk']) ?></span></td>
            <td><?= $linkInsumo($I['codigo'], $I['nome']) ?><?= $tagUn($I['codigo'], $I['un']) ?> <span class="text-muted small"><?= e($I['codigo']) ?></span></td>
            <?= $cel ?>
            <td class="text-end"><?= varPct($varM) ?></td>
        </tr>
    <?php endforeach; if (!$linhasForn): ?>
        <tr><td colspan="<?= count($meses) + 3 ?>" class="text-center text-muted py-4">Nenhuma compra com estes filtros.</td></tr>
    <?php endif; ?>
    </tbody>
    <?php if ($linhasForn): ?>
    <tfoot><tr class="fw-bold"><td class="col-fixa">Total comprado</td><td></td><?= $rodapeMeses() ?></tr></tfoot>
    <?php endif; ?>
</table>
</div></div></div>
<p class="small text-muted mt-2 mb-0">Uma linha por fornecedor e insumo: o preço de cada mês considera só as compras daquele fornecedor
    (<?= $modoPreco === 'fixo' ? 'última compra do mês' : 'preço médio ponderado do mês' ?>). “Var. %” = primeiro × último mês com compra do fornecedor no período;
    embaixo de cada preço, a variação vs o mês anterior com compra. Total comprado = soma do valor das compras do mês.</p>
</div>

<!-- ══ Compras ══ -->
<div class="tab-pane fade <?= $visao === 'compras' ? 'show active' : '' ?>" id="vis-compras" role="tabpanel">
<h5 class="print-titulo"><?= e($abas['compras'][1]) ?></h5>
<div class="card shadow-sm border-0"><div class="card-body p-0"><div class="table-responsive tbl-fixa-wrap">
<table class="table table-hover table-sm align-middle mb-0 tbl-fixa">
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

<!-- ══ Modal: histórico de compras do insumo (aba Resumo) ══ -->
<div class="modal fade" id="modalHist" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0"><i class="bi bi-clock-history me-2"></i><span id="histTitulo"></span></h5>
                    <div class="small text-muted" id="histSub"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3" id="histCards"></div>
                <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0" style="font-size:.85rem">
                    <thead class="table-light"><tr>
                        <th>Data</th><th>NF</th><th>Fornecedor</th><th class="text-end">Qtd.</th>
                        <th class="text-end">Valor unitário</th><th class="text-end">Var. vs compra anterior</th><th class="text-end">Total</th>
                    </tr></thead>
                    <tbody id="histCorpo"></tbody>
                </table>
                </div>
            </div>
            <div class="modal-footer">
                <a href="#" id="histLink" class="btn btn-outline-secondary btn-sm"><i class="bi bi-receipt me-1"></i>Abrir na aba Compras</a>
                <button type="button" class="btn btn-primary btn-sm" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- ══ Modal: insumos de um sub grupo (aba Por sub grupo) ══ -->
<div class="modal fade" id="modalGrupo" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0"><i class="bi bi-tags me-2"></i><span id="grpTitulo"></span></h5>
                    <div class="small text-muted" id="grpSub"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0" style="font-size:.85rem">
                    <thead class="table-light"><tr>
                        <th>Código</th><th>Insumo</th><th class="text-end">CP</th><th class="text-end">Qtd. total</th><th class="text-end">Valor total</th>
                        <th class="text-end">1ª compra</th><th class="text-end">Atual</th><th class="text-end">Var. %</th>
                    </tr></thead>
                    <tbody id="grpCorpo"></tbody>
                    <tfoot id="grpRodape" class="fw-bold"></tfoot>
                </table>
                </div>
                <p class="small text-muted mt-2 mb-0">Abaixo do valor, a participação do insumo no <?= e(mb_strtolower($rotGrupo)) ?>. Clique no insumo para ver o histórico de compras.</p>
            </div>
            <div class="modal-footer">
                <a href="#" id="grpLink" class="btn btn-outline-secondary btn-sm"><i class="bi bi-funnel me-1"></i>Filtrar o relatório por este <?= e(mb_strtolower($rotGrupo)) ?></a>
                <button type="button" class="btn btn-primary btn-sm" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

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
                <input type="file" name="arquivos[]" class="form-control" accept=".xlsx" multiple>
                <div class="alert alert-light border small mt-3 mb-0">
                    <i class="bi bi-arrow-repeat me-1"></i>Reimportar não duplica: as compras já gravadas do mesmo tipo e empresa
                    <b>dentro do período do arquivo</b> (“De … Até …”) são substituídas pelas do arquivo novo. Compras de fora desse período ficam como estão.
                </div>
                <h6 class="fw-bold mt-4 mb-2"><i class="bi bi-tags me-1"></i>Cadastro de materiais de embalagem</h6>
                <p class="small mb-2">Planilha de cadastro dos materiais de embalagem (.xlsx). A <b>coluna P</b> vira o filtro
                    <b><?= e($tipo === 'emb' ? $rotGrupo : 'Classificação') ?></b> do relatório (tipo Material de embalagem); o código do material é localizado automaticamente.
                    Reimportar substitui o cadastro inteiro.</p>
                <input type="file" name="cadastro" class="form-control" accept=".xlsx">
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
                        <td class="text-nowrap"><?= $im['empresa'] === 'CADASTRO' ? 'Cadastro (coluna P)' : dataBR($im['periodo_ini']) . ' a ' . dataBR($im['periodo_fim']) ?></td>
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
    var acumTotal = parseFloat(tbl.dataset.acumTotal) || 0;
    // "% acum." (Resumo): soma o valor na ordem atual das linhas sobre o total do período.
    function recalcAcum() {
        var s = 0;
        linhas.forEach(function (tr) {
            var td = tr.querySelector('.col-acum');
            s += parseFloat(tr.getAttribute('data-o-valor')) || 0;
            if (td) td.textContent = (s / acumTotal * 100).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '%';
        });
    }
    function ordena(th, dir) {
        var col = th.dataset.col, num = th.dataset.tipo === 'num';
        asc = dir ? dir === 'asc' : (col === colAtual) ? !asc : true;
        colAtual = col;
        linhas.sort(function (a, b) {
            var va = a.getAttribute('data-o-' + col), vb = b.getAttribute('data-o-' + col), r;
            r = num ? (parseFloat(va) || 0) - (parseFloat(vb) || 0)
                    : String(va).localeCompare(String(vb), 'pt-BR', {sensitivity: 'base', numeric: true});
            if (!asc) r = -r;
            return r || (a.dataset.pos - b.dataset.pos);
        });
        linhas.forEach(function (tr) { corpo.appendChild(tr); });
        if (acumTotal > 0) recalcAcum();
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
    // O Resumo já vem do servidor por valor decrescente: marca o título para o próximo clique inverter.
    var thValor = acumTotal > 0 && tbl.tHead.querySelector('th.col-ord[data-col="valor"]');
    if (thValor) ordena(thValor, 'desc');
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
// Histórico de compras do insumo: [data, nf, fornecedor, qtd, valor unitário], em ordem de data.
var HIST = <?= json_encode($histJs ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var PERIODO = <?= json_encode(dataBR($ini) . ' a ' . dataBR($fim)) ?>;
function hEsc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
function hPreco(v) { return hMoeda(Math.round((v + Number.EPSILON) * 100) / 100); }   // 2 casas, meio para cima (igual ao PHP)
function hMoeda(v) { return 'R$ ' + v.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
function hQtd(v) { return v.toLocaleString('pt-BR', {maximumFractionDigits: 3}); }
function hData(d) { return d.split('-').reverse().join('/'); }
function hVar(v) {
    if (v === null) return '<span class="text-muted">—</span>';
    if (Math.abs(v) < 0.005) return '<span class="text-muted">0,00%</span>';
    return '<span class="fw-semibold text-nowrap ' + (v > 0 ? 'text-danger' : 'text-success') + '"><i class="bi ' + (v > 0 ? 'bi-arrow-up-short' : 'bi-arrow-down-short') + '"></i>'
         + (v > 0 ? '+' : '') + v.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '%</span>';
}
function abrirHist(i) {
    var h = HIST[i]; if (!h) return;
    var qtd = 0, valor = 0, min = null, max = null, forn = {}, nfs = {}, linhas = [], ant = null;
    h.l.forEach(function (c, j) {
        var tot = c[3] * c[4];
        qtd += c[3]; valor += tot; forn[c[2]] = 1; nfs[c[0] + '|' + c[1]] = 1;
        min = min === null ? c[4] : Math.min(min, c[4]); max = max === null ? c[4] : Math.max(max, c[4]);
        // compara com a compra anterior (outra NF/data); linhas da mesma NF são a mesma compra
        if (j > 0 && (h.l[j - 1][1] !== c[1] || h.l[j - 1][0] !== c[0])) ant = h.l[j - 1][4];
        var v = ant ? (c[4] / ant - 1) * 100 : null;
        linhas.push('<tr><td class="text-nowrap">' + hData(c[0]) + '</td><td>' + hEsc(c[1]) + '</td><td>' + hEsc(c[2]) + '</td>'
            + '<td class="text-end">' + hQtd(c[3]) + ' ' + hEsc(h.u) + '</td><td class="text-end fw-semibold' + (c[4] === min ? ' text-success' : (c[4] === max ? ' text-danger' : '')) + '">' + hPreco(c[4]) + '</td>'
            + '<td class="text-end">' + hVar(v) + '</td><td class="text-end">' + hMoeda(tot) + '</td></tr>');
    });
    document.getElementById('histTitulo').textContent = h.n;
    document.getElementById('histSub').textContent = 'Código ' + h.c + ' · unidade ' + h.u + ' · ' + PERIODO;
    var cards = [['Compras', Object.keys(nfs).length], ['Qtd. total', hQtd(qtd) + ' ' + h.u], ['Valor total', hMoeda(valor)],
                 ['Preço médio', qtd > 0 ? hPreco(valor / qtd) : '—'], ['Menor / maior', hPreco(min) + ' / ' + hPreco(max)],
                 ['Fornecedores', Object.keys(forn).length]];
    document.getElementById('histCards').innerHTML = cards.map(function (c) {
        return '<div class="col-6 col-md-4 col-xl-2"><div class="border rounded p-2 h-100"><div class="small text-muted text-uppercase fw-semibold">' + c[0]
             + '</div><div class="fw-bold">' + c[1] + '</div></div></div>';
    }).join('');
    document.getElementById('histCorpo').innerHTML = linhas.reverse().join('');   // mais recente primeiro
    document.getElementById('histLink').href = h.url;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalHist')).show();
}
Array.prototype.forEach.call(document.querySelectorAll('tr[data-hist]'), function (tr) {
    tr.addEventListener('click', function () { abrirHist(+tr.dataset.hist); });
    tr.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') abrirHist(+tr.dataset.hist); });
});
// Insumos do sub grupo: [código, nome, un., CP, qtd, valor, 1ª compra, atual, data atual, índice no HIST].
// Clicar num insumo troca para o histórico; ao fechar o histórico, o modal do sub grupo volta.
var GRUPOS = <?= json_encode($grupoJs ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var grpVoltar = null;
function abrirGrupo(i) {
    var g = GRUPOS[i]; if (!g) return;
    var tot = 0, cp = 0;
    g.i.forEach(function (r) { tot += r[5]; cp += r[3]; });
    document.getElementById('grpTitulo').textContent = g.n;
    document.getElementById('grpSub').textContent = g.i.length + ' insumo(s) com compra · ' + PERIODO;
    document.getElementById('grpCorpo').innerHTML = g.i.map(function (r) {
        var v = r[6] > 0 ? (r[7] / r[6] - 1) * 100 : null;
        return '<tr data-ghist="' + r[9] + '" tabindex="0" title="Clique para ver o histórico de compras">'
             + '<td class="text-muted">' + hEsc(r[0]) + '</td>'
             + '<td class="fw-semibold"><i class="bi bi-clock-history text-primary me-1 small"></i>' + hEsc(r[1])
             + (r[2] ? ' <span class="badge text-bg-light border fw-normal">' + hEsc(r[2]) + '</span>' : '') + '</td>'
             + '<td class="text-end">' + r[3] + '</td><td class="text-end">' + hQtd(r[4]) + '</td>'
             + '<td class="text-end">' + hMoeda(r[5]) + '<div class="small text-muted">'
             + (tot > 0 ? (r[5] / tot * 100).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '%' : '—') + '</div></td>'
             + '<td class="text-end">' + (r[6] === null ? '<span class="text-muted">—</span>' : hPreco(r[6])) + '</td>'
             + '<td class="text-end">' + hPreco(r[7]) + '<div class="small text-muted">' + hData(r[8]) + '</div></td>'
             + '<td class="text-end">' + hVar(v) + '</td></tr>';
    }).join('');
    document.getElementById('grpRodape').innerHTML = '<tr><td colspan="2">Total</td><td class="text-end">' + cp + '</td><td></td><td class="text-end">'
        + hMoeda(tot) + '</td><td colspan="2"></td><td class="text-end">' + hVar(g['var']) + '</td></tr>';
    document.getElementById('grpLink').href = g.url;
    Array.prototype.forEach.call(document.querySelectorAll('#grpCorpo tr[data-ghist]'), function (tr) {
        function vai() {
            grpVoltar = i;
            var mg = document.getElementById('modalGrupo');
            mg.addEventListener('hidden.bs.modal', function () { abrirHist(+tr.dataset.ghist); }, {once: true});
            bootstrap.Modal.getInstance(mg).hide();
        }
        tr.addEventListener('click', vai);
        tr.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') vai(); });
    });
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalGrupo')).show();
}
document.getElementById('modalHist').addEventListener('hidden.bs.modal', function () {
    if (grpVoltar !== null) { var i = grpVoltar; grpVoltar = null; abrirGrupo(i); }
});
Array.prototype.forEach.call(document.querySelectorAll('tr[data-grp]'), function (tr) {
    tr.addEventListener('click', function () { abrirGrupo(+tr.dataset.grp); });
    tr.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') abrirGrupo(+tr.dataset.grp); });
});
// PDF pela impressão do navegador ("Salvar como PDF"). Imprime sempre em tema claro; "todas" mostra as 5 abas, uma por página.
function gerarPdf(todas) {
    var html = document.documentElement, tema = html.getAttribute('data-bs-theme');
    var tituloOrig = document.title;
    document.body.classList.toggle('pdf-todas', !!todas);
    html.setAttribute('data-bs-theme', 'light');
    document.title = 'Custos ' + <?= json_encode($tiposNome[$tipo] . ' ' . dataBR($ini) . ' a ' . dataBR($fim)) ?>.replace(/\//g, '-');
    window.addEventListener('afterprint', function volta() {
        window.removeEventListener('afterprint', volta);
        document.body.classList.remove('pdf-todas');
        if (tema === null) html.removeAttribute('data-bs-theme'); else html.setAttribute('data-bs-theme', tema);
        document.title = tituloOrig;
    });
    window.print();
}
// Excel: uma planilha por tabela, na ordem em que estão na tela (respeita a ordenação clicada).
// Moeda, %, quantidade e data viram valores numéricos/data; o texto miúdo da célula (data da compra, código, selo) vai para uma coluna ao lado.
var XLS_FILTROS = <?= json_encode([
    ['Relatório', 'Custos de Matéria-prima e Embalagem'],
    ['Tipo', $tiposNome[$tipo]],
    ['Preço', $modoPreco === 'fixo' ? 'Fixo (última compra)' : 'Médio ponderado'],
    ['Período', dataBR($ini) . ' a ' . dataBR($fim)],
    ['Período A × B', 'A: ' . dataBR($iniA) . ' a ' . dataBR($fimA) . ' | B: ' . dataBR($iniB) . ' a ' . dataBR($fimB) . ($soAmbos ? ' (só insumos dos dois períodos)' : '')],
    ['Fornecedor', $forn !== '' ? $forn . ' - ' . (array_column($fornecedores, 'nome', 'fornecedor_codigo')[$forn] ?? '') : 'Todos'],
    ['Insumo', $q !== '' ? $q : 'Todos'],
    [$rotGrupo, $grupo === '' ? 'Todos' : ($grupo === '__sem__' ? 'Sem classificação' : $grupo)],
    ['Var. % (Resumo)', $varPos || $varNeg ? implode(' e ', array_filter([$varPos ? 'Positivos' : '', $varNeg ? 'Negativos' : ''])) : 'Todos'],
    ['Gerado em', date('d/m/Y H:i') . (!empty(usuario()['nome']) ? ' por ' . usuario()['nome'] : '')],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var XLS_NOME = <?= json_encode('Custos ' . $tiposNome[$tipo] . ' ' . str_replace('/', '-', dataBR($ini) . ' a ' . dataBR($fim))) ?>;
function xlsValor(txt, soTexto) {
    txt = txt.replace(/\s+/g, ' ').trim();
    if (txt === '' || txt === '—' || txt === '·') return [''];
    var d = txt.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
    if (d) return [(Date.UTC(+d[3], d[2] - 1, +d[1]) - Date.UTC(1899, 11, 30)) / 86400000, 'dd/mm/yyyy'];   // serial do Excel (sem fuso)
    if (soTexto) return [txt];
    var m = txt.replace(/−/g, '-').match(/^([+-]?)\s*(R\$)?\s*([+-]?)\s*(\d[\d.]*(?:,\d+)?)\s*(%?)$/);
    if (!m) return [txt];
    var v = parseFloat(m[4].replace(/\./g, '').replace(',', '.')) * ((m[1] === '-' || m[3] === '-') ? -1 : 1);
    if (m[5]) return [v / 100, '0.00%'];
    if (m[2]) return [v, '"R$" #,##0.00;[Red]-"R$" #,##0.00'];
    return [v, v % 1 ? '#,##0.000' : '#,##0'];
}
function xlsTabela(tbl) {
    var heads = Array.prototype.map.call(tbl.tHead.rows[0].cells, function (th) { return th.textContent.trim(); });
    var detalhe = heads.map(function () { return null; });   // rótulo da coluna extra de cada coluna (se houver texto miúdo)
    var linhas = [], pend = [];                              // pend[col] = {v, resta} para rowspan
    var trs = Array.prototype.slice.call(tbl.tBodies[0].rows).concat(tbl.tFoot ? Array.prototype.slice.call(tbl.tFoot.rows) : []);
    trs.forEach(function (tr) {
        if (tr.classList.contains('d-none') || tr.querySelector('td[colspan]') && tr.cells.length === 1) return;
        var lin = [], ci = 0;
        Array.prototype.forEach.call(tr.cells, function (td) {
            while (pend[ci] && pend[ci].resta > 0) { pend[ci].resta--; lin[ci] = pend[ci].v; ci++; }
            var cl = td.cloneNode(true), extra = [];
            Array.prototype.forEach.call(cl.querySelectorAll('.small, .badge'), function (el) {
                if (el.textContent.trim()) extra.push(el.textContent.trim());
                el.remove();
            });
            var h = heads[ci] || '', cel = {v: xlsValor(cl.textContent, /^(Código|NF)$/.test(h)), x: extra.join(' · ')};
            if (cel.x && !detalhe[ci]) {
                var rx = td.querySelector('[data-xls]');
                detalhe[ci] = rx ? h + ' ' + rx.dataset.xls : /^\d{2}\/\d{2}\/\d{4}$/.test(cel.x) ? h + ' (data)' : /%$/.test(cel.x) ? h + ' (var.)' : td.querySelector('.badge') ? h + ' (obs.)' : h + ' (código)';
            }
            var n = td.colSpan || 1;
            lin[ci] = cel;
            for (var k = 1; k < n; k++) lin[ci + k] = {v: ['']};
            if (td.rowSpan > 1) pend[ci] = {v: cel, resta: td.rowSpan - 1};
            ci += n;
        });
        while (pend[ci] && pend[ci].resta > 0) { pend[ci].resta--; lin[ci] = pend[ci].v; ci++; }
        linhas.push(lin);
    });
    // Monta a planilha: cada coluna com texto miúdo ganha uma coluna "(data)/(código)/(obs.)" logo depois.
    var aoa = [[]], fmt = [[]];
    heads.forEach(function (h, i) { aoa[0].push(h); if (detalhe[i]) aoa[0].push(detalhe[i]); });
    linhas.forEach(function (lin) {
        var r = [], f = [];
        heads.forEach(function (h, i) {
            var c = lin[i] || {v: ['']};
            r.push(c.v[0]); f.push(c.v[1]);
            if (detalhe[i]) { var x = xlsValor(c.x || '', !/%$/.test(c.x || '')); r.push(x[0]); f.push(x[1]); }
        });
        aoa.push(r); fmt.push(f);
    });
    var ws = XLSX.utils.aoa_to_sheet(aoa);
    fmt.forEach(function (f, ri) { f.forEach(function (z, ci) {
        var a = XLSX.utils.encode_cell({r: ri, c: ci});
        if (z && ws[a]) ws[a].z = z;
    }); });
    ws['!cols'] = aoa[0].map(function (_, ci) {
        var w = 8;
        aoa.forEach(function (r) { var v = r[ci]; w = Math.max(w, String(v == null ? '' : v).length + 2); });
        return {wch: Math.min(w, 50)};
    });
    ws['!autofilter'] = {ref: XLSX.utils.encode_range({s: {r: 0, c: 0}, e: {r: aoa.length - 1, c: aoa[0].length - 1}})};
    return ws;
}
function gerarExcel() {
    if (typeof XLSX === 'undefined') { alert('Não foi possível carregar o gerador de Excel. Verifique a conexão e tente de novo.'); return; }
    var wb = XLSX.utils.book_new();
    var fil = XLSX.utils.aoa_to_sheet(XLS_FILTROS);
    fil['!cols'] = [{wch: 16}, {wch: 80}];
    XLSX.utils.book_append_sheet(wb, fil, 'Filtros');
    [['#vis-resumo', 'Resumo por insumo'], ['#vis-grupo', <?= json_encode(mb_substr('Por ' . mb_strtolower($rotGrupo), 0, 31)) ?>], ['#tblVarResumo', 'Variação total'], ['#tblVarInsumos', 'Variação por insumo'], ['#vis-periodo', 'Período A x B'], ['#vis-mensal', 'Evolução mensal'],
     ['#vis-fornecedor', 'Por fornecedor'], ['#vis-compras', 'Compras']].forEach(function (p) {
        var el = document.querySelector(p[0]);
        var tbl = el && (el.tagName === 'TABLE' ? el : el.querySelector('table'));
        if (tbl) XLSX.utils.book_append_sheet(wb, xlsTabela(tbl), p[1]);
    });
    XLSX.writeFile(wb, XLS_NOME + '.xlsx');
}
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
