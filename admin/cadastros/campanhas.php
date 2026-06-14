<?php
require_once __DIR__ . '/../../config.php';
requireComercial();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    try {
        if ($a === 'excluir') {
            db()->prepare('DELETE FROM campanhas WHERE codigo_campanha=?')->execute([$_POST['codigo_campanha']]);
            flash('success', 'Campanha excluída!');

        } elseif ($a === 'criar' || $a === 'editar') {
            $cod   = trim($_POST['codigo_campanha'] ?? '');
            if ($cod === '') throw new Exception('Informe o código da campanha.');
            $tipo  = ($_POST['tipo'] ?? 'desconto') === 'bonificacao' ? 'bonificacao' : 'desconto';
            $canal = $_POST['canal_venda_id'] ?: null;
            $qtd   = (int)$_POST['quantidade'];
            $desc  = $tipo === 'bonificacao' ? 0.0 : (float)$_POST['desconto'];

            // Produtos bonificados (apenas para tipo bonificacao)
            $bonif = [];
            if ($tipo === 'bonificacao') {
                foreach (($_POST['bonif'] ?? []) as $b) {
                    $pid = (int)($b['produto_id'] ?? 0);
                    if (!$pid) continue;
                    $bq  = max(1, (int)($b['qtd'] ?? 1));
                    $bonif[$pid] = ($bonif[$pid] ?? 0) + $bq;
                }
                if (!$bonif) throw new Exception('Adicione ao menos um produto bonificado.');
            }

            db()->beginTransaction();

            // Substitui por completo as linhas da campanha (código original e/ou novo)
            $codOrig = trim($_POST['codigo_original'] ?? '');
            if ($codOrig !== '' && $codOrig !== $cod) {
                db()->prepare('DELETE FROM campanhas WHERE codigo_campanha=?')->execute([$codOrig]);
                db()->prepare('DELETE FROM campanha_bonificacao WHERE codigo_campanha=?')->execute([$codOrig]);
            }
            db()->prepare('DELETE FROM campanhas WHERE codigo_campanha=?')->execute([$cod]);
            db()->prepare('DELETE FROM campanha_bonificacao WHERE codigo_campanha=?')->execute([$cod]);

            $ins = db()->prepare('INSERT INTO campanhas (codigo_campanha,produto_id,linha,grupo,subgrupo,canal_venda_id,quantidade,desconto,tipo) VALUES (?,?,?,?,?,?,?,?,?)');

            $prodIds = array_values(array_unique(array_filter(array_map('intval', $_POST['produto_ids'] ?? []))));
            if ($prodIds) {
                // Modo Produtos: uma linha por produto (gatilho)
                foreach ($prodIds as $pid) {
                    $ins->execute([$cod, $pid, null, null, null, $canal, $qtd, $desc, $tipo]);
                }
            } else {
                // Modo Categoria: cada critério preenchido vira uma linha (Linha / Grupo / Subgrupo)
                $criterios = [];
                if (trim($_POST['linha']    ?? '') !== '') $criterios[] = ['linha',    trim($_POST['linha'])];
                if (trim($_POST['grupo']    ?? '') !== '') $criterios[] = ['grupo',    trim($_POST['grupo'])];
                if (trim($_POST['subgrupo'] ?? '') !== '') $criterios[] = ['subgrupo', trim($_POST['subgrupo'])];
                if (!$criterios) $criterios[] = [null, null]; // campanha para todos os produtos
                foreach ($criterios as $c) {
                    $ins->execute([
                        $cod, null,
                        $c[0] === 'linha'    ? $c[1] : null,
                        $c[0] === 'grupo'    ? $c[1] : null,
                        $c[0] === 'subgrupo' ? $c[1] : null,
                        $canal, $qtd, $desc, $tipo,
                    ]);
                }
            }

            // Grava os produtos bonificados
            if ($tipo === 'bonificacao') {
                $insB = db()->prepare('INSERT INTO campanha_bonificacao (codigo_campanha, produto_id, quantidade) VALUES (?,?,?)');
                foreach ($bonif as $pid => $bq) $insB->execute([$cod, $pid, $bq]);
            }

            db()->commit();
            flash('success', $a === 'criar' ? 'Campanha criada!' : 'Campanha atualizada!');
        }
    } catch (Exception $e) {
        if (db()->inTransaction()) db()->rollBack();
        flash('danger', 'Erro: ' . $e->getMessage());
    }
    header('Location: ' . BASE_URL . '/admin/cadastros/campanhas.php'); exit;
}

// Carrega todas as linhas e agrupa por código de campanha
$rows = db()->query('SELECT c.*, p.descricao_pt, p.codigo_produto, cv.canal
    FROM campanhas c
    LEFT JOIN produtos p ON p.id = c.produto_id
    LEFT JOIN canal_venda cv ON cv.id = c.canal_venda_id
    ORDER BY c.codigo_campanha, c.id')->fetchAll();

$campanhas = [];
foreach ($rows as $r) {
    $k = $r['codigo_campanha'];
    if (!isset($campanhas[$k])) {
        $campanhas[$k] = [
            'codigo_campanha' => $r['codigo_campanha'],
            'canal_venda_id'  => $r['canal_venda_id'],
            'canal'           => $r['canal'],
            'quantidade'      => $r['quantidade'],
            'desconto'        => $r['desconto'],
            'tipo'            => $r['tipo'] ?? 'desconto',
            'produtos'        => [],
            'bonificados'     => [],
            'linha'           => '',
            'grupo'           => '',
            'subgrupo'        => '',
        ];
    }
    if ($r['produto_id']) {
        $campanhas[$k]['produtos'][] = [
            'id'             => (int)$r['produto_id'],
            'codigo_produto' => $r['codigo_produto'],
            'descricao_pt'   => $r['descricao_pt'],
        ];
    }
    if ($r['linha']    !== null && $r['linha']    !== '') $campanhas[$k]['linha']    = $r['linha'];
    if ($r['grupo']    !== null && $r['grupo']    !== '') $campanhas[$k]['grupo']    = $r['grupo'];
    if ($r['subgrupo'] !== null && $r['subgrupo'] !== '') $campanhas[$k]['subgrupo'] = $r['subgrupo'];
}

// Produtos bonificados por campanha
foreach (db()->query('SELECT cb.codigo_campanha, cb.produto_id, cb.quantidade, p.codigo_produto, p.descricao_pt
    FROM campanha_bonificacao cb JOIN produtos p ON p.id = cb.produto_id ORDER BY cb.id')->fetchAll() as $b) {
    if (isset($campanhas[$b['codigo_campanha']])) {
        $campanhas[$b['codigo_campanha']]['bonificados'][] = [
            'id'             => (int)$b['produto_id'],
            'codigo_produto' => $b['codigo_produto'],
            'descricao_pt'   => $b['descricao_pt'],
            'quantidade'     => (int)$b['quantidade'],
        ];
    }
}

$produtos  = db()->query('SELECT id, codigo_produto, descricao_pt FROM produtos WHERE status="ativo" ORDER BY descricao_pt')->fetchAll();
$linhas    = db()->query("SELECT DISTINCT linha    FROM produtos WHERE linha    IS NOT NULL AND linha    <> '' ORDER BY linha"   )->fetchAll(PDO::FETCH_COLUMN);
$grupos    = db()->query("SELECT DISTINCT grupo    FROM produtos WHERE grupo    IS NOT NULL AND grupo    <> '' ORDER BY grupo"   )->fetchAll(PDO::FETCH_COLUMN);
$subgrupos = db()->query("SELECT DISTINCT subgrupo FROM produtos WHERE subgrupo IS NOT NULL AND subgrupo <> '' ORDER BY subgrupo")->fetchAll(PDO::FETCH_COLUMN);
$canais    = db()->query('SELECT id, canal FROM canal_venda ORDER BY canal')->fetchAll();

$pageTitle = 'Cadastro de Campanhas';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-megaphone me-2"></i>Cadastro de Campanhas</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal" onclick="novoReg()">
        <i class="bi bi-plus-lg me-1"></i>Nova Campanha
    </button>
</div>
<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr><th>Código</th><th>Produtos</th><th>Linha/Grupo/Subgrupo</th><th>Canal</th><th>Qtd. Mín.</th><th>Tipo / Recompensa</th><th>Ações</th></tr>
            </thead>
            <tbody>
            <?php if ($campanhas): foreach ($campanhas as $c):
                $criterios = [];
                if ($c['linha'])    $criterios[] = 'Linha: '    . $c['linha'];
                if ($c['grupo'])    $criterios[] = 'Grupo: '    . $c['grupo'];
                if ($c['subgrupo']) $criterios[] = 'Subgrupo: ' . $c['subgrupo'];
            ?>
                <tr>
                    <td><strong><?= e($c['codigo_campanha']) ?></strong></td>
                    <td>
                        <?php if ($c['produtos']): ?>
                            <span class="badge bg-primary rounded-pill mb-1"><?= count($c['produtos']) ?> produto<?= count($c['produtos']) != 1 ? 's' : '' ?></span>
                            <div class="small text-muted" style="max-width:340px">
                                <?= e(implode(', ', array_map(fn($p) => $p['descricao_pt'] ?: $p['codigo_produto'], $c['produtos']))) ?>
                            </div>
                        <?php else: ?>
                            <span class="text-muted small"><?= $criterios ? '—' : 'Todos os produtos' ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= $criterios ? e(implode(' · ', $criterios)) : '<span class="text-muted">—</span>' ?></td>
                    <td><?= $c['canal'] ? '<span class="badge bg-secondary">'.e($c['canal']).'</span>' : '<span class="text-muted small">Todos</span>' ?></td>
                    <td><?= e($c['quantidade']) ?> un.</td>
                    <td>
                        <?php if ($c['tipo'] === 'bonificacao'): ?>
                            <span class="badge bg-warning text-dark mb-1"><i class="bi bi-gift me-1"></i>Bonificação</span>
                            <div class="small text-muted" style="max-width:300px">
                                <?= $c['bonificados']
                                    ? e(implode(', ', array_map(fn($b) => $b['quantidade'] . 'x ' . ($b['descricao_pt'] ?: $b['codigo_produto']), $c['bonificados'])))
                                    : '<span class="text-danger">sem produtos</span>' ?>
                            </div>
                        <?php else: ?>
                            <span class="badge bg-success"><?= e($c['desconto']) ?>%</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap">
                        <button class="btn btn-sm btn-outline-primary" onclick="editarReg(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)"><i class="bi bi-pencil"></i></button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Excluir campanha?')">
                            <input type="hidden" name="action" value="excluir">
                            <input type="hidden" name="codigo_campanha" value="<?= e($c['codigo_campanha']) ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Nenhuma campanha cadastrada.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" id="fa" value="criar">
                <input type="hidden" name="codigo_original" id="f_cod_orig">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="mt">Nova Campanha</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Código da Campanha <span class="text-danger">*</span></label>
                            <input type="text" name="codigo_campanha" id="f_cod" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Canal de Venda</label>
                            <select name="canal_venda_id" id="f_canal" class="form-select">
                                <option value="">— Todos os canais —</option>
                                <?php foreach ($canais as $cv): ?>
                                <option value="<?= $cv['id'] ?>"><?= e($cv['canal']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Tipo de Campanha <span class="text-danger">*</span></label>
                            <select name="tipo" id="f_tipo" class="form-select" onchange="campSetTipo(this.value)">
                                <option value="desconto">Desconto (%)</option>
                                <option value="bonificacao">Produtos Bonificados</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Qtd. Mínima</label>
                            <input type="number" name="quantidade" id="f_qtd" class="form-control" value="1" min="1">
                        </div>
                        <div class="col-md-3" id="campDescWrap">
                            <label class="form-label fw-semibold">Desconto (%)</label>
                            <input type="number" step="0.01" name="desconto" id="f_desc" class="form-control" value="0">
                        </div>

                        <div class="col-12">
                            <hr class="my-1">
                            <small class="text-muted">
                                <strong>Gatilho da campanha:</strong> defina por <strong>Produtos</strong> (um ou vários)
                                <strong>ou</strong> por <strong>Categoria</strong> (Linha / Grupo / Subgrupo) — os dois modos não se combinam.
                                A Qtd. Mínima é a quantidade comprada que aciona a campanha.
                            </small>
                        </div>

                        <!-- Modo: Produtos -->
                        <div class="col-12">
                            <label class="form-label fw-semibold">Produtos</label>
                            <div class="row g-2">
                                <div class="col-md-9">
                                    <div class="position-relative">
                                        <input type="text" id="campSearch" class="form-control"
                                               placeholder="Buscar produto por código ou nome..." autocomplete="off">
                                        <div id="campDropdown" class="list-group position-absolute w-100 shadow-sm"
                                             style="display:none;z-index:1056;top:100%;left:0;max-height:240px;overflow-y:auto"></div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <button type="button" class="btn btn-success w-100" id="campAddBtn" onclick="campProdAdd()">
                                        <i class="bi bi-plus-lg me-1"></i>Adicionar
                                    </button>
                                </div>
                            </div>
                            <div class="table-responsive mt-2 d-none" id="campProdWrap">
                                <table class="table table-sm table-bordered align-middle mb-0">
                                    <thead class="table-light">
                                        <tr><th style="width:130px">Código</th><th>Produto</th><th style="width:60px" class="text-center">—</th></tr>
                                    </thead>
                                    <tbody id="campProdBody"></tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Modo: Categoria -->
                        <div class="col-12"><div class="text-center text-muted small fw-semibold">— OU por categoria —</div></div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Linha</label>
                            <select name="linha" id="f_linha" class="form-select" onchange="campAtualizarExclusividade()">
                                <option value="">— Todas —</option>
                                <?php foreach ($linhas as $l): ?><option value="<?= e($l) ?>"><?= e($l) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Grupo</label>
                            <select name="grupo" id="f_grupo" class="form-select" onchange="campAtualizarExclusividade()">
                                <option value="">— Todos —</option>
                                <?php foreach ($grupos as $g): ?><option value="<?= e($g) ?>"><?= e($g) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Subgrupo</label>
                            <select name="subgrupo" id="f_sub" class="form-select" onchange="campAtualizarExclusividade()">
                                <option value="">— Todos —</option>
                                <?php foreach ($subgrupos as $s): ?><option value="<?= e($s) ?>"><?= e($s) ?></option><?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Produtos Bonificados (apenas tipo Bonificação) -->
                        <div class="col-12 d-none" id="campBonifWrap">
                            <hr class="my-1">
                            <label class="form-label fw-semibold"><i class="bi bi-gift me-1 text-warning"></i>Produtos Bonificados</label>
                            <div class="text-muted small mb-2">
                                A quantidade bonificada é <strong>multiplicada</strong> conforme o total comprado atinge a Qtd. Mínima
                                (ex.: mínimo 50, comprou 100 → bônus ×2).
                            </div>
                            <div class="row g-2">
                                <div class="col-md-7">
                                    <div class="position-relative">
                                        <input type="text" id="bonifSearch" class="form-control"
                                               placeholder="Buscar produto por código ou nome..." autocomplete="off">
                                        <div id="bonifDropdown" class="list-group position-absolute w-100 shadow-sm"
                                             style="display:none;z-index:1056;top:100%;left:0;max-height:240px;overflow-y:auto"></div>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <input type="number" id="bonifQtd" class="form-control" value="1" min="1" title="Quantidade bonificada">
                                </div>
                                <div class="col-md-3">
                                    <button type="button" class="btn btn-warning w-100" onclick="bonifAdd()">
                                        <i class="bi bi-plus-lg me-1"></i>Adicionar
                                    </button>
                                </div>
                            </div>
                            <div class="table-responsive mt-2 d-none" id="bonifWrapTable">
                                <table class="table table-sm table-bordered align-middle mb-0">
                                    <thead class="table-light">
                                        <tr><th style="width:130px">Código</th><th>Produto</th><th style="width:110px" class="text-center">Qtd. Bônus</th><th style="width:60px" class="text-center">—</th></tr>
                                    </thead>
                                    <tbody id="bonifBody"></tbody>
                                </table>
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

<script>
var _campProdutos = <?= json_encode($produtos, JSON_UNESCAPED_UNICODE) ?>;
var _campSel      = null;

function campEsc(s)     { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function campEscAttr(s) { return campEsc(s).replace(/"/g,'&quot;'); }
function campNorm(s)    { return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }

// ---- Produtos (lista) ----
function campProdAddRow(id, codigo, nome) {
    var tr = document.createElement('tr');
    tr.innerHTML =
        '<td class="fw-semibold">' + campEsc(codigo) + '<input type="hidden" name="produto_ids[]" value="' + parseInt(id, 10) + '"></td>' +
        '<td>' + campEsc(nome) + '</td>' +
        '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" title="Remover" onclick="campProdRemove(this)"><i class="bi bi-x-lg"></i></button></td>';
    document.getElementById('campProdBody').appendChild(tr);
}
function campProdRemove(btn) { btn.closest('tr').remove(); campAtualizarExclusividade(); }

function campProdIds() {
    return Array.prototype.map.call(
        document.querySelectorAll('#campProdBody input[name="produto_ids[]"]'),
        function(i) { return String(i.value); }
    );
}

function campProdAdd() {
    if (!_campSel) { document.getElementById('campSearch').focus(); alert('Selecione um produto da lista.'); return; }
    if (campProdIds().indexOf(String(_campSel.id)) === -1) {
        campProdAddRow(_campSel.id, _campSel.codigo, _campSel.nome);
    }
    _campSel = null;
    document.getElementById('campSearch').value = '';
    document.getElementById('campDropdown').style.display = 'none';
    document.getElementById('campSearch').focus();
    campAtualizarExclusividade();
}

// ---- Exclusividade Produtos x Categoria ----
function campAtualizarExclusividade() {
    var nProd  = document.querySelectorAll('#campProdBody tr').length;
    var cats   = ['f_linha', 'f_grupo', 'f_sub'];
    var hasCat = cats.some(function(id) { return document.getElementById(id).value !== ''; });

    document.getElementById('campProdWrap').classList.toggle('d-none', nProd === 0);

    // Há produtos → bloqueia categorias
    cats.forEach(function(id) { document.getElementById(id).disabled = (nProd > 0); });
    // Há categoria → bloqueia busca de produtos
    document.getElementById('campSearch').disabled = hasCat;
    document.getElementById('campAddBtn').disabled = hasCat;
}

// ---- Autocomplete de produtos ----
(function() {
    var inp = document.getElementById('campSearch');
    var dd  = document.getElementById('campDropdown');
    inp.addEventListener('input', function() {
        var q = campNorm(inp.value.trim());
        _campSel = null;
        if (q.length < 1) { dd.style.display = 'none'; return; }
        var existentes = campProdIds();
        var lista = _campProdutos.filter(function(p) {
            if (existentes.indexOf(String(p.id)) !== -1) return false;
            return campNorm(p.descricao_pt).includes(q) || campNorm(p.codigo_produto).includes(q);
        }).slice(0, 15);

        if (!lista.length) {
            dd.innerHTML = '<div class="list-group-item small text-muted py-2">Nenhum produto encontrado</div>';
        } else {
            dd.innerHTML = lista.map(function(p) {
                return '<button type="button" class="list-group-item list-group-item-action py-2 px-3 small"' +
                    ' data-id="' + p.id + '" data-codigo="' + campEscAttr(p.codigo_produto) + '" data-nome="' + campEscAttr(p.descricao_pt) + '">' +
                    '<span class="badge bg-secondary me-2">' + campEsc(p.codigo_produto) + '</span>' + campEsc(p.descricao_pt) + '</button>';
            }).join('');
            dd.querySelectorAll('button').forEach(function(b) {
                b.addEventListener('mousedown', function(ev) {
                    ev.preventDefault();
                    _campSel = { id: b.dataset.id, codigo: b.dataset.codigo, nome: b.dataset.nome };
                    inp.value = b.dataset.nome + ' (' + b.dataset.codigo + ')';
                    dd.style.display = 'none';
                });
            });
        }
        dd.style.display = '';
    });
    inp.addEventListener('blur', function() { setTimeout(function() { dd.style.display = 'none'; }, 150); });
})();

// ==== Produtos Bonificados ====
var _bonifSel = null;
var bonifSeq  = 0;

function bonifAddRow(id, codigo, nome, qtd) {
    var idx = bonifSeq++;
    var q   = parseInt(qtd, 10) || 1;
    var tr  = document.createElement('tr');
    tr.innerHTML =
        '<td class="fw-semibold">' + campEsc(codigo) + '<input type="hidden" name="bonif[' + idx + '][produto_id]" value="' + parseInt(id, 10) + '"></td>' +
        '<td>' + campEsc(nome) + '</td>' +
        '<td class="text-center"><input type="number" min="1" value="' + q + '" name="bonif[' + idx + '][qtd]" class="form-control form-control-sm text-center mx-auto" style="max-width:80px"></td>' +
        '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" title="Remover" onclick="bonifRemove(this)"><i class="bi bi-x-lg"></i></button></td>';
    document.getElementById('bonifBody').appendChild(tr);
    bonifAtualizar();
}
function bonifRemove(btn) { btn.closest('tr').remove(); bonifAtualizar(); }
function bonifAtualizar() {
    var n = document.querySelectorAll('#bonifBody tr').length;
    document.getElementById('bonifWrapTable').classList.toggle('d-none', n === 0);
}
function bonifIds() {
    return Array.prototype.map.call(
        document.querySelectorAll('#bonifBody input[name$="[produto_id]"]'),
        function(i) { return String(i.value); }
    );
}
function bonifAdd() {
    if (!_bonifSel) { document.getElementById('bonifSearch').focus(); alert('Selecione um produto da lista.'); return; }
    var qtd = parseInt(document.getElementById('bonifQtd').value, 10) || 1;
    if (qtd < 1) qtd = 1;
    if (bonifIds().indexOf(String(_bonifSel.id)) === -1) {
        bonifAddRow(_bonifSel.id, _bonifSel.codigo, _bonifSel.nome, qtd);
    }
    _bonifSel = null;
    document.getElementById('bonifSearch').value = '';
    document.getElementById('bonifQtd').value = '1';
    document.getElementById('bonifDropdown').style.display = 'none';
    document.getElementById('bonifSearch').focus();
}
(function() {
    var inp = document.getElementById('bonifSearch');
    var dd  = document.getElementById('bonifDropdown');
    inp.addEventListener('input', function() {
        var q = campNorm(inp.value.trim());
        _bonifSel = null;
        if (q.length < 1) { dd.style.display = 'none'; return; }
        var lista = _campProdutos.filter(function(p) {
            return campNorm(p.descricao_pt).includes(q) || campNorm(p.codigo_produto).includes(q);
        }).slice(0, 15);
        if (!lista.length) {
            dd.innerHTML = '<div class="list-group-item small text-muted py-2">Nenhum produto encontrado</div>';
        } else {
            dd.innerHTML = lista.map(function(p) {
                return '<button type="button" class="list-group-item list-group-item-action py-2 px-3 small"' +
                    ' data-id="' + p.id + '" data-codigo="' + campEscAttr(p.codigo_produto) + '" data-nome="' + campEscAttr(p.descricao_pt) + '">' +
                    '<span class="badge bg-secondary me-2">' + campEsc(p.codigo_produto) + '</span>' + campEsc(p.descricao_pt) + '</button>';
            }).join('');
            dd.querySelectorAll('button').forEach(function(b) {
                b.addEventListener('mousedown', function(ev) {
                    ev.preventDefault();
                    _bonifSel = { id: b.dataset.id, codigo: b.dataset.codigo, nome: b.dataset.nome };
                    inp.value = b.dataset.nome + ' (' + b.dataset.codigo + ')';
                    dd.style.display = 'none';
                });
            });
        }
        dd.style.display = '';
    });
    inp.addEventListener('blur', function() { setTimeout(function() { dd.style.display = 'none'; }, 150); });
})();

// Alterna entre Desconto e Bonificação
function campSetTipo(tipo) {
    var ehBonif = (tipo === 'bonificacao');
    document.getElementById('campDescWrap').classList.toggle('d-none', ehBonif);
    document.getElementById('campBonifWrap').classList.toggle('d-none', !ehBonif);
}

// ---- Reset / abrir modal ----
function campLimpar() {
    document.getElementById('campProdBody').innerHTML = '';
    document.getElementById('campSearch').value = '';
    document.getElementById('campDropdown').style.display = 'none';
    _campSel = null;
    document.getElementById('f_linha').value = '';
    document.getElementById('f_grupo').value = '';
    document.getElementById('f_sub').value   = '';
    document.getElementById('bonifBody').innerHTML = '';
    document.getElementById('bonifSearch').value = '';
    document.getElementById('bonifQtd').value = '1';
    _bonifSel = null;
    bonifAtualizar();
    campAtualizarExclusividade();
}

function novoReg() {
    document.getElementById('mt').textContent      = 'Nova Campanha';
    document.getElementById('fa').value            = 'criar';
    document.getElementById('f_cod_orig').value    = '';
    document.getElementById('f_cod').value         = '';
    document.getElementById('f_canal').value       = '';
    document.getElementById('f_qtd').value         = 1;
    document.getElementById('f_desc').value        = 0;
    document.getElementById('f_tipo').value        = 'desconto';
    campLimpar();
    campSetTipo('desconto');
}

function editarReg(d) {
    document.getElementById('mt').textContent      = 'Editar Campanha';
    document.getElementById('fa').value            = 'editar';
    document.getElementById('f_cod_orig').value    = d.codigo_campanha || '';
    document.getElementById('f_cod').value         = d.codigo_campanha || '';
    document.getElementById('f_canal').value       = d.canal_venda_id  || '';
    document.getElementById('f_qtd').value         = d.quantidade || 1;
    document.getElementById('f_desc').value        = d.desconto   || 0;
    document.getElementById('f_tipo').value        = d.tipo || 'desconto';
    campLimpar();
    (d.produtos || []).forEach(function(p) {
        campProdAddRow(p.id, p.codigo_produto, p.descricao_pt);
    });
    (d.bonificados || []).forEach(function(b) {
        bonifAddRow(b.id, b.codigo_produto, b.descricao_pt, b.quantidade);
    });
    document.getElementById('f_linha').value = d.linha    || '';
    document.getElementById('f_grupo').value = d.grupo    || '';
    document.getElementById('f_sub').value   = d.subgrupo || '';
    campAtualizarExclusividade();
    campSetTipo(d.tipo || 'desconto');
    new bootstrap.Modal(document.getElementById('modal')).show();
}
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
