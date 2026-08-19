<?php
require_once __DIR__ . '/../../config.php';
requireComercial();

// Endpoint AJAX: histórico mensal de custo de um produto
if (isset($_GET['historico'])) {
    header('Content-Type: application/json; charset=utf-8');
    $pid = (int)$_GET['historico'];
    $hist = db()->prepare('SELECT competencia, custo FROM custos_produtos WHERE produto_id = ? ORDER BY competencia DESC');
    $hist->execute([$pid]);
    echo json_encode(['ok' => true, 'historico' => $hist->fetchAll()]);
    exit;
}

function competenciaValida($v) {
    return $v && preg_match('/^\d{4}-\d{2}$/', $v) ? $v . '-01' : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    try {
        if ($a === 'criar') {
            $pid  = (int)$_POST['produto_id'];
            $comp = competenciaValida($_POST['competencia'] ?? '');
            $custo = (float)$_POST['custo'];
            if (!$pid) throw new Exception('Selecione um produto.');
            if (!$comp) throw new Exception('Competência inválida.');
            if ($custo < 0) throw new Exception('Custo não pode ser negativo.');
            db()->prepare('INSERT INTO custos_produtos (produto_id,competencia,custo) VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE custo=VALUES(custo)')
                ->execute([$pid, $comp, $custo]);
            flash('success', 'Custo salvo!');
        } elseif ($a === 'editar') {
            $custo = (float)$_POST['custo'];
            if ($custo < 0) throw new Exception('Custo não pode ser negativo.');
            db()->prepare('UPDATE custos_produtos SET custo=? WHERE id=?')
                ->execute([$custo, (int)$_POST['id']]);
            flash('success', 'Custo atualizado!');
        } elseif ($a === 'excluir') {
            db()->prepare('DELETE FROM custos_produtos WHERE id=?')->execute([(int)$_POST['id']]);
            flash('success', 'Custo removido!');
        } elseif ($a === 'salvarFixo') {
            $comp = competenciaValida($_POST['competencia_fixo'] ?? '');
            $pct  = (float)str_replace(',', '.', $_POST['percentual'] ?? '0');
            if (!$comp) throw new Exception('Competência inválida.');
            if ($pct < 0) throw new Exception('Percentual não pode ser negativo.');
            db()->prepare('INSERT INTO custos_fixos (competencia,percentual) VALUES (?,?)
                ON DUPLICATE KEY UPDATE percentual=VALUES(percentual)')
                ->execute([$comp, $pct]);
            flash('success', 'Custo fixo salvo!');
        } elseif ($a === 'excluirFixo') {
            db()->prepare('DELETE FROM custos_fixos WHERE id=?')->execute([(int)$_POST['id']]);
            flash('success', 'Custo fixo removido!');
        } elseif ($a === 'importar') {
            $comp = competenciaValida($_POST['competencia'] ?? '');
            if (!$comp) throw new Exception('Selecione a competência (mês/ano) da importação.');
            $dados = json_decode($_POST['dados'] ?? '[]', true);
            if (!is_array($dados)) throw new Exception('Dados inválidos.');
            $ins = 0; $upd = 0; $skip = 0; $skipNeg = 0;
            foreach ($dados as $row) {
                $cod = trim($row['codigo_produto'] ?? '');
                if (!$cod) { $skip++; continue; }
                $prod = db()->prepare('SELECT id FROM produtos WHERE codigo_produto = ?');
                $prod->execute([$cod]);
                $prodId = $prod->fetchColumn();
                if (!$prodId) { $skip++; continue; }
                $custo = isset($row['custo']) && $row['custo'] !== '' ? (float)$row['custo'] : 0;
                if ($custo < 0) { $skipNeg++; continue; }
                $exists = db()->prepare('SELECT id FROM custos_produtos WHERE produto_id=? AND competencia=?');
                $exists->execute([$prodId, $comp]);
                $existId = $exists->fetchColumn();
                if ($existId) {
                    db()->prepare('UPDATE custos_produtos SET custo=? WHERE id=?')->execute([$custo, $existId]);
                    $upd++;
                } else {
                    db()->prepare('INSERT INTO custos_produtos (produto_id,competencia,custo) VALUES (?,?,?)')
                        ->execute([$prodId, $comp, $custo]);
                    $ins++;
                }
            }
            $msg = "Importação concluída: $ins inserido(s), $upd atualizado(s)";
            if ($skip > 0) $msg .= ", $skip linha(s) com código não encontrado ignorada(s)";
            if ($skipNeg > 0) $msg .= ", $skipNeg linha(s) com custo negativo ignorada(s)";
            flash('success', $msg . '.');
        }
    } catch (Exception $e) { flash('danger', 'Erro: ' . $e->getMessage()); }
    header('Location: ' . BASE_URL . '/admin/cadastros/custos-produtos.php'); exit;
}

$custosFixos = db()->query('SELECT * FROM custos_fixos ORDER BY competencia DESC')->fetchAll();

$competencias = db()->query('SELECT DISTINCT competencia FROM custos_produtos ORDER BY competencia DESC')->fetchAll(PDO::FETCH_COLUMN);
$competencia = $_GET['competencia'] ?? ($competencias[0] ?? date('Y-m-01'));
if (!preg_match('/^\d{4}-\d{2}-01$/', $competencia)) $competencia = $competencias[0] ?? date('Y-m-01');

$busca = trim($_GET['q'] ?? '');
$filtro_status = $_GET['status'] ?? 'ativo';
if (!in_array($filtro_status, ['ativo', 'inativo'], true)) $filtro_status = '';

$sql = 'SELECT c.*, p.codigo_produto, p.descricao_pt FROM custos_produtos c
    JOIN produtos p ON p.id = c.produto_id
    WHERE c.competencia = :competencia AND (:q = "" OR p.codigo_produto LIKE :qlike OR p.descricao_pt LIKE :qlike2)';
$params = [':competencia' => $competencia, ':q' => $busca, ':qlike' => "%$busca%", ':qlike2' => "%$busca%"];
if ($filtro_status) {
    $sql .= ' AND p.status = :status';
    $params[':status'] = $filtro_status;
}
$sql .= ' ORDER BY p.descricao_pt';
$custosStmt = db()->prepare($sql);
$custosStmt->execute($params);
$custos   = $custosStmt->fetchAll();
$produtos = db()->query('SELECT id, codigo_produto, descricao_pt FROM produtos WHERE status="ativo" ORDER BY descricao_pt')->fetchAll();

$mesesPt = [1=>'Janeiro',2=>'Fevereiro',3=>'Março',4=>'Abril',5=>'Maio',6=>'Junho',7=>'Julho',8=>'Agosto',9=>'Setembro',10=>'Outubro',11=>'Novembro',12=>'Dezembro'];
function fmtCompetencia($c, $meses) {
    [$y, $m] = explode('-', $c);
    return $meses[(int)$m] . '/' . $y;
}

$pageTitle = 'Custos dos Produtos';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-cash-coin me-2"></i>Custos dos Produtos</h4>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#modalImport">
            <i class="bi bi-file-earmark-arrow-up me-1"></i>Importar Excel
        </button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal" onclick="novoReg()">
            <i class="bi bi-plus-lg me-1"></i>Adicionar Custo
        </button>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-light d-flex justify-content-between align-items-center">
        <span class="fw-bold"><i class="bi bi-percent me-2"></i>Custo Fixo</span>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalFixo" onclick="novoFixo()">
            <i class="bi bi-plus-lg me-1"></i>Adicionar/Editar
        </button>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Competência</th>
                    <th class="text-end">Custo Fixo (%)</th>
                    <th class="text-end pe-3">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($custosFixos): foreach ($custosFixos as $cf): ?>
                <tr>
                    <td class="ps-3"><?= e(fmtCompetencia($cf['competencia'], $mesesPt)) ?></td>
                    <td class="text-end fw-semibold"><?= rtrim(rtrim(number_format((float)$cf['percentual'], 2, ',', '.'), '0'), ',') ?>%</td>
                    <td class="text-end pe-3">
                        <button class="btn btn-sm btn-outline-primary" onclick="editarFixo(<?= htmlspecialchars(json_encode($cf), ENT_QUOTES) ?>)"><i class="bi bi-pencil"></i></button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Remover custo fixo desta competência?')">
                            <input type="hidden" name="action" value="excluirFixo">
                            <input type="hidden" name="id" value="<?= $cf['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="3" class="text-center text-muted py-4">Nenhum custo fixo cadastrado.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<form class="mb-3" method="GET">
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label fw-semibold small mb-1">Competência</label>
            <select name="competencia" class="form-select" onchange="this.form.submit()">
                <?php if (!$competencias): ?>
                <option value="<?= e($competencia) ?>"><?= e(fmtCompetencia($competencia, $mesesPt)) ?></option>
                <?php else: foreach ($competencias as $c): ?>
                <option value="<?= e($c) ?>" <?= $c === $competencia ? 'selected' : '' ?>><?= e(fmtCompetencia($c, $mesesPt)) ?></option>
                <?php endforeach; endif; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold small mb-1">Buscar</label>
            <input type="text" name="q" class="form-control" placeholder="Código ou descrição do produto..." value="<?= e($busca) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label fw-semibold small mb-1">Status</label>
            <select name="status" class="form-select">
                <option value="">Todos</option>
                <option value="ativo"   <?= $filtro_status === 'ativo'   ? 'selected' : '' ?>>Ativo</option>
                <option value="inativo" <?= $filtro_status === 'inativo' ? 'selected' : '' ?>>Inativo</option>
            </select>
        </div>
        <div class="col-auto d-flex gap-2">
            <button type="submit" class="btn btn-outline-primary">
                <i class="bi bi-search me-1"></i>Filtrar
            </button>
            <?php if ($busca !== '' || $filtro_status !== 'ativo'): ?>
            <a href="?competencia=<?= e($competencia) ?>" class="btn btn-outline-secondary" title="Limpar filtro">
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
                    <th class="text-end">Custo</th>
                    <th class="text-end pe-3">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($custos): foreach ($custos as $c): ?>
                <tr>
                    <td class="ps-3"><code><?= e($c['codigo_produto']) ?></code></td>
                    <td><?= e($c['descricao_pt']) ?></td>
                    <td class="text-end fw-semibold"><?= moedaBR($c['custo']) ?></td>
                    <td class="text-end pe-3">
                        <button class="btn btn-sm btn-outline-secondary" title="Histórico" onclick="verHistorico(<?= (int)$c['produto_id'] ?>, '<?= e(addslashes($c['codigo_produto'] . ' — ' . $c['descricao_pt'])) ?>')"><i class="bi bi-clock-history"></i></button>
                        <button class="btn btn-sm btn-outline-primary" onclick="editarReg(<?= htmlspecialchars(json_encode($c),ENT_QUOTES) ?>)"><i class="bi bi-pencil"></i></button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Remover custo desta competência?')">
                            <input type="hidden" name="action" value="excluir">
                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="4" class="text-center text-muted py-4">Nenhum custo cadastrado para esta competência.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php if (!empty($custos)): ?>
    <div class="card-footer bg-white text-muted small py-2 ps-3">
        <?= count($custos) ?> produto(s) com custo cadastrado em <?= e(fmtCompetencia($competencia, $mesesPt)) ?>.
    </div>
    <?php endif; ?>
</div>

<!-- Modal Adicionar/Editar Custo Fixo -->
<div class="modal fade" id="modalFixo" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="salvarFixo">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="mtFixo">Adicionar Custo Fixo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Competência <span class="text-danger">*</span></label>
                            <input type="month" name="competencia_fixo" id="f_competencia_fixo" class="form-control" required value="<?= e(date('Y-m')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Custo Fixo <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" step="0.01" min="0" name="percentual" id="f_percentual" class="form-control" required value="0">
                                <span class="input-group-text">%</span>
                            </div>
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

<!-- Modal Adicionar/Editar -->
<div class="modal fade" id="modal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" id="fa" value="criar">
                <input type="hidden" name="id" id="fi">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="mt">Adicionar Custo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3" id="grupoProduto">
                        <label class="form-label fw-semibold">Produto <span class="text-danger">*</span></label>
                        <select name="produto_id" id="f_produto" class="form-select" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($produtos as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= e($p['codigo_produto']) ?> — <?= e($p['descricao_pt']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6" id="grupoCompetencia">
                            <label class="form-label fw-semibold">Competência <span class="text-danger">*</span></label>
                            <input type="month" name="competencia" id="f_competencia" class="form-control" required value="<?= e(substr($competencia, 0, 7)) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Custo <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">R$</span>
                                <input type="number" step="0.01" min="0" name="custo" id="f_custo" class="form-control" required value="0">
                            </div>
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

<!-- Modal Histórico -->
<div class="modal fade" id="modalHistorico" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-clock-history me-2 text-primary"></i>Histórico de Custo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-muted small mb-2" id="hist-produto"></div>
                <div style="max-height:400px;overflow-y:auto">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light" style="position:sticky;top:0">
                            <tr><th>Competência</th><th class="text-end">Custo</th></tr>
                        </thead>
                        <tbody id="hist-body"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Importar Excel -->
<div class="modal fade" id="modalImport" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-file-earmark-excel me-2 text-success"></i>Importar Custos dos Produtos — Excel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="resetImport()"></button>
            </div>
            <div class="modal-body">

                <!-- Passo 1 -->
                <div id="imp-step1">
                    <div class="alert alert-info py-2 mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        A planilha deve ter <strong>cabeçalho na primeira linha</strong> e os dados a partir da segunda.
                        O sistema localiza cada produto pelo <strong>Código do Produto</strong> e faz upsert (insere ou atualiza)
                        <strong>na competência selecionada abaixo</strong>, preservando o histórico dos demais meses.
                    </div>
                    <div class="mb-3">
                        <strong>Colunas esperadas:</strong>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <?php foreach (['A' => 'Codigo do Produto', 'U' => 'Custo'] as $col => $label): ?>
                            <span class="badge bg-light text-dark border fs-6 px-3 py-2">
                                <span class="fw-bold text-primary"><?= $col ?></span> → <?= $label ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="mb-3" style="max-width:280px">
                        <label class="form-label fw-semibold">Competência da importação <span class="text-danger">*</span></label>
                        <input type="month" id="imp_competencia" class="form-control" required value="<?= e(date('Y-m')) ?>">
                    </div>
                    <div class="border rounded p-5 text-center" id="dropZone"
                         style="border:2px dashed #6c757d!important;cursor:pointer"
                         onclick="clicarDropZone()"
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
                            <span class="badge bg-primary ms-1" id="imp-comp-label"></span>
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
                                    <th class="text-end">Custo</th>
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
                    <input type="hidden" name="competencia" id="imp-competencia-hidden">
                    <input type="hidden" name="dados" id="imp-dados">
                    <button type="submit" class="btn btn-success d-none" id="imp-btn-submit"
                            onclick="document.getElementById('imp-dados').value=JSON.stringify(importData);document.getElementById('imp-competencia-hidden').value=document.getElementById('imp_competencia').value;">
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
var mesesPt = <?= json_encode(array_values($mesesPt)) ?>;

function parseCusto(val) {
    if (val === undefined || val === null || val === '') return '';
    val = String(val).trim().replace(/[R$\s]/g, '');
    if (val === '' || val === '-' || val === '—') return '';
    if (val.indexOf(',') !== -1) {
        val = val.replace(/\./g, '').replace(',', '.');
    }
    var n = parseFloat(val);
    return isNaN(n) ? '' : n;
}

function fmtCusto(v) {
    if (v === '' || v === null || v === undefined) return '<span class="text-muted">—</span>';
    return 'R$ ' + parseFloat(v).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

function clicarDropZone() {
    if (!document.getElementById('imp_competencia').value) {
        alert('Selecione a competência antes de escolher o arquivo.');
        return;
    }
    document.getElementById('impFile').click();
}

function handleDrop(ev) {
    ev.preventDefault();
    document.getElementById('dropZone').style.background = '';
    if (!document.getElementById('imp_competencia').value) {
        alert('Selecione a competência antes de escolher o arquivo.');
        return;
    }
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
    var startRow = 0;
    if (rows.length > 0) {
        var firstCell = String(rows[0][0] || '').trim().toLowerCase();
        if (!firstCell || isNaN(parseFloat(firstCell))) startRow = 1;
    }
    for (var i = startRow; i < rows.length; i++) {
        var row = rows[i];
        var cod = String(row[0] || '').trim();
        var custo = parseCusto(row[20]); // coluna U
        if (!cod || custo === '') { skipped++; continue; }
        importData.push({
            codigo_produto: cod,
            custo: custo
        });
    }

    document.getElementById('imp-loading').classList.add('d-none');
    document.getElementById('imp-step1').classList.add('d-none');
    document.getElementById('imp-step2').classList.remove('d-none');
    document.getElementById('imp-btn-submit').classList.remove('d-none');

    var comp = document.getElementById('imp_competencia').value;
    var compParts = comp.split('-');
    document.getElementById('imp-comp-label').textContent = mesesPt[parseInt(compParts[1], 10) - 1] + '/' + compParts[0];

    var total = importData.length;
    document.getElementById('imp-total-label').textContent =
        total + ' registro(s) encontrado(s)' + (skipped > 0 ? ' (' + skipped + ' linha(s) sem código ou custo ignoradas)' : '');
    document.getElementById('imp-btn-label').textContent = 'Importar ' + total + ' registro(s)';

    var body = document.getElementById('impPreviewBody');
    body.innerHTML = '';
    var preview = importData.slice(0, 200);
    preview.forEach(function(obj, idx) {
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td class="text-muted small">' + (idx + 1) + '</td>' +
            '<td><code>' + esc(obj.codigo_produto) + '</code></td>' +
            '<td class="text-end fw-semibold">' + fmtCusto(obj.custo) + '</td>';
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
function novoFixo() {
    document.getElementById('mtFixo').textContent = 'Adicionar Custo Fixo';
    document.getElementById('f_competencia_fixo').value = '<?= e(date('Y-m')) ?>';
    document.getElementById('f_percentual').value = '0';
}
function editarFixo(d) {
    document.getElementById('mtFixo').textContent = 'Editar Custo Fixo';
    document.getElementById('f_competencia_fixo').value = d.competencia.substring(0, 7);
    document.getElementById('f_percentual').value = d.percentual || '0';
    new bootstrap.Modal(document.getElementById('modalFixo')).show();
}
function novoReg() {
    document.getElementById('mt').textContent = 'Adicionar Custo';
    document.getElementById('fa').value = 'criar';
    document.getElementById('fi').value = '';
    document.getElementById('grupoProduto').classList.remove('d-none');
    document.getElementById('grupoCompetencia').classList.remove('d-none');
    document.getElementById('f_produto').disabled = false;
    document.getElementById('f_competencia').disabled = false;
    document.getElementById('f_produto').value = '';
    document.getElementById('f_competencia').value = '<?= e(substr($competencia, 0, 7)) ?>';
    document.getElementById('f_custo').value = '0';
}
function editarReg(d) {
    document.getElementById('mt').textContent = 'Editar Custo';
    document.getElementById('fa').value = 'editar';
    document.getElementById('fi').value = d.id;
    document.getElementById('f_produto').value = d.produto_id;
    document.getElementById('f_competencia').value = d.competencia.substring(0, 7);
    document.getElementById('f_custo').value = d.custo || '0';
    document.getElementById('f_produto').disabled = true;
    document.getElementById('f_competencia').disabled = true;
    new bootstrap.Modal(document.getElementById('modal')).show();
}
function verHistorico(produtoId, label) {
    document.getElementById('hist-produto').textContent = label;
    document.getElementById('hist-body').innerHTML = '<tr><td colspan="2" class="text-center text-muted py-3">Carregando...</td></tr>';
    new bootstrap.Modal(document.getElementById('modalHistorico')).show();
    fetch('?historico=' + produtoId, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            var body = document.getElementById('hist-body');
            if (!d.ok || !d.historico.length) {
                body.innerHTML = '<tr><td colspan="2" class="text-center text-muted py-3">Nenhum histórico encontrado.</td></tr>';
                return;
            }
            body.innerHTML = '';
            d.historico.forEach(function (h) {
                var parts = h.competencia.split('-');
                var label = mesesPt[parseInt(parts[1], 10) - 1] + '/' + parts[0];
                var tr = document.createElement('tr');
                tr.innerHTML = '<td>' + label + '</td><td class="text-end fw-semibold">' + fmtCusto(h.custo) + '</td>';
                body.appendChild(tr);
            });
        })
        .catch(function () {
            document.getElementById('hist-body').innerHTML = '<tr><td colspan="2" class="text-center text-danger py-3">Erro ao carregar histórico.</td></tr>';
        });
}
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
