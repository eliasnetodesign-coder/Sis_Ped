<?php
require_once __DIR__ . '/../config.php';
requireLogin(); // cliente ou admin (o pedido bonificado usa o cliente_id da sessão)

$ctx = $_SESSION['bonus_selecionavel'] ?? null;
if (!$ctx || empty($ctx['campanhas'])) {
    header('Location: ' . BASE_URL . '/cliente/meus-pedidos.php'); exit;
}
$camps   = $ctx['campanhas'];
$retorno = $ctx['retorno'] ?? (BASE_URL . '/cliente/meus-pedidos.php');

function _concluirSelecao($ctx, $retorno) {
    if (!empty($ctx['pdf_ids'])) $_SESSION['pdf_pedidos_ids'] = $ctx['pdf_ids'];
    unset($_SESSION['bonus_selecionavel']);
    header('Location: ' . $retorno); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Pular: não cria bônus, segue o fluxo
    if (($_POST['action'] ?? '') === 'pular') _concluirSelecao($ctx, $retorno);

    $sel = $_POST['sel'] ?? [];
    $bonusAcc = [];
    $erros = [];
    foreach ($camps as $cp) {
        $code = $cp['codigo'];
        $precoById   = [];
        $multiploById = [];
        foreach ($cp['produtos'] as $p) {
            $precoById[(int)$p['id']]   = (float)$p['preco'];
            $multiploById[(int)$p['id']] = max(1, (int)($p['multiplo'] ?? 1));
        }

        $somaQtd = 0; $somaVal = 0.0; $itens = [];
        foreach (($sel[$code] ?? []) as $pid => $q) {
            $pid  = (int)$pid; $q = max(0, (int)$q);
            if ($q <= 0 || !isset($precoById[$pid])) continue;
            $mult   = $multiploById[$pid] ?? 1;
            $actual = $q * $mult;
            $itens[$pid] = $actual;
            $somaQtd += $actual;
            $somaVal += $actual * $precoById[$pid];
        }
        if ($cp['limite_tipo'] === 'valor') {
            if ($somaVal - (float)$cp['limite'] > 0.001) { $erros[] = "Campanha {$code}: valor selecionado excede o limite."; continue; }
        } else {
            if ($somaQtd > (int)$cp['limite']) { $erros[] = "Campanha {$code}: quantidade selecionada excede o limite."; continue; }
        }
        foreach ($itens as $pid => $q) $bonusAcc[$pid] = ($bonusAcc[$pid] ?? 0) + $q;
    }

    if ($erros) {
        flash('danger', implode(' ', $erros));
        header('Location: ' . BASE_URL . '/cliente/bonificacao-selecionavel.php'); exit;
    }

    if ($bonusAcc) {
        $obs = 'Bonificação selecionável de campanha'
             . (!empty($ctx['ref']) ? ' (ref. ' . $ctx['ref'] . ')' : '')
             . ' — ' . implode(', ', array_map(fn($c) => $c['codigo'], $camps));
        try {
            $criados = criarPedidoBonificado((int)$ctx['cliente_id'], (string)$ctx['supervisor'], (string)$ctx['data'], $bonusAcc, $obs);
            if ($criados) {
                $_SESSION['bonus_aviso'] = array_map(fn($b) => $b['quantidade'] . 'x ' . $b['descricao'], $criados);
            }
        } catch (Exception $e) { /* não impede o fluxo */ }
    }
    _concluirSelecao($ctx, $retorno);
}

$pageTitle = 'Escolha seus bônus';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-gift me-2 text-warning"></i>Escolha seus produtos bônus</h4>
</div>
<div class="alert alert-info">
    Seu pedido participa de campanha(s) de bonificação. Escolha os produtos bônus respeitando o limite de cada campanha.
</div>

<form method="POST" id="bonifForm">
    <?php foreach ($camps as $cp):
        $porValor = $cp['limite_tipo'] === 'valor';
        $limiteTxt = $porValor ? moedaBR((float)$cp['limite']) : ((int)$cp['limite'] . ' un.');
    ?>
    <div class="card shadow-sm border-0 mb-3 camp-card"
         data-codigo="<?= e($cp['codigo']) ?>" data-tipo="<?= e($cp['limite_tipo']) ?>" data-limite="<?= e($cp['limite']) ?>">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div><strong>Campanha <?= e($cp['codigo']) ?></strong>
                <span class="text-muted small ms-2">Limite: <?= e($limiteTxt) ?></span>
            </div>
            <div class="small">
                <span class="text-muted">Selecionado:</span>
                <span class="fw-bold camp-usado">0</span> / <?= e($limiteTxt) ?>
                <span class="badge bg-danger ms-2 d-none camp-excede">Limite excedido</span>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr><th>Código</th><th>Produto</th><th class="text-end">Preço unit.</th><th class="text-center">Múlt.</th><th style="width:120px" class="text-center">Quantidade</th><th class="text-center">Qtd. Total</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($cp['produtos'] as $p):
                        $pMult = max(1.0, (float)($p['multiplo'] ?? 1));
                    ?>
                        <tr>
                            <td class="fw-semibold"><?= e($p['codigo']) ?></td>
                            <td><?= e($p['descricao']) ?></td>
                            <td class="text-end"><?= moedaBR((float)$p['preco']) ?></td>
                            <td class="text-center">
                                <?php if ($pMult > 1): ?>
                                <span class="badge bg-light text-dark border"><?= number_format($pMult, 0) ?></span>
                                <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
                            </td>
                            <td class="text-center">
                                <input type="number" min="0" value="0"
                                       class="form-control form-control-sm text-center mx-auto bonif-qtd"
                                       style="max-width:90px"
                                       data-preco="<?= e($p['preco']) ?>"
                                       data-multiplo="<?= $pMult ?>"
                                       name="sel[<?= e($cp['codigo']) ?>][<?= (int)$p['id'] ?>]">
                            </td>
                            <td class="text-center fw-semibold bonif-total text-muted">—</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="d-flex flex-wrap gap-2 justify-content-end">
        <button type="submit" name="action" value="pular" class="btn btn-outline-secondary"
                onclick="return confirm('Concluir sem escolher bônus? Você não poderá selecionar depois.')">
            Pular
        </button>
        <button type="submit" name="action" value="confirmar" class="btn btn-primary" id="btnConfirmar">
            <i class="bi bi-check-lg me-1"></i>Confirmar bônus
        </button>
    </div>
</form>

<script>
function bonifFmt(v, porValor) {
    if (!porValor) return String(v) + ' un.';
    return 'R$ ' + v.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function bonifRecalc() {
    var ok = true;
    document.querySelectorAll('.camp-card').forEach(function(card) {
        var porValor = card.dataset.tipo === 'valor';
        var limite   = parseFloat(card.dataset.limite) || 0;
        var usado    = 0;
        card.querySelectorAll('.bonif-qtd').forEach(function(inp) {
            var q    = parseInt(inp.value, 10) || 0;
            var mult = parseFloat(inp.dataset.multiplo) || 1;
            if (q < 0) { q = 0; inp.value = 0; }
            var actual = q * mult;
            var totalCell = inp.closest('tr').querySelector('.bonif-total');
            if (totalCell) {
                totalCell.textContent = actual > 0 ? actual + ' un.' : '—';
                totalCell.classList.toggle('text-muted', actual <= 0);
                totalCell.classList.toggle('text-primary', actual > 0);
            }
            usado += porValor ? actual * (parseFloat(inp.dataset.preco) || 0) : actual;
        });
        var excede = usado - limite > 0.001;
        card.querySelector('.camp-usado').textContent = bonifFmt(porValor ? Math.round(usado * 100) / 100 : usado, porValor);
        card.querySelector('.camp-excede').classList.toggle('d-none', !excede);
        if (excede) ok = false;
    });
    document.getElementById('btnConfirmar').disabled = !ok;
}
document.querySelectorAll('.bonif-qtd').forEach(function(inp) {
    inp.addEventListener('input', bonifRecalc);
});
bonifRecalc();
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
