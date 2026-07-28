<?php
require_once __DIR__ . '/../../config.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    try {
        $d = [$_POST['numero_documento'], $_POST['cliente_id'] ?: null, (float)$_POST['valor_receber'],
              (float)$_POST['descontos'], $_POST['data_emissao'] ?: null, $_POST['data_vencimento'] ?: null,
              $_POST['data_pagamento'] ?: null, $_POST['situacao']];
        if ($a === 'criar') {
            db()->prepare('INSERT INTO contas_receber (numero_documento,cliente_id,valor_receber,descontos,data_emissao,data_vencimento,data_pagamento,situacao) VALUES (?,?,?,?,?,?,?,?)')->execute($d);
            flash('success', 'Título criado!');
        } elseif ($a === 'editar') {
            $d[] = (int)$_POST['id'];
            db()->prepare('UPDATE contas_receber SET numero_documento=?,cliente_id=?,valor_receber=?,descontos=?,data_emissao=?,data_vencimento=?,data_pagamento=?,situacao=? WHERE id=?')->execute($d);
            flash('success', 'Título atualizado!');
        } elseif ($a === 'excluir') {
            db()->prepare('DELETE FROM contas_receber WHERE id=?')->execute([(int)$_POST['id']]);
            flash('success', 'Título excluído!');
        }
    } catch (Exception $e) { flash('danger', 'Erro: ' . $e->getMessage()); }
    header('Location: ' . BASE_URL . '/admin/cadastros/contas-receber.php'); exit;
}

$filtro = $_GET['situacao'] ?? '';
$where = $filtro ? 'WHERE cr.situacao = ?' : '';
$params = $filtro ? [$filtro] : [];
$titulos = db()->prepare("SELECT cr.*, c.razao_social FROM contas_receber cr LEFT JOIN clientes c ON c.id=cr.cliente_id $where ORDER BY cr.data_vencimento DESC");
$titulos->execute($params);
$titulos = $titulos->fetchAll();

$clientes = db()->query('SELECT id, razao_social FROM clientes WHERE status="ativo" ORDER BY razao_social')->fetchAll();
$pageTitle = 'Contas a Receber';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-arrow-down-circle me-2"></i>Contas a Receber</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal" onclick="novoReg()"><i class="bi bi-plus-lg me-1"></i>Novo Título</button>
</div>
<div class="mb-3 d-flex gap-2">
    <?php foreach ([''=>'Todos','aberto'=>'Aberto','pago'=>'Pago','vencido'=>'Vencido','cancelado'=>'Cancelado'] as $v=>$l): ?>
    <a href="?situacao=<?= $v ?>" class="btn btn-sm btn-<?= $filtro===$v?'primary':'outline-primary' ?>"><?= $l ?></a>
    <?php endforeach; ?>
</div>
<div class="card shadow-sm border-0"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
    <thead class="table-light"><tr><th>Documento</th><th>Cliente</th><th>Valor</th><th>Desconto</th><th>Líquido</th><th>Emissão</th><th>Vencimento</th><th>Pagamento</th><th>Situação</th><th>Ações</th></tr></thead>
    <tbody>
    <?php if ($titulos): foreach ($titulos as $t): ?>
        <tr class="<?= $t['situacao']==='vencido'?'table-danger':'' ?>">
            <td><?= e($t['numero_documento']?:'—') ?></td>
            <td><?= e($t['razao_social']?:'—') ?></td>
            <td><?= moedaBR($t['valor_receber']) ?></td>
            <td><?= moedaBR($t['descontos']) ?></td>
            <td class="fw-semibold"><?= moedaBR($t['valor_receber']-$t['descontos']) ?></td>
            <td><?= dataBR($t['data_emissao']) ?></td>
            <td><?= dataBR($t['data_vencimento']) ?></td>
            <td><?= dataBR($t['data_pagamento']) ?></td>
            <td><?= statusBadge($t['situacao']) ?></td>
            <td>
                <button class="btn btn-sm btn-outline-primary" onclick="editarReg(<?= htmlspecialchars(json_encode($t),ENT_QUOTES) ?>)"><i class="bi bi-pencil"></i></button>
                <form method="POST" class="d-inline" onsubmit="return confirm('Excluir?')"><input type="hidden" name="action" value="excluir"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
            </td>
        </tr>
    <?php endforeach; else: ?><tr><td colspan="10" class="text-center text-muted py-4">Nenhum título.</td></tr><?php endif; ?>
    </tbody>
</table></div></div></div>

<div class="modal fade" id="modal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
    <form method="POST">
        <input type="hidden" name="action" id="fa" value="criar"><input type="hidden" name="id" id="fi">
        <div class="modal-header"><h5 class="modal-title fw-bold" id="mt">Novo Título</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label fw-semibold">Nº Documento</label><input type="text" name="numero_documento" id="f_nd" class="form-control"></div>
                <div class="col-md-8"><label class="form-label fw-semibold">Cliente</label>
                    <select name="cliente_id" id="f_cli" class="form-select"><option value="">Selecione...</option><?php foreach($clientes as $c):?><option value="<?=$c['id']?>"><?=e($c['razao_social'])?></option><?php endforeach;?></select>
                </div>
                <div class="col-md-4"><label class="form-label fw-semibold">Valor a Receber</label><input type="number" step="0.01" name="valor_receber" id="f_vr" class="form-control" value="0"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Descontos</label><input type="number" step="0.01" name="descontos" id="f_desc" class="form-control" value="0"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Situação</label>
                    <select name="situacao" id="f_sit" class="form-select"><option value="aberto">Aberto</option><option value="pago">Pago</option><option value="vencido">Vencido</option><option value="cancelado">Cancelado</option></select>
                </div>
                <div class="col-md-4"><label class="form-label fw-semibold">Data Emissão</label><input type="date" name="data_emissao" id="f_de" class="form-control"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Data Vencimento</label><input type="date" name="data_vencimento" id="f_dv" class="form-control"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Data Pagamento</label><input type="date" name="data_pagamento" id="f_dp" class="form-control"></div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salvar</button></div>
    </form>
</div></div></div>
<script>
function novoReg(){document.getElementById('mt').textContent='Novo Título';document.getElementById('fa').value='criar';document.getElementById('fi').value='';['nd','de','dv','dp'].forEach(function(f){document.getElementById('f_'+f).value='';});document.getElementById('f_cli').value='';document.getElementById('f_vr').value=0;document.getElementById('f_desc').value=0;document.getElementById('f_sit').value='aberto';}
function editarReg(d){document.getElementById('mt').textContent='Editar Título';document.getElementById('fa').value='editar';document.getElementById('fi').value=d.id;document.getElementById('f_nd').value=d.numero_documento||'';document.getElementById('f_cli').value=d.cliente_id||'';document.getElementById('f_vr').value=d.valor_receber||0;document.getElementById('f_desc').value=d.descontos||0;document.getElementById('f_sit').value=d.situacao||'aberto';document.getElementById('f_de').value=d.data_emissao||'';document.getElementById('f_dv').value=d.data_vencimento||'';document.getElementById('f_dp').value=d.data_pagamento||'';new bootstrap.Modal(document.getElementById('modal')).show();}
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
