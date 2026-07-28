<?php
require_once __DIR__ . '/../../config.php';
requireComercial();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    try {
        $d = [$_POST['canal'], $_POST['faixa_faturamento'], (float)$_POST['desconto'], max(0, (float)($_POST['margem_negociacao'] ?? 0))];
        if ($a === 'criar') {
            db()->prepare('INSERT INTO canal_venda (canal,faixa_faturamento,desconto,margem_negociacao) VALUES (?,?,?,?)')->execute($d);
            flash('success', 'Canal criado!');
        } elseif ($a === 'editar') {
            $d[] = (int)$_POST['id'];
            db()->prepare('UPDATE canal_venda SET canal=?,faixa_faturamento=?,desconto=?,margem_negociacao=? WHERE id=?')->execute($d);
            flash('success', 'Canal atualizado!');
        } elseif ($a === 'excluir') {
            db()->prepare('DELETE FROM canal_venda WHERE id=?')->execute([(int)$_POST['id']]);
            flash('success', 'Canal excluído!');
        }
    } catch (Exception $e) { flash('danger', 'Erro: ' . $e->getMessage()); }
    header('Location: ' . BASE_URL . '/admin/cadastros/canal-venda.php'); exit;
}

$canais = db()->query('SELECT * FROM canal_venda ORDER BY canal')->fetchAll();
$pageTitle = 'Canal de Venda';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-diagram-3 me-2"></i>Canal de Venda</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal" onclick="novoReg()"><i class="bi bi-plus-lg me-1"></i>Novo Canal</button>
</div>
<div class="card shadow-sm border-0"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover mb-0">
    <thead class="table-light"><tr><th>Canal</th><th>Faixa de Faturamento</th><th>Desconto Máximo</th><th>Margem de Negociação</th><th>Ações</th></tr></thead>
    <tbody>
    <?php if ($canais): foreach ($canais as $c): ?>
        <tr>
            <td><strong><?= e($c['canal']) ?></strong></td>
            <td><?= e($c['faixa_faturamento']) ?></td>
            <td><span class="badge bg-info"><?= e($c['desconto']) ?>%</span></td>
            <td><span class="badge bg-warning text-dark"><?= rtrim(rtrim(number_format((float)($c['margem_negociacao'] ?? 0), 2, ',', '.'), '0'), ',') ?>%</span></td>
            <td>
                <button class="btn btn-sm btn-outline-primary" onclick="editarReg(<?= htmlspecialchars(json_encode($c),ENT_QUOTES) ?>)"><i class="bi bi-pencil"></i></button>
                <form method="POST" class="d-inline" onsubmit="return confirm('Excluir canal?')"><input type="hidden" name="action" value="excluir"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
            </td>
        </tr>
    <?php endforeach; else: ?>
        <tr><td colspan="5" class="text-center text-muted py-4">Nenhum canal cadastrado.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div></div></div>

<div class="modal fade" id="modal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form method="POST">
        <input type="hidden" name="action" id="fa" value="criar"><input type="hidden" name="id" id="fi">
        <div class="modal-header"><h5 class="modal-title fw-bold" id="mt">Novo Canal</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label fw-semibold">Canal <span class="text-danger">*</span></label><input type="text" name="canal" id="f_canal" class="form-control" required></div>
            <div class="mb-3"><label class="form-label fw-semibold">Faixa de Faturamento</label><input type="text" name="faixa_faturamento" id="f_faixa" class="form-control" placeholder="Ex: Até R$ 50.000"></div>
            <div class="mb-3"><label class="form-label fw-semibold">Desconto Máximo (%)</label><input type="number" step="0.01" name="desconto" id="f_desc" class="form-control" value="0"></div>
            <div class="mb-3"><label class="form-label fw-semibold">Margem de Negociação (%)</label><input type="number" step="0.01" min="0" name="margem_negociacao" id="f_margem" class="form-control" value="0"><div class="form-text">Teto do <strong>Desconto Comercial</strong> que poderá ser aplicado no pedido (soma aos descontos de canal e cliente).</div></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salvar</button></div>
    </form>
</div></div></div>
<script>
function novoReg(){document.getElementById('mt').textContent='Novo Canal';document.getElementById('fa').value='criar';document.getElementById('fi').value='';document.getElementById('f_canal').value='';document.getElementById('f_faixa').value='';document.getElementById('f_desc').value=0;document.getElementById('f_margem').value=0;}
function editarReg(d){document.getElementById('mt').textContent='Editar Canal';document.getElementById('fa').value='editar';document.getElementById('fi').value=d.id;document.getElementById('f_canal').value=d.canal||'';document.getElementById('f_faixa').value=d.faixa_faturamento||'';document.getElementById('f_desc').value=d.desconto||0;document.getElementById('f_margem').value=d.margem_negociacao||0;new bootstrap.Modal(document.getElementById('modal')).show();}
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
