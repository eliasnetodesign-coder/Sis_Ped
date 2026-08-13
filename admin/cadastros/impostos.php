<?php
require_once __DIR__ . '/../../config.php';
requireComercial();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    try {
        $d = [trim($_POST['nome']), trim($_POST['cnpj']), (float)$_POST['irpj'], (float)$_POST['csll'], (float)$_POST['iss']];
        if ($a === 'criar') {
            db()->prepare('INSERT INTO impostos_empresas (nome,cnpj,irpj,csll,iss) VALUES (?,?,?,?,?)')->execute($d);
            flash('success', 'Empresa criada!');
        } elseif ($a === 'editar') {
            $d[] = (int)$_POST['id'];
            db()->prepare('UPDATE impostos_empresas SET nome=?,cnpj=?,irpj=?,csll=?,iss=? WHERE id=?')->execute($d);
            flash('success', 'Empresa atualizada!');
        } elseif ($a === 'excluir') {
            db()->prepare('DELETE FROM impostos_empresas WHERE id=?')->execute([(int)$_POST['id']]);
            flash('success', 'Empresa excluída!');
        }
    } catch (Exception $e) { flash('danger', 'Erro: ' . $e->getMessage()); }
    header('Location: ' . BASE_URL . '/admin/cadastros/impostos.php'); exit;
}

$empresas = db()->query('SELECT * FROM impostos_empresas ORDER BY nome')->fetchAll();
$pageTitle = 'Impostos';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-percent me-2"></i>Impostos</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal" onclick="novoReg()"><i class="bi bi-plus-lg me-1"></i>Nova Empresa</button>
</div>
<div class="card shadow-sm border-0"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
    <thead class="table-light"><tr><th>Nome</th><th>CNPJ</th><th>IRPJ</th><th>CSLL</th><th>ISS</th><th>Ações</th></tr></thead>
    <tbody>
    <?php if ($empresas): foreach ($empresas as $c): ?>
        <tr>
            <td><strong><?= e($c['nome']) ?></strong></td>
            <td><?= e($c['cnpj']) ?></td>
            <td><span class="badge bg-info"><?= rtrim(rtrim(number_format((float)$c['irpj'], 2, ',', '.'), '0'), ',') ?>%</span></td>
            <td><span class="badge bg-info"><?= rtrim(rtrim(number_format((float)$c['csll'], 2, ',', '.'), '0'), ',') ?>%</span></td>
            <td><span class="badge bg-info"><?= rtrim(rtrim(number_format((float)$c['iss'], 2, ',', '.'), '0'), ',') ?>%</span></td>
            <td>
                <button class="btn btn-sm btn-outline-primary" onclick="editarReg(<?= htmlspecialchars(json_encode($c),ENT_QUOTES) ?>)"><i class="bi bi-pencil"></i></button>
                <form method="POST" class="d-inline" onsubmit="return confirm('Excluir empresa?')"><input type="hidden" name="action" value="excluir"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
            </td>
        </tr>
    <?php endforeach; else: ?>
        <tr><td colspan="6" class="text-center text-muted py-4">Nenhuma empresa cadastrada.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div></div></div>

<div class="modal fade" id="modal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form method="POST">
        <input type="hidden" name="action" id="fa" value="criar"><input type="hidden" name="id" id="fi">
        <div class="modal-header"><h5 class="modal-title fw-bold" id="mt">Nova Empresa</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label fw-semibold">Nome <span class="text-danger">*</span></label><input type="text" name="nome" id="f_nome" class="form-control" required></div>
            <div class="mb-3"><label class="form-label fw-semibold">CNPJ <span class="text-danger">*</span></label><input type="text" name="cnpj" id="f_cnpj" class="form-control" placeholder="00.000.000/0000-00" required></div>
            <div class="mb-3"><label class="form-label fw-semibold">IRPJ (%)</label><input type="number" step="0.01" min="0" name="irpj" id="f_irpj" class="form-control" value="0"></div>
            <div class="mb-3"><label class="form-label fw-semibold">CSLL (%)</label><input type="number" step="0.01" min="0" name="csll" id="f_csll" class="form-control" value="0"></div>
            <div class="mb-3"><label class="form-label fw-semibold">ISS (%)</label><input type="number" step="0.01" min="0" name="iss" id="f_iss" class="form-control" value="0"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salvar</button></div>
    </form>
</div></div></div>
<script>
function novoReg(){document.getElementById('mt').textContent='Nova Empresa';document.getElementById('fa').value='criar';document.getElementById('fi').value='';document.getElementById('f_nome').value='';document.getElementById('f_cnpj').value='';document.getElementById('f_irpj').value=0;document.getElementById('f_csll').value=0;document.getElementById('f_iss').value=0;}
function editarReg(d){document.getElementById('mt').textContent='Editar Empresa';document.getElementById('fa').value='editar';document.getElementById('fi').value=d.id;document.getElementById('f_nome').value=d.nome||'';document.getElementById('f_cnpj').value=d.cnpj||'';document.getElementById('f_irpj').value=d.irpj||0;document.getElementById('f_csll').value=d.csll||0;document.getElementById('f_iss').value=d.iss||0;new bootstrap.Modal(document.getElementById('modal')).show();}
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
