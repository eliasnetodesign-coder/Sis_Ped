<?php
require_once __DIR__ . '/../../config.php';
$u = usuario();
if (!$u || !in_array($u['tipo'], ['financeiro', 'tecnologia da informacao'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// ── Salvar tabela "Estados com ST" (persiste em configuracoes) ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_st') {
    stEstadosSalvar($_POST['st'] ?? []);
    flash('success', 'Tabela de ST por estado atualizada.');
    header('Location: ' . BASE_URL . '/admin/financeiro/analise-financeira.php');
    exit;
}

// ── Consulta sob demanda ao sistema A&M (não grava nada) ─────────────────────
$resultado = null;
$erro      = null;
$hoje      = date('Y-m-d');
$iniGet    = $_GET['ini'] ?? date('Y-01-01');
$fimGet    = $_GET['fim'] ?? date('Y-12-31');

if (($_GET['acao'] ?? '') === 'buscar') {
    $toBR = function ($iso) { $t = strtotime($iso); return $t ? date('d/m/Y', $t) : ''; };
    set_time_limit(300);
    $resultado = analiseFinanceiraAEM($toBR($iniGet), $toBR($fimGet));
    if (!$resultado['ok']) { $erro = $resultado['erro'] ?: 'Falha ao consultar o A&M.'; $resultado = null; }
}

$stTab = stEstadosTabela();

$fmtVal = function ($txt) {
    $txt = trim((string)$txt);
    return $txt === '' ? '—' : (preg_match('/^-?R\$/i', $txt) ? $txt : 'R$ ' . $txt);
};
$okIcon = function ($ok) {
    return $ok ? '<i class="bi bi-check-circle-fill text-success"></i>'
              : '<i class="bi bi-x-circle-fill text-danger"></i>';
};
$pct = function ($v) { return $v === null ? '—' : rtrim(rtrim(number_format((float)$v, 2, ',', '.'), '0'), ',') . '%'; };

$pageTitle = 'Análise Financeira';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="mb-4">
    <h4 class="fw-bold mb-1"><i class="bi bi-search me-2"></i>Análise Financeira</h4>
    <p class="text-muted small mb-0">
        Consulta sob demanda ao sistema A&amp;M — pedidos aguardando liberação (bloco “Pedidos”) x
        Detalhe do Conta Corrente x detalhe de cada pedido. Nada é gravado; nenhuma operação é feita no A&amp;M.
    </p>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end" id="frmBusca">
            <input type="hidden" name="acao" value="buscar">
            <div class="col-sm-4 col-md-3">
                <label class="form-label fw-semibold small">Período — Início</label>
                <input type="date" name="ini" class="form-control" value="<?= e($iniGet) ?>" max="<?= $hoje ?>">
            </div>
            <div class="col-sm-4 col-md-3">
                <label class="form-label fw-semibold small">Período — Fim</label>
                <input type="date" name="fim" class="form-control" value="<?= e($fimGet) ?>">
            </div>
            <div class="col-sm-8 col-md-6 d-flex gap-2">
                <button type="submit" class="btn btn-primary" id="btnBuscar">
                    <i class="bi bi-cloud-download me-1"></i>Buscar informações no A&amp;M
                </button>
                <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mdlST">
                    <i class="bi bi-table me-1"></i>Tabela de ST por estado
                </button>
            </div>
        </form>
        <div class="text-muted small mt-2" id="aguarde" style="display:none">
            <span class="spinner-border spinner-border-sm me-1"></span>
            Consultando o sistema A&amp;M… pode levar até ~2 minutos (lê o detalhe de cada pedido).
        </div>
    </div>
</div>

<?php if ($erro): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= e($erro) ?></div>
<?php endif; ?>

<?php if ($resultado): ?>
    <?php
    $analises = $resultado['analises'];
    $totCod   = count($analises);
    $totAprov = 0;  $totConf = 0;  $totPed = 0;
    foreach ($analises as $a) {
        if ($a['aprovado']) $totAprov++;
        foreach ($a['linhas'] as $l) { $totPed++; if (!empty($l['conforme'])) $totConf++; }
    }
    ?>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <span class="badge bg-secondary">Gerado em <?= e($resultado['gerado_em']) ?></span>
        <span class="badge bg-secondary">Período <?= e($resultado['periodo'][0]) ?> a <?= e($resultado['periodo'][1]) ?></span>
        <span class="badge bg-light text-dark border"><?= $totCod ?> código(s) · <?= $totPed ?> pedido(s)</span>
        <span class="badge bg-success"><?= $totAprov ?> código(s) aprovado(s)</span>
        <span class="badge bg-primary"><span id="selCount"><?= $totConf ?></span> pedido(s) marcado(s)</span>
    </div>

    <?php if ($totCod === 0): ?>
        <div class="alert alert-info">Nenhum pedido aguardando liberação no período.</div>
    <?php else: ?>

    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="selTodos(true)">Marcar todos</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selTodos(false)">Limpar seleção</button>
        <span class="text-muted small ms-2">Pré-marcados os pedidos que atendem aos 4 requisitos (1 sem atraso · 2 dentro do limite/à vista · 3 % Descto ST ≤ tabela · 4 sem desconto diretoria).</span>
    </div>

    <!-- Detalhe por código -->
    <?php foreach ($analises as $a): ?>
        <div class="card shadow-sm border-0 mb-4" id="cod-<?= e($a['codigo']) ?>">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center">
                <div class="fw-bold">
                    <i class="bi bi-hash"></i><?= e($a['codigo']) ?>
                    <span class="text-muted fw-normal">— <?= e($a['cliente'] ?: $a['distribuidor_cc']) ?></span>
                </div>
                <span class="badge bg-<?= $a['aprovado'] ? 'success' : 'danger' ?>"><?= $a['aprovado'] ? 'CÓDIGO APROVADO' : 'CÓDIGO NÃO APROVADO' ?></span>
            </div>
            <div class="card-body">
                <div class="row g-2 small mb-3">
                    <div class="col-md-3"><span class="text-muted">Cód. Distribuidor:</span> <b><?= e($a['codigo_distribuidor']) ?></b></div>
                    <div class="col-md-5"><span class="text-muted">Distribuidor (Conta Corrente):</span> <b><?= e($a['distribuidor_cc'] ?: '—') ?></b></div>
                    <div class="col-md-4"><span class="text-muted">Canal de Venda:</span> <b><?= e($a['canal_venda'] ?: '—') ?></b></div>
                    <div class="col-md-6"><span class="text-muted">Pedido Accademia (Cadastro de Distribuidores):</span>
                        <b><?= $a['accademia_cadastro'] === null ? 'não localizado' : e($a['accademia_cadastro']) ?></b>
                        <span class="text-muted">→ ST pela coluna <b><?= $a['com_academia'] ? 'c/ Academia' : 's/ Academia' ?></b></span>
                    </div>
                </div>

                <!-- Pedidos do código -->
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered align-middle mb-0" style="min-width:900px">
                        <thead class="table-light">
                            <tr>
                                <th style="width:2.5rem" class="text-center"><i class="bi bi-check2-square"></i></th>
                                <th>Número</th><th>Tipo</th><th>Data</th>
                                <th class="text-end">Valor</th>
                                <th class="text-end">Créd. Utilizado</th>
                                <th class="text-end">Saldo a Pagar</th>
                                <th class="text-end">Sim. Network</th>
                                <th class="text-end">Sim. Accademia</th>
                                <th class="text-end">Sim. Descto</th>
                                <th>Forma pagto</th>
                                <th class="text-center">À vista</th>
                                <th>UF</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($a['linhas'] as $l): $nid = e($a['codigo'] . '-' . $l['numero']); ?>
                            <tr>
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input sel-pedido" value="<?= $nid ?>" <?= !empty($l['conforme']) ? 'checked' : '' ?>>
                                </td>
                                <td class="fw-semibold"><?= e($l['numero']) ?></td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $l['tipo'] === 'V' ? 'primary' : 'warning text-dark' ?>"><?= e($l['tipo']) ?></span>
                                </td>
                                <td><?= e($l['data']) ?></td>
                                <td class="text-end"><?= e($l['valor']) ?></td>
                                <td class="text-end"><?= e($l['credito_utilizado']) ?></td>
                                <td class="text-end"><?= e($l['saldo_a_pagar']) ?></td>
                                <td class="text-end"><?= e($l['sim_network']) ?></td>
                                <td class="text-end"><?= e($l['sim_accademia']) ?></td>
                                <td class="text-end"><?= e($l['sim_descto']) ?></td>
                                <td class="small"><?= e($l['forma'] ?: '—') ?></td>
                                <td class="text-center"><?= $okIcon(!empty($l['is_a_vista'])) ?></td>
                                <td><?= e($l['uf'] ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Conferências da avaliação individual -->
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="border rounded p-3 h-100">
                            <div class="fw-semibold mb-2"><?= $okIcon($a['check_sem_atraso']) ?> 1) Atrasos no Conta Corrente</div>
                            <table class="table table-sm mb-2">
                                <tr><td>01-Network</td><td class="text-end"><?= $a['atrasos']['network'] === null ? '—' : moedaBR($a['atrasos']['network']) ?></td></tr>
                                <tr><td>04-Accademia</td><td class="text-end"><?= $a['atrasos']['accademia'] === null ? '—' : moedaBR($a['atrasos']['accademia']) ?></td></tr>
                                <tr class="fw-semibold"><td>Total</td><td class="text-end <?= $a['atrasos']['total'] > 0 ? 'text-danger' : '' ?>"><?= moedaBR($a['atrasos']['total']) ?></td></tr>
                            </table>
                            <div class="small <?= $a['check_sem_atraso'] ? 'text-success' : 'text-danger' ?>">
                                <?= $a['check_sem_atraso'] ? 'Sem valores em atraso.' : 'Possui valor em atraso.' ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded p-3 h-100">
                            <div class="fw-semibold mb-2"><?= $okIcon($a['check_dentro_limite']) ?> 2) Limite de Crédito</div>
                            <table class="table table-sm mb-2">
                                <tr><td>Limite de Crédito</td><td class="text-end"><?= $fmtVal($a['limite_txt']) ?></td></tr>
                                <tr><td>Σ Tipo V que <u>não</u> é à vista <span class="text-muted">(aguardando)</span></td><td class="text-end"><?= moedaBR($a['soma_v_nao_avista']) ?></td></tr>
                                <tr><td>+ Venda já faturada no mês <span class="text-muted">(Consulta/Reimprime, <?= (int)$a['faturado_mes_qtd'] ?> ped.)</span></td><td class="text-end"><?= moedaBR($a['faturado_mes']) ?></td></tr>
                                <tr class="fw-semibold border-top"><td>= Total a considerar</td><td class="text-end <?= $a['check_dentro_limite'] ? '' : 'text-danger' ?>"><?= moedaBR($a['check2_base']) ?></td></tr>
                            </table>
                            <div class="small <?= $a['check_dentro_limite'] ? 'text-success' : 'text-danger' ?>">
                                <?php if ($a['sem_financiar']): ?>Nada a considerar (pedidos V à vista/sem V e sem faturado no mês) — ok.
                                <?php elseif ($a['check_dentro_limite']): ?>O total (aguardando + faturado no mês) cabe no limite.
                                <?php elseif ($a['limite_num'] <= 0): ?>Limite de crédito não cadastrado.
                                <?php else: ?>Excede o limite em <?= moedaBR($a['check2_base'] - $a['limite_num']) ?>.<?php endif; ?>
                            </div>
                            <div class="small text-muted mt-1">Faturado somado no período <?= e($a['faturado_periodo'][0]) ?> a <?= e($a['faturado_periodo'][1]) ?> (tipo Venda, Situação “Faturado”).</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded p-3 h-100">
                            <?php $stAcima = (int)$a['pedidos_st_avaliados'] - (int)$a['pedidos_st_ok']; ?>
                            <div class="fw-semibold mb-2">
                                <?php if ($a['pedidos_st_avaliados'] === 0): ?><i class="bi bi-dash-circle text-muted"></i>
                                <?php elseif ($stAcima > 0): ?><i class="bi bi-exclamation-triangle-fill text-warning"></i>
                                <?php else: ?><i class="bi bi-check-circle-fill text-success"></i><?php endif; ?>
                                3) ST por Estado
                            </div>
                            <div class="small mb-2 text-muted">
                                Alerta quando o <b>% Descto ST</b> aplicado no pedido é <b>maior</b> que o da tabela do estado
                                (coluna <b><?= $a['com_academia'] ? 'c/ Academia' : 's/ Academia' ?></b>).
                            </div>
                            <?php if ($a['pedidos_st_avaliados'] === 0): ?>
                                <div class="small text-muted">Nenhum pedido com UF na tabela de ST.</div>
                            <?php elseif ($stAcima === 0): ?>
                                <div class="small text-success">Nenhum pedido com % Descto ST acima da tabela do estado.</div>
                            <?php else: ?>
                                <div class="small text-muted mb-2">Pedidos com % Descto ST acima da tabela:</div>
                                <table class="table table-sm mb-0">
                                    <thead><tr><th>Pedido</th><th>UF</th><th class="text-end">ST tabela</th><th class="text-end">ST pedido</th><th></th></tr></thead>
                                    <tbody>
                                    <?php foreach ($a['linhas'] as $l): $nid = e($a['codigo'] . '-' . $l['numero']); if ($l['check_st'] !== false) continue; ?>
                                        <tr>
                                            <td><?= e($l['numero']) ?></td>
                                            <td><?= e($l['uf']) ?></td>
                                            <td class="text-end"><?= $pct($l['st_esperado']) ?></td>
                                            <td class="text-end fw-semibold text-danger"><?= $pct($l['descto_st_pedido']) ?></td>
                                            <td class="text-end">
                                                <?php if (!empty($l['tem_descto_st'])): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-danger py-0" data-bs-toggle="modal" data-bs-target="#mdlST-<?= $nid ?>" title="Produtos com % Descto ST">
                                                        <i class="bi bi-eye"></i> <?= count($l['itens_descto_st']) ?>
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded p-3 h-100">
                            <div class="fw-semibold mb-2">
                                <?= $okIcon(!$a['tem_item_diretoria']) ?>
                                4) Desconto Diretoria
                            </div>
                            <?php if (!$a['tem_item_diretoria']): ?>
                                <div class="small text-success">Nenhum produto com % Diretoria.</div>
                            <?php else: ?>
                                <div class="small text-danger mb-2">Pedidos com produtos que têm % Diretoria &gt; 0 (não atendem ao requisito) — clique para ver os produtos:</div>
                                <?php foreach ($a['linhas'] as $l): $nid = e($a['codigo'] . '-' . $l['numero']); if (empty($l['tem_diretoria'])) continue; ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger py-0 me-1 mb-1" data-bs-toggle="modal" data-bs-target="#mdlDir-<?= $nid ?>">
                                        <i class="bi bi-eye"></i> Pedido <?= e($l['numero']) ?> (<?= count($l['itens_diretoria']) ?> produto/s)
                                    </button>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modais de produtos com desconto (por pedido) -->
        <?php foreach ($a['linhas'] as $l): $nid = e($a['codigo'] . '-' . $l['numero']); ?>
            <?php if (!empty($l['tem_diretoria'])): ?>
            <div class="modal fade" id="mdlDir-<?= $nid ?>" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Pedido <?= e($l['numero']) ?> — produtos com % Diretoria &gt; 0</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0"><div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Código</th><th>Produto</th><th class="text-end">Qtd</th><th class="text-end">% Descto</th><th class="text-end">% Diretoria</th></tr></thead>
                        <tbody>
                        <?php foreach ($l['itens_diretoria'] as $it): ?>
                            <tr><td><?= e($it['codigo']) ?></td><td><?= e($it['nome']) ?></td>
                                <td class="text-end"><?= (int)$it['qtd'] ?></td>
                                <td class="text-end"><?= $pct($it['pct_descto']) ?></td>
                                <td class="text-end fw-semibold text-danger"><?= $pct($it['pct_diretoria']) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div></div>
            </div></div></div>
            <?php endif; ?>
            <?php if (!empty($l['tem_descto_st'])): ?>
            <div class="modal fade" id="mdlST-<?= $nid ?>" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Pedido <?= e($l['numero']) ?> — produtos com % Descto ST &gt; 0</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0"><div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Código</th><th>Produto</th><th class="text-end">Qtd</th><th class="text-end">% Descto</th><th class="text-end">% Descto ST</th></tr></thead>
                        <tbody>
                        <?php foreach ($l['itens_descto_st'] as $it): ?>
                            <tr><td><?= e($it['codigo']) ?></td><td><?= e($it['nome']) ?></td>
                                <td class="text-end"><?= (int)$it['qtd'] ?></td>
                                <td class="text-end"><?= $pct($it['pct_descto']) ?></td>
                                <td class="text-end fw-semibold text-danger"><?= $pct($it['pct_descto_st']) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div></div>
            </div></div></div>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <?php endif; // totCod ?>
<?php elseif (!$erro): ?>
    <div class="alert alert-light border">
        <i class="bi bi-info-circle me-2"></i>
        Defina o período e clique em <b>Buscar informações no A&amp;M</b> para rodar a análise.
    </div>
<?php endif; ?>

<!-- Modal: Tabela de ST por estado (editável) -->
<div class="modal fade" id="mdlST" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
    <form method="POST" class="modal-content">
        <input type="hidden" name="acao" value="salvar_st">
        <div class="modal-header">
            <h5 class="modal-title fw-bold"><i class="bi bi-table me-2"></i>Estados com ST (%)</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" style="overflow-y:auto">
            <p class="text-muted small">
                O percentual só se aplica “quando o DI ou Loja reclamam”; caso contrário fica sem ST.
                Usado como referência do ST esperado por pedido conforme a UF do cliente.
            </p>
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light" style="position:sticky;top:0;z-index:1">
                    <tr><th>UF</th><th>Estado</th><th class="text-end">Fat. c/ Academia</th><th class="text-end">Fat. sem Academia</th></tr>
                </thead>
                <tbody>
                <?php foreach ($stTab as $uf => $r): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($uf) ?></td>
                        <td class="small"><?= e($r['nome']) ?></td>
                        <td><div class="input-group input-group-sm"><input type="number" step="0.01" min="0" class="form-control text-end" name="st[<?= e($uf) ?>][com_academia]" value="<?= e(rtrim(rtrim(number_format($r['com_academia'],2,'.',''), '0'), '.')) ?>"><span class="input-group-text">%</span></div></td>
                        <td><div class="input-group input-group-sm"><input type="number" step="0.01" min="0" class="form-control text-end" name="st[<?= e($uf) ?>][sem_academia]" value="<?= e(rtrim(rtrim(number_format($r['sem_academia'],2,'.',''), '0'), '.')) ?>"><span class="input-group-text">%</span></div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salvar tabela</button>
        </div>
    </form>
</div></div>

<script>
document.getElementById('frmBusca').addEventListener('submit', function () {
    var b = document.getElementById('btnBuscar');
    b.disabled = true;
    b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Consultando…';
    document.getElementById('aguarde').style.display = '';
});
function atualizaContagem() {
    var n = document.querySelectorAll('.sel-pedido:checked').length;
    var el = document.getElementById('selCount');
    if (el) el.textContent = n;
}
function selTodos(v) {
    document.querySelectorAll('.sel-pedido').forEach(function (c) { c.checked = v; });
    atualizaContagem();
}
document.addEventListener('change', function (e) {
    if (e.target && e.target.classList.contains('sel-pedido')) atualizaContagem();
});
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
