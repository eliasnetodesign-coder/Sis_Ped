<?php
require_once __DIR__ . '/../../config.php';
$u = usuario();
if (!$u || !in_array($u['tipo'], ['comercial','financeiro','tecnologia da informacao'])) { header('Location: ' . BASE_URL . '/login.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    try {
        $d = [$_POST['numero_ordem'], $_POST['descricao'], (float)$_POST['valor'], $_POST['data_ordem']?:null, (float)$_POST['retorno_esperado'], $_POST['status']];
        if ($a === 'criar') {
            db()->prepare('INSERT INTO ordens_investimento (numero_ordem,descricao,valor,data_ordem,retorno_esperado,status) VALUES (?,?,?,?,?,?)')->execute($d);
            flash('success', 'Ordem de investimento criada!');
        } elseif ($a === 'editar') {
            $d[] = (int)$_POST['id'];
            db()->prepare('UPDATE ordens_investimento SET numero_ordem=?,descricao=?,valor=?,data_ordem=?,retorno_esperado=?,status=? WHERE id=?')->execute($d);
            flash('success', 'Ordem atualizada!');
        } elseif ($a === 'excluir') {
            db()->prepare('DELETE FROM ordens_investimento WHERE id=?')->execute([(int)$_POST['id']]);
            flash('success', 'Ordem excluída!');
        }
    } catch (Exception $e) { flash('danger', 'Erro: ' . $e->getMessage()); }
    header('Location: ' . BASE_URL . '/admin/financeiro/ordens-investimento.php'); exit;
}

$filtro = $_GET['status'] ?? '';
$where = $filtro ? 'WHERE status=?' : '';
$params = $filtro ? [$filtro] : [];
$ordens = db()->prepare("SELECT * FROM ordens_investimento $where ORDER BY data_ordem DESC");
$ordens->execute($params);
$ordens = $ordens->fetchAll();

$total_invest  = db()->query('SELECT COALESCE(SUM(valor),0) FROM ordens_investimento WHERE status="aprovado"')->fetchColumn();
$total_retorno = db()->query('SELECT COALESCE(SUM(retorno_esperado),0) FROM ordens_investimento WHERE status="aprovado"')->fetchColumn();

$pageTitle = 'Ordens de Investimento';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-graph-up-arrow me-2"></i>Ordens de Investimento</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal" onclick="novoReg()"><i class="bi bi-plus-lg me-1"></i>Nova Ordem</button>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card shadow-sm border-0 border-start border-4 border-primary"><div class="card-body"><div class="text-muted small fw-semibold text-uppercase">Total Investido (Aprovado)</div><div class="fw-bold text-primary" style="font-size:1.4rem"><?= moedaBR($total_invest) ?></div></div></div></div>
    <div class="col-md-4"><div class="card shadow-sm border-0 border-start border-4 border-success"><div class="card-body"><div class="text-muted small fw-semibold text-uppercase">Retorno Esperado</div><div class="fw-bold text-success" style="font-size:1.4rem"><?= moedaBR($total_retorno) ?></div></div></div></div>
    <div class="col-md-4"><div class="card shadow-sm border-0 border-start border-4 border-info"><div class="card-body"><div class="text-muted small fw-semibold text-uppercase">ROI Esperado</div><div class="fw-bold text-info" style="font-size:1.4rem"><?= $total_invest>0?round(($total_retorno/$total_invest-1)*100,1).'%':'—' ?></div></div></div></div>
</div>

<div class="mb-3 d-flex gap-2">
    <?php foreach ([''=>'Todos','pendente'=>'Pendente','aprovado'=>'Aprovado','cancelado'=>'Cancelado'] as $v=>$l): ?>
    <a href="?status=<?= $v ?>" class="btn btn-sm btn-<?= $filtro===$v?'primary':'outline-primary' ?>"><?= $l ?></a>
    <?php endforeach; ?>
</div>
<div class="card shadow-sm border-0"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
    <thead class="table-light"><tr><th>Número</th><th>Descrição</th><th>Valor</th><th>Retorno Esperado</th><th>Data</th><th>Status</th><th>Ações</th></tr></thead>
    <tbody>
    <?php if ($ordens): foreach ($ordens as $o): ?>
        <tr>
            <td><strong><?= e($o['numero_ordem']) ?></strong></td>
            <td><?= e($o['descricao']) ?></td>
            <td><?= moedaBR($o['valor']) ?></td>
            <td class="text-success fw-semibold"><?= moedaBR($o['retorno_esperado']) ?></td>
            <td><?= dataBR($o['data_ordem']) ?></td>
            <td><?= statusBadge($o['status']) ?></td>
            <td>
                <button class="btn btn-sm btn-outline-primary" onclick="editarReg(<?= htmlspecialchars(json_encode($o),ENT_QUOTES) ?>)"><i class="bi bi-pencil"></i></button>
                <form method="POST" class="d-inline" onsubmit="return confirm('Excluir?')"><input type="hidden" name="action" value="excluir"><input type="hidden" name="id" value="<?= $o['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
            </td>
        </tr>
    <?php endforeach; else: ?><tr><td colspan="7" class="text-center text-muted py-4">Nenhuma ordem de investimento.</td></tr><?php endif; ?>
    </tbody>
</table></div></div></div>

<div class="modal fade" id="modal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form method="POST">
        <input type="hidden" name="action" id="fa" value="criar"><input type="hidden" name="id" id="fi">
        <div class="modal-header"><h5 class="modal-title fw-bold" id="mt">Nova Ordem de Investimento</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label fw-semibold">Número</label><input type="text" name="numero_ordem" id="f_num" class="form-control"></div>
                <div class="col-md-8"><label class="form-label fw-semibold">Descrição</label><input type="text" name="descricao" id="f_desc" class="form-control"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Valor do Investimento</label><input type="number" step="0.01" name="valor" id="f_val" class="form-control" value="0"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Retorno Esperado</label><input type="number" step="0.01" name="retorno_esperado" id="f_ret" class="form-control" value="0"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Data</label><input type="date" name="data_ordem" id="f_dt" class="form-control"></div>
                <div class="col-md-12"><label class="form-label fw-semibold">Status</label><select name="status" id="f_status" class="form-select"><option value="pendente">Pendente</option><option value="aprovado">Aprovado</option><option value="cancelado">Cancelado</option></select></div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salvar</button></div>
    </form>
</div></div></div>
<script>
function novoReg(){document.getElementById('mt').textContent='Nova Ordem';document.getElementById('fa').value='criar';document.getElementById('fi').value='';['num','desc','dt'].forEach(function(f){document.getElementById('f_'+f).value='';});document.getElementById('f_val').value=0;document.getElementById('f_ret').value=0;document.getElementById('f_status').value='pendente';}
function editarReg(d){document.getElementById('mt').textContent='Editar Ordem';document.getElementById('fa').value='editar';document.getElementById('fi').value=d.id;document.getElementById('f_num').value=d.numero_ordem||'';document.getElementById('f_desc').value=d.descricao||'';document.getElementById('f_val').value=d.valor||0;document.getElementById('f_ret').value=d.retorno_esperado||0;document.getElementById('f_dt').value=d.data_ordem||'';document.getElementById('f_status').value=d.status||'pendente';new bootstrap.Modal(document.getElementById('modal')).show();}
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
