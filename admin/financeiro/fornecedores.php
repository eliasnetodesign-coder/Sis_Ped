<?php
require_once __DIR__ . '/../../config.php';
$u = usuario();
if (!$u || !in_array($u['tipo'], ['comercial','financeiro'])) { header('Location: ' . BASE_URL . '/login.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    try {
        $d = [$_POST['codigo'], $_POST['razao_social'], $_POST['cnpj'], $_POST['email'], $_POST['telefone'], $_POST['cidade'], $_POST['estado'], $_POST['status']];
        if ($a === 'criar') {
            db()->prepare('INSERT INTO fornecedores (codigo,razao_social,cnpj,email,telefone,cidade,estado,status) VALUES (?,?,?,?,?,?,?,?)')->execute($d);
            flash('success', 'Fornecedor criado!');
        } elseif ($a === 'editar') {
            $d[] = (int)$_POST['id'];
            db()->prepare('UPDATE fornecedores SET codigo=?,razao_social=?,cnpj=?,email=?,telefone=?,cidade=?,estado=?,status=? WHERE id=?')->execute($d);
            flash('success', 'Fornecedor atualizado!');
        } elseif ($a === 'excluir') {
            db()->prepare('DELETE FROM fornecedores WHERE id=?')->execute([(int)$_POST['id']]);
            flash('success', 'Fornecedor excluído!');
        }
    } catch (Exception $e) { flash('danger', 'Erro: ' . $e->getMessage()); }
    header('Location: ' . BASE_URL . '/admin/financeiro/fornecedores.php'); exit;
}

$busca = $_GET['q'] ?? '';
$where = $busca ? 'WHERE razao_social LIKE ? OR cnpj LIKE ?' : '';
$params = $busca ? ["%$busca%","%$busca%"] : [];
$fornecedores = db()->prepare("SELECT * FROM fornecedores $where ORDER BY razao_social");
$fornecedores->execute($params);
$fornecedores = $fornecedores->fetchAll();

$pageTitle = 'Cadastro de Fornecedores';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-building me-2"></i>Cadastro de Fornecedores</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal" onclick="novoReg()"><i class="bi bi-plus-lg me-1"></i>Novo Fornecedor</button>
</div>
<form class="mb-3 d-flex gap-2">
    <input type="text" name="q" class="form-control" placeholder="Buscar..." value="<?= e($busca) ?>">
    <button class="btn btn-outline-primary">Buscar</button>
    <?php if ($busca): ?><a href="?" class="btn btn-outline-secondary">Limpar</a><?php endif; ?>
</form>
<div class="card shadow-sm border-0"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
    <thead class="table-light"><tr><th>Código</th><th>Razão Social</th><th>CNPJ</th><th>Cidade/UF</th><th>E-mail</th><th>Telefone</th><th>Status</th><th>Ações</th></tr></thead>
    <tbody>
    <?php if ($fornecedores): foreach ($fornecedores as $f): ?>
        <tr>
            <td><?= e($f['codigo']) ?></td>
            <td><strong><?= e($f['razao_social']) ?></strong></td>
            <td><?= e($f['cnpj']) ?></td>
            <td><?= e($f['cidade']) ?><?= $f['estado']?'/'.e($f['estado']):'' ?></td>
            <td><small><?= e($f['email']) ?></small></td>
            <td><?= e($f['telefone']) ?></td>
            <td><?= statusBadge($f['status']) ?></td>
            <td>
                <button class="btn btn-sm btn-outline-primary" onclick="editarReg(<?= htmlspecialchars(json_encode($f),ENT_QUOTES) ?>)"><i class="bi bi-pencil"></i></button>
                <form method="POST" class="d-inline" onsubmit="return confirm('Excluir?')"><input type="hidden" name="action" value="excluir"><input type="hidden" name="id" value="<?= $f['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
            </td>
        </tr>
    <?php endforeach; else: ?><tr><td colspan="8" class="text-center text-muted py-4">Nenhum fornecedor.</td></tr><?php endif; ?>
    </tbody>
</table></div></div></div>

<div class="modal fade" id="modal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
    <form method="POST">
        <input type="hidden" name="action" id="fa" value="criar"><input type="hidden" name="id" id="fi">
        <div class="modal-header"><h5 class="modal-title fw-bold" id="mt">Novo Fornecedor</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="row g-3">
                <div class="col-md-3"><label class="form-label fw-semibold">Código</label><input type="text" name="codigo" id="f_cod" class="form-control"></div>
                <div class="col-md-9"><label class="form-label fw-semibold">Razão Social <span class="text-danger">*</span></label><input type="text" name="razao_social" id="f_rs" class="form-control" required></div>
                <div class="col-md-4"><label class="form-label fw-semibold">CNPJ</label><input type="text" name="cnpj" id="f_cnpj" class="form-control"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">E-mail</label><input type="email" name="email" id="f_email" class="form-control"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Telefone</label><input type="text" name="telefone" id="f_tel" class="form-control"></div>
                <div class="col-md-5"><label class="form-label fw-semibold">Cidade</label><input type="text" name="cidade" id="f_cidade" class="form-control"></div>
                <div class="col-md-2"><label class="form-label fw-semibold">UF</label><input type="text" name="estado" id="f_uf" class="form-control" maxlength="2"></div>
                <div class="col-md-3"><label class="form-label fw-semibold">Status</label><select name="status" id="f_status" class="form-select"><option value="ativo">Ativo</option><option value="inativo">Inativo</option></select></div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salvar</button></div>
    </form>
</div></div></div>
<script>
function novoReg(){document.getElementById('mt').textContent='Novo Fornecedor';document.getElementById('fa').value='criar';document.getElementById('fi').value='';['cod','rs','cnpj','email','tel','cidade','uf'].forEach(function(f){document.getElementById('f_'+f).value='';});document.getElementById('f_status').value='ativo';}
function editarReg(d){document.getElementById('mt').textContent='Editar Fornecedor';document.getElementById('fa').value='editar';document.getElementById('fi').value=d.id;document.getElementById('f_cod').value=d.codigo||'';document.getElementById('f_rs').value=d.razao_social||'';document.getElementById('f_cnpj').value=d.cnpj||'';document.getElementById('f_email').value=d.email||'';document.getElementById('f_tel').value=d.telefone||'';document.getElementById('f_cidade').value=d.cidade||'';document.getElementById('f_uf').value=d.estado||'';document.getElementById('f_status').value=d.status||'ativo';new bootstrap.Modal(document.getElementById('modal')).show();}
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
