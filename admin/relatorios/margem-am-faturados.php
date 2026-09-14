<?php
require_once __DIR__ . '/../../config.php';
requireComercial();

// AJAX: detalhe "Margem" de um pedido — o mesmo modal da tela do pedido (admin/partials/margem-waterfall.php).
// Recalcula a partir dos dados do A&M já lidos na busca, enviados pela própria página: não consulta o
// A&M de novo e não grava nada.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax_margem'])) {
    header('Content-Type: text/html; charset=utf-8');
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in) || empty($in['itens']) || !is_array($in['itens'])) {
        http_response_code(400);
        echo '<div class="alert alert-danger small">Dados do pedido inválidos.</div>';
        exit;
    }
    $acc = $in['pedido_accademia'] ?? null;
    $p = [
        'numero'            => preg_replace('/\D/', '', (string)($in['numero'] ?? '')),
        'cnpj'              => (string)($in['cnpj'] ?? ''),
        'uf'                => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($in['uf'] ?? '')), 0, 2)),
        'data'              => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['data'] ?? '')) ? $in['data'] : null,
        'pedido_accademia'  => in_array($acc, ['SIM', 'NAO'], true) ? $acc : null,
        'is_a_vista'        => !empty($in['is_a_vista']),
        'credito_utilizado' => max(0.0, (float)($in['credito_utilizado'] ?? 0)),
        'itens'             => [],
    ];
    foreach ($in['itens'] as $it) {
        if (!is_array($it)) continue;
        $p['itens'][] = [
            'codigo'         => (string)($it['codigo'] ?? ''),
            'nome'           => (string)($it['nome'] ?? ''),
            'qtd'            => (int)($it['qtd'] ?? 0),
            'pct_descto'     => (float)($it['pct_descto'] ?? 0),
            'pct_descto_st'  => (float)($it['pct_descto_st'] ?? 0),
            'pct_negociacao' => (float)($it['pct_negociacao'] ?? 0),
            'pct_diretoria'  => (float)($it['pct_diretoria'] ?? 0),
            'valor_total'    => (float)($it['valor_total'] ?? 0),
            'preco_tabela'   => (float)($it['preco_tabela'] ?? 0),
        ];
    }
    $m = calcularMargemPedidoAEM($p);
    if ($m['nao_mapeados']) {
        echo '<div class="alert alert-warning small py-2"><i class="bi bi-exclamation-triangle me-1"></i>Sem cadastro ativo no SisPed (fora do cálculo): '
           . e(implode('; ', $m['nao_mapeados'])) . '</div>';
    }
    if (($_GET['modo'] ?? '') === 'compacto') {
        // Visualização compacta (a 1ª versão do detalhe): uma linha por item com o waterfall resumido.
        $pctFmt = fn($v) => rtrim(rtrim(number_format((float)$v, 2, ',', '.'), '0'), ',') . '%';
        ?>
<div class="table-responsive">
<table class="table table-sm table-hover align-middle mb-0" style="font-size:.8rem">
    <thead class="table-light"><tr>
        <th>Código</th><th>Produto</th><th class="text-end">Qtd</th>
        <th class="text-end">Valor Tabela</th><th class="text-end">Descontos</th><th class="text-end">Crédito</th>
        <th class="text-end">Impostos</th><th class="text-end">Custo MP</th><th class="text-end">Despesas</th><th class="text-end">Margem</th>
    </tr></thead>
    <tbody>
    <?php foreach ($m['impItens'] as $it):
        $q    = (int)$it['qtd'];
        $vTab = $it['precoPadrao'] * $q;
        $vDes = ($it['vCanal'] + $it['vCliente'] + $it['vPedido'] + $it['vCampanha']) * $q;
        $vCre = $it['vCredito'] * $q;
        $vImp = ($it['netTotal'] + array_sum(array_column($it['blocosOutros'], 'total'))) * $q;
        $vMP  = $it['custoMP'] * $q;
        $vDsp = ($it['vCF'] + $it['vDescFinanceiro']) * $q;
        $vRes = $it['resultadoIni'] * $q;
        $pRes = $vTab > 0 ? $vRes / $vTab * 100 : 0;
    ?>
        <tr>
            <td><?= e($it['codigo']) ?></td>
            <td class="text-truncate" style="max-width:260px" title="<?= e($it['descricao']) ?>"><?= e($it['descricao']) ?></td>
            <td class="text-end"><?= $q ?></td>
            <td class="text-end"><?= moedaBR($vTab) ?></td>
            <td class="text-end text-danger"><?= $vDes ? '− ' . moedaBR($vDes) : '—' ?></td>
            <td class="text-end text-danger"><?= $vCre ? '− ' . moedaBR($vCre) : '—' ?></td>
            <td class="text-end text-danger">− <?= moedaBR($vImp) ?></td>
            <td class="text-end text-danger">
                <?= $vMP ? '− ' . moedaBR($vMP) : '—' ?>
                <?php if (!$it['custoMPAchado']): ?><i class="bi bi-exclamation-circle text-warning" title="Sem custo cadastrado para a competência do pedido"></i><?php endif; ?>
            </td>
            <td class="text-end text-danger"><?= $vDsp ? '− ' . moedaBR($vDsp) : '—' ?></td>
            <td class="text-end fw-semibold text-<?= $pRes < 0 ? 'danger' : 'success' ?>"><?= moedaBR($vRes) ?> <span class="text-muted fw-normal">(<?= $pctFmt($pRes) ?>)</span></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php
        exit;
    }
    extract($m);
    require __DIR__ . '/../partials/margem-waterfall.php';
    exit;
}

// Versão "Pedidos faturados" do relatório margem-am.php: em vez dos pedidos importados no SisPed,
// busca na hora, no A&M, os pedidos "FC - Faturado" (Vendas > Consulta/Reimprime Pedidos do grupo
// Faturados — período pela data do faturamento, valor e itens faturados) e, se marcadas, as demais
// situações (Vendas > Consulta/Reimprime), e roda o mesmo cálculo de margem sobre eles. Pedido com
// divergência entre valores e percentuais do A&M fica fora do cálculo, listado no topo. Nada é gravado.
$ini = $_GET['ini'] ?? date('Y-m-01');
$fim = $_GET['fim'] ?? date('Y-m-t');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ini)) $ini = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fim)) $fim = date('Y-m-t');
$cliente = trim((string)($_GET['cliente'] ?? ''));
$bf = in_array($_GET['bf'] ?? '', ['1', '0'], true) ? $_GET['bf'] : '';
// Situação do pedido no A&M (coluna "Situação" do grid Consulta/Reimprime). Nenhuma marcada = só FC.
$situacoesOpc = [
    'FC' => 'FC - Faturado',
    'AB' => 'AB - Liberado para o faturamento',
    'AC' => 'AC - Liberado para o financeiro',
    'SA' => 'SA - Bloqueado',
];
$situacoes = array_values(array_intersect(array_keys($situacoesOpc), (array)($_GET['sit'] ?? [])));
if (!$situacoes) $situacoes = ['FC'];
$buscar = ($_GET['acao'] ?? '') === 'buscar';

$erro = null;
$linhas = [];
$falhas = [];          // pedidos cujo detalhe (PD0303/PD0303F) não pôde ser lido
$divergentes = [];     // pedidos com valores do A&M que não fecham com os percentuais — fora do cálculo
$comSemCadastro = 0;   // pedidos com produto(s) sem cadastro no SisPed
$tot = ['produtos'=>0,'descontos'=>0,'credito'=>0,'impostos'=>0,'mp'=>0,'despesas'=>0,'margem'=>0,
        'canal'=>0,'cliente'=>0,'comercial'=>0,'campanha'=>0,'financeiro'=>0];
// Visões agregadas (abas "Por vendedor" / "Por produto") — mesmas colunas do waterfall.
$porVend = [];         // supervisor (coluna VendPed do A&M) => acumulado
$porProd = [];         // código do produto => acumulado (só itens com cadastro no SisPed)
$novoAcum = fn() => ['pedidos'=>0,'clientes'=>[],'qtd'=>0,'produtos'=>0,'descontos'=>0,'credito'=>0,
                     'impostos'=>0,'mp'=>0,'despesas'=>0,'margem'=>0,
                     'camp_qtd'=>0,'campanhas'=>[]];   // por produto: qtd vendida com campanha + nome => [% mín, % máx]
$somaAcum = function (array &$a, array $v) {
    foreach (['produtos','descontos','credito','impostos','mp','despesas','margem'] as $k) $a[$k] += $v[$k];
};
$visao = in_array($_GET['visao'] ?? '', ['pedido', 'vendedor', 'produto'], true) ? $_GET['visao'] : 'pedido';

if ($buscar) {
    $dias = (strtotime($fim) - strtotime($ini)) / 86400;
    if ($dias < 0) {
        $erro = 'A data final deve ser igual ou posterior à data inicial.';
    } elseif (strtotime($fim) >= strtotime($ini . ' +1 month')) {
        $erro = 'Período máximo de 1 mês por consulta (o detalhe de cada pedido é lido no A&M, um a um).';
    } else {
        set_time_limit(300);
        $r = pedidosFaturadosAEM($ini, $fim, ['cliente' => $cliente, 'bf' => $bf, 'situacoes' => $situacoes]);
        if (!$r['ok']) $erro = $r['erro'] ?: 'Falha ao consultar o A&M.';
    }
}

if ($buscar && !$erro) {
    // Pedido do A&M que também foi importado no SisPed (mesmo "Pedido Nº") — só para o link "Abrir pedido".
    $locais = [];
    foreach (db()->query("SELECT MIN(id) AS id, observacoes FROM pedidos
                          WHERE observacoes LIKE 'Importado do sistema A&M%' GROUP BY observacoes") as $row) {
        if (preg_match('/Pedido N[ºo°]\s*(\d+)/u', (string)$row['observacoes'], $mm)) $locais[$mm[1]] = (int)$row['id'];
    }

    foreach ($r['pedidos'] as $p) {
        if (!empty($p['erro'])) { $falhas[] = $p; continue; }
        if (!empty($p['divergencias'])) { $divergentes[] = $p; continue; }

        $m = calcularMargemPedidoAEM($p);

        $produtos  = (float)$m['impTotalBase'];
        $descontos = -(float)$m['impDeltaDescontos'];
        $credito   = -(float)$m['impDeltaCredito'];
        $impostos  = -((float)$m['impDeltaNet'] + (float)$m['impDeltaImpostos']);
        $mp        = -(float)$m['impDeltaMP'];
        $despesas  = -(float)$m['impDeltaDespesas'];
        $margem    = (float)$m['impTotalFinal'];
        $margemPct = (float)$m['impMargemPct'];
        if ($m['nao_mapeados']) $comSemCadastro++;

        $linhas[] = [
            'p'         => $p,
            'num_am'    => $p['numero'],
            'tipo'      => $p['tipo'],
            'eh_bf'     => $p['eh_bf'],
            'local_id'  => $locais[$p['numero']] ?? null,
            'codigo'    => $p['codigo'],
            'situacao'  => $p['situacao'],
            'situacao_cod' => $p['situacao_cod'],
            'cliente'   => $p['cliente_nome'] ?: $p['cliente'],
            'vendedor'  => $p['vendedor'],
            'vendedor_cod' => $p['vendedor_cod'],
            'data'      => $p['data'],
            'data_pedido' => $p['data_pedido'],
            'produtos'  => $produtos,
            'descontos' => $descontos,
            'credito'   => $credito,
            'impostos'  => $impostos,
            'impostos_pct' => $produtos > 0 ? $impostos / $produtos * 100 : 0,
            'mp'        => $mp,
            'despesas'  => $despesas,
            'margem'    => $margem,
            'margem_pct'=> $margemPct,
            'itens'     => $m['impItens'],
            'nao_mapeados' => $m['nao_mapeados'],
            'canal'     => $m['canal_nome'],
            'uf'        => $m['clienteUF'],
        ];
        $tot['produtos']  += $produtos;
        $tot['descontos'] += $descontos;
        $tot['credito']   += $credito;
        $tot['impostos']  += $impostos;
        $tot['mp']        += $mp;
        $tot['despesas']  += $despesas;
        $tot['canal']      += -(float)$m['impDeltaCanal'];
        $tot['cliente']    += -(float)$m['impDeltaCliente'];
        $tot['comercial']  += -(float)$m['impDeltaComercial'];
        $tot['campanha']   += -(float)$m['impDeltaCampanha'];
        $tot['financeiro'] += -(float)$m['impDeltaFinanceiro'];
        $tot['margem']    += $margem;

        // Por vendedor: o pedido inteiro vai para o supervisor da coluna VendPed.
        $vk = $p['vendedor'] !== '' ? $p['vendedor'] : '(sem supervisor)';
        $porVend[$vk] = $porVend[$vk] ?? $novoAcum();
        $porVend[$vk]['pedidos']++;
        $porVend[$vk]['clientes'][$p['codigo']] = true;
        $porVend[$vk]['qtd'] += array_sum(array_column($m['impItens'], 'qtd'));
        $somaAcum($porVend[$vk], compact('produtos', 'descontos', 'credito', 'impostos', 'mp', 'despesas', 'margem'));

        // Campanha: só pedido com "BF" no Obs (mesma regra do Importa Pedido BF e do check 5 da Análise
        // Financeira). Item de campanha com faixa atingida no pedido = participou, com o % da faixa.
        $campItem = [];
        if ($p['eh_bf']) {
            $av = campanhasAmAvaliarPedido($p['itens'], $p['pedido_accademia'] === 'SIM' ? 'distribuidor' : 'varejo');
            foreach ($av['campanhas_atingidas'] as $ca) {
                if ((float)$ca['percentual_esperado'] <= 0.005) continue;
                foreach ($ca['itens'] as $ci) $campItem[$ci['codigo']] = [$ca['nome'], (float)$ca['percentual_esperado']];
            }
        }

        // Por produto: mesmo rateio por item da visualização compacta (Σ itens = totais do pedido).
        foreach ($m['impItens'] as $it) {
            $q  = (int)$it['qtd'];
            $pk = (string)$it['codigo'];
            $porProd[$pk] = $porProd[$pk] ?? $novoAcum() + ['codigo' => $pk, 'descricao' => $it['descricao']];
            if (!isset($porProd[$pk]['_ped'][$p['numero']])) { $porProd[$pk]['_ped'][$p['numero']] = true; $porProd[$pk]['pedidos']++; }
            $porProd[$pk]['qtd'] += $q;
            if (isset($campItem[$pk])) {
                [$cNome, $cPct] = $campItem[$pk];
                $porProd[$pk]['camp_qtd'] += $q;
                $fx = $porProd[$pk]['campanhas'][$cNome] ?? [$cPct, $cPct];
                $porProd[$pk]['campanhas'][$cNome] = [min($fx[0], $cPct), max($fx[1], $cPct)];
            }
            $somaAcum($porProd[$pk], [
                'produtos'  => $it['precoPadrao'] * $q,
                'descontos' => ($it['vCanal'] + $it['vCliente'] + $it['vPedido'] + $it['vCampanha']) * $q,
                'credito'   => $it['vCredito'] * $q,
                'impostos'  => ($it['netTotal'] + array_sum(array_column($it['blocosOutros'], 'total'))) * $q,
                'mp'        => $it['custoMP'] * $q,
                'despesas'  => ($it['vCF'] + $it['vDescFinanceiro']) * $q,
                'margem'    => $it['resultadoIni'] * $q,
            ]);
        }
    }
    uasort($porVend, fn($x, $y) => $y['margem'] <=> $x['margem']);
    uasort($porProd, fn($x, $y) => $y['margem'] <=> $x['margem']);
}
$totMargemPct   = $tot['produtos'] > 0 ? $tot['margem'] / $tot['produtos'] * 100 : 0;
$totImpostosPct = $tot['produtos'] > 0 ? $tot['impostos'] / $tot['produtos'] * 100 : 0;

$pctFmt = fn($v) => rtrim(rtrim(number_format((float)$v, 2, ',', '.'), '0'), ',') . '%';
// Margem: vermelho só quando negativa; caso contrário, verde.
$corMargem = fn($v) => $v < 0 ? 'danger' : 'success';
// Etiqueta da situação do pedido no A&M (texto completo no title).
$sitBadge = function ($cod, $txt) {
    $cores = ['FC' => 'success', 'AB' => 'info', 'AC' => 'warning', 'SA' => 'danger'];
    $curto = ['FC' => 'Faturado', 'AB' => 'Lib. faturamento', 'AC' => 'Lib. financeiro', 'SA' => 'Bloqueado'];
    return '<span class="badge text-bg-' . ($cores[$cod] ?? 'secondary') . '" title="' . e($txt) . '">' . e($curto[$cod] ?? $txt) . '</span>';
};

$pageTitle = 'Margem dos Pedidos A&M — Faturados';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h4 class="fw-bold mb-0">
            <i class="bi bi-receipt me-2"></i>Margem dos Pedidos A&amp;M
            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 align-middle fs-6">Pedidos faturados</span>
        </h4>
        <p class="text-muted small mb-0">
            Pedidos do A&amp;M nas situações escolhidas (padrão: “FC - Faturado”), buscados na hora. Faturados vêm de Vendas &gt;
            Consulta/Reimprime Pedidos (grupo Faturados): período pela data do faturamento, valor e itens faturados; as demais situações,
            de Vendas &gt; Consulta/Reimprime. Mesmo waterfall do relatório de pedidos colocados: preço de tabela → descontos → crédito →
            impostos por empresa → custo MP → custos fixos. Nada é gravado.
        </p>
    </div>
    <a href="<?= BASE_URL ?>/admin/relatorios/am.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Relatórios A&amp;M
    </a>
</div>

<form class="card shadow-sm border-0 mb-4 p-3" id="frmBusca">
    <input type="hidden" name="acao" value="buscar">
    <input type="hidden" name="visao" id="inpVisao" value="<?= e($visao) ?>">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2">
            <label class="form-label fw-semibold small mb-1">Data inicial</label>
            <input type="date" name="ini" value="<?= e($ini) ?>" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label fw-semibold small mb-1">Data final</label>
            <input type="date" name="fim" value="<?= e($fim) ?>" class="form-control form-control-sm">
        </div>
        <div class="col-12 col-md-3">
            <label class="form-label fw-semibold small mb-1">Cliente</label>
            <input type="text" name="cliente" value="<?= e($cliente) ?>" class="form-control form-control-sm" placeholder="Nome ou código do A&amp;M">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label fw-semibold small mb-1">BF</label>
            <select name="bf" class="form-select form-select-sm">
                <option value="" <?= $bf === '' ? 'selected' : '' ?>>Todos</option>
                <option value="1" <?= $bf === '1' ? 'selected' : '' ?>>Só BF</option>
                <option value="0" <?= $bf === '0' ? 'selected' : '' ?>>Só não-BF</option>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <button class="btn btn-primary btn-sm w-100" id="btnBuscar">
                <i class="bi bi-cloud-download me-1"></i>Buscar no A&amp;M
            </button>
        </div>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
        <span class="fw-semibold small me-1">Situação no A&amp;M:</span>
        <?php foreach ($situacoesOpc as $cod => $rot): ?>
        <input type="checkbox" class="btn-check" name="sit[]" value="<?= $cod ?>" id="sit<?= $cod ?>" autocomplete="off" <?= in_array($cod, $situacoes, true) ? 'checked' : '' ?>>
        <label class="btn btn-sm btn-outline-secondary" for="sit<?= $cod ?>"><?= e($rot) ?></label>
        <?php endforeach; ?>
    </div>
    <div class="text-muted small mt-2" id="aguarde" style="display:none">
        <span class="spinner-border spinner-border-sm me-1"></span>
        Consultando o sistema A&amp;M… lê o detalhe de cada pedido (cerca de 1 minuto num mês cheio).
    </div>
</form>

<?php if ($erro): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= e($erro) ?></div>
<?php endif; ?>

<?php if (!$buscar || $erro): ?>
    <div class="card shadow-sm border-0">
        <div class="card-body text-center text-muted py-5">
            <i class="bi bi-cloud-download fs-1 d-block mb-2"></i>
            Defina o período e as situações e clique em <b>Buscar no A&amp;M</b> para executar o relatório.
        </div>
    </div>
<?php else: ?>

<?php if ($divergentes): ?>
    <div class="alert alert-danger small">
        <div class="fw-semibold mb-1">
            <i class="bi bi-exclamation-octagon me-1"></i><?= count($divergentes) ?> pedido(s) com divergência no A&amp;M — fora do cálculo
            (não entram na tabela, nos totais nem nas abas). Confira no A&amp;M:
        </div>
        <ul class="mb-0 ps-3">
            <?php foreach ($divergentes as $d): ?>
            <li>
                <b><?= e($d['tipo'] . ' ' . $d['numero']) ?></b> (interno <?= e($d['pedido_interno']) ?>, <?= dataBR($d['data']) ?>)
                — <?= e($d['codigo']) ?> <?= e($d['cliente_nome'] ?: $d['cliente']) ?> — <?= moedaBR($d['valor_pedido']) ?>:
                <?= e(implode(' · ', $d['divergencias'])) ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
<?php if ($falhas): ?>
    <div class="alert alert-warning small">
        <i class="bi bi-exclamation-triangle me-1"></i>
        <?= count($falhas) ?> pedido(s) não puderam ser lidos no A&amp;M e ficaram fora do relatório:
        <?= e(implode(', ', array_map(fn($f) => $f['numero'] . ' (' . $f['erro'] . ')', $falhas))) ?>
    </div>
<?php endif; ?>
<?php if ($comSemCadastro): ?>
    <div class="alert alert-warning small">
        <i class="bi bi-exclamation-triangle me-1"></i>
        <?= $comSemCadastro ?> pedido(s) têm produto(s) sem cadastro ativo no SisPed pelo código do A&amp;M
        (marcados com <i class="bi bi-exclamation-triangle-fill text-warning"></i>) — esses itens ficam fora do cálculo.
    </div>
<?php endif; ?>

<?php if ($linhas && count($situacoes) > 1): $porSit = array_count_values(array_column($linhas, 'situacao_cod')); ?>
<div class="small text-muted mb-2">
    <i class="bi bi-funnel me-1"></i>Por situação:
    <?= implode(' · ', array_map(fn($c) => e($situacoesOpc[$c]) . ': <b>' . ($porSit[$c] ?? 0) . '</b>', $situacoes)) ?>
</div>
<?php endif; ?>
<div class="row g-3 mb-4">
    <?php
    // "Descontos Aplicados" = descontos em cascata (canal + cliente + comercial/diretoria +
    // campanha) + crédito — as duas etapas do waterfall entre o preço de tabela e os impostos.
    // O desconto financeiro (Pix) fica em "Despesas", junto com o custo fixo.
    $totDescontos    = $tot['descontos'] + $tot['credito'];
    $totDescontosPct = $tot['produtos'] > 0 ? $totDescontos / $tot['produtos'] * 100 : 0;
    // Abertura por tipo, no title do card (passe o mouse para ver).
    $descontosDet = [
        'Canal'               => $tot['canal'],
        'Cliente'             => $tot['cliente'],
        'Comercial/Diretoria' => $tot['comercial'],
        'Campanha'            => $tot['campanha'],
        'Crédito aplicado'    => $tot['credito'],
    ];
    $tituloDescontos = implode("\n", array_map(
        fn($k, $v) => $k . ': ' . moedaBR($v),
        array_keys($descontosDet), $descontosDet
    ));
    // Saldo em cascata: cada card mostra o que sobra do valor de tabela após a sua etapa.
    $saldoDescontos  = $tot['produtos'] - $totDescontos;
    $saldoImpostos   = $saldoDescontos - $tot['impostos'];
    $saldoMp         = $saldoImpostos - $tot['mp'];
    $totMpPct        = $tot['produtos'] > 0 ? $tot['mp'] / $tot['produtos'] * 100 : 0;
    $totDespesasPct  = $tot['produtos'] > 0 ? $tot['despesas'] / $tot['produtos'] * 100 : 0;
    $resumo = [
        ['Valor de Tabela', $tot['produtos'], 'secondary'],
        ['Descontos Aplicados', $totDescontos, 'danger', $totDescontosPct, $tituloDescontos,
            'Tabela − descontos: <b>' . moedaBR($saldoDescontos) . '</b>'],
        ['Carga de Impostos', $tot['impostos'], 'warning', $totImpostosPct, null,
            'Após impostos: <b>' . moedaBR($saldoImpostos) . '</b>'],
        ['Custo MP', $tot['mp'], 'info', $totMpPct, null,
            'Após custo MP: <b>' . moedaBR($saldoMp) . '</b>'],
        ['Despesas', $tot['despesas'], 'info', $totDespesasPct],
        ['Margem Final', $tot['margem'], $corMargem($totMargemPct), $totMargemPct],
    ];
    foreach ($resumo as $rs): ?>
    <div class="col-6 col-md-4 col-xl">
        <div class="card shadow-sm border-0 border-start border-4 border-<?= $rs[2] ?> h-100"<?= !empty($rs[4]) ?' title="' . e($rs[4]) . '"' : '' ?>>
            <div class="card-body py-3">
                <div class="text-muted small fw-semibold text-uppercase"><?= e($rs[0]) ?></div>
                <div class="fs-4 fw-bold text-<?= $rs[2] ?>"><?= moedaBR($rs[1]) ?></div>
                <?php if (isset($rs[3])): ?><div class="small text-muted"><?= $pctFmt($rs[3]) ?> sobre o valor de tabela</div><?php endif; ?>
                <?php if (isset($rs[5])): ?><div class="small text-muted"><?= $rs[5] ?></div><?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<style>
.tbl-fixa-wrap{max-height:70vh;overflow:auto}
.tbl-fixa thead th{position:sticky;top:0;z-index:3;background:var(--bs-tertiary-bg)!important;box-shadow:inset 0 -1px 0 var(--bs-border-color)}
.tbl-fixa tfoot td{position:sticky;bottom:0;z-index:3;background:var(--bs-tertiary-bg)!important;box-shadow:inset 0 1px 0 var(--bs-border-color)}
.tbl-fixa thead th.col-ord{cursor:pointer;user-select:none;white-space:nowrap}
.tbl-fixa thead th.col-ord:hover{color:var(--bs-primary)}
.tbl-fixa thead th.col-ord .ord-ico{font-size:.7rem;opacity:.3;margin-left:.25rem}
.tbl-fixa thead th.col-ord.ord-ativa .ord-ico{opacity:1;color:var(--bs-primary)}
/* acordeão do detalhe "Margem" (mesmo CSS de admin/pedido.php) */
.imp-chevron{display:inline-block;transition:transform .2s}
.imp-toggle:not(.collapsed) .imp-chevron{transform:rotate(90deg)}
</style>
<?php
$abas = ['pedido' => ['bi-receipt', 'Por pedido', count($linhas)], 'vendedor' => ['bi-person-badge', 'Por vendedor', count($porVend)],
         'produto' => ['bi-box-seam', 'Por produto', count($porProd)]];
?>
<ul class="nav nav-tabs mb-0" role="tablist">
    <?php foreach ($abas as $k => [$ico, $rot, $n]): ?>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $visao === $k ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#vis-<?= $k ?>" data-visao="<?= $k ?>" type="button" role="tab">
            <i class="bi <?= $ico ?> me-1"></i><?= $rot ?> <span class="badge text-bg-light border ms-1"><?= $n ?></span>
        </button>
    </li>
    <?php endforeach; ?>
</ul>
<div class="tab-content">
<div class="tab-pane fade <?= $visao === 'pedido' ? 'show active' : '' ?>" id="vis-pedido" role="tabpanel">
<div class="card shadow-sm border-0">
    <div class="card-body p-0"><div class="table-responsive tbl-fixa-wrap">
    <table class="table table-hover table-sm align-middle mb-0 tbl-fixa" style="font-size:.85rem">
        <thead class="table-light">
            <tr>
                <?php
                // Clique no título ordena as linhas por essa coluna (de novo inverte). data-col = atributo
                // data-o-<col> da linha com o valor bruto; data-tipo = num | txt.
                $colunas = [
                    'num_am' => ['Nº A&amp;M', '', 'num'], 'interno' => ['Pedido Interno', '', 'num'], 'codigo' => ['Cód. Cliente', '', 'num'],
                    'cliente' => ['Cliente', '', 'txt'], 'vendedor' => ['Supervisor', '', 'txt'], 'data' => ['Data', '', 'txt'],
                    'tabela' => ['Valor Tabela', 'text-end', 'num'], 'descontos' => ['Descontos', 'text-end', 'num'], 'credito' => ['Crédito', 'text-end', 'num'],
                    'impostos' => ['Carga Impostos', 'text-end', 'num'], 'mp' => ['Custo MP', 'text-end', 'num'], 'despesas' => ['Despesas', 'text-end', 'num'],
                    'margem' => ['Margem', 'text-end', 'num'],
                ];
                foreach ($colunas as $k => [$rotulo, $cls, $tipo]): ?>
                <th class="col-ord <?= $cls ?>" data-col="<?= $k ?>" data-tipo="<?= $tipo ?>" tabindex="0" title="Clique para ordenar"><?= $rotulo ?><i class="bi bi-arrow-down-up ord-ico"></i></th>
                <?php endforeach; ?>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php if ($linhas): foreach ($linhas as $i => $l):
            // Valores brutos para a ordenação por clique no título (data + pedido interno desempata).
            $soDig = fn($s) => (int)preg_replace('/\D/', '', (string)$s);
            $ord = [
                'num_am' => $soDig($l['num_am']), 'interno' => $soDig($l['p']['pedido_interno']), 'codigo' => $soDig($l['codigo']),
                'cliente' => $l['cliente'], 'vendedor' => $l['vendedor'], 'data' => $l['data'] . ' ' . str_pad((string)$soDig($l['p']['pedido_interno']), 10, '0', STR_PAD_LEFT),
                'tabela' => round($l['produtos'], 2), 'descontos' => round($l['descontos'], 2), 'credito' => round($l['credito'], 2),
                'impostos' => round($l['impostos'], 2), 'mp' => round($l['mp'], 2), 'despesas' => round($l['despesas'], 2),
                'margem' => round($l['margem'], 2),
            ];
        ?>
            <tr class="lin-ord"<?php foreach ($ord as $k => $v) echo ' data-o-' . $k . '="' . e($v) . '"'; ?>>
                <td class="fw-semibold text-nowrap">
                    <?= e($l['num_am']) ?>
                    <?php if ($l['tipo'] === 'MAT'): ?><span class="badge bg-secondary ms-1" title="Pedido MAT no A&amp;M">MAT</span><?php endif; ?>
                    <?php if ($l['p']['descto_zerado']): ?>
                    <i class="bi bi-info-circle text-info ms-1" title="%Descto considerado 0 em <?= (int)$l['p']['descto_zerado'] ?> item(ns): Preço Tabela = Valor Unitário no A&amp;M (preço já líquido)"></i>
                    <?php endif; ?>
                    <?php if ($l['eh_bf']): ?><span class="badge bg-primary ms-1">BF</span><?php endif; ?>
                    <?php if ($l['nao_mapeados']): ?>
                    <i class="bi bi-exclamation-triangle-fill text-warning ms-1" title="Sem cadastro no SisPed (fora do cálculo):&#10;<?= e(implode("\n", $l['nao_mapeados'])) ?>"></i>
                    <?php endif; ?>
                </td>
                <td>
                    <?= e($l['p']['pedido_interno']) ?>
                    <span class="d-block mt-1" style="font-size:.7rem"><?= $sitBadge($l['situacao_cod'], $l['situacao']) ?></span>
                </td>
                <td class="text-nowrap"><?= e($l['codigo']) ?></td>
                <td class="text-truncate" style="max-width:190px" title="<?= e($l['cliente']) ?>"><?= e($l['cliente']) ?></td>
                <td class="text-nowrap" title="<?= e($l['vendedor_cod']) ?>"><?= $l['vendedor'] !== '' ? e($l['vendedor']) : '<span class="text-muted">—</span>' ?></td>
                <td class="text-nowrap"<?= $l['data_pedido'] && $l['data_pedido'] !== $l['data'] ? ' title="Pedido feito em ' . dataBR($l['data_pedido']) . '"' : '' ?>><?= dataBR($l['data']) ?></td>
                <td class="text-end"><?= moedaBR($l['produtos']) ?></td>
                <td class="text-end text-danger"><?= $l['descontos'] ? '− ' . moedaBR($l['descontos']) : '—' ?></td>
                <td class="text-end text-danger"><?= $l['credito'] ? '− ' . moedaBR($l['credito']) : '—' ?></td>
                <td class="text-end text-danger">
                    − <?= moedaBR($l['impostos']) ?>
                    <span class="text-muted d-block" style="font-size:.75rem"><?= $pctFmt($l['impostos_pct']) ?></span>
                </td>
                <td class="text-end text-danger"><?= $l['mp'] ? '− ' . moedaBR($l['mp']) : '—' ?></td>
                <td class="text-end text-danger"><?= $l['despesas'] ? '− ' . moedaBR($l['despesas']) : '—' ?></td>
                <td class="text-end fw-bold">
                    <span class="text-<?= $corMargem($l['margem_pct']) ?>"><?= moedaBR($l['margem']) ?></span>
                    <span class="badge bg-<?= $corMargem($l['margem_pct']) ?> d-block mt-1"><?= $pctFmt($l['margem_pct']) ?></span>
                </td>
                <td class="text-end text-nowrap">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="abrirItens(<?= $i ?>)" title="Detalhe da margem por item">
                        <i class="bi bi-list-ul"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="abrirItens(<?= $i ?>, 'compacto')" title="Itens do pedido (visualização compacta)">
                        <i class="bi bi-table"></i>
                    </button>
                    <?php if ($l['local_id']): ?>
                    <a href="<?= BASE_URL ?>/admin/pedido.php?id=<?= $l['local_id'] ?>" class="btn btn-sm btn-outline-secondary" title="Abrir o pedido importado no SisPed">
                        <i class="bi bi-eye"></i>
                    </a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; else: ?>
            <tr><td colspan="14" class="text-center text-muted py-4">Nenhum pedido no A&amp;M no período com a situação <?= e(implode(', ', array_map(fn($c) => '“' . $situacoesOpc[$c] . '”', $situacoes))) ?><?= $cliente !== '' ? ' para o cliente “' . e($cliente) . '”' : '' ?>.</td></tr>
        <?php endif; ?>
        </tbody>
        <?php if ($linhas): ?>
        <tfoot class="table-light fw-semibold">
            <tr>
                <td colspan="6">Total — <?= count($linhas) ?> pedido(s)</td>
                <td class="text-end"><?= moedaBR($tot['produtos']) ?></td>
                <td class="text-end text-danger"><?= $tot['descontos'] ? '− ' . moedaBR($tot['descontos']) : '—' ?></td>
                <td class="text-end text-danger"><?= $tot['credito'] ? '− ' . moedaBR($tot['credito']) : '—' ?></td>
                <td class="text-end text-danger">− <?= moedaBR($tot['impostos']) ?> <span class="text-muted d-block" style="font-size:.75rem"><?= $pctFmt($totImpostosPct) ?></span></td>
                <td class="text-end text-danger"><?= $tot['mp'] ? '− ' . moedaBR($tot['mp']) : '—' ?></td>
                <td class="text-end text-danger"><?= $tot['despesas'] ? '− ' . moedaBR($tot['despesas']) : '—' ?></td>
                <td class="text-end">
                    <span class="text-<?= $corMargem($totMargemPct) ?>"><?= moedaBR($tot['margem']) ?></span>
                    <span class="badge bg-<?= $corMargem($totMargemPct) ?> d-block mt-1"><?= $pctFmt($totMargemPct) ?></span>
                </td>
                <td></td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
    </div></div>
</div>
<p class="text-muted small mt-2">
    <i class="bi bi-info-circle me-1"></i>“Data” = data do faturamento nos pedidos faturados (a do pedido aparece ao passar o mouse) e data do
    pedido nas demais situações. Nos faturados, itens e quantidades são os faturados (produto em falta fica de fora) e o valor é o faturado.
    “Supervisor” = supervisor do cadastro do cliente, com o nome do cadastro de usuários do A&amp;M. Descontos de canal/cliente, % Negociação,
    % Diretoria, crédito utilizado, forma de pagamento e o preço de tabela (coluna “Preço Tabela”) vêm do próprio pedido no A&amp;M;
    NCM/impostos e custo MP vêm dos cadastros do SisPed. “Despesas” = custos fixos (%) + desconto financeiro.
    <i class="bi bi-list-ul"></i> abre o detalhe da margem por item (o mesmo modal “Margem” da tela do pedido);
    <i class="bi bi-table"></i> mostra os itens numa tabela compacta (uma linha por item); <i class="bi bi-eye"></i> abre o pedido quando ele também foi importado no SisPed.
</p>
</div>

<?php
// Células numéricas comuns às visões "Por vendedor" e "Por produto" (mesmas colunas do "Por pedido").
$colsValores = [
    'tabela' => ['Valor Tabela', 'text-end', 'num'], 'descontos' => ['Descontos', 'text-end', 'num'], 'credito' => ['Crédito', 'text-end', 'num'],
    'impostos' => ['Carga Impostos', 'text-end', 'num'], 'mp' => ['Custo MP', 'text-end', 'num'], 'despesas' => ['Despesas', 'text-end', 'num'],
    'margem' => ['Margem', 'text-end', 'num'], 'part' => ['% da Margem', 'text-end', 'num'],
];
$thOrd = function (array $cols) {
    foreach ($cols as $k => [$rotulo, $cls, $tipo]) {
        echo '<th class="col-ord ' . $cls . '" data-col="' . $k . '" data-tipo="' . $tipo . '" tabindex="0" title="Clique para ordenar">'
           . $rotulo . '<i class="bi bi-arrow-down-up ord-ico"></i></th>';
    }
};
$ordValores = fn(array $a) => [
    'tabela' => round($a['produtos'], 2), 'descontos' => round($a['descontos'], 2), 'credito' => round($a['credito'], 2),
    'impostos' => round($a['impostos'], 2), 'mp' => round($a['mp'], 2), 'despesas' => round($a['despesas'], 2),
    'margem' => round($a['margem'], 2), 'part' => round($a['margem'], 2),
];
$tdValores = function (array $a) use ($tot, $pctFmt, $corMargem) {
    $impPct = $a['produtos'] > 0 ? $a['impostos'] / $a['produtos'] * 100 : 0;
    $mPct   = $a['produtos'] > 0 ? $a['margem'] / $a['produtos'] * 100 : 0;
    $part   = $tot['margem'] != 0 ? $a['margem'] / $tot['margem'] * 100 : 0;
    ?>
    <td class="text-end"><?= moedaBR($a['produtos']) ?></td>
    <td class="text-end text-danger"><?= $a['descontos'] ? '− ' . moedaBR($a['descontos']) : '—' ?></td>
    <td class="text-end text-danger"><?= $a['credito'] ? '− ' . moedaBR($a['credito']) : '—' ?></td>
    <td class="text-end text-danger">− <?= moedaBR($a['impostos']) ?><span class="text-muted d-block" style="font-size:.75rem"><?= $pctFmt($impPct) ?></span></td>
    <td class="text-end text-danger"><?= $a['mp'] ? '− ' . moedaBR($a['mp']) : '—' ?></td>
    <td class="text-end text-danger"><?= $a['despesas'] ? '− ' . moedaBR($a['despesas']) : '—' ?></td>
    <td class="text-end fw-bold">
        <span class="text-<?= $corMargem($mPct) ?>"><?= moedaBR($a['margem']) ?></span>
        <span class="badge bg-<?= $corMargem($mPct) ?> d-block mt-1"><?= $pctFmt($mPct) ?></span>
    </td>
    <td class="text-end">
        <?= $pctFmt($part) ?>
        <div class="progress mt-1" style="height:4px"><div class="progress-bar bg-<?= $corMargem($a['margem']) ?>" style="width:<?= max(0, min(100, abs($part))) ?>%"></div></div>
    </td>
    <?php
};
$trTotal = function (int $colspan, string $rotulo, array $a) use ($tot, $pctFmt, $corMargem, $tdValores) {
    echo '<tfoot class="table-light fw-semibold"><tr><td colspan="' . $colspan . '">' . $rotulo . '</td>';
    $tdValores($a);
    echo '</tr></tfoot>';
};
$totProd = $novoAcum();
foreach ($porProd as $a) { $somaAcum($totProd, $a); $totProd['qtd'] += $a['qtd']; }
?>

<div class="tab-pane fade <?= $visao === 'vendedor' ? 'show active' : '' ?>" id="vis-vendedor" role="tabpanel">
<div class="card shadow-sm border-0">
    <div class="card-body p-0"><div class="table-responsive tbl-fixa-wrap">
    <table class="table table-hover table-sm align-middle mb-0 tbl-fixa" style="font-size:.85rem">
        <thead class="table-light"><tr>
            <?php $thOrd(['vendedor' => ['Supervisor', '', 'txt'], 'pedidos' => ['Pedidos', 'text-end', 'num'],
                          'clientes' => ['Clientes', 'text-end', 'num'], 'qtd' => ['Qtd Itens', 'text-end', 'num']] + $colsValores); ?>
        </tr></thead>
        <tbody>
        <?php foreach ($porVend as $vend => $a):
            $ord = ['vendedor' => $vend, 'pedidos' => $a['pedidos'], 'clientes' => count($a['clientes']), 'qtd' => $a['qtd']] + $ordValores($a); ?>
            <tr class="lin-ord"<?php foreach ($ord as $k => $v) echo ' data-o-' . $k . '="' . e($v) . '"'; ?>>
                <td class="fw-semibold text-nowrap"><?= $vend === '(sem supervisor)' ? '<span class="text-muted fst-italic" title="Cliente sem supervisor no cadastro do A&amp;M">sem supervisor</span>' : e($vend) ?></td>
                <td class="text-end"><?= $a['pedidos'] ?></td>
                <td class="text-end"><?= count($a['clientes']) ?></td>
                <td class="text-end"><?= number_format($a['qtd'], 0, ',', '.') ?></td>
                <?php $tdValores($a); ?>
            </tr>
        <?php endforeach; ?>
        <?php if (!$porVend): ?><tr><td colspan="12" class="text-center text-muted py-4">Nenhum pedido.</td></tr><?php endif; ?>
        </tbody>
        <?php if ($porVend) $trTotal(4, 'Total — ' . count($porVend) . ' supervisor(es), ' . count($linhas) . ' pedido(s)', $tot); ?>
    </table>
    </div></div>
</div>
<p class="text-muted small mt-2">
    <i class="bi bi-info-circle me-1"></i>Supervisor = supervisor do cadastro do cliente no A&amp;M (código “Vend Cad”), com o nome da coluna “Usuário”
    de Acesso &gt; Cadastra Usuários do Sistema. Cada pedido entra inteiro no supervisor. “% da Margem” = participação na margem total do período.
</p>
</div>

<div class="tab-pane fade <?= $visao === 'produto' ? 'show active' : '' ?>" id="vis-produto" role="tabpanel">
<div class="card shadow-sm border-0">
    <div class="card-body p-0"><div class="table-responsive tbl-fixa-wrap">
    <table class="table table-hover table-sm align-middle mb-0 tbl-fixa" style="font-size:.85rem">
        <thead class="table-light"><tr>
            <?php $thOrd(['codigo' => ['Código', '', 'txt'], 'produto' => ['Produto', '', 'txt'], 'campanha' => ['Campanha', '', 'txt'],
                          'pedidos' => ['Pedidos', 'text-end', 'num'], 'qtd' => ['Qtd', 'text-end', 'num'],
                          'pcamp' => ['% em Campanha', 'text-end', 'num']] + $colsValores); ?>
        </tr></thead>
        <tbody>
        <?php foreach ($porProd as $a):
            $pCamp = $a['qtd'] > 0 ? $a['camp_qtd'] / $a['qtd'] * 100 : 0;
            $ord = ['codigo' => $a['codigo'], 'produto' => $a['descricao'], 'campanha' => $a['campanhas'] ? implode(' / ', array_keys($a['campanhas'])) : '~',
                    'pedidos' => $a['pedidos'], 'qtd' => $a['qtd'], 'pcamp' => round($pCamp, 2)] + $ordValores($a); ?>
            <tr class="lin-ord"<?php foreach ($ord as $k => $v) echo ' data-o-' . $k . '="' . e($v) . '"'; ?>>
                <td class="text-nowrap"><?= e($a['codigo']) ?></td>
                <td class="text-truncate" style="max-width:260px" title="<?= e($a['descricao']) ?>"><?= e($a['descricao']) ?></td>
                <td style="min-width:170px">
                    <?php if (!$a['campanhas']): ?><span class="text-muted">—</span><?php endif; ?>
                    <?php foreach ($a['campanhas'] as $cNome => [$pMin, $pMax]): ?>
                    <div class="text-nowrap" style="font-size:.78rem">
                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 text-truncate align-middle" style="max-width:170px" title="<?= e($cNome) ?>"><?= e($cNome) ?></span>
                        <b title="% de desconto da faixa atingida"><?= $pMin == $pMax ? $pctFmt($pMin) : $pctFmt($pMin) . '–' . $pctFmt($pMax) ?></b>
                    </div>
                    <?php endforeach; ?>
                </td>
                <td class="text-end"><?= $a['pedidos'] ?></td>
                <td class="text-end"><?= number_format($a['qtd'], 0, ',', '.') ?></td>
                <td class="text-end">
                    <?php if ($a['camp_qtd']): ?>
                    <span title="<?= number_format($a['camp_qtd'], 0, ',', '.') ?> de <?= number_format($a['qtd'], 0, ',', '.') ?> un. vendidas com campanha"><?= $pctFmt($pCamp) ?></span>
                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                </td>
                <?php $tdValores($a); ?>
            </tr>
        <?php endforeach; ?>
        <?php if (!$porProd): ?><tr><td colspan="14" class="text-center text-muted py-4">Nenhum item com cadastro no SisPed.</td></tr><?php endif; ?>
        </tbody>
        <?php if ($porProd) $trTotal(6, 'Total — ' . count($porProd) . ' produto(s), ' . number_format($totProd['qtd'], 0, ',', '.') . ' un.'
            . ' — ' . count(array_filter(array_column($porProd, 'camp_qtd'))) . ' produto(s) com campanha, '
            . $pctFmt($totProd['qtd'] > 0 ? array_sum(array_column($porProd, 'camp_qtd')) / $totProd['qtd'] * 100 : 0) . ' das un. em campanha', $totProd); ?>
    </table>
    </div></div>
</div>
<p class="text-muted small mt-2">
    <i class="bi bi-info-circle me-1"></i>Soma dos itens de todos os pedidos, com o mesmo cálculo por item do detalhe de margem (descontos, crédito,
    impostos, custo MP e despesas rateados por item). Itens sem cadastro no SisPed ficam de fora
    <?php if ($comSemCadastro): ?>(<?= $comSemCadastro ?> pedido(s) com item(ns) nessa situação)<?php endif; ?>.<br>
    <i class="bi bi-megaphone me-1"></i>“Campanha” = campanhas do A&amp;M de que o produto participou, com o % de desconto
    da faixa atingida (intervalo quando variou entre pedidos). Só contam pedidos com “BF” no Obs e campanha com faixa atingida no pedido — mesma
    regra do Importa Pedido BF. “% em Campanha” = parte das unidades vendidas do produto que saiu com campanha.
</p>
</div>
</div><!-- /tab-content -->

<script>
// Ordenação por clique no título: 1º clique = crescente (A→Z, menor→maior), 2º = decrescente.
// Usa os valores brutos gravados em data-o-<coluna> de cada linha, não o texto formatado.
// Vale para as tabelas das três abas (por pedido / vendedor / produto).
Array.prototype.forEach.call(document.querySelectorAll('.tbl-fixa'), function (tbl) {
    if (!tbl.tBodies[0]) return;
    var corpo = tbl.tBodies[0];
    var linhas = Array.prototype.filter.call(corpo.rows, function (tr) { return tr.classList.contains('lin-ord'); });
    if (linhas.length < 2) return;
    linhas.forEach(function (tr, i) { tr.dataset.pos = i; });            // desempate: ordem original
    var ths = tbl.tHead.querySelectorAll('th.col-ord');
    var colAtual = null, asc = true;

    function ordena(th) {
        var col = th.dataset.col, num = th.dataset.tipo === 'num';
        asc = (col === colAtual) ? !asc : true;
        colAtual = col;
        linhas.sort(function (a, b) {
            var va = a.getAttribute('data-o-' + col), vb = b.getAttribute('data-o-' + col), r;
            r = num ? (parseFloat(va) || 0) - (parseFloat(vb) || 0)
                    : String(va).localeCompare(String(vb), 'pt-BR', {sensitivity: 'base', numeric: true});
            if (!asc) r = -r;
            return r || (a.dataset.pos - b.dataset.pos);
        });
        linhas.forEach(function (tr) { corpo.appendChild(tr); });
        Array.prototype.forEach.call(ths, function (t) {
            var ico = t.querySelector('.ord-ico');
            var ativa = t === th;
            t.classList.toggle('ord-ativa', ativa);
            t.setAttribute('aria-sort', ativa ? (asc ? 'ascending' : 'descending') : 'none');
            ico.className = 'bi ord-ico ' + (ativa ? (asc ? 'bi-caret-up-fill' : 'bi-caret-down-fill') : 'bi-arrow-down-up');
        });
    }
    Array.prototype.forEach.call(ths, function (th) {
        th.addEventListener('click', function () { ordena(th); });
        th.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); ordena(th); }
        });
    });
});
// Aba escolhida vai junto na próxima busca e fica na URL (recarregar mantém a visão).
Array.prototype.forEach.call(document.querySelectorAll('[data-visao]'), function (bt) {
    bt.addEventListener('shown.bs.tab', function () {
        document.getElementById('inpVisao').value = bt.dataset.visao;
        var u = new URL(location.href); u.searchParams.set('visao', bt.dataset.visao);
        history.replaceState(null, '', u);
    });
});
</script>

<?php
// Um único modal, cujo corpo "Margem" (mesmo da tela do pedido) vem do endpoint ajax_margem ao abrir —
// renderizar o waterfall completo de todos os pedidos deixaria a página com vários MB num mês cheio.
// 'pedido' = dados crus do A&M que o endpoint precisa para recalcular (nada fica guardado no servidor).
$modalDados = [];
foreach ($linhas as $i => $l) {
    $p = $l['p'];
    $modalDados[$i] = [
        'numero' => $p['tipo'] . ' ' . $p['numero'], 'interno' => $p['pedido_interno'], 'bf' => $l['eh_bf'],
        'faturado' => $p['fonte'] === 'faturado', 'data_pedido' => $p['data_pedido'] ? dataBR($p['data_pedido']) : '',
        'cliente' => $l['cliente'], 'cnpj' => $p['cnpj'], 'uf' => $l['uf'], 'canal' => $l['canal'], 'vendedor' => $l['vendedor'],
        'data' => dataBR($p['data']), 'forma' => $p['forma'], 'valor' => moedaBR($p['valor_pedido']),
        'credito' => moedaBR($p['credito_utilizado']), 'obs' => $p['obs'],
        'pedido' => array_intersect_key($p, array_flip(['numero', 'cnpj', 'uf', 'data', 'pedido_accademia', 'is_a_vista', 'credito_utilizado', 'itens'])),
    ];
}
?>
<div class="modal fade" id="mdlItens" tabindex="-1"><div class="modal-dialog modal-fullscreen-lg-down modal-xl modal-dialog-scrollable">
    <div class="modal-content">
        <div class="modal-header border-bottom">
            <h5 class="modal-title fw-bold" id="mdlItensTitulo"></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" id="mdlItensCorpo"></div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
        </div>
    </div>
</div></div>
<script>
var pedidosAM = <?= json_encode($modalDados, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
var URL_MARGEM = <?= json_encode(BASE_URL . '/admin/relatorios/margem-am-faturados.php?ajax_margem=1') ?>;
var seqMargem = 0;   // descarta a resposta de um pedido aberto antes, se o usuário trocar rápido
// modo: '' = modal "Margem" da tela do pedido; 'compacto' = tabela resumida de itens (visualização anterior).
function abrirItens(i, modo) {
    var d = pedidosAM[i]; if (!d) return;
    var compacto = modo === 'compacto';
    var esc = function (s) { var el = document.createElement('div'); el.textContent = s == null ? '' : String(s); return el.innerHTML; };
    document.getElementById('mdlItensTitulo').innerHTML = (compacto ? '<i class="bi bi-table me-2"></i>Itens' : '<i class="bi bi-bank me-2"></i>Margem')
        + ' — Pedido A&amp;M Nº ' + esc(d.numero)
        + ' <small class="text-muted fw-normal">— Interno ' + esc(d.interno) + '</small>'
        + (d.bf ? ' <span class="badge bg-primary ms-1">BF</span>' : '');
    var h = '<div class="row g-2 small mb-3">'
        + '<div class="col-md-6"><b>Cliente:</b> ' + esc(d.cliente) + (d.cnpj ? ' — CNPJ ' + esc(d.cnpj) : '') + '</div>'
        + '<div class="col-md-3"><b>UF:</b> ' + esc(d.uf || '—') + ' &nbsp; <b>Canal:</b> ' + esc(d.canal || '—') + '</div>'
        + '<div class="col-md-3"><b>' + (d.faturado ? 'Faturado em' : 'Data') + ':</b> ' + esc(d.data)
        + (d.faturado && d.data_pedido && d.data_pedido !== d.data ? ' <span class="text-muted">(pedido ' + esc(d.data_pedido) + ')</span>' : '')
        + ' &nbsp; <b>Supervisor:</b> ' + esc(d.vendedor || '—') + '</div>'
        + '<div class="col-md-6"><b>Forma de pagamento:</b> ' + esc(d.forma || '—') + '</div>'
        + '<div class="col-md-3"><b>' + (d.faturado ? 'Valor faturado' : 'Valor no A&amp;M') + ':</b> ' + esc(d.valor) + '</div>'
        + '<div class="col-md-3"><b>Crédito utilizado:</b> ' + esc(d.credito) + '</div>'
        + (d.obs ? '<div class="col-12"><b>Obs:</b> ' + esc(d.obs) + '</div>' : '')
        + '</div>';
    h += '<div id="mdlItensMargem" class="text-center text-muted py-5">'
       + '<span class="spinner-border spinner-border-sm me-2"></span>Calculando a margem…</div>';
    document.getElementById('mdlItensCorpo').innerHTML = h;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlItens')).show();

    var seq = ++seqMargem;
    var mostra = function (cls, html) {
        if (seq !== seqMargem) return;
        var alvo = document.getElementById('mdlItensMargem');
        alvo.className = cls; alvo.innerHTML = html;
    };
    fetch(URL_MARGEM + (compacto ? '&modo=compacto' : ''), {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'}, body: JSON.stringify(d.pedido)
    })
        .then(function (r) { if (!r.ok || r.redirected) throw new Error(r.status); return r.text(); })
        .then(function (html) { mostra('', html); })
        .catch(function () {
            mostra('alert alert-danger small', 'Não foi possível calcular a margem deste pedido (sessão expirada?). Recarregue a página e tente de novo.');
        });
}
</script>

<?php endif; ?>

<script>
document.getElementById('frmBusca').addEventListener('submit', function () {
    var b = document.getElementById('btnBuscar');
    b.disabled = true;
    b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Consultando…';
    document.getElementById('aguarde').style.display = '';
});
</script>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
