<?php
require_once __DIR__ . '/../../config.php';
$u = usuario();
if (!$u || !in_array($u['tipo'], ['financeiro', 'tecnologia da informacao'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// ── POST: Campanha (cabeçalho + faixas + bonificação — substitui tudo a cada salvar) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'campanha_salvar') {
    $id       = (int)($_POST['id'] ?? 0);
    $nome     = trim($_POST['nome'] ?? '');
    $tipo     = in_array($_POST['tipo'] ?? '', ['desconto', 'bonificacao'], true) ? $_POST['tipo'] : 'desconto';
    $criterio = in_array($_POST['criterio'] ?? '', ['quantidade', 'valor'], true) ? $_POST['criterio'] : 'quantidade';
    $unidade  = trim($_POST['unidade'] ?? '') ?: null;
    $obs      = trim($_POST['observacoes'] ?? '') ?: null;
    $ativo    = isset($_POST['ativo']) ? 1 : 0;
    $ordem    = (int)($_POST['ordem'] ?? 0);

    if ($nome === '') {
        flash('danger', 'Informe o nome da campanha.');
    } else {
        $numBR = function ($v) { $v = trim((string)$v); return $v === '' ? null : (float)str_replace(',', '.', $v); };
        if ($id) {
            db()->prepare('UPDATE campanhas_am SET nome=?,tipo=?,criterio=?,unidade=?,observacoes=?,ativo=?,ordem=? WHERE id=?')
                ->execute([$nome, $tipo, $criterio, $unidade, $obs, $ativo, $ordem, $id]);
        } else {
            db()->prepare('INSERT INTO campanhas_am (nome,tipo,criterio,unidade,observacoes,ativo,ordem) VALUES (?,?,?,?,?,?,?)')
                ->execute([$nome, $tipo, $criterio, $unidade, $obs, $ativo, $ordem]);
            $id = (int)db()->lastInsertId();
        }

        db()->prepare('DELETE FROM campanhas_am_faixas WHERE campanha_id = ?')->execute([$id]);
        $insF = db()->prepare('INSERT INTO campanhas_am_faixas (campanha_id,minimo,maximo,percentual) VALUES (?,?,?,?)');
        foreach (($_POST['faixa_min'] ?? []) as $idx => $mn) {
            $pct = $_POST['faixa_pct'][$idx] ?? '';
            if (trim((string)$mn) === '' && trim((string)$pct) === '') continue;
            $insF->execute([$id, $numBR($mn) ?? 0, $numBR($_POST['faixa_max'][$idx] ?? ''), $numBR($pct) ?? 0]);
        }

        db()->prepare('DELETE FROM campanhas_am_bonificacao WHERE campanha_id = ?')->execute([$id]);
        $insB = db()->prepare('INSERT INTO campanhas_am_bonificacao (campanha_id,qtd_base,produto_bonus_codigo,produto_bonus_nome,qtd_bonus) VALUES (?,?,?,?,?)');
        foreach (($_POST['bonif_codigo'] ?? []) as $idx => $cod) {
            $cod = trim($cod);
            if ($cod === '') continue;
            $insB->execute([$id, max(1, (int)($_POST['bonif_base'][$idx] ?? 1)), $cod, trim($_POST['bonif_nome'][$idx] ?? '') ?: null, max(1, (int)($_POST['bonif_qtd'][$idx] ?? 1))]);
        }
        flash('success', 'Campanha salva.');
    }
    header('Location: ' . BASE_URL . '/admin/financeiro/campanhas-am.php?tab=campanhas');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'campanha_excluir') {
    $id = (int)($_POST['id'] ?? 0);
    foreach (['campanhas_am_faixas', 'campanhas_am_produtos', 'campanhas_am_bonificacao'] as $t) {
        db()->prepare("DELETE FROM $t WHERE campanha_id = ?")->execute([$id]);
    }
    db()->prepare('DELETE FROM campanhas_am WHERE id = ?')->execute([$id]);
    flash('success', 'Campanha excluída.');
    header('Location: ' . BASE_URL . '/admin/financeiro/campanhas-am.php?tab=campanhas');
    exit;
}

// ── POST: Produtos por campanha ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'produto_add') {
    $campId = (int)($_POST['campanha_id'] ?? 0);
    $codigo = trim($_POST['codigo_produto'] ?? '');
    if ($campId && $codigo) {
        db()->prepare('INSERT INTO campanhas_am_produtos (campanha_id,codigo_produto,produto_nome) VALUES (?,?,?)')
            ->execute([$campId, $codigo, trim($_POST['produto_nome'] ?? '') ?: null]);
        flash('success', 'Produto adicionado.');
    }
    header('Location: ' . BASE_URL . '/admin/financeiro/campanhas-am.php?tab=produtos&campanha=' . $campId);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'produto_remover') {
    db()->prepare('DELETE FROM campanhas_am_produtos WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
    flash('success', 'Produto removido.');
    header('Location: ' . BASE_URL . '/admin/financeiro/campanhas-am.php?tab=produtos&campanha=' . (int)($_POST['campanha_id'] ?? 0));
    exit;
}

// ── POST: Produtos fora de campanha ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'fora_add') {
    $codigo = trim($_POST['codigo_produto'] ?? '');
    if ($codigo) {
        db()->prepare('INSERT INTO campanhas_am_fora (codigo_produto,produto_nome) VALUES (?,?) ON DUPLICATE KEY UPDATE produto_nome = VALUES(produto_nome)')
            ->execute([$codigo, trim($_POST['produto_nome'] ?? '') ?: null]);
        flash('success', 'Produto adicionado à lista.');
    }
    header('Location: ' . BASE_URL . '/admin/financeiro/campanhas-am.php?tab=fora');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'fora_remover') {
    db()->prepare('DELETE FROM campanhas_am_fora WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
    flash('success', 'Produto removido da lista.');
    header('Location: ' . BASE_URL . '/admin/financeiro/campanhas-am.php?tab=fora');
    exit;
}

$campanhas   = campanhasAmListar();
$foraList    = campanhasAmForaLista();
$tabAtiva    = in_array($_GET['tab'] ?? '', ['campanhas', 'produtos', 'fora'], true) ? $_GET['tab'] : 'campanhas';
$campSelId   = (int)($_GET['campanha'] ?? ($campanhas[0]['id'] ?? 0));
$campSel     = null;
foreach ($campanhas as $c) if ((int)$c['id'] === $campSelId) $campSel = $c;
if (!$campSel && $campanhas) { $campSel = $campanhas[0]; $campSelId = (int)$campSel['id']; }

$pctFmt = function ($v) { return rtrim(rtrim(number_format((float)$v, 2, ',', '.'), '0'), ',') . '%'; };
$numFmt = function ($v) { return $v === null ? '' : rtrim(rtrim(number_format((float)$v, 2, ',', '.'), '0'), ','); };

$pageTitle = 'Campanhas — Análise Financeira';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="mb-4">
    <h4 class="fw-bold mb-1"><i class="bi bi-megaphone me-2"></i>Campanhas (Análise Financeira A&amp;M)</h4>
    <p class="text-muted small mb-0">
        Campanhas "Beauty" usadas no check 5 (confere se os produtos do pedido estão dentro de alguma
        campanha e se o %Diretoria aplicado é justificado pela faixa atingida). Independente do módulo
        de campanhas do pedido local.
    </p>
</div>

<a href="<?= BASE_URL ?>/admin/financeiro/analise-financeira.php" class="btn btn-outline-secondary btn-sm mb-3">
    <i class="bi bi-arrow-left me-1"></i>Voltar para Análise Financeira
</a>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $tabAtiva === 'campanhas' ? 'active' : '' ?>" href="?tab=campanhas">Campanhas</a></li>
    <li class="nav-item"><a class="nav-link <?= $tabAtiva === 'produtos' ? 'active' : '' ?>" href="?tab=produtos&campanha=<?= $campSelId ?>">Produtos por Campanha</a></li>
    <li class="nav-item"><a class="nav-link <?= $tabAtiva === 'fora' ? 'active' : '' ?>" href="?tab=fora">Produtos Fora de Campanha <span class="badge bg-secondary ms-1"><?= count($foraList) ?></span></a></li>
</ul>

<?php if ($tabAtiva === 'campanhas'): ?>
    <div class="d-flex justify-content-end mb-2">
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#mdlCampNova">
            <i class="bi bi-plus-circle me-1"></i>Nova Campanha
        </button>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle bg-white">
            <thead class="table-light">
                <tr>
                    <th>Nome</th><th>Tipo</th><th>Critério</th><th>Unidade</th>
                    <th class="text-center">Faixas</th><th class="text-center">Produtos</th>
                    <th class="text-center">Ativa</th><th style="width:120px"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($campanhas as $c): ?>
                <tr>
                    <td class="fw-semibold"><?= e($c['nome']) ?><?php if ($c['observacoes']): ?><br><span class="text-muted small"><?= e($c['observacoes']) ?></span><?php endif; ?></td>
                    <td><span class="badge bg-<?= $c['tipo'] === 'bonificacao' ? 'info text-dark' : 'primary' ?>"><?= $c['tipo'] === 'bonificacao' ? 'Bonificação' : 'Desconto' ?></span></td>
                    <td><?= $c['tipo'] === 'bonificacao' ? '—' : e(ucfirst($c['criterio'])) ?></td>
                    <td><?= e($c['unidade'] ?: '—') ?></td>
                    <td class="text-center"><?= $c['tipo'] === 'bonificacao' ? count($c['bonificacao']) . ' bônus' : count($c['faixas']) ?></td>
                    <td class="text-center"><a href="?tab=produtos&campanha=<?= (int)$c['id'] ?>"><?= count($c['produtos']) ?></a></td>
                    <td class="text-center"><?= $c['ativo'] ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-dash-circle text-muted"></i>' ?></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mdlCamp-<?= (int)$c['id'] ?>"><i class="bi bi-pencil"></i></button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Excluir esta campanha e todos os produtos/faixas ligados a ela?');">
                            <input type="hidden" name="acao" value="campanha_excluir">
                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$campanhas): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">Nenhuma campanha cadastrada.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php
    // Modal de edição (uma por campanha) + modal de criação — mesmo corpo de formulário.
    $renderModalCampanha = function ($id, $c) use ($numFmt) {
        $nome = $c['nome'] ?? ''; $tipo = $c['tipo'] ?? 'desconto'; $criterio = $c['criterio'] ?? 'quantidade';
        $unidade = $c['unidade'] ?? ''; $obs = $c['observacoes'] ?? ''; $ativo = $c['ativo'] ?? 1; $ordem = $c['ordem'] ?? 0;
        $faixas = $c['faixas'] ?? []; $bonif = $c['bonificacao'] ?? [];
        $mid = 'mdlCamp-' . ($id ?: 'Nova');
        ?>
        <div class="modal fade" id="<?= $mid ?>" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
            <form method="POST">
                <input type="hidden" name="acao" value="campanha_salvar">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><?= $id ? 'Editar Campanha' : 'Nova Campanha' ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2 mb-2">
                        <div class="col-md-6"><label class="form-label small fw-semibold">Nome</label>
                            <input type="text" name="nome" class="form-control" value="<?= e($nome) ?>" required></div>
                        <div class="col-md-3"><label class="form-label small fw-semibold">Tipo</label>
                            <select name="tipo" class="form-select camp-tipo">
                                <option value="desconto" <?= $tipo === 'desconto' ? 'selected' : '' ?>>Desconto (faixas)</option>
                                <option value="bonificacao" <?= $tipo === 'bonificacao' ? 'selected' : '' ?>>Bonificação (brinde)</option>
                            </select></div>
                        <div class="col-md-3"><label class="form-label small fw-semibold">Ordem</label>
                            <input type="number" name="ordem" class="form-control" value="<?= (int)$ordem ?>"></div>
                        <div class="col-md-4"><label class="form-label small fw-semibold">Critério da faixa</label>
                            <select name="criterio" class="form-select">
                                <option value="quantidade" <?= $criterio === 'quantidade' ? 'selected' : '' ?>>Quantidade</option>
                                <option value="valor" <?= $criterio === 'valor' ? 'selected' : '' ?>>Valor (R$)</option>
                            </select></div>
                        <div class="col-md-4"><label class="form-label small fw-semibold">Unidade (rótulo)</label>
                            <input type="text" name="unidade" class="form-control" placeholder="tubos, un, R$..." value="<?= e($unidade) ?>"></div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" name="ativo" id="ativo-<?= $mid ?>" <?= $ativo ? 'checked' : '' ?>>
                                <label class="form-check-label" for="ativo-<?= $mid ?>">Campanha ativa</label>
                            </div>
                        </div>
                        <div class="col-12"><label class="form-label small fw-semibold">Observações</label>
                            <textarea name="observacoes" class="form-control" rows="2"><?= e($obs) ?></textarea></div>
                    </div>

                    <div class="camp-bloco-desconto <?= $tipo === 'bonificacao' ? 'd-none' : '' ?>">
                        <hr>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="fw-semibold">Faixas de desconto</div>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="campAddFaixa('<?= $mid ?>')"><i class="bi bi-plus"></i> Faixa</button>
                        </div>
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Mínimo</th><th>Máximo (vazio = "acima de")</th><th>% Desconto esperado</th><th></th></tr></thead>
                            <tbody id="faixasBody-<?= $mid ?>">
                            <?php foreach ($faixas as $f): ?>
                                <tr>
                                    <td><input type="text" name="faixa_min[]" class="form-control form-control-sm" value="<?= e($numFmt($f['minimo'])) ?>"></td>
                                    <td><input type="text" name="faixa_max[]" class="form-control form-control-sm" value="<?= e($numFmt($f['maximo'])) ?>"></td>
                                    <td><input type="text" name="faixa_pct[]" class="form-control form-control-sm" value="<?= e($numFmt($f['percentual'])) ?>"></td>
                                    <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-x"></i></button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="camp-bloco-bonif <?= $tipo === 'bonificacao' ? '' : 'd-none' ?>">
                        <hr>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="fw-semibold">Regra de bonificação</div>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="campAddBonif('<?= $mid ?>')"><i class="bi bi-plus"></i> Regra</button>
                        </div>
                        <table class="table table-sm mb-0">
                            <thead><tr><th>A cada (qtd. do produto da campanha)</th><th>Código do produto bônus</th><th>Nome (opcional)</th><th>Qtd. do bônus</th><th></th></tr></thead>
                            <tbody id="bonifBody-<?= $mid ?>">
                            <?php foreach ($bonif as $b): ?>
                                <tr>
                                    <td><input type="number" name="bonif_base[]" class="form-control form-control-sm" value="<?= (int)$b['qtd_base'] ?>"></td>
                                    <td><input type="text" name="bonif_codigo[]" class="form-control form-control-sm" value="<?= e($b['produto_bonus_codigo']) ?>"></td>
                                    <td><input type="text" name="bonif_nome[]" class="form-control form-control-sm" value="<?= e($b['produto_bonus_nome']) ?>"></td>
                                    <td><input type="number" name="bonif_qtd[]" class="form-control form-control-sm" value="<?= (int)$b['qtd_bonus'] ?>"></td>
                                    <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-x"></i></button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Salvar</button>
                </div>
            </form>
        </div></div></div>
        <?php
    };
    foreach ($campanhas as $c) $renderModalCampanha((int)$c['id'], $c);
    $renderModalCampanha(0, ['nome' => '', 'tipo' => 'desconto', 'criterio' => 'quantidade', 'unidade' => '', 'observacoes' => '', 'ativo' => 1, 'ordem' => count($campanhas), 'faixas' => [], 'bonificacao' => []]);
    ?>
    <script>
    function campAddFaixa(mid) {
        var tr = document.createElement('tr');
        tr.innerHTML = '<td><input type="text" name="faixa_min[]" class="form-control form-control-sm"></td>'
            + '<td><input type="text" name="faixa_max[]" class="form-control form-control-sm"></td>'
            + '<td><input type="text" name="faixa_pct[]" class="form-control form-control-sm"></td>'
            + '<td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest(\'tr\').remove()"><i class="bi bi-x"></i></button></td>';
        document.getElementById('faixasBody-' + mid).appendChild(tr);
    }
    function campAddBonif(mid) {
        var tr = document.createElement('tr');
        tr.innerHTML = '<td><input type="number" name="bonif_base[]" class="form-control form-control-sm" value="1"></td>'
            + '<td><input type="text" name="bonif_codigo[]" class="form-control form-control-sm"></td>'
            + '<td><input type="text" name="bonif_nome[]" class="form-control form-control-sm"></td>'
            + '<td><input type="number" name="bonif_qtd[]" class="form-control form-control-sm" value="1"></td>'
            + '<td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest(\'tr\').remove()"><i class="bi bi-x"></i></button></td>';
        document.getElementById('bonifBody-' + mid).appendChild(tr);
    }
    document.addEventListener('change', function (e) {
        if (!e.target.classList.contains('camp-tipo')) return;
        var modal = e.target.closest('.modal-content');
        var isBonif = e.target.value === 'bonificacao';
        modal.querySelector('.camp-bloco-desconto').classList.toggle('d-none', isBonif);
        modal.querySelector('.camp-bloco-bonif').classList.toggle('d-none', !isBonif);
    });
    </script>

<?php elseif ($tabAtiva === 'produtos'): ?>
    <div class="row g-2 mb-3">
        <div class="col-md-5">
            <select class="form-select" onchange="location.href='?tab=produtos&campanha='+this.value">
                <?php foreach ($campanhas as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $campSelId ? 'selected' : '' ?>><?= e($c['nome']) ?> (<?= count($c['produtos']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <?php if (!$campSel): ?>
        <div class="alert alert-info">Cadastre uma campanha primeiro, na aba "Campanhas".</div>
    <?php else: ?>
        <form method="POST" class="row g-2 align-items-end mb-3">
            <input type="hidden" name="acao" value="produto_add">
            <input type="hidden" name="campanha_id" value="<?= $campSelId ?>">
            <div class="col-md-3"><label class="form-label small">Código do produto</label>
                <input type="text" name="codigo_produto" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label small">Nome (opcional)</label>
                <input type="text" name="produto_nome" class="form-control"></div>
            <div class="col-md-3"><button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-circle me-1"></i>Adicionar</button></div>
        </form>
        <div class="table-responsive" style="max-height:70vh">
            <table class="table table-sm table-bordered align-middle bg-white">
                <thead class="table-light" style="position:sticky;top:0"><tr><th style="width:120px">Código</th><th>Produto</th><th style="width:60px"></th></tr></thead>
                <tbody>
                <?php foreach ($campSel['produtos'] as $p): ?>
                    <tr>
                        <td><?= e($p['codigo_produto']) ?></td>
                        <td><?= e($p['produto_nome'] ?: '—') ?></td>
                        <td>
                            <form method="POST" onsubmit="return confirm('Remover este produto da campanha?');">
                                <input type="hidden" name="acao" value="produto_remover">
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <input type="hidden" name="campanha_id" value="<?= $campSelId ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$campSel['produtos']): ?>
                    <tr><td colspan="3" class="text-center text-muted py-3">Nenhum produto nesta campanha.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

<?php else: /* fora */ ?>
    <p class="text-muted small">
        Produtos que sabidamente não pertencem a nenhuma campanha — qualquer %Diretoria &gt; 0
        neles é sempre sinalizado no check 5, mesmo que não estejam nesta lista (o check considera
        "fora de campanha" todo produto que não está em nenhuma campanha ativa; esta lista é só
        documentação/conferência, igual à planilha original).
    </p>
    <form method="POST" class="row g-2 align-items-end mb-3">
        <input type="hidden" name="acao" value="fora_add">
        <div class="col-md-3"><label class="form-label small">Código do produto</label>
            <input type="text" name="codigo_produto" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label small">Nome (opcional)</label>
            <input type="text" name="produto_nome" class="form-control"></div>
        <div class="col-md-3"><button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-circle me-1"></i>Adicionar</button></div>
    </form>
    <div class="table-responsive" style="max-height:70vh">
        <table class="table table-sm table-bordered align-middle bg-white">
            <thead class="table-light" style="position:sticky;top:0"><tr><th style="width:120px">Código</th><th>Produto</th><th style="width:60px"></th></tr></thead>
            <tbody>
            <?php foreach ($foraList as $p): ?>
                <tr>
                    <td><?= e($p['codigo_produto']) ?></td>
                    <td><?= e($p['produto_nome'] ?: '—') ?></td>
                    <td>
                        <form method="POST" onsubmit="return confirm('Remover este produto da lista?');">
                            <input type="hidden" name="acao" value="fora_remover">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$foraList): ?>
                <tr><td colspan="3" class="text-center text-muted py-3">Lista vazia.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require_once LAYOUT_PATH . '/footer.php'; ?>
