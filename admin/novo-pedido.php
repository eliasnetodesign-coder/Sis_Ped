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

    $tipoVenda     = (($_POST['tipo_venda'] ?? 'venda') === 'bonificacao') ? 'bonificacao' : 'venda';
    // Bonificação: sem desconto de canal/cliente e usa a tabela de preços Network
    $desconto      = ($tipoVenda === 'bonificacao')
        ? 0.0
        : ((float)($cli['desconto_cliente'] ?? 0) + (float)($cli['desconto_canal'] ?? 0)) / 100;
    $colPreco      = colPrecoMoeda($cli['moeda'] ?? 'BRL', $tipoVenda === 'bonificacao');
    // Bonificação usa preço network (BRL); não converte. Demais usam a cotação do dia.
    $cotacaoPedido = ($tipoVenda === 'bonificacao') ? null : cotacaoDia($cli['moeda'] ?? 'BRL');
    $data          = date('Y-m-d');
    $campanhas_all = db()->query('SELECT codigo_campanha, produto_id, linha, grupo, subgrupo, canal_venda_id, quantidade, desconto FROM campanhas WHERE ' . campanhaVigenteSql())->fetchAll();
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
            $stmtP = db()->prepare("SELECT p.*, COALESCE($colPreco, p.vendas_varejo) as preco FROM produtos p LEFT JOIN tabela_precos t ON t.produto_id = p.id WHERE p.id = ? AND p.status = \"ativo\"");
            $stmtP->execute([$produto_id]);
            $prod = $stmtP->fetch();
            if (!$prod) continue;
            $items_data[] = ['produto_id' => $produto_id, 'qtd' => $qtd, 'prod' => $prod];
            $l = trim($prod['linha']    ?? ''); if ($l) $totaisLinha[$l]    = ($totaisLinha[$l]    ?? 0) + $qtd;
            $g = trim($prod['grupo']    ?? ''); if ($g) $totaisGrupo[$g]    = ($totaisGrupo[$g]    ?? 0) + $qtd;
            $s = trim($prod['subgrupo'] ?? ''); if ($s) $totaisSubgrupo[$s] = ($totaisSubgrupo[$s] ?? 0) + $qtd;
        }

        // Soma das quantidades pedidas por produto e por campanha de produtos
        // (campanha com vários produtos: o mínimo considera a soma de todos eles)
        $qtdPorProduto = [];
        foreach ($items_data as $it) $qtdPorProduto[$it['produto_id']] = ($qtdPorProduto[$it['produto_id']] ?? 0) + $it['qtd'];
        $totaisCampanha = [];
        foreach ($campanhas_all as $camp) {
            if (!$camp['produto_id']) continue;
            $cod = $camp['codigo_campanha'];
            $totaisCampanha[$cod] = ($totaisCampanha[$cod] ?? 0) + ($qtdPorProduto[(int)$camp['produto_id']] ?? 0);
        }

        // Campanhas de desconto do novo modelo (condições E, qtd OU valor por categoria)
        $ctxCamp = ctxCampanha(array_map(fn($it) => [
            'produto_id' => $it['produto_id'], 'qtd' => $it['qtd'],
            'linha' => $it['prod']['linha'] ?? '', 'grupo' => $it['prod']['grupo'] ?? '', 'subgrupo' => $it['prod']['subgrupo'] ?? '',
            'preco' => (float)$it['prod']['preco'],
        ], $items_data), $canalVendaId, (int)$cliente_id);
        $descAvancados = ($tipoVenda !== 'bonificacao') ? avaliarCampanhasDescontoAvancadas($ctxCamp) : [];

        // Passagem 2: gravar cada item aplicando desconto de campanha com base nos totais
        $loteFinal = count($items_data) > 1 ? $lote_id : null;
        foreach ($items_data as $it) {
            $produto_id  = $it['produto_id'];
            $qtd         = $it['qtd'];
            $prod        = $it['prod'];
            $valor_total = $qtd * (float)$prod['preco'] * (1 - $desconto);

            $campDescJS = (float)($itens[$produto_id]['camp_desc'] ?? 0);
            $campDesc   = 0;
            if ($tipoVenda !== 'bonificacao')
            foreach ($campanhas_all as $camp) {
                if ((int)$camp['quantidade'] <= 0) continue;
                if ($camp['canal_venda_id'] && (int)$camp['canal_venda_id'] !== $canalVendaId) continue;
                if ($camp['produto_id'] && (int)$camp['produto_id'] !== $produto_id) continue;
                if (!$camp['produto_id']) {
                    $cLinha    = trim(preg_replace('/\d+/', '', $camp['linha']    ?? ''));
                    $cGrupo    = trim(preg_replace('/\d+/', '', $camp['grupo']    ?? ''));
                    $cSubgrupo = trim(preg_replace('/\d+/', '', $camp['subgrupo'] ?? ''));
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
                    // Campanha por produto: usa a soma das quantidades de todos os produtos da campanha
                    $qtdRef = $totaisCampanha[$camp['codigo_campanha']] ?? $qtd;
                }
                if ($qtdRef < (int)$camp['quantidade']) continue;
                if ((float)$camp['desconto'] > $campDesc) $campDesc = (float)$camp['desconto'];
            }
            // Se PHP não detectou campanha mas JS detectou, valida e usa o valor do JS
            if ($tipoVenda !== 'bonificacao' && $campDescJS > $campDesc) {
                $validDesc = array_column($campanhas_all, 'desconto');
                if (in_array(number_format($campDescJS, 4), array_map(fn($d) => number_format((float)$d, 4), $validDesc))) {
                    $campDesc = $campDescJS;
                }
            }
            // Campanhas de desconto do novo modelo: desconto incide nos itens dos grupos da condição
            foreach ($descAvancados as $ac) {
                if ($ac['desconto'] > $campDesc && itemBateGruposAlvo($prod, $ac['gruposAlvo'])) {
                    $campDesc = $ac['desconto'];
                }
            }

            if ($campDesc > 0) $valor_total *= (1 - $campDesc / 100);

            $num = 'PED-' . date('Y') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
            db()->prepare('INSERT INTO pedidos (numero_pedido,tipo_venda,data_pedido,cliente_id,produto_id,supervisor,codigo_barra,descricao_produto,quantidade_total,valor_total,status,observacoes,lote_id,desconto_campanha,moeda,cotacao) VALUES (?,?,?,?,?,?,?,?,?,?,"comercial",?,?,?,?,?)')
                ->execute([$num, $tipoVenda, $data, $cliente_id, $produto_id, $cli['supervisor'] ?? $cli['vendedor'] ?? '', $prod['codigo_barra'], $prod['descricao_pt'], $qtd, $valor_total, $obs_geral, $loteFinal, $campDesc ?: null, $cli['moeda'] ?? 'BRL', $cotacaoPedido]);
            $ids_criados[] = (int)db()->lastInsertId();
            $criados++;
        }

        if ($criados > 0) {
            $msg = $criados . ' item(ns) adicionado(s) com sucesso!';
            // Bonificação automática de campanha (apenas em vendas)
            if ($tipoVenda === 'venda') {
                $itensVenda = array_map(function ($it) {
                    return [
                        'produto_id' => $it['produto_id'],
                        'qtd'        => $it['qtd'],
                        'linha'      => $it['prod']['linha']    ?? '',
                        'grupo'      => $it['prod']['grupo']    ?? '',
                        'subgrupo'   => $it['prod']['subgrupo'] ?? '',
                        'preco'      => (float)$it['prod']['preco'],
                    ];
                }, $items_data);
                try {
                    $refNum = db()->query('SELECT numero_pedido FROM pedidos WHERE id = ' . (int)$ids_criados[0])->fetchColumn();
                    $bonus  = gerarBonificacaoCampanha($cliente_id, $canalVendaId, $cli['supervisor'] ?? $cli['vendedor'] ?? '', $data, $itensVenda, $refNum ?: null);
                    if ($bonus) {
                        $msg .= ' Bonificação gerada: ' . implode(', ', array_map(fn($b) => $b['quantidade'] . 'x ' . $b['descricao'], $bonus)) . '.';
                    }
                    // Bonificação selecionável: redireciona para a tela de escolha dos bônus
                    $selec = detectarBonificacaoSelecionavel($itensVenda, $canalVendaId, (int)$cliente_id);
                    if ($selec) {
                        $_SESSION['bonus_selecionavel'] = [
                            'campanhas'  => $selec,
                            'cliente_id' => (int)$cliente_id,
                            'supervisor' => $cli['supervisor'] ?? $cli['vendedor'] ?? '',
                            'data'       => $data,
                            'ref'        => $refNum ?: null,
                            'retorno'    => BASE_URL . '/admin/pedido.php?id=' . $ids_criados[0],
                        ];
                        flash('success', $msg);
                        header('Location: ' . BASE_URL . '/cliente/bonificacao-selecionavel.php'); exit;
                    }
                } catch (Exception $e) { /* bônus não deve impedir a confirmação da venda */ }
            }
            flash('success', $msg);
            header('Location: ' . BASE_URL . '/admin/pedido.php?id=' . $ids_criados[0]); exit;
        } else {
            flash('warning', 'Adicione ao menos um produto ao carrinho.');
        }
    } catch (Exception $e) {
        flash('danger', 'Erro ao criar pedido: ' . $e->getMessage());
    }
    header('Location: ' . BASE_URL . '/admin/novo-pedido.php'); exit;
}

$clientes  = db()->query('SELECT id, razao_social, codigo_cliente, desconto_cliente, desconto_canal, canal_venda_id, idioma, moeda FROM clientes WHERE status = "ativo" ORDER BY razao_social')->fetchAll();
$campanhas = db()->query('SELECT c.*, p.descricao_pt, cv.canal FROM campanhas c LEFT JOIN produtos p ON p.id = c.produto_id LEFT JOIN canal_venda cv ON cv.id = c.canal_venda_id WHERE ' . campanhaVigenteSql('c') . ' ORDER BY c.codigo_campanha')->fetchAll();

// Produtos bonificados por campanha (chips de campanhas de bonificação)
$bonifByCode = [];      // com "Nx" (modo fixo)
$bonifNomesByCode = []; // só nomes (modo selecionável)
foreach (db()->query('SELECT cb.codigo_campanha, cb.quantidade, p.descricao_pt, p.codigo_produto
    FROM campanha_bonificacao cb JOIN produtos p ON p.id = cb.produto_id ORDER BY cb.id')->fetchAll() as $b) {
    $nome = $b['descricao_pt'] ?: $b['codigo_produto'];
    $bonifByCode[$b['codigo_campanha']][]      = (int)$b['quantidade'] . 'x ' . $nome;
    $bonifNomesByCode[$b['codigo_campanha']][] = $nome;
}

// Condições combinadas (E) por código de campanha — cada uma é um filtro composto
$condByCode = [];
try { $condRows = db()->query('SELECT codigo_campanha, criterio_tipo, criterio_valor, quantidade, criterio_modo, valor_min, cond_linha, cond_grupo, cond_subgrupo, cond_produto_id FROM campanha_condicoes ORDER BY id')->fetchAll(); }
catch (PDOException $e) {
    try { $condRows = db()->query('SELECT codigo_campanha, criterio_tipo, criterio_valor, quantidade, criterio_modo, valor_min FROM campanha_condicoes ORDER BY id')->fetchAll(); }
    catch (PDOException $e2) { $condRows = db()->query('SELECT codigo_campanha, criterio_tipo, criterio_valor, quantidade FROM campanha_condicoes ORDER BY id')->fetchAll(); }
}
foreach ($condRows as $cc) {
    $f = condFiltro($cc);
    $condByCode[$cc['codigo_campanha']][] = [
        'linha'     => $f['linha'] ?? '',
        'grupo'     => $f['grupo'] ?? '',
        'subgrupo'  => $f['subgrupo'] ?? '',
        'produto'   => $f['produto'] ?? 0,
        'modo'      => ($cc['criterio_modo'] ?? 'quantidade') === 'valor' ? 'valor' : 'quantidade',
        'quantidade'=> (int)$cc['quantidade'],
        'valor_min' => (float)($cc['valor_min'] ?? 0),
    ];
}

// Alvos explícitos do desconto por código de campanha
$alvoByCode = [];
try {
    $alvoRows = db()->query('SELECT codigo_campanha, alvo_tipo, alvo_valor, alvo_linha, alvo_grupo, alvo_subgrupo, alvo_produto_id, desconto
        FROM campanha_desconto_alvo ORDER BY id')->fetchAll();
} catch (PDOException $e) {
    try { $alvoRows = db()->query('SELECT codigo_campanha, alvo_tipo, alvo_valor FROM campanha_desconto_alvo ORDER BY id')->fetchAll(); }
    catch (PDOException $e2) { $alvoRows = []; }
}
foreach ($alvoRows as $al) $alvoByCode[$al['codigo_campanha']][] = alvoFiltro($al);

// Nome legível de produtos referenciados em condições/alvos
$prodNomeById = [];
foreach (db()->query('SELECT id, codigo_produto, descricao_pt FROM produtos')->fetchAll() as $p) {
    $prodNomeById[(int)$p['id']] = $p['descricao_pt'] ?: $p['codigo_produto'];
}

// Agrupa campanhas por código (uma campanha pode ter vários alvos: produtos/linha/grupo/subgrupo)
$campGroup = [];
foreach ($campanhas as $c) {
    $code = $c['codigo_campanha'];
    if (!isset($campGroup[$code])) {
        $campGroup[$code] = [
            'codigo_campanha'    => $code,
            'tipo'               => $c['tipo'] ?? 'desconto',
            'desconto'           => $c['desconto'],
            'quantidade'         => $c['quantidade'],
            'valor_alvo'         => $c['valor_alvo'] ?? null,
            'bonif_modo'         => $c['bonif_modo'] ?? 'fixo',
            'bonif_limite_tipo'  => $c['bonif_limite_tipo'] ?? 'quantidade',
            'bonif_limite_valor' => $c['bonif_limite_valor'] ?? null,
            'cond_operador'      => campanhaOperador([$c]),
            'aplicacao'          => $c['aplicacao'] ?? 'canal',
            'observacao'         => trim((string)($c['observacao'] ?? '')),
            'total'              => campanhaTotalCond([$c]),
            'faixas'             => (($c['tipo'] ?? 'desconto') === 'desconto' && ($c['desconto_modo'] ?? 'fixo') === 'faixas')
                                    ? (campanhasFaixas()[$code] ?? []) : null,
            'alvos'              => [],
        ];
    }
    $alvo = $c['descricao_pt']
        ?? ($c['linha']    ? 'Linha '    . trim($c['linha'])    : null)
        ?? ($c['grupo']    ? 'Grupo '    . trim($c['grupo'])    : null)
        ?? ($c['subgrupo'] ? 'Subgrupo ' . trim($c['subgrupo']) : 'Todos os produtos');
    if (!in_array($alvo, $campGroup[$code]['alvos'], true)) $campGroup[$code]['alvos'][] = $alvo;
}

// Texto do gatilho: condição composta (linha + grupo + ...) com qtd OU valor mínimo
$critTexto = function($cd) use ($prodNomeById) {
    $parts = [];
    if (!empty($cd['linha']))    $parts[] = 'Linha '    . $cd['linha'];
    if (!empty($cd['grupo']))    $parts[] = 'Grupo '    . $cd['grupo'];
    if (!empty($cd['subgrupo'])) $parts[] = 'Subgrupo ' . $cd['subgrupo'];
    if (!empty($cd['produto']))  $parts[] = 'Produto '  . ($prodNomeById[(int)$cd['produto']] ?? ('#' . $cd['produto']));
    $ehVal = ($cd['modo'] ?? 'quantidade') === 'valor';
    if (($ehVal ? (float)($cd['valor_min'] ?? 0) : (int)$cd['quantidade']) <= 0) return implode(' · ', $parts);
    $alvo = $ehVal
        ? '≥ ' . moedaBR((float)($cd['valor_min'] ?? 0))
        : '≥ ' . (int)$cd['quantidade'] . ' un.';
    return implode(' · ', $parts) . ' ' . $alvo;
};
foreach ($campGroup as $code => &$g) {
    $conds = $condByCode[$code] ?? [];
    // Faixas escalonadas: "F2: Grupo X ≥ 504 un. E Grupo Y ≥ 42 un. → 8%"
    $g['faixas_txt'] = [];
    foreach (($g['faixas'] ?? []) as $k => $fx) {
        $cs = array_values($conds);
        foreach ($cs as $i => &$cd) {
            if (!array_key_exists($i, $fx['mins'])) continue;
            if (($cd['modo'] ?? 'quantidade') === 'valor') $cd['valor_min'] = $fx['mins'][$i]; else $cd['quantidade'] = (int)$fx['mins'][$i];
        }
        unset($cd);
        $tot = $g['total'] && $fx['total_min'] !== null ? ' · Total ≥ ' . ($g['total']['modo'] === 'valor' ? moedaBR($fx['total_min']) : (int)$fx['total_min'] . ' un.') : '';
        $g['faixas_txt'][] = ['F' . $k, implode(' ' . $g['cond_operador'] . ' ', array_map($critTexto, $cs)) . $tot, $fx['pct']];
    }
    if ($g['observacao'] !== '') {
        $g['gatilho'] = $g['observacao'];   // observação da campanha substitui o texto da condição
    } elseif ($conds) {
        $g['gatilho'] = implode(' ' . $g['cond_operador'] . ' ', array_map($critTexto, $conds))
                      . ($g['total'] ? ' · Total ≥ ' . ($g['total']['modo'] === 'valor' ? moedaBR($g['total']['min']) : (int)$g['total']['min'] . ' un.') : '');
    } elseif ((float)$g['valor_alvo'] > 0 && $g['alvos'] === ['Todos os produtos']) {
        $g['gatilho'] = 'Valor ≥ ' . moedaBR((float)$g['valor_alvo']);
    } else {
        $g['gatilho'] = implode(', ', $g['alvos']) . ' · a partir de ' . (int)$g['quantidade'] . ' un.';
    }
}
unset($g);

$produtos = db()->query('SELECT p.id, p.codigo_produto, p.codigo_barra, p.descricao_pt, p.multiplo, p.linha, p.grupo, p.subgrupo, p.desc_cliente_pt, p.desc_cliente_en, p.desc_cliente_es,
    COALESCE(t.preco_padrao, p.vendas_varejo, 0) as preco,
    COALESCE(t.preco_network, p.vendas_varejo, 0) as preco_network,
    COALESCE(t.preco_dolar, p.vendas_varejo, 0) as preco_dolar,
    COALESCE(t.preco_euro,  p.vendas_varejo, 0) as preco_euro
    FROM produtos p LEFT JOIN tabela_precos t ON t.produto_id = p.id
    WHERE p.status = "ativo" ORDER BY p.linha, p.descricao_pt')->fetchAll();

$MA_MERGE = ['MAT APOIO ITALLIAN - BRINDE', 'MAT APOIO ITALLIAN - VENDIDO'];
$porLinha = [];
foreach ($produtos as $p) {
    $linha = trim($p['linha'] ?? '');
    $linha = $linha !== '' ? $linha : 'Outros';
    if (in_array($linha, $MA_MERGE, true)) $linha = 'MATERIAL DE APOIO';
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
<div class="mb-3 p-3 rounded-3 border bg-white" id="campanhasPanel">
    <div class="d-flex align-items-center gap-2 mb-2">
        <i class="bi bi-megaphone-fill text-primary"></i>
        <span class="fw-semibold text-primary small text-uppercase">Campanhas Ativas</span>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php foreach ($campGroup as $c):
            $ehBonif = $c['tipo'] === 'bonificacao';
            $ehSelec = $ehBonif && ($c['bonif_modo'] ?? 'fixo') === 'selecionavel';
            $pct = rtrim(rtrim(number_format((float)$c['desconto'], 2, ',', '.'), '0'), ',');
            $limiteTxt = ($c['bonif_limite_tipo'] ?? '') === 'valor'
                ? moedaBR((float)$c['bonif_limite_valor'])
                : ((int)$c['bonif_limite_valor'] . ' un.');
        ?>
        <div class="d-flex align-items-center gap-2 border rounded-3 px-3 py-2" style="background:#f8fffe">
            <?php if ($ehBonif): ?>
            <span class="badge bg-warning text-dark fs-6 fw-bold px-2"><i class="bi bi-gift"></i></span>
            <div style="line-height:1.3">
                <div class="fw-semibold" style="font-size:.82rem"><?= e($c['codigo_campanha']) ?></div>
                <div style="font-size:.76rem;color:var(--brand-dark)"><?= e($c['gatilho']) ?></div>
                <div class="fw-semibold" style="font-size:.74rem;color:#c8880a">
                    <?php if ($ehSelec): ?>
                    <i class="bi bi-hand-index me-1"></i>Cliente escolhe (até <?= e($limiteTxt) ?>): <?= e(implode(', ', $bonifNomesByCode[$c['codigo_campanha']] ?? ['—'])) ?>
                    <?php else: ?>
                    <i class="bi bi-gift-fill me-1"></i>Brinde: <?= e(implode(', ', $bonifByCode[$c['codigo_campanha']] ?? ['—'])) ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <?php if ($c['faixas'] !== null):
                $fmtP = fn($v) => rtrim(rtrim(number_format((float)$v, 2, ',', '.'), '0'), ',');
                $maxPct = max(array_merge([(float)$c['desconto']], array_column($c['faixas'], 'pct')));
            ?>
            <span class="badge bg-success fs-6 fw-bold px-2"><small class="fw-normal">até</small> −<?= $fmtP($maxPct) ?>%</span>
            <?php else: ?>
            <span class="badge bg-success fs-6 fw-bold px-2">−<?= $pct ?>%</span>
            <?php endif; ?>
            <div style="line-height:1.3">
                <div class="fw-semibold" style="font-size:.82rem"><?= e($c['codigo_campanha']) ?></div>
                <div style="font-size:.76rem;color:var(--brand-dark)"><?= e($c['gatilho']) ?></div>
                <?php if (($c['aplicacao'] ?? 'canal') === 'clientes'): ?>
                <div style="font-size:.72rem" class="text-muted"><i class="bi bi-person-check me-1"></i>Só para clientes específicos</div>
                <?php endif; ?>
                <?php if ($c['faixas'] !== null): ?>
                <div style="font-size:.72rem" class="text-success"><strong>F1:</strong> <?= $fmtP($c['desconto']) ?>%
                    <?php foreach ($c['faixas_txt'] as [$fn, $ft, $fp]): ?>
                    <div><strong><?= e($fn) ?>:</strong> <?= e($ft) ?> → <?= $fmtP($fp) ?>%</div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
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
                             data-canal-id="<?= (int)($c['canal_venda_id'] ?? 0) ?>"
                             data-idioma="<?= e($c['idioma'] ?? 'pt') ?>"
                             data-moeda="<?= e($c['moeda'] ?? 'BRL') ?>">
                            <span class="badge bg-secondary me-1"><?= e($c['codigo_cliente']) ?></span><?= e($c['razao_social']) ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Tipo de Pedido</label>
                <input type="hidden" name="tipo_venda" id="tipo_venda" value="venda">
                <select id="tipo_venda_sel" class="form-select" onchange="onTipoVendaChange(this.value)">
                    <option value="venda" selected>Venda</option>
                    <option value="bonificacao">Bonificação</option>
                </select>
            </div>
            <input type="hidden" name="data_pedido" value="<?= date('Y-m-d') ?>">
        </div>
        <div id="alertBonificacao" class="alert alert-info py-2 mt-3 mb-0" style="display:none">
            <i class="bi bi-gift-fill me-1"></i><strong>Bonificação:</strong> usa a tabela de preços <strong>Network</strong>, sem desconto de canal/cliente e sem campanhas.
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
                        <th class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($porLinha[$linha] as $p):
                    $pid      = (int)$p['id'];
                    $multiplo = (float)($p['multiplo'] ?? 0);
                    $multiplo = $multiplo > 0 ? $multiplo : 1;
                    $preco    = (float)$p['preco'];
                    $precoNet = (float)$p['preco_network'];
                    $precoUsd = (float)$p['preco_dolar'];
                    $precoEur = (float)$p['preco_euro'];
                ?>
                <tr class="produto-row"
                    data-pid="<?= $pid ?>"
                    data-preco="<?= e($preco) ?>"
                    data-preco-net="<?= e($precoNet) ?>"
                    data-preco-usd="<?= e($precoUsd) ?>"
                    data-preco-eur="<?= e($precoEur) ?>"
                    data-nome="<?= e($p['descricao_pt']) ?>"
                    data-codigo="<?= e($p['codigo_produto']) ?>"
                    data-linha="<?= e($p['linha'] ?? '') ?>"
                    data-grupo="<?= e($p['grupo'] ?? '') ?>"
                    data-subgrupo="<?= e($p['subgrupo'] ?? '') ?>"
                    data-multiplo="<?= $multiplo ?>"
                    data-tab="<?= $tid ?>"
                    data-desc-pt="<?= e($p['desc_cliente_pt'] ?? '') ?>"
                    data-desc-en="<?= e($p['desc_cliente_en'] ?? '') ?>"
                    data-desc-es="<?= e($p['desc_cliente_es'] ?? '') ?>">
                    <td class="text-muted small"><?= e($p['codigo_produto']) ?></td>
                    <td class="text-muted small"><?= e($p['codigo_barra'] ?: '—') ?></td>
                    <td class="fw-semibold prod-nome-cell"><?= e($p['descricao_pt']) ?></td>
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
var _moeda     = 'BRL';
var tipoVenda  = 'venda';

// Preço base do produto conforme a moeda do cliente (bonificação sempre usa Network)
function precoBaseRow(row, ehBonif) {
    if (ehBonif) return parseFloat(row.dataset.precoNet) || 0;
    if (_moeda === 'USD') return parseFloat(row.dataset.precoUsd) || 0;
    if (_moeda === 'EUR') return parseFloat(row.dataset.precoEur) || 0;
    return parseFloat(row.dataset.preco) || 0;
}

function onTipoVendaChange(val) {
    tipoVenda = (val === 'bonificacao') ? 'bonificacao' : 'venda';
    document.getElementById('tipo_venda').value = tipoVenda;
    var alertBon = document.getElementById('alertBonificacao');
    if (alertBon) alertBon.style.display = (tipoVenda === 'bonificacao') ? '' : 'none';
    var campPanel = document.getElementById('campanhasPanel');
    if (campPanel) campPanel.style.display = (tipoVenda === 'bonificacao') ? 'none' : '';
    // Esconde alerta de desconto na bonificação (não se aplica)
    if (tipoVenda === 'bonificacao') {
        var alertDesc = document.getElementById('alertDesconto');
        if (alertDesc) alertDesc.style.display = 'none';
    }
    recalcularTodas();
    atualizar();
}
var _campanhas = <?= json_encode(array_map(function($c) {
    return [
        'codigo'       => $c['codigo_campanha'],
        'produto_id'   => $c['produto_id'] ? (int)$c['produto_id'] : null,
        'linha'        => trim(preg_replace('/\d+/', '', $c['linha']    ?? '')),
        'grupo'        => trim(preg_replace('/\d+/', '', $c['grupo']    ?? '')),
        'subgrupo'     => trim(preg_replace('/\d+/', '', $c['subgrupo'] ?? '')),
        'canal_id'     => $c['canal_venda_id'] ? (int)$c['canal_venda_id'] : null,
        'quantidade'   => (int)$c['quantidade'],
        'desconto'     => (float)$c['desconto'],
    ];
}, $campanhas)) ?>;

// Campanhas de DESCONTO do novo modelo (condições E, cada uma por qtd OU valor) — espelha o PHP.
// O desconto incide nos alvos explícitos (se houver) ou nos itens das condições.
var _campCondicoes = <?= json_encode((function () use ($campanhas, $condByCode, $alvoByCode) {
    $hdr = [];
    foreach ($campanhas as $c) {
        $code = $c['codigo_campanha'];
        if (!isset($hdr[$code])) $hdr[$code] = $c; // 1ª linha = cabeçalho da campanha
    }
    $out = [];
    foreach ($hdr as $code => $c) {
        if (($c['tipo'] ?? 'desconto') !== 'desconto') continue;
        $conds = $condByCode[$code] ?? [];
        if (!$conds) continue; // só novo modelo (com condições)
        $out[] = [
            'canal_id'  => $c['canal_venda_id'] ? (int)$c['canal_venda_id'] : null,
            'desconto'  => (float)$c['desconto'],
            'total'     => campanhaTotalCond([$c]),
            'ou'        => campanhaOperador([$c]) === 'OU',
            'mesma'     => (int)($c['cond_mesma_qtd'] ?? 0) === 1,
            'clientes'  => ($c['aplicacao'] ?? 'canal') === 'clientes' ? array_keys(campanhasClientes()[$code] ?? []) : null,
            'conds'     => $cj = array_values(array_map(fn($cd) => [
                'linha'    => $cd['linha'], 'grupo' => $cd['grupo'], 'subgrupo' => $cd['subgrupo'], 'produto' => (int)($cd['produto'] ?: 0),
                'modo'     => $cd['modo'] ?? 'quantidade',
                'qtd'      => (int)$cd['quantidade'],
                'valorMin' => (float)($cd['valor_min'] ?? 0),
            ], $conds)),
            'niveis'    => campanhaNiveisJs((string)$code, $c, $cj, campanhaTotalCond([$c])),
            'alvos'     => array_map(fn($a) => [
                'linha' => $a['linha'], 'grupo' => $a['grupo'], 'subgrupo' => $a['subgrupo'], 'produto' => (int)($a['produto'] ?: 0),
                'desconto' => $a['desconto'] ?? null,
            ], $alvoByCode[$code] ?? []),
        ];
    }
    return $out;
})()) ?>;

// Campanhas por produto: código -> lista de produto_id (para somar a quantidade mínima)
var _campProdIds = {};
_campanhas.forEach(function(c) {
    if (c.produto_id !== null) (_campProdIds[c.codigo] = _campProdIds[c.codigo] || []).push(c.produto_id);
});

function simboloMoedaJS() {
    if (_moeda === 'USD') return 'US$';
    if (_moeda === 'EUR') return '€';
    return 'R$';
}
function fmtBRL(v) {
    return simboloMoedaJS() + ' ' + v.toFixed(2).replace('.', ',');
}

function onClienteChange(dCli, dCan, canalId, idioma, moeda) {
    desconto = dCli + dCan;
    _canalId = canalId;
    _moeda   = moeda || 'BRL';
    idioma   = idioma || 'pt';
    document.querySelectorAll('.produto-row').forEach(function(row) {
        var key = 'desc' + idioma.charAt(0).toUpperCase() + idioma.slice(1);
        var dc  = (row.dataset[key] || '').trim();
        var el  = row.querySelector('.prod-nome-cell');
        if (el) el.textContent = dc || row.dataset.nome;
    });

    var alertEl  = document.getElementById('alertDesconto');
    var alertTxt = document.getElementById('alertDescontoTexto');
    if (tipoVenda !== 'bonificacao' && document.getElementById('cli_id').value && desconto > 0) {
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
            var dCli   = (parseFloat(o.dataset.desconto)      || 0) / 100;
            var dCan   = (parseFloat(o.dataset.descontoCanal) || 0) / 100;
            var cId    = parseInt(o.dataset.canalId)          || 0;
            var idioma = o.dataset.idioma || 'pt';
            var moeda  = o.dataset.moeda || 'BRL';
            onClienteChange(dCli, dCan, cId, idioma, moeda);
        });
        o.addEventListener('mouseover', function () { o.style.background = '#f0f0f0'; });
        o.addEventListener('mouseout',  function () { o.style.background = ''; });
    });
}());

// Item satisfaz TODOS os campos definidos do filtro composto (E)
function _matchFiltro(it, f) {
    var temFiltro = false;
    if (f.produto)  { temFiltro = true; if (parseInt(it.pid) !== parseInt(f.produto)) return false; }
    if (f.linha)    { temFiltro = true; if ((it.linha || '')    !== f.linha)    return false; }
    if (f.grupo)    { temFiltro = true; if ((it.grupo || '')    !== f.grupo)    return false; }
    if (f.subgrupo) { temFiltro = true; if ((it.subgrupo || '') !== f.subgrupo) return false; }
    return temFiltro;
}
function _alvoFiltro(a) {
    if (a.tipo) { var f = {}; if (a.tipo === 'produto') f.produto = parseInt(a.valor); else f[a.tipo] = a.valor; return f; }
    return a;
}

// Soma qtd ou valor dos itens que batem em QUALQUER filtro (sem contar em dobro)
function _campSomaFiltros(itens, filtros, criterio) {
    var tot = 0;
    itens.forEach(function(it) {
        if (filtros.some(function(f) { return _matchFiltro(it, f); })) tot += criterio === 'valor' ? it.val : it.qtd;
    });
    return tot;
}
// Gatilho de uma campanha do novo modelo — espelha avaliarCampanhaTrigger (PHP).
// Condição com mínimo 0 é só filtro; o mínimo total (se houver) é exigido além do E/OU.
// "Mesmas quantidades" (c.mesma, só no E): todas as condições precisam somar a mesma quantidade.
function _campQtdsIguais(c, itens) {
    if (!c.mesma || !c.conds || c.conds.length < 2) return true;
    var qs = c.conds.map(function(cd) { return _campSomaFiltros(itens, [cd], 'quantidade'); });
    return qs.every(function(q) { return q === qs[0]; });
}
// Campanha vale para o cliente selecionado? "Clientes específicos": só os listados; senão, pelo canal.
function _campValeCliente(c) {
    if (c.clientes) return c.clientes.indexOf(parseInt(document.getElementById('cli_id').value, 10) || 0) !== -1;
    return !c.canal_id || c.canal_id === _canalId;
}
function _campAvaliar(c, itens) {
    var nao = { acionada: false, mult: 0, atingidas: [] };
    if (!c.conds || !c.conds.length) return nao;
    var temMin = false, allMet = true, atingidas = [], minMult = Infinity, maxMult = 0;
    c.conds.forEach(function(cd) {
        var q = 0, v = 0;
        itens.forEach(function(it) { if (_matchFiltro(it, cd)) { q += it.qtd; v += it.val; } });
        var porValor = (cd.modo || 'quantidade') === 'valor';
        var min = porValor ? cd.valorMin : cd.qtd;
        if (!(min > 0)) { atingidas.push(cd); return; }
        temMin = true;
        var m = Math.floor((porValor ? v : q) / min);
        if (m >= 1) { atingidas.push(cd); minMult = Math.min(minMult, m); maxMult = Math.max(maxMult, m); }
        else allMet = false;
    });
    var multTotal = Infinity;
    if (c.total) {
        var soma = _campSomaFiltros(itens, c.conds, c.total.modo);
        if (soma + 0.0001 < c.total.min) return nao;
        multTotal = Math.floor((soma + 0.0001) / c.total.min);
    } else if (!temMin) return nao;
    if (c.ou) {
        if (temMin && maxMult < 1) return nao;
        return { acionada: true, mult: Math.min(temMin ? maxMult : Infinity, multTotal), atingidas: atingidas };
    }
    if (!allMet || !_campQtdsIguais(c, itens)) return nao;
    var mult = Math.min(minMult, multTotal);
    return mult >= 1 ? { acionada: true, mult: mult, atingidas: c.conds } : nao;
}

// Maior faixa atingida (campanha sem faixas = um nível só, com o % padrão). Espelha campanhaFaixaAtingida (PHP).
function _campNivel(c, itens) {
    var niveis = c.niveis || [{ faixa: 1, pct: c.desconto, total: c.total, conds: c.conds }];
    for (var i = niveis.length - 1; i >= 0; i--) {
        var nv = niveis[i];
        var av = _campAvaliar({ ou: c.ou, mesma: c.mesma, total: nv.total, conds: nv.conds }, itens);
        if (av.acionada) return { faixa: nv.faixa, pct: nv.pct, av: av, conds: nv.conds, idx: i };
    }
    return null;
}

function recalcularTodas() {
    // Soma quantidades/valores por categoria/produto + lista de itens
    var totLinha = {}, totGrupo = {}, totSub = {}, totProd = {}, valorTotal = 0, itens = [];
    document.querySelectorAll('.produto-row').forEach(function(row) {
        var actual = parseInt(row.querySelector('.qtd-hidden').value) || 0;
        var pid = parseInt(row.dataset.pid);
        var val = actual * precoBaseRow(row, false);
        var l = row.dataset.linha    || '';
        var g = row.dataset.grupo    || '';
        var s = row.dataset.subgrupo || '';
        if (l) totLinha[l] = (totLinha[l] || 0) + actual;
        if (g) totGrupo[g] = (totGrupo[g] || 0) + actual;
        if (s) totSub[s]   = (totSub[s]   || 0) + actual;
        totProd[pid] = (totProd[pid] || 0) + actual;
        valorTotal += val;
        if (actual > 0) itens.push({ pid: pid, qtd: actual, val: val, linha: l, grupo: g, subgrupo: s });
    });

    // Soma por campanha de produtos (legado: mínimo considera todos os produtos da campanha)
    var totCamp = {};
    Object.keys(_campProdIds).forEach(function(cod) {
        var soma = 0;
        _campProdIds[cod].forEach(function(pid) { soma += (totProd[pid] || 0); });
        totCamp[cod] = soma;
    });

    // Campanhas de desconto do novo modelo: aciona se TODAS (E) ou QUALQUER UMA (OU)
    // das condições — cada uma um filtro composto, mínimo por qtd OU valor — for
    // atingida. No OU, sem alvo explícito, o desconto vai só nas condições atingidas. Espelha o PHP.
    var descAvancados = [];
    _campCondicoes.forEach(function(c) {
        if (!_campValeCliente(c)) return; // filtra por canal (ou pelos clientes específicos)
        var nv = _campNivel(c, itens);
        if (!nv) return;
        var atingidas = nv.av.atingidas;
        // Faixas escalonadas: o % da maior faixa atingida vai para os alvos (ou itens das condições)
        if (c.niveis) {
            if (nv.pct > 0) descAvancados.push({ desconto: nv.pct, alvos: (c.alvos && c.alvos.length ? c.alvos : (c.ou ? atingidas : nv.conds)) });
            return;
        }
        // Alvo com % próprio vira entrada separada; os demais (ou as condições) usam o % padrão
        if (c.alvos && c.alvos.length) {
            c.alvos.forEach(function(a) {
                var pct = (a.desconto !== null && a.desconto !== undefined) ? a.desconto : c.desconto;
                if (pct > 0) descAvancados.push({ desconto: pct, alvos: [a] });
            });
        } else if (c.desconto > 0) {
            descAvancados.push({ desconto: c.desconto, alvos: (c.ou ? atingidas : c.conds) });
        }
    });

    document.querySelectorAll('.produto-row').forEach(function(row) {
        var pid      = parseInt(row.dataset.pid);
        var actual   = parseInt(row.querySelector('.qtd-hidden').value) || 0;
        var linha    = row.dataset.linha    || '';
        var grupo    = row.dataset.grupo    || '';
        var subgrupo = row.dataset.subgrupo || '';
        var ehBonif      = (tipoVenda === 'bonificacao');
        var precoBase    = precoBaseRow(row, ehBonif);
        var descAtual    = ehBonif ? 0 : desconto;
        var precoComDesc = precoBase * (1 - descAtual);

        var campDesc = 0;
        if (!ehBonif) _campanhas.forEach(function(c) {
            if (c.quantidade <= 0) return;
            // Filtra por canal: ignora campanha de canal diferente do cliente
            if (c.canal_id && c.canal_id !== _canalId) return;
            var qtdRef;
            if (c.produto_id !== null) {
                if (c.produto_id !== pid) return;
                qtdRef = (totCamp[c.codigo] !== undefined) ? totCamp[c.codigo] : actual;
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
        // Novo modelo: desconto incide nos alvos (explícitos ou as próprias condições)
        if (!ehBonif) {
            var itemRow = { pid: pid, linha: linha, grupo: grupo, subgrupo: subgrupo };
            descAvancados.forEach(function(ac) {
                if (ac.desconto <= campDesc) return;
                var bate = ac.alvos.some(function(a) { return _matchFiltro(itemRow, _alvoFiltro(a)); });
                if (bate) campDesc = ac.desconto;
            });
        }
        row.dataset.campDesc = campDesc;

        var preco     = campDesc > 0 ? precoComDesc * (1 - campDesc / 100) : precoComDesc;
        var precoCell = row.querySelector('.preco-unit-col');
        if (precoCell) {
            var baseFormatado = precoComDesc > 0 ? fmtBRL(precoComDesc) : (precoBase > 0 ? fmtBRL(precoBase) : '—');
            if (campDesc > 0) {
                precoCell.innerHTML = precoCampanhaHTML(baseFormatado, fmtBRL(preco), campDesc);
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

// Preço com desconto de campanha: original riscado acima, preço final + badge abaixo.
function precoCampanhaHTML(origFmt, finalFmt, desc) {
    return '<div class="text-muted text-decoration-line-through lh-1" style="font-size:.75em">' + origFmt + '</div>'
        + '<div class="lh-sm"><span class="fw-semibold text-success">' + finalFmt + '</span>'
        + ' <span class="badge bg-success ms-1" style="font-size:.7em">-' + desc + '%</span></div>';
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
            var ehBonif      = (tipoVenda === 'bonificacao');
            var precoBase    = precoBaseRow(row, ehBonif);
            var precoComDesc = precoBase * (1 - (ehBonif ? 0 : desconto));
            var campDesc     = parseFloat(row.dataset.campDesc) || 0;
            var preco        = campDesc > 0 ? precoComDesc * (1 - campDesc / 100) : precoComDesc;
            itens.push({
                nome:     row.dataset.nome,
                codigo:   row.dataset.codigo,
                linha:    row.dataset.linha || '',
                preco:    preco,
                precoOrig: precoComDesc,
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
                + ' × ' + simboloMoedaJS() + ' ' + i.preco.toFixed(2).replace('.', ',') + '</div></div>'
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
                + '<td class="text-end">' + (i.campDesc > 0
                    ? precoCampanhaHTML(fmtBRL(i.precoOrig), fmtBRL(i.preco), i.campDesc)
                    : simboloMoedaJS() + ' ' + i.preco.toFixed(2).replace('.', ',')) + '</td>'
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
