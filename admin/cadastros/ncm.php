<?php
require_once __DIR__ . '/../../config.php';
requireComercial();

// Lista fixa de estados (mesma ordem/grafia da planilha)
$ESTADOS = ['Acre','Alagoas','Amazonas','Amapa','Bahia','Ceará','Distrito Federal','Espirito Santo','Goias','Maranhão','Minas Gerais','Mato Grosso Sul','Mato Grosso','Para','Paraíba','Pernanbuco','Piauí','Paraná','Rio de Janeiro','Rio Grande Norte','Rondônia','Roraima','Rio Grande Sul','Santa Catarina','Sergipe','São Paulo','Tocantins'];

// Converte entrada do form em decimal ou null
function nv($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    return (float)str_replace(',', '.', $v);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    try {
        if ($a === 'excluir') {
            db()->prepare('DELETE FROM ncm WHERE id=?')->execute([(int)$_POST['id']]);
            flash('success', 'NCM excluído!');
        } elseif ($a === 'criar' || $a === 'editar') {
            $dados = [
                trim($_POST['nome_categoria'] ?? ''),
                trim($_POST['ncm'] ?? ''),
                trim($_POST['cest'] ?? ''),
                nv($_POST['ii'] ?? ''),
                nv($_POST['ipi'] ?? ''),
                nv($_POST['pis'] ?? ''),
                nv($_POST['cofins'] ?? ''),
            ];
            db()->beginTransaction();
            if ($a === 'criar') {
                db()->prepare('INSERT INTO ncm (nome_categoria,ncm,cest,ii,ipi,pis,cofins) VALUES (?,?,?,?,?,?,?)')->execute($dados);
                $ncmId = (int)db()->lastInsertId();
            } else {
                $ncmId = (int)$_POST['id'];
                $dados[] = $ncmId;
                db()->prepare('UPDATE ncm SET nome_categoria=?,ncm=?,cest=?,ii=?,ipi=?,pis=?,cofins=? WHERE id=?')->execute($dados);
                db()->prepare('DELETE FROM ncm_estados WHERE ncm_id=?')->execute([$ncmId]);
            }
            $insE = db()->prepare('INSERT INTO ncm_estados (ncm_id,estado,indice_lp,red_base_lp,indice_sp_ls,red_base_sp,fecep,icms_local,icms_interestadual) VALUES (?,?,?,?,?,?,?,?,?)');
            foreach (($_POST['estados'] ?? []) as $e) {
                $est = trim($e['estado'] ?? '');
                if ($est === '') continue;
                $insE->execute([$ncmId, $est, nv($e['indice_lp'] ?? ''), nv($e['red_base_lp'] ?? ''), nv($e['indice_sp_ls'] ?? ''), nv($e['red_base_sp'] ?? ''), nv($e['fecep'] ?? ''), nv($e['icms_local'] ?? ''), nv($e['icms_interestadual'] ?? '')]);
            }
            db()->commit();
            flash('success', $a === 'criar' ? 'NCM cadastrado!' : 'NCM atualizado!');
        }
    } catch (Exception $e) {
        if (db()->inTransaction()) db()->rollBack();
        flash('danger', 'Erro: ' . $e->getMessage());
    }
    header('Location: ' . BASE_URL . '/admin/cadastros/ncm.php'); exit;
}

$ncms = db()->query('SELECT * FROM ncm ORDER BY nome_categoria')->fetchAll();

// Carrega estados agrupados por ncm_id
$estadosByNcm = [];
foreach (db()->query('SELECT * FROM ncm_estados ORDER BY id')->fetchAll() as $e) {
    $estadosByNcm[$e['ncm_id']][] = $e;
}

// Mapa para o JS (edição) — chaveado por ncm_id e por nome do estado
$estadosJsMap = [];
foreach ($estadosByNcm as $nid => $lista) {
    foreach ($lista as $e) {
        $estadosJsMap[$nid][$e['estado']] = [
            'indice_lp' => $e['indice_lp'], 'red_base_lp' => $e['red_base_lp'],
            'indice_sp_ls' => $e['indice_sp_ls'], 'red_base_sp' => $e['red_base_sp'],
            'fecep' => $e['fecep'], 'icms_local' => $e['icms_local'], 'icms_interestadual' => $e['icms_interestadual'],
        ];
    }
}

// Formata valor percentual para exibição
function pct($v) {
    if ($v === null || $v === '') return '<span class="text-muted">-</span>';
    $s = rtrim(rtrim(number_format((float)$v, 4, ',', '.'), '0'), ',');
    return $s . '%';
}

$pageTitle = 'Cadastro de NCM';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-upc-scan me-2"></i>NCM Cadastrados</h4>
    <button class="btn btn-primary" onclick="novoReg()"><i class="bi bi-plus-lg me-1"></i>Novo NCM</button>
</div>

<?php if (!$ncms): ?>
<div class="card shadow-sm border-0"><div class="card-body text-center text-muted py-5">Nenhum NCM cadastrado.</div></div>
<?php else: ?>
<div class="accordion" id="ncmAccordion">
<?php foreach ($ncms as $i => $n):
    $estados = $estadosByNcm[$n['id']] ?? [];
?>
    <div class="accordion-item border-0 shadow-sm mb-3">
        <h2 class="accordion-header">
            <button class="accordion-button <?= $i === 0 ? '' : 'collapsed' ?> bg-white" type="button"
                    data-bs-toggle="collapse" data-bs-target="#col<?= $n['id'] ?>">
                <div class="d-flex flex-wrap align-items-center gap-2 w-100 pe-3">
                    <span class="fw-bold text-primary"><?= e($n['nome_categoria']) ?: '—' ?></span>
                    <span class="badge bg-secondary">NCM <?= e($n['ncm']) ?></span>
                    <?php if ($n['cest']): ?><span class="badge bg-light text-dark border">CEST <?= e($n['cest']) ?></span><?php endif; ?>
                    <span class="ms-auto small text-muted d-none d-md-inline">
                        II <?= pct($n['ii']) ?> &nbsp;·&nbsp; IPI <?= pct($n['ipi']) ?> &nbsp;·&nbsp; PIS <?= pct($n['pis']) ?> &nbsp;·&nbsp; COFINS <?= pct($n['cofins']) ?>
                    </span>
                </div>
            </button>
        </h2>
        <div id="col<?= $n['id'] ?>" class="accordion-collapse collapse <?= $i === 0 ? 'show' : '' ?>" data-bs-parent="#ncmAccordion">
            <div class="accordion-body">
                <!-- Impostos nacionais -->
                <div class="row g-2 mb-3">
                    <div class="col-6 col-md-3"><div class="border rounded p-2 text-center"><div class="small text-muted">II</div><div class="fw-semibold"><?= pct($n['ii']) ?></div></div></div>
                    <div class="col-6 col-md-3"><div class="border rounded p-2 text-center"><div class="small text-muted">IPI</div><div class="fw-semibold"><?= pct($n['ipi']) ?></div></div></div>
                    <div class="col-6 col-md-3"><div class="border rounded p-2 text-center"><div class="small text-muted">PIS</div><div class="fw-semibold"><?= pct($n['pis']) ?></div></div></div>
                    <div class="col-6 col-md-3"><div class="border rounded p-2 text-center"><div class="small text-muted">COFINS</div><div class="fw-semibold"><?= pct($n['cofins']) ?></div></div></div>
                </div>
                <!-- Tabela de estados -->
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0" style="font-size:.82rem">
                        <thead class="table-light">
                            <tr>
                                <th>Estado</th>
                                <th class="text-end">Índice LP</th>
                                <th class="text-end">Red. Base LP</th>
                                <th class="text-end">Índice SP/LS</th>
                                <th class="text-end">Red. Base SP</th>
                                <th class="text-end">FECEP</th>
                                <th class="text-end">ICMS Local</th>
                                <th class="text-end">ICMS Interest.</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($estados as $e): ?>
                            <tr>
                                <td class="fw-semibold"><?= e($e['estado']) ?></td>
                                <td class="text-end"><?= pct($e['indice_lp']) ?></td>
                                <td class="text-end"><?= pct($e['red_base_lp']) ?></td>
                                <td class="text-end"><?= pct($e['indice_sp_ls']) ?></td>
                                <td class="text-end"><?= pct($e['red_base_sp']) ?></td>
                                <td class="text-end"><?= pct($e['fecep']) ?></td>
                                <td class="text-end fw-semibold"><?= pct($e['icms_local']) ?></td>
                                <td class="text-end"><?= pct($e['icms_interestadual']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$estados): ?>
                            <tr><td colspan="8" class="text-center text-muted py-3">Sem estados cadastrados.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <!-- Ações -->
                <div class="d-flex gap-2 mt-3">
                    <button class="btn btn-sm btn-outline-primary" onclick='editarReg(<?= htmlspecialchars(json_encode($n), ENT_QUOTES) ?>)'>
                        <i class="bi bi-pencil me-1"></i>Editar
                    </button>
                    <form method="POST" onsubmit="return confirm('Excluir este NCM e todos os seus estados?')">
                        <input type="hidden" name="action" value="excluir">
                        <input type="hidden" name="id" value="<?= $n['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Excluir</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Modal Cadastro/Edição -->
<div class="modal fade" id="modal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" id="fa" value="criar">
        <input type="hidden" name="id" id="fi">
        <div class="modal-header">
            <h5 class="modal-title fw-bold" id="mt">Novo NCM</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <!-- Dados do produto -->
            <div class="row g-3 mb-3">
                <div class="col-md-12"><label class="form-label fw-semibold">Produto / Categoria</label><input type="text" name="nome_categoria" id="f_cat" class="form-control"></div>
                <div class="col-md-3"><label class="form-label fw-semibold">NCM <span class="text-danger">*</span></label><input type="text" name="ncm" id="f_ncm" class="form-control" required></div>
                <div class="col-md-3"><label class="form-label fw-semibold">CEST</label><input type="text" name="cest" id="f_cest" class="form-control"></div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-6 col-md-3"><label class="form-label fw-semibold">II (%)</label><input type="number" step="0.0001" name="ii" id="f_ii" class="form-control"></div>
                <div class="col-6 col-md-3"><label class="form-label fw-semibold">IPI (%)</label><input type="number" step="0.0001" name="ipi" id="f_ipi" class="form-control"></div>
                <div class="col-6 col-md-3"><label class="form-label fw-semibold">PIS (%)</label><input type="number" step="0.0001" name="pis" id="f_pis" class="form-control"></div>
                <div class="col-6 col-md-3"><label class="form-label fw-semibold">COFINS (%)</label><input type="number" step="0.0001" name="cofins" id="f_cofins" class="form-control"></div>
            </div>

            <h6 class="fw-bold border-top pt-3"><i class="bi bi-geo-alt me-1"></i>ICMS por Estado <small class="text-muted fw-normal">(valores em %)</small></h6>
            <div class="table-responsive" style="max-height:340px">
                <table class="table table-sm table-bordered align-middle mb-0" style="font-size:.8rem">
                    <thead class="table-light position-sticky top-0">
                        <tr>
                            <th style="min-width:130px">Estado</th>
                            <th>Índice LP</th><th>Red. Base LP</th><th>Índice SP/LS</th>
                            <th>Red. Base SP</th><th>FECEP</th><th>ICMS Local</th><th>ICMS Interest.</th>
                        </tr>
                    </thead>
                    <tbody id="statesTbody">
                    <?php foreach ($ESTADOS as $idx => $uf): ?>
                        <tr data-estado="<?= e($uf) ?>">
                            <td class="fw-semibold">
                                <?= e($uf) ?>
                                <input type="hidden" name="estados[<?= $idx ?>][estado]" value="<?= e($uf) ?>">
                            </td>
                            <?php foreach (['indice_lp','red_base_lp','indice_sp_ls','red_base_sp','fecep','icms_local','icms_interestadual'] as $f): ?>
                            <td><input type="number" step="0.0001" data-f="<?= $f ?>" name="estados[<?= $idx ?>][<?= $f ?>]" class="form-control form-control-sm" style="min-width:80px"></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
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
var _estadosMap = <?= json_encode($estadosJsMap) ?>;
var _modal = null;
function getModal(){ if(!_modal) _modal = new bootstrap.Modal(document.getElementById('modal')); return _modal; }

function limparEstados(){
    document.querySelectorAll('#statesTbody tr').forEach(function(tr){
        tr.querySelectorAll('input[data-f]').forEach(function(i){ i.value=''; });
    });
}

function novoReg(){
    document.getElementById('mt').textContent='Novo NCM';
    document.getElementById('fa').value='criar';
    document.getElementById('fi').value='';
    ['cat','ncm','cest','ii','ipi','pis','cofins'].forEach(function(f){ document.getElementById('f_'+f).value=''; });
    limparEstados();
    getModal().show();
}

function editarReg(d){
    document.getElementById('mt').textContent='Editar NCM';
    document.getElementById('fa').value='editar';
    document.getElementById('fi').value=d.id;
    document.getElementById('f_cat').value=d.nome_categoria||'';
    document.getElementById('f_ncm').value=d.ncm||'';
    document.getElementById('f_cest').value=d.cest||'';
    document.getElementById('f_ii').value=(d.ii!=null?d.ii:'');
    document.getElementById('f_ipi').value=(d.ipi!=null?d.ipi:'');
    document.getElementById('f_pis').value=(d.pis!=null?d.pis:'');
    document.getElementById('f_cofins').value=(d.cofins!=null?d.cofins:'');
    limparEstados();
    var vals = _estadosMap[d.id] || {};
    document.querySelectorAll('#statesTbody tr').forEach(function(tr){
        var est = tr.dataset.estado;
        var ev = vals[est];
        if(!ev) return;
        tr.querySelectorAll('input[data-f]').forEach(function(i){
            var f = i.dataset.f;
            i.value = (ev[f]!=null ? ev[f] : '');
        });
    });
    getModal().show();
}
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
