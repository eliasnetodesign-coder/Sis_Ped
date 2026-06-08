<?php
require_once __DIR__ . '/../../config.php';
requireComercial();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    try {
        $d = [$_POST['nome_categoria'], $_POST['ncm'], $_POST['cest'], (float)$_POST['ipi']];
        if ($a === 'criar') {
            db()->prepare('INSERT INTO ncm (nome_categoria,ncm,cest,ipi) VALUES (?,?,?,?)')->execute($d);
            flash('success', 'NCM criado!');
        } elseif ($a === 'editar') {
            $d[] = (int)$_POST['id'];
            db()->prepare('UPDATE ncm SET nome_categoria=?,ncm=?,cest=?,ipi=? WHERE id=?')->execute($d);
            flash('success', 'NCM atualizado!');
        } elseif ($a === 'excluir') {
            db()->prepare('DELETE FROM ncm WHERE id=?')->execute([(int)$_POST['id']]);
            flash('success', 'NCM excluído!');
        }
    } catch (Exception $e) { flash('danger', 'Erro: ' . $e->getMessage()); }
    header('Location: ' . BASE_URL . '/admin/cadastros/ncm.php'); exit;
}

$ncms = db()->query('SELECT * FROM ncm ORDER BY nome_categoria')->fetchAll();
$pageTitle = 'Cadastro de NCM';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-upc-scan me-2"></i>Cadastro de NCM</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal" onclick="novoReg()"><i class="bi bi-plus-lg me-1"></i>Novo NCM</button>
</div>
<div class="card shadow-sm border-0"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
    <thead class="table-light"><tr><th>Categoria</th><th>NCM</th><th>CEST</th><th>IPI</th><th>Ações</th></tr></thead>
    <tbody>
    <?php if ($ncms): foreach ($ncms as $n): ?>
        <tr>
            <td><?= e($n['nome_categoria']) ?></td>
            <td><strong><?= e($n['ncm']) ?></strong></td>
            <td><?= e($n['cest']) ?></td>
            <td><?= e($n['ipi']) ?>%</td>
            <td>
                <button class="btn btn-sm btn-outline-primary" onclick="editarReg(<?= htmlspecialchars(json_encode($n),ENT_QUOTES) ?>)"><i class="bi bi-pencil"></i></button>
                <form method="POST" class="d-inline" onsubmit="return confirm('Excluir NCM?')"><input type="hidden" name="action" value="excluir"><input type="hidden" name="id" value="<?= $n['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
            </td>
        </tr>
    <?php endforeach; else: ?>
        <tr><td colspan="5" class="text-center text-muted py-4">Nenhum NCM cadastrado.</td></tr>
    <?php endif; ?>
    </tbody>
</table></div></div></div>

<div class="modal fade" id="modal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form method="POST">
        <input type="hidden" name="action" id="fa" value="criar"><input type="hidden" name="id" id="fi">
        <div class="modal-header"><h5 class="modal-title fw-bold" id="mt">Novo NCM</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="row g-3">
                <div class="col-md-12"><label class="form-label fw-semibold">Nome da Categoria</label><input type="text" name="nome_categoria" id="f_cat" class="form-control"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">NCM <span class="text-danger">*</span></label><input type="text" name="ncm" id="f_ncm" class="form-control" required></div>
                <div class="col-md-4"><label class="form-label fw-semibold">CEST</label><input type="text" name="cest" id="f_cest" class="form-control"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">IPI (%)</label><input type="number" step="0.0001" name="ipi" id="f_ipi" class="form-control" value="0"></div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salvar</button></div>
    </form>
</div></div></div>
<script>
function novoReg(){document.getElementById('mt').textContent='Novo NCM';document.getElementById('fa').value='criar';document.getElementById('fi').value='';['cat','ncm','cest'].forEach(function(f){document.getElementById('f_'+f).value='';});document.getElementById('f_ipi').value=0;}
function editarReg(d){document.getElementById('mt').textContent='Editar NCM';document.getElementById('fa').value='editar';document.getElementById('fi').value=d.id;document.getElementById('f_cat').value=d.nome_categoria||'';document.getElementById('f_ncm').value=d.ncm||'';document.getElementById('f_cest').value=d.cest||'';document.getElementById('f_ipi').value=d.ipi||0;new bootstrap.Modal(document.getElementById('modal')).show();}
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
