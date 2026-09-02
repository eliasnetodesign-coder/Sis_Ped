<?php
require_once __DIR__ . '/../../config.php';
requireComercial();

$ini = $_GET['ini'] ?? date('Y-m-01');
$fim = $_GET['fim'] ?? date('Y-m-t');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ini)) $ini = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fim)) $fim = date('Y-m-t');

// Pedidos importados do A&M com a marca "(BF)" no período (um por lote).
$q = db()->prepare("
    SELECT COALESCE(p.lote_id, CAST(p.id AS CHAR)) AS grp,
           MIN(p.id) AS pedido_id, p.lote_id, p.numero_pedido, p.observacoes,
           p.cliente_id, c.razao_social,
           COALESCE(c.desconto_cliente, 0) AS desconto_cliente,
           COALESCE(c.desconto_canal, 0)   AS desconto_canal,
           MIN(p.data_pedido) AS data_pedido
    FROM pedidos p JOIN clientes c ON c.id = p.cliente_id
    WHERE p.observacoes LIKE 'Importado do sistema A&M (BF)%'
      AND DATE(p.data_pedido) BETWEEN ? AND ?
    GROUP BY grp, p.lote_id, p.numero_pedido, p.observacoes, p.cliente_id, c.razao_social,
             c.desconto_cliente, c.desconto_canal
    ORDER BY data_pedido DESC, p.numero_pedido DESC");
$q->execute([$ini, $fim]);
$pedidos = $q->fetchAll();

$sqlItensBase = "
    SELECT p.quantidade_total, p.valor_total, p.desconto_comercial, p.desconto_diretoria,
           pr.codigo_produto, pr.descricao_pt,
           COALESCE(t.preco_padrao, pr.vendas_varejo, 0) AS preco
    FROM pedidos p
    LEFT JOIN produtos pr     ON pr.id = p.produto_id
    LEFT JOIN tabela_precos t ON t.produto_id = pr.id
    WHERE %COND% ORDER BY p.id";

$relatorio = [];
$geral = ['real'=>0, 'economia'=>0, 'liquido'=>0];
foreach ($pedidos as $p) {
    $st = db()->prepare(str_replace('%COND%', $p['lote_id'] ? 'p.lote_id = ?' : 'p.id = ?', $sqlItensBase));
    $st->execute([$p['lote_id'] ?: $p['pedido_id']]);
    $rows = $st->fetchAll();

    $descCliCanal = min(100, (float)$p['desconto_cliente'] + (float)$p['desconto_canal']);

    $itens = [];
    $campItens = [];
    $sPedido = ['real'=>0, 'economia'=>0, 'liquido'=>0];
    foreach ($rows as $r) {
        $qtd   = (int)$r['quantidade_total'];
        $preco = (float)$r['preco'];
        $dCom  = (float)$r['desconto_comercial'];
        $dDir  = (float)$r['desconto_diretoria'];

        $valorReal    = $qtd * $preco * (1 - $descCliCanal / 100) * (1 - $dCom / 100);
        $valorComCamp = (float)$r['valor_total'];
        $economia     = $valorReal - $valorComCamp;

        $itens[] = [
            'codigo'    => $r['codigo_produto'],
            'descricao' => $r['descricao_pt'],
            'qtd'       => $qtd,
            'preco'     => $preco,
            'pct_camp'  => $dDir,
            'real'      => $valorReal,
            'com_camp'  => $valorComCamp,
            'economia'  => $economia,
        ];
        $campItens[] = [
            'codigo'        => $r['codigo_produto'],
            'nome'          => $r['descricao_pt'],
            'qtd'           => $qtd,
            'valor_total'   => $qtd * $preco,
            'pct_diretoria' => $dDir,
        ];
        $sPedido['real']     += $valorReal;
        $sPedido['economia'] += $economia;
        $sPedido['liquido']  += $valorComCamp;
    }

    $av = campanhasAmAvaliarPedido($campItens);

    $numAM = preg_match('/Pedido N[ºo°]\s*([^\s—-]+)/u', (string)$p['observacoes'], $mm) ? $mm[1] : '—';

    $relatorio[] = [
        'pedido_id' => (int)$p['pedido_id'],
        'numero'    => $p['numero_pedido'],
        'num_am'    => $numAM,
        'cliente'   => $p['razao_social'],
        'data'      => $p['data_pedido'],
        'itens'     => $itens,
        'soma'      => $sPedido,
        'campanhas' => $av['campanhas_atingidas'],
        'fora'      => $av['itens_fora_campanha'],
    ];
    $geral['real']     += $sPedido['real'];
    $geral['economia'] += $sPedido['economia'];
    $geral['liquido']  += $sPedido['liquido'];
}

$pctFmt = fn($v) => rtrim(rtrim(number_format((float)$v, 2, ',', '.'), '0'), ',') . '%';

$pageTitle = 'Pedidos BF — Campanhas';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-tags me-2"></i>Pedidos BF — Campanhas</h4>
        <p class="text-muted small mb-0">
            Pedidos importados do A&amp;M com a característica “BF”. <strong>Valor Real</strong> = valor sem o
            desconto de campanha; <strong>Valor c/ Campanha</strong> = valor com o % da faixa de campanha
            aplicado no % Diretoria dos itens (mesma regra da Análise Financeira).
        </p>
    </div>
    <a href="<?= BASE_URL ?>/admin/relatorios/am.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Relatórios A&amp;M
    </a>
</div>

<form class="card shadow-sm border-0 mb-3 p-3">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-3">
            <label class="form-label fw-semibold small mb-1">Data inicial</label>
            <input type="date" name="ini" value="<?= e($ini) ?>" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label fw-semibold small mb-1">Data final</label>
            <input type="date" name="fim" value="<?= e($fim) ?>" class="form-control form-control-sm">
        </div>
        <div class="col-12 col-md-2">
            <button class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filtrar</button>
        </div>
    </div>
</form>

<div class="alert alert-light border small py-2">
    <i class="bi bi-info-circle me-1"></i>
    Só aparecem pedidos importados via <strong>“Importa Pedido BF”</strong> após a marca de origem BF.
    Pedidos BF importados antes disso não são listados aqui.
</div>

<div class="row g-3 mb-4">
    <?php
    $resumo = [
        ['Valor Real (sem campanha)', $geral['real'], 'secondary'],
        ['Desconto de Campanha', $geral['economia'], 'primary'],
        ['Valor c/ Campanha', $geral['liquido'], 'success'],
    ];
    foreach ($resumo as $r): ?>
    <div class="col-12 col-md-4">
        <div class="card shadow-sm border-0 border-start border-4 border-<?= $r[2] ?> h-100">
            <div class="card-body py-3">
                <div class="text-muted small fw-semibold text-uppercase"><?= e($r[0]) ?></div>
                <div class="fs-4 fw-bold text-<?= $r[2] ?>"><?= moedaBR($r[1]) ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php if (!$relatorio): ?>
<div class="card shadow-sm border-0"><div class="card-body text-center text-muted py-5">
    Nenhum pedido BF importado no período.
</div></div>
<?php endif; ?>

<?php foreach ($relatorio as $rp): ?>
<div class="card shadow-sm border-0 mb-3">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span class="fw-semibold">
            <i class="bi bi-receipt me-1"></i>Pedido <?= e($rp['numero']) ?>
            <span class="text-muted">· Nº A&amp;M <?= e($rp['num_am']) ?></span>
        </span>
        <span class="small text-muted"><?= e($rp['cliente']) ?> · <?= dataBR($rp['data']) ?></span>
        <a href="<?= BASE_URL ?>/admin/pedido.php?id=<?= $rp['pedido_id'] ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-eye me-1"></i>Abrir
        </a>
    </div>
    <div class="card-body p-0"><div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0" style="font-size:.85rem">
            <thead class="table-light">
                <tr>
                    <th>Código</th>
                    <th>Produto</th>
                    <th class="text-end">Qtd</th>
                    <th class="text-end">Preço Tabela</th>
                    <th class="text-end">Valor Real</th>
                    <th class="text-end">% Campanha</th>
                    <th class="text-end">Valor c/ Campanha</th>
                    <th class="text-end">Economia</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rp['itens'] as $it): ?>
                <tr>
                    <td><?= e($it['codigo']) ?></td>
                    <td class="text-truncate" style="max-width:240px" title="<?= e($it['descricao']) ?>"><?= e($it['descricao']) ?></td>
                    <td class="text-end"><?= (int)$it['qtd'] ?></td>
                    <td class="text-end"><?= moedaBR($it['preco']) ?></td>
                    <td class="text-end"><?= moedaBR($it['real']) ?></td>
                    <td class="text-end"><?= $it['pct_camp'] ? '<span class="badge bg-primary">' . $pctFmt($it['pct_camp']) . '</span>' : '—' ?></td>
                    <td class="text-end"><?= moedaBR($it['com_camp']) ?></td>
                    <td class="text-end text-success"><?= $it['economia'] > 0.005 ? '− ' . moedaBR($it['economia']) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light fw-semibold">
                <tr>
                    <td colspan="4">Total do pedido</td>
                    <td class="text-end"><?= moedaBR($rp['soma']['real']) ?></td>
                    <td></td>
                    <td class="text-end"><?= moedaBR($rp['soma']['liquido']) ?></td>
                    <td class="text-end text-success"><?= $rp['soma']['economia'] > 0.005 ? '− ' . moedaBR($rp['soma']['economia']) : '—' ?></td>
                </tr>
            </tfoot>
        </table>
    </div></div>
    <div class="card-footer bg-white small">
        <?php if ($rp['campanhas']): ?>
            <span class="fw-semibold">Campanhas atingidas:</span>
            <?php foreach ($rp['campanhas'] as $ca): ?>
                <span class="badge bg-light text-dark border me-1">
                    <?= e($ca['nome']) ?> ·
                    <?= $ca['criterio'] === 'valor' ? moedaBR($ca['agregado']) : (int)$ca['agregado'] . ' ' . e($ca['unidade'] ?: 'un') ?>
                    → <?= $pctFmt($ca['percentual_esperado']) ?>
                </span>
            <?php endforeach; ?>
        <?php else: ?>
            <span class="text-muted">Nenhuma campanha atingida.</span>
        <?php endif; ?>
        <?php if ($rp['fora']): ?>
            <div class="text-danger mt-1">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <?= count($rp['fora']) ?> item(ns) com % Diretoria acima do esperado pela faixa:
                <?php foreach ($rp['fora'] as $f): ?>
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle me-1"><?= e($f['codigo'] ?? '') ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
