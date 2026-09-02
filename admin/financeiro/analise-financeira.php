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

// ── Liberar pedidos selecionados no A&M (GRAVA de verdade lá — ver liberarPedidoAEM()) ────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'liberar_pedidos') {
    header('Content-Type: application/json');
    $codigo = trim($_POST['codigo'] ?? '');
    $itens  = is_array($_POST['itens'] ?? null) ? $_POST['itens'] : [];
    $insLog = db()->prepare('INSERT INTO liberacoes_am_logs (sid_ped,numero_pedido,codigo_cliente,usuario_id,usuario_nome,status,resposta) VALUES (?,?,?,?,?,?,?)');
    $resultados = [];
    foreach ($itens as $it) {
        $sidped = trim($it['sidped'] ?? '');
        $numero = trim($it['numero'] ?? '');
        $tipo   = strtoupper(trim($it['tipo'] ?? ''));
        $avista = !empty($it['avista']);
        if ($sidped === '') continue;
        // À vista não libera por aqui, exceto pedido de Bonificação (tipo B) — sempre pode.
        if ($avista && $tipo !== 'B') {
            $insLog->execute([$sidped, $numero, $codigo, $u['id'], $u['nome'], 'pulado_avista', 'Pedido à vista — liberação por aqui não permitida (por enquanto).']);
            $resultados[] = ['sidped' => $sidped, 'numero' => $numero, 'status' => 'pulado_avista'];
            continue;
        }
        $r = liberarPedidoAEM($sidped);
        $insLog->execute([$sidped, $numero, $codigo, $u['id'], $u['nome'], $r['ok'] ? 'liberado' : 'erro', $r['ok'] ? $r['resposta'] : $r['erro']]);
        $resultados[] = ['sidped' => $sidped, 'numero' => $numero, 'status' => $r['ok'] ? 'liberado' : 'erro', 'erro' => $r['erro']];
    }
    echo json_encode(['ok' => true, 'resultados' => $resultados]);
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
$logLiberacoes = db()->query('SELECT * FROM liberacoes_am_logs ORDER BY created_at DESC LIMIT 300')->fetchAll();

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
                <a href="<?= BASE_URL ?>/admin/financeiro/campanhas-am.php" class="btn btn-outline-secondary">
                    <i class="bi bi-megaphone me-1"></i>Campanhas
                </a>
                <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mdlLogLiberacoes">
                    <i class="bi bi-journal-text me-1"></i>Log de Liberações
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
        <span class="text-muted small ms-2">Pré-marcados os pedidos que atendem aos 4 requisitos (1 sem atraso · 2 dentro do limite/à vista · 3 % Descto ST ≤ tabela · 4 sem desconto fora de campanha).</span>
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
                    <div class="col-md-4"><span class="text-muted">Supervisor:</span> <b><?= e($a['supervisor'] ?: '—') ?></b></div>
                    <div class="col-md-4"><span class="text-muted">Segmento:</span> <b><?= e($a['segmento'] ?: '—') ?></b></div>
                    <div class="col-md-6"><span class="text-muted">Pedido Accademia (Cadastro de Distribuidores):</span>
                        <b><?= $a['accademia_cadastro'] === null ? 'não localizado' : e($a['accademia_cadastro']) ?></b>
                        <span class="text-muted">→ ST pela coluna <b><?= $a['com_academia'] ? 'c/ Academia' : 's/ Academia' ?></b></span>
                    </div>
                    <div class="col-md-6">
                        <?php if (!empty($a['instrucoes'])): $ultInstr = $a['instrucoes'][0]; ?>
                            <span class="text-muted"><i class="bi bi-chat-left-text me-1"></i>Última Instrução (<?= e($ultInstr['data']) ?> — <?= e($ultInstr['usuario']) ?>):</span>
                            <b><?= e($ultInstr['texto']) ?></b>
                        <?php else: ?>
                            <span class="text-muted small"><i class="bi bi-chat-left-text me-1"></i>Sem instruções cadastradas.</span>
                        <?php endif; ?>
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
                                    <input type="checkbox" class="form-check-input sel-pedido" value="<?= $nid ?>"
                                           data-sidped="<?= e($l['pedido_interno'] ?? '') ?>"
                                           data-avista="<?= !empty($l['is_a_vista']) ? '1' : '0' ?>"
                                           data-tipo="<?= e($l['tipo']) ?>"
                                           data-numero="<?= e($l['numero']) ?>"
                                           <?= !empty($l['conforme']) ? 'checked' : '' ?>>
                                </td>
                                <td class="fw-semibold">
                                    <?php if (!empty($l['itens_am'])): ?>
                                        <button type="button" class="btn btn-link btn-sm p-0 fw-semibold" data-bs-toggle="modal" data-bs-target="#mdlPed-<?= $nid ?>" title="Ver detalhe do pedido">
                                            <?= e($l['numero']) ?> <i class="bi bi-eye small"></i>
                                        </button>
                                    <?php else: ?>
                                        <?= e($l['numero']) ?>
                                    <?php endif; ?>
                                </td>
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
                <div class="d-flex align-items-center gap-2 mb-3">
                    <button type="button" class="btn btn-sm btn-success btn-liberar" data-codigo="<?= e($a['codigo']) ?>">
                        <i class="bi bi-unlock me-1"></i>Liberar Selecionados
                    </button>
                    <span class="text-muted small">Libera no A&amp;M os pedidos marcados acima que <b>não</b> são à vista (por enquanto, à vista só libera por aqui se for Bonificação).</span>
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
                                <tr><td>+ Total Geral do cliente <span class="text-muted">(Pedidos + Cheques + Acordos + Cursos)</span></td><td class="text-end"><?= moedaBR($a['total_geral_cliente']) ?></td></tr>
                                <tr class="fw-semibold border-top"><td>= Total a considerar</td><td class="text-end <?= $a['check_dentro_limite'] ? '' : 'text-danger' ?>"><?= moedaBR($a['check2_base']) ?></td></tr>
                                <?php $limiteRestante = $a['limite_num'] - $a['check2_base']; ?>
                                <tr class="fw-semibold"><td>Limite de Crédito − Total</td><td class="text-end <?= $limiteRestante >= 0 ? 'text-success' : 'text-danger' ?>"><?= moedaBR($limiteRestante) ?></td></tr>
                            </table>
                            <div class="small <?= $a['check_dentro_limite'] ? 'text-success' : 'text-danger' ?>">
                                <?php if ($a['sem_financiar']): ?>Nada a considerar (pedidos V à vista/sem V e sem Total Geral) — ok.
                                <?php elseif ($a['check_dentro_limite']): ?>O total (aguardando + Total Geral) cabe no limite.
                                <?php elseif ($a['limite_num'] <= 0): ?>Limite de crédito não cadastrado.
                                <?php else: ?>Excede o limite em <?= moedaBR($a['check2_base'] - $a['limite_num']) ?>.<?php endif; ?>
                            </div>
                            <div class="small text-muted mt-1">"Total Geral" lido do painel da tela "Pedidos Aguardando liberação" (PD050P) do A&amp;M, filtrada pelo Codigo Distribuidor.</div>
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
                                <?= $okIcon(!$a['tem_item_fora_campanha']) ?>
                                4) Campanhas
                            </div>
                            <?php if (!empty($a['campanhas_resumo'])):
                                $numFmt = function ($v) { return rtrim(rtrim(number_format((float)$v, 2, ',', '.'), '0'), ','); };
                                $faixaLabel = function ($cr) use ($numFmt) {
                                    if ($cr['faixa_min'] === null) return '—';
                                    $fmt1 = $cr['criterio'] === 'valor' ? moedaBR($cr['faixa_min']) : $numFmt($cr['faixa_min']) . ' ' . $cr['unidade'];
                                    if ($cr['faixa_max'] === null) return 'Acima de ' . $fmt1;
                                    $fmt2 = $cr['criterio'] === 'valor' ? moedaBR($cr['faixa_max']) : $numFmt($cr['faixa_max']) . ' ' . $cr['unidade'];
                                    return $fmt1 . ' a ' . $fmt2;
                                };
                            ?>
                                <div class="small text-muted mb-1">Campanhas atingidas:</div>
                                <div class="table-responsive">
                                <table class="table table-sm mb-2">
                                    <thead class="text-muted small"><tr><th class="fw-normal">Campanha</th><th class="text-end fw-normal">Atingido</th><th class="text-end fw-normal">Faixa</th><th class="text-end fw-normal">Benefício</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($a['campanhas_resumo'] as $cr): ?>
                                        <tr>
                                            <td><?= e($cr['nome']) ?></td>
                                            <td class="text-end">
                                                <?= $cr['criterio'] === 'valor' ? moedaBR($cr['agregado']) : (int)$cr['agregado'] . ' ' . e($cr['unidade']) ?>
                                            </td>
                                            <td class="text-end text-muted small"><?= $faixaLabel($cr) ?></td>
                                            <td class="text-end fw-semibold <?= $cr['percentual'] > 0 ? 'text-success' : 'text-muted' ?>"><?= $pct($cr['percentual']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                                </div>
                            <?php endif; ?>
                            <?php if (!$a['tem_item_fora_campanha']): ?>
                                <div class="small <?= $a['campanhas_resumo'] ? 'text-success' : 'text-muted' ?>">
                                    <?= $a['campanhas_resumo'] ? 'Descontos de campanha corretos.' : 'Nenhuma campanha aplicável nos pedidos.' ?>
                                </div>
                            <?php else: ?>
                                <div class="small text-danger mb-2">Pedidos com desconto fora de campanha (não atendem ao requisito) — clique para ver os produtos:</div>
                                <?php foreach ($a['linhas'] as $l): $nid = e($a['codigo'] . '-' . $l['numero']); if (!empty($l['check_campanha'])) continue; ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger py-0 me-1 mb-1" data-bs-toggle="modal" data-bs-target="#mdlCamp-<?= $nid ?>">
                                        <i class="bi bi-eye"></i> Pedido <?= e($l['numero']) ?><?php if (!empty($l['itens_fora_campanha'])): ?> (<?= count($l['itens_fora_campanha']) ?> produto/s)<?php endif; ?>
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
            <?php if (!empty($l['itens_am'])): ?>
            <div class="modal fade" id="mdlPed-<?= $nid ?>" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Pedido <?= e($l['numero']) ?> — detalhe (sistema A&amp;M)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex flex-wrap gap-3 mb-3 small">
                        <span><strong>Data:</strong> <?= e($l['data']) ?></span>
                        <span><strong>Tipo:</strong> <?= $l['tipo'] === 'V' ? 'Venda' : 'Bonificação' ?></span>
                        <span><strong>Valor:</strong> <?= e($l['valor']) ?></span>
                        <span><strong>UF:</strong> <?= e($l['uf'] ?: '—') ?></span>
                        <span><strong>Forma Pagto:</strong> <?= e($l['forma'] ?: '—') ?></span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr><th>Código</th><th>Produto</th><th class="text-end">Qtd</th>
                                    <th class="text-end">% Descto</th><th class="text-end">% Descto ST</th>
                                    <th class="text-end">% Negociação</th><th class="text-end">% Diretoria</th>
                                    <th class="text-end">Valor Total</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($l['itens_am'] as $it): ?>
                                <tr>
                                    <td><?= e($it['codigo']) ?></td><td><?= e($it['nome']) ?></td>
                                    <td class="text-end"><?= (int)$it['qtd'] ?></td>
                                    <td class="text-end"><?= $pct($it['pct_descto']) ?></td>
                                    <td class="text-end"><?= $pct($it['pct_descto_st']) ?></td>
                                    <td class="text-end"><?= $pct($it['pct_negociacao']) ?></td>
                                    <td class="text-end <?= $it['pct_diretoria'] > 0.005 ? 'fw-semibold text-danger' : '' ?>"><?= $pct($it['pct_diretoria']) ?></td>
                                    <td class="text-end"><?= moedaBR($it['valor_total']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
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
            <?php if (!empty($l['tem_item_fora_campanha']) || !empty($l['campanhas_atingidas']) || !empty($l['bonificacoes_campanha'])): ?>
            <div class="modal fade" id="mdlCamp-<?= $nid ?>" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Pedido <?= e($l['numero']) ?> — Campanhas</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if (empty($l['eh_bf'])): ?>
                        <div class="small text-muted mb-2">
                            <i class="bi bi-info-circle me-1"></i>Obs deste pedido (Consulta/Reimprime) <?= $l['obs'] ? ('é "' . e($l['obs']) . '"') : 'está vazio' ?> — não começa com "BF", então nenhuma campanha se aplica.
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($l['itens_fora_campanha'])): ?>
                        <div class="fw-semibold text-danger mb-2"><i class="bi bi-exclamation-triangle-fill me-1"></i>Desconto fora de campanha</div>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm mb-0">
                                <thead class="table-light"><tr><th>Código</th><th>Produto</th><th class="text-end">Qtd</th><th class="text-end">% Diretoria</th><th class="text-end">Esperado</th><th>Motivo</th></tr></thead>
                                <tbody>
                                <?php foreach ($l['itens_fora_campanha'] as $it): ?>
                                    <tr class="table-danger">
                                        <td><?= e($it['codigo']) ?></td><td><?= e($it['nome']) ?></td>
                                        <td class="text-end"><?= (int)$it['qtd'] ?></td>
                                        <td class="text-end fw-semibold text-danger"><?= $pct($it['pct_diretoria']) ?></td>
                                        <td class="text-end"><?= $pct($it['percentual_esperado']) ?></td>
                                        <td class="small"><?php
                                            if ($it['motivo'] === 'sem_bf') echo 'Pedido sem "BF" no Obs — nenhuma campanha se aplica';
                                            elseif ($it['motivo'] === 'sem_campanha') echo 'Produto não pertence a nenhuma campanha';
                                            else echo 'Acima da faixa de ' . e($it['campanha_nome']);
                                        ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($l['campanhas_atingidas'])): ?>
                        <div class="fw-semibold mb-2"><i class="bi bi-check-circle text-success me-1"></i>Campanhas atingidas neste pedido</div>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm mb-0">
                                <thead class="table-light"><tr><th>Campanha</th><th>Critério</th><th class="text-end">Atingido</th><th class="text-end">% Diretoria esperado</th><th class="text-end">Itens</th></tr></thead>
                                <tbody>
                                <?php foreach ($l['campanhas_atingidas'] as $ca): ?>
                                    <tr>
                                        <td><?= e($ca['nome']) ?></td>
                                        <td><?= $ca['criterio'] === 'valor' ? 'Valor' : 'Quantidade' ?></td>
                                        <td class="text-end"><?= $ca['criterio'] === 'valor' ? moedaBR($ca['agregado']) : (int)$ca['agregado'] ?> <span class="text-muted small"><?= e($ca['unidade']) ?></span></td>
                                        <td class="text-end fw-semibold <?= $ca['percentual_esperado'] > 0 ? 'text-success' : 'text-muted' ?>"><?= $pct($ca['percentual_esperado']) ?></td>
                                        <td class="text-end"><?= count($ca['itens']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($l['bonificacoes_campanha'])): ?>
                        <div class="fw-semibold mb-2"><i class="bi bi-gift me-1"></i>Bonificação</div>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead class="table-light"><tr><th>Campanha</th><th class="text-end">Comprado</th><th>Produto bônus</th><th class="text-end">Esperado</th><th class="text-end">Encontrado</th><th class="text-center">OK</th></tr></thead>
                                <tbody>
                                <?php foreach ($l['bonificacoes_campanha'] as $b): ?>
                                    <tr class="<?= $b['ok'] ? '' : 'table-danger' ?>">
                                        <td><?= e($b['nome']) ?></td>
                                        <td class="text-end"><?= (int)$b['qtd_trigger'] ?></td>
                                        <td><?= e($b['produto_bonus_codigo']) ?> — <?= e($b['produto_bonus_nome']) ?></td>
                                        <td class="text-end"><?= (int)$b['qtd_bonus_esperada'] ?></td>
                                        <td class="text-end"><?= (int)$b['qtd_bonus_encontrada'] ?></td>
                                        <td class="text-center"><?= $okIcon($b['ok']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
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

<!-- Modal: Log de Liberações (pedidos liberados no A&M pelo SisPed) -->
<div class="modal fade" id="mdlLogLiberacoes" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title fw-bold"><i class="bi bi-journal-text me-2"></i>Log de Liberações</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <p class="text-muted small">Últimas <?= count($logLiberacoes) ?> liberações tentadas pelo SisPed no sistema A&amp;M (mais recentes primeiro).</p>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light" style="position:sticky;top:0">
                        <tr><th>Data/Hora</th><th>Pedido</th><th>Código</th><th>Usuário</th><th>Status</th><th>Resposta / Erro</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($logLiberacoes as $lg):
                        $badge = ['liberado' => 'success', 'erro' => 'danger', 'pulado_avista' => 'secondary'][$lg['status']] ?? 'secondary';
                        $label = ['liberado' => 'Liberado', 'erro' => 'Erro', 'pulado_avista' => 'Pulado (à vista)'][$lg['status']] ?? $lg['status'];
                    ?>
                        <tr>
                            <td class="small text-nowrap"><?= e(date('d/m/Y H:i', strtotime($lg['created_at']))) ?></td>
                            <td><?= e($lg['numero_pedido'] ?: '—') ?><br><span class="text-muted small">SidPed <?= e($lg['sid_ped']) ?></span></td>
                            <td><?= e($lg['codigo_cliente'] ?: '—') ?></td>
                            <td class="small"><?= e($lg['usuario_nome'] ?: '—') ?></td>
                            <td><span class="badge bg-<?= $badge ?>"><?= e($label) ?></span></td>
                            <td class="small" style="max-width:340px;overflow:auto"><?= e(mb_strimwidth(strip_tags((string)$lg['resposta']), 0, 300, '…')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$logLiberacoes): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">Nenhuma liberação registrada ainda.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
        </div>
    </div>
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

document.addEventListener('click', function (e) {
    var btn = e.target.closest('.btn-liberar');
    if (!btn) return;
    var codigo = btn.dataset.codigo;
    var card = document.getElementById('cod-' + codigo);
    if (!card) return;

    var itens = [];
    var puladosAvista = 0;
    card.querySelectorAll('.sel-pedido:checked').forEach(function (c) {
        // À vista não libera por aqui, exceto pedido de Bonificação (tipo B) — sempre pode.
        if (c.dataset.avista === '1' && c.dataset.tipo !== 'B') { puladosAvista++; return; }
        itens.push({ sidped: c.dataset.sidped, numero: c.dataset.numero, tipo: c.dataset.tipo, avista: c.dataset.avista });
    });
    if (itens.length === 0) {
        alert(puladosAvista > 0
            ? 'Os pedidos marcados são todos à vista — por enquanto não são liberados por aqui.'
            : 'Marque ao menos um pedido para liberar.');
        return;
    }
    var msg = 'Confirma a liberação de ' + itens.length + ' pedido(s) no sistema A&M?\n\n'
        + itens.map(function (i) { return '- ' + i.numero; }).join('\n')
        + (puladosAvista > 0 ? '\n\n(' + puladosAvista + ' pedido(s) à vista marcado(s) serão ignorados.)' : '');
    if (!confirm(msg)) return;

    var orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Liberando...';

    var fd = new FormData();
    fd.append('acao', 'liberar_pedidos');
    fd.append('codigo', codigo);
    itens.forEach(function (i, idx) {
        fd.append('itens[' + idx + '][sidped]', i.sidped);
        fd.append('itens[' + idx + '][numero]', i.numero);
        fd.append('itens[' + idx + '][tipo]', i.tipo || '');
        fd.append('itens[' + idx + '][avista]', i.avista === '1' ? '1' : '0');
    });

    fetch(window.location.pathname + window.location.search, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var res = data.resultados || [];
            var okCount  = res.filter(function (r) { return r.status === 'liberado'; }).length;
            var errCount = res.filter(function (r) { return r.status === 'erro'; }).length;
            var texto = okCount + ' pedido(s) liberado(s) com sucesso.';
            if (errCount > 0) texto += ' ' + errCount + ' com erro — confira no "Log de Liberações".';
            alert(texto);

            // Tira da página os pedidos já liberados com sucesso, pra não correr o risco de
            // alguém clicar "Liberar" de novo em cima do mesmo pedido.
            res.forEach(function (r) {
                if (r.status !== 'liberado') return;
                var chk = card.querySelector('.sel-pedido[data-sidped="' + r.sidped + '"]');
                var row = chk ? chk.closest('tr') : null;
                if (row) row.remove();
            });
            atualizaContagem();

            btn.disabled = false;
            btn.innerHTML = orig;
        })
        .catch(function () {
            alert('Erro de comunicação ao tentar liberar. Confira o "Log de Liberações" antes de tentar de novo.');
            btn.disabled = false;
            btn.innerHTML = orig;
        });
});
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
