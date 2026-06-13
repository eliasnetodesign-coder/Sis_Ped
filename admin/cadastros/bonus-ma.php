<?php
require_once __DIR__ . '/../../config.php';
requireComercial();
$u = usuario();

// Mês atual por padrão
$mesAtual = (int)date('n');
$anoAtual = (int)date('Y');
$mesPad   = $mesAtual;
$anoPad   = $anoAtual;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['acao'] ?? '', ['aprovado', 'reprovado'])) {
    db()->prepare('INSERT INTO bonus_ma_logs (cliente_id, mes, ano, acao, usuario_nome) VALUES (?,?,?,?,?)')
       ->execute([(int)$_POST['cliente_id'], (int)$_POST['mes'], (int)$_POST['ano'], $_POST['acao'], $u['nome']]);
    header('Location: ' . BASE_URL . '/admin/cadastros/bonus-ma.php?mes=' . (int)$_POST['mes'] . '&ano=' . (int)$_POST['ano']);
    exit;
}

$mes = isset($_GET['mes']) ? (int)$_GET['mes'] : $mesPad;
$ano = isset($_GET['ano']) ? (int)$_GET['ano'] : $anoPad;
if ($mes < 1 || $mes > 12) $mes = $mesPad;

// Mês anterior (base do faturamento para cálculo do bônus)
$fatMes   = $mes === 1 ? 12 : $mes - 1;
$fatAno   = $mes === 1 ? $ano - 1 : $ano;
$fatDtIni = sprintf('%04d-%02d-01', $fatAno, $fatMes);
$fatDtFim = date('Y-m-t', mktime(0, 0, 0, $fatMes, 1, $fatAno));
// Datas do mês de referência (apenas para exibição)
$dtIni = sprintf('%04d-%02d-01', $ano, $mes);
$dtFim = date('Y-m-t', mktime(0, 0, 0, $mes, 1, $ano));

$rows = db()->prepare("
    SELECT
        c.id, c.codigo_cliente, c.razao_social, COALESCE(c.supervisor, c.vendedor) AS supervisor,
        cv.canal,
        c.desconto_canal,
        CAST(c.material_apoio AS UNSIGNED) AS ma_pct,
        COALESCE(SUM(CASE WHEN p.status = 'faturado' THEN p.valor_total ELSE 0 END), 0) AS faturamento,
        COALESCE(cr_avg.media_atrasos, 0) AS media_atrasos
    FROM clientes c
    LEFT JOIN canal_venda cv ON cv.id = c.canal_venda_id
    LEFT JOIN pedidos p ON p.cliente_id = c.id
        AND DATE(p.data_pedido) BETWEEN ? AND ?
    LEFT JOIN (
        SELECT cliente_id, ROUND(AVG(DATEDIFF(data_pagamento, data_vencimento)), 1) AS media_atrasos
        FROM contas_receber
        WHERE data_pagamento IS NOT NULL AND data_pagamento > data_vencimento
        GROUP BY cliente_id
    ) cr_avg ON cr_avg.cliente_id = c.id
    WHERE c.material_apoio > 0 AND c.status = 'ativo'
    GROUP BY c.id
    HAVING faturamento > 0
    ORDER BY c.razao_social
");
$rows->execute([$fatDtIni, $fatDtFim]);
$rows = $rows->fetchAll();

// Último log por cliente no mês/ano selecionado
$logsStmt = db()->prepare("
    SELECT l1.cliente_id, l1.acao, l1.usuario_nome, l1.created_at
    FROM bonus_ma_logs l1
    INNER JOIN (
        SELECT cliente_id, MAX(id) AS max_id
        FROM bonus_ma_logs
        WHERE mes = ? AND ano = ?
        GROUP BY cliente_id
    ) l2 ON l2.cliente_id = l1.cliente_id AND l2.max_id = l1.id
");
$logsStmt->execute([$mes, $ano]);
$logs = [];
foreach ($logsStmt->fetchAll() as $l) $logs[$l['cliente_id']] = $l;

$totalFat   = array_sum(array_column($rows, 'faturamento'));
$totalBonus = array_sum(array_map(fn($r) => $r['faturamento'] * $r['ma_pct'] / 100, $rows));

$meses     = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
$mesAntMes = $mes === 1  ? 12 : $mes - 1;
$mesAntAno = $mes === 1  ? $ano - 1 : $ano;
$mesProxMes = $mes === 12 ? 1  : $mes + 1;
$mesProxAno = $mes === 12 ? $ano + 1 : $ano;
$ehMesPad  = ($mes === $mesPad && $ano === $anoPad);

$pageTitle = 'Bônus de Material de Apoio';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-gift me-2"></i>Bônus de Material de Apoio</h4>
</div>

<!-- Navegação -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label fw-semibold small mb-1">Mês</label>
                <select name="mes" class="form-select form-select-sm" style="width:140px" onchange="this.form.submit()">
                    <?php foreach ($meses as $i => $nm): ?>
                    <option value="<?= $i+1 ?>" <?= $mes===$i+1?'selected':'' ?>><?= $nm ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label fw-semibold small mb-1">Ano</label>
                <input type="number" name="ano" class="form-control form-control-sm" style="width:90px" value="<?= $ano ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Consultar</button>
            </div>
            <div class="col-auto ms-auto d-flex gap-2 flex-wrap">
                <a href="?mes=<?= $mesAntMes ?>&ano=<?= $mesAntAno ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-chevron-left"></i> Mês Anterior
                </a>
                <?php if ($ano < $anoAtual || ($ano === $anoAtual && $mes < $mesAtual)): ?>
                <a href="?mes=<?= $mesProxMes ?>&ano=<?= $mesProxAno ?>" class="btn btn-sm btn-outline-secondary">
                    Próximo <i class="bi bi-chevron-right"></i>
                </a>
                <?php endif; ?>
                <?php if (!$ehMesPad): ?>
                <a href="?" class="btn btn-sm btn-outline-primary">Mês Atual (Padrão)</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Cards resumo -->
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="card border-0 shadow-sm border-start border-4 border-primary">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Bônus de</div>
                <div class="fw-bold text-primary"><?= $meses[$mes-1] ?> / <?= $ano ?></div>
                <div class="text-muted small">Fat. base: <?= $meses[$fatMes-1] ?>/<?= $fatAno ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card border-0 shadow-sm border-start border-4 border-info">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Clientes Elegíveis</div>
                <div class="fw-bold text-info display-6"><?= count($rows) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card border-0 shadow-sm border-start border-4 border-success">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Total Faturado</div>
                <div class="fw-bold text-success"><?= moedaBR($totalFat) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card border-0 shadow-sm border-start border-4 border-warning">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Total Bônus MA</div>
                <div class="fw-bold" style="color:#c8880a"><?= moedaBR($totalBonus) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Tabela -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-table me-2"></i>Clientes com Bônus MA — <?= $meses[$mes-1] ?>/<?= $ano ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover table-sm mb-0" style="font-size:.85rem">
            <thead class="table-light">
                <tr>
                    <th>Código</th>
                    <th>Cliente</th>
                    <th>Supervisor</th>
                    <th>Canal</th>
                    <th class="text-center">Desconto do Canal</th>
                    <th class="text-end">Faturado (<?= $meses[$fatMes-1] ?>/<?= $fatAno ?>)</th>
                    <th class="text-center">%MA</th>
                    <th class="text-end">Valor MA</th>
                    <th class="text-center">Méd. Atr.</th>
                    <th>Ação</th>
                    <th>Log</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($rows): foreach ($rows as $r):
                $valorMA = $r['faturamento'] * $r['ma_pct'] / 100;
            ?>
                <tr>
                    <td><span class="badge bg-secondary"><?= e($r['codigo_cliente']) ?></span></td>
                    <td class="fw-semibold"><?= e($r['razao_social']) ?></td>
                    <td class="text-muted"><?= e($r['supervisor'] ?: '—') ?></td>
                    <td><?= $r['canal'] ? '<span class="badge bg-light text-dark border">'.e($r['canal']).'</span>' : '—' ?></td>
                    <td class="text-center"><?= number_format($r['desconto_canal'], 2) ?>%</td>
                    <td class="text-end fw-semibold"><?= moedaBR($r['faturamento']) ?></td>
                    <td class="text-center"><span class="badge bg-info text-dark"><?= (int)$r['ma_pct'] ?>%</span></td>
                    <td class="text-end fw-semibold text-success"><?= moedaBR($valorMA) ?></td>
                    <td class="text-center">
                        <?php if ($r['media_atrasos'] > 0): ?>
                            <span class="badge bg-<?= $r['media_atrasos'] > 30 ? 'danger' : ($r['media_atrasos'] > 10 ? 'warning text-dark' : 'secondary') ?>">
                                <?= number_format($r['media_atrasos'], 1) ?> dias
                            </span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex gap-1 flex-nowrap">
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="cliente_id" value="<?= $r['id'] ?>">
                                <input type="hidden" name="mes" value="<?= $mes ?>">
                                <input type="hidden" name="ano" value="<?= $ano ?>">
                                <input type="hidden" name="acao" value="aprovado">
                                <button class="btn btn-xs btn-outline-primary" style="padding:.2rem .5rem;font-size:.75rem" title="Aprovar bônus MA">
                                    <i class="bi bi-check-lg"></i>
                                </button>
                            </form>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="cliente_id" value="<?= $r['id'] ?>">
                                <input type="hidden" name="mes" value="<?= $mes ?>">
                                <input type="hidden" name="ano" value="<?= $ano ?>">
                                <input type="hidden" name="acao" value="reprovado">
                                <button class="btn btn-xs btn-outline-danger" style="padding:.2rem .5rem;font-size:.75rem" title="Reprovar bônus MA">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                    <td style="min-width:160px">
                        <?php if (isset($logs[$r['id']])): $lg = $logs[$r['id']]; ?>
                            <span class="badge bg-<?= $lg['acao']==='aprovado'?'success':'danger' ?> mb-1">
                                <?= $lg['acao']==='aprovado' ? 'Aprovado' : 'Reprovado' ?>
                            </span><br>
                            <span class="small text-muted"><?= e($lg['usuario_nome']) ?></span><br>
                            <span class="small text-muted"><?= date('d/m/Y H:i', strtotime($lg['created_at'])) ?></span>
                        <?php else: ?>
                            <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="11" class="text-center text-muted py-4">Nenhum cliente elegível para este período.</td></tr>
            <?php endif; ?>
            </tbody>
            <?php if ($rows): ?>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td colspan="5">Total</td>
                    <td class="text-end"><?= moedaBR($totalFat) ?></td>
                    <td></td>
                    <td class="text-end text-success"><?= moedaBR($totalBonus) ?></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
        </div>
    </div>
</div>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
