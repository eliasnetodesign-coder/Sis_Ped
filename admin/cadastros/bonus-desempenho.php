<?php
require_once __DIR__ . '/../../config.php';
requireComercial();
$u = usuario();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['acao'] ?? '', ['aprovado', 'reprovado'])) {
    $cliId = (int)$_POST['cliente_id'];
    $pTri  = (int)$_POST['tri'];
    $pAno  = (int)$_POST['ano'];
    $acao  = $_POST['acao'];
    db()->prepare('INSERT INTO bonus_desempenho_logs (cliente_id, trimestre, ano, acao, usuario_nome) VALUES (?,?,?,?,?)')
       ->execute([$cliId, $pTri, $pAno, $acao, $u['nome']]);
    header('Location: ' . BASE_URL . '/admin/cadastros/bonus-desempenho.php?tri=' . $pTri . '&ano=' . $pAno);
    exit;
}

$anoAtual = (int)date('Y');
$triAtual  = (int)ceil(date('n') / 3);

$ano = isset($_GET['ano']) ? (int)$_GET['ano'] : $anoAtual;
$tri = isset($_GET['tri']) ? (int)$_GET['tri'] : $triAtual;
if ($tri < 1) $tri = 1;
if ($tri > 4) $tri = 4;

$mes1 = ($tri - 1) * 3 + 1;
$mes2 = ($tri - 1) * 3 + 2;
$mes3 =  $tri      * 3;

$dtIni = sprintf('%04d-%02d-01', $ano, $mes1);
$dtFim = date('Y-m-t', mktime(0, 0, 0, $mes3, 1, $ano));

$mesesAbr = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
$nomeMes1 = $mesesAbr[$mes1 - 1];
$nomeMes2 = $mesesAbr[$mes2 - 1];
$nomeMes3 = $mesesAbr[$mes3 - 1];

$rows = db()->prepare("
    SELECT
        c.id, c.codigo_cliente, c.razao_social, COALESCE(c.supervisor, c.vendedor) AS supervisor,
        cv.canal,
        c.desconto_cliente,
        CAST(c.bonus_desempenho AS DECIMAL(10,2)) AS bonus_pct,
        COALESCE(SUM(CASE WHEN MONTH(p.data_pedido) = ? AND p.status = 'faturado' THEN p.valor_total ELSE 0 END), 0) AS fat_mes1,
        COALESCE(SUM(CASE WHEN MONTH(p.data_pedido) = ? AND p.status = 'faturado' THEN p.valor_total ELSE 0 END), 0) AS fat_mes2,
        COALESCE(SUM(CASE WHEN MONTH(p.data_pedido) = ? AND p.status = 'faturado' THEN p.valor_total ELSE 0 END), 0) AS fat_mes3,
        COALESCE(m.meta_cliente, 0) AS meta,
        COALESCE(cr_avg.media_atrasos, 0) AS media_atrasos
    FROM clientes c
    LEFT JOIN canal_venda cv ON cv.id = c.canal_venda_id
    LEFT JOIN pedidos p ON p.cliente_id = c.id AND YEAR(p.data_pedido) = ?
    LEFT JOIN metas m ON m.cliente_id = c.id AND m.trimestre = ? AND m.ano = ?
    LEFT JOIN (
        SELECT cliente_id, ROUND(AVG(DATEDIFF(data_pagamento, data_vencimento)), 1) AS media_atrasos
        FROM contas_receber
        WHERE data_pagamento IS NOT NULL AND data_pagamento > data_vencimento
        GROUP BY cliente_id
    ) cr_avg ON cr_avg.cliente_id = c.id
    WHERE c.bonus_desempenho > 0 AND c.status = 'ativo'
    GROUP BY c.id
    ORDER BY c.razao_social
");
$rows->execute([$mes1, $mes2, $mes3, $ano, $tri, $ano]);
$rows = $rows->fetchAll();

// Último log por cliente no trimestre/ano selecionado
$logsStmt = db()->prepare("
    SELECT l1.cliente_id, l1.acao, l1.usuario_nome, l1.created_at
    FROM bonus_desempenho_logs l1
    INNER JOIN (
        SELECT cliente_id, MAX(id) AS max_id
        FROM bonus_desempenho_logs
        WHERE trimestre = ? AND ano = ?
        GROUP BY cliente_id
    ) l2 ON l2.cliente_id = l1.cliente_id AND l2.max_id = l1.id
");
$logsStmt->execute([$tri, $ano]);
$logs = [];
foreach ($logsStmt->fetchAll() as $l) $logs[$l['cliente_id']] = $l;

$totalFat1   = array_sum(array_column($rows, 'fat_mes1'));
$totalFat2   = array_sum(array_column($rows, 'fat_mes2'));
$totalFat3   = array_sum(array_column($rows, 'fat_mes3'));
$totalFat    = $totalFat1 + $totalFat2 + $totalFat3;
$totalMeta   = array_sum(array_column($rows, 'meta'));
$totalBonus  = array_sum(array_map(fn($r) => ($r['fat_mes1']+$r['fat_mes2']+$r['fat_mes3']) * $r['bonus_pct'] / 100, $rows));

$triAnteriorTri = $tri - 1 <= 0 ? 4      : $tri - 1;
$triAnteriorAno = $tri - 1 <= 0 ? $ano-1 : $ano;
$triProximoTri  = $tri + 1 > 4  ? 1      : $tri + 1;
$triProximoAno  = $tri + 1 > 4  ? $ano+1 : $ano;

$pageTitle = 'Bônus de Desempenho';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-trophy me-2"></i>Bônus de Desempenho</h4>
</div>

<!-- Filtros + Navegação -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label fw-semibold small mb-1">Trimestre</label>
                <select name="tri" class="form-select form-select-sm" style="width:110px" onchange="this.form.submit()">
                    <option value="1" <?= $tri===1?'selected':'' ?>>1ºTRI</option>
                    <option value="2" <?= $tri===2?'selected':'' ?>>2ºTRI</option>
                    <option value="3" <?= $tri===3?'selected':'' ?>>3ºTRI</option>
                    <option value="4" <?= $tri===4?'selected':'' ?>>4ºTRI</option>
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
                <a href="?tri=<?= $triAnteriorTri ?>&ano=<?= $triAnteriorAno ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-chevron-left"></i> Trimestre Anterior
                </a>
                <?php if ($ano < $anoAtual || ($ano === $anoAtual && $tri < $triAtual)): ?>
                <a href="?tri=<?= $triProximoTri ?>&ano=<?= $triProximoAno ?>" class="btn btn-sm btn-outline-secondary">
                    Próximo <i class="bi bi-chevron-right"></i>
                </a>
                <?php endif; ?>
                <?php if ($tri !== $triAtual || $ano !== $anoAtual): ?>
                <a href="?" class="btn btn-sm btn-outline-primary">Trimestre Vigente</a>
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
                <div class="text-muted small fw-semibold text-uppercase">Período</div>
                <div class="fw-bold text-primary"><?= $tri ?>ºTRI / <?= $ano ?></div>
                <div class="text-muted small"><?= $nomeMes1 ?> – <?= $nomeMes3 ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card border-0 shadow-sm border-start border-4 border-info">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Clientes</div>
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
                <div class="text-muted small fw-semibold text-uppercase">Total Bônus</div>
                <div class="fw-bold" style="color:#c8880a"><?= moedaBR($totalBonus) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Tabela -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-table me-2"></i><?= $tri ?>ºTRI/<?= $ano ?> &nbsp;
        <span class="text-muted small"><?= $nomeMes1 ?> · <?= $nomeMes2 ?> · <?= $nomeMes3 ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover table-sm mb-0" style="font-size:.85rem">
            <thead class="table-light">
                <tr>
                    <th class="text-center" style="white-space:nowrap">Código</th>
                    <th style="white-space:nowrap">Cliente</th>
                    <th style="white-space:nowrap">Supervisor</th>
                    <th class="text-center" style="white-space:nowrap">Canal</th>
                    <th class="text-center" style="white-space:nowrap">Desconto</th>
                    <th class="text-end" style="white-space:nowrap"><?= $nomeMes1 ?></th>
                    <th class="text-end" style="white-space:nowrap"><?= $nomeMes2 ?></th>
                    <th class="text-end" style="white-space:nowrap"><?= $nomeMes3 ?></th>
                    <th class="text-end" style="white-space:nowrap">Total Faturado</th>
                    <th class="text-end" style="white-space:nowrap">Meta</th>
                    <th class="text-center" style="white-space:nowrap">%BD</th>
                    <th class="text-end" style="white-space:nowrap">Valor BD</th>
                    <th class="text-center" style="white-space:nowrap">Méd. Atrasos</th>
                    <th class="text-center" style="white-space:nowrap">Ação</th>
                    <th style="white-space:nowrap">Log</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($rows): foreach ($rows as $r):
                $fatTotal = $r['fat_mes1'] + $r['fat_mes2'] + $r['fat_mes3'];
                $valorBD  = $fatTotal * $r['bonus_pct'] / 100;
            ?>
                <tr>
                    <td class="text-center"><span class="badge bg-secondary"><?= e($r['codigo_cliente']) ?></span></td>
                    <td class="fw-semibold"><?= e($r['razao_social']) ?></td>
                    <td class="text-muted"><?= e($r['supervisor'] ?: '—') ?></td>
                    <td class="text-center"><?= $r['canal'] ? '<span class="badge bg-light text-dark border">'.e($r['canal']).'</span>' : '<span class="text-muted">—</span>' ?></td>
                    <td class="text-center"><?= number_format($r['desconto_cliente'], 2) ?>%</td>
                    <td class="text-end"><?= $r['fat_mes1'] > 0 ? moedaBR($r['fat_mes1']) : '<span class="text-muted">—</span>' ?></td>
                    <td class="text-end"><?= $r['fat_mes2'] > 0 ? moedaBR($r['fat_mes2']) : '<span class="text-muted">—</span>' ?></td>
                    <td class="text-end"><?= $r['fat_mes3'] > 0 ? moedaBR($r['fat_mes3']) : '<span class="text-muted">—</span>' ?></td>
                    <td class="text-end fw-semibold"><?= moedaBR($fatTotal) ?></td>
                    <td class="text-end"><?= $r['meta'] > 0 ? moedaBR($r['meta']) : '<span class="text-muted">—</span>' ?></td>
                    <td class="text-center"><span class="badge bg-primary"><?= number_format($r['bonus_pct'], 2) ?>%</span></td>
                    <td class="text-end fw-semibold text-success"><?= moedaBR($valorBD) ?></td>
                    <td class="text-center">
                        <?php if ($r['media_atrasos'] > 0): ?>
                            <span class="badge bg-<?= $r['media_atrasos'] > 30 ? 'danger' : ($r['media_atrasos'] > 10 ? 'warning text-dark' : 'secondary') ?>">
                                <?= number_format($r['media_atrasos'], 1) ?> dias
                            </span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <div class="d-flex gap-1 flex-nowrap justify-content-center">
                            <a href="<?= BASE_URL ?>/admin/cadastros/concessao-creditos.php?cliente_id=<?= $r['id'] ?>"
                               class="btn btn-xs btn-outline-success" style="padding:.2rem .5rem;font-size:.75rem"
                               title="Conceder crédito">
                                <i class="bi bi-coin"></i>
                            </a>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="cliente_id" value="<?= $r['id'] ?>">
                                <input type="hidden" name="tri" value="<?= $tri ?>">
                                <input type="hidden" name="ano" value="<?= $ano ?>">
                                <input type="hidden" name="acao" value="aprovado">
                                <button class="btn btn-xs btn-outline-primary" style="padding:.2rem .5rem;font-size:.75rem" title="Aprovar bônus">
                                    <i class="bi bi-check-lg"></i>
                                </button>
                            </form>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="cliente_id" value="<?= $r['id'] ?>">
                                <input type="hidden" name="tri" value="<?= $tri ?>">
                                <input type="hidden" name="ano" value="<?= $ano ?>">
                                <input type="hidden" name="acao" value="reprovado">
                                <button class="btn btn-xs btn-outline-danger" style="padding:.2rem .5rem;font-size:.75rem" title="Reprovar bônus">
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
                <tr><td colspan="15" class="text-center text-muted py-4">Nenhum cliente elegível para este período.</td></tr>
            <?php endif; ?>
            </tbody>
            <?php if ($rows): ?>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td colspan="5" class="text-start">Total</td>
                    <td class="text-end"><?= moedaBR($totalFat1) ?></td>
                    <td class="text-end"><?= moedaBR($totalFat2) ?></td>
                    <td class="text-end"><?= moedaBR($totalFat3) ?></td>
                    <td class="text-end"><?= moedaBR($totalFat) ?></td>
                    <td class="text-end"><?= moedaBR($totalMeta) ?></td>
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
