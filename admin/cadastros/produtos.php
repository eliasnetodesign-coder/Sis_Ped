<?php
require_once __DIR__ . '/../../config.php';
requireComercial();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    try {
        if ($a === 'criar' || $a === 'editar') {
            $d = [
                $_POST['codigo_produto'], $_POST['linha'], $_POST['grupo'], $_POST['subgrupo'],
                $_POST['codigo_barra'], $_POST['descricao_pt'], $_POST['descricao_en'], $_POST['descricao_es'],
                $_POST['nuance'], (int)$_POST['multiplo'],
                (float)$_POST['vendas_distribuidor'], (float)$_POST['vendas_varejo'], (float)$_POST['vendas_exportacao'],
                $_POST['ncm_id'] ?: null, $_POST['cest'], $_POST['status'],
            ];
            if ($a === 'criar') {
                db()->prepare('INSERT INTO produtos (codigo_produto,linha,grupo,subgrupo,codigo_barra,descricao_pt,descricao_en,descricao_es,nuance,multiplo,vendas_distribuidor,vendas_varejo,vendas_exportacao,ncm_id,cest,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($d);
                // Inserir preço padrão
                $pid = db()->lastInsertId();
                if ($_POST['preco_padrao']) {
                    db()->prepare('INSERT INTO tabela_precos (produto_id, preco_padrao) VALUES (?,?)')->execute([$pid, (float)$_POST['preco_padrao']]);
                }
                flash('success', 'Produto criado com sucesso!');
            } else {
                $d[] = (int)$_POST['id'];
                db()->prepare('UPDATE produtos SET codigo_produto=?,linha=?,grupo=?,subgrupo=?,codigo_barra=?,descricao_pt=?,descricao_en=?,descricao_es=?,nuance=?,multiplo=?,vendas_distribuidor=?,vendas_varejo=?,vendas_exportacao=?,ncm_id=?,cest=?,status=? WHERE id=?')->execute($d);
                if ($_POST['preco_padrao']) {
                    $exists = db()->prepare('SELECT id FROM tabela_precos WHERE produto_id=?');
                    $exists->execute([(int)$_POST['id']]);
                    if ($exists->fetch()) {
                        db()->prepare('UPDATE tabela_precos SET preco_padrao=? WHERE produto_id=?')->execute([(float)$_POST['preco_padrao'], (int)$_POST['id']]);
                    } else {
                        db()->prepare('INSERT INTO tabela_precos (produto_id,preco_padrao) VALUES (?,?)')->execute([(int)$_POST['id'], (float)$_POST['preco_padrao']]);
                    }
                }
                flash('success', 'Produto atualizado com sucesso!');
            }
        } elseif ($a === 'excluir') {
            db()->prepare('DELETE FROM produtos WHERE id=?')->execute([(int)$_POST['id']]);
            flash('success', 'Produto excluído!');
        } elseif ($a === 'importar') {
            $dados = json_decode($_POST['dados'] ?? '[]', true);
            if (!is_array($dados)) throw new Exception('Dados inválidos.');
            $ins = 0; $upd = 0; $skip = 0;
            foreach ($dados as $row) {
                $codigo = trim($row['codigo_produto'] ?? '');
                $descPT = trim($row['descricao_pt']  ?? '');
                if (!$codigo && !$descPT) { $skip++; continue; }
                $status   = strtolower(trim($row['status'] ?? '')) === 'inativo' ? 'inativo' : 'ativo';
                $multiplo = max(1, (int)($row['multiplo'] ?? 1));
                // Lookup NCM pelo código
                $ncm_id   = null;
                $ncm_code = trim($row['ncm'] ?? '');
                if ($ncm_code) {
                    $nq = db()->prepare('SELECT id FROM ncm WHERE ncm = ?');
                    $nq->execute([$ncm_code]);
                    $ncm_id = $nq->fetchColumn() ?: null;
                }
                // Verifica duplicata por código do produto
                $existing = false;
                if ($codigo) {
                    $chk = db()->prepare('SELECT id FROM produtos WHERE codigo_produto = ?');
                    $chk->execute([$codigo]);
                    $existing = $chk->fetchColumn();
                }
                $linha    = trim(preg_replace('/\d+/', '', $row['linha']    ?? ''));
                $grupo    = trim(preg_replace('/\d+/', '', $row['grupo']    ?? ''));
                $subgrupo = trim(preg_replace('/\d+/', '', $row['subgrupo'] ?? ''));
                $cbarra   = trim($row['codigo_barra'] ?? '');
                $cest     = trim($row['cest']        ?? '');
                if ($existing) {
                    db()->prepare('UPDATE produtos SET codigo_produto=?,linha=?,grupo=?,subgrupo=?,codigo_barra=?,descricao_pt=?,multiplo=?,ncm_id=?,cest=?,status=? WHERE id=?')
                        ->execute([$codigo,$linha,$grupo,$subgrupo,$cbarra,$descPT,$multiplo,$ncm_id,$cest,$status,$existing]);
                    $upd++;
                } else {
                    db()->prepare('INSERT INTO produtos (codigo_produto,linha,grupo,subgrupo,codigo_barra,descricao_pt,descricao_en,descricao_es,nuance,multiplo,vendas_distribuidor,vendas_varejo,vendas_exportacao,ncm_id,cest,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$codigo,$linha,$grupo,$subgrupo,$cbarra,$descPT,'','','', $multiplo,0,0,0,$ncm_id,$cest,$status]);
                    $ins++;
                }
            }
            flash('success', "Importação concluída: $ins inserido(s), $upd atualizado(s)" . ($skip > 0 ? ", $skip ignorado(s)." : '.'));
        }
    } catch (Exception $e) {
        flash('danger', 'Erro: ' . $e->getMessage());
    }
    $qs = trim($_POST['_qs'] ?? '');
    header('Location: ' . BASE_URL . '/admin/cadastros/produtos.php' . ($qs ? '?' . $qs : ''));
    exit;
}

$busca          = $_GET['q']      ?? '';
$filtro_status  = $_GET['status'] ?? 'ativo';
$filtro_linha   = $_GET['linha']  ?? '';

$conditions = [];
$params     = [];

if ($busca) {
    $conditions[] = '(p.descricao_pt LIKE ? OR p.codigo_produto LIKE ?)';
    $params[] = "%$busca%";
    $params[] = "%$busca%";
}
if ($filtro_status) {
    $conditions[] = 'p.status = ?';
    $params[] = $filtro_status;
}
if ($filtro_linha) {
    $conditions[] = 'p.linha = ?';
    $params[] = $filtro_linha;
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$produtos = db()->prepare("SELECT p.*, t.preco_padrao, n.ncm FROM produtos p
    LEFT JOIN tabela_precos t ON t.produto_id = p.id
    LEFT JOIN ncm n ON n.id = p.ncm_id
    $where ORDER BY p.descricao_pt");
$produtos->execute($params);
$produtos = $produtos->fetchAll();


$linhas = db()->query("SELECT DISTINCT linha FROM produtos WHERE linha IS NOT NULL AND linha <> '' ORDER BY linha")->fetchAll(PDO::FETCH_COLUMN);

$ncms = db()->query('SELECT * FROM ncm ORDER BY nome_categoria')->fetchAll();

$pageTitle = 'Cadastro de Produtos';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-box-seam me-2"></i>Cadastro de Produtos</h4>
    <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-outline-secondary" onclick="exportarProdutos()">
            <i class="bi bi-file-earmark-excel me-1"></i>Exportar
        </button>
        <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#modalImport">
            <i class="bi bi-file-earmark-arrow-up me-1"></i>Importar Excel
        </button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalProduto" onclick="novoRegistro()">
            <i class="bi bi-plus-lg me-1"></i>Novo Produto
        </button>
    </div>
</div>

<!-- Busca e Filtros -->
<form class="mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-5">
            <label class="form-label fw-semibold small mb-1">Buscar</label>
            <input type="text" name="q" class="form-control" placeholder="Código ou descrição..." value="<?= e($busca) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold small mb-1">Linha</label>
            <select name="linha" class="form-select">
                <option value="">Todas as linhas</option>
                <?php foreach ($linhas as $l): ?>
                <option value="<?= e($l) ?>" <?= $filtro_linha === $l ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label fw-semibold small mb-1">Status</label>
            <select name="status" class="form-select">
                <option value="">Todos</option>
                <option value="ativo"   <?= $filtro_status === 'ativo'   ? 'selected' : '' ?>>Ativo</option>
                <option value="inativo" <?= $filtro_status === 'inativo' ? 'selected' : '' ?>>Inativo</option>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-outline-primary w-100">Filtrar</button>
            <?php if (!empty($_GET)): ?>
            <a href="?" class="btn btn-outline-secondary" title="Limpar filtros"><i class="bi bi-x-lg"></i></a>
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
                    <th>Código</th>
                    <th>Descrição</th>
                    <th>Linha/Grupo</th>
                    <th>Preço Padrão</th>
                    <th>NCM</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($produtos): foreach ($produtos as $p): ?>
                <tr>
                    <td><strong><?= e($p['codigo_produto']) ?></strong></td>
                    <td><?= e($p['descricao_pt']) ?></td>
                    <td><?= e($p['linha']) ?><?= $p['grupo'] ? ' / ' . e($p['grupo']) : '' ?></td>
                    <td><?= moedaBR($p['preco_padrao']) ?></td>
                    <td><small><?= e($p['ncm'] ?? '—') ?></small></td>
                    <td><?= statusBadge($p['status']) ?></td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" onclick="editarRegistro(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Excluir este produto?')">
                            <input type="hidden" name="action" value="excluir">
                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
                            <input type="hidden" name="_qs" value="<?= e($_SERVER['QUERY_STRING']) ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Nenhum produto cadastrado.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="modalProduto" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" id="formAction" value="criar">
                <input type="hidden" name="id" id="formId">
                <input type="hidden" name="_qs" value="<?= e($_SERVER['QUERY_STRING']) ?>">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="modalTitle">Novo Produto</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Código do Produto</label>
                            <input type="text" name="codigo_produto" id="f_codigo" class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Linha</label>
                            <input type="text" name="linha" id="f_linha" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Grupo</label>
                            <input type="text" name="grupo" id="f_grupo" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Subgrupo</label>
                            <input type="text" name="subgrupo" id="f_subgrupo" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Código de Barra</label>
                            <input type="text" name="codigo_barra" id="f_codigo_barra" class="form-control">
                        </div>
                        <div class="col-md-9">
                            <label class="form-label fw-semibold">Descrição (Português) <span class="text-danger">*</span></label>
                            <input type="text" name="descricao_pt" id="f_descricao_pt" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Descrição (Inglês)</label>
                            <input type="text" name="descricao_en" id="f_descricao_en" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Descrição (Espanhol)</label>
                            <input type="text" name="descricao_es" id="f_descricao_es" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Nuance</label>
                            <input type="text" name="nuance" id="f_nuance" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Múltiplo</label>
                            <input type="number" name="multiplo" id="f_multiplo" class="form-control" value="1" min="1">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">V. Distribuidor</label>
                            <select name="vendas_distribuidor" id="f_vd" class="form-select">
                                <option value="0">Não</option>
                                <option value="1">Sim</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">V. Varejo</label>
                            <select name="vendas_varejo" id="f_vv" class="form-select">
                                <option value="0">Não</option>
                                <option value="1">Sim</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">V. Exportação</label>
                            <select name="vendas_exportacao" id="f_ve" class="form-select">
                                <option value="0">Não</option>
                                <option value="1">Sim</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Preço Padrão</label>
                            <input type="number" step="0.01" name="preco_padrao" id="f_preco" class="form-control" value="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">NCM</label>
                            <select name="ncm_id" id="f_ncm" class="form-select">
                                <option value="">Selecione...</option>
                                <?php foreach ($ncms as $n): ?>
                                <option value="<?= $n['id'] ?>"><?= e($n['ncm']) ?> — <?= e($n['nome_categoria']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">CEST</label>
                            <input type="text" name="cest" id="f_cest" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="status" id="f_status" class="form-select">
                                <option value="ativo">Ativo</option>
                                <option value="inativo">Inativo</option>
                            </select>
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
                <h5 class="modal-title fw-bold"><i class="bi bi-file-earmark-excel me-2 text-success"></i>Importar Produtos — Excel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="resetImport()"></button>
            </div>
            <div class="modal-body">

                <!-- Passo 1: selecionar arquivo -->
                <div id="imp-step1">
                    <div class="alert alert-info py-2 mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        O sistema lê a <strong>primeira aba</strong> da planilha .xlsx/.xls, ignorando as <strong>5 primeiras linhas</strong> (metadados do relatório + cabeçalho de colunas). Os dados começam na linha 6.
                        Se o produto já existir (mesmo Código) os dados serão <strong>atualizados</strong>; caso contrário será <strong>inserido</strong>.
                    </div>
                    <div class="mb-3">
                        <strong>Colunas lidas:</strong>
                        <div class="d-flex flex-wrap gap-1 mt-2">
                            <?php foreach ([
                                'A'=>'Código do Produto','B'=>'Descrição (PT)','C'=>'Status',
                                'E'=>'Código de Barra','O'=>'Grupo','P'=>'Subgrupo',
                                'Q'=>'Linha','AO'=>'NCM (código)','BF'=>'Múltiplo','CF'=>'CEST'
                            ] as $col => $label): ?>
                            <span class="badge bg-light text-dark border">
                                <span class="fw-bold text-primary"><?= $col ?></span> → <?= $label ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="border-2 border-dashed rounded p-5 text-center" id="dropZone"
                         style="border:2px dashed #6c757d;cursor:pointer"
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
                    <div style="max-height:380px;overflow-y:auto">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light" style="position:sticky;top:0;z-index:1">
                                <tr>
                                    <th>#</th>
                                    <th>Código</th>
                                    <th>Descrição (PT)</th>
                                    <th>Cód. Barra</th>
                                    <th>Linha</th>
                                    <th>Grupo / Subgrupo</th>
                                    <th>NCM</th>
                                    <th>Múlt.</th>
                                    <th>Status</th>
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
var _exportProdutos = <?= json_encode(array_map(function($p) {
    return [
        'Código'              => $p['codigo_produto']        ?? '',
        'Descrição PT'        => $p['descricao_pt']          ?? '',
        'Linha'               => $p['linha']                 ?? '',
        'Grupo'               => $p['grupo']                 ?? '',
        'Subgrupo'            => $p['subgrupo']              ?? '',
        'Cód. Barras'         => $p['codigo_barra']          ?? '',
        'Múltiplo'            => (int)($p['multiplo']        ?? 1),
        'Preço Padrão'        => (float)($p['preco_padrao']        ?? 0),
        'Venda Distribuidor'  => (float)($p['vendas_distribuidor'] ?? 0),
        'Venda Varejo'        => (float)($p['vendas_varejo']       ?? 0),
        'Venda Exportação'    => (float)($p['vendas_exportacao']   ?? 0),
        'NCM'                 => $p['ncm']                   ?? '',
        'CEST'                => $p['cest']                  ?? '',
        'Nuance'              => $p['nuance']                ?? '',
        'Status'              => $p['status']                ?? '',
    ];
}, $produtos), JSON_UNESCAPED_UNICODE) ?>;

function exportarProdutos() {
    if (!_exportProdutos.length) { alert('Nenhum registro para exportar.'); return; }
    var ws = XLSX.utils.json_to_sheet(_exportProdutos);
    var keys = Object.keys(_exportProdutos[0]);
    ws['!cols'] = keys.map(function(k) {
        var max = k.length;
        _exportProdutos.forEach(function(r) { max = Math.max(max, String(r[k] || '').length); });
        return { wch: Math.min(max + 2, 45) };
    });
    var wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Produtos');
    XLSX.writeFile(wb, 'produtos_<?= date('Y-m-d') ?>.xlsx');
}
</script>
<script>
// Mapeamento: índice de coluna (0-based) → campo
// A=0, B=1, C=2, E=4, O=14, P=15, Q=16, AO=40, BF=57, CF=83
var IMP_MAP = {
    0:  'codigo_produto',
    1:  'descricao_pt',
    2:  'status',
    4:  'codigo_barra',
    14: 'grupo',
    15: 'subgrupo',
    16: 'linha',
    40: 'ncm',
    57: 'multiplo',
    83: 'cest'
};

var importData = [];

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
    for (var i = 5; i < rows.length; i++) {
        var row = rows[i];
        var obj = {};
        for (var col in IMP_MAP) {
            obj[IMP_MAP[col]] = (row[col] !== undefined && row[col] !== null) ? String(row[col]).trim() : '';
        }
        obj.subgrupo = obj.subgrupo.replace(/^\d+\s*/, '');
        obj.linha    = obj.linha.replace(/^\d+\s*/, '');
        if (!obj.codigo_produto && !obj.descricao_pt) { skipped++; continue; }
        importData.push(obj);
    }

    document.getElementById('imp-loading').classList.add('d-none');
    document.getElementById('imp-step1').classList.add('d-none');
    document.getElementById('imp-step2').classList.remove('d-none');
    document.getElementById('imp-btn-submit').classList.remove('d-none');

    var total = importData.length;
    document.getElementById('imp-total-label').textContent =
        total + ' produto(s) encontrado(s)' + (skipped > 0 ? ' (' + skipped + ' linha(s) vazia(s) ignoradas)' : '');
    document.getElementById('imp-btn-label').textContent = 'Importar ' + total + ' produto(s)';

    var body = document.getElementById('impPreviewBody');
    body.innerHTML = '';
    importData.slice(0, 200).forEach(function(obj, idx) {
        var grupoComb = obj.grupo + (obj.subgrupo ? ' / ' + obj.subgrupo : '');
        var statusCls = obj.status.toLowerCase() === 'inativo' ? 'secondary' : 'success';
        var statusLbl = obj.status || 'Ativo';
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td class="text-muted small">' + (idx + 1) + '</td>' +
            '<td><small class="fw-semibold">' + esc(obj.codigo_produto) + '</small></td>' +
            '<td><small>' + esc(obj.descricao_pt) + '</small></td>' +
            '<td><small>' + esc(obj.codigo_barra) + '</small></td>' +
            '<td><small>' + esc(obj.linha) + '</small></td>' +
            '<td><small>' + esc(grupoComb) + '</small></td>' +
            '<td><small>' + esc(obj.ncm) + '</small></td>' +
            '<td><small>' + esc(obj.multiplo || '1') + '</small></td>' +
            '<td><span class="badge bg-' + statusCls + '">' + esc(statusLbl) + '</span></td>';
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
function novoRegistro() {
    document.getElementById('modalTitle').textContent = 'Novo Produto';
    document.getElementById('formAction').value = 'criar';
    document.getElementById('formId').value = '';
    ['codigo','linha','grupo','subgrupo','codigo_barra','descricao_pt','descricao_en','descricao_es','nuance','cest'].forEach(function(f){ document.getElementById('f_'+f).value=''; });
    ['multiplo'].forEach(function(f){ document.getElementById('f_'+f).value='1'; });
    ['vd','vv','ve','preco'].forEach(function(f){ document.getElementById('f_'+f).value='0'; });
    document.getElementById('f_ncm').value='';
    document.getElementById('f_status').value='ativo';
}
function editarRegistro(d) {
    document.getElementById('modalTitle').textContent = 'Editar Produto';
    document.getElementById('formAction').value = 'editar';
    document.getElementById('formId').value = d.id;
    document.getElementById('f_codigo').value = d.codigo_produto||'';
    document.getElementById('f_linha').value = d.linha||'';
    document.getElementById('f_grupo').value = d.grupo||'';
    document.getElementById('f_subgrupo').value = d.subgrupo||'';
    document.getElementById('f_codigo_barra').value = d.codigo_barra||'';
    document.getElementById('f_descricao_pt').value = d.descricao_pt||'';
    document.getElementById('f_descricao_en').value = d.descricao_en||'';
    document.getElementById('f_descricao_es').value = d.descricao_es||'';
    document.getElementById('f_nuance').value = d.nuance||'';
    document.getElementById('f_multiplo').value = d.multiplo||1;
    document.getElementById('f_vd').value = d.vendas_distribuidor > 0 ? '1' : '0';
    document.getElementById('f_vv').value = d.vendas_varejo > 0 ? '1' : '0';
    document.getElementById('f_ve').value = d.vendas_exportacao > 0 ? '1' : '0';
    document.getElementById('f_preco').value = d.preco_padrao||0;
    document.getElementById('f_ncm').value = d.ncm_id||'';
    document.getElementById('f_cest').value = d.cest||'';
    document.getElementById('f_status').value = d.status||'ativo';
    new bootstrap.Modal(document.getElementById('modalProduto')).show();
}
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
