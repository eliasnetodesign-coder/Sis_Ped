<?php
require_once __DIR__ . '/../../config.php';
requireComercial();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    try {
        $d = [(int)$_POST['cliente_id'], (int)$_POST['trimestre'], (int)$_POST['ano'], (float)$_POST['meta_cliente']];
        if ($a === 'criar') {
            db()->prepare('INSERT INTO metas (cliente_id,trimestre,ano,meta_cliente) VALUES (?,?,?,?)')->execute($d);
            flash('success', 'Meta criada!');
        } elseif ($a === 'editar') {
            $d[] = (int)$_POST['id'];
            db()->prepare('UPDATE metas SET cliente_id=?,trimestre=?,ano=?,meta_cliente=? WHERE id=?')->execute($d);
            flash('success', 'Meta atualizada!');
        } elseif ($a === 'excluir') {
            db()->prepare('DELETE FROM metas WHERE id=?')->execute([(int)$_POST['id']]);
            flash('success', 'Meta excluída!');
        }
    } catch (Exception $e) { flash('danger', 'Erro: ' . $e->getMessage()); }
    header('Location: ' . BASE_URL . '/admin/cadastros/metas.php'); exit;
}

$metas = db()->query('SELECT m.*, c.razao_social, c.codigo_cliente FROM metas m JOIN clientes c ON c.id=m.cliente_id ORDER BY m.ano DESC, m.trimestre')->fetchAll();
$clientes = db()->query('SELECT id, codigo_cliente, razao_social FROM clientes WHERE status="ativo" ORDER BY razao_social')->fetchAll();

$pageTitle = 'Cadastro de Metas';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-bullseye me-2"></i>Cadastro de Metas</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal" onclick="novoReg()"><i class="bi bi-plus-lg me-1"></i>Nova Meta</button>
</div>
<div class="card shadow-sm border-0"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
    <thead class="table-light"><tr><th>Código</th><th>Cliente</th><th>Trimestre</th><th>Ano</th><th>Meta</th><th>Ações</th></tr></thead>
    <tbody>
    <?php if ($metas): foreach ($metas as $m): ?>
        <tr>
            <td><span class="badge bg-secondary"><?= e($m['codigo_cliente']) ?></span></td>
            <td><?= e($m['razao_social']) ?></td>
            <td><span class="badge bg-info"><?= $m['trimestre'] ?>ºTRI</span></td>
            <td><?= $m['ano'] ?></td>
            <td class="fw-semibold"><?= moedaBR($m['meta_cliente']) ?></td>
            <td>
                <button class="btn btn-sm btn-outline-primary" onclick="editarReg(<?= htmlspecialchars(json_encode($m),ENT_QUOTES) ?>)"><i class="bi bi-pencil"></i></button>
                <form method="POST" class="d-inline" onsubmit="return confirm('Excluir meta?')"><input type="hidden" name="action" value="excluir"><input type="hidden" name="id" value="<?= $m['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
            </td>
        </tr>
    <?php endforeach; else: ?>
        <tr><td colspan="6" class="text-center text-muted py-4">Nenhuma meta cadastrada.</td></tr>
    <?php endif; ?>
    </tbody>
</table></div></div></div>

<div class="modal fade" id="modal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form method="POST" onsubmit="if(!document.getElementById('f_cli').value){document.getElementById('f_cli_txt').focus();return false;}">
        <input type="hidden" name="action" id="fa" value="criar"><input type="hidden" name="id" id="fi">
        <div class="modal-header"><h5 class="modal-title fw-bold" id="mt">Nova Meta</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label fw-semibold">Cliente <span class="text-danger">*</span></label>
                <input type="hidden" name="cliente_id" id="f_cli">
                <div class="position-relative">
                    <input type="text" id="f_cli_txt" class="form-control" placeholder="Buscar por nome ou código..." autocomplete="off">
                    <div id="f_cli_drop" class="border rounded bg-white shadow-sm position-absolute w-100"
                         style="display:none;max-height:210px;overflow-y:auto;z-index:1060">
                        <?php foreach ($clientes as $c): ?>
                        <div class="px-3 py-2 cli-opt"
                             style="cursor:pointer;font-size:.9rem"
                             onmouseover="this.style.background='#f0f4f2'"
                             onmouseout="this.style.background=''"
                             data-id="<?= $c['id'] ?>"
                             data-label="<?= e($c['codigo_cliente'] . ' — ' . $c['razao_social']) ?>"
                             data-search="<?= e(mb_strtolower($c['codigo_cliente'] . ' ' . $c['razao_social'])) ?>">
                            <span class="badge bg-secondary me-1" style="font-size:.7rem"><?= e($c['codigo_cliente']) ?></span><?= e($c['razao_social']) ?>
                        </div>
                        <?php endforeach; ?>
                        <div id="f_cli_empty" class="px-3 py-2 text-muted small" style="display:none">Nenhum resultado.</div>
                    </div>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label fw-semibold">Trimestre</label>
                    <select name="trimestre" id="f_tri" class="form-select">
                        <option value="1">1ºTRI</option><option value="2">2ºTRI</option><option value="3">3ºTRI</option><option value="4">4ºTRI</option>
                    </select>
                </div>
                <div class="col-md-4"><label class="form-label fw-semibold">Ano</label><input type="number" name="ano" id="f_ano" class="form-control" value="<?= date('Y') ?>"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Meta (R$)</label><input type="number" step="0.01" name="meta_cliente" id="f_meta" class="form-control" value="0"></div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salvar</button></div>
    </form>
</div></div></div>
<script>
// Dropdown de busca de cliente
(function() {
    var txt   = document.getElementById('f_cli_txt');
    var hid   = document.getElementById('f_cli');
    var drop  = document.getElementById('f_cli_drop');
    var empty = document.getElementById('f_cli_empty');
    var opts  = drop.querySelectorAll('.cli-opt');

    function filter(q) {
        var n = 0;
        opts.forEach(function(o) {
            var show = !q || o.dataset.search.indexOf(q) !== -1;
            o.style.display = show ? '' : 'none';
            if (show) n++;
        });
        empty.style.display = n === 0 ? '' : 'none';
    }

    txt.addEventListener('focus', function() { filter(txt.value.toLowerCase()); drop.style.display = ''; });
    txt.addEventListener('input', function() { hid.value = ''; filter(txt.value.toLowerCase()); drop.style.display = ''; });
    txt.addEventListener('blur',  function() { setTimeout(function() { drop.style.display = 'none'; }, 200); });

    drop.addEventListener('mousedown', function(e) {
        var o = e.target.closest('.cli-opt');
        if (!o) return;
        hid.value = o.dataset.id;
        txt.value = o.dataset.label;
        drop.style.display = 'none';
    });
}());

function novoReg() {
    document.getElementById('mt').textContent = 'Nova Meta';
    document.getElementById('fa').value = 'criar';
    document.getElementById('fi').value = '';
    document.getElementById('f_cli').value = '';
    document.getElementById('f_cli_txt').value = '';
    document.getElementById('f_tri').value = '1';
    document.getElementById('f_ano').value = '<?= date('Y') ?>';
    document.getElementById('f_meta').value = 0;
}
function editarReg(d) {
    document.getElementById('mt').textContent = 'Editar Meta';
    document.getElementById('fa').value = 'editar';
    document.getElementById('fi').value = d.id;
    document.getElementById('f_cli').value = d.cliente_id || '';
    var opt = document.querySelector('#f_cli_drop .cli-opt[data-id="' + d.cliente_id + '"]');
    document.getElementById('f_cli_txt').value = opt ? opt.dataset.label : '';
    document.getElementById('f_tri').value = d.trimestre || 1;
    document.getElementById('f_ano').value = d.ano || '<?= date('Y') ?>';
    document.getElementById('f_meta').value = d.meta_cliente || 0;
    new bootstrap.Modal(document.getElementById('modal')).show();
}
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
