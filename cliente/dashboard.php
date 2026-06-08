<?php
require_once __DIR__ . '/../config.php';
requireCliente();
$u = usuario();

$totais = db()->prepare('SELECT
    COUNT(*) AS total,
    SUM(status = "comercial") AS comercial,
    SUM(status = "financeiro") AS financeiro,
    SUM(status = "faturado") AS faturados,
    SUM(status = "reprovado") AS reprovados,
    COALESCE(SUM(CASE WHEN status="faturado" THEN valor_total ELSE 0 END),0) AS valor_faturado
FROM pedidos WHERE cliente_id = ?');
$totais->execute([$u['id']]);
$t = $totais->fetch();

$recentes = db()->prepare('SELECT p.*, pr.descricao_pt FROM pedidos p
    LEFT JOIN produtos pr ON pr.id = p.produto_id
    WHERE p.cliente_id = ? ORDER BY p.created_at DESC LIMIT 5');
$recentes->execute([$u['id']]);
$recentes = $recentes->fetchAll();

$boletos = db()->prepare('SELECT
    SUM(situacao="aberto") AS abertos,
    SUM(situacao="vencido") AS vencidos,
    COALESCE(SUM(CASE WHEN situacao IN ("aberto","vencido") THEN valor_receber-descontos ELSE 0 END),0) AS saldo
FROM contas_receber WHERE cliente_id = ?');
$boletos->execute([$u['id']]);
$b = $boletos->fetch();

// Bônus MA: verifica se deve exibir popup (uma vez por sessão de login)
$showMaPopup = false;
$maValor     = 0;
$_maKey      = 'ma_popup_shown_' . $u['id'];
if (empty($_SESSION[$_maKey])) {
    $_SESSION[$_maKey] = true;
    $mesAtual = (int)date('n');
    $anoAtual = (int)date('Y');
    $mesPad   = $mesAtual === 1 ? 12 : $mesAtual - 1;
    $anoPad   = $mesAtual === 1 ? $anoAtual - 1 : $anoAtual;
    $maLog = db()->prepare("
        SELECT l1.acao, l1.valor_utilizado FROM bonus_ma_logs l1
        INNER JOIN (
            SELECT cliente_id, MAX(id) AS max_id FROM bonus_ma_logs
            WHERE cliente_id = ? AND mes = ? AND ano = ?
            GROUP BY cliente_id
        ) l2 ON l2.cliente_id = l1.cliente_id AND l2.max_id = l1.id
    ");
    $maLog->execute([$u['id'], $mesPad, $anoPad]);
    $maLog = $maLog->fetch();
    if ($maLog && $maLog['acao'] === 'aprovado') {
        $cliMa = db()->prepare('SELECT material_apoio FROM clientes WHERE id = ?');
        $cliMa->execute([$u['id']]);
        $maPct = (int)($cliMa->fetch()['material_apoio'] ?? 0);
        if ($maPct > 0) {
            $dtIni = sprintf('%04d-%02d-01', $anoPad, $mesPad);
            $dtFim = date('Y-m-t', mktime(0, 0, 0, $mesPad, 1, $anoPad));
            $fat = db()->prepare("SELECT COALESCE(SUM(valor_total),0) AS total FROM pedidos WHERE cliente_id=? AND status='faturado' AND DATE(data_pedido) BETWEEN ? AND ?");
            $fat->execute([$u['id'], $dtIni, $dtFim]);
            $maTotal = (float)$fat->fetch()['total'] * $maPct / 100;
            $maValor = max(0, $maTotal - (float)($maLog['valor_utilizado'] ?? 0));
            if ($maValor > 0) $showMaPopup = true;
        }
    }
}

$pageTitle = 'Dashboard';
require_once LAYOUT_PATH . '/header.php';
?>

<?php if ($showMaPopup): ?>
<div class="modal fade" id="modalBonusMA" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-success text-white border-0">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-gift-fill me-2"></i>Bônus de Material de Apoio Disponível!
                </h5>
            </div>
            <div class="modal-body text-center py-5">
                <div class="mb-3">
                    <i class="bi bi-gift text-success" style="font-size:3rem"></i>
                </div>
                <p class="fs-5 mb-2">Você possui</p>
                <div class="display-5 fw-bold text-success mb-3"><?= moedaBR($maValor) ?></div>
                <p class="text-muted mb-0">de Bônus MA para utilizar em produtos de Material de Apoio.<br>Deseja utilizá-lo agora?</p>
            </div>
            <div class="modal-footer justify-content-center gap-3 border-0 pb-4">
                <button type="button" class="btn btn-outline-secondary btn-lg px-5" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg me-2"></i>Não
                </button>
                <a href="<?= BASE_URL ?>/cliente/novo-pedido.php?modo=ma_bonus" class="btn btn-success btn-lg px-5">
                    <i class="bi bi-check-lg me-2"></i>Sim, utilizar agora
                </a>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    new bootstrap.Modal(document.getElementById('modalBonusMA')).show();
});
</script>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-0">Dashboard</h4>
        <p class="text-muted small mb-0">Bem-vindo, <?= e($u['nome']) ?>!</p>
    </div>
    <a href="<?= BASE_URL ?>/cliente/novo-pedido.php" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Novo Pedido
    </a>
</div>

<!-- Cards de resumo -->
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="card shadow-sm border-0 border-start border-4 border-primary">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Total de Pedidos</div>
                <div class="display-5 fw-bold text-primary"><?= $t['total'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card shadow-sm border-0 border-start border-4 border-warning">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Em Andamento</div>
                <div class="display-5 fw-bold" style="color:#c8880a"><?= (int)$t['comercial'] + (int)$t['financeiro'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card shadow-sm border-0 border-start border-4 border-success">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Faturados</div>
                <div class="display-5 fw-bold text-success"><?= $t['faturados'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card shadow-sm border-0 border-start border-4 border-info">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Valor Faturado</div>
                <div class="fw-bold text-info" style="font-size:1.4rem"><?= moedaBR($t['valor_faturado']) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="card shadow-sm border-0 border-start border-4 border-danger">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Boletos Vencidos</div>
                <div class="display-5 fw-bold text-danger"><?= $b['vencidos'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card shadow-sm border-0 border-start border-4 border-warning">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Boletos em Aberto</div>
                <div class="display-5 fw-bold" style="color:#c8880a"><?= $b['abertos'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card shadow-sm border-0 border-start border-4 border-secondary">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Saldo a Pagar</div>
                <div class="fw-bold text-danger" style="font-size:1.2rem"><?= moedaBR($b['saldo']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card shadow-sm border-0 border-start border-4 border-danger">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Reprovados</div>
                <div class="display-5 fw-bold text-danger"><?= $t['reprovados'] ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Últimos pedidos -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-clock-history me-2"></i>Últimos Pedidos</span>
        <a href="<?= BASE_URL ?>/cliente/meus-pedidos.php" class="btn btn-sm btn-outline-primary">Ver todos</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Nº Pedido</th>
                    <th>Produto</th>
                    <th>Data</th>
                    <th>Qtd</th>
                    <th>Valor</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($recentes): foreach ($recentes as $r): ?>
                <tr>
                    <td><strong><?= e($r['numero_pedido']) ?></strong></td>
                    <td><?= e($r['descricao_produto'] ?: ($r['descricao_pt'] ?? '-')) ?></td>
                    <td><?= dataBR($r['data_pedido']) ?></td>
                    <td><?= e($r['quantidade_total']) ?></td>
                    <td><?= moedaBR($r['valor_total']) ?></td>
                    <td><?= statusBadge($r['status']) ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Nenhum pedido encontrado.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
