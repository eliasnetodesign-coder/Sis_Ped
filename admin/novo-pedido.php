<?php
require_once __DIR__ . '/../config.php';
requireComercial();
$u = usuario();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cliente_id = (int)($_POST['cliente_id'] ?? 0);
    $obs_geral  = trim($_POST['observacoes'] ?? '');
    $itens      = $_POST['itens'] ?? [];

    if (!$cliente_id) {
        flash('danger', 'Selecione um cliente.');
        header('Location: ' . BASE_URL . '/admin/novo-pedido.php'); exit;
    }

    $cli = db()->prepare('SELECT c.* FROM clientes c WHERE c.id = ?');
    $cli->execute([$cliente_id]);
    $cli = $cli->fetch();
    if (!$cli) {
        flash('danger', 'Cliente não encontrado.');
        header('Location: ' . BASE_URL . '/admin/novo-pedido.php'); exit;
    }

    $desconto      = ((float)($cli['desconto_cliente'] ?? 0) + (float)($cli['desconto_canal'] ?? 0)) / 100;
    $data          = date('Y-m-d');
    $campanhas_all = db()->query('SELECT produto_id, linha, grupo, subgrupo, canal_venda_id, quantidade, desconto FROM campanhas')->fetchAll();
    $canalVendaId  = (int)($cli['canal_venda_id'] ?? 0);
    $criados       = 0;
    $ids_criados   = [];

    try {
        $lote_id = uniqid('L', true);

        // Passagem 1: coletar itens válidos e totais por linha/grupo/subgrupo
        $items_data     = [];
        $totaisLinha    = [];
        $totaisGrupo    = [];
        $totaisSubgrupo = [];
        foreach ($itens as $pid => $item) {
            $qtd = max(0, (int)($item['quantidade'] ?? 0));
            if ($qtd <= 0) continue;
            $produto_id = (int)($item['produto_id'] ?? $pid);
            $stmtP = db()->prepare('SELECT p.*, COALESCE(t.preco_padrao, p.vendas_varejo) as preco FROM produtos p LEFT JOIN tabela_precos t ON t.produto_id = p.id WHERE p.id = ? AND p.status = "ativo"');
            $stmtP->execute([$produto_id]);
            $prod = $stmtP->fetch();
            if (!$prod) continue;
            $items_data[] = ['produto_id' => $produto_id, 'qtd' => $qtd, 'prod' => $prod];
            $l = trim($prod['linha']    ?? ''); if ($l) $totaisLinha[$l]    = ($totaisLinha[$l]    ?? 0) + $qtd;
            $g = trim($prod['grupo']    ?? ''); if ($g) $totaisGrupo[$g]    = ($totaisGrupo[$g]    ?? 0) + $qtd;
            $s = trim($prod['subgrupo'] ?? ''); if ($s) $totaisSubgrupo[$s] = ($totaisSubgrupo[$s] ?? 0) + $qtd;
        }

        // Passagem 2: gravar cada item aplicando desconto de campanha com base nos totais
        $loteFinal = count($items_data) > 1 ? $lote_id : null;
        foreach ($items_data as $it) {
            $produto_id  = $it['produto_id'];
            $qtd         = $it['qtd'];
            $prod        = $it['prod'];
            $valor_total = $qtd * (float)$prod['preco'] * (1 - $desconto);

            $campDescJS = (float)($itens[$produto_id]['camp_desc'] ?? 0);
            $campDesc   = 0;
            foreach ($campanhas_all as $camp) {
                if ((int)$camp['quantidade'] <= 0) continue;
                if ($camp['canal_venda_id'] && (int)$camp['canal_venda_id'] !== $canalVendaId) continue;
                if ($camp['produto_id'] && (int)$camp['produto_id'] !== $produto_id) continue;
                if (!$camp['produto_id']) {
                    $cLinha    = trim($camp['linha']    ?? '');
                    $cGrupo    = trim($camp['grupo']    ?? '');
                    $cSubgrupo = trim($camp['subgrupo'] ?? '');
                    if ($cLinha) {
                        if ($cLinha !== trim($prod['linha'] ?? '')) continue;
                        $qtdRef = $totaisLinha[$cLinha] ?? 0;
                    } elseif ($cGrupo) {
                        if ($cGrupo !== trim($prod['grupo'] ?? '')) continue;
                        $qtdRef = $totaisGrupo[$cGrupo] ?? 0;
                    } elseif ($cSubgrupo) {
                        if ($cSubgrupo !== trim($prod['subgrupo'] ?? '')) continue;
                        $qtdRef = $totaisSubgrupo[$cSubgrupo] ?? 0;
                    } else {
                        $qtdRef = $qtd;
                    }
                } else {
                    $qtdRef = $qtd;
                }
                if ($qtdRef < (int)$camp['quantidade']) continue;
                if ((float)$camp['desconto'] > $campDesc) $campDesc = (float)$camp['desconto'];
            }
            // Se PHP não detectou campanha mas JS detectou, valida e usa o valor do JS
            if ($campDescJS > $campDesc) {
                $validDesc = array_column($campanhas_all, 'desconto');
                if (in_array(number_format($campDescJS, 4), array_map(fn($d) => number_format((float)$d, 4), $validDesc))) {
                    $campDesc = $campDescJS;
                }
            }

            if ($campDesc > 0) $valor_total *= (1 - $campDesc / 100);

            $num = 'PED-' . date('Y') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
            db()->prepare('INSERT INTO pedidos (numero_pedido,tipo_venda,data_pedido,cliente_id,produto_id,supervisor,codigo_barra,descricao_produto,quantidade_total,valor_total,status,observacoes,lote_id,desconto_campanha) VALUES (?,?,?,?,?,?,?,?,?,?,"comercial",?,?,?)')
                ->execute([$num, 'venda', $data, $cliente_id, $produto_id, $cli['supervisor'] ?? $cli['vendedor'] ?? '', $prod['codigo_barra'], $prod['descricao_pt'], $qtd, $valor_total, $obs_geral, $loteFinal, $campDesc ?: null]);
            $ids_criados[] = (int)db()->lastInsertId();
            $criados++;
        }

        if ($criados > 0) {
            flash('success', $criados . ' item(ns) adicionado(s) com sucesso!');
            header('Location: ' . BASE_URL . '/admin/pedido.php?id=' . $ids_criados[0]); exit;
        } else {
            flash('warning', 'Adicione ao menos um produto ao carrinho.');
        }
    } catch (Exception $e) {
        flash('danger', 'Erro ao criar pedido: ' . $e->getMessage());
    }
    header('Location: ' . BASE_URL . '/admin/novo-pedido.php'); exit;
}

$clientes  = db()->query('SELECT id, razao_social, codigo_cliente, desconto_cliente, desconto_canal, canal_venda_id FROM clientes WHERE status = "ativo" ORDER BY razao_social')->fetchAll();
$campanhas = db()->query('SELECT c.*, p.descricao_pt, cv.canal FROM campanhas c LEFT JOIN produtos p ON p.id = c.produto_id LEFT JOIN canal_venda cv ON cv.id = c.canal_venda_id ORDER BY c.codigo_campanha')->fetchAll();

$produtos = db()->query('SELECT p.id, p.codigo_produto, p.codigo_barra, p.descricao_pt, p.multiplo, p.linha, p.grupo, p.subgrupo,
    COALESCE(t.preco_padrao, p.vendas_varejo, 0) as preco
    FROM produtos p LEFT JOIN tabela_precos t ON t.produto_id = p.id
    WHERE p.status = "ativo" ORDER BY p.linha, p.descricao_pt')->fetchAll();

$porLinha = [];
foreach ($produtos as $p) {
    $linha = trim($p['linha'] ?? '');
    $linha = $linha !== '' ? $linha : 'Outros';
    $porLinha[$linha][] = $p;
}
ksort($porLinha);
$linhas = array_keys($porLinha);

$pageTitle = 'Novo Pedido';
require_once LAYOUT_PATH . '/header.php';
?>

<form id="formPedido" method="POST">

<!-- ══ ETAPA 1 ══════════════════════════════════════════════ -->
<div id="step1">

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-plus-circle me-2 text-primary"></i>Novo Pedido</h4>
        <small class="text-muted">Selecione o cliente, informe as quantidades e clique em Carrinho para avançar</small>
    </div>
    <div class="d-flex align-items-center gap-3">
        <a href="<?= BASE_URL ?>/admin/pedidos.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Pedidos
        </a>
        <button type="button" class="btn btn-primary position-relative px-4"
                data-bs-toggle="offcanvas" data-bs-target="#offCarrinho">
            <i class="bi bi-cart3 me-2"></i>Carrinho
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                  id="cartBadge" style="display:none">0</span>
        </button>
    </div>
</div>

<?php if ($campanhas): ?>
<div class="mb-3 p-3 rounded-3 border bg-white">
    <div class="d-flex align-items-center gap-2 mb-2">
        <i class="bi bi-megaphone-fill text-primary"></i>
        <span class="fw-semibold text-primary small text-uppercase">Campanhas Ativas</span>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php foreach ($campanhas as $c):
            $alvo = $c['descricao_pt']
                ?? ($c['linha']     ? 'Linha '     . trim($c['linha'])     : null)
                ?? ($c['grupo']     ? 'Grupo '     . trim($c['grupo'])     : null)
                ?? ($c['subgrupo']  ? 'Subgrupo '  . trim($c['subgrupo'])  : 'Todos os produtos');
            $pct = rtrim(rtrim(number_format((float)$c['desconto'], 2, ',', '.'), '0'), ',');
        ?>
        <div class="d-flex align-items-center gap-2 border rounded-3 px-3 py-2" style="background:#f8fffe">
            <span class="badge bg-success fs-6 fw-bold px-2">−<?= $pct ?>%</span>
            <div style="line-height:1.3">
                <div class="fw-semibold" style="font-size:.82rem"><?= e($c['codigo_campanha']) ?></div>
                <div class="text-muted" style="font-size:.76rem"><?= e($alvo) ?> &middot; a partir de <?= (int)$c['quantidade'] ?> un.</div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div id="alertDesconto" class="alert alert-success py-2 mb-3" style="display:none">
    <i class="bi bi-tag-fill me-1"></i><span id="alertDescontoTexto"></span>
</div>

<!-- Seleção de cliente -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-6">
                <label class="form-label fw-semibold">Cliente <span class="text-danger">*</span></label>
                <input type="hidden" name="cliente_id" id="cli_id">
                <div class="position-relative">
                    <input type="text" id="cli_txt" class="form-control"
                           placeholder="Buscar por nome ou código..." autocomplete="off">
                    <div id="cli_drop" class="position-absolute bg-white border rounded shadow-sm w-100"
                         style="z-index:1050;max-height:240px;overflow-y:auto;display:none">
                        <?php foreach ($clientes as $c): ?>
                        <div class="cli-opt px-3 py-2"
                             style="cursor:pointer;font-size:.9rem"
                             data-id="<?= $c['id'] ?>"
                             data-label="[<?= e($c['codigo_cliente']) ?>] <?= e($c['razao_social']) ?>"
                             data-desconto="<?= e($c['desconto_cliente'] ?? 0) ?>"
                             data-desconto-canal="<?= e($c['desconto_canal'] ?? 0) ?>"
                             data-canal-id="<?= (int)($c['canal_venda_id'] ?? 0) ?>">
                            <span class="badge bg-secondary me-1"><?= e($c['codigo_cliente']) ?></span><?= e($c['razao_social']) ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <input type="hidden" name="data_pedido" value="<?= date('Y-m-d') ?>">
        </div>
    </div>
</div>

<div class="d-flex align-items-center gap-3 mb-0">
    <h6 class="mb-0 text-muted fw-semibold text-uppercase small"><i class="bi bi-grid me-1"></i>Linhas</h6>
    <input type="text" id="filtroProduto" class="form-control form-control-sm ms-auto"
           style="max-width:220px" placeholder="Filtrar produto...">
</div>

<ul class="nav nav-tabs mt-2 mb-0 flex-nowrap"
    style="overflow-x:auto;overflow-y:hidden;scrollbar-width:none;white-space:nowrap">
    <?php foreach ($linhas as $i => $linha):
        $tid = 'tab-' . preg_replace('/\W+/', '_', strtolower($linha));
    ?>
    <li class="nav-item flex-shrink-0">
        <button class="nav-link <?= $i === 0 ? 'active' : '' ?> text-nowrap"
                type="button" data-bs-toggle="tab" data-bs-target="#pane-<?= $tid ?>">
            <?= e($linha) ?>
            <span class="badge bg-primary ms-1 tab-badge" id="badge-<?= $tid ?>" style="display:none">0</span>
        </button>
    </li>
    <?php endforeach; ?>
</ul>

<div class="tab-content border border-top-0 rounded-bottom mb-4">
    <?php foreach ($linhas as $i => $linha):
        $tid = 'tab-' . preg_replace('/\W+/', '_', strtolower($linha));
    ?>
    <div class="tab-pane fade <?= $i === 0 ? 'show active' : '' ?>" id="pane-<?= $tid ?>">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Código</th>
                        <th>Cod. Barras</th>
                        <th>Produto</th>
                        <th class="text-end">Preço Unit.</th>
                        <th class="text-center">Múlt.</th>
                        <th class="text-center" style="width:100px">Quantidade</th>
                        <th class="text-center" style="width:140px">Quantidade Total</th>
                        <th class="text-end">Total R$</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($porLinha[$linha] as $p):
                    $pid      = (int)$p['id'];
                    $multiplo = (float)($p['multiplo'] ?? 0);
                    $multiplo = $multiplo > 0 ? $multiplo : 1;
                    $preco    = (float)$p['preco'];
                ?>
                <tr class="produto-row"
                    data-pid="<?= $pid ?>"
                    data-preco="<?= e($preco) ?>"
                    data-nome="<?= e($p['descricao_pt']) ?>"
                    data-codigo="<?= e($p['codigo_produto']) ?>"
                    data-linha="<?= e($p['linha'] ?? '') ?>"
                    data-grupo="<?= e($p['grupo'] ?? '') ?>"
                    data-subgrupo="<?= e($p['subgrupo'] ?? '') ?>"
                    data-multiplo="<?= $multiplo ?>"
                    data-tab="<?= $tid ?>">
                    <td class="text-muted small"><?= e($p['codigo_produto']) ?></td>
                    <td class="text-muted small"><?= e($p['codigo_barra'] ?: '—') ?></td>
                    <td class="fw-semibold"><?= e($p['descricao_pt']) ?></td>
                    <td class="text-end text-muted small preco-unit-col"
                        data-preco-base="<?= $preco > 0 ? e('R$ ' . number_format($preco, 2, ',', '.')) : '—' ?>">
                        <?= $preco > 0 ? 'R$ ' . number_format($preco, 2, ',', '.') : '—' ?>
                    </td>
                    <td class="text-center">
                        <?php if ($multiplo > 1): ?>
                        <span class="badge bg-light text-dark border"><?= number_format($multiplo, 0) ?></span>
                        <?php else: ?>
                        <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <input type="hidden" name="itens[<?= $pid ?>][produto_id]" value="<?= $pid ?>">
                        <input type="hidden" name="itens[<?= $pid ?>][quantidade]" class="qtd-hidden" value="0">
                        <input type="number" class="form-control form-control-sm text-center qtd-visual mx-auto"
                               style="width:80px" min="0" value="0" oninput="atualizarQtd(this)">
                    </td>
                    <td class="text-center fw-semibold qtd-total-col">—</td>
                    <td class="text-end fw-semibold text-primary row-total">—</td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
</div>

</div><!-- /step1 -->

<!-- ══ ETAPA 2 ══════════════════════════════════════════════ -->
<div id="step2" style="display:none">

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-clipboard-check me-2 text-primary"></i>Resumo do Pedido</h4>
        <small class="text-muted" id="step2Cliente"></small>
    </div>
    <button type="button" class="btn btn-outline-secondary" onclick="voltarStep1()">
        <i class="bi bi-arrow-left me-1"></i>Voltar aos produtos
    </button>
</div>

<div id="resumoConteudo" class="mb-4"></div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-4">
        <label class="form-label fw-semibold">Observação (opcional)</label>
        <textarea class="form-control" name="observacoes" rows="2"
                  placeholder="Instruções especiais, prazo, etc."></textarea>
    </div>
</div>

<div class="d-flex justify-content-end">
    <button type="submit" class="btn btn-primary btn-lg px-5" id="btnFinalizar">
        <i class="bi bi-check-lg me-2"></i>Finalizar Pedido
    </button>
</div>

</div><!-- /step2 -->

<!-- ══ OFFCANVAS: carrinho ════════════════════════════════── -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="offCarrinho"
     style="width:400px;max-width:100vw">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title"><i class="bi bi-cart3 me-2 text-primary"></i>Carrinho</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column p-0">
        <div id="carrinhoItens" class="flex-grow-1 overflow-auto px-3 py-2"></div>
        <div class="border-top px-3 pt-3 pb-3 bg-white">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="fw-semibold">Total</span>
                <span class="fw-bold fs-5 text-primary" id="carrinhoTotal">R$ 0,00</span>
            </div>
            <button type="button" class="btn btn-primary w-100 btn-lg" id="btnAvancar">
                <i class="bi bi-arrow-right me-2"></i>Avançar
            </button>
        </div>
    </div>
</div>

</form>

<script>
var desconto   = 0;
var _canalId   = 0;
var _campanhas = <?= json_encode(array_map(function($c) {
    return [
        'produto_id'   => $c['produto_id'] ? (int)$c['produto_id'] : null,
        'linha'        => trim($c['linha']    ?? ''),
        'grupo'        => trim($c['grupo']    ?? ''),
        'subgrupo'     => trim($c['subgrupo'] ?? ''),
        'canal_id'     => $c['canal_venda_id'] ? (int)$c['canal_venda_id'] : null,
        'quantidade'   => (int)$c['quantidade'],
        'desconto'     => (float)$c['desconto'],
    ];
}, $campanhas)) ?>;

function fmtBRL(v) {
    return 'R$ ' + v.toFixed(2).replace('.', ',');
}

function onClienteChange(dCli, dCan, canalId) {
    desconto = dCli + dCan;
    _canalId = canalId;

    var alertEl  = document.getElementById('alertDesconto');
    var alertTxt = document.getElementById('alertDescontoTexto');
    if (document.getElementById('cli_id').value && desconto > 0) {
        var partes = [];
        if (dCli > 0) partes.push('Cliente: ' + (dCli * 100).toFixed(2) + '%');
        if (dCan > 0) partes.push('Canal: '   + (dCan * 100).toFixed(2) + '%');
        alertTxt.innerHTML = 'Descontos especiais — <strong>' + partes.join(' | ') + '</strong> serão aplicados automaticamente.';
        alertEl.style.display = '';
    } else {
        alertEl.style.display = 'none';
    }

    recalcularTodas();
    atualizar();
}

(function () {
    var inp  = document.getElementById('cli_txt');
    var drop = document.getElementById('cli_drop');
    var hid  = document.getElementById('cli_id');

    function filtrar() {
        var q = inp.value.toLowerCase();
        drop.querySelectorAll('.cli-opt').forEach(function (o) {
            o.style.display = o.dataset.label.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
        });
    }

    inp.addEventListener('focus', function () { drop.style.display = ''; filtrar(); });
    inp.addEventListener('input', filtrar);
    inp.addEventListener('blur',  function () { setTimeout(function () { drop.style.display = 'none'; }, 180); });

    drop.querySelectorAll('.cli-opt').forEach(function (o) {
        o.addEventListener('mousedown', function (e) {
            e.preventDefault();
            hid.value = o.dataset.id;
            inp.value = o.dataset.label;
            drop.style.display = 'none';
            var dCli = (parseFloat(o.dataset.desconto)      || 0) / 100;
            var dCan = (parseFloat(o.dataset.descontoCanal) || 0) / 100;
            var cId  = parseInt(o.dataset.canalId)          || 0;
            onClienteChange(dCli, dCan, cId);
        });
        o.addEventListener('mouseover', function () { o.style.background = '#f0f0f0'; });
        o.addEventListener('mouseout',  function () { o.style.background = ''; });
    });
}());

function recalcularTodas() {
    // Soma quantidades por linha, grupo e subgrupo para campanhas de linha
    var totLinha = {}, totGrupo = {}, totSub = {};
    document.querySelectorAll('.produto-row').forEach(function(row) {
        var actual = parseInt(row.querySelector('.qtd-hidden').value) || 0;
        var l = row.dataset.linha    || '';
        var g = row.dataset.grupo    || '';
        var s = row.dataset.subgrupo || '';
        if (l) totLinha[l] = (totLinha[l] || 0) + actual;
        if (g) totGrupo[g] = (totGrupo[g] || 0) + actual;
        if (s) totSub[s]   = (totSub[s]   || 0) + actual;
    });

    document.querySelectorAll('.produto-row').forEach(function(row) {
        var pid      = parseInt(row.dataset.pid);
        var actual   = parseInt(row.querySelector('.qtd-hidden').value) || 0;
        var linha    = row.dataset.linha    || '';
        var grupo    = row.dataset.grupo    || '';
        var subgrupo = row.dataset.subgrupo || '';
        var precoBase    = parseFloat(row.dataset.preco) || 0;
        var precoComDesc = precoBase * (1 - desconto);

        var campDesc = 0;
        _campanhas.forEach(function(c) {
            if (c.quantidade <= 0) return;
            // Filtra por canal: ignora campanha de canal diferente do cliente
            if (c.canal_id && c.canal_id !== _canalId) return;
            var qtdRef;
            if (c.produto_id !== null) {
                if (c.produto_id !== pid) return;
                qtdRef = actual;
            } else if (c.linha) {
                if (c.linha !== linha) return;
                qtdRef = totLinha[linha] || 0;
            } else if (c.grupo) {
                if (c.grupo !== grupo) return;
                qtdRef = totGrupo[grupo] || 0;
            } else if (c.subgrupo) {
                if (c.subgrupo !== subgrupo) return;
                qtdRef = totSub[subgrupo] || 0;
            } else {
                qtdRef = actual;
            }
            if (qtdRef < c.quantidade) return;
            if (c.desconto > campDesc) campDesc = c.desconto;
        });
        row.dataset.campDesc = campDesc;

        var preco     = campDesc > 0 ? precoComDesc * (1 - campDesc / 100) : precoComDesc;
        var precoCell = row.querySelector('.preco-unit-col');
        if (precoCell) {
            var baseFormatado = precoComDesc > 0 ? fmtBRL(precoComDesc) : (precoBase > 0 ? fmtBRL(precoBase) : '—');
            if (campDesc > 0) {
                precoCell.innerHTML = baseFormatado + ' <span class="badge bg-success ms-1" style="font-size:.7em">-' + campDesc + '%</span>';
            } else {
                precoCell.textContent = baseFormatado;
            }
        }
        if (actual > 0) {
            row.querySelector('.row-total').innerHTML = fmtBRL(actual * preco)
                + (campDesc > 0 ? ' <span class="badge bg-success ms-1">-' + campDesc + '%</span>' : '');
        } else {
            row.querySelector('.row-total').textContent = '—';
        }
    });
}

function atualizarQtd(visualInput) {
    var row      = visualInput.closest('.produto-row');
    var multiplo = parseFloat(row.dataset.multiplo) || 1;
    var visual   = parseInt(visualInput.value) || 0;
    var actual   = Math.round(visual * multiplo);
    row.querySelector('.qtd-hidden').value          = actual;
    row.querySelector('.qtd-total-col').textContent = actual > 0 ? actual : '—';
    recalcularTodas();
    atualizar();
}

function getItens() {
    var itens = [];
    document.querySelectorAll('.produto-row').forEach(function(row) {
        var actual = parseInt(row.querySelector('.qtd-hidden').value) || 0;
        if (actual > 0) {
            var visual       = parseInt(row.querySelector('.qtd-visual').value) || 0;
            var multiplo     = parseFloat(row.dataset.multiplo) || 1;
            var precoBase    = parseFloat(row.dataset.preco) || 0;
            var precoComDesc = precoBase * (1 - desconto);
            var campDesc     = parseFloat(row.dataset.campDesc) || 0;
            var preco        = campDesc > 0 ? precoComDesc * (1 - campDesc / 100) : precoComDesc;
            itens.push({
                nome:     row.dataset.nome,
                codigo:   row.dataset.codigo,
                linha:    row.dataset.linha || '',
                preco:    preco,
                campDesc: campDesc,
                visual:   visual,
                multiplo: multiplo,
                qtd:      actual,
                sub:      preco * actual,
                tab:      row.dataset.tab
            });
        }
    });
    return itens;
}

function atualizar() {
    var itens  = getItens();
    var badges = {};
    itens.forEach(function(i) { badges[i.tab] = (badges[i.tab] || 0) + 1; });
    var total       = itens.reduce(function(a, i) { return a + i.sub; }, 0);
    var totalVisual = itens.reduce(function(a, i) { return a + i.visual; }, 0);

    document.querySelectorAll('.produto-row').forEach(function(row) {
        row.classList.toggle('table-primary', parseInt(row.querySelector('.qtd-hidden').value) > 0);
    });

    var cb = document.getElementById('cartBadge');
    cb.textContent   = totalVisual;
    cb.style.display = totalVisual > 0 ? '' : 'none';

    document.querySelectorAll('.tab-badge').forEach(function(b) {
        var cnt = badges[b.id.replace('badge-', '')] || 0;
        b.textContent   = cnt;
        b.style.display = cnt > 0 ? '' : 'none';
    });

    document.getElementById('carrinhoTotal').textContent = fmtBRL(total);

    var el = document.getElementById('carrinhoItens');
    if (itens.length === 0) {
        el.innerHTML = '<div class="text-center text-muted py-5">'
            + '<i class="bi bi-cart3 display-4 d-block mb-3 opacity-25"></i>'
            + 'Nenhum produto adicionado.<br><small>Informe as quantidades na lista.</small></div>';
    } else {
        el.innerHTML = itens.map(function(i) {
            var qtdDesc   = i.multiplo > 1
                ? i.visual + ' × ' + i.multiplo + ' = ' + i.qtd + ' un.'
                : i.qtd + ' un.';
            var campBadge = i.campDesc > 0
                ? ' <span class="badge bg-success">-' + i.campDesc + '%</span>' : '';
            return '<div class="d-flex justify-content-between align-items-start py-2 border-bottom">'
                + '<div style="max-width:65%"><div class="fw-semibold small lh-sm">' + i.nome + campBadge + '</div>'
                + '<div class="text-muted" style="font-size:.78rem">' + qtdDesc
                + ' × R$ ' + i.preco.toFixed(2).replace('.', ',') + '</div></div>'
                + '<div class="fw-bold text-primary small">' + fmtBRL(i.sub) + '</div></div>';
        }).join('');
    }
}

document.getElementById('btnAvancar').addEventListener('click', function() {
    if (!document.getElementById('cli_id').value) {
        alert('Selecione um cliente antes de avançar.');
        var oc = bootstrap.Offcanvas.getInstance(document.getElementById('offCarrinho'));
        if (oc) oc.hide();
        document.getElementById('cli_txt').focus();
        return;
    }
    var itens = getItens();
    if (itens.length === 0) {
        alert('Adicione pelo menos um produto ao carrinho.');
        return;
    }

    var nomeCliente = document.getElementById('cli_txt').value || '';

    var grupos = {};
    itens.forEach(function(i) {
        if (!grupos[i.linha]) grupos[i.linha] = [];
        grupos[i.linha].push(i);
    });

    var total = 0;
    var html  = '';
    Object.keys(grupos).sort().forEach(function(linha) {
        var linhaTotal = 0;
        var rows = grupos[linha].map(function(i) {
            linhaTotal += i.sub;
            total      += i.sub;
            var qtdDesc   = i.multiplo > 1
                ? i.visual + ' × ' + i.multiplo + ' = ' + i.qtd + ' un.'
                : i.qtd + ' un.';
            var campBadge = i.campDesc > 0
                ? ' <span class="badge bg-success">-' + i.campDesc + '%</span>' : '';
            return '<tr>'
                + '<td class="fw-semibold">' + i.nome + campBadge + '</td>'
                + '<td class="text-muted small">' + i.codigo + '</td>'
                + '<td class="text-center small">' + qtdDesc + '</td>'
                + '<td class="text-end">R$ ' + i.preco.toFixed(2).replace('.', ',') + '</td>'
                + '<td class="text-end fw-semibold text-primary">' + fmtBRL(i.sub) + '</td>'
                + '</tr>';
        }).join('');

        html += '<div class="card border-0 shadow-sm mb-3">'
            + '<div class="card-header bg-white d-flex justify-content-between align-items-center py-2">'
            + '<span class="fw-bold"><i class="bi bi-tag me-2 text-primary"></i>' + linha + '</span>'
            + '<span class="text-muted small">Subtotal: <strong class="text-primary">' + fmtBRL(linhaTotal) + '</strong></span>'
            + '</div><div class="table-responsive"><table class="table table-hover align-middle mb-0">'
            + '<thead class="table-light"><tr><th>Produto</th><th>Código</th>'
            + '<th class="text-center">Qtd.</th><th class="text-end">Preço Unit.</th>'
            + '<th class="text-end">Subtotal</th></tr></thead><tbody>' + rows + '</tbody>'
            + '<tfoot class="table-light"><tr><td colspan="4" class="text-end fw-semibold">Subtotal ' + linha + '</td>'
            + '<td class="text-end fw-bold text-primary">' + fmtBRL(linhaTotal) + '</td>'
            + '</tr></tfoot></table></div></div>';
    });

    html += '<div class="card border-0 shadow-sm mb-4">'
        + '<div class="card-body d-flex justify-content-between align-items-center py-3">'
        + '<span class="fw-bold fs-5">Total Geral</span>'
        + '<span class="fw-bold fs-4 text-primary">' + fmtBRL(total) + '</span>'
        + '</div></div>';

    document.getElementById('resumoConteudo').innerHTML = html;
    document.getElementById('step2Cliente').textContent = 'Cliente: ' + nomeCliente;

    var oc = bootstrap.Offcanvas.getInstance(document.getElementById('offCarrinho'));
    if (oc) oc.hide();
    document.getElementById('step1').style.display = 'none';
    document.getElementById('step2').style.display = '';
    window.scrollTo({ top: 0, behavior: 'smooth' });
});

function voltarStep1() {
    document.getElementById('step2').style.display = 'none';
    document.getElementById('step1').style.display = '';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

document.getElementById('filtroProduto').addEventListener('input', function() {
    var q = this.value.toLowerCase();
    document.querySelectorAll('.produto-row').forEach(function(row) {
        row.style.display = row.dataset.nome.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
    });
});

document.getElementById('formPedido').addEventListener('submit', function() {
    recalcularTodas();
    document.querySelectorAll('.produto-row').forEach(function(row) {
        var campDesc = parseFloat(row.dataset.campDesc) || 0;
        if (campDesc > 0) {
            var pid = row.dataset.pid;
            var inp = document.createElement('input');
            inp.type  = 'hidden';
            inp.name  = 'itens[' + pid + '][camp_desc]';
            inp.value = campDesc;
            document.getElementById('formPedido').appendChild(inp);
        }
    });
    var btn = document.getElementById('btnFinalizar');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processando...';
});

recalcularTodas();
atualizar();
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
