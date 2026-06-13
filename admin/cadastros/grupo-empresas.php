<?php
require_once __DIR__ . '/../../config.php';
requireComercial();
$u = usuario();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    try {
        if ($a === 'criar') {
            $nome = trim($_POST['nome'] ?? '');
            $desc = trim($_POST['descricao'] ?? '');
            if ($nome === '') throw new Exception('Informe o nome do grupo.');
            db()->prepare('INSERT INTO grupo_empresas (nome, descricao) VALUES (?,?)')
               ->execute([$nome, $desc]);
            flash('success', 'Grupo criado com sucesso!');

        } elseif ($a === 'editar') {
            $id   = (int)$_POST['id'];
            $nome = trim($_POST['nome'] ?? '');
            $desc = trim($_POST['descricao'] ?? '');
            if ($nome === '') throw new Exception('Informe o nome do grupo.');
            db()->prepare('UPDATE grupo_empresas SET nome=?, descricao=? WHERE id=?')
               ->execute([$nome, $desc, $id]);
            flash('success', 'Grupo atualizado.');

        } elseif ($a === 'excluir') {
            $id = (int)$_POST['id'];
            db()->prepare('DELETE FROM grupo_empresas_clientes WHERE grupo_id=?')->execute([$id]);
            db()->prepare('DELETE FROM grupo_empresas WHERE id=?')->execute([$id]);
            flash('success', 'Grupo excluído.');

        } elseif ($a === 'add_cliente') {
            $grupo_id   = (int)$_POST['grupo_id'];
            $cliente_id = (int)$_POST['cliente_id'];
            db()->prepare('INSERT IGNORE INTO grupo_empresas_clientes (grupo_id, cliente_id) VALUES (?,?)')
               ->execute([$grupo_id, $cliente_id]);
            flash('success', 'Empresa adicionada ao grupo.');

        } elseif ($a === 'rem_cliente') {
            db()->prepare('DELETE FROM grupo_empresas_clientes WHERE grupo_id=? AND cliente_id=?')
               ->execute([(int)$_POST['grupo_id'], (int)$_POST['cliente_id']]);
            flash('success', 'Empresa removida do grupo.');
        }
    } catch (Exception $e) { flash('danger', 'Erro: ' . $e->getMessage()); }
    header('Location: ' . BASE_URL . '/admin/cadastros/grupo-empresas.php' . (isset($_POST['grupo_id']) ? '#grupo-' . (int)$_POST['grupo_id'] : '')); exit;
}

// Busca grupos com contagem de membros
$grupos = db()->query("
    SELECT g.*, COUNT(gc.cliente_id) AS total_membros
    FROM grupo_empresas g
    LEFT JOIN grupo_empresas_clientes gc ON gc.grupo_id = g.id
    GROUP BY g.id
    ORDER BY g.nome
")->fetchAll();

// Membros de cada grupo
$membros = [];
if ($grupos) {
    $ids = implode(',', array_column($grupos, 'id'));
    $rows = db()->query("
        SELECT gc.grupo_id, c.id, c.razao_social, c.codigo_cliente, c.cnpj
        FROM grupo_empresas_clientes gc
        JOIN clientes c ON c.id = gc.cliente_id
        WHERE gc.grupo_id IN ($ids)
        ORDER BY c.razao_social
    ")->fetchAll();
    foreach ($rows as $r) $membros[$r['grupo_id']][] = $r;
}

// Todos os clientes ativos (para o select de adicionar)
$clientes = db()->query("SELECT id, razao_social, codigo_cliente, cnpj FROM clientes WHERE status='ativo' ORDER BY razao_social")->fetchAll();

$pageTitle = 'Grupo de Empresas';
require_once LAYOUT_PATH . '/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-diagram-2 me-2 text-primary"></i>Grupo de Empresas</h4>
        <small class="text-muted">Agrupe CNPJs cadastrados para análise e operações conjuntas</small>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNovoGrupo">
        <i class="bi bi-plus-lg me-1"></i>Novo Grupo
    </button>
</div>

<?php if (empty($grupos)): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5 text-muted">
        <i class="bi bi-diagram-2 display-4 d-block mb-3 opacity-25"></i>
        Nenhum grupo cadastrado ainda.<br>
        <button class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#modalNovoGrupo">
            <i class="bi bi-plus-lg me-1"></i>Criar primeiro grupo
        </button>
    </div>
</div>
<?php else: ?>

<div class="row g-4">
<?php foreach ($grupos as $g):
    $gmembros = $membros[$g['id']] ?? [];
    // IDs já no grupo (para filtrar o select)
    $idsNoGrupo = array_column($gmembros, 'id');
?>
<div class="col-12" id="grupo-<?= $g['id'] ?>">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex align-items-center gap-3">
                <i class="bi bi-diagram-2 text-primary fs-5"></i>
                <div>
                    <div class="fw-bold"><?= e($g['nome']) ?></div>
                    <?php if ($g['descricao']): ?>
                    <div class="text-muted small"><?= e($g['descricao']) ?></div>
                    <?php endif; ?>
                </div>
                <span class="badge bg-primary rounded-pill"><?= $g['total_membros'] ?> empresa<?= $g['total_membros'] != 1 ? 's' : '' ?></span>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-secondary"
                        data-bs-toggle="modal" data-bs-target="#modalEditarGrupo"
                        data-id="<?= $g['id'] ?>"
                        data-nome="<?= e($g['nome']) ?>"
                        data-desc="<?= e($g['descricao'] ?? '') ?>">
                    <i class="bi bi-pencil me-1"></i>Editar
                </button>
                <form method="POST" onsubmit="return confirm('Excluir o grupo \'<?= e($g['nome']) ?>\'? Isso removerá todas as empresas do grupo.')">
                    <input type="hidden" name="action" value="excluir">
                    <input type="hidden" name="id" value="<?= $g['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Excluir</button>
                </form>
            </div>
        </div>
        <div class="card-body p-0">

            <?php if (empty($gmembros)): ?>
            <div class="text-center text-muted py-4 small">Nenhuma empresa neste grupo ainda.</div>
            <?php else: ?>
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3 small">Código</th>
                        <th class="small">Razão Social</th>
                        <th class="small">CNPJ</th>
                        <th class="text-center small" style="width:80px">Remover</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($gmembros as $m): ?>
                <tr>
                    <td class="ps-3"><span class="badge bg-secondary"><?= e($m['codigo_cliente']) ?></span></td>
                    <td class="fw-semibold"><?= e($m['razao_social']) ?></td>
                    <td class="text-muted small"><?= e($m['cnpj'] ?? '—') ?></td>
                    <td class="text-center">
                        <form method="POST">
                            <input type="hidden" name="action"     value="rem_cliente">
                            <input type="hidden" name="grupo_id"   value="<?= $g['id'] ?>">
                            <input type="hidden" name="cliente_id" value="<?= $m['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" title="Remover do grupo"
                                    onclick="return confirm('Remover <?= e($m['razao_social']) ?> do grupo?')">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <!-- Adicionar empresa -->
            <div class="border-top px-3 py-3 bg-light">
                <form method="POST" class="ge-add-form d-flex gap-2 align-items-center flex-wrap"
                      onsubmit="return geValidar(this)">
                    <input type="hidden" name="action"     value="add_cliente">
                    <input type="hidden" name="grupo_id"   value="<?= $g['id'] ?>">
                    <input type="hidden" name="cliente_id" class="ge-hidden-id">
                    <div class="position-relative" style="max-width:420px;width:100%">
                        <input type="text"
                               class="form-control form-control-sm ge-search"
                               placeholder="Digite nome ou código da empresa..."
                               autocomplete="off"
                               data-grupo="<?= $g['id'] ?>">
                        <div class="ge-dropdown list-group position-absolute w-100 shadow-sm"
                             style="display:none;z-index:1050;top:100%;left:0;max-height:220px;overflow-y:auto"></div>
                    </div>
                    <button class="btn btn-sm btn-success">
                        <i class="bi bi-plus-lg me-1"></i>Adicionar
                    </button>
                </form>
            </div>

        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>


<!-- Modal: Novo Grupo -->
<div class="modal fade" id="modalNovoGrupo" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <input type="hidden" name="action" value="criar">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold"><i class="bi bi-diagram-2 me-2 text-primary"></i>Novo Grupo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pt-3">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nome do Grupo <span class="text-danger">*</span></label>
                        <input type="text" name="nome" class="form-control" placeholder="Ex: Grupo ABC" required>
                    </div>
                    <div class="mb-1">
                        <label class="form-label fw-semibold">Descrição <span class="text-muted small fw-normal">(opcional)</span></label>
                        <textarea name="descricao" class="form-control" rows="2" placeholder="Observações sobre o grupo..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Criar Grupo</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Editar Grupo -->
<div class="modal fade" id="modalEditarGrupo" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <input type="hidden" name="action" value="editar">
                <input type="hidden" name="id" id="editGrupoId">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold"><i class="bi bi-pencil me-2 text-primary"></i>Editar Grupo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pt-3">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nome do Grupo <span class="text-danger">*</span></label>
                        <input type="text" name="nome" id="editGrupoNome" class="form-control" required>
                    </div>
                    <div class="mb-1">
                        <label class="form-label fw-semibold">Descrição <span class="text-muted small fw-normal">(opcional)</span></label>
                        <textarea name="descricao" id="editGrupoDesc" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('modalEditarGrupo').addEventListener('show.bs.modal', function(e) {
    var btn = e.relatedTarget;
    document.getElementById('editGrupoId').value   = btn.dataset.id;
    document.getElementById('editGrupoNome').value = btn.dataset.nome;
    document.getElementById('editGrupoDesc').value = btn.dataset.desc;
});

// Dados dos clientes por grupo (excluindo já membros)
var _geClientes = <?php
    $byGrupo = [];
    foreach ($grupos as $g) {
        $idsNoGrupo = array_column($membros[$g['id']] ?? [], 'id');
        $byGrupo[$g['id']] = array_values(array_filter($clientes, fn($c) => !in_array($c['id'], $idsNoGrupo)));
    }
    echo json_encode($byGrupo);
?>;

function geNormalizar(s) {
    return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
}

document.querySelectorAll('.ge-search').forEach(function(inp) {
    var grupoId  = inp.dataset.grupo;
    var dropdown = inp.closest('.position-relative').querySelector('.ge-dropdown');
    var form     = inp.closest('form');
    var hiddenId = form.querySelector('.ge-hidden-id');

    inp.addEventListener('input', function() {
        var q = geNormalizar(inp.value.trim());
        hiddenId.value = '';
        inp.classList.remove('is-valid');

        if (q.length < 1) { dropdown.style.display = 'none'; return; }

        var lista = (_geClientes[grupoId] || []).filter(function(c) {
            return geNormalizar(c.razao_social).includes(q) || geNormalizar(c.codigo_cliente).includes(q);
        }).slice(0, 12);

        if (lista.length === 0) {
            dropdown.innerHTML = '<div class="list-group-item text-muted small py-2">Nenhuma empresa encontrada</div>';
        } else {
            dropdown.innerHTML = lista.map(function(c) {
                return '<button type="button" class="list-group-item list-group-item-action py-2 px-3 small"'
                    + ' data-id="' + c.id + '" data-label="' + c.razao_social.replace(/"/g,'&quot;') + ' (' + c.codigo_cliente + ')">'
                    + '<span class="badge bg-secondary me-2">' + c.codigo_cliente + '</span>'
                    + c.razao_social
                    + '</button>';
            }).join('');
            dropdown.querySelectorAll('button').forEach(function(btn) {
                btn.addEventListener('mousedown', function(e) {
                    e.preventDefault();
                    hiddenId.value = btn.dataset.id;
                    inp.value      = btn.dataset.label;
                    inp.classList.add('is-valid');
                    dropdown.style.display = 'none';
                });
            });
        }
        dropdown.style.display = '';
    });

    inp.addEventListener('blur',  function() { setTimeout(function(){ dropdown.style.display='none'; }, 150); });
    inp.addEventListener('focus', function() { if (inp.value.trim().length > 0) inp.dispatchEvent(new Event('input')); });
});

function geValidar(form) {
    var hid = form.querySelector('.ge-hidden-id');
    if (!hid.value) {
        form.querySelector('.ge-search').focus();
        alert('Selecione uma empresa da lista antes de adicionar.');
        return false;
    }
    return true;
}
</script>

<?php require_once LAYOUT_PATH . '/footer.php'; ?>
