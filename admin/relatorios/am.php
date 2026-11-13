<?php
require_once __DIR__ . '/../../config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $senha = trim($_POST['senha'] ?? '');

    if ($login && $senha) {
        // Verificar usuários admin pelo e-mail
        $stmt = db()->prepare('SELECT * FROM usuarios WHERE email = ? AND status = "ativo"');
        $stmt->execute([$login]);
        $usuario = $stmt->fetch();

        if ($usuario && $usuario['senha'] === $senha) {
            $sessionUser = [
                'id'    => $usuario['id'],
                'nome'  => $usuario['nome'],
                'email' => $usuario['email'],
                'tipo'  => $usuario['tipo_acesso'],
                'must_change' => ((int)($usuario['senha_temporaria'] ?? 0) === 1),
            ];
            $_SESSION['usuario'] = $sessionUser;
        }else{
            dd($cliente, $senha, $login);
            die('no fez login');
        }
    }
}

requireComercial();

// Contadores do mês corrente.
$ini = date('Y-m-01');
$fim = date('Y-m-t');

$qtdInsumos = (int)db()->query("SELECT COUNT(DISTINCT tipo, insumo_codigo) FROM custos_insumos_compras")->fetchColumn();

$quadros = [
    [
        'slug'  => 'margem-am-faturados',
        'titulo'=> 'Margem dos Pedidos A&M',
        'tag'   => 'Pedidos faturados',
        'desc'  => 'Resultado (margem) de cada pedido, com impostos, custo de MP, despesas e a separação Comercial / Campanha / Diretoria extra, sobre os pedidos faturados no A&M (Vendas > Consulta/Reimprime Pedidos, grupo Faturados: data, valor e itens do faturamento) — ou também Liberado para o faturamento, Liberado para o financeiro e Bloqueado (Vendas > Consulta/Reimprime) — buscados na hora direto no sistema A&M; nada é gravado.',
        'icon'  => 'bi-receipt',
        'cor'   => 'success',
        'contador' => null,   // sem contador: a busca no A&M só roda ao executar o relatório
        'contador_label' => 'busca sob demanda no A&M',
    ],
    [
        'slug'  => 'custos-insumos-am',
        'titulo'=> 'Custos de MP e Embalagem',
        'desc'  => 'Preço pago nas compras de matéria-prima e de material de embalagem (Listagem Resumida do A&M, importada em Excel): evolução, comparativo entre períodos e entre fornecedores.',
        'icon'  => 'bi-box2',
        'cor'   => 'primary',
        'contador' => $qtdInsumos,
        'contador_label' => 'insumos com compras importadas',
    ],
];

$pageTitle = 'Relatórios A&M';
require_once LAYOUT_PATH . '/header.php';
?>
<div class="mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-clipboard-data me-2"></i>Relatórios A&M</h4>
    <p class="text-muted small mb-0">Relatórios sobre os pedidos importados do sistema A&amp;M (Itallian Hairtech).</p>
</div>

<style>
/* O style.css força .bg-success/.bg-primary sólidos (!important), o que anula o bg-opacity-10:
   ícone e etiqueta ficavam verde-escuro sobre verde-escuro. Tom claro próprio, legível nos dois temas. */
.am-icone,.am-tag{background-color:#eaf5ef;color:var(--brand-darkest)}
.am-tag{border:1px solid var(--brand-light);font-weight:600}
[data-bs-theme="dark"] .am-icone,
[data-bs-theme="dark"] .am-tag{background-color:rgba(86,141,102,.22);color:#9ed3b1;border-color:rgba(158,211,177,.35)}
</style>
<div class="row g-3">
<?php foreach ($quadros as $q): ?>
    <div class="col-md-6 col-xl-4">
        <div class="card shadow-sm border-0 h-100 position-relative">
            <div class="card-body d-flex flex-column">
                <div class="d-flex align-items-center gap-3 mb-2">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-3 am-icone"
                          style="width:44px;height:44px;font-size:1.4rem;flex-shrink:0">
                        <i class="bi <?= $q['icon'] ?>"></i>
                    </span>
                    <div>
                        <h5 class="fw-bold mb-0"><?= e($q['titulo']) ?></h5>
                        <?php if (!empty($q['tag'])): ?>
                        <span class="badge am-tag mt-1"><?= e($q['tag']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <p class="text-muted small flex-grow-1 mb-3"><?= e($q['desc']) ?></p>
                <div class="d-flex justify-content-between align-items-end">
                    <span class="small text-muted">
                        <?php if ($q['contador'] === null): ?>
                        <i class="bi bi-cloud-download text-<?= $q['cor'] ?> me-1"></i>
                        <?php else: ?>
                        <span class="badge bg-<?= $q['cor'] ?> rounded-pill"><?= (int)$q['contador'] ?></span>
                        <?php endif; ?>
                        <?= e($q['contador_label']) ?>
                    </span>
                    <span class="btn btn-sm btn-outline-<?= $q['cor'] ?>">Abrir <i class="bi bi-arrow-right ms-1"></i></span>
                </div>
            </div>
            <a href="<?= BASE_URL ?>/admin/relatorios/<?= $q['slug'] ?>.php" class="stretched-link" aria-label="<?= e($q['titulo']) ?>"></a>
        </div>
    </div>
<?php endforeach; ?>
</div>
<?php require_once LAYOUT_PATH . '/footer.php'; ?>
