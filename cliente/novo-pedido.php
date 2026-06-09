<?php
require_once __DIR__ . '/../config.php';
requireCliente();
$u = usuario();

// Modo Bônus MA: filtra linhas e força tipo_venda = bonificacao
$MA_LINHAS = ['MAT APOIO ITALLIAN - BRINDE', 'MAT APOIO ITALLIAN - VENDIDO'];
$modoMA    = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $itens           = $_POST['itens'] ?? [];
    $obs_geral       = trim($_POST['observacoes'] ?? '');
    $editarId        = (int)($_POST['editar_id'] ?? 0);
    $tipoVenda       = (isset($_POST['modo']) && $_POST['modo'] === 'ma_bonus') ? 'bonificacao' : 'venda';
    $formaPagamento  = trim($_POST['forma_pagamento'] ?? '');
    $creditoAplicado = $editarId > 0 ? 0.0 : max(0.0, (float)($_POST['credito_aplicado'] ?? 0));

    // Forma de pagamento obrigatória (exceto pedidos de bonificação MA)
    if ($tipoVenda !== 'bonificacao' && $formaPagamento === '') {
        flash('danger', 'Selecione uma forma de pagamento para continuar.');
        header('Location: ' . BASE_URL . '/cliente/novo-pedido.php' . ($editarId ? '?editar=' . $editarId : ''));
        exit;
    }

    $cli = db()->prepare('SELECT c.* FROM clientes c WHERE c.id = ?');
    $cli->execute([$u['id']]);
    $cli = $cli->fetch();

    $desconto = ((float)($cli['desconto_cliente'] ?? 0) + (float)($cli['desconto_canal'] ?? 0)) / 100;
    $data          = date('Y-m-d');
    $campanhas_all = db()->query('SELECT produto_id, linha, grupo, subgrupo, canal_venda_id, quantidade, desconto FROM campanhas')->fetchAll();
    $canalVendaId  = (int)($cli['canal_venda_id'] ?? 0);
    $criados         = 0;
    $ids_criados     = [];
    $processedIds    = [];
    $loteMap         = [];
    $existingCampanha = [];
    $existingLote    = null;

    try {
        if ($editarId > 0) {
            $orig = db()->prepare('SELECT id, lote_id, produto_id FROM pedidos WHERE id = ? AND cliente_id = ? AND status = "comercial"');
            $orig->execute([$editarId, $u['id']]);
            $orig = $orig->fetch();
            if (!$orig) throw new Exception('Pedido não disponível para edição.');
            $existingLote = $orig['lote_id'] ?: null;
            if ($existingLote) {
                $loteItems = db()->prepare('SELECT id, produto_id, desconto_campanha FROM pedidos WHERE lote_id = ? AND cliente_id = ?');
                $loteItems->execute([$existingLote, $u['id']]);
                foreach ($loteItems->fetchAll() as $li) {
                    $loteMap[(int)$li['produto_id']]          = (int)$li['id'];
                    $existingCampanha[(int)$li['produto_id']] = (float)($li['desconto_campanha'] ?? 0);
                }
            } else {
                $stmt2 = db()->prepare('SELECT desconto_campanha FROM pedidos WHERE id = ?');
                $stmt2->execute([$editarId]);
                $row2 = $stmt2->fetch();
                $loteMap[(int)$orig['produto_id']]          = $editarId;
                $existingCampanha[(int)$orig['produto_id']] = (float)($row2['desconto_campanha'] ?? 0);
            }
        }

        $lote_id = $existingLote ?: uniqid('L', true);

        // Passagem 1: coletar todos os itens válidos e calcular totais por linha/grupo/subgrupo
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
        foreach ($items_data as $it) {
            $produto_id  = $it['produto_id'];
            $qtd         = $it['qtd'];
            $prod        = $it['prod'];
            $valor_total = $qtd * (float)$prod['preco'] * (1 - $desconto);

            $isExistingItem = ($editarId > 0 && isset($loteMap[$produto_id]));

            if ($isExistingItem) {
                // Preserva o desconto de campanha já gravado — não recalcula para itens existentes
                $campDesc = $existingCampanha[$produto_id] ?? 0;
            } else {
                // Item novo na edição ou pedido novo: calcula campanha a partir dos totais
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
            }

            if ($campDesc > 0) $valor_total *= (1 - $campDesc / 100);

            if ($editarId > 0 && isset($loteMap[$produto_id])) {
                db()->prepare('UPDATE pedidos SET produto_id=?,descricao_produto=?,codigo_barra=?,quantidade_total=?,valor_total=?,observacoes=?,lote_id=?,desconto_campanha=?,forma_pagamento=? WHERE id=? AND cliente_id=?')
                    ->execute([$produto_id, $prod['descricao_pt'], $prod['codigo_barra'], $qtd, $valor_total, $obs_geral, $lote_id, $campDesc ?: null, $formaPagamento ?: null, $loteMap[$produto_id], $u['id']]);
                $processedIds[] = $loteMap[$produto_id];
                $ids_criados[]  = $loteMap[$produto_id];
            } else {
                $num = 'PED-' . date('Y') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
                db()->prepare('INSERT INTO pedidos (numero_pedido,tipo_venda,data_pedido,cliente_id,produto_id,vendedor,codigo_barra,descricao_produto,quantidade_total,valor_total,status,observacoes,lote_id,desconto_campanha,forma_pagamento) VALUES (?,?,?,?,?,?,?,?,?,?,"comercial",?,?,?,?)')
                    ->execute([$num,$tipoVenda,$data,$u['id'],$produto_id,$cli['vendedor']??'',$prod['codigo_barra'],$prod['descricao_pt'],$qtd,$valor_total,$obs_geral,$lote_id,$campDesc ?: null,$formaPagamento ?: null]);
                $newId = (int)db()->lastInsertId();
                $processedIds[] = $newId;
                $ids_criados[]  = $newId;
            }
            $criados++;
        }

        if ($editarId > 0) {
            foreach ($loteMap as $mapPid => $mapPedId) {
                if (!in_array($mapPedId, $processedIds)) {
                    db()->prepare('DELETE FROM pedidos WHERE id = ? AND cliente_id = ?')->execute([$mapPedId, $u['id']]);
                }
            }
        }

        if ($criados > 0) {
            if ($tipoVenda === 'bonificacao') {
                $maLogIdPost         = (int)($_POST['ma_log_id'] ?? 0);
                $maOriginalTotalPost = (float)($_POST['ma_original_total'] ?? 0);
                if ($maLogIdPost > 0) {
                    $ph = implode(',', array_fill(0, count($ids_criados), '?'));
                    $sumStmt = db()->prepare("SELECT COALESCE(SUM(valor_total),0) FROM pedidos WHERE id IN ($ph)");
                    $sumStmt->execute($ids_criados);
                    $novoTotal = (float)$sumStmt->fetchColumn();
                    $delta     = $novoTotal - $maOriginalTotalPost;
                    if (abs($delta) > 0.001) {
                        db()->prepare('UPDATE bonus_ma_logs SET valor_utilizado = valor_utilizado + ? WHERE id = ?')
                            ->execute([$delta, $maLogIdPost]);
                    }
                }
            }
            // Aplica crédito disponível (somente em pedidos novos)
            if ($creditoAplicado > 0.001) {
                // Limita crédito ao total real do pedido (os itens NÃO são alterados)
                $phC = implode(',', array_fill(0, count($ids_criados), '?'));
                $totStmt = db()->prepare("SELECT COALESCE(SUM(valor_total),0) FROM pedidos WHERE id IN ($phC)");
                $totStmt->execute($ids_criados);
                $totalPedido     = (float)$totStmt->fetchColumn();
                $creditoAplicado = min($creditoAplicado, $totalPedido);

                // Grava o crédito utilizado no primeiro item do lote (abate só o total)
                db()->prepare('UPDATE pedidos SET credito_utilizado = ? WHERE id = ?')
                    ->execute([$creditoAplicado, $ids_criados[0]]);

                // Deduz FIFO dos créditos aprovados
                $credsStmt = db()->prepare("
                    SELECT cr.id, cr.valor - COALESCE(cr.valor_utilizado,0) AS saldo
                    FROM creditos cr
                    LEFT JOIN (
                        SELECT l1.credito_id, l1.acao
                        FROM creditos_logs l1
                        INNER JOIN (SELECT credito_id, MAX(id) AS max_id FROM creditos_logs GROUP BY credito_id) l2
                            ON l2.credito_id = l1.credito_id AND l2.max_id = l1.id
                    ) lg ON lg.credito_id = cr.id
                    WHERE cr.cliente_id = ? AND lg.acao = 'aprovado'
                      AND cr.valor > COALESCE(cr.valor_utilizado,0)
                    ORDER BY cr.data ASC, cr.id ASC
                ");
                $credsStmt->execute([$u['id']]);
                $restante = $creditoAplicado;
                foreach ($credsStmt->fetchAll() as $cred) {
                    if ($restante <= 0.001) break;
                    $deduzir  = min($restante, (float)$cred['saldo']);
                    db()->prepare('UPDATE creditos SET valor_utilizado = COALESCE(valor_utilizado,0) + ? WHERE id = ?')
                        ->execute([$deduzir, $cred['id']]);
                    $restante -= $deduzir;
                }
            }

            // Grava log de criação/edição pelo cliente
            $logNumRow = db()->prepare('SELECT numero_pedido FROM pedidos WHERE id = ? LIMIT 1');
            $logNumRow->execute([$ids_criados[0]]);
            $logNumPed = $logNumRow->fetchColumn() ?: "#{$ids_criados[0]}";
            $logAcao   = $editarId > 0 ? 'Pedido editado pelo cliente' : 'Pedido criado pelo cliente';
            $logAntes  = $editarId > 0 ? 'comercial' : null;
            $logDet    = array_filter([
                $formaPagamento  ? 'Pagamento: ' . $formaPagamento : '',
                $creditoAplicado > 0.001 ? 'Crédito utilizado: R$ ' . number_format($creditoAplicado, 2, ',', '.') : '',
            ]);
            db()->prepare('INSERT INTO pedido_logs (pedido_id,numero_pedido,usuario_nome,usuario_tipo,acao,status_antes,status_depois,detalhes) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$ids_criados[0], $logNumPed, $u['nome'] ?? ($cli['razao_social'] ?? ''), 'cliente', $logAcao, $logAntes, 'comercial', implode(' | ', $logDet)]);

            $_SESSION['pdf_pedidos_ids'] = $ids_criados;
            header('Location: ' . BASE_URL . '/cliente/pedido-pdf.php'); exit;
        } else {
            flash('warning', 'Adicione ao menos um produto ao carrinho.');
        }
    } catch (Exception $e) {
        flash('danger', 'Erro: ' . $e->getMessage());
    }
    header('Location: ' . BASE_URL . '/cliente/meus-pedidos.php'); exit;
}

$cli_data = db()->prepare('SELECT desconto_cliente, desconto_canal, canal_venda_id FROM clientes WHERE id = ?');
$cli_data->execute([$u['id']]);
$cli_data     = $cli_data->fetch();
$desconto_pct = (float)($cli_data['desconto_cliente'] ?? 0) + (float)($cli_data['desconto_canal'] ?? 0);

// Crédito aprovado disponível para o cliente
$creditoStmt = db()->prepare("
    SELECT cr.id, cr.descricao, cr.valor,
           COALESCE(cr.valor_utilizado, 0) AS valor_utilizado,
           GREATEST(cr.valor - COALESCE(cr.valor_utilizado, 0), 0) AS saldo
    FROM creditos cr
    LEFT JOIN (
        SELECT l1.credito_id, l1.acao
        FROM creditos_logs l1
        INNER JOIN (SELECT credito_id, MAX(id) AS max_id FROM creditos_logs GROUP BY credito_id) l2
            ON l2.credito_id = l1.credito_id AND l2.max_id = l1.id
    ) lg ON lg.credito_id = cr.id
    WHERE cr.cliente_id = ? AND lg.acao = 'aprovado'
      AND cr.valor > COALESCE(cr.valor_utilizado, 0)
    ORDER BY cr.data ASC, cr.id ASC
");
$creditoStmt->execute([$u['id']]);
$creditosDisponiveis = $creditoStmt->fetchAll();
$creditoDisponivel   = array_sum(array_column($creditosDisponiveis, 'saldo'));

$produtos = db()->query('SELECT p.id, p.codigo_produto, p.codigo_barra, p.descricao_pt, p.multiplo, p.linha, p.grupo, p.subgrupo,
    COALESCE(t.preco_padrao, p.vendas_varejo, 0) as preco
    FROM produtos p LEFT JOIN tabela_precos t ON t.produto_id = p.id
    WHERE p.status = "ativo" ORDER BY p.linha, p.descricao_pt')->fetchAll();

$campanhas = db()->query('SELECT c.*, p.descricao_pt FROM campanhas c LEFT JOIN produtos p ON p.id = c.produto_id ORDER BY c.codigo_campanha')
    ->fetchAll();
// Filtra campanhas para mostrar apenas as do canal do cliente (ou sem canal)
$cliCanalId = (int)($cli_data['canal_venda_id'] ?? 0);
$campanhas  = array_filter($campanhas, fn($c) => !$c['canal_venda_id'] || (int)$c['canal_venda_id'] === $cliCanalId);

$porLinha = [];
foreach ($produtos as $p) {
    $linha = trim($p['linha'] ?? '');
    $linha = $linha !== '' ? $linha : 'Outros';
    $porLinha[$linha][] = $p;
}
ksort($porLinha);

$editarId      = (int)($_GET['editar'] ?? 0);
$editarPedido  = null;
$editarPedidos = [];
if ($editarId) {
    $stmt = db()->prepare('SELECT * FROM pedidos WHERE id = ? AND cliente_id = ? AND status = "comercial"');
    $stmt->execute([$editarId, $u['id']]);
    $editarPedido = $stmt->fetch();
    if (!$editarPedido) {
        flash('warning', 'Pedido não disponível para edição.');
        header('Location: ' . BASE_URL . '/cliente/meus-pedidos.php'); exit;
    }
    if ($editarPedido['lote_id']) {
        $stmtLote = db()->prepare('SELECT * FROM pedidos WHERE lote_id = ? AND cliente_id = ?');
        $stmtLote->execute([$editarPedido['lote_id'], $u['id']]);
        $editarPedidos = $stmtLote->fetchAll();
    } else {
        $editarPedidos = [$editarPedido];
    }
}

// modoMA: pela URL (?modo=ma_bonus) ou editando um pedido de bonificação
$modoMA          = (isset($_GET['modo']) && $_GET['modo'] === 'ma_bonus')
                || ($editarId && !empty($editarPedidos) && ($editarPedidos[0]['tipo_venda'] ?? '') === 'bonificacao');
$maSaldo         = 0.0;
$maLogId         = 0;
$maOriginalTotal = 0.0;
if ($modoMA) {
    $porLinha = array_filter($porLinha, fn($k) => in_array($k, $MA_LINHAS), ARRAY_FILTER_USE_KEY);
    $_mn2 = (int)date('n'); $_ay2 = (int)date('Y');
    $_mp2 = $_mn2===1?12:$_mn2-1; $_ap2 = $_mn2===1?$_ay2-1:$_ay2;
    $_di2 = sprintf('%04d-%02d-01',$_ap2,$_mp2);
    $_df2 = date('Y-m-t',mktime(0,0,0,$_mp2,1,$_ap2));
    $maStmt = db()->prepare("
        SELECT l.id AS log_id, COALESCE(l.valor_utilizado,0) AS valor_utilizado,
               c.material_apoio,
               COALESCE((SELECT SUM(valor_total) FROM pedidos
                         WHERE cliente_id=c.id AND status='faturado'
                         AND DATE(data_pedido) BETWEEN ? AND ?),0) AS fat
        FROM clientes c
        LEFT JOIN bonus_ma_logs l ON l.id=(
            SELECT MAX(id) FROM bonus_ma_logs
            WHERE cliente_id=c.id AND mes=? AND ano=? AND acao='aprovado'
        )
        WHERE c.id=?
    ");
    $maStmt->execute([$_di2,$_df2,$_mp2,$_ap2,$u['id']]);
    $maRow = $maStmt->fetch();
    if ($maRow && $maRow['log_id'] && (int)$maRow['material_apoio']>0) {
        $valorTotalMA    = (float)$maRow['fat'] * (int)$maRow['material_apoio'] / 100;
        // Na edição, devolve o valor original ao saldo para que o cliente possa reeditar livremente
        $maOriginalTotal = $editarId && !empty($editarPedidos)
            ? (float)array_sum(array_column($editarPedidos, 'valor_total')) : 0.0;
        $maSaldo = max(0.0, $valorTotalMA - (float)$maRow['valor_utilizado'] + $maOriginalTotal);
        $maLogId = (int)$maRow['log_id'];
    }
}
$linhas = array_keys($porLinha);

$pageTitle = $editarId ? 'Editar Pedido' : 'Novo Pedido';
require_once LAYOUT_PATH . '/header.php';
?>

<form id="formPedido" method="POST">
<?php if ($editarId): ?><input type="hidden" name="editar_id" value="<?= $editarId ?>"><?php endif; ?>
<?php if ($modoMA): ?>
<input type="hidden" name="modo" value="ma_bonus">
<input type="hidden" name="ma_log_id" value="<?= $maLogId ?>">
<input type="hidden" name="ma_original_total" value="<?= number_format($maOriginalTotal, 2, '.', '') ?>">
<?php endif; ?>

<!-- ══ ETAPA 1 ══════════════════════════════════════════════ -->
<div id="step1">

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-0">
            <i class="bi bi-<?= $editarId ? 'pencil-square' : 'plus-circle' ?> me-2 text-primary"></i>
            <?= $editarId ? 'Editar Pedido — ' . e($editarPedido['numero_pedido']) : 'Novo Pedido' ?>
        </h4>
        <small class="text-muted">Informe as quantidades e clique em Carrinho para avançar</small>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span id="headerTotal" class="fw-bold text-primary" style="display:none;font-size:1.05rem"></span>
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
                ?? ($c['linha']     ? 'Linha ' . $c['linha']         : null)
                ?? ($c['grupo']     ? 'Grupo ' . $c['grupo']         : null)
                ?? ($c['subgrupo']  ? 'Subgrupo ' . $c['subgrupo']   : 'Todos os produtos');
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

<?php if ($modoMA): ?>
<div class="alert alert-success border-success mb-3 d-flex align-items-center justify-content-between gap-3">
    <div class="d-flex align-items-center gap-3">
        <i class="bi bi-gift-fill fs-4 text-success"></i>
        <div>
            <strong>Pedido de Bônus de Material de Apoio</strong><br>
            <span class="small">Apenas linhas de Material de Apoio disponíveis. Tipo de venda: <strong>Bonificação</strong>.</span>
        </div>
    </div>
    <div class="text-end flex-shrink-0">
        <div class="text-muted small fw-semibold">Saldo disponível</div>
        <div class="fw-bold fs-5 text-success" id="maSaldoDisplay"><?= moedaBR($maSaldo) ?></div>
    </div>
</div>
<?php endif; ?>

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
                    $precoExib = $desconto_pct > 0 ? $preco * (1 - $desconto_pct/100) : $preco;
                ?>
                <tr class="produto-row"
                    data-pid="<?= $pid ?>"
                    data-preco="<?= e($precoExib) ?>"
                    data-nome="<?= e($p['descricao_pt']) ?>"
                    data-codigo="<?= e($p['codigo_produto']) ?>"
                    data-barra="<?= e($p['codigo_barra'] ?? '') ?>"
                    data-linha="<?= e($p['linha'] ?? '') ?>"
                    data-grupo="<?= e($p['grupo'] ?? '') ?>"
                    data-subgrupo="<?= e($p['subgrupo'] ?? '') ?>"
                    data-multiplo="<?= $multiplo ?>"
                    data-tab="<?= $tid ?>">
                    <td class="text-muted small"><?= e($p['codigo_produto']) ?></td>
                    <td class="text-muted small"><?= e($p['codigo_barra'] ?: '—') ?></td>
                    <td class="fw-semibold"><?= e($p['descricao_pt']) ?></td>
                    <td class="text-end text-muted small preco-unit-col" data-preco-fmt="<?= $precoExib > 0 ? e('R$ ' . number_format($precoExib, 2, ',', '.')) : '—' ?>">
                        <?= $precoExib > 0 ? 'R$ ' . number_format($precoExib, 2, ',', '.') : '—' ?>
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
        <small class="text-muted">Confira os itens e finalize</small>
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

<input type="hidden" name="forma_pagamento" id="formaPagamento" value="">
<input type="hidden" name="credito_aplicado" id="creditoAplicadoInput" value="0">

<div class="d-flex justify-content-end">
<?php if ($modoMA): ?>
    <button type="button" class="btn btn-success btn-lg px-5" id="btnFinalizarDireto">
        <i class="bi bi-check-lg me-2"></i>Finalizar Pedido
    </button>
<?php else: ?>
    <button type="button" class="btn btn-primary btn-lg px-5" id="btnAvancarPagamento"
            data-bs-toggle="modal" data-bs-target="#modalPagamento">
        <i class="bi bi-arrow-right me-2"></i>Avançar para Pagamento
    </button>
<?php endif; ?>
</div>

</div><!-- /step2 -->

<!-- ══ MODAL: forma de pagamento ══════════════════════════ -->
<div class="modal fade" id="modalPagamento" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-credit-card-2-front me-2 text-primary"></i>Forma de Pagamento
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" id="btnFecharModalPagto"></button>
            </div>
            <div class="modal-body pt-2">
                <p class="text-muted small mb-3">Selecione como deseja pagar este pedido:</p>
                <div class="d-grid gap-2" id="opcoesPagamento">

                    <label class="pagto-card d-flex align-items-center gap-3 p-3 border rounded-3"
                           style="cursor:pointer;transition:all .15s">
                        <input type="radio" class="form-check-input flex-shrink-0 mt-0"
                               name="pagamento_sel" value="Pix">
                        <span class="fs-4 text-success"><i class="bi bi-qr-code-scan"></i></span>
                        <div>
                            <div class="fw-semibold">Pix</div>
                            <div class="text-muted small">Pagamento à vista instantâneo</div>
                        </div>
                    </label>

                    <label class="pagto-card d-flex align-items-center gap-3 p-3 border rounded-3"
                           style="cursor:pointer;transition:all .15s">
                        <input type="radio" class="form-check-input flex-shrink-0 mt-0"
                               name="pagamento_sel" value="Boleto 30 Dias">
                        <span class="fs-4 text-primary"><i class="bi bi-calendar-check"></i></span>
                        <div>
                            <div class="fw-semibold">Boleto 30 Dias</div>
                            <div class="text-muted small">1 parcela — vencimento em 30 dias</div>
                        </div>
                    </label>

                    <label class="pagto-card d-flex align-items-center gap-3 p-3 border rounded-3"
                           style="cursor:pointer;transition:all .15s">
                        <input type="radio" class="form-check-input flex-shrink-0 mt-0"
                               name="pagamento_sel" value="Boleto 30/60 Dias">
                        <span class="fs-4 text-primary"><i class="bi bi-calendar2-range"></i></span>
                        <div>
                            <div class="fw-semibold">Boleto 30/60 Dias</div>
                            <div class="text-muted small">2 parcelas — 30 e 60 dias</div>
                        </div>
                    </label>

                    <label class="pagto-card d-flex align-items-center gap-3 p-3 border rounded-3"
                           style="cursor:pointer;transition:all .15s">
                        <input type="radio" class="form-check-input flex-shrink-0 mt-0"
                               name="pagamento_sel" value="Boleto 30/60/90 Dias">
                        <span class="fs-4 text-primary"><i class="bi bi-calendar3-range"></i></span>
                        <div>
                            <div class="fw-semibold">Boleto 30/60/90 Dias</div>
                            <div class="text-muted small">3 parcelas — 30, 60 e 90 dias</div>
                        </div>
                    </label>

                </div>
                <div class="alert alert-danger py-2 mt-2 mb-0" id="pagtoErro" style="display:none">
                    <i class="bi bi-exclamation-triangle me-1"></i>Selecione uma forma de pagamento para continuar.
                </div>

                <?php if ($creditoDisponivel > 0): ?>
                <div class="border-top pt-3 mt-3">
                    <p class="small text-muted mb-2 fw-semibold">CRÉDITO DISPONÍVEL</p>
                    <!-- Lançamentos individuais -->
                    <div class="mb-2" style="max-height:110px;overflow-y:auto">
                        <?php foreach ($creditosDisponiveis as $cr): ?>
                        <div class="d-flex justify-content-between align-items-center px-2 py-1 rounded mb-1" style="background:#f8f9fa;font-size:.82rem">
                            <span class="text-muted"><i class="bi bi-tag me-1"></i><?= e($cr['descricao'] ?: '—') ?></span>
                            <span class="fw-semibold text-success text-nowrap ms-2"><?= moedaBR($cr['saldo']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <label class="d-flex align-items-center justify-content-between gap-3 p-3 border rounded-3 bg-warning bg-opacity-10"
                           style="cursor:pointer" for="chkUsarCredito">
                        <div class="d-flex align-items-center gap-3">
                            <span class="fs-4 text-warning"><i class="bi bi-coin"></i></span>
                            <div>
                                <div class="fw-semibold">Usar crédito no pedido</div>
                                <div class="text-muted small">Total disponível: <strong class="text-success"><?= moedaBR($creditoDisponivel) ?></strong></div>
                            </div>
                        </div>
                        <div class="form-check form-switch mb-0 ms-2">
                            <input class="form-check-input" type="checkbox" role="switch"
                                   id="chkUsarCredito" style="width:2.5em;height:1.4em">
                        </div>
                    </label>
                    <div id="creditoAvisoBox" class="mt-2 p-2 rounded small" style="display:none;background:#f0fdf4;border:1px solid #bbf7d0">
                        <i class="bi bi-check-circle-fill text-success me-1"></i>
                        Será aplicado <strong id="creditoValorTexto"></strong> de crédito neste pedido.
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-arrow-left me-1"></i>Voltar
                </button>
                <button type="button" class="btn btn-success btn-lg px-5" id="btnFinalizarPedido">
                    <i class="bi bi-check-lg me-2"></i>Finalizar Pedido
                </button>
            </div>
        </div>
    </div>
</div>

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
            <button type="button" class="btn btn-outline-danger w-100 mt-2" id="btnLimparCarrinho">
                <i class="bi bi-trash me-1"></i>Limpar Carrinho
            </button>
        </div>
    </div>
</div>

</form>

<script>
var _cartKey           = 'sis_ped_cart_<?= (int)$u['id'] ?>';
var _maSaldo           = <?= $modoMA ? number_format($maSaldo, 2, '.', '') : 'Infinity' ?>;
var _modoMA            = <?= $modoMA ? 'true' : 'false' ?>;
var _creditoDisponivel = <?= number_format($creditoDisponivel, 2, '.', '') ?>;

function salvarCarrinho() {
    if (_modoMA) return;
    var items = {};
    document.querySelectorAll('.produto-row').forEach(function(row) {
        var vis = parseInt(row.querySelector('.qtd-visual').value) || 0;
        if (vis > 0) items[row.dataset.pid] = vis;
    });
    if (Object.keys(items).length > 0) {
        localStorage.setItem(_cartKey, JSON.stringify(items));
    } else {
        localStorage.removeItem(_cartKey);
    }
}

function restaurarCarrinho() {
    if (_modoMA) return;
    var saved = localStorage.getItem(_cartKey);
    if (!saved) return;
    try {
        var items = JSON.parse(saved);
        var restaurados = 0;
        Object.keys(items).forEach(function(pid) {
            var row = document.querySelector('.produto-row[data-pid="' + pid + '"]');
            if (!row) return;
            var vis    = parseInt(items[pid]) || 0;
            var mult   = parseFloat(row.dataset.multiplo) || 1;
            var actual = Math.round(vis * mult);
            if (vis < 1) return;
            row.querySelector('.qtd-visual').value          = vis;
            row.querySelector('.qtd-hidden').value          = actual;
            row.querySelector('.qtd-total-col').textContent = actual;
            restaurados++;
        });
        atualizarCarrinho();
    } catch(e) {
        localStorage.removeItem(_cartKey);
    }
}

var _campanhas = <?= json_encode(array_values(array_map(function($c) {
    return [
        'produto_id' => $c['produto_id'] ? (int)$c['produto_id'] : null,
        'linha'      => trim($c['linha']    ?? ''),
        'grupo'      => trim($c['grupo']    ?? ''),
        'subgrupo'   => trim($c['subgrupo'] ?? ''),
        'quantidade' => (int)$c['quantidade'],
        'desconto'   => (float)$c['desconto'],
    ];
}, $campanhas))) ?>;

function fmtBRL(v) {
    return 'R$ ' + v.toFixed(2).replace('.', ',');
}

function recalcularTodas() {
    // Soma quantidades por linha, grupo e subgrupo
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

    // Aplica desconto a cada linha
    document.querySelectorAll('.produto-row').forEach(function(row) {
        var pid      = parseInt(row.dataset.pid);
        var actual   = parseInt(row.querySelector('.qtd-hidden').value) || 0;
        var linha    = row.dataset.linha    || '';
        var grupo    = row.dataset.grupo    || '';
        var subgrupo = row.dataset.subgrupo || '';
        var precoBase = parseFloat(row.dataset.preco) || 0;

        var campDesc;
        if (row.dataset.campLocked === '1') {
            // Item já existente no pedido: usa o desconto gravado no banco
            campDesc = parseFloat(row.dataset.campDesc) || 0;
        } else {
            campDesc = 0;
            _campanhas.forEach(function(c) {
                if (c.quantidade <= 0) return;
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
        }

        var preco = campDesc > 0 ? precoBase * (1 - campDesc / 100) : precoBase;
        var precoCell = row.querySelector('.preco-unit-col');
        if (precoCell) {
            if (campDesc > 0) {
                precoCell.innerHTML = fmtBRL(preco) + ' <span class="badge bg-success ms-1" style="font-size:.7em">-' + campDesc + '%</span>';
            } else {
                precoCell.textContent = precoCell.dataset.precoFmt;
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
    var pid      = parseInt(row.dataset.pid);
    var multiplo = parseFloat(row.dataset.multiplo) || 1;
    var visual   = parseInt(visualInput.value) || 0;
    var actual   = Math.round(visual * multiplo);
    row.querySelector('.qtd-hidden').value          = actual;
    row.querySelector('.qtd-total-col').textContent = actual > 0 ? actual : '—';
    recalcularTodas();
    salvarCarrinho();
    atualizar();
}

function getItens() {
    var itens = [];
    document.querySelectorAll('.produto-row').forEach(function(row) {
        var actual = parseInt(row.querySelector('.qtd-hidden').value) || 0;
        if (actual > 0) {
            var pid       = parseInt(row.dataset.pid);
            var linha     = row.dataset.linha    || '';
            var visual    = parseInt(row.querySelector('.qtd-visual').value) || 0;
            var multiplo  = parseFloat(row.dataset.multiplo) || 1;
            var precoBase = parseFloat(row.dataset.preco) || 0;
            var campDesc  = parseFloat(row.dataset.campDesc) || 0;
            var preco     = campDesc > 0 ? precoBase * (1 - campDesc / 100) : precoBase;
            itens.push({
                pid:       pid,
                nome:      row.dataset.nome,
                codigo:    row.dataset.codigo,
                barra:     row.dataset.barra  || '',
                linha:     linha,
                preco:     preco,
                precoBase: precoBase,
                campDesc:  campDesc,
                visual:    visual,
                multiplo:  multiplo,
                qtd:       actual,
                sub:       preco * actual,
                tab:       row.dataset.tab
            });
        }
    });
    return itens;
}

function atualizar() {
    var itens  = getItens();
    var badges = {};
    itens.forEach(function(i) { badges[i.tab] = (badges[i.tab] || 0) + 1; });
    var total  = itens.reduce(function(a, i) { return a + i.sub; }, 0);

    document.querySelectorAll('.produto-row').forEach(function(row) {
        row.classList.toggle('table-primary', parseInt(row.querySelector('.qtd-hidden').value) > 0);
    });

    var totalVisual = itens.reduce(function(a, i) { return a + i.visual; }, 0);
    var cb = document.getElementById('cartBadge');
    cb.textContent   = totalVisual;
    cb.style.display = totalVisual > 0 ? '' : 'none';

    document.querySelectorAll('.tab-badge').forEach(function(b) {
        var cnt = badges[b.id.replace('badge-', '')] || 0;
        b.textContent   = cnt;
        b.style.display = cnt > 0 ? '' : 'none';
    });

    document.getElementById('carrinhoTotal').textContent = fmtBRL(total);

    var ht = document.getElementById('headerTotal');
    if (total > 0) { ht.textContent = fmtBRL(total); ht.style.display = ''; }
    else           { ht.style.display = 'none'; }

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

document.getElementById('btnLimparCarrinho').addEventListener('click', function() {
    if (!confirm('Deseja remover todos os produtos do carrinho?')) return;
    document.querySelectorAll('.produto-row').forEach(function(row) {
        var inp = row.querySelector('input[type="number"]');
        if (inp) { inp.value = 0; inp.dispatchEvent(new Event('input')); }
    });
    localStorage.removeItem(_cartKey);
    atualizarCarrinho();
});

document.getElementById('btnAvancar').addEventListener('click', function() {
    var itens = getItens();
    if (itens.length === 0) {
        alert('Adicione pelo menos um produto ao carrinho.');
        return;
    }

    if (isFinite(_maSaldo)) {
        var totalMA = itens.reduce(function(a, i) { return a + i.sub; }, 0);
        if (totalMA > _maSaldo + 0.01) {
            alert('O valor do pedido (' + fmtBRL(totalMA) + ') ultrapassa o saldo disponível de Bônus MA (' + fmtBRL(_maSaldo) + ').\nReduz a quantidade dos produtos para continuar.');
            return;
        }
    }

    gerarResumo();
    var oc = bootstrap.Offcanvas.getInstance(document.getElementById('offCarrinho'));
    if (oc) oc.hide();
    document.getElementById('step1').style.display = 'none';
    document.getElementById('step2').style.display = '';
    window.scrollTo({ top: 0, behavior: 'smooth' });
});

function gerarResumo() {
    var itens = getItens();
    if (itens.length === 0) { voltarStep1(); return; }

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
            var campBadge = i.campDesc > 0
                ? ' <span class="badge bg-success ms-1" style="font-size:.7em">-' + i.campDesc + '%</span>' : '';
            return '<tr>'
                + '<td class="text-muted small ps-3">'  + (i.codigo || '—') + '</td>'
                + '<td class="text-muted small">'        + (i.barra  || '—') + '</td>'
                + '<td class="fw-semibold">'              + i.nome + campBadge + '</td>'
                + '<td class="text-end small pe-3">'      + fmtBRL(i.preco) + '</td>'
                + '<td class="text-center small">'        + (i.multiplo > 1 ? i.multiplo : '—') + '</td>'
                + '<td class="text-center" style="width:100px">'
                + '<input type="number" class="form-control form-control-sm text-center resumo-qtd-input mx-auto" '
                + 'value="' + i.visual + '" min="0" step="1" data-pid="' + i.pid + '" style="width:68px">'
                + '</td>'
                + '<td class="text-center small fw-semibold">' + i.qtd + '</td>'
                + '<td class="text-end fw-semibold text-primary pe-3">' + fmtBRL(i.sub) + '</td>'
                + '</tr>';
        }).join('');

        html += '<div class="card border-0 shadow-sm mb-3">'
            + '<div class="card-header bg-white d-flex justify-content-between align-items-center py-2">'
            + '<span class="fw-bold"><i class="bi bi-tag me-2 text-primary"></i>' + linha + '</span>'
            + '<span class="text-muted small">Subtotal: <strong class="text-primary">' + fmtBRL(linhaTotal) + '</strong></span>'
            + '</div><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">'
            + '<thead class="table-light"><tr>'
            + '<th class="small ps-3" style="white-space:nowrap">Código</th>'
            + '<th class="small" style="white-space:nowrap">Cod. Barras</th>'
            + '<th class="small">Produto</th>'
            + '<th class="text-end small pe-3" style="white-space:nowrap">Preço Unit.</th>'
            + '<th class="text-center small" style="white-space:nowrap">Múlt.</th>'
            + '<th class="text-center small" style="white-space:nowrap">Quantidade</th>'
            + '<th class="text-center small" style="white-space:nowrap">Quantidade<br>Total</th>'
            + '<th class="text-end small pe-3" style="white-space:nowrap">Total R$</th>'
            + '</tr></thead><tbody>' + rows + '</tbody>'
            + '<tfoot class="table-light"><tr><td colspan="7" class="text-end fw-semibold small pe-3">Subtotal ' + linha + '</td>'
            + '<td class="text-end fw-bold text-primary pe-3">' + fmtBRL(linhaTotal) + '</td>'
            + '</tr></tfoot></table></div></div>';
    });

    html += '<div class="card border-0 shadow-sm mb-4">'
        + '<div class="card-body d-flex justify-content-between align-items-center py-3">'
        + '<span class="fw-bold fs-5">Total Geral</span>'
        + '<span class="fw-bold fs-4 text-primary">' + fmtBRL(total) + '</span>'
        + '</div></div>';

    document.getElementById('resumoConteudo').innerHTML = html;
}

document.getElementById('resumoConteudo').addEventListener('change', function(e) {
    if (!e.target.classList.contains('resumo-qtd-input')) return;
    var pid    = parseInt(e.target.dataset.pid);
    var row    = document.querySelector('.produto-row[data-pid="' + pid + '"]');
    if (!row) return;

    var visual = Math.max(0, parseInt(e.target.value) || 0);
    var mult   = parseFloat(row.dataset.multiplo) || 1;
    var actual = Math.round(visual * mult);

    row.querySelector('.qtd-visual').value          = visual;
    row.querySelector('.qtd-hidden').value          = actual;
    row.querySelector('.qtd-total-col').textContent = actual > 0 ? actual : '—';

    recalcularTodas();
    salvarCarrinho();

    if (isFinite(_maSaldo)) {
        var totalMA = getItens().reduce(function(a, i) { return a + i.sub; }, 0);
        if (totalMA > _maSaldo + 0.01) {
            alert('O valor do pedido (' + fmtBRL(totalMA) + ') ultrapassa o saldo de Bônus MA (' + fmtBRL(_maSaldo) + ').\nReduz a quantidade para continuar.');
            // Reverte
            var visAntes = parseInt(row.querySelector('.qtd-visual').value) || 0;
            var actAntes = Math.round(visAntes * mult);
            row.querySelector('.qtd-hidden').value          = actAntes;
            row.querySelector('.qtd-total-col').textContent = actAntes > 0 ? actAntes : '—';
            recalcularTodas();
            salvarCarrinho();
        }
    }

    gerarResumo();
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

function _submeterPedido(btnSpinner) {
    btnSpinner.disabled = true;
    btnSpinner.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processando...';
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
    localStorage.removeItem(_cartKey);
    document.getElementById('formPedido').submit();
}

if (_modoMA) {
    // Modo MA: finaliza direto, sem selecionar forma de pagamento
    document.getElementById('btnFinalizarDireto').addEventListener('click', function() {
        _submeterPedido(this);
    });
} else {
    // Destaca opção selecionada no modal
    document.getElementById('opcoesPagamento').addEventListener('change', function() {
        document.querySelectorAll('.pagto-card').forEach(function(card) {
            var radio = card.querySelector('input[type="radio"]');
            if (radio.checked) {
                card.classList.add('border-primary', 'bg-primary', 'bg-opacity-10');
            } else {
                card.classList.remove('border-primary', 'bg-primary', 'bg-opacity-10');
            }
        });
        document.getElementById('pagtoErro').style.display = 'none';
    });

    // Limpa seleção ao fechar o modal pelo X ou Voltar
    document.getElementById('modalPagamento').addEventListener('hidden.bs.modal', function() {
        document.querySelectorAll('input[name="pagamento_sel"]').forEach(function(r) { r.checked = false; });
        document.querySelectorAll('.pagto-card').forEach(function(c) {
            c.classList.remove('border-primary', 'bg-primary', 'bg-opacity-10');
        });
        document.getElementById('pagtoErro').style.display = 'none';
    });

    document.getElementById('btnFinalizarPedido').addEventListener('click', function() {
        var sel = document.querySelector('input[name="pagamento_sel"]:checked');
        if (!sel) {
            document.getElementById('pagtoErro').style.display = '';
            return;
        }
        document.getElementById('formaPagamento').value = sel.value;
        // Captura crédito a aplicar
        var chk = document.getElementById('chkUsarCredito');
        if (chk && chk.checked && _creditoDisponivel > 0) {
            var total = getItens().reduce(function(a,i){ return a+i.sub; }, 0);
            var aplicar = Math.min(_creditoDisponivel, total);
            document.getElementById('creditoAplicadoInput').value = aplicar.toFixed(2);
        }
        document.getElementById('btnFecharModalPagto').disabled = true;
        _submeterPedido(this);
    });

    // Toggle crédito: atualiza aviso com valor calculado
    var _chkCred = document.getElementById('chkUsarCredito');
    if (_chkCred) {
        _chkCred.addEventListener('change', function() {
            var box = document.getElementById('creditoAvisoBox');
            if (this.checked) {
                var total   = getItens().reduce(function(a,i){ return a+i.sub; }, 0);
                var aplicar = Math.min(_creditoDisponivel, total);
                var liquido = total - aplicar;
                document.getElementById('creditoValorTexto').innerHTML =
                    '<strong>' + fmtBRL(aplicar) + '</strong>. '
                    + 'Total a pagar: <strong>' + fmtBRL(liquido) + '</strong>';
                box.style.display = '';
            } else {
                box.style.display = 'none';
            }
        });
    }
}

var _preFill = <?= !empty($editarPedidos) ? json_encode(array_map(function($p) {
    return [
        'produto_id'        => (int)$p['produto_id'],
        'qtd'               => (int)$p['quantidade_total'],
        'desconto_campanha' => (float)($p['desconto_campanha'] ?? 0),
    ];
}, $editarPedidos)) : 'null' ?>;
if (_preFill) {
    _preFill.forEach(function(item) {
        var _row = document.querySelector('.produto-row[data-pid="' + item.produto_id + '"]');
        if (_row) {
            var _mult = parseFloat(_row.dataset.multiplo) || 1;
            var _vis  = _mult > 1 ? Math.round(item.qtd / _mult) : item.qtd;
            if (_vis < 1) _vis = 1;
            _row.querySelector('.qtd-visual').value          = _vis;
            _row.querySelector('.qtd-hidden').value          = item.qtd;
            _row.querySelector('.qtd-total-col').textContent = item.qtd;
            _row.dataset.campDesc   = item.desconto_campanha || 0;
            _row.dataset.campLocked = '1';
        }
    });
    if (_preFill.length > 0) {
        var _firstRow = document.querySelector('.produto-row[data-pid="' + _preFill[0].produto_id + '"]');
        if (_firstRow) {
            var _tab = _firstRow.dataset.tab;
            var _btn = document.querySelector('[data-bs-target="#pane-' + _tab + '"]');
            if (_btn) _btn.click();
            setTimeout(function() { _firstRow.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 200);
        }
    }
}
if (!_preFill) restaurarCarrinho();
recalcularTodas();
atualizar();
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
