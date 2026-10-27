<?php
require_once __DIR__ . '/../../config.php';
requireComercial();

// Busca de clientes (AJAX) para campanhas com aplicação "Clientes específicos"
if (isset($_GET['buscar_cliente'])) {
    $q = trim((string)$_GET['buscar_cliente']);
    $out = [];
    if (mb_strlen($q) >= 2) {
        $like = '%' . $q . '%';
        $st = db()->prepare('SELECT id, codigo_cliente, razao_social, cidade, estado FROM clientes
            WHERE razao_social LIKE ? OR codigo_cliente LIKE ? OR cnpj LIKE ? OR cpf LIKE ?
            ORDER BY (status = "ativo") DESC, razao_social LIMIT 20');
        $st->execute([$like, $like, $like, $like]);
        foreach ($st->fetchAll() as $r) {
            $out[] = ['id' => (int)$r['id'], 'codigo' => (string)$r['codigo_cliente'], 'nome' => (string)$r['razao_social'],
                      'local' => trim(($r['cidade'] ?? '') . (($r['estado'] ?? '') !== '' ? '/' . $r['estado'] : ''))];
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($out, JSON_UNESCAPED_UNICODE); exit;
}

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
            // Aplicação: no canal de venda (ou todos) ou só para clientes específicos
            $aplicacao = ($_POST['aplicacao'] ?? 'canal') === 'clientes' ? 'clientes' : 'canal';
            $cliClean  = [];
            if ($aplicacao === 'clientes') {
                $canal = null;   // o canal não se aplica: valem só os clientes listados
                foreach (($_POST['clientes'] ?? []) as $cid) if ((int)$cid > 0) $cliClean[(int)$cid] = true;
                $cliClean = array_keys($cliClean);
                if (!$cliClean) throw new Exception('Adicione ao menos um cliente à campanha (ou aplique no canal de venda).');
            }
            $ativo = ($_POST['ativo'] ?? '1') === '0' ? 0 : 1;
            $desc  = $tipo === 'bonificacao' ? 0.0 : (float)str_replace(',', '.', $_POST['desconto'] ?? '0');
            // Período de vigência (opcional em cada lado)
            $dtIni = trim($_POST['data_inicio'] ?? '');
            $dtFim = trim($_POST['data_fim'] ?? '');
            foreach ([&$dtIni, &$dtFim] as &$dt) {
                if ($dt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) throw new Exception('Data do período inválida.');
                if ($dt === '') $dt = null;
            }
            unset($dt);
            if ($dtIni && $dtFim && $dtFim < $dtIni) throw new Exception('A data final do período deve ser igual ou posterior à data inicial.');

            // Condições combinadas (E ou OU, conforme o operador): cada condição é um filtro
            // composto (linha E grupo E subgrupo E produto) + mínimo por quantidade OU valor.
            $condOp = ($_POST['cond_operador'] ?? 'E') === 'OU' ? 'OU' : 'E';
            $obsCamp = trim($_POST['observacao'] ?? '');
            $obsAm   = mb_substr(trim($_POST['obs_am'] ?? ''), 0, 255);
            $condClean = [];
            foreach (($_POST['cond'] ?? []) as $c) {
                $fl = trim($c['linha']    ?? '');
                $fg = trim($c['grupo']    ?? '');
                $fs = trim($c['subgrupo'] ?? '');
                $fp = (int)($c['produto']  ?? 0);
                if ($fl === '' && $fg === '' && $fs === '' && $fp <= 0) continue; // sem nenhum filtro
                $modo = ($c['modo'] ?? 'quantidade') === 'valor' ? 'valor' : 'quantidade';
                $min  = max(0, (float)str_replace(',', '.', $c['min'] ?? '0'));   // 0 = sem mínimo próprio (só filtro)
                $fxMin = [];   // mínimos desta condição nas faixas 2..N (desconto por faixas)
                foreach (($c['fx'] ?? []) as $k => $v) $fxMin[(int)$k] = max(0, (float)str_replace(',', '.', $v));
                if ($modo === 'valor') $condClean[] = ['valor', 0, $min, $fl, $fg, $fs, $fp, $fxMin];
                else                   $condClean[] = ['quantidade', (int)$min, null, $fl, $fg, $fs, $fp, $fxMin];
            }
            if (!$condClean) throw new Exception('Adicione ao menos uma condição (com categoria/produto).');

            // Mesmas quantidades: no pedido final, todas as condições precisam somar a MESMA quantidade
            // (os mínimos continuam valendo). Só faz sentido com E e condições por quantidade.
            $mesmaQtd = $tipo === 'desconto' && ($_POST['cond_mesma_qtd'] ?? '0') === '1' ? 1 : 0;
            if ($mesmaQtd) {
                if (count($condClean) < 2) throw new Exception('"Mesmas quantidades" exige ao menos duas condições.');
                if ($condOp !== 'E') throw new Exception('"Mesmas quantidades" só vale com "Todas precisam ser atendidas (E)".');
                if (array_filter($condClean, fn($c) => $c[0] !== 'quantidade')) throw new Exception('"Mesmas quantidades" exige todas as condições com mínimo por Quantidade.');
            }

            // Mínimo TOTAL das condições (opcional): soma dos itens de todas as condições
            $totModo = ($_POST['cond_total_modo'] ?? '') === 'valor' ? 'valor' : (($_POST['cond_total_modo'] ?? '') === 'quantidade' ? 'quantidade' : null);
            $totMin  = (float)str_replace(',', '.', $_POST['cond_total_min'] ?? '0');
            if (!$totModo || $totMin <= 0) { $totModo = null; $totMin = null; }
            $semMinProprio = array_filter($condClean, fn($c) => ($c[0] === 'valor' ? $c[2] : $c[1]) <= 0);
            if ($semMinProprio && !$totModo) throw new Exception('Informe o mínimo de cada condição ou um mínimo total das condições.');

            // Onde o desconto incide: nos itens das condições (sem alvos) ou em outros produtos
            // (alvos explícitos: filtro composto linha E grupo E subgrupo E produto).
            $descItensCond = ($_POST['desc_itens_cond'] ?? '1') !== '0';
            $alvoClean = [];
            if ($tipo === 'desconto' && !$descItensCond) {
                foreach (($_POST['alvo'] ?? []) as $al) {
                    $fl = trim($al['linha']    ?? '');
                    $fg = trim($al['grupo']    ?? '');
                    $fs = trim($al['subgrupo'] ?? '');
                    $fp = (int)($al['produto']  ?? 0);
                    if ($fl === '' && $fg === '' && $fs === '' && $fp <= 0) continue;
                    $ad = trim(str_replace(',', '.', $al['desconto'] ?? ''));
                    $ad = $ad === '' ? null : (float)$ad;   // vazio = usa o % padrão da campanha
                    if ($ad !== null && $ad <= 0) $ad = null;
                    $alvoClean[] = [$fl, $fg, $fs, $fp, $ad];
                }
                if (!$alvoClean) throw new Exception('Escolha ao menos um produto a conceder o desconto (ou marque "Sim, nos itens das condições").');
            }

            // Forma do desconto: fixo (% padrão / % por alvo) ou faixas escalonadas das condições.
            // Faixa 1 = mínimos das condições + mínimo total + % padrão; faixas 2..N = mínimos/total/% mais altos.
            $descModo = ($tipo === 'desconto' && ($_POST['desconto_modo'] ?? 'fixo') === 'faixas') ? 'faixas' : 'fixo';
            $faixasClean = [];
            if ($descModo === 'faixas') {
                if ($desc <= 0) throw new Exception('Faixa 1: informe o percentual de desconto.');
                foreach ($alvoClean as &$al) $al[4] = null;   // nas faixas o % vem da faixa (não por alvo)
                unset($al);
                $pctAnt = $desc;
                foreach (($_POST['fx_pct'] ?? []) as $k => $pc) {
                    $k = (int)$k; if ($k < 2) continue;
                    $pc = (float)str_replace(',', '.', $pc);
                    if ($pc <= 0) throw new Exception("Faixa $k: informe o percentual de desconto.");
                    $mins = [];
                    foreach ($condClean as $ci => $c) {
                        $min1 = $c[0] === 'valor' ? $c[2] : $c[1];
                        $mk   = $c[7][$k] ?? 0;
                        if ($min1 > 0 && $mk <= 0) throw new Exception("Faixa $k: informe o mínimo de todas as condições.");
                        $mins[$ci] = $mk;
                    }
                    $tk = null;
                    if ($totModo) {
                        $tk = (float)str_replace(',', '.', $_POST['fx_total'][$k] ?? '0');
                        if ($tk <= 0) throw new Exception("Faixa $k: informe o mínimo total das condições.");
                    }
                    if ($pc <= $pctAnt) throw new Exception("Faixa $k: o percentual deve ser maior que o da faixa anterior.");
                    $pctAnt = $pc;
                    $faixasClean[$k] = ['pct' => $pc, 'total' => $tk, 'mins' => $mins];
                }
                if (!$faixasClean) throw new Exception('Adicione ao menos a Faixa 2 (ou use "Percentual fixo").');
                ksort($faixasClean);
            } elseif ($tipo === 'desconto') {
                // % padrão é obrigatório, a menos que todos os alvos tenham % próprio
                $semPct = !$alvoClean || array_filter($alvoClean, fn($al) => $al[4] === null);
                if ($desc <= 0 && $semPct) throw new Exception('Informe o percentual de desconto padrão (ou um % em cada produto a conceder o desconto).');
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
                try { db()->prepare('DELETE FROM campanha_faixas WHERE codigo_campanha=?')->execute([$delCod]); } catch (PDOException $e) {}
                try { db()->prepare('DELETE FROM campanha_faixa_minimos WHERE codigo_campanha=?')->execute([$delCod]); } catch (PDOException $e) {}
                try { db()->prepare('DELETE FROM campanha_clientes WHERE codigo_campanha=?')->execute([$delCod]); } catch (PDOException $e) {}
            }

            // Linha cabeçalho — parâmetros da campanha (gatilho fica nas condições)
            db()->prepare('INSERT INTO campanhas
                (codigo_campanha,produto_id,linha,grupo,subgrupo,canal_venda_id,quantidade,desconto,tipo,valor_alvo,bonif_modo,bonif_limite_tipo,bonif_limite_valor,ativo,bonif_selec_modo,cond_operador,observacao,cond_total_modo,cond_total_min,desconto_modo,obs_am,data_inicio,data_fim,cond_mesma_qtd,aplicacao)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$cod, null, null, null, null, $canal, 0, $desc, $tipo, null, $bonifModo, $bonifLimTipo, $bonifLimValor, $ativo, $bonifSelecModo, $condOp, $obsCamp !== '' ? $obsCamp : null, $totModo, $totMin, $descModo, $obsAm !== '' ? $obsAm : null, $dtIni, $dtFim, $mesmaQtd, $aplicacao]);

            // Clientes específicos
            if ($cliClean) {
                $insCli = db()->prepare('INSERT INTO campanha_clientes (codigo_campanha, cliente_id) VALUES (?,?)');
                foreach ($cliClean as $cid) $insCli->execute([$cod, $cid]);
            }

            // Condições (E/OU) — filtro composto por condição
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
                    (codigo_campanha,alvo_tipo,alvo_valor,alvo_linha,alvo_grupo,alvo_subgrupo,alvo_produto_id,desconto)
                    VALUES (?,?,?,?,?,?,?,?)');
                foreach ($alvoClean as $al) $insA->execute([$cod, 'composto', '', $al[0] ?: null, $al[1] ?: null, $al[2] ?: null, $al[3] ?: null, $al[4]]);
            }
            // Faixas escalonadas das condições (faixas 2..N; a faixa 1 é a própria campanha)
            if ($faixasClean) {
                $insF = db()->prepare('INSERT INTO campanha_faixas (codigo_campanha,faixa,percentual,total_min) VALUES (?,?,?,?)');
                $insM = db()->prepare('INSERT INTO campanha_faixa_minimos (codigo_campanha,faixa,cond_idx,minimo) VALUES (?,?,?,?)');
                foreach ($faixasClean as $k => $fx) {
                    $insF->execute([$cod, $k, $fx['pct'], $fx['total']]);
                    foreach ($fx['mins'] as $ci => $mn) $insM->execute([$cod, $k, $ci, $mn]);
                }
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
            'cond_operador'      => ($r['cond_operador'] ?? 'E') === 'OU' ? 'OU' : 'E',
            'observacao'         => (string)($r['observacao'] ?? ''),
            'obs_am'             => (string)($r['obs_am'] ?? ''),
            'cond_total_modo'    => $r['cond_total_modo'] ?? null,
            'cond_total_min'     => isset($r['cond_total_min']) ? (float)$r['cond_total_min'] : null,
            'desconto_modo'      => ($r['desconto_modo'] ?? 'fixo') === 'faixas' ? 'faixas' : 'fixo',
            'data_inicio'        => $r['data_inicio'] ?? null,
            'data_fim'           => $r['data_fim'] ?? null,
            'cond_mesma_qtd'     => (int)($r['cond_mesma_qtd'] ?? 0),
            'aplicacao'          => ($r['aplicacao'] ?? 'canal') === 'clientes' ? 'clientes' : 'canal',
            'clientes'           => [],
            'faixas'             => [],
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

// Clientes específicos por campanha (aplicação "clientes")
try {
    foreach (db()->query('SELECT cc.codigo_campanha, cl.id, cl.codigo_cliente, cl.razao_social
        FROM campanha_clientes cc JOIN clientes cl ON cl.id = cc.cliente_id ORDER BY cc.id')->fetchAll() as $cl) {
        if (isset($campanhas[$cl['codigo_campanha']])) {
            $campanhas[$cl['codigo_campanha']]['clientes'][] = ['id' => (int)$cl['id'], 'codigo' => (string)$cl['codigo_cliente'], 'nome' => (string)$cl['razao_social']];
        }
    }
} catch (PDOException $e) { /* tabela ainda não existe */ }

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

// Condições combinadas (E/OU) por campanha — cada uma é um filtro composto
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
    $alvoRows = db()->query('SELECT codigo_campanha, alvo_tipo, alvo_valor, alvo_linha, alvo_grupo, alvo_subgrupo, alvo_produto_id, desconto
        FROM campanha_desconto_alvo ORDER BY id')->fetchAll();
} catch (PDOException $e) {
    try { $alvoRows = db()->query('SELECT codigo_campanha, alvo_tipo, alvo_valor FROM campanha_desconto_alvo ORDER BY id')->fetchAll(); }
    catch (PDOException $e2) { $alvoRows = []; }
}
foreach ($alvoRows as $a) {
    if (isset($campanhas[$a['codigo_campanha']])) $campanhas[$a['codigo_campanha']]['desconto_alvos'][] = alvoFiltro($a);
}

// Faixas 2..N do desconto escalonado (a faixa 1 é a própria campanha)
foreach (campanhasFaixas() as $code => $fxs) {
    if (!isset($campanhas[$code])) continue;
    foreach ($fxs as $k => $fx) $campanhas[$code]['faixas'][] = ['faixa' => $k, 'pct' => $fx['pct'], 'total_min' => $fx['total_min'], 'mins' => (object)$fx['mins']];
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

// Lista compacta para a tabela: mostra os $max primeiros itens e esconde o resto atrás de "+N mais".
function listaCompacta(array $itens, string $sep = ', ', int $max = 3): string {
    $itens = array_values($itens);
    if (count($itens) <= $max) return e(implode($sep, $itens));
    $vis  = e(implode($sep, array_slice($itens, 0, $max)));
    $resto = e($sep . implode($sep, array_slice($itens, $max)));
    return $vis . '<span class="lc-resto d-none">' . $resto . '</span> '
         . '<a href="#" class="lc-toggle text-nowrap" data-mais="+' . (count($itens) - $max) . ' mais" onclick="return lcAlternar(this)">+'
         . (count($itens) - $max) . ' mais</a>';
}

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
    $minCd = ($cd['modo'] ?? 'quantidade') === 'valor' ? (float)($cd['valor_min'] ?? 0) : (int)($cd['qtd'] ?? 0);
    if (array_key_exists('modo', $cd) && $minCd > 0) {
        $out .= ($cd['modo'] ?? 'quantidade') === 'valor'
            ? ' ≥ ' . moedaBR($cd['valor_min'] ?? 0)
            : ' ≥ ' . (int)($cd['qtd'] ?? 0) . ' un.';
    }
    return $out;
}

// Campanha fora do período de vigência (ainda não começou ou já terminou)
function campanhaForaPeriodo(array $c): bool {
    $hoje = date('Y-m-d');
    return ($c['data_inicio'] && $c['data_inicio'] > $hoje) || ($c['data_fim'] && $c['data_fim'] < $hoje);
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
                <tr><th>Código</th><th>Status</th><th>Condições</th><th>Canal / Clientes</th><th>Tipo / Recompensa</th><th>Ações</th></tr>
            </thead>
            <tbody>
            <?php if ($campanhas): foreach ($campanhas as $c):
                $criterios = array_map(fn($cd) => critLabel($cd, $prodById), $c['condicoes']);
                $ehFaixas  = $c['tipo'] === 'desconto' && $c['desconto_modo'] === 'faixas';
                $fmtPct    = fn($v) => rtrim(rtrim(number_format((float)$v, 2, ',', '.'), '0'), ',') . '%';
                // Linhas das faixas: "F1: Grupo X ≥ 100 un. E Grupo Y ≥ 10 un. → 5%"
                $linhasFaixa = [];
                if ($ehFaixas) {
                    $fmtTot = fn($v) => $c['cond_total_modo'] === 'valor' ? moedaBR($v) : (int)$v . ' un.';
                    $linhasFaixa[] = ['F1', implode(' ' . $c['cond_operador'] . ' ', $criterios)
                        . ($c['cond_total_modo'] && $c['cond_total_min'] > 0 ? ' · Σ ≥ ' . $fmtTot($c['cond_total_min']) : ''), $fmtPct($c['desconto'])];
                    foreach ($c['faixas'] as $fx) {
                        $mins = (array)$fx['mins'];
                        $partes = [];
                        foreach ($c['condicoes'] as $ci => $cd) {
                            $cd2 = $cd;
                            if (($cd['modo'] ?? 'quantidade') === 'valor') $cd2['valor_min'] = $mins[$ci] ?? 0; else $cd2['qtd'] = (int)($mins[$ci] ?? 0);
                            $partes[] = critLabel($cd2, $prodById);
                        }
                        $linhasFaixa[] = ['F' . $fx['faixa'], implode(' ' . $c['cond_operador'] . ' ', $partes)
                            . ($fx['total_min'] ? ' · Σ ≥ ' . $fmtTot($fx['total_min']) : ''), $fmtPct($fx['pct'])];
                    }
                }
                $alvoTemPctProprio = !$ehFaixas && array_filter($c['desconto_alvos'], fn($a) => $a['desconto'] !== null);
                $alvos     = array_map(fn($cd) => critLabel($cd, $prodById)
                    . ($alvoTemPctProprio ? ' (' . $fmtPct($cd['desconto'] ?? (float)$c['desconto']) . ')' : ''), $c['desconto_alvos']);
                // alvos = exatamente os filtros das condições (e sem % próprio) → resumo curto
                $chaveF = fn($f) => implode('|', [$f['linha'] ?? '', $f['grupo'] ?? '', $f['subgrupo'] ?? '', (int)($f['produto'] ?? 0)]);
                $fAlvos = array_map($chaveF, $c['desconto_alvos']); sort($fAlvos);
                $fConds = array_map($chaveF, $c['condicoes']);      sort($fConds);
                $alvosIguaisCond = $alvos && !$alvoTemPctProprio && $fAlvos === array_values(array_unique($fConds));
            ?>
                <tr class="<?= $c['ativo'] && !campanhaForaPeriodo($c) ? '' : 'opacity-50' ?>">
                    <td><strong><?= e($c['codigo_campanha']) ?></strong></td>
                    <td>
                        <?php
                        $hoje = date('Y-m-d');
                        $foraPeriodo = ($c['data_inicio'] && $c['data_inicio'] > $hoje) ? 'Agendada' : (($c['data_fim'] && $c['data_fim'] < $hoje) ? 'Encerrada' : '');
                        ?>
                        <?php if (!$c['ativo']): ?>
                            <span class="badge bg-secondary">Inativa</span>
                        <?php elseif ($foraPeriodo === 'Agendada'): ?>
                            <span class="badge bg-info text-dark">Agendada</span>
                        <?php elseif ($foraPeriodo === 'Encerrada'): ?>
                            <span class="badge bg-secondary">Encerrada</span>
                        <?php else: ?>
                            <span class="badge bg-success">Ativa</span>
                        <?php endif; ?>
                        <?php if ($c['data_inicio'] || $c['data_fim']): ?>
                            <div class="small text-muted text-nowrap mt-1"><i class="bi bi-calendar-range me-1"></i><?= $c['data_inicio'] ? date('d/m/Y', strtotime($c['data_inicio'])) : '…' ?> a <?= $c['data_fim'] ? date('d/m/Y', strtotime($c['data_fim'])) : '…' ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if ($ehFaixas): ?>
                            <span class="badge bg-info text-dark me-1"><?= $c['cond_operador'] === 'OU' ? 'Qualquer uma (OU)' : 'Todas (E)' ?></span>
                            <?php foreach ($linhasFaixa as [$fxNome, $fxTxt, $fxPct]): ?>
                                <div><strong><?= e($fxNome) ?>:</strong> <?= e($fxTxt) ?> <span class="badge bg-success"><?= e($fxPct) ?></span></div>
                            <?php endforeach; ?>
                            <?php if ($c['cond_mesma_qtd']): ?>
                                <div class="fw-semibold mt-1"><i class="bi bi-distribute-horizontal me-1"></i>Quantidades iguais entre as condições</div>
                            <?php endif; ?>
                            <?php if ($c['observacao'] !== ''): ?>
                                <div class="text-primary mt-1" title="Observação — exibida no pedido no lugar da condição"><i class="bi bi-chat-left-text me-1"></i><?= e($c['observacao']) ?></div>
                            <?php endif; ?>
                        <?php elseif ($criterios): ?>
                            <?php if (count($criterios) > 1): ?>
                                <span class="badge <?= $c['cond_operador'] === 'OU' ? 'bg-warning text-dark' : 'bg-info text-dark' ?> me-1"><?= $c['cond_operador'] === 'OU' ? 'Qualquer uma (OU)' : 'Todas (E)' ?></span>
                            <?php endif; ?>
                            <?php if (count($criterios) > 3): ?><span class="text-muted">(<?= count($criterios) ?>)</span><?php endif; ?>
                            <?= listaCompacta($criterios, ' ' . $c['cond_operador'] . ' ') ?>
                            <?php if ($c['cond_mesma_qtd']): ?>
                                <div class="fw-semibold mt-1"><i class="bi bi-distribute-horizontal me-1"></i>Quantidades iguais entre as condições</div>
                            <?php endif; ?>
                            <?php if ($c['cond_total_modo'] && $c['cond_total_min'] > 0): ?>
                                <div class="fw-semibold mt-1"><i class="bi bi-sigma me-1"></i>Total das condições ≥ <?= $c['cond_total_modo'] === 'valor' ? e(moedaBR($c['cond_total_min'])) : (int)$c['cond_total_min'] . ' un.' ?></div>
                            <?php endif; ?>
                            <?php if ($c['observacao'] !== ''): ?>
                                <div class="text-primary mt-1" title="Observação — exibida no pedido no lugar da condição"><i class="bi bi-chat-left-text me-1"></i><?= e($c['observacao']) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($c['aplicacao'] === 'clientes'): ?>
                            <span class="badge bg-primary"><i class="bi bi-person-check me-1"></i><?= count($c['clientes']) ?> cliente(s)</span>
                            <div class="small text-muted" style="max-width:220px"><?= $c['clientes'] ? listaCompacta(array_map(fn($cl) => $cl['nome'] ?: $cl['codigo'], $c['clientes']), ', ', 2) : '<span class="text-danger">nenhum</span>' ?></div>
                        <?php else: ?>
                            <?= $c['canal'] ? '<span class="badge bg-secondary">'.e($c['canal']).'</span>' : '<span class="text-muted small">Todos</span>' ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($c['tipo'] === 'bonificacao'): ?>
                            <?php if ($c['bonif_modo'] === 'selecionavel'): ?>
                                <span class="badge bg-info text-dark mb-1"><i class="bi bi-hand-index me-1"></i>Bônus selecionável</span>
                                <div class="small text-muted">Limite: <?= $c['bonif_limite_tipo'] === 'valor' ? moedaBR($c['bonif_limite_valor']) : ((int)$c['bonif_limite_valor'] . ' un.') ?></div>
                                <div class="small text-muted" style="max-width:300px">
                                    <?php if ($c['bonif_selec_modo'] === 'categoria'): ?>
                                        Pool: <?= $c['bonif_pool'] ? listaCompacta(array_map(fn($p) => critLabel($p, $prodById), $c['bonif_pool'])) : '<span class="text-danger">vazio</span>' ?>
                                    <?php else: ?>
                                        <?= $c['bonificados'] ? listaCompacta(array_map(fn($b) => $b['descricao_pt'] ?: $b['codigo_produto'], $c['bonificados'])) : '<span class="text-danger">sem produtos</span>' ?>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark mb-1"><i class="bi bi-gift me-1"></i>Bonificação fixa</span>
                                <div class="small text-muted" style="max-width:300px">
                                    <?= $c['bonificados'] ? listaCompacta(array_map(fn($b) => $b['quantidade'] . 'x ' . ($b['descricao_pt'] ?: $b['codigo_produto']), $c['bonificados'])) : '<span class="text-danger">sem produtos</span>' ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php if ($ehFaixas): ?>
                                <span class="badge bg-success">Faixas <?= e($fmtPct($c['desconto'])) ?> → <?= e($fmtPct(max(array_merge([(float)$c['desconto']], array_column($c['faixas'], 'pct'))))) ?></span>
                                <div class="small text-muted" style="max-width:300px">
                                    <?php if ($alvosIguaisCond): ?>Aplica nos mesmos produtos das condições
                                    <?php elseif ($alvos): ?>Aplica em: <?= listaCompacta($alvos) ?>
                                    <?php else: ?><span class="text-muted">Aplica nos itens das condições</span><?php endif; ?>
                                </div>
                            <?php else: ?>
                            <?php if ((float)$c['desconto'] > 0): ?><span class="badge bg-success"><?= e($fmtPct($c['desconto'])) ?></span><?php endif; ?>
                            <div class="small text-muted" style="max-width:300px">
                                <?php if ($alvosIguaisCond): ?>Aplica nos mesmos produtos das condições
                                <?php elseif ($alvos): ?>Aplica em: <?= listaCompacta($alvos) ?>
                                <?php else: ?><span class="text-muted">Aplica nos itens das condições</span><?php endif; ?>
                            </div>
                            <?php endif; ?>
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
#bonifWrapTable, #condTableWrap, #alvoTableWrap, #poolTableWrap, #cliTableWrap { max-height: 240px; overflow-y: auto; }
#campAlvoWrap.sem-pct .alvo-pct-col { display: none; }
/* modal-dialog-scrollable só rola se o .modal-body for filho direto do .modal-content;
   aqui há um <form> no meio — ele vira a coluna flexível para o corpo rolar (zoom alto/telas baixas). */
#modal .modal-dialog-scrollable .modal-content > form { display: flex; flex-direction: column; min-height: 0; max-height: 100%; }
#modal .modal-dialog-scrollable .modal-body { overflow-y: auto; min-height: 0; }
#condTableWrap .fx-col { width: 110px; background: var(--bs-success-bg-subtle); }

/* ---- Assistente (passo a passo) ---- */
.wz-steps { display: flex; gap: .35rem; list-style: none; padding: 0; margin: 0; }
.wz-steps li { flex: 1; min-width: 0; }
.wz-steps button { width: 100%; border: 0; background: none; padding: .4rem .2rem; font-size: .8rem; color: var(--bs-secondary-color);
                   border-bottom: 3px solid var(--bs-border-color); text-align: left; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.wz-steps button:disabled { cursor: default; opacity: .7; }
.wz-steps .wz-n { display: inline-flex; width: 1.45rem; height: 1.45rem; border-radius: 50%; align-items: center; justify-content: center;
                  font-weight: 600; font-size: .75rem; margin-right: .3rem; background: var(--bs-secondary-bg); color: var(--bs-body-color); }
.wz-steps li.feito button { color: var(--bs-success); border-color: var(--bs-success); }
.wz-steps li.feito .wz-n { background: var(--bs-success); color: #fff; }
.wz-steps li.atual button { color: var(--bs-primary); border-color: var(--bs-primary); font-weight: 600; }
.wz-steps li.atual .wz-n { background: var(--bs-primary); color: #fff; }
.wz-bloco { margin-bottom: 1.6rem; }
.wz-q { font-weight: 600; font-size: 1.05rem; margin-bottom: .2rem; }
.wz-ajuda { color: var(--bs-secondary-color); font-size: .85rem; margin-bottom: .65rem; }
.wz-opts { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: .75rem; max-width: 780px; }
.wz-opt { display: flex; gap: .75rem; align-items: flex-start; text-align: left; width: 100%; margin: 0; cursor: pointer;
          border: 2px solid var(--bs-border-color); border-radius: .75rem; background: var(--bs-body-bg); color: var(--bs-body-color); padding: .75rem 1rem; }
.wz-opt:hover { border-color: var(--bs-primary-border-subtle); }
.wz-opt.active, .wz-opt:has(input:checked) { border-color: var(--bs-primary); background: var(--bs-primary-bg-subtle); }
.wz-opt > i { font-size: 1.5rem; color: var(--bs-primary); line-height: 1; }
.wz-opt-tit { display: block; font-weight: 600; }
.wz-opt-sub { display: block; font-size: .8rem; color: var(--bs-secondary-color); }
.wz-mais > summary { cursor: pointer; color: var(--bs-primary); font-size: .9rem; }
#wzResumo th { width: 180px; color: var(--bs-secondary-color); font-weight: 600; }
</style>
<div class="modal fade" id="modal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" onsubmit="return campSubmit(event)">
                <input type="hidden" name="action" id="fa" value="criar">
                <input type="hidden" name="codigo_original" id="f_cod_orig">
                <div class="modal-header flex-wrap">
                    <h5 class="modal-title fw-bold" id="mt">Nova Campanha</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    <ol class="wz-steps w-100 mt-3" id="wzSteps">
                        <li><button type="button" data-go="1"><span class="wz-n">1</span><span class="d-none d-md-inline">Identificação</span></button></li>
                        <li><button type="button" data-go="2"><span class="wz-n">2</span><span class="d-none d-md-inline">Para quem</span></button></li>
                        <li><button type="button" data-go="3"><span class="wz-n">3</span><span class="d-none d-md-inline">Benefício</span></button></li>
                        <li><button type="button" data-go="4"><span class="wz-n">4</span><span class="d-none d-md-inline">O que comprar</span></button></li>
                        <li><button type="button" data-go="5"><span class="wz-n">5</span><span class="d-none d-md-inline">Detalhes</span></button></li>
                        <li><button type="button" data-go="6"><span class="wz-n">6</span><span class="d-none d-md-inline">Revisão</span></button></li>
                    </ol>
                </div>
                <div class="modal-body">

                    <!-- ===== Passo 1 — Identificação ===== -->
                    <div class="wz-page" data-page="1">
                        <div class="wz-bloco">
                            <div class="wz-q">Qual o código da campanha? <span class="text-danger">*</span></div>
                            <div class="wz-ajuda">Um nome curto para identificar a campanha. Ex.: <em>COLOR-NOV</em></div>
                            <input type="text" name="codigo_campanha" id="f_cod" class="form-control" style="max-width:360px" required>
                        </div>
                        <div class="wz-bloco">
                            <div class="wz-q">Em qual período ela vale?</div>
                            <div class="wz-ajuda">Deixe em branco para não ter data de início ou de fim.</div>
                            <div class="row g-2" style="max-width:480px">
                                <div class="col-6">
                                    <label class="form-label small mb-1" for="f_dt_ini">Início</label>
                                    <input type="date" name="data_inicio" id="f_dt_ini" class="form-control">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1" for="f_dt_fim">Fim</label>
                                    <input type="date" name="data_fim" id="f_dt_fim" class="form-control">
                                </div>
                            </div>
                        </div>
                        <div class="wz-bloco">
                            <div class="wz-q">A campanha já fica ativa?</div>
                            <div class="wz-ajuda">Inativa = fica salva, mas não vale para nenhum pedido.</div>
                            <select name="ativo" id="f_ativo" class="form-select" style="max-width:220px">
                                <option value="1">Sim, ativa</option>
                                <option value="0">Não, inativa</option>
                            </select>
                        </div>
                    </div>

                    <!-- ===== Passo 2 — Para quem ===== -->
                    <div class="wz-page d-none" data-page="2">
                        <div class="wz-bloco">
                            <div class="wz-q">Para quem é a campanha?</div>
                            <input type="hidden" name="aplicacao" id="f_aplicacao" value="canal" onchange="campStep()">
                            <div class="wz-opts mt-2" data-for="f_aplicacao">
                                <button type="button" class="wz-opt" data-value="canal">
                                    <i class="bi bi-diagram-3"></i>
                                    <span><span class="wz-opt-tit">Clientes de um canal de venda</span>
                                    <span class="wz-opt-sub">Vale para todos os clientes do canal (ou de todos os canais).</span></span>
                                </button>
                                <button type="button" class="wz-opt" data-value="clientes">
                                    <i class="bi bi-person-check"></i>
                                    <span><span class="wz-opt-tit">Clientes específicos</span>
                                    <span class="wz-opt-sub">Você escolhe os clientes, um a um.</span></span>
                                </button>
                            </div>
                        </div>
                        <div class="wz-bloco camp-step" data-step="canal">
                            <div class="wz-q">Qual canal de venda?</div>
                            <select name="canal_venda_id" id="f_canal" class="form-select mt-2" style="max-width:360px">
                                <option value="">— Todos os canais —</option>
                                <?php foreach ($canais as $cv): ?>
                                <option value="<?= $cv['id'] ?>"><?= e($cv['canal']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="wz-bloco camp-step d-none" data-step="clientes">
                            <div class="wz-q">Quais clientes?</div>
                            <div class="wz-ajuda">Busque e clique no cliente para adicioná-lo. A campanha vale <strong>só</strong> para os clientes da lista.</div>
                            <div class="position-relative" style="max-width:560px">
                                <input type="text" id="cliSearch" class="form-control" placeholder="Buscar cliente por nome, código ou CNPJ/CPF..." autocomplete="off">
                                <div id="cliDropdown" class="list-group position-absolute w-100 shadow-sm" style="display:none;z-index:1056;top:100%;left:0;max-height:260px;overflow-y:auto"></div>
                            </div>
                            <div class="table-responsive mt-2 d-none" id="cliTableWrap" style="max-width:760px">
                                <table class="table table-sm table-bordered align-middle mb-0">
                                    <thead class="table-light"><tr><th style="width:130px">Código</th><th>Cliente</th><th style="width:50px" class="text-center">—</th></tr></thead>
                                    <tbody id="cliBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- ===== Passo 3 — Benefício ===== -->
                    <div class="wz-page d-none" data-page="3">
                        <div class="wz-bloco">
                            <div class="wz-q">O que o cliente ganha?</div>
                            <input type="hidden" name="tipo" id="f_tipo" value="desconto" onchange="campSetTipo(this.value)">
                            <div class="wz-opts mt-2" data-for="f_tipo">
                                <button type="button" class="wz-opt" data-value="desconto">
                                    <i class="bi bi-percent"></i>
                                    <span><span class="wz-opt-tit">Desconto</span>
                                    <span class="wz-opt-sub">Um percentual de desconto nos produtos.</span></span>
                                </button>
                                <button type="button" class="wz-opt" data-value="bonificacao">
                                    <i class="bi bi-gift"></i>
                                    <span><span class="wz-opt-tit">Produtos bonificados</span>
                                    <span class="wz-opt-sub">Brindes que vão junto com o pedido.</span></span>
                                </button>
                            </div>
                        </div>
                        <div class="wz-bloco" id="dmWrap">
                            <div class="wz-q">Como é o desconto?</div>
                            <div class="wz-opts mt-2">
                                <label class="wz-opt" for="f_dm_fixo">
                                    <input class="d-none" type="radio" name="desconto_modo" id="f_dm_fixo" value="fixo" checked onchange="fxRender()">
                                    <i class="bi bi-dash-circle"></i>
                                    <span><span class="wz-opt-tit">Percentual fixo</span>
                                    <span class="wz-opt-sub">Atingiu a condição, ganha sempre o mesmo %.</span></span>
                                </label>
                                <label class="wz-opt" for="f_dm_faixas">
                                    <input class="d-none" type="radio" name="desconto_modo" id="f_dm_faixas" value="faixas" onchange="fxRender()">
                                    <i class="bi bi-bar-chart-steps"></i>
                                    <span><span class="wz-opt-tit">Por faixas</span>
                                    <span class="wz-opt-sub">Quanto mais compra, maior o %. Ex.: 10 un. = 5%, 20 un. = 8%.</span></span>
                                </label>
                            </div>
                        </div>
                        <div class="wz-bloco camp-step d-none" data-step="bmodo">
                            <div class="wz-q">Como é o brinde?</div>
                            <input type="hidden" name="bonif_modo" id="f_bonif_modo" value="fixo" onchange="campSetBonifModo()">
                            <div class="wz-opts mt-2" data-for="f_bonif_modo">
                                <button type="button" class="wz-opt" data-value="fixo">
                                    <i class="bi bi-box-seam"></i>
                                    <span><span class="wz-opt-tit">Produtos definidos</span>
                                    <span class="wz-opt-sub">Você escolhe os brindes; a quantidade multiplica conforme o cliente compra mais.</span></span>
                                </button>
                                <button type="button" class="wz-opt" data-value="selecionavel">
                                    <i class="bi bi-hand-index"></i>
                                    <span><span class="wz-opt-tit">O cliente escolhe</span>
                                    <span class="wz-opt-sub">O cliente escolhe os brindes, até um limite.</span></span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- ===== Passo 4 — O que o cliente precisa comprar ===== -->
                    <div class="wz-page d-none" data-page="4">
                        <div class="wz-bloco">
                            <div class="wz-q">O que o cliente precisa comprar?</div>
                            <div class="wz-ajuda">
                                Cada linha é uma <strong>condição</strong>: escolha a Linha, o Grupo, o Subgrupo e/ou o Produto (só o que precisar) e o mínimo.
                                Ex.: <em>Grupo Coloração, mínimo 10 un.</em>
                            </div>
                            <div id="fxToolbar" class="d-none mb-2">
                                <div id="fxAjuda" class="small text-muted mb-2">
                                    <i class="bi bi-bar-chart-steps me-1"></i>Informe o mínimo de cada <strong>faixa</strong>. A Faixa 1 é o mínimo para a campanha valer; vale a maior faixa atingida.
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="fxAdd()"><i class="bi bi-plus-lg me-1"></i>Adicionar faixa</button>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="fxRemove()"><i class="bi bi-dash-lg me-1"></i>Remover última</button>
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
                                            <th style="width:110px" class="text-center" id="thCondMin">Mínimo</th>
                                            <th style="width:46px" class="text-center" id="thCondDel">—</th>
                                        </tr>
                                    </thead>
                                    <tbody id="condBody"></tbody>
                                </table>
                            </div>
                            <button type="button" class="btn btn-outline-success btn-sm" onclick="condAddRow()">
                                <i class="bi bi-plus-lg me-1"></i>Adicionar condição
                            </button>
                        </div>

                        <div class="wz-bloco d-none" id="wzOpWrap">
                            <div class="wz-q">O cliente precisa cumprir:</div>
                            <input type="hidden" name="cond_operador" id="f_cond_op" value="E" onchange="condOpAtualizar()">
                            <div class="wz-opts mt-2" data-for="f_cond_op">
                                <button type="button" class="wz-opt" data-value="E">
                                    <i class="bi bi-check2-all"></i>
                                    <span><span class="wz-opt-tit">Todas as condições</span>
                                    <span class="wz-opt-sub">Ex.: comprar Coloração <strong>e</strong> Oxidante.</span></span>
                                </button>
                                <button type="button" class="wz-opt" data-value="OU">
                                    <i class="bi bi-check2"></i>
                                    <span><span class="wz-opt-tit">Qualquer uma delas</span>
                                    <span class="wz-opt-sub">Ex.: comprar Coloração <strong>ou</strong> Oxidante.</span></span>
                                </button>
                            </div>
                        </div>

                        <div class="wz-bloco d-none" id="wzMesmaWrap">
                            <div class="wz-q">As quantidades precisam ser iguais?</div>
                            <div class="wz-ajuda">No pedido final, todas as condições precisam ter a mesma quantidade (além dos mínimos). Ex.: 30 Coloração e 30 Oxidante valem; 30 e 24 não.</div>
                            <div class="form-check form-switch">
                                <input type="hidden" name="cond_mesma_qtd" value="0">
                                <input class="form-check-input" type="checkbox" name="cond_mesma_qtd" id="f_mesma_qtd" value="1">
                                <label class="form-check-label" for="f_mesma_qtd">Sim, exigir as mesmas quantidades em todas as condições</label>
                            </div>
                        </div>

                        <details class="wz-mais" id="wzTotDet">
                            <summary>Mais opções: mínimo total somando todas as condições</summary>
                            <div class="border rounded-3 p-2 mt-2 bg-body-tertiary">
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold small mb-1"><i class="bi bi-sigma me-1"></i>Mínimo total das condições</label>
                                        <select name="cond_total_modo" id="f_tot_modo" class="form-select form-select-sm" onchange="condTotalAtualizar()">
                                            <option value="">Não usar</option>
                                            <option value="quantidade">Quantidade total (un.)</option>
                                            <option value="valor">Valor total (R$)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="d-flex flex-wrap gap-2" id="fxTotWrap">
                                            <div class="fx-par"><div class="fx-rot small text-muted d-none">Faixa 1</div>
                                            <input type="number" step="0.01" min="0" name="cond_total_min" id="f_tot_min" class="form-control form-control-sm" placeholder="Mínimo" style="width:110px" disabled></div>
                                        </div>
                                    </div>
                                    <div class="col-md-4 small text-muted">
                                        Soma os itens de <strong>todas</strong> as condições juntas. Com ele, o mínimo de cada condição
                                        fica opcional (deixe 0 para a condição servir só como filtro).
                                    </div>
                                </div>
                            </div>
                        </details>
                    </div>

                    <!-- ===== Passo 5 — Detalhes do benefício ===== -->
                    <div class="wz-page d-none" data-page="5">
                        <!-- DESCONTO -->
                        <div class="wz-bloco camp-step" data-step="desc" id="campDescWrap">
                            <div class="wz-q">Qual o percentual de desconto?</div>
                            <div class="wz-ajuda" id="descAjuda">Vale para os itens das condições (ou para os produtos escolhidos abaixo que não tenham % próprio).</div>
                            <div class="d-flex flex-wrap gap-2" id="fxPctWrap">
                                <div class="fx-par"><div class="fx-rot small text-muted d-none">Faixa 1</div>
                                <div class="input-group input-group-sm" style="width:140px"><input type="number" step="0.01" name="desconto" id="f_desc" class="form-control" value="0"><span class="input-group-text">%</span></div></div>
                            </div>
                        </div>
                        <div class="wz-bloco camp-step" data-step="alvo" id="campAlvoWrap">
                            <div class="wz-q">Em quais produtos o desconto é aplicado?</div>
                            <div class="wz-opts mt-2 mb-3">
                                <label class="wz-opt" for="f_dic_sim">
                                    <input class="d-none" type="radio" name="desc_itens_cond" id="f_dic_sim" value="1" checked onchange="alvoModoAtualizar()">
                                    <i class="bi bi-arrow-repeat"></i>
                                    <span><span class="wz-opt-tit">Nos itens das condições</span>
                                    <span class="wz-opt-sub">Os mesmos produtos que o cliente comprou para atingir a campanha.</span></span>
                                </label>
                                <label class="wz-opt" for="f_dic_nao">
                                    <input class="d-none" type="radio" name="desc_itens_cond" id="f_dic_nao" value="0" onchange="alvoModoAtualizar()">
                                    <i class="bi bi-list-check"></i>
                                    <span><span class="wz-opt-tit">Em outros produtos</span>
                                    <span class="wz-opt-sub">Você escolhe quais produtos recebem o desconto.</span></span>
                                </label>
                            </div>
                            <div id="alvoEscolha" class="d-none">
                            <label class="form-label fw-semibold">Produtos a conceder o desconto</label>
                            <div class="table-responsive d-none" id="alvoTableWrap">
                                <table class="table table-sm table-bordered align-middle mb-2">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="min-width:140px">Linha</th>
                                            <th style="min-width:140px">Grupo</th>
                                            <th style="min-width:140px">Subgrupo</th>
                                            <th style="min-width:160px">Produto</th>
                                            <th style="width:110px" class="text-center alvo-pct-col">% Desconto</th>
                                            <th style="width:46px" class="text-center">—</th>
                                        </tr>
                                    </thead>
                                    <tbody id="alvoBody"></tbody>
                                </table>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="alvoAddRow()">
                                <i class="bi bi-plus-lg me-1"></i>Adicionar produto
                            </button>
                            </div>
                        </div>

                        <!-- BONIFICAÇÃO -->
                        <div class="camp-step d-none" data-step="bonif" id="campBonifWrap">
                            <div class="wz-bloco d-none" id="bonifSelecCfg">
                                <div class="wz-q">Como o cliente escolhe os brindes?</div>
                                <div class="row g-2 align-items-end mt-1" style="max-width:780px">
                                    <div class="col-md-5">
                                        <label class="form-label small mb-1">Escolhe entre</label>
                                        <select name="bonif_selec_modo" id="f_bonif_selec_modo" class="form-select" onchange="campSetBonifModo()">
                                            <option value="produtos">Uma lista de produtos</option>
                                            <option value="categoria">Produtos de uma categoria</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small mb-1">Limite por</label>
                                        <select name="bonif_limite_tipo" id="f_bonif_lim_tipo" class="form-select" onchange="campSetBonifModo()">
                                            <option value="quantidade">Quantidade</option>
                                            <option value="valor">Valor (R$)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small mb-1" id="bonifLimValorLabel">Máx.</label>
                                        <input type="number" step="0.01" min="0" name="bonif_limite_valor" id="f_bonif_lim_valor" class="form-control" value="0">
                                    </div>
                                </div>
                            </div>

                            <!-- Bônus: lista de produtos (fixo ou selecionável-produtos) -->
                            <div id="bonifListaWrap" class="wz-bloco">
                                <div class="wz-q" id="bonifTituloLista">Quais produtos vão de brinde?</div>
                                <div class="wz-ajuda" id="bonifAjudaFixo">
                                    A quantidade bonificada é <strong>multiplicada</strong> conforme o total comprado atinge a condição.
                                </div>
                                <div class="wz-ajuda d-none" id="bonifAjudaSelec">
                                    Produtos que o <strong>cliente</strong> poderá escolher como brinde, até o limite acima.
                                </div>
                                <div class="row g-2" style="max-width:780px">
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
                            <div id="bonifPoolWrap" class="wz-bloco d-none">
                                <div class="wz-q">De quais categorias/produtos o cliente pode escolher?</div>
                                <div class="table-responsive d-none mt-2" id="poolTableWrap">
                                    <table class="table table-sm table-bordered align-middle mb-2">
                                        <thead class="table-light">
                                            <tr><th style="width:120px">Tipo</th><th>Categoria / Produto</th><th style="width:50px" class="text-center">—</th></tr>
                                        </thead>
                                        <tbody id="poolBody"></tbody>
                                    </table>
                                </div>
                                <button type="button" class="btn btn-outline-warning btn-sm mt-2" onclick="poolAddRow()"><i class="bi bi-plus-lg me-1"></i>Adicionar categoria/produto</button>
                            </div>
                        </div>
                    </div>

                    <!-- ===== Passo 6 — Revisão ===== -->
                    <div class="wz-page d-none" data-page="6">
                        <div class="wz-bloco">
                            <div class="wz-q">Confira a campanha</div>
                            <div class="wz-ajuda">Para corrigir algo, clique na etapa lá em cima.</div>
                            <div class="table-responsive"><table class="table table-sm align-middle mb-0" id="wzResumo"></table></div>
                        </div>
                        <div class="wz-bloco">
                            <label class="form-label fw-semibold mb-1" for="f_obs">Observação <span class="text-muted small">(opcional)</span></label>
                            <textarea name="observacao" id="f_obs" class="form-control form-control-sm" rows="2" maxlength="1000" placeholder="Ex.: Na compra de 504 un. de Coloração e 42 un. de Oxidação"></textarea>
                            <div class="form-text">Se preenchida, aparece no pedido (área do cliente e admin) no lugar do texto da condição.</div>
                            <label class="form-label fw-semibold mb-1 mt-2" for="f_obs_am">Observação para o A&amp;M <span class="text-muted small">(opcional)</span></label>
                            <input type="text" name="obs_am" id="f_obs_am" class="form-control form-control-sm" maxlength="255" placeholder="Ex.: Desc {pct} color">
                            <div class="form-text">Levada ao campo <strong>Obs</strong> do pedido no A&amp;M pelo <strong>Importa Pedido</strong> (com "Gravar também no A&amp;M") quando a campanha é atingida. <code>{pct}</code> vira o % atingido. Várias campanhas são separadas por vírgula.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <span class="small text-muted me-auto" id="wzPassoTxt">Passo 1 de 6</span>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-outline-primary" id="wzVoltar" onclick="wzIr(_wzPag - 1)"><i class="bi bi-arrow-left me-1"></i>Voltar</button>
                    <button type="button" class="btn btn-primary" id="wzProx" onclick="wzProximo()">Próximo<i class="bi bi-arrow-right ms-1"></i></button>
                    <button type="submit" class="btn btn-success d-none" id="wzSalvar"><i class="bi bi-save me-1"></i>Salvar</button>
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

// ---- Blocos condicionais (mostra só o que vale para as escolhas feitas) ----
function campStep() {
    var ehDesc      = document.getElementById('f_tipo').value === 'desconto';
    var porClientes = document.getElementById('f_aplicacao').value === 'clientes';
    document.querySelectorAll('#modal .camp-step').forEach(function(el) {
        var step = el.dataset.step, show = true;
        if (step === 'desc' || step === 'alvo')  show = ehDesc;
        if (step === 'bonif' || step === 'bmodo') show = !ehDesc;
        if (step === 'canal')    show = !porClientes;
        if (step === 'clientes') show = porClientes;
        el.classList.toggle('d-none', !show);
    });
    wzOptsAtualizar();
    wzCondVis();
    fxRender();
}

// ==== Assistente (passo a passo) ====
var _wzPag = 1, _wzMax = 1, _wzTotal = 6;
// Cartões de opção ligados a um campo oculto (data-for): clicar grava o valor e dispara o onchange do campo
function wzOptsAtualizar() {
    document.querySelectorAll('#modal .wz-opts[data-for]').forEach(function(box) {
        var v = document.getElementById(box.dataset.for).value;
        box.querySelectorAll('.wz-opt').forEach(function(b) { b.classList.toggle('active', b.dataset.value === v); });
    });
}
document.querySelectorAll('#modal .wz-opts[data-for]').forEach(function(box) {
    box.addEventListener('click', function(ev) {
        var b = ev.target.closest('.wz-opt');
        if (!b) return;
        var inp = document.getElementById(box.dataset.for);
        if (inp.value !== b.dataset.value) {
            inp.value = b.dataset.value;
            inp.dispatchEvent(new Event('change'));
        }
        wzOptsAtualizar();
    });
});
// "Todas / qualquer uma" só com 2+ condições; "mesmas quantidades" só no desconto com 2+ condições em E
function wzCondVis() {
    var n = document.querySelectorAll('#condBody tr').length;
    var ehE = document.getElementById('f_cond_op').value === 'E';
    var mesma = document.getElementById('f_tipo').value === 'desconto' && n >= 2 && ehE;
    document.getElementById('wzOpWrap').classList.toggle('d-none', n < 2);
    document.getElementById('wzMesmaWrap').classList.toggle('d-none', !mesma);
    if (!mesma) document.getElementById('f_mesma_qtd').checked = false;
}
function wzIr(n) {
    n = Math.max(1, Math.min(_wzTotal, n));
    _wzPag = n;
    if (n > _wzMax) _wzMax = n;
    document.querySelectorAll('#modal .wz-page').forEach(function(p) { p.classList.toggle('d-none', +p.dataset.page !== n); });
    document.querySelectorAll('#wzSteps button').forEach(function(b) {
        var k = +b.dataset.go, li = b.parentNode;
        li.classList.toggle('atual', k === n);
        li.classList.toggle('feito', k < n || (k <= _wzMax && k !== n));
        b.disabled = k > _wzMax;
    });
    var editando = document.getElementById('fa').value === 'editar';
    document.getElementById('wzVoltar').classList.toggle('d-none', n === 1);
    document.getElementById('wzProx').classList.toggle('d-none', n === _wzTotal);
    document.getElementById('wzSalvar').classList.toggle('d-none', n !== _wzTotal && !editando);
    document.getElementById('wzPassoTxt').textContent = 'Passo ' + n + ' de ' + _wzTotal;
    if (n === 4 && !document.querySelectorAll('#condBody tr').length) condAddRow();
    if (n === _wzTotal) wzResumo();
    document.querySelector('#modal .modal-body').scrollTop = 0;
}
function wzProximo() { if (wzValidar(_wzPag)) wzIr(_wzPag + 1); }
function wzIniciar(editando) {
    _wzMax = editando ? _wzTotal : 1;
    wzIr(1);
}
document.querySelectorAll('#wzSteps button').forEach(function(b) {
    b.addEventListener('click', function() {
        var k = +b.dataset.go;
        if (k <= _wzPag) { wzIr(k); return; }
        // avançar pela barra: valida as etapas do caminho
        for (var p = _wzPag; p < k; p++) { if (!wzValidar(p)) return; }
        wzIr(k);
    });
});
// Enter fora da última etapa avança em vez de salvar
function campSubmit(ev) {
    var salvar = ev.submitter && ev.submitter.id === 'wzSalvar';
    if (!salvar && _wzPag < _wzTotal) { wzProximo(); return false; }
    return campValidar();
}

// ---- Resumo (última etapa) ----
function wzDataBr(d) { return d ? d.split('-').reverse().join('/') : ''; }
function wzSelTxt(sel) { return sel && sel.value !== '' ? sel.options[sel.selectedIndex].text : ''; }
function wzNum(v) { return (parseFloat(String(v).replace(',', '.')) || 0).toLocaleString('pt-BR'); }
function wzFiltroTxt(tr) {
    return ['linha', 'grupo', 'subgrupo', 'produto'].map(function(k) {
        var t = wzSelTxt(tr.querySelector('[name$="[' + k + ']"]'));
        return t ? (k === 'produto' ? t : k.charAt(0).toUpperCase() + k.slice(1) + ' ' + t) : '';
    }).filter(Boolean).join(' · ');
}
function wzCondTxt(tr) {
    var valor = tr.querySelector('[name$="[modo]"]').value === 'valor';
    var fmt = function(v) { return valor ? 'R$ ' + wzNum(v) : wzNum(v) + ' un.'; };
    var min = tr.querySelector('[name$="[min]"]').value;
    var txt = wzFiltroTxt(tr) || '(sem filtro)';
    if (fxAtivo()) {
        var fx = ['F1 ≥ ' + fmt(min)];
        tr.querySelectorAll('td.fx-col input').forEach(function(inp, i) { fx.push('F' + (i + 2) + ' ≥ ' + fmt(inp.value)); });
        return txt + ' — ' + fx.join(', ');
    }
    return txt + (parseFloat(min) > 0 ? ' — mínimo ' + fmt(min) : ' — sem mínimo próprio');
}
function wzResumo() {
    var g = function(id) { return document.getElementById(id); };
    var lin = [];
    var add = function(rot, html) { lin.push('<tr><th>' + rot + '</th><td>' + html + '</td></tr>'); };
    add('Campanha', '<strong>' + campEsc(g('f_cod').value.trim()) + '</strong> ' +
        (g('f_ativo').value === '1' ? '<span class="badge bg-success">Ativa</span>' : '<span class="badge bg-secondary">Inativa</span>'));
    var ini = g('f_dt_ini').value, fim = g('f_dt_fim').value;
    add('Período', ini && fim ? 'de ' + wzDataBr(ini) + ' a ' + wzDataBr(fim) : ini ? 'a partir de ' + wzDataBr(ini) : fim ? 'até ' + wzDataBr(fim) : 'sem data limite');
    if (g('f_aplicacao').value === 'clientes') {
        var nomes = Array.prototype.map.call(document.querySelectorAll('#cliBody tr'), function(tr) { return campEsc(tr.children[1].textContent); });
        add('Para quem', nomes.length + ' cliente(s): ' + nomes.join(', '));
    } else {
        add('Para quem', g('f_canal').value ? 'Clientes do canal <strong>' + campEsc(wzSelTxt(g('f_canal'))) + '</strong>' : 'Clientes de todos os canais');
    }
    var conds = Array.prototype.map.call(document.querySelectorAll('#condBody tr'), function(tr) { return campEsc(wzCondTxt(tr)); });
    var op = g('f_cond_op').value === 'OU' ? 'OU' : 'E';
    var condHtml = conds.length ? conds.join('<br><strong class="text-primary">' + op + '</strong> ') : '<span class="text-danger">nenhuma</span>';
    if (g('f_mesma_qtd').checked) condHtml += '<br><span class="badge bg-info text-dark mt-1">Quantidades iguais entre as condições</span>';
    if (g('f_tot_modo').value) condHtml += '<br><span class="text-muted">Mínimo total: ' + (g('f_tot_modo').value === 'valor' ? 'R$ ' : '') + wzNum(g('f_tot_min').value) + (g('f_tot_modo').value === 'valor' ? '' : ' un.') + '</span>';
    add('Precisa comprar', condHtml);
    if (g('f_tipo').value === 'desconto') {
        var pct;
        if (fxAtivo()) {
            var p = [wzNum(g('f_desc').value) + '%'];
            document.querySelectorAll('#fxPctWrap .fx-extra input').forEach(function(inp) { p.push(wzNum(inp.value) + '%'); });
            pct = 'Desconto por faixas: ' + p.map(function(v, i) { return 'F' + (i + 1) + ' = ' + v; }).join(', ');
        } else {
            pct = 'Desconto de <strong>' + wzNum(g('f_desc').value) + '%</strong>';
        }
        add('Ganha', pct);
        if (g('f_dic_nao').checked) {
            var alvos = Array.prototype.map.call(document.querySelectorAll('#alvoBody tr'), function(tr) {
                var t = wzFiltroTxt(tr);
                var ap = tr.querySelector('[name$="[desconto]"]');
                return t ? campEsc(t) + (!fxAtivo() && ap && ap.value ? ' (' + wzNum(ap.value) + '%)' : '') : '';
            }).filter(Boolean);
            add('Desconto aplicado em', alvos.join('<br>') || '<span class="text-danger">nenhum produto</span>');
        } else {
            add('Desconto aplicado em', 'Nos itens das condições');
        }
    } else {
        var selec = g('f_bonif_modo').value === 'selecionavel';
        var lim = selec ? ' — limite de ' + (g('f_bonif_lim_tipo').value === 'valor' ? 'R$ ' + wzNum(g('f_bonif_lim_valor').value) : wzNum(g('f_bonif_lim_valor').value) + ' un.') : '';
        add('Ganha', (selec ? 'Brindes à escolha do cliente' : 'Brindes definidos (multiplicam conforme a compra)') + lim);
        var itens;
        if (selec && g('f_bonif_selec_modo').value === 'categoria') {
            itens = Array.prototype.map.call(document.querySelectorAll('#poolBody tr'), function(tr) {
                var v = tr.querySelector('[data-cell="valor"] select, [data-cell="valor"] input');
                var t = v ? (v.tagName === 'SELECT' ? wzSelTxt(v) : v.value) : '';
                return t ? campEsc(wzSelTxt(tr.querySelector('[data-role="tipo"]')) + ': ' + t) : '';
            }).filter(Boolean);
        } else {
            itens = Array.prototype.map.call(document.querySelectorAll('#bonifBody tr'), function(tr) {
                return (selec ? '' : tr.querySelector('[name$="[qtd]"]').value + ' × ') + campEsc(tr.children[1].textContent);
            });
        }
        add('Brindes', itens.join('<br>') || '<span class="text-danger">nenhum</span>');
    }
    g('wzResumo').innerHTML = '<tbody>' + lin.join('') + '</tbody>';
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

// ---- Condições (E/OU) — filtro composto (linha + grupo + subgrupo + produto) ----
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
function condAddRow(linha, grupo, subgrupo, produto, modo, qtd, valorMin, fxMins) {
    var i = _critSeq++;
    modo = modo === 'valor' ? 'valor' : 'quantidade';
    var min = modo === 'valor' ? (parseFloat(valorMin) || 0) : (qtd === undefined ? 1 : (parseInt(qtd, 10) || 0));
    var tr = document.createElement('tr');
    tr.dataset.idx = i;
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
    fxSyncRow(tr, fxMins);
    document.getElementById('condTableWrap').classList.remove('d-none');
    wzCondVis();
}
function condTotalAtualizar() {
    var on = document.getElementById('f_tot_modo').value !== '';
    var inp = document.getElementById('f_tot_min');
    inp.disabled = !on;
    if (!on) inp.value = '';
    fxRender();
}

// Lista compacta da tabela: mostra/esconde o restante dos itens
function lcAlternar(a) {
    var resto = a.previousElementSibling, aberto = resto.classList.toggle('d-none') === false;
    a.textContent = aberto ? 'menos' : a.dataset.mais;
    return false;
}

// ---- Faixas escalonadas das condições ----
// Faixa 1 = coluna "Mínimo" + mínimo total + % padrão; faixas 2..N = colunas/caixas extras.
var _fxN = 1;
function fxAtivo() {
    return document.getElementById('f_tipo').value === 'desconto' && document.getElementById('f_dm_faixas').checked;
}
function fxAdd()    { _fxN++; fxRender(); }
function fxRemove() { if (_fxN > 2) { _fxN--; fxRender(); } }
// Ajusta um contêiner para ter exatamente "qtd" elementos extras (preserva os existentes)
function fxSync(container, sel, qtd, criar) {
    var atuais = container.querySelectorAll(sel);
    for (var i = atuais.length - 1; i >= qtd; i--) atuais[i].remove();
    for (var k = atuais.length; k < qtd; k++) criar(k + 2);
}
function fxCaixa(nome, k, valor, largura, desab) {
    var d = document.createElement('div');
    d.className = 'fx-par fx-extra';
    d.innerHTML = '<div class="fx-rot small text-muted">Faixa ' + k + '</div>' +
        '<input type="number" step="0.01" min="0" class="form-control form-control-sm" name="' + nome + '" value="' + (valor == null ? '' : valor) + '" style="width:' + largura + '"' + (desab ? ' disabled' : '') + '>';
    return d;
}
function fxSyncRow(tr, mins) {
    var n = fxAtivo() ? _fxN - 1 : 0, del = tr.lastElementChild, i = tr.dataset.idx;
    fxSync(tr, 'td.fx-col', n, function(k) {
        var td = document.createElement('td');
        td.className = 'fx-col';
        var v = mins && mins[k] != null ? mins[k] : '';
        td.innerHTML = '<input type="number" step="0.01" min="0" value="' + v + '" name="cond[' + i + '][fx][' + k + ']" class="form-control form-control-sm text-center">';
        tr.insertBefore(td, del);
    });
}
function fxRender() {
    var on = fxAtivo(), n = on ? _fxN - 1 : 0;
    if (on && _fxN < 2) { _fxN = 2; n = 1; }
    document.getElementById('dmWrap').classList.toggle('d-none', document.getElementById('f_tipo').value !== 'desconto');
    document.getElementById('fxToolbar').classList.toggle('d-none', !on);
    document.getElementById('fxAjuda').classList.toggle('d-none', !on);
    document.querySelectorAll('.fx-rot').forEach(function(el) { el.classList.toggle('d-none', !on); });
    document.getElementById('thCondMin').textContent = on ? 'Faixa 1' : 'Mínimo';
    // cabeçalho das faixas 2..N
    var thead = document.getElementById('thCondDel').parentNode;
    fxSync(thead, 'th.fx-col', n, function(k) {
        var th = document.createElement('th');
        th.className = 'fx-col text-center';
        th.textContent = 'Faixa ' + k;
        thead.insertBefore(th, document.getElementById('thCondDel'));
    });
    document.querySelectorAll('#condBody tr').forEach(function(tr) { fxSyncRow(tr, null); });
    // % e mínimo total por faixa
    var pctWrap = document.getElementById('fxPctWrap');
    fxSync(pctWrap, '.fx-extra', n, function(k) { pctWrap.appendChild(fxCaixa('fx_pct[' + k + ']', k, '', '140px', false)); });
    var totWrap = document.getElementById('fxTotWrap'), totOn = document.getElementById('f_tot_modo').value !== '';
    fxSync(totWrap, '.fx-extra', n, function(k) { totWrap.appendChild(fxCaixa('fx_total[' + k + ']', k, '', '110px', !totOn)); });
    totWrap.querySelectorAll('.fx-extra input').forEach(function(inp) { inp.disabled = !totOn; if (!totOn) inp.value = ''; });
    document.getElementById('campAlvoWrap').classList.toggle('sem-pct', on);
    document.getElementById('descAjuda').textContent = on
        ? 'Percentual de cada faixa — vale o da maior faixa atingida, aplicado nos itens das condições (ou nos produtos escolhidos abaixo).'
        : 'Vale para os itens das condições (ou para os produtos escolhidos abaixo que não tenham % próprio).';
}
// Preenche % e totais das faixas 2..N (ao editar)
function fxPreencher(faixas) {
    (faixas || []).forEach(function(fx) {
        var p = document.querySelector('[name="fx_pct[' + fx.faixa + ']"]');   if (p) p.value = parseFloat(fx.pct) || '';
        var t = document.querySelector('[name="fx_total[' + fx.faixa + ']"]'); if (t && fx.total_min != null) t.value = parseFloat(fx.total_min) || '';
    });
}

function condOpAtualizar() {
    var op = document.getElementById('f_cond_op').value === 'OU' ? 'OU' : 'E';
    document.querySelectorAll('.cond-op-txt').forEach(function(el) { el.textContent = op; });
    wzOptsAtualizar();
    wzCondVis();
}
function condRemove(btn) {
    btn.closest('tr').remove();
    document.getElementById('condTableWrap').classList.toggle('d-none', document.querySelectorAll('#condBody tr').length === 0);
    wzCondVis();
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
function alvoAddRow(linha, grupo, subgrupo, produto, desconto) {
    var i = _critSeq++;
    var pct = (desconto === null || desconto === undefined || desconto === '') ? '' : (parseFloat(desconto) || '');
    var tr = document.createElement('tr');
    tr.innerHTML =
        '<td>' + catSelect('alvo[' + i + '][linha]',    'linha',    linha)    + '</td>' +
        '<td>' + catSelect('alvo[' + i + '][grupo]',    'grupo',    grupo)    + '</td>' +
        '<td>' + catSelect('alvo[' + i + '][subgrupo]', 'subgrupo', subgrupo) + '</td>' +
        '<td>' + prodSelect('alvo[' + i + '][produto]', produto) + '</td>' +
        '<td class="alvo-pct-col"><input type="number" step="0.01" min="0" value="' + pct + '" name="alvo[' + i + '][desconto]" class="form-control form-control-sm text-center" placeholder="padrão"></td>' +
        '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="alvoRemove(this)"><i class="bi bi-x-lg"></i></button></td>';
    document.getElementById('alvoBody').appendChild(tr);
    document.getElementById('alvoTableWrap').classList.remove('d-none');
}
// "O desconto será aplicado sobre os itens das condições?" — Não = escolher os produtos
function alvoModoAtualizar() {
    var escolher = document.getElementById('f_dic_nao').checked;
    document.getElementById('alvoEscolha').classList.toggle('d-none', !escolher);
    if (escolher && !document.querySelectorAll('#alvoBody tr').length) alvoAddRow();
}
function alvoRemove(btn) {
    btn.closest('tr').remove();
    document.getElementById('alvoTableWrap').classList.toggle('d-none', document.querySelectorAll('#alvoBody tr').length === 0);
}

// ---- Alterna Desconto x Bonificação ----
function campSetTipo(tipo) {
    var ehBonif = (tipo === 'bonificacao');
    document.getElementById('campBonifWrap').classList.toggle('d-none', !ehBonif);
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

// ==== Clientes específicos (busca AJAX, adicionados um a um) ====
function cliIds() {
    return Array.prototype.map.call(document.querySelectorAll('#cliBody input[name="clientes[]"]'), function(i) { return String(i.value); });
}
function cliAddRow(id, codigo, nome) {
    if (cliIds().indexOf(String(id)) !== -1) return;
    var tr = document.createElement('tr');
    tr.innerHTML =
        '<td class="fw-semibold">' + campEsc(codigo) + '<input type="hidden" name="clientes[]" value="' + parseInt(id, 10) + '"></td>' +
        '<td>' + campEsc(nome) + '</td>' +
        '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="cliRemove(this)"><i class="bi bi-x-lg"></i></button></td>';
    document.getElementById('cliBody').appendChild(tr);
    cliAtualizar();
}
function cliRemove(btn) { btn.closest('tr').remove(); cliAtualizar(); }
function cliAtualizar() {
    document.getElementById('cliTableWrap').classList.toggle('d-none', document.querySelectorAll('#cliBody tr').length === 0);
}
(function() {
    var inp = document.getElementById('cliSearch');
    var dd  = document.getElementById('cliDropdown');
    var tmr = null, seq = 0;
    inp.addEventListener('input', function() {
        clearTimeout(tmr);
        var q = inp.value.trim();
        if (q.length < 2) { dd.style.display = 'none'; return; }
        tmr = setTimeout(function() {
            var my = ++seq;
            fetch('?buscar_cliente=' + encodeURIComponent(q), { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(lista) {
                    if (my !== seq) return;   // resposta de uma busca antiga
                    var ja = cliIds();
                    if (!lista.length) {
                        dd.innerHTML = '<div class="list-group-item small text-muted py-2">Nenhum cliente encontrado</div>';
                    } else {
                        dd.innerHTML = lista.map(function(c) {
                            var add = ja.indexOf(String(c.id)) !== -1;
                            return '<button type="button" class="list-group-item list-group-item-action py-2 px-3 small' + (add ? ' disabled' : '') + '"' +
                                ' data-id="' + c.id + '" data-codigo="' + campEscAttr(c.codigo) + '" data-nome="' + campEscAttr(c.nome) + '">' +
                                '<span class="badge bg-secondary me-2">' + campEsc(c.codigo) + '</span>' + campEsc(c.nome) +
                                (c.local ? ' <span class="text-muted">· ' + campEsc(c.local) + '</span>' : '') +
                                (add ? ' <span class="badge bg-success ms-1">adicionado</span>' : '') + '</button>';
                        }).join('');
                        dd.querySelectorAll('button:not(.disabled)').forEach(function(b) {
                            b.addEventListener('mousedown', function(ev) {
                                ev.preventDefault();
                                cliAddRow(b.dataset.id, b.dataset.codigo, b.dataset.nome);
                                inp.value = '';
                                dd.style.display = 'none';
                                inp.focus({ preventScroll: true });
                            });
                        });
                    }
                    dd.style.display = '';
                })
                .catch(function() { dd.style.display = 'none'; });
        }, 250);
    });
    inp.addEventListener('keydown', function(ev) { if (ev.key === 'Enter') ev.preventDefault(); });
    inp.addEventListener('blur', function() { setTimeout(function() { dd.style.display = 'none'; }, 150); });
})();

// ---- Validação (por etapa do assistente; as mesmas regras do servidor) ----
// el = campo com problema (ou nº da etapa): vai até a etapa dele antes de avisar
function wzFalha(msg, el) {
    var pag = typeof el === 'number' ? el : (el && el.closest('.wz-page') ? +el.closest('.wz-page').dataset.page : _wzPag);
    if (pag !== _wzPag) wzIr(pag);
    alert(msg);
    if (el && typeof el !== 'number') {
        if (el.matches('input,select,textarea') && el.type !== 'hidden') el.classList.add('is-invalid');
        el.focus();
    }
    return false;
}
function wzValidar(p) {
    var g = function(id) { return document.getElementById(id); };
    var num = function(el) { return el ? parseFloat(String(el.value).replace(',', '.')) || 0 : 0; };
    document.querySelectorAll('#modal .is-invalid').forEach(function(el) { el.classList.remove('is-invalid'); });
    var ehDesc = g('f_tipo').value === 'desconto';
    var condRows = document.querySelectorAll('#condBody tr');
    if (p === 1) {
        if (!g('f_cod').value.trim()) return wzFalha('Informe o código da campanha.', g('f_cod'));
        var dtIni = g('f_dt_ini').value, dtFim = g('f_dt_fim').value;
        if (dtIni && dtFim && dtFim < dtIni) return wzFalha('A data final do período deve ser igual ou posterior à data inicial.', g('f_dt_fim'));
    }
    if (p === 2) {
        if (g('f_aplicacao').value === 'clientes' && !cliIds().length) return wzFalha('Adicione ao menos um cliente à campanha (ou escolha "Clientes de um canal de venda").', g('cliSearch'));
    }
    if (p === 4) {
        var temFiltro = Array.prototype.some.call(condRows, function(tr) {
            return Array.prototype.some.call(tr.querySelectorAll('select:not([name$="[modo]"])'), function(sl) { return sl.value !== ''; });
        });
        if (!condRows.length || !temFiltro) return wzFalha('Adicione ao menos uma condição (escolha a Linha, o Grupo, o Subgrupo ou o Produto).', 4);
        var totOn = g('f_tot_modo').value !== '' && num(g('f_tot_min')) > 0;
        var semMin = Array.prototype.find.call(condRows, function(tr) {
            var filtro = Array.prototype.some.call(tr.querySelectorAll('select:not([name$="[modo]"])'), function(sl) { return sl.value !== ''; });
            return filtro && num(tr.querySelector('[name$="[min]"]')) <= 0;
        });
        if (semMin && !totOn) return wzFalha('Informe o mínimo de cada condição (ou um mínimo total em "Mais opções").', semMin.querySelector('[name$="[min]"]'));
        if (g('f_mesma_qtd').checked && Array.prototype.some.call(condRows, function(tr) { return tr.querySelector('[name$="[modo]"]').value !== 'quantidade'; })) {
            return wzFalha('"Mesmas quantidades" exige todas as condições com mínimo por Quantidade.', 4);
        }
        if (fxAtivo()) {
            if (_fxN < 2) return wzFalha('Adicione ao menos a Faixa 2 (ou volte e escolha "Percentual fixo").', 4);
            for (var k = 2; k <= _fxN; k++) {
                for (var r = 0; r < condRows.length; r++) {
                    var mEl = condRows[r].querySelector('[name$="[fx][' + k + ']"]');
                    if (num(condRows[r].querySelector('[name$="[min]"]')) > 0 && num(mEl) <= 0) return wzFalha('Faixa ' + k + ': informe o mínimo da condição ' + (r + 1) + '.', mEl);
                }
                var tEl = document.querySelector('[name="fx_total[' + k + ']"]');
                if (g('f_tot_modo').value !== '' && num(tEl) <= 0) return wzFalha('Faixa ' + k + ': informe o mínimo total das condições.', tEl);
            }
        }
    }
    if (p === 5 && ehDesc) {
        var escolher = g('f_dic_nao').checked;
        var alvoRows = Array.prototype.filter.call(document.querySelectorAll('#alvoBody tr'), function(tr) {
            return Array.prototype.some.call(tr.querySelectorAll('select'), function(sl) { return sl.value !== ''; });
        });
        if (fxAtivo()) {
            var pctAnt = num(g('f_desc'));
            if (pctAnt <= 0) return wzFalha('Faixa 1: informe o percentual de desconto.', g('f_desc'));
            for (var k2 = 2; k2 <= _fxN; k2++) {
                var pEl = document.querySelector('[name="fx_pct[' + k2 + ']"]'), pct = num(pEl);
                if (pct <= 0) return wzFalha('Faixa ' + k2 + ': informe o percentual de desconto.', pEl);
                if (pct <= pctAnt) return wzFalha('Faixa ' + k2 + ': o percentual deve ser maior que o da faixa anterior (' + pctAnt + '%).', pEl);
                pctAnt = pct;
            }
        } else if (num(g('f_desc')) <= 0) {
            var semPct = !escolher || !alvoRows.length || alvoRows.some(function(tr) { return num(tr.querySelector('[name$="[desconto]"]')) <= 0; });
            if (semPct) return wzFalha('Informe o percentual de desconto' + (escolher ? ' (ou um % em cada produto escolhido).' : '.'), g('f_desc'));
        }
        if (escolher && !alvoRows.length) return wzFalha('Escolha ao menos um produto para receber o desconto (ou marque "Nos itens das condições").', 5);
    }
    if (p === 5 && !ehDesc) {
        var selec = g('f_bonif_modo').value === 'selecionavel';
        if (selec && num(g('f_bonif_lim_valor')) <= 0) return wzFalha('Informe o limite (quantidade ou valor) que o cliente pode escolher.', g('f_bonif_lim_valor'));
        if (selec && g('f_bonif_selec_modo').value === 'categoria') {
            var temPool = Array.prototype.some.call(document.querySelectorAll('#poolBody [data-cell="valor"] select'), function(sl) { return sl.value !== ''; });
            if (!temPool) return wzFalha('Adicione ao menos uma categoria/produto que o cliente pode escolher.', 5);
        } else if (!bonifIds().length) {
            return wzFalha(selec ? 'Adicione ao menos um produto que o cliente pode escolher.' : 'Adicione ao menos um produto de brinde.', g('bonifSearch'));
        }
    }
    return true;
}
// Salvar: confere todas as etapas (para no primeiro problema, já na etapa dele)
function campValidar() {
    for (var p = 1; p < _wzTotal; p++) { if (!wzValidar(p)) return false; }
    return true;
}

// ---- Reset / abrir modal ----
function campLimpar() {
    document.getElementById('f_obs').value = '';
    document.getElementById('f_obs_am').value = '';
    document.getElementById('cliBody').innerHTML = '';
    document.getElementById('cliSearch').value = '';
    cliAtualizar();
    document.getElementById('f_tot_modo').value = '';
    condTotalAtualizar();
    document.getElementById('wzTotDet').open = false;
    document.getElementById('f_dm_fixo').checked = true;
    document.getElementById('f_mesma_qtd').checked = false;
    _fxN = 1;
    fxRender();
    document.getElementById('f_cond_op').value = 'E';
    condOpAtualizar();
    document.getElementById('condBody').innerHTML = '';
    document.getElementById('condTableWrap').classList.add('d-none');
    document.getElementById('alvoBody').innerHTML = '';
    document.getElementById('f_dic_sim').checked = true;
    document.getElementById('alvoEscolha').classList.add('d-none');
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
    document.getElementById('f_aplicacao').value = 'canal';
    document.getElementById('f_ativo').value    = '1';
    document.getElementById('f_dt_ini').value   = '';
    document.getElementById('f_dt_fim').value   = '';
    document.getElementById('f_desc').value     = 0;
    document.getElementById('f_tipo').value     = 'desconto';
    campLimpar();
    campSetTipo('desconto');
    campStep();
    wzIniciar(false);
}

function editarReg(d) {
    document.getElementById('mt').textContent   = 'Editar Campanha';
    document.getElementById('fa').value         = 'editar';
    document.getElementById('f_cod_orig').value = d.codigo_campanha || '';
    document.getElementById('f_cod').value      = d.codigo_campanha || '';
    document.getElementById('f_canal').value    = d.canal_venda_id  || '';
    document.getElementById('f_aplicacao').value = d.aplicacao === 'clientes' ? 'clientes' : 'canal';
    document.getElementById('f_ativo').value    = String(d.ativo == null ? 1 : d.ativo);
    document.getElementById('f_dt_ini').value   = d.data_inicio || '';
    document.getElementById('f_dt_fim').value   = d.data_fim || '';
    document.getElementById('f_desc').value     = parseFloat(d.desconto) || 0;
    document.getElementById('f_tipo').value     = d.tipo || 'desconto';
    campLimpar();
    document.getElementById('f_cond_op').value = d.cond_operador === 'OU' ? 'OU' : 'E';
    condOpAtualizar();
    document.getElementById('f_obs').value = d.observacao || '';
    document.getElementById('f_obs_am').value = d.obs_am || '';
    document.getElementById('f_tot_modo').value = d.cond_total_modo || '';
    condTotalAtualizar();
    if (d.cond_total_modo) document.getElementById('f_tot_min').value = parseFloat(d.cond_total_min || 0) || '';
    document.getElementById(d.desconto_modo === 'faixas' ? 'f_dm_faixas' : 'f_dm_fixo').checked = true;
    document.getElementById('f_mesma_qtd').checked = !!d.cond_mesma_qtd;
    _fxN = 1 + (d.faixas || []).length;
    fxRender();
    fxPreencher(d.faixas);
    (d.condicoes || []).forEach(function(c, ci) {
        var mins = {};
        (d.faixas || []).forEach(function(fx) { if (fx.mins && fx.mins[ci] != null) mins[fx.faixa] = parseFloat(fx.mins[ci]); });
        condAddRow(c.linha, c.grupo, c.subgrupo, c.produto, c.modo, c.qtd, c.valor_min, mins);
    });
    (d.desconto_alvos || []).forEach(function(a) { alvoAddRow(a.linha, a.grupo, a.subgrupo, a.produto, a.desconto); });
    document.getElementById((d.desconto_alvos || []).length ? 'f_dic_nao' : 'f_dic_sim').checked = true;
    alvoModoAtualizar();
    (d.bonif_pool || []).forEach(function(a) { poolAddRow(a.tipo, a.valor); });
    document.getElementById('f_bonif_modo').value       = d.bonif_modo || 'fixo';
    document.getElementById('f_bonif_selec_modo').value = d.bonif_selec_modo || 'produtos';
    document.getElementById('f_bonif_lim_tipo').value   = d.bonif_limite_tipo || 'quantidade';
    document.getElementById('f_bonif_lim_valor').value  = parseFloat(d.bonif_limite_valor || 0) || 0;
    (d.bonificados || []).forEach(function(b) { bonifAddRow(b.id, b.codigo_produto, b.descricao_pt, b.quantidade); });
    (d.clientes || []).forEach(function(cl) { cliAddRow(cl.id, cl.codigo, cl.nome); });
    campSetBonifModo();
    campSetTipo(d.tipo || 'desconto');
    campStep();
    document.getElementById('wzTotDet').open = !!d.cond_total_modo;
    wzIniciar(true);
    new bootstrap.Modal(document.getElementById('modal')).show();
}
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
