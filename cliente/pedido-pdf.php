<?php
require_once __DIR__ . '/../config.php';
requireCliente();
$u = usuario();

$ids = $_SESSION['pdf_pedidos_ids'] ?? [];
unset($_SESSION['pdf_pedidos_ids']);

if (empty($ids)) {
    header('Location: ' . BASE_URL . '/cliente/meus-pedidos.php'); exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = db()->prepare(
    "SELECT p.*, pr.linha, pr.codigo_produto,
            COALESCE(t.preco_padrao, pr.vendas_varejo, 0) AS preco_padrao
     FROM pedidos p
     LEFT JOIN produtos pr ON pr.id = p.produto_id
     LEFT JOIN tabela_precos t ON t.produto_id = p.produto_id
     WHERE p.id IN ($placeholders) AND p.cliente_id = ?
     ORDER BY pr.linha, p.descricao_produto"
);
$stmt->execute(array_merge($ids, [$u['id']]));
$pedidos = $stmt->fetchAll();

if (empty($pedidos)) {
    header('Location: ' . BASE_URL . '/cliente/meus-pedidos.php'); exit;
}

$cli = db()->prepare('SELECT c.*, cv.canal AS canal_venda FROM clientes c LEFT JOIN canal_venda cv ON cv.id = c.canal_venda_id WHERE c.id = ?');
$cli->execute([$u['id']]);
$cli = $cli->fetch();

$total_geral     = array_sum(array_column($pedidos, 'valor_total'));
$data_pedido     = $pedidos[0]['data_pedido'];
$supervisor      = $pedidos[0]['supervisor'] ?? $pedidos[0]['vendedor'];
$observacoes     = $pedidos[0]['observacoes'];
$forma_pagamento = $pedidos[0]['forma_pagamento'] ?? '';
$tipo_venda      = ($pedidos[0]['tipo_venda'] ?? 'venda') === 'bonificacao' ? 'Bonificação' : 'Venda';

// Crédito utilizado (gravado no primeiro item do lote)
$credito_utilizado = 0.0;
foreach ($pedidos as $p) {
    if ((float)($p['credito_utilizado'] ?? 0) > 0) {
        $credito_utilizado = (float)$p['credito_utilizado'];
        break;
    }
}


// agrupa por linha
$porLinha = [];
foreach ($pedidos as $p) {
    $linha = trim($p['linha'] ?? '');
    $linha = $linha !== '' ? $linha : 'Outros';
    $porLinha[$linha][] = $p;
}
ksort($porLinha);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirmação de Pedido — <?= date('d/m/Y', strtotime($data_pedido)) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --verde: #004733; }
        body { font-family: Arial, sans-serif; font-size: 13px; background: #f5f5f5; }

        .pdf-wrapper {
            max-width: 900px;
            margin: 30px auto;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 6px;
            overflow: hidden;
        }

        .pdf-header {
            background: var(--verde);
            color: #fff;
            padding: 24px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .pdf-header h1 { font-size: 1.4rem; margin: 0; font-weight: 700; }
        .pdf-header .subtitle { font-size: .85rem; opacity: .85; margin-top: 4px; }
        .pdf-header .doc-num { text-align: right; }
        .pdf-header .doc-num span { font-size: 1.1rem; font-weight: 700; letter-spacing: .5px; }

        .pdf-body { padding: 28px 32px; }

        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px; }
        .info-box { border: 1px solid #e0e0e0; border-radius: 4px; padding: 12px 14px; }
        .info-box label { font-size: .72rem; text-transform: uppercase; color: #888; letter-spacing: .4px; display: block; margin-bottom: 3px; }
        .info-box span { font-weight: 600; color: #222; }

        .section-title {
            font-size: .75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--verde);
            border-bottom: 2px solid var(--verde);
            padding-bottom: 4px;
            margin-bottom: 12px;
        }

        table { width: 100%; border-collapse: collapse; font-size: 12.5px; margin-bottom: 20px; }
        thead th { background: var(--verde); color: #fff; padding: 7px 10px; text-align: left; font-weight: 600; }
        thead th.text-end { text-align: right; }
        thead th.text-center { text-align: center; }
        tbody tr:nth-child(even) { background: #f9f9f9; }
        tbody td { padding: 6px 10px; border-bottom: 1px solid #eee; vertical-align: middle; }
        tbody td.text-end { text-align: right; }
        tbody td.text-center { text-align: center; }
        tfoot td { padding: 7px 10px; font-weight: 700; background: #f0f5f2; border-top: 2px solid var(--verde); }
        tfoot td.text-end { text-align: right; }

        .linha-header {
            background: #e8f0ec;
            padding: 6px 10px;
            font-weight: 700;
            font-size: .8rem;
            color: var(--verde);
            border-left: 4px solid var(--verde);
            margin-bottom: 0;
        }

        .total-geral {
            background: var(--verde);
            color: #fff;
            padding: 12px 16px;
            border-radius: 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 1.05rem;
            font-weight: 700;
            margin-top: 4px;
        }

        .obs-box {
            background: #fffbea;
            border: 1px solid #f0e68c;
            border-radius: 4px;
            padding: 10px 14px;
            font-size: 12.5px;
            margin-top: 16px;
        }

        .badge-status {
            display: inline-block;
            background: #0d6efd;
            color: #fff;
            border-radius: 20px;
            padding: 3px 10px;
            font-size: .75rem;
            font-weight: 600;
        }

        .pdf-footer {
            border-top: 1px solid #e0e0e0;
            padding: 14px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: .78rem;
            color: #888;
        }

        /* Barra de ações — oculta na impressão */
        .action-bar {
            max-width: 900px;
            margin: 0 auto 14px auto;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        @media print {
            body { background: #fff; }
            .action-bar { display: none !important; }
            .pdf-wrapper { margin: 0; border: none; border-radius: 0; box-shadow: none; }
            @page { margin: 12mm 14mm; size: A4; }
        }
    </style>
</head>
<body>

<div class="action-bar mt-3">
    <a href="<?= BASE_URL ?>/cliente/meus-pedidos.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-list-ul me-1"></i>Meus Pedidos
    </a>
    <button onclick="window.print()" class="btn btn-success btn-sm">
        <i class="bi bi-file-earmark-pdf me-1"></i>Salvar / Imprimir PDF
    </button>
</div>

<div class="pdf-wrapper">

    <!-- Cabeçalho -->
    <div class="pdf-header">
        <div>
            <h1>Itallian Hairtech</h1>
            <div class="subtitle">Confirmação de Pedido</div>
            <div class="mt-2">
                <span class="badge-status" style="background:rgba(255,255,255,.25);color:#fff;border:1px solid rgba(255,255,255,.5)">
                    Aguardando Comercial
                </span>
                <span class="badge-status" style="background:rgba(255,255,255,.25);color:#fff;border:1px solid rgba(255,255,255,.5)">
                    <i class="bi bi-tag me-1"></i><?= $tipo_venda ?>
                </span>
            </div>
        </div>
        <div class="doc-num">
            <div style="font-size:.8rem;opacity:.8">Data do Pedido</div>
            <span><?= date('d/m/Y', strtotime($data_pedido)) ?></span>
            <?php if ($supervisor): ?>
            <div style="font-size:.8rem;opacity:.8;margin-top:8px">Supervisor</div>
            <span style="font-size:.95rem"><?= e($supervisor) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="pdf-body">

        <!-- Informações do Cliente -->
        <div class="section-title"><i class="bi bi-person me-1"></i>Dados do Cliente</div>
        <div class="info-grid" style="margin-bottom:24px">
            <div class="info-box">
                <label>Razão Social</label>
                <span><?= e($cli['razao_social'] ?? '—') ?></span>
            </div>
            <div class="info-box">
                <label>CNPJ / CPF</label>
                <span><?= e($cli['cnpj'] ?? $cli['cpf'] ?? '—') ?></span>
            </div>
            <div class="info-box">
                <label>Cidade / Estado</label>
                <span><?= e(($cli['cidade'] ?? '') . ($cli['estado'] ? ' — ' . $cli['estado'] : '')) ?></span>
            </div>
            <div class="info-box">
                <label>Canal de Venda</label>
                <span><?= e($cli['canal_venda'] ?? '—') ?></span>
            </div>
        </div>

        <!-- Itens do Pedido -->
        <div class="section-title"><i class="bi bi-cart3 me-1"></i>Itens do Pedido</div>

        <?php foreach ($porLinha as $linha => $itens):
            $subtotal_linha = array_sum(array_column($itens, 'valor_total'));
        ?>
        <div class="linha-header"><?= e($linha) ?></div>
        <table>
            <thead>
                <tr>
                    <th>Produto</th>
                    <th>Cód. Produto</th>
                    <th>Cód. Barras</th>
                    <th class="text-center">Qtd.</th>
                    <th class="text-end">Preço Unit.</th>
                    <th class="text-center">Desconto</th>
                    <th class="text-end">Valor Total</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($itens as $item):
                $qtd       = (int)$item['quantidade_total'];
                $precoUnit = $qtd > 0 ? (float)$item['valor_total'] / $qtd : 0;
                $descPct   = (float)($item['desconto_campanha'] ?? 0);
            ?>
            <tr>
                <td><?= e($item['descricao_produto']) ?></td>
                <td style="color:#666"><?= e($item['codigo_produto'] ?? '—') ?></td>
                <td style="color:#666"><?= e($item['codigo_barra'] ?: '—') ?></td>
                <td class="text-center"><?= $qtd ?></td>
                <td class="text-end"><?= moedaBR($precoUnit) ?></td>
                <td class="text-center">
                    <?php if ($descPct > 0): ?>
                    <span style="background:#198754;color:#fff;border-radius:12px;padding:2px 8px;font-size:11px;font-weight:600">
                        -<?= number_format($descPct, 2, ',', '.') ?>%
                    </span>
                    <?php else: ?>
                    <span style="color:#aaa">—</span>
                    <?php endif; ?>
                </td>
                <td class="text-end" style="color:#004733;font-weight:600"><?= moedaBR($item['valor_total']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="6" class="text-end">Subtotal <?= e($linha) ?></td>
                    <td class="text-end"><?= moedaBR($subtotal_linha) ?></td>
                </tr>
            </tfoot>
        </table>
        <?php endforeach; ?>

        <!-- Total Geral -->
        <?php if ($credito_utilizado > 0): ?>
        <table style="width:100%;border-collapse:collapse;margin-bottom:0">
            <tr>
                <td style="text-align:right;padding:6px 8px;color:#555">Subtotal:</td>
                <td style="text-align:right;padding:6px 8px;min-width:110px;color:#555"><?= moedaBR($total_geral) ?></td>
            </tr>
            <tr>
                <td style="text-align:right;padding:4px 8px;color:#198754">Crédito aplicado:</td>
                <td style="text-align:right;padding:4px 8px;color:#198754;font-weight:600">− <?= moedaBR($credito_utilizado) ?></td>
            </tr>
        </table>
        <?php endif; ?>
        <div class="total-geral">
            <span><?= $credito_utilizado > 0 ? 'Total a Pagar' : 'Total Geral do Pedido' ?></span>
            <span><?= moedaBR(max(0, $total_geral - $credito_utilizado)) ?></span>
        </div>

        <?php if (trim($forma_pagamento ?? '')): ?>
        <div class="obs-box mt-3">
            <strong><i class="bi bi-credit-card-2-front me-1"></i>Forma de Pagamento:</strong>
            <?= e($forma_pagamento) ?>
        </div>
        <?php endif; ?>

        <?php if (trim($observacoes ?? '')): ?>
        <div class="obs-box mt-3">
            <strong><i class="bi bi-chat-left-text me-1"></i>Observações:</strong>
            <?= e($observacoes) ?>
        </div>
        <?php endif; ?>

    </div><!-- /pdf-body -->

    <div class="pdf-footer">
        <span>Itallian Hairtech &mdash; Sistema de Pedidos</span>
        <span>Gerado em <?= date('d/m/Y H:i') ?></span>
    </div>

</div><!-- /pdf-wrapper -->

<script>
window.addEventListener('load', function () { window.print(); });
</script>
</body>
</html>
