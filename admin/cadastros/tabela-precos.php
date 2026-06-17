<?php
require_once __DIR__ . '/../../config.php';
requireComercial();

// Endpoint AJAX: cotação do dia (USD/EUR em BRL) via AwesomeAPI
if (isset($_GET['cotacao'])) {
    header('Content-Type: application/json; charset=utf-8');
    $api = buscarCotacaoAPI();
    if (!$api) {
        echo json_encode(['ok' => false]);
        exit;
    }
    // Registra a cotação do dia (cache usado também na criação dos pedidos)
    $atualizado = $api['data'] ?: date('Y-m-d H:i:s');
    setConfig('cotacao_usd', $api['usd']);
    setConfig('cotacao_eur', $api['eur']);
    setConfig('cotacao_data', date('Y-m-d'));
    setConfig('cotacao_atualizado', $atualizado);
    echo json_encode([
        'ok'   => true,
        'usd'  => $api['usd'],
        'eur'  => $api['eur'],
        'data' => $atualizado,
    ]);
    exit;
}

$dolarSeg = (float)getConfig('dolar_seguranca', 0);
$euroSeg  = (float)getConfig('euro_seguranca', 0);
// preço em moeda estrangeira = preço auxiliar / câmbio de segurança
$calcMoeda = function ($aux, $rate) {
    if ($aux === null || $rate <= 0) return null;
    return round($aux / $rate, 2);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    try {
        if ($a === 'cambio') {
            $ds = $_POST['dolar_seguranca'] !== '' ? (float)$_POST['dolar_seguranca'] : 0;
            $es = $_POST['euro_seguranca']  !== '' ? (float)$_POST['euro_seguranca']  : 0;
            setConfig('dolar_seguranca', $ds);
            setConfig('euro_seguranca', $es);
            db()->prepare('UPDATE tabela_precos SET
                preco_dolar = CASE WHEN preco_auxiliar IS NOT NULL AND ? > 0 THEN ROUND(preco_auxiliar / ?, 2) ELSE NULL END,
                preco_euro  = CASE WHEN preco_auxiliar IS NOT NULL AND ? > 0 THEN ROUND(preco_auxiliar / ?, 2) ELSE NULL END')
                ->execute([$ds, $ds, $es, $es]);
            flash('success', 'Câmbio de segurança atualizado e preços recalculados!');
        } elseif ($a === 'criar') {
            $pid  = (int)$_POST['produto_id'];
            $pp   = (float)$_POST['preco_padrao'];
            $pn   = $_POST['preco_network']  !== '' ? (float)$_POST['preco_network']  : null;
            $pa   = $_POST['preco_auxiliar'] !== '' ? (float)$_POST['preco_auxiliar'] : null;
            $pd   = $calcMoeda($pa, $dolarSeg);
            $pe   = $calcMoeda($pa, $euroSeg);
            $exists = db()->prepare('SELECT id FROM tabela_precos WHERE produto_id=?');
            $exists->execute([$pid]);
            if ($exists->fetchColumn()) {
                db()->prepare('UPDATE tabela_precos SET preco_padrao=?,preco_network=?,preco_auxiliar=?,preco_dolar=?,preco_euro=? WHERE produto_id=?')
                    ->execute([$pp, $pn, $pa, $pd, $pe, $pid]);
            } else {
                db()->prepare('INSERT INTO tabela_precos (produto_id,preco_padrao,preco_network,preco_auxiliar,preco_dolar,preco_euro) VALUES (?,?,?,?,?,?)')
                    ->execute([$pid, $pp, $pn, $pa, $pd, $pe]);
            }
            flash('success', 'Preço salvo!');
        } elseif ($a === 'editar') {
            $pn = $_POST['preco_network']  !== '' ? (float)$_POST['preco_network']  : null;
            $pa = $_POST['preco_auxiliar'] !== '' ? (float)$_POST['preco_auxiliar'] : null;
            $pd = $calcMoeda($pa, $dolarSeg);
            $pe = $calcMoeda($pa, $euroSeg);
            db()->prepare('UPDATE tabela_precos SET preco_padrao=?,preco_network=?,preco_auxiliar=?,preco_dolar=?,preco_euro=? WHERE id=?')
                ->execute([(float)$_POST['preco_padrao'], $pn, $pa, $pd, $pe, (int)$_POST['id']]);
            flash('success', 'Preço atualizado!');
        } elseif ($a === 'excluir') {
            db()->prepare('DELETE FROM tabela_precos WHERE id=?')->execute([(int)$_POST['id']]);
            flash('success', 'Preço removido!');
        } elseif ($a === 'importar') {
            $dados = json_decode($_POST['dados'] ?? '[]', true);
            if (!is_array($dados)) throw new Exception('Dados inválidos.');
            $ins = 0; $upd = 0; $skip = 0;
            foreach ($dados as $row) {
                $cod = trim($row['codigo_produto'] ?? '');
                if (!$cod) { $skip++; continue; }
                $prod = db()->prepare('SELECT id FROM produtos WHERE codigo_produto = ?');
                $prod->execute([$cod]);
                $prodId = $prod->fetchColumn();
                if (!$prodId) { $skip++; continue; }
                $pp = isset($row['preco_padrao'])  && $row['preco_padrao']  !== '' ? (float)$row['preco_padrao']  : 0;
                $pn = isset($row['preco_network']) && $row['preco_network'] !== '' ? (float)$row['preco_network'] : null;
                $pa = isset($row['preco_auxiliar'])&& $row['preco_auxiliar']!== '' ? (float)$row['preco_auxiliar']: null;
                $pd = $calcMoeda($pa, $dolarSeg);
                $pe = $calcMoeda($pa, $euroSeg);
                $exists = db()->prepare('SELECT id FROM tabela_precos WHERE produto_id=?');
                $exists->execute([$prodId]);
                $existId = $exists->fetchColumn();
                if ($existId) {
                    db()->prepare('UPDATE tabela_precos SET preco_padrao=?,preco_network=?,preco_auxiliar=?,preco_dolar=?,preco_euro=? WHERE id=?')
                        ->execute([$pp, $pn, $pa, $pd, $pe, $existId]);
                    $upd++;
                } else {
                    db()->prepare('INSERT INTO tabela_precos (produto_id,preco_padrao,preco_network,preco_auxiliar,preco_dolar,preco_euro) VALUES (?,?,?,?,?,?)')
                        ->execute([$prodId, $pp, $pn, $pa, $pd, $pe]);
                    $ins++;
                }
            }
            $msg = "Importação concluída: $ins inserido(s), $upd atualizado(s)";
            if ($skip > 0) $msg .= ", $skip linha(s) com código não encontrado ignorada(s)";
            flash('success', $msg . '.');
        }
    } catch (Exception $e) { flash('danger', 'Erro: ' . $e->getMessage()); }
    header('Location: ' . BASE_URL . '/admin/cadastros/tabela-precos.php'); exit;
}

$busca = trim($_GET['q'] ?? '');
$tabelaStmt = db()->prepare('SELECT t.*, p.codigo_produto, p.descricao_pt FROM tabela_precos t
    JOIN produtos p ON p.id = t.produto_id
    WHERE (:q = "" OR p.codigo_produto LIKE :qlike OR p.descricao_pt LIKE :qlike2)
    ORDER BY p.descricao_pt');
$tabelaStmt->execute([':q' => $busca, ':qlike' => "%$busca%", ':qlike2' => "%$busca%"]);
$tabela   = $tabelaStmt->fetchAll();
$produtos = db()->query('SELECT id, codigo_produto, descricao_pt FROM produtos WHERE status="ativo" ORDER BY descricao_pt')->fetchAll();

// Última cotação registrada (exibida ao abrir a tela)
$cotUsd  = (float)getConfig('cotacao_usd', 0);
$cotEur  = (float)getConfig('cotacao_eur', 0);
$cotAtu  = getConfig('cotacao_atualizado');
$cotAtuFmt = $cotAtu ? date('d/m/Y H:i', strtotime($cotAtu)) : '';

$pageTitle = 'Tabela de Preços';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-tags me-2"></i>Tabela de Preços</h4>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#modalImport">
            <i class="bi bi-file-earmark-arrow-up me-1"></i>Importar Excel
        </button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal" onclick="novoReg()">
            <i class="bi bi-plus-lg me-1"></i>Adicionar Preço
        </button>
    </div>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <form method="POST" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="cambio">
            <div class="col-12">
                <h6 class="fw-bold mb-0"><i class="bi bi-currency-exchange me-2 text-primary"></i>Câmbio de Segurança</h6>
                <small class="text-muted">Usado para calcular <strong>Preço Dólar</strong> e <strong>Preço Euro</strong> a partir do Preço Auxiliar.</small>
            </div>

            <div class="col-12">
                <div class="p-3 rounded-3 border bg-light">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small mb-1">Dólar hoje (USD→BRL)</label>
                            <div class="input-group">
                                <span class="input-group-text">R$</span>
                                <input type="text" id="cot_usd" class="form-control bg-white" readonly placeholder="—"
                                       value="<?= $cotUsd > 0 ? number_format($cotUsd, 4, ',', '.') : '' ?>"
                                       data-raw="<?= $cotUsd > 0 ? e($cotUsd) : '' ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small mb-1">Euro hoje (EUR→BRL)</label>
                            <div class="input-group">
                                <span class="input-group-text">R$</span>
                                <input type="text" id="cot_eur" class="form-control bg-white" readonly placeholder="—"
                                       value="<?= $cotEur > 0 ? number_format($cotEur, 4, ',', '.') : '' ?>"
                                       data-raw="<?= $cotEur > 0 ? e($cotEur) : '' ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <button type="button" id="btnCotacao" class="btn btn-outline-primary" onclick="buscarCotacao()">
                                <i class="bi bi-arrow-repeat me-1"></i>Buscar cotação
                            </button>
                        </div>
                        <div class="col-12">
                            <small id="cot_info" class="text-muted"><?= $cotAtuFmt ? 'Cotação comercial (compra) — atualizada em ' . e($cotAtuFmt) . '. Fonte: AwesomeAPI.' : '' ?></small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold small mb-1">Dólar de Segurança</label>
                <div class="input-group">
                    <span class="input-group-text">US$</span>
                    <input type="number" step="0.0001" min="0" name="dolar_seguranca" class="form-control" placeholder="0,00" value="<?= $dolarSeg > 0 ? e($dolarSeg) : '' ?>">
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold small mb-1">Euro de Segurança</label>
                <div class="input-group">
                    <span class="input-group-text">€</span>
                    <input type="number" step="0.0001" min="0" name="euro_seguranca" class="form-control" placeholder="0,00" value="<?= $euroSeg > 0 ? e($euroSeg) : '' ?>">
                </div>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary" onclick="return confirm('Salvar o câmbio e recalcular os preços Dólar e Euro de todos os produtos?')">
                    <i class="bi bi-save me-1"></i>Salvar e recalcular
                </button>
            </div>
        </form>
    </div>
</div>

<form class="mb-3" method="GET">
    <div class="row g-2 align-items-end">
        <div class="col-md-5">
            <label class="form-label fw-semibold small mb-1">Buscar</label>
            <input type="text" name="q" class="form-control" placeholder="Código ou descrição do produto..." value="<?= e($busca) ?>" autofocus>
        </div>
        <div class="col-auto d-flex gap-2">
            <button type="submit" class="btn btn-outline-primary">
                <i class="bi bi-search me-1"></i>Filtrar
            </button>
            <?php if ($busca !== ''): ?>
            <a href="?" class="btn btn-outline-secondary" title="Limpar filtro">
                <i class="bi bi-x-lg"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
</form>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Código</th>
                    <th>Produto</th>
                    <th class="text-end">Preço Padrão</th>
                    <th class="text-end">Preço Network</th>
                    <th class="text-end">Preço Auxiliar</th>
                    <th class="text-end">Preço Dólar</th>
                    <th class="text-end">Preço Euro</th>
                    <th class="text-end pe-3">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($tabela): foreach ($tabela as $t): ?>
                <tr>
                    <td class="ps-3"><code><?= e($t['codigo_produto']) ?></code></td>
                    <td><?= e($t['descricao_pt']) ?></td>
                    <td class="text-end fw-semibold"><?= moedaBR($t['preco_padrao']) ?></td>
                    <td class="text-end text-muted"><?= $t['preco_network']  !== null ? moedaBR($t['preco_network'])  : '—' ?></td>
                    <td class="text-end text-muted"><?= $t['preco_auxiliar'] !== null ? moedaBR($t['preco_auxiliar']) : '—' ?></td>
                    <td class="text-end text-muted"><?= $t['preco_dolar'] !== null ? 'US$ ' . number_format((float)$t['preco_dolar'], 2, ',', '.') : '—' ?></td>
                    <td class="text-end text-muted"><?= $t['preco_euro']  !== null ? '€ '   . number_format((float)$t['preco_euro'],  2, ',', '.') : '—' ?></td>
                    <td class="text-end pe-3">
                        <button class="btn btn-sm btn-outline-primary" onclick="editarReg(<?= htmlspecialchars(json_encode($t),ENT_QUOTES) ?>)"><i class="bi bi-pencil"></i></button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Remover preço?')">
                            <input type="hidden" name="action" value="excluir">
                            <input type="hidden" name="id" value="<?= $t['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="8" class="text-center text-muted py-4">Nenhum preço cadastrado.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php if (!empty($tabela)): ?>
    <div class="card-footer bg-white text-muted small py-2 ps-3">
        <?= count($tabela) ?> produto(s) com preço cadastrado.
    </div>
    <?php endif; ?>
</div>

<!-- Modal Adicionar/Editar -->
<div class="modal fade" id="modal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" id="fa" value="criar">
                <input type="hidden" name="id" id="fi">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="mt">Adicionar Preço</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Produto <span class="text-danger">*</span></label>
                        <select name="produto_id" id="f_produto" class="form-select" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($produtos as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= e($p['codigo_produto']) ?> — <?= e($p['descricao_pt']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Preço Padrão <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0" name="preco_padrao" id="f_pp" class="form-control" required value="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Preço Network</label>
                            <input type="number" step="0.01" min="0" name="preco_network" id="f_pn" class="form-control" placeholder="—">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Preço Auxiliar</label>
                            <input type="number" step="0.01" min="0" name="preco_auxiliar" id="f_pa" class="form-control" placeholder="—">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Importar Excel -->
<div class="modal fade" id="modalImport" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-file-earmark-excel me-2 text-success"></i>Importar Tabela de Preços — Excel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="resetImport()"></button>
            </div>
            <div class="modal-body">

                <!-- Passo 1 -->
                <div id="imp-step1">
                    <div class="alert alert-info py-2 mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        A planilha deve ter <strong>cabeçalho na primeira linha</strong> e os dados a partir da segunda.
                        O sistema localiza cada produto pelo <strong>Código do Produto</strong> e faz upsert (insere ou atualiza).
                    </div>
                    <div class="mb-3">
                        <strong>Colunas esperadas:</strong>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <?php foreach (['A' => 'Codigo do Produto', 'B' => 'Preço Padrão', 'C' => 'Preço Network', 'D' => 'Preço Auxiliar'] as $col => $label): ?>
                            <span class="badge bg-light text-dark border fs-6 px-3 py-2">
                                <span class="fw-bold text-primary"><?= $col ?></span> → <?= $label ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="border rounded p-5 text-center" id="dropZone"
                         style="border:2px dashed #6c757d!important;cursor:pointer"
                         onclick="document.getElementById('impFile').click()"
                         ondragover="event.preventDefault();this.style.background='#f0faf5'"
                         ondragleave="this.style.background=''"
                         ondrop="handleDrop(event)">
                        <i class="bi bi-file-earmark-excel text-success" style="font-size:3rem"></i>
                        <div class="mt-2 fw-semibold">Clique ou arraste o arquivo aqui</div>
                        <div class="text-muted small">Suporta .xlsx e .xls</div>
                        <input type="file" id="impFile" accept=".xlsx,.xls" class="d-none" onchange="lerArquivo(this.files[0])">
                    </div>
                    <div id="imp-loading" class="text-center mt-3 d-none">
                        <div class="spinner-border text-primary" role="status"></div>
                        <div class="mt-2 text-muted">Lendo planilha...</div>
                    </div>
                </div>

                <!-- Passo 2: preview -->
                <div id="imp-step2" class="d-none">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <span class="fw-semibold">Prévia —</span>
                            <span id="imp-total-label" class="text-muted"></span>
                        </div>
                        <button class="btn btn-sm btn-outline-secondary" onclick="resetImport()">
                            <i class="bi bi-arrow-left me-1"></i>Trocar arquivo
                        </button>
                    </div>
                    <div style="max-height:400px;overflow-y:auto">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light" style="position:sticky;top:0;z-index:1">
                                <tr>
                                    <th>#</th>
                                    <th>Código do Produto</th>
                                    <th class="text-end">Preço Padrão</th>
                                    <th class="text-end">Preço Network</th>
                                    <th class="text-end">Preço Auxiliar</th>
                                </tr>
                            </thead>
                            <tbody id="impPreviewBody"></tbody>
                        </table>
                    </div>
                    <div class="alert alert-warning mt-3 py-2 d-none" id="imp-warn-rows">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <span id="imp-warn-text"></span>
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" onclick="resetImport()">Cancelar</button>
                <form method="POST" id="formImport" style="display:contents">
                    <input type="hidden" name="action" value="importar">
                    <input type="hidden" name="dados" id="imp-dados">
                    <button type="submit" class="btn btn-success d-none" id="imp-btn-submit"
                            onclick="document.getElementById('imp-dados').value=JSON.stringify(importData)">
                        <i class="bi bi-upload me-1"></i>
                        <span id="imp-btn-label">Importar</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
var importData = [];

function parsePreco(val) {
    if (val === undefined || val === null || val === '') return '';
    val = String(val).trim().replace(/[R$\s]/g, '');
    if (val === '' || val === '-' || val === '—') return '';
    // BRL format: "1.234,56" → "1234.56"
    if (val.indexOf(',') !== -1) {
        val = val.replace(/\./g, '').replace(',', '.');
    }
    var n = parseFloat(val);
    return isNaN(n) ? '' : n;
}

function fmtPreco(v) {
    if (v === '' || v === null || v === undefined) return '<span class="text-muted">—</span>';
    return 'R$ ' + parseFloat(v).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

function handleDrop(ev) {
    ev.preventDefault();
    document.getElementById('dropZone').style.background = '';
    var file = ev.dataTransfer.files[0];
    if (file) lerArquivo(file);
}

function lerArquivo(file) {
    if (!file) return;
    document.getElementById('imp-loading').classList.remove('d-none');
    var reader = new FileReader();
    reader.onload = function(e) {
        try {
            var wb   = XLSX.read(new Uint8Array(e.target.result), {type: 'array'});
            var ws   = wb.Sheets[wb.SheetNames[0]];
            var rows = XLSX.utils.sheet_to_json(ws, {header: 1, defval: '', raw: false});
            processarLinhas(rows);
        } catch(err) {
            alert('Erro ao ler o arquivo: ' + err.message);
            document.getElementById('imp-loading').classList.add('d-none');
        }
    };
    reader.readAsArrayBuffer(file);
}

function processarLinhas(rows) {
    importData = [];
    var skipped = 0;
    // Detecta se a primeira linha é cabeçalho (contém texto não numérico na coluna A)
    var startRow = 0;
    if (rows.length > 0) {
        var firstCell = String(rows[0][0] || '').trim().toLowerCase();
        if (!firstCell || isNaN(parseFloat(firstCell))) startRow = 1;
    }
    for (var i = startRow; i < rows.length; i++) {
        var row = rows[i];
        var cod = String(row[0] || '').trim();
        if (!cod) { skipped++; continue; }
        importData.push({
            codigo_produto:  cod,
            preco_padrao:    parsePreco(row[1]),
            preco_network:   parsePreco(row[2]),
            preco_auxiliar:  parsePreco(row[3])
        });
    }

    document.getElementById('imp-loading').classList.add('d-none');
    document.getElementById('imp-step1').classList.add('d-none');
    document.getElementById('imp-step2').classList.remove('d-none');
    document.getElementById('imp-btn-submit').classList.remove('d-none');

    var total = importData.length;
    document.getElementById('imp-total-label').textContent =
        total + ' registro(s) encontrado(s)' + (skipped > 0 ? ' (' + skipped + ' linha(s) vazias ignoradas)' : '');
    document.getElementById('imp-btn-label').textContent = 'Importar ' + total + ' registro(s)';

    var body = document.getElementById('impPreviewBody');
    body.innerHTML = '';
    var preview = importData.slice(0, 200);
    preview.forEach(function(obj, idx) {
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td class="text-muted small">' + (idx + 1) + '</td>' +
            '<td><code>' + esc(obj.codigo_produto) + '</code></td>' +
            '<td class="text-end fw-semibold">' + fmtPreco(obj.preco_padrao) + '</td>' +
            '<td class="text-end">' + fmtPreco(obj.preco_network) + '</td>' +
            '<td class="text-end">' + fmtPreco(obj.preco_auxiliar) + '</td>';
        body.appendChild(tr);
    });

    if (importData.length > 200) {
        document.getElementById('imp-warn-rows').classList.remove('d-none');
        document.getElementById('imp-warn-text').textContent =
            'Exibindo apenas os primeiros 200 registros. Todos os ' + importData.length + ' serão importados.';
    }
}

function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function resetImport() {
    importData = [];
    document.getElementById('imp-step1').classList.remove('d-none');
    document.getElementById('imp-step2').classList.add('d-none');
    document.getElementById('imp-loading').classList.add('d-none');
    document.getElementById('imp-btn-submit').classList.add('d-none');
    document.getElementById('imp-warn-rows').classList.add('d-none');
    document.getElementById('impPreviewBody').innerHTML = '';
    document.getElementById('impFile').value = '';
    document.getElementById('dropZone').style.background = '';
}
</script>

<script>
function novoReg() {
    document.getElementById('mt').textContent = 'Adicionar Preço';
    document.getElementById('fa').value = 'criar';
    document.getElementById('fi').value = '';
    document.getElementById('f_produto').value = '';
    document.getElementById('f_pp').value = '0';
    document.getElementById('f_pn').value = '';
    document.getElementById('f_pa').value = '';
}
function editarReg(d) {
    document.getElementById('mt').textContent = 'Editar Preço';
    document.getElementById('fa').value = 'editar';
    document.getElementById('fi').value = d.id;
    document.getElementById('f_produto').value = d.produto_id;
    document.getElementById('f_pp').value = d.preco_padrao || '0';
    document.getElementById('f_pn').value = d.preco_network  != null ? d.preco_network  : '';
    document.getElementById('f_pa').value = d.preco_auxiliar != null ? d.preco_auxiliar : '';
    new bootstrap.Modal(document.getElementById('modal')).show();
}
</script>
<script>
function fmtData(s) {
    if (!s) return '';
    // "2026-06-16 19:54:07" -> "16/06/2026 19:54"
    var m = String(s).match(/(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
    if (m) return m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5];
    return s;
}
function buscarCotacao() {
    var btn = document.getElementById('btnCotacao');
    var original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Buscando...';
    fetch('?cotacao=1', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.ok) throw new Error();
            var usd = document.getElementById('cot_usd');
            var eur = document.getElementById('cot_eur');
            usd.value = parseFloat(d.usd).toFixed(4).replace('.', ',');
            eur.value = parseFloat(d.eur).toFixed(4).replace('.', ',');
            usd.dataset.raw = d.usd;
            eur.dataset.raw = d.eur;
            document.getElementById('cot_info').textContent =
                'Cotação comercial (compra) — atualizada em ' + fmtData(d.data) + '. Fonte: AwesomeAPI.';
        })
        .catch(function () { alert('Não foi possível buscar a cotação agora. Tente novamente em instantes.'); })
        .finally(function () { btn.disabled = false; btn.innerHTML = original; });
}
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
