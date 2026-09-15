<?php
require_once __DIR__ . '/../../config.php';
requireAdmin();   // comercial, financeiro, supervisor e TI (o financeiro acessa pelo menu Financeiro)

// "Print" da tela do A&M guardado no log (?ver=<id>&tela=validar|conclui): devolve o HTML cru num
// sandbox sem scripts/forms, com <base> no A&M para o CSS e as imagens da tela carregarem.
if (isset($_GET['ver'], $_GET['tela']) && in_array($_GET['tela'], ['validar', 'conclui'], true)) {
    $st = db()->prepare('SELECT html_' . $_GET['tela'] . ' FROM descontos_diretoria_am_logs WHERE id = ?');
    $st->execute([(int)$_GET['ver']]);
    $html = (string)$st->fetchColumn();
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Security-Policy: sandbox');
    header('X-Content-Type-Options: nosniff');
    if ($html === '') { echo '<p style="font:14px sans-serif;color:#888">Tela não registrada.</p>'; exit; }
    // Já convertido para UTF-8 em aplicarDescontoDiretoriaAEM(): tira o charset ISO-8859-1 original.
    echo '<base href="' . e(rtrim(AEM_URL, '/')) . '/">' . preg_replace('/<meta[^>]+charset[^>]*>/i', '', $html);
    exit;
}

// Log da gravação do % Diretoria direto no pedido do A&M ("Importa Pedido BF" com
// "Gravar no A&M") — tabela descontos_diretoria_am_logs, ver aplicarDescontoDiretoriaAEM().

$ini    = $_GET['ini'] ?? date('Y-m-01');
$fim    = $_GET['fim'] ?? date('Y-m-t');
$status = $_GET['status'] ?? '';
$numero = preg_replace('/\D/', '', (string)($_GET['numero'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ini)) $ini = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fim)) $fim = date('Y-m-t');
if (!in_array($status, ['', 'gravado', 'parcial', 'erro'], true)) $status = '';

$where  = ['DATE(l.created_at) BETWEEN ? AND ?'];
$params = [$ini, $fim];
if ($status !== '') { $where[] = 'l.status = ?'; $params[] = $status; }
if ($numero !== '') { $where[] = '(l.numero_pedido = ? OR l.pedido_interno = ?)'; $params[] = $numero; $params[] = $numero; }

$q = db()->prepare('SELECT l.id, l.numero_pedido, l.sid_ped, l.pedido_interno, l.pedido_sisped, l.usuario_nome,
           l.itens, l.status, l.mensagem, l.resposta, l.created_at,
           l.html_validar IS NOT NULL AS tem_validar, l.html_conclui IS NOT NULL AS tem_conclui,
           p.numero_pedido AS numero_sisped
    FROM descontos_diretoria_am_logs l
    LEFT JOIN pedidos p ON p.id = l.pedido_sisped
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY l.id DESC LIMIT 500');
$q->execute($params);
$rows = $q->fetchAll();

// Texto "legível" de uma resposta do A&M: a tela devolve HTML + JS; o que interessa
// vem depois de "Mensagem:" (quando houver).
$textoAM = function (?string $s): string {
    $t = html_entity_decode(strip_tags(preg_replace('#<(script|style)\b.*?</\1>#is', ' ', (string)$s)), ENT_QUOTES, 'UTF-8');
    if (($p = mb_stripos($t, 'Mensagem:')) !== false) $t = mb_substr($t, $p + 9);
    return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $t));
};

$logs = [];
$tot  = ['total' => 0, 'gravado' => 0, 'parcial' => 0, 'erro' => 0, 'concluido' => 0, 'conclui_falhou' => 0];
foreach ($rows as $r) {
    $resp = (string)$r['resposta'];

    $pv = strpos($resp, '=== VALIDAR ===');
    $pc = strpos($resp, '=== CONCLUI ===');
    $validar = ($pv !== false) ? trim(substr($resp, $pv + 15, ($pc !== false ? $pc : strlen($resp)) - $pv - 15)) : '';
    $conclui = ($pc !== false) ? trim(substr($resp, $pc + 15)) : '';

    // O resumo gravado é só o começo do texto da tela (pode nem chegar à mensagem); "gravado" já
    // implica validação OK em aplicarDescontoDiretoriaAEM().
    $validado = $r['status'] === 'gravado' || preg_match('/Validad[ao]\s+com\s+Sucesso/i', $validar);
    // null = "Conclui Pedido" não foi executado (validação não passou / item com erro / resposta truncada).
    // Registros novos trazem "### CONCLUI resultado: ..." (resposta sem erro + situação conferida no
    // grid). Nos demais: ERRO na resposta = falhou; "Aguarde... Processando" + PDF = concluído.
    $concluiInfo = null;
    if (preg_match('/### CONCLUI resultado: (\S+)(.*)/u', $resp, $mg)) {
        $concluido   = $mg[1] === 'concluído';
        $concluiInfo = trim($mg[1] . $mg[2]);   // inclui "Resumo dos Pedidos Colocados: ..." e a situação
    }
    elseif ($conclui === '') $concluido = null;
    else $concluido = !preg_match('/erro|inv[aá]lid|permiss/i', $textoAM($conclui));

    $obsEnviada = preg_match('/### OBS enviada: (.*)/', $resp, $mo) ? trim($mo[1]) : null;
    $obsOk = null;
    if ($obsEnviada !== null) {
        $obsResp = preg_match('/### OBS resposta: (.*)/', $resp, $mr) ? $mr[1] : '';
        $obsOk = !preg_match('/erro|inv[aá]lid|permiss|negad/i', $obsResp);
    }

    $itens = json_decode((string)$r['itens'], true) ?: [];
    $alterados = array_filter($itens, fn($i) => abs((float)$i['de'] - (float)$i['para']) > 0.01);
    $aplicados = array_filter($alterados, fn($i) => !empty($i['aplicado']));

    $msg = '';
    if ($r['status'] !== 'gravado') $msg = mb_substr($textoAM($r['mensagem']), 0, 300);

    $logs[] = [
        'row'        => $r,
        'validado'   => $validado,
        'validar'    => mb_substr($textoAM($validar), 0, 300),
        'concluido'  => $concluido,
        'conclui'    => mb_substr($concluiInfo ?? $textoAM($conclui), 0, 300),
        'obs'        => $obsEnviada,
        'obs_ok'     => $obsOk,
        'itens'      => $itens,
        'n_alterados'=> count($alterados),
        'n_aplicados'=> count($aplicados),
        'msg'        => $msg,
    ];
    $tot['total']++;
    $tot[$r['status']]++;
    if ($concluido === true)  $tot['concluido']++;
    if ($concluido === false) $tot['conclui_falhou']++;
}

$pctFmt = fn($v) => rtrim(rtrim(number_format((float)$v, 2, ',', '.'), '0'), ',') . '%';
$badgeStatus = ['gravado' => 'success', 'parcial' => 'warning', 'erro' => 'danger'];

$pageTitle = 'Log de Descontos A&M';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-journal-text me-2"></i>Log de Descontos A&amp;M</h4>
        <p class="text-muted small mb-0">
            Cada gravação do % Diretoria no pedido do A&amp;M feita pelo <strong>“Importa Pedido BF”</strong>
            (opção “Gravar o % Diretoria também no pedido do A&amp;M”): itens alterados, aba
            <strong>Validar</strong> e <strong>Conclui Pedido</strong>.
        </p>
    </div>
    <?php if (usuario()['tipo'] !== 'financeiro'): // o hub Relatórios A&M é só do Comercial ?>
    <a href="<?= BASE_URL ?>/admin/relatorios/am.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Relatórios A&amp;M
    </a>
    <?php endif; ?>
</div>

<form class="card shadow-sm border-0 mb-3 p-3">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2">
            <label class="form-label fw-semibold small mb-1">Data inicial</label>
            <input type="date" name="ini" value="<?= e($ini) ?>" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label fw-semibold small mb-1">Data final</label>
            <input type="date" name="fim" value="<?= e($fim) ?>" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label fw-semibold small mb-1">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">Todos</option>
                <?php foreach (['gravado' => 'Gravado', 'parcial' => 'Parcial', 'erro' => 'Erro'] as $k => $v): ?>
                <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label fw-semibold small mb-1">Nº pedido A&amp;M (cliente ou interno)</label>
            <input type="text" name="numero" value="<?= e($numero) ?>" class="form-control form-control-sm" inputmode="numeric">
        </div>
        <div class="col-12 col-md-2">
            <button class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filtrar</button>
        </div>
    </div>
</form>

<div class="row g-3 mb-4">
    <?php
    $resumo = [
        ['Gravações', $tot['total'], 'secondary'],
        ['Gravado (validado)', $tot['gravado'], 'success'],
        ['Parcial / Erro', $tot['parcial'] + $tot['erro'], 'danger'],
        ['Conclui Pedido OK', $tot['concluido'], 'primary'],
        ['Conclui Pedido c/ erro', $tot['conclui_falhou'], 'warning'],
    ];
    foreach ($resumo as $r): ?>
    <div class="col-6 col-md">
        <div class="card shadow-sm border-0 border-start border-4 border-<?= $r[2] ?> h-100">
            <div class="card-body py-3">
                <div class="text-muted small fw-semibold text-uppercase"><?= e($r[0]) ?></div>
                <div class="fs-4 fw-bold text-<?= $r[2] ?>"><?= (int)$r[1] ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php if (count($rows) === 500): ?>
<div class="alert alert-light border small py-2">
    <i class="bi bi-info-circle me-1"></i>Mostrando os 500 registros mais recentes do filtro — refine o período para ver os demais.
</div>
<?php endif; ?>

<div class="card shadow-sm border-0">
    <div class="card-body p-0"><div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0" style="font-size:.85rem">
            <thead class="table-light">
                <tr>
                    <th>Data</th>
                    <th>Pedido A&amp;M</th>
                    <th>Pedido SisPed</th>
                    <th>Usuário</th>
                    <th class="text-center">Itens alterados</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Validar</th>
                    <th class="text-center">Conclui Pedido</th>
                    <th class="text-center">Obs</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$logs): ?>
                <tr><td colspan="10" class="text-center text-muted py-5">Nenhuma gravação no período.</td></tr>
            <?php endif; ?>
            <?php foreach ($logs as $lg): $r = $lg['row']; $det = 'det-' . (int)$r['id']; ?>
                <tr>
                    <td class="text-nowrap"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
                    <td class="text-nowrap">
                        <?= e($r['numero_pedido'] ?: '—') ?>
                        <?php if ($r['pedido_interno']): ?><div class="text-muted small">interno <?= e($r['pedido_interno']) ?></div><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['pedido_sisped']): ?>
                        <a href="<?= BASE_URL ?>/admin/pedido.php?id=<?= (int)$r['pedido_sisped'] ?>"><?= e($r['numero_sisped'] ?: '#' . $r['pedido_sisped']) ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td><?= e($r['usuario_nome'] ?: '—') ?></td>
                    <td class="text-center"><?= $lg['n_aplicados'] ?> / <?= $lg['n_alterados'] ?> <span class="text-muted small">(<?= count($lg['itens']) ?> itens)</span></td>
                    <td class="text-center"><span class="badge bg-<?= $badgeStatus[$r['status']] ?? 'secondary' ?>"><?= e(ucfirst($r['status'])) ?></span></td>
                    <td class="text-center">
                        <?= $lg['validado'] ? '<i class="bi bi-check-circle-fill text-success" title="Validado com Sucesso"></i>'
                                            : '<i class="bi bi-x-circle-fill text-danger" title="Não validado"></i>' ?>
                    </td>
                    <td class="text-center">
                        <?php if ($lg['concluido'] === true): ?>
                            <span class="badge bg-success">Concluído</span>
                        <?php elseif ($lg['concluido'] === false): ?>
                            <span class="badge bg-danger" title="<?= e($lg['conclui']) ?>">Erro</span>
                        <?php else: ?>
                            <span class="badge bg-light text-muted border">Não executado</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($lg['obs'] === null): ?>—
                        <?php else: ?>
                            <i class="bi <?= $lg['obs_ok'] ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' ?>" title="<?= e($lg['obs']) ?>"></i>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#<?= $det ?>">
                            <i class="bi bi-chevron-down"></i>
                        </button>
                    </td>
                </tr>
                <?php if ($lg['msg'] !== '' || $lg['concluido'] === false): ?>
                <tr class="table-borderless">
                    <td colspan="10" class="pt-0 small text-danger">
                        <?php if ($lg['msg'] !== ''): ?><div><i class="bi bi-exclamation-triangle me-1"></i><?= e($lg['msg']) ?></div><?php endif; ?>
                        <?php if ($lg['concluido'] === false): ?><div><i class="bi bi-exclamation-triangle me-1"></i>Conclui Pedido: <?= e($lg['conclui']) ?></div><?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
                <tr class="collapse" id="<?= $det ?>">
                    <td colspan="10" class="bg-body-tertiary">
                        <div class="row g-3 p-2">
                            <div class="col-lg-6">
                                <div class="fw-semibold small mb-1">Itens (% Diretoria)</div>
                                <table class="table table-sm table-bordered mb-0 bg-body" style="font-size:.8rem">
                                    <thead class="table-light"><tr><th>Seq</th><th>Código</th><th class="text-end">De</th><th class="text-end">Para</th><th>Resultado</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($lg['itens'] as $it): $mudou = abs((float)$it['de'] - (float)$it['para']) > 0.01; ?>
                                        <tr class="<?= $mudou && empty($it['aplicado']) ? 'table-danger' : '' ?>">
                                            <td><?= e($it['seq'] ?? '') ?></td>
                                            <td><?= e($it['codigo'] ?? '') ?></td>
                                            <td class="text-end"><?= $pctFmt($it['de']) ?></td>
                                            <td class="text-end fw-semibold"><?= $pctFmt($it['para']) ?></td>
                                            <td><?= !$mudou ? '<span class="text-muted">sem alteração</span>'
                                                  : (!empty($it['aplicado']) ? '<span class="text-success">gravado</span>'
                                                  : '<span class="text-danger">' . e($it['resposta'] ?? 'falhou') . '</span>') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="col-lg-6 small">
                                <?php if ($lg['obs'] !== null): ?>
                                <div class="mb-2"><span class="fw-semibold">Obs enviada ao A&amp;M:</span> <?= e($lg['obs']) ?></div>
                                <?php endif; ?>
                                <div class="mb-2"><span class="fw-semibold">Validar:</span> <?= e($lg['validar'] ?: '—') ?></div>
                                <div class="mb-2"><span class="fw-semibold">Conclui Pedido:</span> <?= e($lg['conclui'] ?: 'não executado') ?></div>
                                <?php if ($r['sid_ped']): ?><div class="mb-2 text-muted">SidPed <?= e($r['sid_ped']) ?></div><?php endif; ?>
                                <details>
                                    <summary class="text-muted">Resposta bruta do A&amp;M</summary>
                                    <pre class="bg-body border rounded p-2 mt-1 mb-0" style="max-height:320px;overflow:auto;white-space:pre-wrap;font-size:.72rem"><?= e($r['resposta']) ?></pre>
                                </details>
                            </div>
                            <?php $urlTela = BASE_URL . '/admin/relatorios/log-descontos-am.php?ver=' . (int)$r['id'] . '&tela='; ?>
                            <?php if ($r['tem_validar']): ?>
                            <div class="col-12">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-semibold small"><i class="bi bi-camera me-1"></i>Tela do A&amp;M antes do “Conclui Pedido” (aba Validar)</span>
                                    <a href="<?= e($urlTela . 'validar') ?>" target="_blank" class="small">abrir em nova aba <i class="bi bi-box-arrow-up-right"></i></a>
                                </div>
                                <iframe class="w-100 border rounded bg-white" style="height:460px" sandbox title="Tela Validar do A&amp;M"
                                        data-src="<?= e($urlTela . 'validar') ?>"></iframe>
                            </div>
                            <?php endif; ?>
                            <?php if ($r['tem_conclui']): ?>
                            <div class="col-12">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-semibold small"><i class="bi bi-camera me-1"></i>Tela devolvida pelo A&amp;M ao clicar em “Conclui Pedido”</span>
                                    <a href="<?= e($urlTela . 'conclui') ?>" target="_blank" class="small">abrir em nova aba <i class="bi bi-box-arrow-up-right"></i></a>
                                </div>
                                <iframe class="w-100 border rounded bg-white" style="height:240px" sandbox title="Resposta do Conclui Pedido"
                                        data-src="<?= e($urlTela . 'conclui') ?>"></iframe>
                            </div>
                            <?php endif; ?>
                            <?php if (!$r['tem_validar'] && !$r['tem_conclui']): ?>
                            <div class="col-12 small text-muted">
                                <i class="bi bi-camera me-1"></i>Sem print da tela: registros anteriores a esta versão guardam só o texto da resposta.
                            </div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
</div>
<script>
// Os "prints" só carregam ao expandir a linha (evita 2 telas do A&M por registro no carregamento).
document.addEventListener('show.bs.collapse', ev => {
    ev.target.querySelectorAll('iframe[data-src]').forEach(f => { f.src = f.dataset.src; f.removeAttribute('data-src'); });
});
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
