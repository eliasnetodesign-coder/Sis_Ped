<?php
require_once __DIR__ . '/../../config.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    try {
        $d = [$_POST['codigo'], $_POST['nome'], $_POST['email'], $_POST['departamento'],
              $_POST['divisao_vendas'], $_POST['tipo_acesso'], $_POST['tipo_usuario'],
              $_POST['telefone'], $_POST['ramal'], $_POST['status']];
        if ($a === 'criar') {
            if (!$_POST['senha']) throw new Exception('Senha obrigatória.');
            $d[] = $_POST['senha'];
            db()->prepare('INSERT INTO usuarios (codigo,nome,email,departamento,divisao_vendas,tipo_acesso,tipo_usuario,telefone,ramal,status,senha) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute($d);
            flash('success', 'Usuário criado!');
        } elseif ($a === 'editar') {
            $d[] = (int)$_POST['id'];
            db()->prepare('UPDATE usuarios SET codigo=?,nome=?,email=?,departamento=?,divisao_vendas=?,tipo_acesso=?,tipo_usuario=?,telefone=?,ramal=?,status=? WHERE id=?')->execute($d);
            if ($_POST['senha']) {
                db()->prepare('UPDATE usuarios SET senha=? WHERE id=?')->execute([$_POST['senha'], (int)$_POST['id']]);
            }
            flash('success', 'Usuário atualizado!');
        } elseif ($a === 'excluir') {
            $uid = (int)$_POST['id'];
            if ($uid === (int)usuario()['id']) throw new Exception('Não é possível excluir o próprio usuário.');
            db()->prepare('DELETE FROM usuarios WHERE id=?')->execute([$uid]);
            flash('success', 'Usuário excluído!');
        }
    } catch (Exception $e) { flash('danger', 'Erro: ' . $e->getMessage()); }
    header('Location: ' . BASE_URL . '/admin/cadastros/usuarios.php'); exit;
}

$usuarios = db()->query('SELECT * FROM usuarios ORDER BY nome')->fetchAll();
$pageTitle = 'Cadastro de Usuários';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-person-badge me-2"></i>Cadastro de Usuários</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal" onclick="novoReg()"><i class="bi bi-plus-lg me-1"></i>Novo Usuário</button>
</div>
<div class="card shadow-sm border-0"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
    <thead class="table-light"><tr><th>Código</th><th>Nome</th><th>E-mail</th><th>Departamento</th><th>Tipo de Acesso</th><th>Telefone</th><th>Status</th><th>Ações</th></tr></thead>
    <tbody>
    <?php if ($usuarios): foreach ($usuarios as $u): ?>
        <tr>
            <td><?= e($u['codigo']) ?></td>
            <td><strong><?= e($u['nome']) ?></strong></td>
            <td><small><?= e($u['email']) ?></small></td>
            <td><?= e($u['departamento']) ?></td>
            <?php $badgeColor = $u['tipo_acesso']==='comercial'?'primary':($u['tipo_acesso']==='vendedor'?'success':'warning'); ?>
            <td><span class="badge bg-<?= $badgeColor ?>"><?= ucfirst($u['tipo_acesso']) ?></span></td>
            <td><?= e($u['telefone']) ?></td>
            <td><?= statusBadge($u['status']) ?></td>
            <td>
                <button class="btn btn-sm btn-outline-primary" onclick="editarReg(<?= htmlspecialchars(json_encode($u),ENT_QUOTES) ?>)"><i class="bi bi-pencil"></i></button>
                <form method="POST" class="d-inline" onsubmit="return confirm('Excluir usuário?')"><input type="hidden" name="action" value="excluir"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
            </td>
        </tr>
    <?php endforeach; else: ?>
        <tr><td colspan="8" class="text-center text-muted py-4">Nenhum usuário cadastrado.</td></tr>
    <?php endif; ?>
    </tbody>
</table></div></div></div>

<div class="modal fade" id="modal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
    <form method="POST">
        <input type="hidden" name="action" id="fa" value="criar"><input type="hidden" name="id" id="fi">
        <div class="modal-header"><h5 class="modal-title fw-bold" id="mt">Novo Usuário</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="row g-3">
                <div class="col-md-3"><label class="form-label fw-semibold">Código</label><input type="text" name="codigo" id="f_cod" class="form-control"></div>
                <div class="col-md-9"><label class="form-label fw-semibold">Nome <span class="text-danger">*</span></label><input type="text" name="nome" id="f_nome" class="form-control" required></div>
                <div class="col-md-6"><label class="form-label fw-semibold">E-mail <span class="text-danger">*</span></label><input type="email" name="email" id="f_email" class="form-control" required></div>
                <div class="col-md-6"><label class="form-label fw-semibold">Senha <small class="text-muted">(em branco para manter)</small></label><input type="text" name="senha" id="f_senha" class="form-control"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Departamento</label><input type="text" name="departamento" id="f_dep" class="form-control"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Divisão de Vendas</label><input type="text" name="divisao_vendas" id="f_div" class="form-control"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Tipo de Acesso <span class="text-danger">*</span></label>
                    <select name="tipo_acesso" id="f_tipo" class="form-select" required>
                        <option value="comercial">Comercial</option>
                        <option value="financeiro">Financeiro</option>
                        <option value="vendedor">Vendedor</option>
                    </select>
                </div>
                <div class="col-md-4"><label class="form-label fw-semibold">Tipo de Usuário</label><input type="text" name="tipo_usuario" id="f_tusr" class="form-control" placeholder="Ex: Gerente, Analista"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Telefone</label><input type="text" name="telefone" id="f_tel" class="form-control"></div>
                <div class="col-md-2"><label class="form-label fw-semibold">Ramal</label><input type="text" name="ramal" id="f_ramal" class="form-control"></div>
                <div class="col-md-2"><label class="form-label fw-semibold">Status</label><select name="status" id="f_status" class="form-select"><option value="ativo">Ativo</option><option value="inativo">Inativo</option></select></div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salvar</button></div>
    </form>
</div></div></div>
<script>
function novoReg(){document.getElementById('mt').textContent='Novo Usuário';document.getElementById('fa').value='criar';document.getElementById('fi').value='';['cod','nome','email','senha','dep','div','tusr','tel','ramal'].forEach(function(f){document.getElementById('f_'+f).value='';});document.getElementById('f_tipo').value='comercial';document.getElementById('f_status').value='ativo';}
function editarReg(d){document.getElementById('mt').textContent='Editar Usuário';document.getElementById('fa').value='editar';document.getElementById('fi').value=d.id;document.getElementById('f_cod').value=d.codigo||'';document.getElementById('f_nome').value=d.nome||'';document.getElementById('f_email').value=d.email||'';document.getElementById('f_senha').value='';document.getElementById('f_dep').value=d.departamento||'';document.getElementById('f_div').value=d.divisao_vendas||'';document.getElementById('f_tipo').value=d.tipo_acesso||'comercial';document.getElementById('f_tusr').value=d.tipo_usuario||'';document.getElementById('f_tel').value=d.telefone||'';document.getElementById('f_ramal').value=d.ramal||'';document.getElementById('f_status').value=d.status||'ativo';new bootstrap.Modal(document.getElementById('modal')).show();}
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
