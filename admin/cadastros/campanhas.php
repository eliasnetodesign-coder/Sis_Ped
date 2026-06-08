<?php
require_once __DIR__ . '/../../config.php';
requireComercial();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    try {
        $d = [
            $_POST['codigo_campanha'],
            $_POST['produto_id']    ?: null,
            $_POST['linha'],
            $_POST['grupo'],
            $_POST['subgrupo'],
            $_POST['canal_venda_id'] ?: null,
            (int)$_POST['quantidade'],
            (float)$_POST['desconto'],
        ];
        if ($a === 'criar') {
            db()->prepare('INSERT INTO campanhas (codigo_campanha,produto_id,linha,grupo,subgrupo,canal_venda_id,quantidade,desconto) VALUES (?,?,?,?,?,?,?,?)')->execute($d);
            flash('success', 'Campanha criada!');
        } elseif ($a === 'editar') {
            $d[] = (int)$_POST['id'];
            db()->prepare('UPDATE campanhas SET codigo_campanha=?,produto_id=?,linha=?,grupo=?,subgrupo=?,canal_venda_id=?,quantidade=?,desconto=? WHERE id=?')->execute($d);
            flash('success', 'Campanha atualizada!');
        } elseif ($a === 'excluir') {
            db()->prepare('DELETE FROM campanhas WHERE id=?')->execute([(int)$_POST['id']]);
            flash('success', 'Campanha excluída!');
        }
    } catch (Exception $e) { flash('danger', 'Erro: ' . $e->getMessage()); }
    header('Location: ' . BASE_URL . '/admin/cadastros/campanhas.php'); exit;
}

$campanhas = db()->query('SELECT c.*, p.descricao_pt, cv.canal FROM campanhas c LEFT JOIN produtos p ON p.id=c.produto_id LEFT JOIN canal_venda cv ON cv.id=c.canal_venda_id ORDER BY c.codigo_campanha')->fetchAll();
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
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr><th>Código</th><th>Produto</th><th>Linha/Grupo</th><th>Canal</th><th>Qtd. Mín.</th><th>Desconto</th><th>Ações</th></tr>
            </thead>
            <tbody>
            <?php if ($campanhas): foreach ($campanhas as $c): ?>
                <tr>
                    <td><strong><?= e($c['codigo_campanha']) ?></strong></td>
                    <td><?= e($c['descricao_pt'] ?? '—') ?></td>
                    <td><?= e($c['linha']) ?><?= $c['grupo'] ? ' / ' . e($c['grupo']) : '' ?></td>
                    <td><?= $c['canal'] ? '<span class="badge bg-secondary">'.e($c['canal']).'</span>' : '<span class="text-muted small">Todos</span>' ?></td>
                    <td><?= e($c['quantidade']) ?> un.</td>
                    <td><span class="badge bg-success"><?= e($c['desconto']) ?>%</span></td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" onclick="editarReg(<?= htmlspecialchars(json_encode($c),ENT_QUOTES) ?>)"><i class="bi bi-pencil"></i></button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Excluir campanha?')">
                            <input type="hidden" name="action" value="excluir">
                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
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
                <input type="hidden" name="id" id="fi">
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
                        <div class="col-12">
                            <small class="text-muted">Selecione apenas <strong>um</strong> critério: Produto, Linha, Grupo ou Subgrupo.</small>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Produto</label>
                            <select name="produto_id" id="f_prod" class="form-select" onchange="exclusivo('f_prod')">
                                <option value="">— Todos os produtos —</option>
                                <?php foreach ($produtos as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= e($p['codigo_produto']) ?> — <?= e($p['descricao_pt']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Linha</label>
                            <select name="linha" id="f_linha" class="form-select" onchange="exclusivo('f_linha')">
                                <option value="">— Todas —</option>
                                <?php foreach ($linhas as $l): ?><option value="<?= e($l) ?>"><?= e($l) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Grupo</label>
                            <select name="grupo" id="f_grupo" class="form-select" onchange="exclusivo('f_grupo')">
                                <option value="">— Todos —</option>
                                <?php foreach ($grupos as $g): ?><option value="<?= e($g) ?>"><?= e($g) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Subgrupo</label>
                            <select name="subgrupo" id="f_sub" class="form-select" onchange="exclusivo('f_sub')">
                                <option value="">— Todos —</option>
                                <?php foreach ($subgrupos as $s): ?><option value="<?= e($s) ?>"><?= e($s) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Quantidade Mínima</label>
                            <input type="number" name="quantidade" id="f_qtd" class="form-control" value="1" min="1">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Desconto (%)</label>
                            <input type="number" step="0.01" name="desconto" id="f_desc" class="form-control" value="0">
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
var _exclusivoIds = ['f_prod', 'f_linha', 'f_grupo', 'f_sub'];
function exclusivo(ativo) {
    var temValor = document.getElementById(ativo).value !== '';
    _exclusivoIds.forEach(function(id) {
        if (id === ativo) return;
        var el = document.getElementById(id);
        if (temValor) { el.value = ''; el.disabled = true; }
        else          { el.disabled = false; }
    });
}
function resetExclusivo() {
    _exclusivoIds.forEach(function(id) {
        document.getElementById(id).value    = '';
        document.getElementById(id).disabled = false;
    });
}
function novoReg() {
    document.getElementById('mt').textContent  = 'Nova Campanha';
    document.getElementById('fa').value        = 'criar';
    document.getElementById('fi').value        = '';
    document.getElementById('f_cod').value     = '';
    document.getElementById('f_canal').value   = '';
    document.getElementById('f_qtd').value     = 1;
    document.getElementById('f_desc').value    = 0;
    resetExclusivo();
}
function editarReg(d) {
    document.getElementById('mt').textContent  = 'Editar Campanha';
    document.getElementById('fa').value        = 'editar';
    document.getElementById('fi').value        = d.id;
    document.getElementById('f_cod').value     = d.codigo_campanha || '';
    document.getElementById('f_canal').value   = d.canal_venda_id  || '';
    document.getElementById('f_qtd').value     = d.quantidade || 1;
    document.getElementById('f_desc').value    = d.desconto   || 0;
    resetExclusivo();
    document.getElementById('f_prod').value  = d.produto_id || '';
    document.getElementById('f_linha').value = d.linha      || '';
    document.getElementById('f_grupo').value = d.grupo      || '';
    document.getElementById('f_sub').value   = d.subgrupo   || '';
    _exclusivoIds.forEach(function(id) { if (document.getElementById(id).value) exclusivo(id); });
    new bootstrap.Modal(document.getElementById('modal')).show();
}
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
