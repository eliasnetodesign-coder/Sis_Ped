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
            $ativo = ($_POST['ativo'] ?? '1') === '0' ? 0 : 1;
            $desc  = $tipo === 'bonificacao' ? 0.0 : (float)$_POST['desconto'];
            if ($tipo === 'desconto' && $desc <= 0) throw new Exception('Informe o percentual de desconto.');

            // Condições combinadas (E): cada condição é um filtro composto
            // (linha E grupo E subgrupo E produto) + mínimo por quantidade OU valor.
            $condClean = [];
            foreach (($_POST['cond'] ?? []) as $c) {
                $fl = trim($c['linha']    ?? '');
                $fg = trim($c['grupo']    ?? '');
                $fs = trim($c['subgrupo'] ?? '');
                $fp = (int)($c['produto']  ?? 0);
                if ($fl === '' && $fg === '' && $fs === '' && $fp <= 0) continue; // sem nenhum filtro
                $modo = ($c['modo'] ?? 'quantidade') === 'valor' ? 'valor' : 'quantidade';
                $min  = (float)str_replace(',', '.', $c['min'] ?? '0');
                if ($modo === 'valor') {
                    if ($min <= 0) continue;
                    $condClean[] = ['valor', 0, $min, $fl, $fg, $fs, $fp];
                } else {
                    if ((int)$min <= 0) continue;
                    $condClean[] = ['quantidade', (int)$min, null, $fl, $fg, $fs, $fp];
                }
            }
            if (!$condClean) throw new Exception('Adicione ao menos uma condição (com categoria/produto e mínimo).');

            // Alvos do desconto (opcional): filtro composto (linha E grupo E subgrupo E produto)
            $alvoClean = [];
            if ($tipo === 'desconto') {
                foreach (($_POST['alvo'] ?? []) as $al) {
                    $fl = trim($al['linha']    ?? '');
                    $fg = trim($al['grupo']    ?? '');
                    $fs = trim($al['subgrupo'] ?? '');
                    $fp = (int)($al['produto']  ?? 0);
                    if ($fl === '' && $fg === '' && $fs === '' && $fp <= 0) continue;
                    $alvoClean[] = [$fl, $fg, $fs, $fp];
                }
            }

            // Bonificação: modo fixo ou selecionável; selecionável por lista de produtos ou categoria
            $bonifModo = 'fixo'; $bonifLimTipo = null; $bonifLimValor = null; $bonifSelecModo = 'produtos';
            $bonif = []; $poolClean = [];
            if ($tipo === 'bonificacao') {
                $bonifModo = ($_POST['bonif_modo'] ?? 'fixo') === 'selecionavel' ? 'selecionavel' : 'fixo';

                if ($bonifModo === 'selecionavel') {
                    $bonifLimTipo  = ($_POST['bonif_limite_tipo'] ?? 'quantidade') === 'valor' ? 'valor' : 'quantidade';
                    $bonifLimValor = (float)str_replace(',', '.', $_POST['bonif_limite_valor'] ?? '0');
                    if ($bonifLimValor <= 0) throw new Exception('Informe o limite (quantidade ou valor) da bonificação selecionável.');
                    $bonifSelecModo = ($_POST['bonif_selec_modo'] ?? 'produtos') === 'categoria' ? 'categoria' : 'produtos';
                }

                if ($bonifModo === 'selecionavel' && $bonifSelecModo === 'categoria') {
                    // Pool por categoria/produto
                    foreach (($_POST['pool'] ?? []) as $pl) {
                        $t = $pl['tipo'] ?? '';
                        $v = trim($pl['valor'] ?? '');
                        if (in_array($t, ['produto', 'linha', 'grupo', 'subgrupo'], true) && $v !== '') $poolClean[] = [$t, $v];
                    }
                    if (!$poolClean) throw new Exception('Defina ao menos uma categoria/produto no pool selecionável.');
                } else {
                    // Lista fixa de produtos (fixo) ou lista elegível (selecionável por produtos)
                    foreach (($_POST['bonif'] ?? []) as $b) {
                        $pid = (int)($b['produto_id'] ?? 0);
                        if (!$pid) continue;
                        $bq = max(1, (int)($b['qtd'] ?? 1));
                        $bonif[$pid] = ($bonif[$pid] ?? 0) + $bq;
                    }
                    if (!$bonif) throw new Exception($bonifModo === 'selecionavel'
                        ? 'Adicione ao menos um produto à lista de bônus selecionáveis.'
                        : 'Adicione ao menos um produto bonificado.');
                }
            }

            db()->beginTransaction();

            // Substitui por completo as linhas da campanha (código original e/ou novo)
            $codOrig = trim($_POST['codigo_original'] ?? '');
            foreach (array_unique(array_filter([$codOrig, $cod])) as $delCod) {
                db()->prepare('DELETE FROM campanhas WHERE codigo_campanha=?')->execute([$delCod]);
                db()->prepare('DELETE FROM campanha_bonificacao WHERE codigo_campanha=?')->execute([$delCod]);
                db()->prepare('DELETE FROM campanha_condicoes WHERE codigo_campanha=?')->execute([$delCod]);
                try { db()->prepare('DELETE FROM campanha_desconto_alvo WHERE codigo_campanha=?')->execute([$delCod]); } catch (PDOException $e) {}
                try { db()->prepare('DELETE FROM campanha_bonif_pool WHERE codigo_campanha=?')->execute([$delCod]); } catch (PDOException $e) {}
            }

            // Linha cabeçalho — parâmetros da campanha (gatilho fica nas condições)
            db()->prepare('INSERT INTO campanhas
                (codigo_campanha,produto_id,linha,grupo,subgrupo,canal_venda_id,quantidade,desconto,tipo,valor_alvo,bonif_modo,bonif_limite_tipo,bonif_limite_valor,ativo,bonif_selec_modo)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$cod, null, null, null, null, $canal, 0, $desc, $tipo, null, $bonifModo, $bonifLimTipo, $bonifLimValor, $ativo, $bonifSelecModo]);

            // Condições (E) — filtro composto por condição
            $insC = db()->prepare('INSERT INTO campanha_condicoes
                (codigo_campanha,criterio_tipo,criterio_valor,quantidade,criterio_modo,valor_min,cond_linha,cond_grupo,cond_subgrupo,cond_produto_id)
                VALUES (?,?,?,?,?,?,?,?,?,?)');
            foreach ($condClean as $c) {
                // $c = [modo, qtd, valor_min, linha, grupo, subgrupo, produto_id]
                $insC->execute([$cod, 'composto', '', $c[1], $c[0], $c[2], $c[3] ?: null, $c[4] ?: null, $c[5] ?: null, $c[6] ?: null]);
            }

            // Alvos do desconto — filtro composto (linha, grupo, subgrupo, produto)
            if ($tipo === 'desconto' && $alvoClean) {
                $insA = db()->prepare('INSERT INTO campanha_desconto_alvo
                    (codigo_campanha,alvo_tipo,alvo_valor,alvo_linha,alvo_grupo,alvo_subgrupo,alvo_produto_id)
                    VALUES (?,?,?,?,?,?,?)');
                foreach ($alvoClean as $al) $insA->execute([$cod, 'composto', '', $al[0] ?: null, $al[1] ?: null, $al[2] ?: null, $al[3] ?: null]);
            }

            // Bonificação: lista de produtos ou pool por categoria
            if ($tipo === 'bonificacao') {
                if ($bonifModo === 'selecionavel' && $bonifSelecModo === 'categoria') {
                    $insP = db()->prepare('INSERT INTO campanha_bonif_pool (codigo_campanha,alvo_tipo,alvo_valor) VALUES (?,?,?)');
                    foreach ($poolClean as $pl) $insP->execute([$cod, $pl[0], $pl[1]]);
                } else {
                    $insB = db()->prepare('INSERT INTO campanha_bonificacao (codigo_campanha, produto_id, quantidade) VALUES (?,?,?)');
                    foreach ($bonif as $pid => $bq) $insB->execute([$cod, $pid, $bq]);
                }
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
$rows = db()->query('SELECT c.*, cv.canal
    FROM campanhas c
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
            'ativo'              => (int)($r['ativo'] ?? 1),
            'bonif_modo'         => $r['bonif_modo'] ?? 'fixo',
            'bonif_selec_modo'   => $r['bonif_selec_modo'] ?? 'produtos',
            'bonif_limite_tipo'  => $r['bonif_limite_tipo'] ?? 'quantidade',
            'bonif_limite_valor' => $r['bonif_limite_valor'] ?? null,
            'produtos'           => [],   // legado (gatilho por produto)
            'bonificados'        => [],
            'condicoes'          => [],
            'desconto_alvos'     => [],
            'bonif_pool'         => [],
            'linha'              => '',
            'grupo'              => '',
            'subgrupo'           => '',
        ];
    }
    if ($r['produto_id']) $campanhas[$k]['produtos'][] = ['id' => (int)$r['produto_id']];
    if ($r['linha']    !== null && $r['linha']    !== '') $campanhas[$k]['linha']    = $r['linha'];
    if ($r['grupo']    !== null && $r['grupo']    !== '') $campanhas[$k]['grupo']    = $r['grupo'];
    if ($r['subgrupo'] !== null && $r['subgrupo'] !== '') $campanhas[$k]['subgrupo'] = $r['subgrupo'];
}

// Mapa de produtos para descrição legível em condições/alvos/pool
$prodById = [];
foreach (db()->query('SELECT id, codigo_produto, descricao_pt FROM produtos')->fetchAll() as $p) {
    $prodById[(int)$p['id']] = $p['descricao_pt'] ?: $p['codigo_produto'];
}

// Produtos bonificados por campanha (lista fixa / elegível)
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

// Condições combinadas (E) por campanha — cada uma é um filtro composto
$condCols = 'codigo_campanha, criterio_tipo, criterio_valor, quantidade, criterio_modo, valor_min, cond_linha, cond_grupo, cond_subgrupo, cond_produto_id';
try { $condRows = db()->query("SELECT $condCols FROM campanha_condicoes ORDER BY id")->fetchAll(); }
catch (PDOException $e) {
    try { $condRows = db()->query("SELECT codigo_campanha, criterio_tipo, criterio_valor, quantidade, criterio_modo, valor_min FROM campanha_condicoes ORDER BY id")->fetchAll(); }
    catch (PDOException $e2) { $condRows = db()->query("SELECT codigo_campanha, criterio_tipo, criterio_valor, quantidade FROM campanha_condicoes ORDER BY id")->fetchAll(); }
}
foreach ($condRows as $cc) {
    if (!isset($campanhas[$cc['codigo_campanha']])) continue;
    $f = condFiltro($cc);  // resolve cond_* ou critério legado
    $campanhas[$cc['codigo_campanha']]['condicoes'][] = [
        'linha'     => $f['linha'] ?? '',
        'grupo'     => $f['grupo'] ?? '',
        'subgrupo'  => $f['subgrupo'] ?? '',
        'produto'   => $f['produto'] ?? '',
        'qtd'       => (int)$cc['quantidade'],
        'modo'      => ($cc['criterio_modo'] ?? 'quantidade') === 'valor' ? 'valor' : 'quantidade',
        'valor_min' => (float)($cc['valor_min'] ?? 0),
    ];
}

// Alvos de desconto por campanha — filtro composto (linha, grupo, subgrupo, produto)
try {
    $alvoRows = db()->query('SELECT codigo_campanha, alvo_tipo, alvo_valor, alvo_linha, alvo_grupo, alvo_subgrupo, alvo_produto_id
        FROM campanha_desconto_alvo ORDER BY id')->fetchAll();
} catch (PDOException $e) {
    try { $alvoRows = db()->query('SELECT codigo_campanha, alvo_tipo, alvo_valor FROM campanha_desconto_alvo ORDER BY id')->fetchAll(); }
    catch (PDOException $e2) { $alvoRows = []; }
}
foreach ($alvoRows as $a) {
    if (isset($campanhas[$a['codigo_campanha']])) $campanhas[$a['codigo_campanha']]['desconto_alvos'][] = alvoFiltro($a);
}

// Pool de bonificação por campanha (critério único: tipo + valor)
try {
    foreach (db()->query('SELECT codigo_campanha, alvo_tipo, alvo_valor FROM campanha_bonif_pool ORDER BY id')->fetchAll() as $a) {
        if (isset($campanhas[$a['codigo_campanha']])) {
            $campanhas[$a['codigo_campanha']]['bonif_pool'][] = ['tipo' => $a['alvo_tipo'], 'valor' => $a['alvo_valor']];
        }
    }
} catch (PDOException $e) { /* tabela ainda não existe */ }

// Campanhas legadas (sem condições): sintetiza condições compostas a partir de
// produtos/categoria para que apareçam no novo bloco de condições ao editar.
foreach ($campanhas as $k => &$cmp) {
    if ($cmp['condicoes']) continue;
    $q = (int)$cmp['quantidade'];
    if ($q <= 0) continue;
    if ($cmp['produtos']) {
        foreach ($cmp['produtos'] as $p) $cmp['condicoes'][] = ['linha' => '', 'grupo' => '', 'subgrupo' => '', 'produto' => $p['id'], 'qtd' => $q, 'modo' => 'quantidade', 'valor_min' => 0];
    } else {
        $e = ['linha' => $cmp['linha'] ?: '', 'grupo' => $cmp['grupo'] ?: '', 'subgrupo' => $cmp['subgrupo'] ?: '', 'produto' => '', 'qtd' => $q, 'modo' => 'quantidade', 'valor_min' => 0];
        if ($e['linha'] !== '' || $e['grupo'] !== '' || $e['subgrupo'] !== '') $cmp['condicoes'][] = $e;
    }
}
unset($cmp);

$produtos  = db()->query('SELECT id, codigo_produto, descricao_pt FROM produtos WHERE status="ativo" ORDER BY descricao_pt')->fetchAll();
$linhas    = db()->query("SELECT DISTINCT linha    FROM produtos WHERE linha    IS NOT NULL AND linha    <> '' ORDER BY linha"   )->fetchAll(PDO::FETCH_COLUMN);
$grupos    = db()->query("SELECT DISTINCT grupo    FROM produtos WHERE grupo    IS NOT NULL AND grupo    <> '' ORDER BY grupo"   )->fetchAll(PDO::FETCH_COLUMN);
$subgrupos = db()->query("SELECT DISTINCT subgrupo FROM produtos WHERE subgrupo IS NOT NULL AND subgrupo <> '' ORDER BY subgrupo")->fetchAll(PDO::FETCH_COLUMN);
$canais    = db()->query('SELECT id, canal FROM canal_venda ORDER BY canal')->fetchAll();

// Rótulo legível de uma condição composta OU de um alvo/pool single {tipo,valor}
function critLabel(array $cd, array $prodById): string {
    if (isset($cd['tipo'])) { // alvo de desconto / pool de bonificação (critério único)
        $rot = ['produto' => 'Produto', 'linha' => 'Linha', 'grupo' => 'Grupo', 'subgrupo' => 'Subgrupo'][$cd['tipo']] ?? ucfirst($cd['tipo']);
        $nome = $cd['tipo'] === 'produto' ? ($prodById[(int)$cd['valor']] ?? ('#' . $cd['valor'])) : $cd['valor'];
        return $rot . ' ' . $nome;
    }
    // condição composta (linha E grupo E subgrupo E produto)
    $parts = [];
    if (!empty($cd['linha']))    $parts[] = 'Linha ' . $cd['linha'];
    if (!empty($cd['grupo']))    $parts[] = 'Grupo ' . $cd['grupo'];
    if (!empty($cd['subgrupo'])) $parts[] = 'Subgrupo ' . $cd['subgrupo'];
    if (!empty($cd['produto']))  $parts[] = 'Produto ' . ($prodById[(int)$cd['produto']] ?? ('#' . $cd['produto']));
    $out = implode(' + ', $parts);
    if (array_key_exists('modo', $cd)) {
        $out .= ($cd['modo'] ?? 'quantidade') === 'valor'
            ? ' ≥ ' . moedaBR($cd['valor_min'] ?? 0)
            : ' ≥ ' . (int)($cd['qtd'] ?? 0) . ' un.';
    }
    return $out;
}

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
                <tr><th>Código</th><th>Status</th><th>Condições (E)</th><th>Canal</th><th>Tipo / Recompensa</th><th>Ações</th></tr>
            </thead>
            <tbody>
            <?php if ($campanhas): foreach ($campanhas as $c):
                $criterios = array_map(fn($cd) => critLabel($cd, $prodById), $c['condicoes']);
                $alvos     = array_map(fn($cd) => critLabel($cd, $prodById), $c['desconto_alvos']);
            ?>
                <tr class="<?= $c['ativo'] ? '' : 'opacity-50' ?>">
                    <td><strong><?= e($c['codigo_campanha']) ?></strong></td>
                    <td>
                        <?php if ($c['ativo']): ?>
                            <span class="badge bg-success">Ativa</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Inativa</span>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if ($criterios): ?>
                            <?= e(implode(' E ', $criterios)) ?>
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
                                <div class="small text-muted" style="max-width:300px">
                                    <?php if ($c['bonif_selec_modo'] === 'categoria'): ?>
                                        Pool: <?= $c['bonif_pool'] ? e(implode(', ', array_map(fn($p) => critLabel($p, $prodById), $c['bonif_pool']))) : '<span class="text-danger">vazio</span>' ?>
                                    <?php else: ?>
                                        <?= $c['bonificados'] ? e(implode(', ', array_map(fn($b) => $b['descricao_pt'] ?: $b['codigo_produto'], $c['bonificados']))) : '<span class="text-danger">sem produtos</span>' ?>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark mb-1"><i class="bi bi-gift me-1"></i>Bonificação fixa</span>
                                <div class="small text-muted" style="max-width:300px">
                                    <?= $c['bonificados'] ? e(implode(', ', array_map(fn($b) => $b['quantidade'] . 'x ' . ($b['descricao_pt'] ?: $b['codigo_produto']), $c['bonificados']))) : '<span class="text-danger">sem produtos</span>' ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge bg-success"><?= e($c['desconto']) ?>%</span>
                            <div class="small text-muted" style="max-width:300px">
                                <?php if ($alvos): ?>Aplica em: <?= e(implode(', ', $alvos)) ?>
                                <?php else: ?><span class="text-muted">Aplica nos itens do gatilho</span><?php endif; ?>
                            </div>
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
#bonifWrapTable, #condTableWrap, #alvoTableWrap, #poolTableWrap { max-height: 240px; overflow-y: auto; }
</style>
<div class="modal fade" id="modal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" onsubmit="return campValidar()">
                <input type="hidden" name="action" id="fa" value="criar">
                <input type="hidden" name="codigo_original" id="f_cod_orig">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="mt">Nova Campanha</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <!-- 1. Código -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Código da Campanha <span class="text-danger">*</span></label>
                            <input type="text" name="codigo_campanha" id="f_cod" class="form-control" required oninput="campStep()">
                        </div>
                        <!-- 2. Canal -->
                        <div class="col-md-4 camp-step d-none" data-step="canal">
                            <label class="form-label fw-semibold">Canal de Venda</label>
                            <select name="canal_venda_id" id="f_canal" class="form-select">
                                <option value="">— Todos os canais —</option>
                                <?php foreach ($canais as $cv): ?>
                                <option value="<?= $cv['id'] ?>"><?= e($cv['canal']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- 3. Status -->
                        <div class="col-md-4 camp-step d-none" data-step="status">
                            <label class="form-label fw-semibold">Campanha</label>
                            <select name="ativo" id="f_ativo" class="form-select">
                                <option value="1">Ativa</option>
                                <option value="0">Inativa</option>
                            </select>
                        </div>
                        <!-- 4. Tipo -->
                        <div class="col-md-6 camp-step d-none" data-step="tipo">
                            <label class="form-label fw-semibold">Tipo de Campanha <span class="text-danger">*</span></label>
                            <select name="tipo" id="f_tipo" class="form-select" onchange="campSetTipo(this.value)">
                                <option value="desconto">Desconto (%)</option>
                                <option value="bonificacao">Produtos Bonificados</option>
                            </select>
                        </div>

                        <!-- Condições (E) — ambos os tipos -->
                        <div class="col-12 camp-step d-none" data-step="cond">
                            <hr class="my-1">
                            <label class="form-label fw-semibold">Condições <span class="text-muted small">(todas precisam ser atendidas — E)</span></label>
                            <div class="text-muted small mb-2">
                                Cada condição combina <strong>Linha + Grupo + Subgrupo + Produto</strong> (os preenchidos somam-se em <strong>E</strong>).
                                Ex.: <em>Linha Itallian Color · Grupo Coloração ≥ 10 un.</em> <strong>E</strong> <em>Linha Itallian Color · Grupo Oxidante ≥ 5 un.</em>
                            </div>
                            <div class="table-responsive d-none" id="condTableWrap">
                                <table class="table table-sm table-bordered align-middle mb-2">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="min-width:140px">Linha</th>
                                            <th style="min-width:140px">Grupo</th>
                                            <th style="min-width:140px">Subgrupo</th>
                                            <th style="min-width:160px">Produto</th>
                                            <th style="width:120px">Mínimo por</th>
                                            <th style="width:110px" class="text-center">Mínimo</th>
                                            <th style="width:46px" class="text-center">—</th>
                                        </tr>
                                    </thead>
                                    <tbody id="condBody"></tbody>
                                </table>
                            </div>
                            <button type="button" class="btn btn-outline-success btn-sm" onclick="condAddRow()">
                                <i class="bi bi-plus-lg me-1"></i>Adicionar condição
                            </button>
                        </div>

                        <!-- DESCONTO -->
                        <div class="col-md-4 camp-step d-none" data-step="desc" id="campDescWrap">
                            <label class="form-label fw-semibold">Percentual de Desconto (%) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="desconto" id="f_desc" class="form-control" value="0">
                        </div>
                        <div class="col-12 camp-step d-none" data-step="alvo" id="campAlvoWrap">
                            <label class="form-label fw-semibold">Produtos a conceder o desconto <span class="text-muted small">(opcional — vazio = itens do gatilho)</span></label>
                            <div class="table-responsive d-none" id="alvoTableWrap">
                                <table class="table table-sm table-bordered align-middle mb-2">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="min-width:140px">Linha</th>
                                            <th style="min-width:140px">Grupo</th>
                                            <th style="min-width:140px">Subgrupo</th>
                                            <th style="min-width:160px">Produto</th>
                                            <th style="width:46px" class="text-center">—</th>
                                        </tr>
                                    </thead>
                                    <tbody id="alvoBody"></tbody>
                                </table>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="alvoAddRow()">
                                <i class="bi bi-plus-lg me-1"></i>Adicionar alvo
                            </button>
                        </div>

                        <!-- BONIFICAÇÃO -->
                        <div class="col-12 camp-step d-none" data-step="bonif" id="campBonifWrap">
                            <hr class="my-1">
                            <div class="row g-2 align-items-end mb-2">
                                <div class="col-md-5">
                                    <label class="form-label fw-semibold">Modo do bônus</label>
                                    <select name="bonif_modo" id="f_bonif_modo" class="form-select" onchange="campSetBonifModo()">
                                        <option value="fixo">Fixo (gera pedido com item fixo)</option>
                                        <option value="selecionavel">Selecionável (cliente escolhe)</option>
                                    </select>
                                </div>
                                <div class="col-md-7 d-none" id="bonifSelecCfg">
                                    <div class="row g-2 align-items-end">
                                        <div class="col-md-5">
                                            <label class="form-label fw-semibold">Pool</label>
                                            <select name="bonif_selec_modo" id="f_bonif_selec_modo" class="form-select" onchange="campSetBonifModo()">
                                                <option value="produtos">Lista de produtos</option>
                                                <option value="categoria">Por categoria</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-semibold">Limite por</label>
                                            <select name="bonif_limite_tipo" id="f_bonif_lim_tipo" class="form-select" onchange="campSetBonifModo()">
                                                <option value="quantidade">Quantidade</option>
                                                <option value="valor">Valor (R$)</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label fw-semibold" id="bonifLimValorLabel">Máx.</label>
                                            <input type="number" step="0.01" min="0" name="bonif_limite_valor" id="f_bonif_lim_valor" class="form-control" value="0">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Bônus: lista de produtos (fixo ou selecionável-produtos) -->
                            <div id="bonifListaWrap">
                                <label class="form-label fw-semibold"><i class="bi bi-gift me-1 text-warning"></i><span id="bonifTituloLista">Produtos Bonificados</span></label>
                                <div class="text-muted small mb-2" id="bonifAjudaFixo">
                                    A quantidade bonificada é <strong>multiplicada</strong> conforme o total comprado atinge o gatilho.
                                </div>
                                <div class="text-muted small mb-2 d-none" id="bonifAjudaSelec">
                                    Lista de produtos que o <strong>cliente</strong> poderá escolher como bônus, até o limite acima.
                                </div>
                                <div class="row g-2">
                                    <div class="col-md-7">
                                        <div class="position-relative">
                                            <input type="text" id="bonifSearch" class="form-control" placeholder="Buscar produto por código ou nome..." autocomplete="off">
                                            <div id="bonifDropdown" class="list-group position-absolute w-100 shadow-sm" style="display:none;z-index:1056;top:100%;left:0;max-height:240px;overflow-y:auto"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <input type="number" id="bonifQtd" class="form-control" value="1" min="1" title="Quantidade bonificada">
                                    </div>
                                    <div class="col-md-3">
                                        <button type="button" class="btn btn-warning w-100" onclick="bonifAdd()"><i class="bi bi-plus-lg me-1"></i>Adicionar</button>
                                    </div>
                                </div>
                                <div class="table-responsive mt-2 d-none" id="bonifWrapTable">
                                    <table class="table table-sm table-bordered align-middle mb-0">
                                        <thead class="table-light">
                                            <tr><th style="width:130px">Código</th><th>Produto</th><th style="width:110px" class="text-center">Qtd. Bônus</th><th style="width:50px" class="text-center">—</th></tr>
                                        </thead>
                                        <tbody id="bonifBody"></tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Bônus: pool por categoria (selecionável-categoria) -->
                            <div id="bonifPoolWrap" class="d-none">
                                <label class="form-label fw-semibold"><i class="bi bi-collection me-1 text-warning"></i>Pool elegível (por categoria/produto)</label>
                                <div class="table-responsive d-none" id="poolTableWrap">
                                    <table class="table table-sm table-bordered align-middle mb-2">
                                        <thead class="table-light">
                                            <tr><th style="width:120px">Tipo</th><th>Categoria / Produto</th><th style="width:50px" class="text-center">—</th></tr>
                                        </thead>
                                        <tbody id="poolBody"></tbody>
                                    </table>
                                </div>
                                <button type="button" class="btn btn-outline-warning btn-sm" onclick="poolAddRow()"><i class="bi bi-plus-lg me-1"></i>Adicionar ao pool</button>
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
var _critSeq = 0;

function campEsc(s)     { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function campEscAttr(s) { return campEsc(s).replace(/"/g,'&quot;'); }
function campNorm(s)    { return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }

// ---- Exibição progressiva ----
function campStep() {
    var temCod = document.getElementById('f_cod').value.trim() !== '';
    document.querySelectorAll('#modal .camp-step').forEach(function(el) {
        var step = el.dataset.step;
        var show = temCod;
        if (step === 'desc' || step === 'alvo') show = temCod && document.getElementById('f_tipo').value === 'desconto';
        if (step === 'bonif')                   show = temCod && document.getElementById('f_tipo').value === 'bonificacao';
        el.classList.toggle('d-none', !show);
    });
}

// ---- Opções de Tipo / Categoria / Produto ----
function critTipoOptions(sel) {
    return [['produto','Produto'],['linha','Linha'],['grupo','Grupo'],['subgrupo','Subgrupo']].map(function(o) {
        return '<option value="' + o[0] + '"' + (o[0] === sel ? ' selected' : '') + '>' + o[1] + '</option>';
    }).join('');
}
function critValorCellHtml(name, tipo, valor) {
    if (tipo === 'produto') {
        var opts = '<option value="">— selecione o produto —</option>' + _campProdutos.map(function(p) {
            return '<option value="' + p.id + '"' + (String(p.id) === String(valor) ? ' selected' : '') + '>' +
                   campEsc(p.codigo_produto) + ' — ' + campEsc(p.descricao_pt) + '</option>';
        }).join('');
        return '<select class="form-select form-select-sm" name="' + name + '" data-role="valor">' + opts + '</select>';
    }
    var lista = _campCats[tipo] || [];
    var opts = '<option value="">— selecione —</option>' + lista.map(function(v) {
        return '<option value="' + campEscAttr(v) + '"' + (String(v) === String(valor) ? ' selected' : '') + '>' + campEsc(v) + '</option>';
    }).join('');
    return '<select class="form-select form-select-sm" name="' + name + '" data-role="valor">' + opts + '</select>';
}
// Troca a célula de valor quando muda o Tipo
function critOnTipoChange(sel, prefix) {
    var tr  = sel.closest('tr');
    var cell = tr.querySelector('[data-cell="valor"]');
    var idx  = tr.dataset.idx;
    cell.innerHTML = critValorCellHtml(prefix + '[' + idx + '][valor]', sel.value, '');
}

// ---- Condições (E) — filtro composto (linha + grupo + subgrupo + produto) ----
function catSelect(name, tipo, val) {
    var lista = _campCats[tipo] || [];
    return '<select class="form-select form-select-sm" name="' + name + '"><option value="">— qualquer —</option>' +
        lista.map(function(v) {
            return '<option value="' + campEscAttr(v) + '"' + (String(v) === String(val || '') ? ' selected' : '') + '>' + campEsc(v) + '</option>';
        }).join('') + '</select>';
}
function prodSelect(name, val) {
    return '<select class="form-select form-select-sm" name="' + name + '"><option value="">— qualquer —</option>' +
        _campProdutos.map(function(p) {
            return '<option value="' + p.id + '"' + (String(p.id) === String(val || '') ? ' selected' : '') + '>' +
                   campEsc(p.codigo_produto) + ' — ' + campEsc(p.descricao_pt) + '</option>';
        }).join('') + '</select>';
}
function condAddRow(linha, grupo, subgrupo, produto, modo, qtd, valorMin) {
    var i = _critSeq++;
    modo = modo === 'valor' ? 'valor' : 'quantidade';
    var min = modo === 'valor' ? (parseFloat(valorMin) || '') : (parseInt(qtd, 10) || 1);
    var tr = document.createElement('tr');
    tr.innerHTML =
        '<td>' + catSelect('cond[' + i + '][linha]',    'linha',    linha)    + '</td>' +
        '<td>' + catSelect('cond[' + i + '][grupo]',    'grupo',    grupo)    + '</td>' +
        '<td>' + catSelect('cond[' + i + '][subgrupo]', 'subgrupo', subgrupo) + '</td>' +
        '<td>' + prodSelect('cond[' + i + '][produto]', produto) + '</td>' +
        '<td><select class="form-select form-select-sm" name="cond[' + i + '][modo]">' +
            '<option value="quantidade"' + (modo === 'quantidade' ? ' selected' : '') + '>Quantidade</option>' +
            '<option value="valor"'      + (modo === 'valor'      ? ' selected' : '') + '>Valor (R$)</option>' +
        '</select></td>' +
        '<td><input type="number" step="0.01" min="0" value="' + min + '" name="cond[' + i + '][min]" class="form-control form-control-sm text-center"></td>' +
        '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="condRemove(this)"><i class="bi bi-x-lg"></i></button></td>';
    document.getElementById('condBody').appendChild(tr);
    document.getElementById('condTableWrap').classList.remove('d-none');
}
function condRemove(btn) {
    btn.closest('tr').remove();
    document.getElementById('condTableWrap').classList.toggle('d-none', document.querySelectorAll('#condBody tr').length === 0);
}

// ---- Alvos do desconto / Pool da bonificação (Tipo + Categoria/Produto) ----
function alvoLikeAddRow(prefix, bodyId, wrapId, tipo, valor) {
    var i = _critSeq++;
    tipo = tipo || 'linha';
    var tr = document.createElement('tr');
    tr.dataset.idx = i;
    tr.innerHTML =
        '<td><select class="form-select form-select-sm" name="' + prefix + '[' + i + '][tipo]" data-role="tipo" onchange="critOnTipoChange(this,\'' + prefix + '\')">' + critTipoOptions(tipo) + '</select></td>' +
        '<td data-cell="valor">' + critValorCellHtml(prefix + '[' + i + '][valor]', tipo, valor) + '</td>' +
        '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="alvoLikeRemove(this,\'' + bodyId + '\',\'' + wrapId + '\')"><i class="bi bi-x-lg"></i></button></td>';
    document.getElementById(bodyId).appendChild(tr);
    document.getElementById(wrapId).classList.remove('d-none');
}
function alvoLikeRemove(btn, bodyId, wrapId) {
    btn.closest('tr').remove();
    document.getElementById(wrapId).classList.toggle('d-none', document.querySelectorAll('#' + bodyId + ' tr').length === 0);
}
function poolAddRow(tipo, valor) { alvoLikeAddRow('pool', 'poolBody', 'poolTableWrap', tipo, valor); }

// ---- Alvo do desconto — filtro composto (linha + grupo + subgrupo + produto), igual às condições ----
function alvoAddRow(linha, grupo, subgrupo, produto) {
    var i = _critSeq++;
    var tr = document.createElement('tr');
    tr.innerHTML =
        '<td>' + catSelect('alvo[' + i + '][linha]',    'linha',    linha)    + '</td>' +
        '<td>' + catSelect('alvo[' + i + '][grupo]',    'grupo',    grupo)    + '</td>' +
        '<td>' + catSelect('alvo[' + i + '][subgrupo]', 'subgrupo', subgrupo) + '</td>' +
        '<td>' + prodSelect('alvo[' + i + '][produto]', produto) + '</td>' +
        '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="alvoRemove(this)"><i class="bi bi-x-lg"></i></button></td>';
    document.getElementById('alvoBody').appendChild(tr);
    document.getElementById('alvoTableWrap').classList.remove('d-none');
}
function alvoRemove(btn) {
    btn.closest('tr').remove();
    document.getElementById('alvoTableWrap').classList.toggle('d-none', document.querySelectorAll('#alvoBody tr').length === 0);
}

// ---- Alterna Desconto x Bonificação ----
function campSetTipo(tipo) {
    var ehBonif = (tipo === 'bonificacao');
    document.getElementById('campBonifWrap').classList.toggle('d-none', !ehBonif);
    document.getElementById('campDescWrap').classList.toggle('d-none', ehBonif);
    document.getElementById('campAlvoWrap').classList.toggle('d-none', ehBonif);
    campStep();
}

// ---- Modo do bônus (fixo / selecionável; lista / categoria) ----
function campSetBonifModo() {
    var selec = document.getElementById('f_bonif_modo').value === 'selecionavel';
    var pool  = document.getElementById('f_bonif_selec_modo').value === 'categoria';
    document.getElementById('bonifSelecCfg').classList.toggle('d-none', !selec);
    document.getElementById('bonifAjudaFixo').classList.toggle('d-none', selec);
    document.getElementById('bonifAjudaSelec').classList.toggle('d-none', !selec);
    document.getElementById('bonifTituloLista').textContent = selec ? 'Produtos elegíveis (cliente escolhe)' : 'Produtos Bonificados';
    // categoria só faz sentido no selecionável
    var usaPool = selec && pool;
    document.getElementById('bonifListaWrap').classList.toggle('d-none', usaPool);
    document.getElementById('bonifPoolWrap').classList.toggle('d-none', !usaPool);
    if (selec) {
        var porValor = document.getElementById('f_bonif_lim_tipo').value === 'valor';
        document.getElementById('bonifLimValorLabel').textContent = porValor ? 'Máx. (R$)' : 'Máx. (un.)';
    }
}

// ==== Autocomplete de produtos (lista de bônus) ====
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
        '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="bonifRemove(this)"><i class="bi bi-x-lg"></i></button></td>';
    document.getElementById('bonifBody').appendChild(tr);
    bonifAtualizar();
    tr.scrollIntoView({ block: 'nearest' });
}
function bonifRemove(btn) { btn.closest('tr').remove(); bonifAtualizar(); }
function bonifAtualizar() {
    document.getElementById('bonifWrapTable').classList.toggle('d-none', document.querySelectorAll('#bonifBody tr').length === 0);
}
function bonifIds() {
    return Array.prototype.map.call(document.querySelectorAll('#bonifBody input[name$="[produto_id]"]'), function(i) { return String(i.value); });
}
function bonifAdd() {
    if (!_bonifSel) { document.getElementById('bonifSearch').focus(); alert('Selecione um produto da lista.'); return; }
    var qtd = parseInt(document.getElementById('bonifQtd').value, 10) || 1;
    if (qtd < 1) qtd = 1;
    if (bonifIds().indexOf(String(_bonifSel.id)) === -1) bonifAddRow(_bonifSel.id, _bonifSel.codigo, _bonifSel.nome, qtd);
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

// ---- Validação do submit ----
function campValidar() {
    if (document.querySelectorAll('#condBody tr').length === 0) {
        alert('Adicione ao menos uma condição.');
        return false;
    }
    return true;
}

// ---- Reset / abrir modal ----
function campLimpar() {
    document.getElementById('condBody').innerHTML = '';
    document.getElementById('condTableWrap').classList.add('d-none');
    document.getElementById('alvoBody').innerHTML = '';
    document.getElementById('alvoTableWrap').classList.add('d-none');
    document.getElementById('poolBody').innerHTML = '';
    document.getElementById('poolTableWrap').classList.add('d-none');
    document.getElementById('bonifBody').innerHTML = '';
    document.getElementById('bonifSearch').value = '';
    document.getElementById('bonifQtd').value = '1';
    document.getElementById('f_bonif_modo').value = 'fixo';
    document.getElementById('f_bonif_selec_modo').value = 'produtos';
    document.getElementById('f_bonif_lim_tipo').value = 'quantidade';
    document.getElementById('f_bonif_lim_valor').value = 0;
    _bonifSel = null;
    bonifAtualizar();
    campSetBonifModo();
}

function novoReg() {
    document.getElementById('mt').textContent   = 'Nova Campanha';
    document.getElementById('fa').value         = 'criar';
    document.getElementById('f_cod_orig').value = '';
    document.getElementById('f_cod').value      = '';
    document.getElementById('f_canal').value    = '';
    document.getElementById('f_ativo').value    = '1';
    document.getElementById('f_desc').value     = 0;
    document.getElementById('f_tipo').value     = 'desconto';
    campLimpar();
    campSetTipo('desconto');
    campStep();
}

function editarReg(d) {
    document.getElementById('mt').textContent   = 'Editar Campanha';
    document.getElementById('fa').value         = 'editar';
    document.getElementById('f_cod_orig').value = d.codigo_campanha || '';
    document.getElementById('f_cod').value      = d.codigo_campanha || '';
    document.getElementById('f_canal').value    = d.canal_venda_id  || '';
    document.getElementById('f_ativo').value    = String(d.ativo == null ? 1 : d.ativo);
    document.getElementById('f_desc').value     = d.desconto || 0;
    document.getElementById('f_tipo').value     = d.tipo || 'desconto';
    campLimpar();
    (d.condicoes || []).forEach(function(c) { condAddRow(c.linha, c.grupo, c.subgrupo, c.produto, c.modo, c.qtd, c.valor_min); });
    (d.desconto_alvos || []).forEach(function(a) { alvoAddRow(a.linha, a.grupo, a.subgrupo, a.produto); });
    (d.bonif_pool || []).forEach(function(a) { poolAddRow(a.tipo, a.valor); });
    document.getElementById('f_bonif_modo').value       = d.bonif_modo || 'fixo';
    document.getElementById('f_bonif_selec_modo').value = d.bonif_selec_modo || 'produtos';
    document.getElementById('f_bonif_lim_tipo').value   = d.bonif_limite_tipo || 'quantidade';
    document.getElementById('f_bonif_lim_valor').value  = parseFloat(d.bonif_limite_valor || 0) || 0;
    (d.bonificados || []).forEach(function(b) { bonifAddRow(b.id, b.codigo_produto, b.descricao_pt, b.quantidade); });
    campSetBonifModo();
    campSetTipo(d.tipo || 'desconto');
    campStep();
    new bootstrap.Modal(document.getElementById('modal')).show();
}
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
