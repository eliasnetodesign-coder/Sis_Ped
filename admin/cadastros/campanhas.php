<?php
require_once __DIR__ . '/../../config.php';
requireComercial();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    try {
        if ($a === 'excluir') {
            db()->prepare('DELETE FROM campanhas WHERE codigo_campanha=?')->execute([$_POST['codigo_campanha']]);
            flash('success', 'Campanha excluída!');

        } elseif ($a === 'criar' || $a === 'editar') {
            $cod   = trim($_POST['codigo_campanha'] ?? '');
            if ($cod === '') throw new Exception('Informe o código da campanha.');
            $tipo  = ($_POST['tipo'] ?? 'desconto') === 'bonificacao' ? 'bonificacao' : 'desconto';
            $canal = $_POST['canal_venda_id'] ?: null;
            $qtd   = (int)$_POST['quantidade'];
            $desc  = $tipo === 'bonificacao' ? 0.0 : (float)$_POST['desconto'];
            $valorAlvo = (float)($_POST['valor_alvo'] ?? 0);
            $valorAlvo = $valorAlvo > 0 ? $valorAlvo : null;

            // Bonificação: modo fixo (auto) ou selecionável (cliente escolhe até um limite)
            $bonifModo = 'fixo'; $bonifLimTipo = null; $bonifLimValor = null;
            if ($tipo === 'bonificacao') {
                $bonifModo = ($_POST['bonif_modo'] ?? 'fixo') === 'selecionavel' ? 'selecionavel' : 'fixo';
                if ($bonifModo === 'selecionavel') {
                    $bonifLimTipo  = ($_POST['bonif_limite_tipo'] ?? 'quantidade') === 'valor' ? 'valor' : 'quantidade';
                    $bonifLimValor = (float)($_POST['bonif_limite_valor'] ?? 0);
                    if ($bonifLimValor <= 0) throw new Exception('Informe o limite (quantidade ou valor) da bonificação selecionável.');
                }
            }

            // Condições combinadas (E): cada linha = [tipo, valor, qtd]
            $condClean = [];
            foreach (($_POST['cond'] ?? []) as $c) {
                $t  = $c['tipo']  ?? '';
                $v  = trim($c['valor'] ?? '');
                $cq = (int)($c['qtd'] ?? 0);
                if (in_array($t, ['linha', 'grupo', 'subgrupo'], true) && $v !== '' && $cq > 0) $condClean[] = [$t, $v, $cq];
            }

            // Produtos bonificados (fixo) / lista elegível (selecionável)
            $bonif = [];
            if ($tipo === 'bonificacao') {
                foreach (($_POST['bonif'] ?? []) as $b) {
                    $pid = (int)($b['produto_id'] ?? 0);
                    if (!$pid) continue;
                    $bq  = max(1, (int)($b['qtd'] ?? 1));
                    $bonif[$pid] = ($bonif[$pid] ?? 0) + $bq;
                }
                if (!$bonif) throw new Exception($bonifModo === 'selecionavel'
                    ? 'Adicione ao menos um produto à lista de bônus selecionáveis.'
                    : 'Adicione ao menos um produto bonificado.');
            }

            $prodIds = array_values(array_unique(array_filter(array_map('intval', $_POST['produto_ids'] ?? []))));

            db()->beginTransaction();

            // Substitui por completo as linhas da campanha (código original e/ou novo)
            $codOrig = trim($_POST['codigo_original'] ?? '');
            foreach (array_unique(array_filter([$codOrig, $cod])) as $delCod) {
                db()->prepare('DELETE FROM campanhas WHERE codigo_campanha=?')->execute([$delCod]);
                db()->prepare('DELETE FROM campanha_bonificacao WHERE codigo_campanha=?')->execute([$delCod]);
                db()->prepare('DELETE FROM campanha_condicoes WHERE codigo_campanha=?')->execute([$delCod]);
            }

            $ins = db()->prepare('INSERT INTO campanhas (codigo_campanha,produto_id,linha,grupo,subgrupo,canal_venda_id,quantidade,desconto,tipo,valor_alvo,bonif_modo,bonif_limite_tipo,bonif_limite_valor) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $insHeader = fn($pid) => $ins->execute([$cod, $pid, null, null, null, $canal, $qtd, $desc, $tipo, $valorAlvo, $bonifModo, $bonifLimTipo, $bonifLimValor]);

            if ($prodIds) {
                // Modo Produtos (legado): uma linha por produto (gatilho)
                foreach ($prodIds as $pid) $insHeader($pid);
            } else {
                // Linha cabeçalho — carrega os parâmetros da campanha (condições / valor / “todos”)
                $insHeader(null);
            }

            // Condições combinadas (E) — só no modo categoria (sem produtos)
            if (!$prodIds && $condClean) {
                $insC = db()->prepare('INSERT INTO campanha_condicoes (codigo_campanha,criterio_tipo,criterio_valor,quantidade) VALUES (?,?,?,?)');
                foreach ($condClean as $c) $insC->execute([$cod, $c[0], $c[1], $c[2]]);
            }

            // Grava os produtos bonificados / lista elegível
            if ($tipo === 'bonificacao') {
                $insB = db()->prepare('INSERT INTO campanha_bonificacao (codigo_campanha, produto_id, quantidade) VALUES (?,?,?)');
                foreach ($bonif as $pid => $bq) $insB->execute([$cod, $pid, $bq]);
            }

            db()->commit();
            flash('success', $a === 'criar' ? 'Campanha criada!' : 'Campanha atualizada!');
        }
    } catch (Exception $e) {
        if (db()->inTransaction()) db()->rollBack();
        flash('danger', 'Erro: ' . $e->getMessage());
    }
    header('Location: ' . BASE_URL . '/admin/cadastros/campanhas.php'); exit;
}

// Carrega todas as linhas e agrupa por código de campanha
$rows = db()->query('SELECT c.*, p.descricao_pt, p.codigo_produto, cv.canal
    FROM campanhas c
    LEFT JOIN produtos p ON p.id = c.produto_id
    LEFT JOIN canal_venda cv ON cv.id = c.canal_venda_id
    ORDER BY c.codigo_campanha, c.id')->fetchAll();

$campanhas = [];
foreach ($rows as $r) {
    $k = $r['codigo_campanha'];
    if (!isset($campanhas[$k])) {
        $campanhas[$k] = [
            'codigo_campanha'    => $r['codigo_campanha'],
            'canal_venda_id'     => $r['canal_venda_id'],
            'canal'              => $r['canal'],
            'quantidade'         => $r['quantidade'],
            'desconto'           => $r['desconto'],
            'tipo'               => $r['tipo'] ?? 'desconto',
            'valor_alvo'         => $r['valor_alvo'] ?? null,
            'bonif_modo'         => $r['bonif_modo'] ?? 'fixo',
            'bonif_limite_tipo'  => $r['bonif_limite_tipo'] ?? 'quantidade',
            'bonif_limite_valor' => $r['bonif_limite_valor'] ?? null,
            'produtos'           => [],
            'bonificados'        => [],
            'condicoes'          => [],
            'linha'              => '',
            'grupo'              => '',
            'subgrupo'           => '',
        ];
    }
    if ($r['produto_id']) {
        $campanhas[$k]['produtos'][] = [
            'id'             => (int)$r['produto_id'],
            'codigo_produto' => $r['codigo_produto'],
            'descricao_pt'   => $r['descricao_pt'],
        ];
    }
    if ($r['linha']    !== null && $r['linha']    !== '') $campanhas[$k]['linha']    = $r['linha'];
    if ($r['grupo']    !== null && $r['grupo']    !== '') $campanhas[$k]['grupo']    = $r['grupo'];
    if ($r['subgrupo'] !== null && $r['subgrupo'] !== '') $campanhas[$k]['subgrupo'] = $r['subgrupo'];
}

// Produtos bonificados por campanha
foreach (db()->query('SELECT cb.codigo_campanha, cb.produto_id, cb.quantidade, p.codigo_produto, p.descricao_pt
    FROM campanha_bonificacao cb JOIN produtos p ON p.id = cb.produto_id ORDER BY cb.id')->fetchAll() as $b) {
    if (isset($campanhas[$b['codigo_campanha']])) {
        $campanhas[$b['codigo_campanha']]['bonificados'][] = [
            'id'             => (int)$b['produto_id'],
            'codigo_produto' => $b['codigo_produto'],
            'descricao_pt'   => $b['descricao_pt'],
            'quantidade'     => (int)$b['quantidade'],
        ];
    }
}

// Condições combinadas (E) por campanha
foreach (db()->query('SELECT codigo_campanha, criterio_tipo, criterio_valor, quantidade
    FROM campanha_condicoes ORDER BY id')->fetchAll() as $cc) {
    if (isset($campanhas[$cc['codigo_campanha']])) {
        $campanhas[$cc['codigo_campanha']]['condicoes'][] = [
            'tipo'  => $cc['criterio_tipo'],
            'valor' => $cc['criterio_valor'],
            'qtd'   => (int)$cc['quantidade'],
        ];
    }
}

// Campanhas legadas (categoria única, sem condições): sintetiza condições a partir
// de linha/grupo/subgrupo para que apareçam no novo bloco de condições ao editar.
foreach ($campanhas as $k => &$cmp) {
    if ($cmp['condicoes'] || $cmp['produtos']) continue;
    $q = (int)$cmp['quantidade'];
    foreach (['linha', 'grupo', 'subgrupo'] as $crit) {
        if ($cmp[$crit] !== '' && $cmp[$crit] !== null && $q > 0) {
            $cmp['condicoes'][] = ['tipo' => $crit, 'valor' => $cmp[$crit], 'qtd' => $q];
        }
    }
}
unset($cmp);

$produtos  = db()->query('SELECT id, codigo_produto, descricao_pt FROM produtos WHERE status="ativo" ORDER BY descricao_pt')->fetchAll();
$linhas    = db()->query("SELECT DISTINCT linha    FROM produtos WHERE linha    IS NOT NULL AND linha    <> '' ORDER BY linha"   )->fetchAll(PDO::FETCH_COLUMN);
$grupos    = db()->query("SELECT DISTINCT grupo    FROM produtos WHERE grupo    IS NOT NULL AND grupo    <> '' ORDER BY grupo"   )->fetchAll(PDO::FETCH_COLUMN);
$subgrupos = db()->query("SELECT DISTINCT subgrupo FROM produtos WHERE subgrupo IS NOT NULL AND subgrupo <> '' ORDER BY subgrupo")->fetchAll(PDO::FETCH_COLUMN);
$canais    = db()->query('SELECT id, canal FROM canal_venda ORDER BY canal')->fetchAll();

$pageTitle = 'Cadastro de Campanhas';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-megaphone me-2"></i>Cadastro de Campanhas</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal" onclick="novoReg()">
        <i class="bi bi-plus-lg me-1"></i>Nova Campanha
    </button>
</div>
<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr><th>Código</th><th>Produtos</th><th>Condições (E) / Valor-alvo (OU)</th><th>Canal</th><th>Tipo / Recompensa</th><th>Ações</th></tr>
            </thead>
            <tbody>
            <?php if ($campanhas): foreach ($campanhas as $c):
                $labelCrit = ['linha' => 'Linha', 'grupo' => 'Grupo', 'subgrupo' => 'Subgrupo'];
                $criterios = array_map(fn($cd) => ($labelCrit[$cd['tipo']] ?? $cd['tipo']) . ' ' . $cd['valor'] . ' ≥ ' . $cd['qtd'], $c['condicoes']);
            ?>
                <tr>
                    <td><strong><?= e($c['codigo_campanha']) ?></strong></td>
                    <td>
                        <?php if ($c['produtos']): ?>
                            <span class="badge bg-primary rounded-pill mb-1"><?= count($c['produtos']) ?> produto<?= count($c['produtos']) != 1 ? 's' : '' ?></span>
                            <div class="small text-muted" style="max-width:340px">
                                <?= e(implode(', ', array_map(fn($p) => $p['descricao_pt'] ?: $p['codigo_produto'], $c['produtos']))) ?>
                            </div>
                            <div class="small text-muted">Qtd. mín.: <?= e($c['quantidade']) ?> un.</div>
                        <?php else: ?>
                            <span class="text-muted small"><?= $criterios ? '—' : 'Todos os produtos' ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if ($criterios): ?>
                            <?= e(implode(' E ', $criterios)) ?>
                            <?php if ($c['valor_alvo'] > 0): ?><div class="text-muted">OU valor ≥ <?= moedaBR($c['valor_alvo']) ?></div><?php endif; ?>
                        <?php elseif (!$c['produtos'] && $c['valor_alvo'] > 0): ?>
                            Valor ≥ <?= moedaBR($c['valor_alvo']) ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $c['canal'] ? '<span class="badge bg-secondary">'.e($c['canal']).'</span>' : '<span class="text-muted small">Todos</span>' ?></td>
                    <td>
                        <?php if ($c['tipo'] === 'bonificacao'): ?>
                            <?php if ($c['bonif_modo'] === 'selecionavel'): ?>
                                <span class="badge bg-info text-dark mb-1"><i class="bi bi-hand-index me-1"></i>Bônus selecionável</span>
                                <div class="small text-muted">Limite: <?= $c['bonif_limite_tipo'] === 'valor' ? moedaBR($c['bonif_limite_valor']) : ((int)$c['bonif_limite_valor'] . ' un.') ?></div>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark mb-1"><i class="bi bi-gift me-1"></i>Bonificação fixa</span>
                            <?php endif; ?>
                            <div class="small text-muted" style="max-width:300px">
                                <?= $c['bonificados']
                                    ? e(implode(', ', array_map(fn($b) => ($c['bonif_modo'] === 'selecionavel' ? '' : $b['quantidade'] . 'x ') . ($b['descricao_pt'] ?: $b['codigo_produto']), $c['bonificados'])))
                                    : '<span class="text-danger">sem produtos</span>' ?>
                            </div>
                        <?php else: ?>
                            <span class="badge bg-success"><?= e($c['desconto']) ?>%</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap">
                        <button class="btn btn-sm btn-outline-primary" onclick="editarReg(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)"><i class="bi bi-pencil"></i></button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Excluir campanha?')">
                            <input type="hidden" name="action" value="excluir">
                            <input type="hidden" name="codigo_campanha" value="<?= e($c['codigo_campanha']) ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Nenhuma campanha cadastrada.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<style>
#campProdWrap, #bonifWrapTable, #condTableWrap { max-height: 220px; overflow-y: auto; }
</style>
<div class="modal fade" id="modal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" id="fa" value="criar">
                <input type="hidden" name="codigo_original" id="f_cod_orig">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="mt">Nova Campanha</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Código da Campanha <span class="text-danger">*</span></label>
                            <input type="text" name="codigo_campanha" id="f_cod" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Canal de Venda</label>
                            <select name="canal_venda_id" id="f_canal" class="form-select">
                                <option value="">— Todos os canais —</option>
                                <?php foreach ($canais as $cv): ?>
                                <option value="<?= $cv['id'] ?>"><?= e($cv['canal']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Tipo de Campanha <span class="text-danger">*</span></label>
                            <select name="tipo" id="f_tipo" class="form-select" onchange="campSetTipo(this.value)">
                                <option value="desconto">Desconto (%)</option>
                                <option value="bonificacao">Produtos Bonificados</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Qtd. Mínima <small class="text-muted">(p/ Produtos)</small></label>
                            <input type="number" name="quantidade" id="f_qtd" class="form-control" value="1" min="1">
                        </div>
                        <div class="col-md-3" id="campDescWrap">
                            <label class="form-label fw-semibold">Desconto (%)</label>
                            <input type="number" step="0.01" name="desconto" id="f_desc" class="form-control" value="0">
                        </div>

                        <div class="col-12">
                            <hr class="my-1">
                            <small class="text-muted">
                                <strong>Gatilho da campanha:</strong> por <strong>Produtos</strong> (Qtd. Mínima acima)
                                <strong>ou</strong> por <strong>Condições (E)</strong> de categoria — os dois modos não se combinam.
                                Em Condições, <strong>todas</strong> precisam ser atingidas; o <strong>Valor-alvo</strong> aciona a campanha em alternativa (OU).
                            </small>
                        </div>

                        <!-- Modo: Produtos -->
                        <div class="col-12">
                            <label class="form-label fw-semibold">Produtos</label>
                            <div class="row g-2">
                                <div class="col-md-9">
                                    <div class="position-relative">
                                        <input type="text" id="campSearch" class="form-control"
                                               placeholder="Buscar produto por código ou nome..." autocomplete="off">
                                        <div id="campDropdown" class="list-group position-absolute w-100 shadow-sm"
                                             style="display:none;z-index:1056;top:100%;left:0;max-height:240px;overflow-y:auto"></div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <button type="button" class="btn btn-success w-100" id="campAddBtn" onclick="campProdAdd()">
                                        <i class="bi bi-plus-lg me-1"></i>Adicionar
                                    </button>
                                </div>
                            </div>
                            <div class="table-responsive mt-2 d-none" id="campProdWrap">
                                <table class="table table-sm table-bordered align-middle mb-0">
                                    <thead class="table-light">
                                        <tr><th style="width:130px">Código</th><th>Produto</th><th style="width:60px" class="text-center">—</th></tr>
                                    </thead>
                                    <tbody id="campProdBody"></tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Modo: Condições (E) por categoria -->
                        <div class="col-12"><div class="text-center text-muted small fw-semibold">— OU por condições de categoria (todas obrigatórias) —</div></div>
                        <div class="col-12" id="campCondWrap">
                            <div class="table-responsive d-none" id="condTableWrap">
                                <table class="table table-sm table-bordered align-middle mb-2">
                                    <thead class="table-light">
                                        <tr><th style="width:140px">Tipo</th><th>Categoria</th><th style="width:110px" class="text-center">Qtd. Mín.</th><th style="width:60px" class="text-center">—</th></tr>
                                    </thead>
                                    <tbody id="condBody"></tbody>
                                </table>
                            </div>
                            <button type="button" class="btn btn-outline-success btn-sm" id="condAddBtn" onclick="condAddRow()">
                                <i class="bi bi-plus-lg me-1"></i>Adicionar condição
                            </button>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Valor-alvo (OU)</label>
                            <input type="number" step="0.01" min="0" name="valor_alvo" id="f_valor_alvo" class="form-control" value="0" placeholder="0,00" oninput="campAtualizarExclusividade()">
                        </div>

                        <!-- Bonificação (apenas tipo Bonificação) -->
                        <div class="col-12 d-none" id="campBonifWrap">
                            <hr class="my-1">
                            <div class="row g-2 align-items-end mb-2">
                                <div class="col-md-5">
                                    <label class="form-label fw-semibold">Modo do bônus</label>
                                    <select name="bonif_modo" id="f_bonif_modo" class="form-select" onchange="campSetBonifModo(this.value)">
                                        <option value="fixo">Fixo (gerado automaticamente)</option>
                                        <option value="selecionavel">Selecionável pelo cliente</option>
                                    </select>
                                </div>
                                <div class="col-md-4 d-none" id="bonifLimTipoWrap">
                                    <label class="form-label fw-semibold">Limite por</label>
                                    <select name="bonif_limite_tipo" id="f_bonif_lim_tipo" class="form-select" onchange="campSetBonifModo(document.getElementById('f_bonif_modo').value)">
                                        <option value="quantidade">Quantidade (un.)</option>
                                        <option value="valor">Valor (R$)</option>
                                    </select>
                                </div>
                                <div class="col-md-3 d-none" id="bonifLimValorWrap">
                                    <label class="form-label fw-semibold" id="bonifLimValorLabel">Limite (un.)</label>
                                    <input type="number" step="0.01" min="0" name="bonif_limite_valor" id="f_bonif_lim_valor" class="form-control" value="0">
                                </div>
                            </div>
                            <label class="form-label fw-semibold"><i class="bi bi-gift me-1 text-warning"></i><span id="bonifTituloLista">Produtos Bonificados</span></label>
                            <div class="text-muted small mb-2" id="bonifAjudaFixo">
                                A quantidade bonificada é <strong>multiplicada</strong> conforme o total comprado atinge o gatilho
                                (ex.: mínimo 50, comprou 100 → bônus ×2).
                            </div>
                            <div class="text-muted small mb-2 d-none" id="bonifAjudaSelec">
                                Lista de produtos que o <strong>cliente</strong> poderá escolher como bônus, até o limite definido acima.
                                A coluna Qtd. funciona como teto opcional por produto.
                            </div>
                            <div class="row g-2">
                                <div class="col-md-7">
                                    <div class="position-relative">
                                        <input type="text" id="bonifSearch" class="form-control"
                                               placeholder="Buscar produto por código ou nome..." autocomplete="off">
                                        <div id="bonifDropdown" class="list-group position-absolute w-100 shadow-sm"
                                             style="display:none;z-index:1056;top:100%;left:0;max-height:240px;overflow-y:auto"></div>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <input type="number" id="bonifQtd" class="form-control" value="1" min="1" title="Quantidade bonificada">
                                </div>
                                <div class="col-md-3">
                                    <button type="button" class="btn btn-warning w-100" onclick="bonifAdd()">
                                        <i class="bi bi-plus-lg me-1"></i>Adicionar
                                    </button>
                                </div>
                            </div>
                            <div class="table-responsive mt-2 d-none" id="bonifWrapTable">
                                <table class="table table-sm table-bordered align-middle mb-0">
                                    <thead class="table-light">
                                        <tr><th style="width:130px">Código</th><th>Produto</th><th style="width:110px" class="text-center">Qtd. Bônus</th><th style="width:60px" class="text-center">—</th></tr>
                                    </thead>
                                    <tbody id="bonifBody"></tbody>
                                </table>
                            </div>
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
var _campProdutos = <?= json_encode($produtos, JSON_UNESCAPED_UNICODE) ?>;
var _campCats     = {
    linha:    <?= json_encode(array_values($linhas),    JSON_UNESCAPED_UNICODE) ?>,
    grupo:    <?= json_encode(array_values($grupos),    JSON_UNESCAPED_UNICODE) ?>,
    subgrupo: <?= json_encode(array_values($subgrupos), JSON_UNESCAPED_UNICODE) ?>
};
var _campSel      = null;
var _condSeq      = 0;

function campEsc(s)     { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function campEscAttr(s) { return campEsc(s).replace(/"/g,'&quot;'); }
function campNorm(s)    { return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }

// ---- Produtos (lista) ----
function campProdAddRow(id, codigo, nome) {
    var tr = document.createElement('tr');
    tr.innerHTML =
        '<td class="fw-semibold">' + campEsc(codigo) + '<input type="hidden" name="produto_ids[]" value="' + parseInt(id, 10) + '"></td>' +
        '<td>' + campEsc(nome) + '</td>' +
        '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" title="Remover" onclick="campProdRemove(this)"><i class="bi bi-x-lg"></i></button></td>';
    document.getElementById('campProdBody').appendChild(tr);
    tr.scrollIntoView({ block: 'nearest' });
}
function campProdRemove(btn) { btn.closest('tr').remove(); campAtualizarExclusividade(); }

function campProdIds() {
    return Array.prototype.map.call(
        document.querySelectorAll('#campProdBody input[name="produto_ids[]"]'),
        function(i) { return String(i.value); }
    );
}

function campProdAdd() {
    if (!_campSel) { document.getElementById('campSearch').focus(); alert('Selecione um produto da lista.'); return; }
    if (campProdIds().indexOf(String(_campSel.id)) === -1) {
        campProdAddRow(_campSel.id, _campSel.codigo, _campSel.nome);
    }
    _campSel = null;
    document.getElementById('campSearch').value = '';
    document.getElementById('campDropdown').style.display = 'none';
    document.getElementById('campSearch').focus({ preventScroll: true });
    campAtualizarExclusividade();
}

// ---- Condições (E) por categoria ----
function condCatOptions(tipo, sel) {
    var lista = _campCats[tipo] || [];
    return '<option value="">— selecione —</option>' + lista.map(function(v) {
        return '<option value="' + campEscAttr(v) + '"' + (String(v) === String(sel) ? ' selected' : '') + '>' + campEsc(v) + '</option>';
    }).join('');
}
function condOnTipoChange(sel) {
    var tr  = sel.closest('tr');
    var val = tr.querySelector('select[data-role="valor"]');
    val.innerHTML = condCatOptions(sel.value, '');
}
function condAddRow(tipo, valor, qtd) {
    var i  = _condSeq++;
    tipo = tipo || 'grupo';
    var tr = document.createElement('tr');
    tr.innerHTML =
        '<td><select class="form-select form-select-sm" name="cond[' + i + '][tipo]" data-role="tipo" onchange="condOnTipoChange(this)">' +
            '<option value="linha"'    + (tipo === 'linha'    ? ' selected' : '') + '>Linha</option>' +
            '<option value="grupo"'    + (tipo === 'grupo'    ? ' selected' : '') + '>Grupo</option>' +
            '<option value="subgrupo"' + (tipo === 'subgrupo' ? ' selected' : '') + '>Subgrupo</option>' +
        '</select></td>' +
        '<td><select class="form-select form-select-sm" name="cond[' + i + '][valor]" data-role="valor">' + condCatOptions(tipo, valor) + '</select></td>' +
        '<td><input type="number" min="1" value="' + (parseInt(qtd, 10) || 1) + '" name="cond[' + i + '][qtd]" class="form-control form-control-sm text-center mx-auto" style="max-width:90px"></td>' +
        '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" title="Remover" onclick="condRemove(this)"><i class="bi bi-x-lg"></i></button></td>';
    document.getElementById('condBody').appendChild(tr);
    condAtualizar();
}
function condRemove(btn) { btn.closest('tr').remove(); condAtualizar(); }
function condAtualizar() {
    var n = document.querySelectorAll('#condBody tr').length;
    document.getElementById('condTableWrap').classList.toggle('d-none', n === 0);
    campAtualizarExclusividade();
}

// ---- Exclusividade Produtos x Condições ----
function campAtualizarExclusividade() {
    var nProd = document.querySelectorAll('#campProdBody tr').length;
    var nCond = document.querySelectorAll('#condBody tr').length;
    var hasValor = parseFloat(document.getElementById('f_valor_alvo').value || 0) > 0;
    var hasCond  = nCond > 0 || hasValor;

    document.getElementById('campProdWrap').classList.toggle('d-none', nProd === 0);

    // Há produtos → bloqueia condições/valor; há condições/valor → bloqueia produtos
    document.getElementById('condAddBtn').disabled       = nProd > 0;
    document.getElementById('f_valor_alvo').disabled     = nProd > 0;
    document.getElementById('campSearch').disabled       = hasCond;
    document.getElementById('campAddBtn').disabled       = hasCond;
    document.getElementById('f_qtd').disabled            = hasCond;
}

// ---- Modo do bônus (fixo x selecionável) ----
function campSetBonifModo(modo) {
    var selec = (modo === 'selecionavel');
    document.getElementById('bonifLimTipoWrap').classList.toggle('d-none', !selec);
    document.getElementById('bonifLimValorWrap').classList.toggle('d-none', !selec);
    document.getElementById('bonifAjudaFixo').classList.toggle('d-none', selec);
    document.getElementById('bonifAjudaSelec').classList.toggle('d-none', !selec);
    document.getElementById('bonifTituloLista').textContent = selec ? 'Produtos elegíveis (cliente escolhe)' : 'Produtos Bonificados';
    if (selec) {
        var porValor = document.getElementById('f_bonif_lim_tipo').value === 'valor';
        document.getElementById('bonifLimValorLabel').textContent = porValor ? 'Limite (R$)' : 'Limite (un.)';
    }
}

// ---- Autocomplete de produtos ----
(function() {
    var inp = document.getElementById('campSearch');
    var dd  = document.getElementById('campDropdown');
    inp.addEventListener('input', function() {
        var q = campNorm(inp.value.trim());
        _campSel = null;
        if (q.length < 1) { dd.style.display = 'none'; return; }
        var existentes = campProdIds();
        var lista = _campProdutos.filter(function(p) {
            if (existentes.indexOf(String(p.id)) !== -1) return false;
            return campNorm(p.descricao_pt).includes(q) || campNorm(p.codigo_produto).includes(q);
        }).slice(0, 15);

        if (!lista.length) {
            dd.innerHTML = '<div class="list-group-item small text-muted py-2">Nenhum produto encontrado</div>';
        } else {
            dd.innerHTML = lista.map(function(p) {
                return '<button type="button" class="list-group-item list-group-item-action py-2 px-3 small"' +
                    ' data-id="' + p.id + '" data-codigo="' + campEscAttr(p.codigo_produto) + '" data-nome="' + campEscAttr(p.descricao_pt) + '">' +
                    '<span class="badge bg-secondary me-2">' + campEsc(p.codigo_produto) + '</span>' + campEsc(p.descricao_pt) + '</button>';
            }).join('');
            dd.querySelectorAll('button').forEach(function(b) {
                b.addEventListener('mousedown', function(ev) {
                    ev.preventDefault();
                    _campSel = { id: b.dataset.id, codigo: b.dataset.codigo, nome: b.dataset.nome };
                    inp.value = b.dataset.nome + ' (' + b.dataset.codigo + ')';
                    dd.style.display = 'none';
                });
            });
        }
        dd.style.display = '';
    });
    inp.addEventListener('blur', function() { setTimeout(function() { dd.style.display = 'none'; }, 150); });
})();

// ==== Produtos Bonificados ====
var _bonifSel = null;
var bonifSeq  = 0;

function bonifAddRow(id, codigo, nome, qtd) {
    var idx = bonifSeq++;
    var q   = parseInt(qtd, 10) || 1;
    var tr  = document.createElement('tr');
    tr.innerHTML =
        '<td class="fw-semibold">' + campEsc(codigo) + '<input type="hidden" name="bonif[' + idx + '][produto_id]" value="' + parseInt(id, 10) + '"></td>' +
        '<td>' + campEsc(nome) + '</td>' +
        '<td class="text-center"><input type="number" min="1" value="' + q + '" name="bonif[' + idx + '][qtd]" class="form-control form-control-sm text-center mx-auto" style="max-width:80px"></td>' +
        '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" title="Remover" onclick="bonifRemove(this)"><i class="bi bi-x-lg"></i></button></td>';
    document.getElementById('bonifBody').appendChild(tr);
    bonifAtualizar();
    tr.scrollIntoView({ block: 'nearest' });
}
function bonifRemove(btn) { btn.closest('tr').remove(); bonifAtualizar(); }
function bonifAtualizar() {
    var n = document.querySelectorAll('#bonifBody tr').length;
    document.getElementById('bonifWrapTable').classList.toggle('d-none', n === 0);
}
function bonifIds() {
    return Array.prototype.map.call(
        document.querySelectorAll('#bonifBody input[name$="[produto_id]"]'),
        function(i) { return String(i.value); }
    );
}
function bonifAdd() {
    if (!_bonifSel) { document.getElementById('bonifSearch').focus(); alert('Selecione um produto da lista.'); return; }
    var qtd = parseInt(document.getElementById('bonifQtd').value, 10) || 1;
    if (qtd < 1) qtd = 1;
    if (bonifIds().indexOf(String(_bonifSel.id)) === -1) {
        bonifAddRow(_bonifSel.id, _bonifSel.codigo, _bonifSel.nome, qtd);
    }
    _bonifSel = null;
    document.getElementById('bonifSearch').value = '';
    document.getElementById('bonifQtd').value = '1';
    document.getElementById('bonifDropdown').style.display = 'none';
    document.getElementById('bonifSearch').focus({ preventScroll: true });
}
(function() {
    var inp = document.getElementById('bonifSearch');
    var dd  = document.getElementById('bonifDropdown');
    inp.addEventListener('input', function() {
        var q = campNorm(inp.value.trim());
        _bonifSel = null;
        if (q.length < 1) { dd.style.display = 'none'; return; }
        var lista = _campProdutos.filter(function(p) {
            return campNorm(p.descricao_pt).includes(q) || campNorm(p.codigo_produto).includes(q);
        }).slice(0, 15);
        if (!lista.length) {
            dd.innerHTML = '<div class="list-group-item small text-muted py-2">Nenhum produto encontrado</div>';
        } else {
            dd.innerHTML = lista.map(function(p) {
                return '<button type="button" class="list-group-item list-group-item-action py-2 px-3 small"' +
                    ' data-id="' + p.id + '" data-codigo="' + campEscAttr(p.codigo_produto) + '" data-nome="' + campEscAttr(p.descricao_pt) + '">' +
                    '<span class="badge bg-secondary me-2">' + campEsc(p.codigo_produto) + '</span>' + campEsc(p.descricao_pt) + '</button>';
            }).join('');
            dd.querySelectorAll('button').forEach(function(b) {
                b.addEventListener('mousedown', function(ev) {
                    ev.preventDefault();
                    _bonifSel = { id: b.dataset.id, codigo: b.dataset.codigo, nome: b.dataset.nome };
                    inp.value = b.dataset.nome + ' (' + b.dataset.codigo + ')';
                    dd.style.display = 'none';
                });
            });
        }
        dd.style.display = '';
    });
    inp.addEventListener('blur', function() { setTimeout(function() { dd.style.display = 'none'; }, 150); });
})();

// Alterna entre Desconto e Bonificação
function campSetTipo(tipo) {
    var ehBonif = (tipo === 'bonificacao');
    document.getElementById('campDescWrap').classList.toggle('d-none', ehBonif);
    document.getElementById('campBonifWrap').classList.toggle('d-none', !ehBonif);
}

// ---- Reset / abrir modal ----
function campLimpar() {
    document.getElementById('campProdBody').innerHTML = '';
    document.getElementById('campSearch').value = '';
    document.getElementById('campDropdown').style.display = 'none';
    _campSel = null;
    document.getElementById('condBody').innerHTML = '';
    document.getElementById('f_valor_alvo').value = 0;
    condAtualizar();
    document.getElementById('bonifBody').innerHTML = '';
    document.getElementById('bonifSearch').value = '';
    document.getElementById('bonifQtd').value = '1';
    document.getElementById('f_bonif_modo').value = 'fixo';
    document.getElementById('f_bonif_lim_tipo').value = 'quantidade';
    document.getElementById('f_bonif_lim_valor').value = 0;
    campSetBonifModo('fixo');
    _bonifSel = null;
    bonifAtualizar();
    campAtualizarExclusividade();
}

function novoReg() {
    document.getElementById('mt').textContent      = 'Nova Campanha';
    document.getElementById('fa').value            = 'criar';
    document.getElementById('f_cod_orig').value    = '';
    document.getElementById('f_cod').value         = '';
    document.getElementById('f_canal').value       = '';
    document.getElementById('f_qtd').value         = 1;
    document.getElementById('f_desc').value        = 0;
    document.getElementById('f_tipo').value        = 'desconto';
    campLimpar();
    campSetTipo('desconto');
}

function editarReg(d) {
    document.getElementById('mt').textContent      = 'Editar Campanha';
    document.getElementById('fa').value            = 'editar';
    document.getElementById('f_cod_orig').value    = d.codigo_campanha || '';
    document.getElementById('f_cod').value         = d.codigo_campanha || '';
    document.getElementById('f_canal').value       = d.canal_venda_id  || '';
    document.getElementById('f_qtd').value         = d.quantidade || 1;
    document.getElementById('f_desc').value        = d.desconto   || 0;
    document.getElementById('f_tipo').value        = d.tipo || 'desconto';
    campLimpar();
    (d.produtos || []).forEach(function(p) {
        campProdAddRow(p.id, p.codigo_produto, p.descricao_pt);
    });
    (d.condicoes || []).forEach(function(c) {
        condAddRow(c.tipo, c.valor, c.qtd);
    });
    document.getElementById('f_valor_alvo').value = parseFloat(d.valor_alvo || 0) || 0;
    document.getElementById('f_bonif_modo').value     = d.bonif_modo || 'fixo';
    document.getElementById('f_bonif_lim_tipo').value = d.bonif_limite_tipo || 'quantidade';
    document.getElementById('f_bonif_lim_valor').value = parseFloat(d.bonif_limite_valor || 0) || 0;
    (d.bonificados || []).forEach(function(b) {
        bonifAddRow(b.id, b.codigo_produto, b.descricao_pt, b.quantidade);
    });
    campSetBonifModo(d.bonif_modo || 'fixo');
    condAtualizar();
    campAtualizarExclusividade();
    campSetTipo(d.tipo || 'desconto');
    new bootstrap.Modal(document.getElementById('modal')).show();
}
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
