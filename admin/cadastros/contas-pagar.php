<?php
require_once __DIR__ . '/../../config.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    try {
        $d = [$_POST['numero_documento'], $_POST['fornecedor_id'] ?: null, (float)$_POST['valor_pagar'],
              (float)$_POST['descontos'], (float)$_POST['juros'], $_POST['data_emissao'] ?: null,
              $_POST['data_vencimento'] ?: null, $_POST['data_pagamento'] ?: null, $_POST['situacao']];
        if ($a === 'criar') {
            db()->prepare('INSERT INTO contas_pagar (numero_documento,fornecedor_id,valor_pagar,descontos,juros,data_emissao,data_vencimento,data_pagamento,situacao) VALUES (?,?,?,?,?,?,?,?,?)')->execute($d);
            flash('success', 'Conta a pagar criada!');
        } elseif ($a === 'editar') {
            $d[] = (int)$_POST['id'];
            db()->prepare('UPDATE contas_pagar SET numero_documento=?,fornecedor_id=?,valor_pagar=?,descontos=?,juros=?,data_emissao=?,data_vencimento=?,data_pagamento=?,situacao=? WHERE id=?')->execute($d);
            flash('success', 'Conta atualizada!');
        } elseif ($a === 'excluir') {
            db()->prepare('DELETE FROM contas_pagar WHERE id=?')->execute([(int)$_POST['id']]);
            flash('success', 'Conta excluída!');
        }
    } catch (Exception $e) { flash('danger', 'Erro: ' . $e->getMessage()); }
    header('Location: ' . BASE_URL . '/admin/cadastros/contas-pagar.php'); exit;
}

$filtro = $_GET['situacao'] ?? '';
$where = $filtro ? 'WHERE cp.situacao = ?' : '';
$params = $filtro ? [$filtro] : [];
$contas = db()->prepare("SELECT cp.*, f.razao_social FROM contas_pagar cp LEFT JOIN fornecedores f ON f.id=cp.fornecedor_id $where ORDER BY cp.data_vencimento DESC");
$contas->execute($params);
$contas = $contas->fetchAll();

$fornecedores = db()->query('SELECT id, razao_social FROM fornecedores WHERE status="ativo" ORDER BY razao_social')->fetchAll();
$pageTitle = 'Contas a Pagar';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-arrow-up-circle me-2"></i>Contas a Pagar</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal" onclick="novoReg()"><i class="bi bi-plus-lg me-1"></i>Nova Conta</button>
</div>
<div class="mb-3 d-flex gap-2">
    <?php foreach ([''=>'Todos','aberto'=>'Aberto','pago'=>'Pago','vencido'=>'Vencido','cancelado'=>'Cancelado'] as $v=>$l): ?>
    <a href="?situacao=<?= $v ?>" class="btn btn-sm btn-<?= $filtro===$v?'primary':'outline-primary' ?>"><?= $l ?></a>
    <?php endforeach; ?>
</div>
<div class="card shadow-sm border-0"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
    <thead class="table-light"><tr><th>Documento</th><th>Fornecedor</th><th>Valor</th><th>Desconto</th><th>Juros</th><th>Emissão</th><th>Vencimento</th><th>Pagamento</th><th>Situação</th><th>Ações</th></tr></thead>
    <tbody>
    <?php if ($contas): foreach ($contas as $c): ?>
        <tr class="<?= $c['situacao']==='vencido'?'table-danger':'' ?>">
            <td><?= e($c['numero_documento']?:'—') ?></td>
            <td><?= e($c['razao_social']?:'—') ?></td>
            <td><?= moedaBR($c['valor_pagar']) ?></td>
            <td><?= moedaBR($c['descontos']) ?></td>
            <td><?= moedaBR($c['juros']) ?></td>
            <td><?= dataBR($c['data_emissao']) ?></td>
            <td><?= dataBR($c['data_vencimento']) ?></td>
            <td><?= dataBR($c['data_pagamento']) ?></td>
            <td><?= statusBadge($c['situacao']) ?></td>
            <td>
                <button class="btn btn-sm btn-outline-primary" onclick="editarReg(<?= htmlspecialchars(json_encode($c),ENT_QUOTES) ?>)"><i class="bi bi-pencil"></i></button>
                <form method="POST" class="d-inline" onsubmit="return confirm('Excluir?')"><input type="hidden" name="action" value="excluir"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
            </td>
        </tr>
    <?php endforeach; else: ?><tr><td colspan="10" class="text-center text-muted py-4">Nenhuma conta.</td></tr><?php endif; ?>
    </tbody>
</table></div></div></div>

<div class="modal fade" id="modal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
    <form method="POST">
        <input type="hidden" name="action" id="fa" value="criar"><input type="hidden" name="id" id="fi">
        <div class="modal-header"><h5 class="modal-title fw-bold" id="mt">Nova Conta a Pagar</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label fw-semibold">Nº Documento</label><input type="text" name="numero_documento" id="f_nd" class="form-control"></div>
                <div class="col-md-8"><label class="form-label fw-semibold">Fornecedor</label>
                    <select name="fornecedor_id" id="f_for" class="form-select"><option value="">Selecione...</option><?php foreach($fornecedores as $f):?><option value="<?=$f['id']?>"><?=e($f['razao_social'])?></option><?php endforeach;?></select>
                </div>
                <div class="col-md-4"><label class="form-label fw-semibold">Valor a Pagar</label><input type="number" step="0.01" name="valor_pagar" id="f_vp" class="form-control" value="0"></div>
                <div class="col-md-3"><label class="form-label fw-semibold">Descontos</label><input type="number" step="0.01" name="descontos" id="f_desc" class="form-control" value="0"></div>
                <div class="col-md-3"><label class="form-label fw-semibold">Juros</label><input type="number" step="0.01" name="juros" id="f_jur" class="form-control" value="0"></div>
                <div class="col-md-2"><label class="form-label fw-semibold">Situação</label>
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
function novoReg(){document.getElementById('mt').textContent='Nova Conta a Pagar';document.getElementById('fa').value='criar';document.getElementById('fi').value='';['nd','de','dv','dp'].forEach(function(f){document.getElementById('f_'+f).value='';});document.getElementById('f_for').value='';document.getElementById('f_vp').value=0;document.getElementById('f_desc').value=0;document.getElementById('f_jur').value=0;document.getElementById('f_sit').value='aberto';}
function editarReg(d){document.getElementById('mt').textContent='Editar Conta';document.getElementById('fa').value='editar';document.getElementById('fi').value=d.id;document.getElementById('f_nd').value=d.numero_documento||'';document.getElementById('f_for').value=d.fornecedor_id||'';document.getElementById('f_vp').value=d.valor_pagar||0;document.getElementById('f_desc').value=d.descontos||0;document.getElementById('f_jur').value=d.juros||0;document.getElementById('f_sit').value=d.situacao||'aberto';document.getElementById('f_de').value=d.data_emissao||'';document.getElementById('f_dv').value=d.data_vencimento||'';document.getElementById('f_dp').value=d.data_pagamento||'';new bootstrap.Modal(document.getElementById('modal')).show();}
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
