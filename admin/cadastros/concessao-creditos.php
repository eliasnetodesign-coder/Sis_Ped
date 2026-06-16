<?php
require_once __DIR__ . '/../../config.php';
requireComercial();
$u = usuario();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    try {
        if ($a === 'criar') {
            db()->prepare('INSERT INTO creditos (cliente_id, descricao, observacao_interna, valor, data, usuario_id) VALUES (?,?,?,?,?,?)')
               ->execute([(int)$_POST['cliente_id'], trim($_POST['descricao']), trim($_POST['observacao_interna']), (float)$_POST['valor'], $_POST['data'], $u['id']]);
            flash('success', 'Crédito concedido com sucesso!');
        } elseif ($a === 'excluir') {
            $chk = db()->prepare('SELECT COALESCE(valor_utilizado,0) FROM creditos WHERE id=?');
            $chk->execute([(int)$_POST['id']]);
            if ((float)$chk->fetchColumn() > 0) {
                flash('danger', 'Este crédito já foi utilizado em um pedido e não pode ser excluído.');
            } else {
                db()->prepare('DELETE FROM creditos WHERE id=?')->execute([(int)$_POST['id']]);
                flash('success', 'Registro excluído.');
            }
        } elseif (in_array($a, ['aprovado', 'reprovado'])) {
            db()->prepare('INSERT INTO creditos_logs (credito_id, acao, usuario_nome) VALUES (?,?,?)')
               ->execute([(int)$_POST['id'], $a, $u['nome']]);
        }
    } catch (Exception $e) { flash('danger', 'Erro: ' . $e->getMessage()); }
    header('Location: ' . BASE_URL . '/admin/cadastros/concessao-creditos.php?' . http_build_query(array_filter(['dt_ini'=>$_POST['dt_ini']??'','dt_fim'=>$_POST['dt_fim']??'','q'=>$_POST['q']??'']))); exit;
}

$busca     = $_GET['q']      ?? '';
$dt_inicio = $_GET['dt_ini'] ?? date('Y-m-01');
$dt_fim    = $_GET['dt_fim'] ?? date('Y-m-t');

$conditions = ['DATE(cr.data) BETWEEN ? AND ?'];
$params     = [$dt_inicio, $dt_fim];
if ($busca) { $conditions[] = '(c.razao_social LIKE ? OR c.codigo_cliente LIKE ?)'; $params[] = "%$busca%"; $params[] = "%$busca%"; }
$where = 'WHERE ' . implode(' AND ', $conditions);

$creditos = db()->prepare("
    SELECT cr.*,
           c.razao_social, c.codigo_cliente,
           u.nome AS usuario_nome,
           COALESCE(cr_avg.media_atrasos, 0) AS media_atrasos,
           lg.acao AS log_acao, lg.usuario_nome AS log_usuario, lg.created_at AS log_at
    FROM creditos cr
    JOIN clientes c ON c.id = cr.cliente_id
    LEFT JOIN usuarios u ON u.id = cr.usuario_id
    LEFT JOIN (
        SELECT cliente_id, ROUND(AVG(DATEDIFF(data_pagamento, data_vencimento)), 1) AS media_atrasos
        FROM contas_receber
        WHERE data_pagamento IS NOT NULL AND data_pagamento > data_vencimento
        GROUP BY cliente_id
    ) cr_avg ON cr_avg.cliente_id = c.id
    LEFT JOIN (
        SELECT l1.credito_id, l1.acao, l1.usuario_nome, l1.created_at
        FROM creditos_logs l1
        INNER JOIN (SELECT credito_id, MAX(id) AS max_id FROM creditos_logs GROUP BY credito_id) l2
            ON l2.credito_id = l1.credito_id AND l2.max_id = l1.id
    ) lg ON lg.credito_id = cr.id
    $where
    ORDER BY cr.data DESC, cr.created_at DESC
");
$creditos->execute($params);
$creditos = $creditos->fetchAll();

$totalCreditos = array_sum(array_column($creditos, 'valor'));
$clientes = db()->query('SELECT id, codigo_cliente, razao_social FROM clientes WHERE status="ativo" ORDER BY razao_social')->fetchAll();

$pageTitle = 'Concessão de Créditos';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-coin me-2"></i>Concessão de Créditos</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal" onclick="novoReg()">
        <i class="bi bi-plus-lg me-1"></i>Conceder Crédito
    </button>
</div>

<!-- Filtros -->
<form method="GET" class="card shadow-sm border-0 mb-4">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-semibold small mb-1">Buscar cliente</label>
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Nome ou código..." value="<?= e($busca) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold small mb-1">Data inicial</label>
                <input type="date" name="dt_ini" class="form-control form-control-sm" value="<?= e($dt_inicio) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold small mb-1">Data final</label>
                <input type="date" name="dt_fim" class="form-control form-control-sm" value="<?= e($dt_fim) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Filtrar</button>
                <a href="?" class="btn btn-sm btn-outline-secondary ms-1">Limpar</a>
            </div>
            <?php if ($creditos): ?>
            <div class="col-auto ms-auto">
                <span class="fw-semibold text-success">Total no período: <?= moedaBR($totalCreditos) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</form>

<!-- Tabela -->
<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover table-sm mb-0" style="font-size:.85rem">
            <thead class="table-light">
                <tr>
                    <th>Data</th>
                    <th>Código</th>
                    <th>Cliente</th>
                    <th>Crédito Referente</th>
                    <th>Observação Interna</th>
                    <th class="text-center">Média de Atraso</th>
                    <th class="text-end">Valor do Crédito</th>
                    <th>Solicitante</th>
                    <th>Ações</th>
                    <th>Log</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($creditos): foreach ($creditos as $cr): ?>
                <tr>
                    <td><?= dataBR($cr['data']) ?></td>
                    <td><span class="badge bg-secondary"><?= e($cr['codigo_cliente']) ?></span></td>
                    <td class="fw-semibold"><?= e($cr['razao_social']) ?></td>
                    <td class="text-muted small"><?= e($cr['descricao'] ?: '—') ?></td>
                    <td class="text-muted small" style="max-width:180px">
                        <?php if ($cr['observacao_interna']): ?>
                            <span title="<?= e($cr['observacao_interna']) ?>" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">
                                <?= e($cr['observacao_interna']) ?>
                            </span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($cr['media_atrasos'] > 0): ?>
                            <span class="badge bg-<?= $cr['media_atrasos'] > 30 ? 'danger' : ($cr['media_atrasos'] > 10 ? 'warning text-dark' : 'secondary') ?>">
                                <?= number_format($cr['media_atrasos'], 1) ?> dias
                            </span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end fw-semibold text-success"><?= moedaBR($cr['valor']) ?></td>
                    <td class="text-muted small"><?= e($cr['usuario_nome'] ?: '—') ?></td>
                    <td>
                        <div class="d-flex gap-1 flex-nowrap">
                            <?php if ($cr['log_acao'] !== 'aprovado'): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="aprovado">
                                <input type="hidden" name="id" value="<?= $cr['id'] ?>">
                                <input type="hidden" name="dt_ini" value="<?= e($dt_inicio) ?>">
                                <input type="hidden" name="dt_fim" value="<?= e($dt_fim) ?>">
                                <input type="hidden" name="q" value="<?= e($busca) ?>">
                                <button class="btn btn-xs btn-outline-primary" style="padding:.2rem .5rem;font-size:.75rem" title="Aprovar">
                                    <i class="bi bi-check-lg"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php if ($cr['log_acao'] !== 'reprovado'): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="reprovado">
                                <input type="hidden" name="id" value="<?= $cr['id'] ?>">
                                <input type="hidden" name="dt_ini" value="<?= e($dt_inicio) ?>">
                                <input type="hidden" name="dt_fim" value="<?= e($dt_fim) ?>">
                                <input type="hidden" name="q" value="<?= e($busca) ?>">
                                <button class="btn btn-xs btn-outline-danger" style="padding:.2rem .5rem;font-size:.75rem" title="Reprovar">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php $jaUsado = (float)($cr['valor_utilizado'] ?? 0) > 0; ?>
                            <?php if ($jaUsado): ?>
                                <button class="btn btn-xs btn-outline-secondary disabled" disabled
                                        style="padding:.2rem .5rem;font-size:.75rem"
                                        title="Crédito já utilizado — não pode ser excluído">
                                    <i class="bi bi-trash"></i>
                                </button>
                            <?php else: ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Excluir este crédito?')">
                                <input type="hidden" name="action" value="excluir">
                                <input type="hidden" name="id" value="<?= $cr['id'] ?>">
                                <input type="hidden" name="dt_ini" value="<?= e($dt_inicio) ?>">
                                <input type="hidden" name="dt_fim" value="<?= e($dt_fim) ?>">
                                <input type="hidden" name="q" value="<?= e($busca) ?>">
                                <button class="btn btn-xs btn-outline-secondary" style="padding:.2rem .5rem;font-size:.75rem" title="Excluir">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td style="min-width:160px">
                        <?php if ($cr['log_acao']): ?>
                            <span class="badge bg-<?= $cr['log_acao']==='aprovado'?'success':'danger' ?> mb-1">
                                <?= $cr['log_acao']==='aprovado' ? 'Aprovado' : 'Reprovado' ?>
                            </span><br>
                            <span class="small text-muted"><?= e($cr['log_usuario']) ?></span><br>
                            <span class="small text-muted"><?= date('d/m/Y H:i', strtotime($cr['log_at'])) ?></span>
                        <?php else: ?>
                            <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="10" class="text-center text-muted py-4">Nenhum crédito encontrado para o período.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="modal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" onsubmit="if(!document.getElementById('f_cli').value){document.getElementById('f_cli_txt').focus();return false;}">
                <input type="hidden" name="action" value="criar">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="bi bi-coin me-2"></i>Conceder Crédito</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Cliente <span class="text-danger">*</span></label>
                            <input type="hidden" name="cliente_id" id="f_cli">
                            <div class="position-relative">
                                <input type="text" id="f_cli_txt" class="form-control" placeholder="Buscar por nome ou código..." autocomplete="off">
                                <div id="f_cli_drop" class="border rounded bg-white shadow-sm position-absolute w-100"
                                     style="display:none;max-height:200px;overflow-y:auto;z-index:1060">
                                    <?php foreach ($clientes as $c): ?>
                                    <div class="px-3 py-2 cli-opt" style="cursor:pointer;font-size:.9rem"
                                         onmouseover="this.style.background='#f0f4f2'" onmouseout="this.style.background=''"
                                         data-id="<?= $c['id'] ?>"
                                         data-label="<?= e($c['codigo_cliente'] . ' — ' . $c['razao_social']) ?>"
                                         data-search="<?= e(mb_strtolower($c['codigo_cliente'] . ' ' . $c['razao_social'])) ?>">
                                        <span class="badge bg-secondary me-1" style="font-size:.7rem"><?= e($c['codigo_cliente']) ?></span><?= e($c['razao_social']) ?>
                                    </div>
                                    <?php endforeach; ?>
                                    <div id="f_cli_empty" class="px-3 py-2 text-muted small" style="display:none">Nenhum resultado.</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Crédito Referente</label>
                            <input type="text" name="descricao" id="f_desc" class="form-control" placeholder="Ex: Bônus de desempenho Q2/2026">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Data <span class="text-danger">*</span></label>
                            <input type="date" name="data" id="f_data" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Valor do Crédito (R$) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0.01" name="valor" id="f_val" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Observação Interna</label>
                            <textarea name="observacao_interna" id="f_obs" class="form-control" rows="2" placeholder="Observações internas sobre este crédito..."></textarea>
                        </div>
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
function novoReg() {
    document.getElementById('f_cli').value     = '';
    document.getElementById('f_cli_txt').value = '';
    document.getElementById('f_desc').value    = '';
    document.getElementById('f_data').value    = '<?= date('Y-m-d') ?>';
    document.getElementById('f_val').value     = '';
    document.getElementById('f_obs').value     = '';
}

(function() {
    var txt   = document.getElementById('f_cli_txt');
    var hid   = document.getElementById('f_cli');
    var drop  = document.getElementById('f_cli_drop');
    var empty = document.getElementById('f_cli_empty');
    var opts  = drop.querySelectorAll('.cli-opt');

    function filter(q) {
        var n = 0;
        opts.forEach(function(o) {
            var show = !q || o.dataset.search.indexOf(q) !== -1;
            o.style.display = show ? '' : 'none';
            if (show) n++;
        });
        empty.style.display = n === 0 ? '' : 'none';
    }
    txt.addEventListener('focus', function() { filter(txt.value.toLowerCase()); drop.style.display = ''; });
    txt.addEventListener('input', function() { hid.value = ''; filter(txt.value.toLowerCase()); drop.style.display = ''; });
    txt.addEventListener('blur',  function() { setTimeout(function() { drop.style.display = 'none'; }, 200); });
    drop.addEventListener('mousedown', function(e) {
        var o = e.target.closest('.cli-opt');
        if (!o) return;
        hid.value = o.dataset.id;
        txt.value = o.dataset.label;
        drop.style.display = 'none';
    });
}());
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
