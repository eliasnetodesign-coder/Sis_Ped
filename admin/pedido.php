<?php
require_once __DIR__ . '/../config.php';
requireAdmin();
$u = usuario();

// Garante que a tabela de logs existe
db()->exec("CREATE TABLE IF NOT EXISTS pedido_logs (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    pedido_id     INT         NOT NULL,
    numero_pedido VARCHAR(50) NOT NULL,
    usuario_nome  VARCHAR(100),
    usuario_tipo  VARCHAR(30),
    acao          VARCHAR(120) NOT NULL,
    status_antes  VARCHAR(30),
    status_depois VARCHAR(30),
    detalhes      TEXT,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pedido (pedido_id)
)");

function logPedido(int $pedidoId, string $numero, string $acao, ?string $antes, ?string $depois, string $detalhes = ''): void {
    global $u;
    db()->prepare('INSERT INTO pedido_logs (pedido_id,numero_pedido,usuario_nome,usuario_tipo,acao,status_antes,status_depois,detalhes) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$pedidoId, $numero, $u['nome'] ?? '—', $u['tipo'] ?? '—', $acao, $antes, $depois, $detalhes]);
}

function recalcularDescontosCampanha(string $lote_id, float $dCliente, float $dCanal): void {
    // Busca o canal_venda_id do cliente deste lote para filtrar campanhas
    $canalRow = db()->prepare('SELECT c.canal_venda_id FROM pedidos p JOIN clientes c ON c.id = p.cliente_id WHERE p.lote_id = ? LIMIT 1');
    $canalRow->execute([$lote_id]);
    $canalVendaId = (int)($canalRow->fetchColumn() ?: 0);

    $camps = db()->query('SELECT produto_id, linha, grupo, subgrupo, canal_venda_id, quantidade, desconto FROM campanhas ORDER BY desconto DESC')->fetchAll();
    $stmt  = db()->prepare('
        SELECT p.id, p.produto_id, p.quantidade_total, p.tipo_venda,
               pr.linha, pr.grupo, pr.subgrupo,
               COALESCE(t.preco_padrao, pr.vendas_varejo) AS preco
        FROM pedidos p
        JOIN produtos pr ON pr.id = p.produto_id
        LEFT JOIN tabela_precos t ON t.produto_id = pr.id
        WHERE p.lote_id = ?
    ');
    $stmt->execute([$lote_id]);
    $items = $stmt->fetchAll();
    foreach ($items as $item) {
        $bestDisc = 0;
        foreach ($camps as $camp) {
            // Filtro de canal: ignora campanha restrita a canal diferente do cliente
            if ($camp['canal_venda_id'] && (int)$camp['canal_venda_id'] !== $canalVendaId) continue;
            if ($camp['produto_id'] !== null) {
                if ((int)$camp['produto_id'] !== (int)$item['produto_id']) continue;
                $qtdCheck = (int)$item['quantidade_total'];
            } else {
                $l = trim($camp['linha'] ?? ''); $g = trim($camp['grupo'] ?? ''); $s = trim($camp['subgrupo'] ?? '');
                if ($l && strtolower($l) !== strtolower(trim($item['linha']    ?? ''))) continue;
                if ($g && strtolower($g) !== strtolower(trim($item['grupo']    ?? ''))) continue;
                if ($s && strtolower($s) !== strtolower(trim($item['subgrupo'] ?? ''))) continue;
                $qtdCheck = 0;
                foreach ($items as $other) {
                    if ($l && strtolower($l) !== strtolower(trim($other['linha']    ?? ''))) continue;
                    if ($g && strtolower($g) !== strtolower(trim($other['grupo']    ?? ''))) continue;
                    if ($s && strtolower($s) !== strtolower(trim($other['subgrupo'] ?? ''))) continue;
                    $qtdCheck += (int)$other['quantidade_total'];
                }
            }
            if ((int)$camp['quantidade'] > 0 && $qtdCheck < (int)$camp['quantidade']) continue;
            if ((float)$camp['desconto'] > $bestDisc) $bestDisc = (float)$camp['desconto'];
        }
        $valor = $item['tipo_venda'] === 'bonificacao' ? 0.0
               : (float)$item['quantidade_total'] * (float)($item['preco'] ?? 0)
                 * (1 - ($dCliente + $dCanal) / 100)
                 * (1 - $bestDisc / 100);
        db()->prepare('UPDATE pedidos SET desconto_campanha = ?, valor_total = ? WHERE id = ?')
            ->execute([$bestDisc ?: null, $valor, $item['id']]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $from   = $_POST['_from'] ?? 'detail';

    // Busca número do pedido para o log
    $numRow = db()->prepare('SELECT numero_pedido, status FROM pedidos WHERE id = ?');
    $numRow->execute([$id]);
    $numRow = $numRow->fetch();
    $numPed = $numRow['numero_pedido'] ?? "#{$id}";

    try {
        if ($action === 'aprovar') {
            $ped = db()->prepare('SELECT status, lote_id FROM pedidos WHERE id = ?');
            $ped->execute([$id]);
            $ped = $ped->fetch();
            if ($u['tipo'] === 'comercial' && $ped['status'] === 'comercial') {
                if ($ped['lote_id']) {
                    db()->prepare('UPDATE pedidos SET status = "financeiro" WHERE lote_id = ?')->execute([$ped['lote_id']]);
                } else {
                    db()->prepare('UPDATE pedidos SET status = "financeiro" WHERE id = ?')->execute([$id]);
                }
                logPedido($id, $numPed, 'Aprovado → Financeiro', 'comercial', 'financeiro');
                flash('success', 'Pedido aprovado e enviado ao Financeiro.');
            } elseif ($u['tipo'] === 'financeiro' && $ped['status'] === 'financeiro') {
                if ($ped['lote_id']) {
                    db()->prepare('UPDATE pedidos SET status = "faturado" WHERE lote_id = ?')->execute([$ped['lote_id']]);
                } else {
                    db()->prepare('UPDATE pedidos SET status = "faturado" WHERE id = ?')->execute([$id]);
                }
                logPedido($id, $numPed, 'Aprovado / Faturado', 'financeiro', 'faturado');
                flash('success', 'Pedido faturado com sucesso!');
            } else {
                flash('warning', 'Ação não permitida para o status atual.');
            }
        } elseif ($action === 'reprovar') {
            $ped = db()->prepare('SELECT status, lote_id FROM pedidos WHERE id = ?');
            $ped->execute([$id]);
            $ped = $ped->fetch();
            $statusAntes = $ped['status'];
            if ($ped['lote_id']) {
                db()->prepare('UPDATE pedidos SET status = "reprovado" WHERE lote_id = ?')->execute([$ped['lote_id']]);
            } else {
                db()->prepare('UPDATE pedidos SET status = "reprovado" WHERE id = ?')->execute([$id]);
            }
            logPedido($id, $numPed, 'Reprovado', $statusAntes, 'reprovado');
            flash('danger', 'Pedido reprovado.');
        } elseif ($action === 'retornar' && $u['tipo'] === 'financeiro') {
            $ped = db()->prepare('SELECT lote_id FROM pedidos WHERE id = ?');
            $ped->execute([$id]);
            $ped = $ped->fetch();
            if ($ped['lote_id']) {
                db()->prepare('UPDATE pedidos SET status = "comercial" WHERE lote_id = ?')->execute([$ped['lote_id']]);
            } else {
                db()->prepare('UPDATE pedidos SET status = "comercial" WHERE id = ?')->execute([$id]);
            }
            logPedido($id, $numPed, 'Retornado ao Comercial', 'financeiro', 'comercial');
            flash('warning', 'Pedido retornado ao Comercial.');
        } elseif ($action === 'editar' && $u['tipo'] === 'comercial') {
            $ped = db()->prepare('SELECT p.*, c.desconto_cliente, c.desconto_canal FROM pedidos p LEFT JOIN clientes c ON c.id = p.cliente_id WHERE p.id = ?');
            $ped->execute([$id]);
            $ped = $ped->fetch();
            if ($ped && $ped['status'] === 'comercial') {
                $produto_id = (int)$_POST['produto_id'];
                $qtd        = max(1, (int)$_POST['quantidade_total']);
                $tipo       = $_POST['tipo_venda'] === 'bonificacao' ? 'bonificacao' : 'venda';
                $obs        = trim($_POST['observacoes'] ?? '');
                $prod = db()->prepare('SELECT p.*, COALESCE(t.preco_padrao, p.vendas_varejo) as preco FROM produtos p LEFT JOIN tabela_precos t ON t.produto_id = p.id WHERE p.id = ?');
                $prod->execute([$produto_id]);
                $prod = $prod->fetch();
                if (!$prod) throw new Exception('Produto inválido.');
                $desconto    = ((float)($ped['desconto_cliente'] ?? 0) + (float)($ped['desconto_canal'] ?? 0)) / 100;
                $valor_total = $qtd * (float)$prod['preco'] * (1 - $desconto);
                // Aplica desconto de campanha
                $camps_all = db()->query('SELECT produto_id, linha, grupo, subgrupo, quantidade, desconto FROM campanhas')->fetchAll();
                $campDesc  = 0;
                foreach ($camps_all as $camp) {
                    if ((int)$camp['quantidade'] > 0 && $qtd < (int)$camp['quantidade']) continue;
                    if ($camp['produto_id'] && (int)$camp['produto_id'] !== $produto_id) continue;
                    if (!$camp['produto_id']) {
                        $l = trim($camp['linha']    ?? ''); $g = trim($camp['grupo']    ?? ''); $s = trim($camp['subgrupo'] ?? '');
                        if ($l && strtolower($l) !== strtolower(trim($prod['linha']    ?? ''))) continue;
                        if ($g && strtolower($g) !== strtolower(trim($prod['grupo']    ?? ''))) continue;
                        if ($s && strtolower($s) !== strtolower(trim($prod['subgrupo'] ?? ''))) continue;
                    }
                    if ((float)$camp['desconto'] > $campDesc) $campDesc = (float)$camp['desconto'];
                }
                if ($campDesc > 0) $valor_total *= (1 - $campDesc / 100);
                if ($tipo === 'bonificacao') $valor_total = 0;
                db()->prepare('UPDATE pedidos SET produto_id=?,descricao_produto=?,codigo_barra=?,quantidade_total=?,tipo_venda=?,observacoes=?,valor_total=?,desconto_campanha=? WHERE id=?')
                    ->execute([$produto_id, $prod['descricao_pt'], $prod['codigo_barra'], $qtd, $tipo, $obs, $valor_total, $campDesc ?: null, $id]);
                if ($ped['lote_id']) {
                    recalcularDescontosCampanha($ped['lote_id'], (float)($ped['desconto_cliente'] ?? 0), (float)($ped['desconto_canal'] ?? 0));
                }
                $det = "Produto: {$prod['descricao_pt']} | Qtd: {$qtd} | Tipo: {$tipo} | Valor: " . number_format($valor_total, 2, ',', '.');
                logPedido($id, $numPed, 'Item editado', $ped['status'], $ped['status'], $det);
                flash('success', 'Item atualizado com sucesso!');
            } else {
                flash('warning', 'Edição não permitida para o status atual.');
            }
        } elseif ($action === 'adicionar' && $u['tipo'] === 'comercial') {
            $ped = db()->prepare('SELECT p.*, c.desconto_cliente, c.desconto_canal FROM pedidos p LEFT JOIN clientes c ON c.id = p.cliente_id WHERE p.id = ?');
            $ped->execute([$id]);
            $ped = $ped->fetch();
            if (!$ped) throw new Exception('Pedido não encontrado.');
            if ($ped['status'] !== 'comercial') throw new Exception('Não é possível adicionar itens neste status.');
            $produto_id = (int)$_POST['produto_id'];
            if (!$produto_id) throw new Exception('Selecione um produto.');
            $qtdPacotes = max(1, (int)$_POST['quantidade_total']);
            $tipo = $_POST['tipo_venda'] === 'bonificacao' ? 'bonificacao' : 'venda';
            $obs  = trim($_POST['observacoes'] ?? '');
            $prod = db()->prepare('SELECT p.*, COALESCE(t.preco_padrao, p.vendas_varejo) as preco FROM produtos p LEFT JOIN tabela_precos t ON t.produto_id = p.id WHERE p.id = ?');
            $prod->execute([$produto_id]);
            $prod = $prod->fetch();
            if (!$prod) throw new Exception('Produto inválido.');
            $qtd         = $qtdPacotes * max(1, (int)($prod['multiplo'] ?? 1));
            $dCliente    = (float)($ped['desconto_cliente'] ?? 0);
            $dCanal      = (float)($ped['desconto_canal']   ?? 0);
            $valor_total = $tipo === 'bonificacao' ? 0.0
                         : $qtd * (float)$prod['preco'] * (1 - ($dCliente + $dCanal) / 100);
            // Garante lote_id — cria se o pedido ainda não tem
            $lote_id = $ped['lote_id'];
            if (!$lote_id) {
                $lote_id = 'L' . date('Ymd') . str_pad($id, 6, '0', STR_PAD_LEFT);
                db()->prepare('UPDATE pedidos SET lote_id = ? WHERE id = ?')->execute([$lote_id, $id]);
            }
            db()->prepare('INSERT INTO pedidos (numero_pedido,tipo_venda,data_pedido,cliente_id,produto_id,vendedor,codigo_barra,descricao_produto,quantidade_total,valor_total,status,observacoes,lote_id,desconto_campanha) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$ped['numero_pedido'], $tipo, $ped['data_pedido'], $ped['cliente_id'], $produto_id, $ped['vendedor'] ?? '', $prod['codigo_barra'], $prod['descricao_pt'], $qtd, $valor_total, $ped['status'], $obs, $lote_id, null]);
            recalcularDescontosCampanha($lote_id, $dCliente, $dCanal);
            $det = "Adicionado: {$prod['descricao_pt']} | Qtd: {$qtd} | Tipo: {$tipo}";
            logPedido($id, $numPed, 'Produto adicionado', $ped['status'], $ped['status'], $det);
            flash('success', 'Produto adicionado ao pedido!');
        } elseif ($action === 'set_qtd' && $u['tipo'] === 'comercial') {
            $pacotes = max(1, (int)($_POST['qtd_total'] ?? 1));
            $ped = db()->prepare('SELECT p.*, c.desconto_cliente, c.desconto_canal FROM pedidos p LEFT JOIN clientes c ON c.id = p.cliente_id WHERE p.id = ?');
            $ped->execute([$id]);
            $ped = $ped->fetch();
            if (!$ped || $ped['status'] !== 'comercial') throw new Exception('Edição não permitida neste status.');
            $multRow = db()->prepare('SELECT multiplo FROM produtos WHERE id = ?');
            $multRow->execute([(int)$ped['produto_id']]);
            $multiplo = max(1, (int)($multRow->fetchColumn() ?: 1));
            $novaQtd  = $pacotes * $multiplo;
            db()->prepare('UPDATE pedidos SET quantidade_total = ? WHERE id = ?')->execute([$novaQtd, $id]);
            $dC  = (float)($ped['desconto_cliente'] ?? 0);
            $dCn = (float)($ped['desconto_canal']   ?? 0);
            if ($ped['lote_id']) {
                recalcularDescontosCampanha($ped['lote_id'], $dC, $dCn);
            } else {
                $prod = db()->prepare('SELECT p.*, COALESCE(t.preco_padrao, p.vendas_varejo) as preco FROM produtos p LEFT JOIN tabela_precos t ON t.produto_id = p.id WHERE p.id = ?');
                $prod->execute([(int)$ped['produto_id']]);
                $prod = $prod->fetch();
                $camps_all = db()->query('SELECT produto_id, linha, grupo, subgrupo, quantidade, desconto FROM campanhas')->fetchAll();
                $campDesc  = 0;
                foreach ($camps_all as $camp) {
                    if ((int)$camp['quantidade'] > 0 && $novaQtd < (int)$camp['quantidade']) continue;
                    if ($camp['produto_id'] && (int)$camp['produto_id'] !== (int)$ped['produto_id']) continue;
                    if (!$camp['produto_id']) {
                        $l = trim($camp['linha'] ?? ''); $g = trim($camp['grupo'] ?? ''); $s = trim($camp['subgrupo'] ?? '');
                        if ($l && strtolower($l) !== strtolower(trim($prod['linha'] ?? ''))) continue;
                        if ($g && strtolower($g) !== strtolower(trim($prod['grupo'] ?? ''))) continue;
                        if ($s && strtolower($s) !== strtolower(trim($prod['subgrupo'] ?? ''))) continue;
                    }
                    if ((float)$camp['desconto'] > $campDesc) $campDesc = (float)$camp['desconto'];
                }
                $valor = $ped['tipo_venda'] === 'bonificacao' ? 0.0
                       : $novaQtd * (float)$prod['preco'] * (1 - ($dC + $dCn) / 100) * (1 - $campDesc / 100);
                db()->prepare('UPDATE pedidos SET quantidade_total = ?, valor_total = ?, desconto_campanha = ? WHERE id = ?')
                    ->execute([$novaQtd, $valor, $campDesc ?: null, $id]);
            }
            logPedido($id, $numPed, 'Quantidade alterada', $ped['status'], $ped['status'], "Qtd: {$novaQtd}");
            flash('success', 'Quantidade atualizada.');
        } elseif ($action === 'remover_item' && $u['tipo'] === 'comercial') {
            $ped = db()->prepare('SELECT p.*, c.desconto_cliente, c.desconto_canal FROM pedidos p LEFT JOIN clientes c ON c.id = p.cliente_id WHERE p.id = ?');
            $ped->execute([$id]);
            $ped = $ped->fetch();
            if (!$ped || $ped['status'] !== 'comercial') throw new Exception('Remoção não permitida neste status.');
            if (!$ped['lote_id']) throw new Exception('Não é possível remover o único item do pedido.');
            $cntStmt = db()->prepare('SELECT COUNT(*) FROM pedidos WHERE lote_id = ?');
            $cntStmt->execute([$ped['lote_id']]);
            if ((int)$cntStmt->fetchColumn() <= 1) throw new Exception('Não é possível remover o único item do pedido.');
            $loteParaRecalc = $ped['lote_id'];
            $dCli = (float)($ped['desconto_cliente'] ?? 0);
            $dCan = (float)($ped['desconto_canal']   ?? 0);
            $descProd = $ped['descricao_produto'] ?? '';
            // Determina o redirecionamento ANTES de deletar (exclui o item atual)
            $firstStmt = db()->prepare('SELECT id FROM pedidos WHERE lote_id = ? AND id != ? ORDER BY id LIMIT 1');
            $firstStmt->execute([$loteParaRecalc, $id]);
            $redirectRemover = (int)($firstStmt->fetchColumn() ?: 0);
            db()->prepare('DELETE FROM pedidos WHERE id = ?')->execute([$id]);
            logPedido($id, $numPed, 'Item removido', $ped['status'], $ped['status'], $descProd);
            recalcularDescontosCampanha($loteParaRecalc, $dCli, $dCan);
            // Se sobrou apenas 1 item, limpa o lote_id
            $cntStmt->execute([$loteParaRecalc]);
            if ((int)$cntStmt->fetchColumn() === 1) {
                db()->prepare('UPDATE pedidos SET lote_id = NULL WHERE lote_id = ?')->execute([$loteParaRecalc]);
            }
            flash('success', 'Item removido do pedido.');
        }
    } catch (Exception $e) {
        flash('danger', 'Erro: ' . $e->getMessage());
    }

    if ($from === 'list') {
        header('Location: ' . BASE_URL . '/admin/pedidos.php');
    } else {
        $rId = isset($redirectRemover) && $redirectRemover ? $redirectRemover : $id;
        header('Location: ' . BASE_URL . '/admin/pedido.php?id=' . $rId);
    }
    exit;
}

$pedidoId = (int)($_GET['id'] ?? 0);
if ($pedidoId < 1) {
    flash('warning', 'Pedido inválido.');
    header('Location: ' . BASE_URL . '/admin/pedidos.php'); exit;
}

$pedido = db()->prepare("
    SELECT p.*, c.razao_social, c.email AS cliente_email,
           c.desconto_cliente, c.desconto_canal,
           cv.canal AS canal_venda,
           pr.codigo_produto, pr.multiplo, pr.linha, pr.grupo, pr.subgrupo,
           COALESCE(t.preco_padrao, pr.vendas_varejo) AS preco_unit
    FROM pedidos p
    LEFT JOIN clientes c      ON c.id  = p.cliente_id
    LEFT JOIN canal_venda cv  ON cv.id = c.canal_venda_id
    LEFT JOIN produtos pr     ON pr.id = p.produto_id
    LEFT JOIN tabela_precos t ON t.produto_id = pr.id
    WHERE p.id = ?");
$pedido->execute([$pedidoId]);
$pedido = $pedido->fetch();

if (!$pedido) {
    flash('warning', 'Pedido não encontrado.');
    header('Location: ' . BASE_URL . '/admin/pedidos.php'); exit;
}

$loteId = $pedido['lote_id'] ?: null;
if ($loteId) {
    $stmtItens = db()->prepare("
        SELECT p.*, pr.codigo_produto, pr.multiplo, COALESCE(t.preco_padrao, pr.vendas_varejo) AS preco_unit
        FROM pedidos p
        LEFT JOIN produtos pr ON pr.id = p.produto_id
        LEFT JOIN tabela_precos t ON t.produto_id = pr.id
        WHERE p.lote_id = ? ORDER BY p.id");
    $stmtItens->execute([$loteId]);
    $itensPedido = $stmtItens->fetchAll();
} else {
    $itensPedido = [$pedido];
}
$valorTotalGeral = array_sum(array_column($itensPedido, 'valor_total'));

$creditoUsadoAdmin = 0.0;
if ($pedido['lote_id']) {
    $cuAdm = db()->prepare('SELECT credito_utilizado FROM pedidos WHERE lote_id = ? AND credito_utilizado > 0 LIMIT 1');
    $cuAdm->execute([$pedido['lote_id']]);
    $creditoUsadoAdmin = (float)($cuAdm->fetchColumn() ?: 0);
} else {
    $creditoUsadoAdmin = (float)($pedido['credito_utilizado'] ?? 0);
}

$produtos = db()->query('SELECT p.id, p.descricao_pt, p.codigo_produto, p.codigo_barra, p.multiplo, p.linha, p.grupo, p.subgrupo, COALESCE(t.preco_padrao, p.vendas_varejo) as preco FROM produtos p LEFT JOIN tabela_precos t ON t.produto_id = p.id WHERE p.status = "ativo" ORDER BY p.descricao_pt')->fetchAll();
$campanhas_edit = db()->query('SELECT produto_id, linha, grupo, subgrupo, quantidade, desconto, codigo_campanha FROM campanhas ORDER BY desconto DESC')->fetchAll();

// Logs do pedido — busca por numero_pedido para incluir todos os itens do lote
$logsStmt = db()->prepare('SELECT * FROM pedido_logs WHERE numero_pedido = ? ORDER BY created_at DESC');
$logsStmt->execute([$pedido['numero_pedido']]);
$pedidoLogs = $logsStmt->fetchAll();

$status       = $pedido['status'];
$isComercial  = $u['tipo'] === 'comercial';
$isFinanceiro = $u['tipo'] === 'financeiro';
$canEdit      = $isComercial  && $status === 'comercial';
$canAprovar   = ($isComercial  && $status === 'comercial')
             || ($isFinanceiro && $status === 'financeiro');
$canReprovar  = $canAprovar;
$canRetornar  = $isFinanceiro && $status === 'financeiro';

$pageTitle = 'Pedido ' . e($pedido['numero_pedido']);
require_once LAYOUT_PATH . '/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><?= e($pedido['numero_pedido']) ?></h4>
        <div><?= statusBadge($status) ?></div>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalLog">
            <i class="bi bi-clock-history me-1"></i>Log
            <?php if ($pedidoLogs): ?>
            <span class="badge bg-primary ms-1"><?= count($pedidoLogs) ?></span>
            <?php endif; ?>
        </button>
        <a href="<?= BASE_URL ?>/admin/pedido-pdf.php?id=<?= $pedidoId ?>" target="_blank"
           class="btn btn-outline-secondary">
            <i class="bi bi-file-earmark-pdf me-1"></i>PDF
        </a>
        <a href="<?= BASE_URL ?>/admin/pedidos.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Voltar
        </a>
    </div>
</div>

<!-- Modal Log -->
<div class="modal fade" id="modalLog" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header border-bottom">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-clock-history me-2 text-primary"></i>Histórico — <?= e($pedido['numero_pedido']) ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
            <?php if ($pedidoLogs): ?>
                <ul class="list-unstyled mb-0" style="position:relative">
                    <!-- linha vertical da timeline -->
                    <style>
                    .log-timeline::before{content:'';position:absolute;left:14px;top:0;bottom:0;width:2px;background:var(--gray-border);}
                    .log-dot{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.85rem;z-index:1;position:relative;}
                    </style>
                    <ul class="list-unstyled mb-0 log-timeline ps-1">
                    <?php
                    $acaoCor = [
                        'Aprovado → Financeiro'       => ['success', 'bi-check-circle'],
                        'Aprovado / Faturado'         => ['success', 'bi-check2-all'],
                        'Reprovado'                   => ['danger',  'bi-x-circle'],
                        'Retornado ao Comercial'      => ['warning', 'bi-arrow-counterclockwise'],
                        'Pedido editado'              => ['primary', 'bi-pencil'],
                        'Pedido criado pelo cliente'  => ['info',    'bi-plus-circle'],
                        'Pedido editado pelo cliente' => ['primary', 'bi-pencil-square'],
                        'Item editado'                => ['primary', 'bi-pencil'],
                        'Produto adicionado'          => ['success', 'bi-plus-lg'],
                        'Quantidade alterada'         => ['secondary','bi-123'],
                    ];
                    foreach ($pedidoLogs as $log):
                        [$cor, $icon] = $acaoCor[$log['acao']] ?? ['secondary', 'bi-circle'];
                        $statusLabels = ['comercial'=>'Ag. Comercial','financeiro'=>'Ag. Financeiro','faturado'=>'Ag. Faturamento','reprovado'=>'Reprovado'];
                    ?>
                    <li class="d-flex gap-3 mb-4">
                        <div class="log-dot bg-<?= $cor ?> bg-opacity-10 text-<?= $cor ?>">
                            <i class="bi <?= $icon ?>"></i>
                        </div>
                        <div class="flex-grow-1" style="margin-top:4px">
                            <div class="fw-semibold"><?= e($log['acao']) ?></div>
                            <?php if ($log['status_antes'] && $log['status_depois'] && $log['status_antes'] !== $log['status_depois']): ?>
                            <div class="small mt-1">
                                <span class="badge bg-secondary bg-opacity-25 text-dark"><?= e($statusLabels[$log['status_antes']] ?? $log['status_antes']) ?></span>
                                <i class="bi bi-arrow-right mx-1 text-muted"></i>
                                <span class="badge bg-<?= $cor ?> bg-opacity-15 text-<?= $cor ?>"><?= e($statusLabels[$log['status_depois']] ?? $log['status_depois']) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($log['detalhes'])): ?>
                            <div class="small text-muted mt-1 p-2 bg-light rounded"><?= e($log['detalhes']) ?></div>
                            <?php endif; ?>
                            <div class="small text-muted mt-1">
                                <i class="bi bi-person me-1"></i><?= e($log['usuario_nome']) ?>
                                <span class="badge bg-light text-dark border ms-1"><?= ucfirst($log['usuario_tipo']) ?></span>
                                <span class="ms-2"><i class="bi bi-clock me-1"></i><?= date('d/m/Y H:i', strtotime($log['created_at'])) ?></span>
                            </div>
                        </div>
                    </li>
                    <?php endforeach; ?>
                    </ul>
            <?php else: ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-clock-history display-5 d-block mb-2 opacity-25"></i>
                    Nenhuma alteração registrada ainda.
                </div>
            <?php endif; ?>
            </div>
            <div class="modal-footer border-top">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Coluna principal -->
    <div class="col-lg-8">

        <!-- Informações do pedido -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="bi bi-info-circle me-2 text-primary"></i>Informações</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-4">
                        <div class="text-muted small">Cliente</div>
                        <div class="fw-semibold"><?= e($pedido['razao_social'] ?? '—') ?></div>
                        <div class="text-muted small"><?= e($pedido['cliente_email'] ?? '') ?></div>
                    </div>
                    <div class="col-sm-2">
                        <div class="text-muted small">Canal de Venda</div>
                        <div class="fw-semibold"><?= e($pedido['canal_venda'] ?? '—') ?></div>
                    </div>
                    <div class="col-sm-3">
                        <div class="text-muted small">Tipo de Venda</div>
                        <div class="fw-semibold">
                            <span class="badge bg-<?= $pedido['tipo_venda'] === 'venda' ? 'primary' : 'info' ?> fs-6">
                                <?= ucfirst($pedido['tipo_venda']) ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-sm-3">
                        <div class="text-muted small">Data do Pedido</div>
                        <div class="fw-semibold"><?= dataBR($pedido['data_pedido']) ?></div>
                        <div class="text-muted small"><?= $pedido['created_at'] ? date('H:i', strtotime($pedido['created_at'])) : '' ?></div>
                    </div>
                    <?php if (!empty($pedido['vendedor'])): ?>
                    <div class="col-sm-6">
                        <div class="text-muted small">Vendedor</div>
                        <div class="fw-semibold"><?= e($pedido['vendedor']) ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="col-sm-<?= !empty($pedido['vendedor']) ? '6' : '12' ?>">
                        <div class="text-muted small"><?= $creditoUsadoAdmin > 0 ? 'Total a Pagar' : 'Valor Total' ?></div>
                        <div class="fw-bold fs-5 text-primary"><?= moedaBR(max(0, $valorTotalGeral - $creditoUsadoAdmin)) ?></div>
                        <?php if ($creditoUsadoAdmin > 0): ?>
                        <div class="text-muted small text-decoration-line-through"><?= moedaBR($valorTotalGeral) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($pedido['forma_pagamento'])): ?>
                    <div class="col-sm-6">
                        <div class="text-muted small">Forma de Pagamento</div>
                        <div class="fw-semibold"><i class="bi bi-credit-card-2-front me-1 text-primary"></i><?= e($pedido['forma_pagamento']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($creditoUsadoAdmin > 0): ?>
                    <div class="col-sm-<?= !empty($pedido['forma_pagamento']) ? '6' : '12' ?>">
                        <div class="text-muted small">Crédito Aplicado</div>
                        <div class="fw-semibold text-success"><i class="bi bi-coin me-1"></i><?= moedaBR($creditoUsadoAdmin) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($pedido['observacoes'])): ?>
                    <div class="col-<?= !empty($pedido['forma_pagamento']) ? 'sm-6' : '12' ?>">
                        <div class="text-muted small">Observações</div>
                        <div><?= e($pedido['observacoes']) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Itens do pedido -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-cart me-2 text-primary"></i>Itens do Pedido</h5>
                <?php if (count($itensPedido) > 1): ?>
                <span class="badge bg-primary"><?= count($itensPedido) ?> itens</span>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Produto</th>
                            <th>Código</th>
                            <?php if ($canEdit): ?><th class="text-center">Quantidade</th><?php endif; ?>
                            <th class="text-center">Múltiplo</th>
                            <th class="text-center">Quantidade Total</th>
                            <th class="text-end">Preço Unit.</th>
                            <th class="text-center">Desconto</th>
                            <th class="text-end pe-3">Total</th>
                            <?php if ($canEdit): ?><th></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($itensPedido as $item):
                            $descPct   = (float)($item['desconto_campanha'] ?? 0);
                            $qtd       = (int)$item['quantidade_total'];
                            $precoUnit = $qtd > 0 ? (float)$item['valor_total'] / $qtd : 0;
                        ?>
                        <tr>
                            <td class="ps-3 fw-semibold"><?= e($item['descricao_produto'] ?? '—') ?></td>
                            <td><code><?= e($item['codigo_produto'] ?? $item['codigo_barra'] ?? '—') ?></code></td>
                            <?php if ($canEdit): ?>
                            <td class="text-center">
                                <form method="POST">
                                    <input type="hidden" name="action" value="set_qtd">
                                    <input type="hidden" name="id"     value="<?= $item['id'] ?>">
                                    <?php $mult = max(1, (int)($item['multiplo'] ?: 1)); ?>
                                    <input type="number" name="qtd_total" min="1"
                                           value="<?= max(1, (int)round($qtd / $mult)) ?>"
                                           class="form-control form-control-sm text-center mx-auto"
                                           style="width:72px"
                                           onchange="if(parseInt(this.value)>0)this.closest('form').submit()">
                                </form>
                            </td>
                            <?php endif; ?>
                            <td class="text-center text-muted small"><?= !empty($item['multiplo']) ? (int)$item['multiplo'] : '—' ?></td>
                            <td class="text-center"><?= $qtd ?></td>
                            <td class="text-end"><?= $precoUnit > 0 ? moedaBR($precoUnit) : '—' ?></td>
                            <td class="text-center">
                                <?php if ($descPct > 0): ?>
                                <span class="badge bg-success">-<?= number_format($descPct, 2, ',', '.') ?>%</span>
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-semibold pe-3"><?= moedaBR($item['valor_total']) ?></td>
                            <?php if ($canEdit): ?>
                            <td class="text-center pe-2">
                                <form method="POST"
                                      onsubmit="return confirm('Remover <?= e(addslashes($item['descricao_produto'] ?? 'este item')) ?> do pedido?')">
                                    <input type="hidden" name="action" value="remover_item">
                                    <input type="hidden" name="id"     value="<?= $item['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Remover item">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <?php $cols = $canEdit ? 7 : 6; ?>
                        <tr>
                            <td colspan="<?= $cols ?>" class="text-end fw-semibold pe-3">Subtotal:</td>
                            <td class="fw-semibold text-end pe-3"><?= moedaBR($valorTotalGeral) ?></td>
                            <?php if ($canEdit): ?><td></td><?php endif; ?>
                        </tr>
                        <?php if ($creditoUsadoAdmin > 0): ?>
                        <tr class="text-success">
                            <td colspan="<?= $cols ?>" class="text-end fw-semibold pe-3"><i class="bi bi-coin me-1"></i>Crédito aplicado:</td>
                            <td class="fw-semibold text-end pe-3">− <?= moedaBR($creditoUsadoAdmin) ?></td>
                            <?php if ($canEdit): ?><td></td><?php endif; ?>
                        </tr>
                        <tr>
                            <td colspan="<?= $cols ?>" class="text-end fw-bold pe-3">Total a Pagar:</td>
                            <td class="fw-bold text-primary fs-5 text-end pe-3"><?= moedaBR(max(0, $valorTotalGeral - $creditoUsadoAdmin)) ?></td>
                            <?php if ($canEdit): ?><td></td><?php endif; ?>
                        </tr>
                        <?php else: ?>
                        <tr>
                            <td colspan="<?= $cols ?>" class="text-end fw-bold pe-3">Total Geral:</td>
                            <td class="fw-bold text-primary fs-5 text-end pe-3"><?= moedaBR($valorTotalGeral) ?></td>
                            <?php if ($canEdit): ?><td></td><?php endif; ?>
                        </tr>
                        <?php endif; ?>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Adicionar / Editar item (Comercial, status aguardando) -->
        <?php if ($canEdit): ?>
        <div class="card border-0 shadow-sm border-start border-warning border-4" id="ep_card">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0" id="ep_card_title"><i class="bi bi-plus-circle me-2 text-warning"></i>Adicionar Produto</h5>
                <button type="button" id="ep_cancelar_btn" class="btn btn-sm btn-outline-secondary d-none"
                        onclick="epResetForm()">
                    <i class="bi bi-x-lg me-1"></i>Cancelar edição
                </button>
            </div>
            <div class="card-body">
                <form method="POST" id="ep_form" class="row g-3">
                    <input type="hidden" name="id"     id="ep_form_id"     value="<?= $pedido['id'] ?>">
                    <input type="hidden" name="action" id="ep_form_action" value="adicionar">
                    <input type="hidden" name="produto_id" id="ep_produto_id" value="">

                    <!-- Busca de produto -->
                    <div class="col-12">
                        <label class="form-label fw-semibold">Produto <span class="text-danger">*</span></label>
                        <div class="position-relative">
                            <input type="text" id="ep_busca" class="form-control" autocomplete="off"
                                   placeholder="Digite código ou descrição…" value="">
                            <div id="ep_dropdown"
                                 class="position-absolute start-0 end-0 bg-white border rounded shadow-sm"
                                 style="top:100%;z-index:600;max-height:220px;overflow-y:auto;display:none"></div>
                        </div>
                        <div id="ep_produto_info" class="text-muted small mt-1"></div>
                    </div>

                    <!-- Tipo | Qtd | Múltiplo | Qtd.Total | Valor Unit. | Estimado -->
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Tipo de Venda</label>
                        <select name="tipo_venda" id="ep_tipo" class="form-select">
                            <option value="venda"       <?= $pedido['tipo_venda'] === 'venda'       ? 'selected' : '' ?>>Venda</option>
                            <option value="bonificacao" <?= $pedido['tipo_venda'] === 'bonificacao' ? 'selected' : '' ?>>Bonificação</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Quantidade <span class="text-danger">*</span></label>
                        <input type="number" name="quantidade_total" id="ep_qtd" class="form-control"
                               min="1" value="" placeholder="0" required>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label fw-semibold">Múltiplo</label>
                        <input type="text" id="ep_multiplo" class="form-control bg-light text-center fw-semibold"
                               readonly value="<?= (int)($pedido['multiplo'] ?? 1) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Qtd. Total</label>
                        <input type="text" id="ep_qtd_total" class="form-control bg-light text-center fw-bold text-primary"
                               readonly value="—">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Valor Unit.</label>
                        <input type="text" id="ep_preco_unit" class="form-control bg-light fw-semibold text-primary"
                               readonly value="<?= $pedido['preco_unit'] > 0 ? 'R$ ' . number_format((float)$pedido['preco_unit'], 2, ',', '.') : '—' ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Valor Estimado</label>
                        <div class="form-control bg-light fw-bold text-success" id="ep_valor_preview" style="cursor:default; min-height:38px">—</div>
                        <div id="ep_camp_info" class="text-muted" style="font-size:.72rem"></div>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">Observações</label>
                        <textarea name="observacoes" class="form-control" rows="2"><?= e($pedido['observacoes'] ?? '') ?></textarea>
                    </div>
                    <div class="col-12">
                        <button type="submit" id="ep_submit_btn" class="btn btn-warning px-4">
                            <i class="bi bi-plus-circle me-1"></i>Adicionar Produto
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <script>
        (function () {
            var _prods = <?= json_encode(array_map(function($p) {
                return [
                    'id'       => (int)$p['id'],
                    'label'    => '[' . $p['codigo_produto'] . '] ' . $p['descricao_pt'],
                    'codigo'   => $p['codigo_produto'],
                    'descricao'=> $p['descricao_pt'],
                    'preco'    => (float)$p['preco'],
                    'multiplo' => (int)($p['multiplo'] ?? 1),
                    'linha'    => trim($p['linha']    ?? ''),
                    'grupo'    => trim($p['grupo']    ?? ''),
                    'subgrupo' => trim($p['subgrupo'] ?? ''),
                ];
            }, $produtos), JSON_UNESCAPED_UNICODE) ?>;

            var _camps = <?= json_encode(array_map(function($c) {
                return [
                    'produto_id'   => $c['produto_id'] ? (int)$c['produto_id'] : null,
                    'linha'        => trim($c['linha']    ?? ''),
                    'grupo'        => trim($c['grupo']    ?? ''),
                    'subgrupo'     => trim($c['subgrupo'] ?? ''),
                    'quantidade'   => (int)$c['quantidade'],
                    'desconto'     => (float)$c['desconto'],
                    'codigo'       => $c['codigo_campanha'] ?? '',
                ];
            }, $campanhas_edit), JSON_UNESCAPED_UNICODE) ?>;

            var dCliente = <?= (float)($pedido['desconto_cliente'] ?? 0) ?>;
            var dCanal   = <?= (float)($pedido['desconto_canal']   ?? 0) ?>;

            var elBusca    = document.getElementById('ep_busca');
            var elDrop     = document.getElementById('ep_dropdown');
            var elId       = document.getElementById('ep_produto_id');
            var elMultiplo = document.getElementById('ep_multiplo');
            var elQtdTotal = document.getElementById('ep_qtd_total');
            var elUnit     = document.getElementById('ep_preco_unit');
            var elPreview  = document.getElementById('ep_valor_preview');
            var elCampInfo = document.getElementById('ep_camp_info');
            var elQtd      = document.getElementById('ep_qtd');
            var elTipo     = document.getElementById('ep_tipo');

            var _prodAtual = null;

            function fmt(v) {
                return 'R$ ' + v.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            }

            function melhorCampanha(prod, qtd) {
                var best = null;
                _camps.forEach(function(c) {
                    if (c.quantidade > 0 && qtd < c.quantidade) return;
                    if (c.produto_id !== null && c.produto_id !== prod.id) return;
                    if (c.produto_id === null) {
                        if (c.linha    && c.linha.toLowerCase()    !== (prod.linha    || '').toLowerCase()) return;
                        if (c.grupo    && c.grupo.toLowerCase()    !== (prod.grupo    || '').toLowerCase()) return;
                        if (c.subgrupo && c.subgrupo.toLowerCase() !== (prod.subgrupo || '').toLowerCase()) return;
                    }
                    if (!best || c.desconto > best.desconto) best = c;
                });
                return best;
            }

            function atualizarPreview() {
                if (!_prodAtual) {
                    elPreview.textContent = '—'; elCampInfo.textContent = '';
                    elQtdTotal.value = '—'; return;
                }
                var pacotes  = parseInt(elQtd.value) || 0;
                var multiplo = _prodAtual.multiplo || 1;
                var qtd      = pacotes * multiplo;
                elQtdTotal.value = qtd > 0 ? qtd : '—';
                var tipo = elTipo.value;
                if (tipo === 'bonificacao' || pacotes <= 0) {
                    elPreview.textContent = tipo === 'bonificacao' ? fmt(0) : '—';
                    elCampInfo.textContent = '';
                    return;
                }
                var camp  = melhorCampanha(_prodAtual, qtd);
                var dCamp = camp ? camp.desconto : 0;
                var total = _prodAtual.preco * qtd
                          * (1 - dCliente / 100)
                          * (1 - dCanal   / 100)
                          * (1 - dCamp    / 100);
                elPreview.textContent = fmt(total);

                var partes = [];
                if (dCliente > 0) partes.push('Cliente ' + dCliente.toFixed(2).replace('.', ',') + '%');
                if (dCanal   > 0) partes.push('Canal '   + dCanal.toFixed(2).replace('.', ',')   + '%');
                if (dCamp    > 0) partes.push('Campanha ' + camp.codigo + ' ' + dCamp.toFixed(2).replace('.', ',') + '%');
                elCampInfo.textContent = partes.length ? 'Descontos: ' + partes.join(' + ') : 'Sem desconto';
            }

            function selecionarProduto(p) {
                _prodAtual       = p;
                elId.value       = p.id;
                elBusca.value    = p.label;
                elMultiplo.value = p.multiplo;
                elUnit.value     = fmt(p.preco);
                elDrop.style.display = 'none';
                atualizarPreview();
            }

            function renderDrop(lista) {
                if (!lista.length) { elDrop.style.display = 'none'; return; }
                elDrop.innerHTML = lista.slice(0, 80).map(function(p) {
                    return '<div class="px-3 py-2 ep-item" style="cursor:pointer;font-size:.875rem" data-id="' + p.id + '">' +
                        '<span class="text-muted me-2" style="font-size:.8rem">' + p.codigo + '</span>' +
                        p.descricao + '</div>';
                }).join('');
                elDrop.style.display = 'block';
                elDrop.querySelectorAll('.ep-item').forEach(function(el) {
                    el.addEventListener('mousedown', function(e) {
                        e.preventDefault();
                        var id = parseInt(el.getAttribute('data-id'));
                        selecionarProduto(_prods.find(function(p) { return p.id === id; }));
                    });
                    el.addEventListener('mouseenter', function() { el.style.background = '#f0f4f1'; });
                    el.addEventListener('mouseleave', function() { el.style.background = ''; });
                });
            }

            elBusca.addEventListener('input', function() {
                var q = elBusca.value.trim().toLowerCase();
                if (q.length < 1) { elDrop.style.display = 'none'; return; }
                renderDrop(_prods.filter(function(p) { return p.label.toLowerCase().includes(q); }));
            });
            elBusca.addEventListener('focus', function() {
                var q = elBusca.value.trim().toLowerCase();
                if (q.length >= 1) renderDrop(_prods.filter(function(p) { return p.label.toLowerCase().includes(q); }));
            });
            elBusca.addEventListener('blur', function() {
                setTimeout(function() { elDrop.style.display = 'none'; }, 150);
            });

            elQtd.addEventListener('input',   atualizarPreview);
            elTipo.addEventListener('change',  atualizarPreview);

            var _pedidoBaseId = <?= $pedidoId ?>;

            // Reseta para modo Adicionar
            function epResetForm() {
                document.getElementById('ep_form_id').value     = _pedidoBaseId;
                document.getElementById('ep_form_action').value = 'adicionar';
                document.getElementById('ep_card_title').innerHTML =
                    '<i class="bi bi-plus-circle me-2 text-warning"></i>Adicionar Produto';
                document.getElementById('ep_submit_btn').innerHTML =
                    '<i class="bi bi-plus-circle me-1"></i>Adicionar Produto';
                document.getElementById('ep_cancelar_btn').classList.add('d-none');
                elBusca.value = '';
                elId.value    = '';
                elQtd.value   = '';
                elTipo.value  = 'venda';
                elMultiplo.value  = '—';
                elQtdTotal.value  = '—';
                elUnit.value      = '—';
                elPreview.textContent  = '—';
                elCampInfo.textContent = '';
                _prodAtual = null;
            }

            // Muda para modo Editar item específico
            window.epEditarItem = function(itemId, prodId, label, qtd, tipo, obs) {
                document.getElementById('ep_form_id').value     = itemId;
                document.getElementById('ep_form_action').value = 'editar';
                document.getElementById('ep_card_title').innerHTML =
                    '<i class="bi bi-pencil-square me-2 text-warning"></i>Editando Item';
                document.getElementById('ep_submit_btn').innerHTML =
                    '<i class="bi bi-save me-1"></i>Salvar Alterações';
                document.getElementById('ep_cancelar_btn').classList.remove('d-none');
                elBusca.value = label;
                elId.value    = prodId;
                elQtd.value   = qtd;
                elTipo.value  = tipo;
                var obs_el = document.querySelector('#ep_form textarea[name="observacoes"]');
                if (obs_el) obs_el.value = obs;
                _prodAtual = _prods.find(function(p) { return p.id === prodId; });
                if (_prodAtual) {
                    elUnit.value     = fmt(_prodAtual.preco);
                    elMultiplo.value = _prodAtual.multiplo;
                }
                atualizarPreview();
                document.getElementById('ep_card').scrollIntoView({ behavior: 'smooth', block: 'start' });
            };
        }());
        </script>
        <?php endif; ?>
    </div>

    <!-- Coluna de ações -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm sticky-top" style="top:1rem">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="bi bi-gear me-2 text-primary"></i>Ações</h5>
            </div>
            <div class="card-body d-grid gap-2">

                <?php if ($canAprovar): ?>
                <form method="POST"
                      onsubmit="return confirm('Confirmar aprovação do pedido <?= e($pedido['numero_pedido']) ?>?')">
                    <input type="hidden" name="action" value="aprovar">
                    <input type="hidden" name="id" value="<?= $pedido['id'] ?>">
                    <button class="btn btn-success w-100">
                        <i class="bi bi-check-circle me-1"></i>
                        <?= $isFinanceiro ? 'Aprovar' : 'Aprovar → Financeiro' ?>
                    </button>
                </form>
                <?php endif; ?>

                <?php if ($canRetornar): ?>
                <form method="POST"
                      onsubmit="return confirm('Retornar pedido ao Comercial?')">
                    <input type="hidden" name="action" value="retornar">
                    <input type="hidden" name="id" value="<?= $pedido['id'] ?>">
                    <button class="btn btn-outline-warning w-100">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Retornar ao Comercial
                    </button>
                </form>
                <?php endif; ?>

                <?php if ($canReprovar): ?>
                <form method="POST"
                      onsubmit="return confirm('Reprovar o pedido <?= e($pedido['numero_pedido']) ?>?')">
                    <input type="hidden" name="action" value="reprovar">
                    <input type="hidden" name="id" value="<?= $pedido['id'] ?>">
                    <button class="btn btn-danger w-100">
                        <i class="bi bi-x-circle me-1"></i>Reprovar
                    </button>
                </form>
                <?php endif; ?>

                <?php if (!$canAprovar && !$canReprovar && !$canRetornar): ?>
                <div class="text-center text-muted py-3">
                    <i class="bi bi-lock display-6 d-block mb-2"></i>
                    Nenhuma ação disponível para este pedido.
                </div>
                <?php endif; ?>
            </div>

            <div class="card-footer bg-light">
                <small class="text-muted">
                    <strong>Criado em:</strong><br>
                    <?= $pedido['created_at'] ? date('d/m/Y H:i', strtotime($pedido['created_at'])) : dataBR($pedido['data_pedido']) ?>
                </small>
            </div>
        </div>
    </div>
</div>

<?php require_once LAYOUT_PATH . '/footer.php'; ?>
