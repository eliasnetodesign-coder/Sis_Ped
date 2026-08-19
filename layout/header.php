<?php
$_flash     = getFlash();
$_usuario   = usuario();
$_pageTitle = isset($pageTitle) ? $pageTitle : 'Sis_Ped';
$_pg        = basename($_SERVER['PHP_SELF'], '.php');
$_self      = $_SERVER['PHP_SELF'];

$_inCadastros   = strpos($_self, '/cadastros/')   !== false;
$_inRelatorios  = strpos($_self, '/relatorios/')  !== false;
$_inFinanceiro  = strpos($_self, '/financeiro/')  !== false;

$_sectionActive = isset($activeSection) ? $activeSection : '';
?>
<!DOCTYPE html>
<html lang="<?= htmlLang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($_pageTitle) ?> — Sis_Ped</title>
    <?php if ($_usuario && $_usuario['tipo'] !== 'cliente'): ?>
    <script>(function(){try{if(localStorage.getItem('sisped-theme')==='dark')document.documentElement.setAttribute('data-bs-theme','dark');}catch(e){}})();</script>
    <?php endif; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= ASSETS_URL ?>/css/style.css" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
    <div class="container-fluid px-3 px-md-4">
        <a class="navbar-brand p-0 fw-bold text-white" href="<?= BASE_URL ?>/index.php">
            <img src="/sistema/assets/img/Logo.png"
                 alt="Itallian Hairtech"
                 style="height:38px; filter:invert(1); mix-blend-mode:screen;"
                 onerror="this.style.display='none'">
        </a>
        <?php if ($_usuario): ?>
        <button class="btn btn-link text-white d-md-none p-1 ms-2 border-0"
                type="button"
                data-bs-toggle="offcanvas"
                data-bs-target="#sidebarMenu"
                aria-label="<?= et('Abrir menu') ?>">
            <i class="bi bi-list fs-3 lh-1"></i>
        </button>
        <div class="ms-auto d-flex align-items-center gap-2 gap-md-3">
            <?php if ($_usuario['tipo'] !== 'cliente'): ?>
            <button type="button" class="btn btn-link text-white p-1 border-0 lh-1" id="themeToggle"
                    title="<?= et('Alternar tema claro/escuro') ?>" aria-label="<?= et('Alternar tema claro/escuro') ?>">
                <i class="bi bi-moon-stars fs-5" id="themeIcon"></i>
            </button>
            <?php endif; ?>
            <?php $_temGrupo = !empty($_SESSION['grupo_opcoes']) && count($_SESSION['grupo_opcoes']) > 1; ?>
            <?php if ($_temGrupo): ?>
            <button type="button"
                    class="btn btn-link text-white text-decoration-none p-0 small d-none d-sm-inline-flex align-items-center gap-1"
                    data-bs-toggle="modal" data-bs-target="#modalTrocarCnpj"
                    title="<?= et('Trocar empresa') ?>">
                <i class="bi bi-person-circle me-1"></i><?= e($_usuario['nome']) ?>
                <?php if (!empty($_usuario['cnpj'])): ?>
                <span class="text-white-50" style="font-size:.78rem"><?= e($_usuario['cnpj']) ?></span>
                <?php endif; ?>
                <span class="badge bg-white text-primary ms-1"><?= $_usuario['tipo'] === 'cliente' ? et('Cliente') : e(ucfirst($_usuario['tipo'])) ?></span>
                <i class="bi bi-chevron-down ms-1" style="font-size:.7rem;opacity:.7"></i>
            </button>
            <?php else: ?>
            <span class="text-white small d-none d-sm-inline">
                <i class="bi bi-person-circle me-1"></i><?= e($_usuario['nome']) ?>
                <?php if (!empty($_usuario['cnpj'])): ?>
                <span class="text-white-50 ms-1" style="font-size:.78rem"><?= e($_usuario['cnpj']) ?></span>
                <?php endif; ?>
                <span class="badge bg-white text-primary ms-1"><?= $_usuario['tipo'] === 'cliente' ? et('Cliente') : e(ucfirst($_usuario['tipo'])) ?></span>
            </span>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/logout.php"
               class="btn btn-outline-light btn-sm"
               onclick="return confirm('<?= et('Deseja realmente sair?') ?>')">
                <i class="bi bi-box-arrow-right me-1"></i><span class="d-none d-sm-inline"><?= et('Sair') ?></span>
            </a>
        </div>
        <?php endif; ?>
    </div>
</nav>

<?php if (!empty($_SESSION['grupo_opcoes']) && count($_SESSION['grupo_opcoes']) > 1): ?>
<div class="modal fade" id="modalTrocarCnpj" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-building me-2 text-primary"></i><?= et('Trocar Empresa') ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-2 pb-1">
                <p class="text-muted small mb-3"><?= et('Selecione o CNPJ para o qual deseja realizar o pedido:') ?></p>
                <form method="POST" action="<?= BASE_URL ?>/cliente/trocar-cnpj.php">
                    <input type="hidden" name="voltar" value="<?= e($_SERVER['REQUEST_URI']) ?>">
                    <div class="d-flex flex-column gap-2 mb-3">
                        <?php foreach ($_SESSION['grupo_opcoes'] as $op): ?>
                        <label class="d-flex align-items-center gap-3 p-3 border rounded-3"
                               style="cursor:pointer;transition:all .15s"
                               onmouseover="this.style.background='#f0faf5';this.style.borderColor='#2b6a4d'"
                               onmouseout="this.style.background='';this.style.borderColor=''">
                            <input type="radio" name="cliente_id" value="<?= $op['id'] ?>"
                                   class="form-check-input flex-shrink-0 mt-0" style="width:1.2em;height:1.2em"
                                   <?= $op['id'] == $_usuario['id'] ? 'checked' : '' ?>>
                            <div>
                                <div class="fw-semibold" style="font-size:.9rem"><?= e($op['razao_social']) ?></div>
                                <div class="text-muted small">
                                    <?= !empty($op['cnpj']) ? e($op['cnpj']) . ' &nbsp;' : '' ?>
                                    <span class="badge bg-secondary" style="font-size:.68rem"><?= e($op['codigo_cliente']) ?></span>
                                    <?php if ($op['id'] == $_usuario['id']): ?>
                                    <span class="badge bg-success ms-1" style="font-size:.68rem"><?= et('Atual') ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="d-flex gap-2 justify-content-end pb-1">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal"><?= et('Cancelar') ?></button>
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i><?= et('Confirmar') ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($_usuario): ?>
<div class="d-flex" style="min-height: calc(100vh - 56px)">

    <!-- Sidebar: offcanvas-md = drawer no mobile, estática no desktop -->
    <nav class="sidebar offcanvas-md offcanvas-start bg-white border-end shadow-sm"
         id="sidebarMenu" tabindex="-1" style="width:220px">

        <div class="offcanvas-header border-bottom d-md-none">
            <span class="fw-semibold text-primary"><i class="bi bi-grid-3x3-gap me-2"></i><?= et('Menu') ?></span>
            <button type="button" class="btn-close"
                    data-bs-dismiss="offcanvas"
                    data-bs-target="#sidebarMenu"></button>
        </div>

        <div class="sidebar-body">
        <div class="pt-3">

        <?php if ($_usuario['tipo'] === 'cliente'):
            // Verifica Bônus MA disponível para o botão do sidebar
            $_maSidebar = 0;
            $_mn = (int)date('n'); $_ay = (int)date('Y');
            $_mp = $_mn===1?12:$_mn-1; $_ap = $_mn===1?$_ay-1:$_ay;
            $_di = sprintf('%04d-%02d-01',$_ap,$_mp);
            $_df = date('Y-m-t',mktime(0,0,0,$_mp,1,$_ap));
            $_maR = db()->prepare("
                SELECT c.material_apoio,
                       COALESCE((SELECT acao FROM bonus_ma_logs WHERE cliente_id=c.id AND mes=? AND ano=? ORDER BY id DESC LIMIT 1),'') AS acao,
                       COALESCE((SELECT valor_utilizado FROM bonus_ma_logs WHERE cliente_id=c.id AND mes=? AND ano=? ORDER BY id DESC LIMIT 1),0) AS utilizado,
                       COALESCE((SELECT SUM(valor_total * (CASE WHEN moeda <> 'BRL' AND cotacao > 0 THEN cotacao ELSE 1 END)) FROM pedidos WHERE cliente_id=c.id AND status='faturado' AND DATE(data_pedido) BETWEEN ? AND ?),0) AS fat
                FROM clientes c WHERE c.id=?
            ");
            $_maR->execute([$_mn,$_ay,$_mn,$_ay,$_di,$_df,$_usuario['id']]);
            $_maR = $_maR->fetch();
            if ($_maR && (int)$_maR['material_apoio']>0 && $_maR['acao']==='aprovado') {
                $_maTotal   = (float)$_maR['fat'] * (int)$_maR['material_apoio'] / 100;
                $_maSidebar = max(0, $_maTotal - (float)$_maR['utilizado']);
            }
        ?>
            <div class="px-3 pb-2 text-uppercase text-muted small fw-semibold"><?= et('Área do Cliente') ?></div>
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link <?= $_pg === 'dashboard' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>/cliente/dashboard.php"
                       data-bs-dismiss="offcanvas">
                        <i class="bi bi-speedometer2 me-2"></i><?= et('Dashboard') ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $_pg === 'novo-pedido' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>/cliente/novo-pedido.php"
                       data-bs-dismiss="offcanvas">
                        <i class="bi bi-plus-circle me-2"></i><?= et('Novo Pedido') ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $_pg === 'meus-pedidos' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>/cliente/meus-pedidos.php"
                       data-bs-dismiss="offcanvas">
                        <i class="bi bi-list-ul me-2"></i><?= et('Meus Pedidos') ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $_pg === 'financeiro' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>/cliente/financeiro.php"
                       data-bs-dismiss="offcanvas">
                        <i class="bi bi-receipt me-2"></i><?= et('Financeiro') ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $_pg === 'perfil' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>/cliente/perfil.php"
                       data-bs-dismiss="offcanvas">
                        <i class="bi bi-person-gear me-2"></i><?= et('Meu Perfil') ?>
                    </a>
                </li>
                <?php if ($_maSidebar > 0): ?>
                <li class="nav-item px-2 pt-2">
                    <a href="<?= BASE_URL ?>/cliente/novo-pedido.php?modo=ma_bonus"
                       class="btn btn-success btn-sm w-100 d-flex align-items-center justify-content-between"
                       data-bs-dismiss="offcanvas">
                        <span><i class="bi bi-gift me-1"></i>Bônus MA</span>
                        <strong><?= moedaBR($_maSidebar) ?></strong>
                    </a>
                </li>
                <?php endif; ?>
            </ul>

        <?php else: // admin (comercial | financeiro | supervisor) ?>
            <?php $tipoLabels = ['comercial'=>'Comercial','financeiro'=>'Financeiro','supervisor'=>'Supervisor','tecnologia da informacao'=>'Tecnologia da Informação']; ?>
            <div class="px-3 pb-2 text-uppercase text-muted small fw-semibold">
                <?= $tipoLabels[$_usuario['tipo']] ?? ucfirst($_usuario['tipo']) ?>
            </div>
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link <?= $_pg === 'dashboard' && !$_inCadastros && !$_inRelatorios && !$_inFinanceiro ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>/admin/dashboard.php"
                       data-bs-dismiss="offcanvas">
                        <i class="bi bi-speedometer2 me-2"></i>Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $_pg === 'pedidos' && !$_inCadastros && !$_inRelatorios && !$_inFinanceiro ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>/admin/pedidos.php"
                       data-bs-dismiss="offcanvas">
                        <i class="bi bi-list-check me-2"></i>Pedidos
                    </a>
                </li>
                <?php if (in_array($_usuario['tipo'], ['comercial', 'supervisor', 'tecnologia da informacao'])): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $_pg === 'novo-pedido' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>/admin/novo-pedido.php"
                       data-bs-dismiss="offcanvas">
                        <i class="bi bi-plus-circle me-2"></i>Novo Pedido
                    </a>
                </li>
                <?php endif; ?>
            </ul>

            <?php if (in_array($_usuario['tipo'], ['comercial', 'supervisor', 'tecnologia da informacao'])): ?>
            <?php
            $cadAtivo = $_inCadastros && $_pg !== 'usuarios';
            $relAtivo = $_inRelatorios;
            $admAtivo = $_inCadastros && $_pg === 'usuarios';

            $cadastroLinks = [
                ['slug'=>'produtos',        'label'=>'Produtos',            'icon'=>'bi-box-seam'],
                ['slug'=>'clientes',        'label'=>'Clientes',            'icon'=>'bi-people'],
                ['slug'=>'grupo-empresas',  'label'=>'Grupo de Empresas',   'icon'=>'bi-diagram-2'],
                ['slug'=>'tabela-precos',   'label'=>'Tabela de Preços',    'icon'=>'bi-tags'],
                ['slug'=>'custos-produtos', 'label'=>'Custos dos Produtos', 'icon'=>'bi-cash-coin'],
                ['slug'=>'campanhas',       'label'=>'Campanhas',           'icon'=>'bi-megaphone'],
                ['slug'=>'canal-venda',     'label'=>'Canal de Venda',      'icon'=>'bi-diagram-3'],
['slug'=>'ncm',             'label'=>'NCM',                 'icon'=>'bi-upc-scan'],
                ['slug'=>'metas',              'label'=>'Metas',               'icon'=>'bi-bullseye'],
                ['slug'=>'bonus-desempenho',  'label'=>'Bônus Desempenho',    'icon'=>'bi-trophy'],
                ['slug'=>'bonus-ma',          'label'=>'Bônus Mat. Apoio',    'icon'=>'bi-gift'],
                ['slug'=>'concessao-creditos','label'=>'Concess. Créditos',   'icon'=>'bi-coin'],
                ['slug'=>'impostos',          'label'=>'Impostos',            'icon'=>'bi-percent'],
            ];
            $relLinks = [
                ['slug'=>'status-pedidos',       'label'=>'Status dos Pedidos',  'icon'=>'bi-kanban'],
                ['slug'=>'faturamento-diario',   'label'=>'Fat. Diário',         'icon'=>'bi-calendar-day'],
                ['slug'=>'faturamento-mensal',   'label'=>'Fat. Mensal',         'icon'=>'bi-calendar-month'],
                ['slug'=>'faturamento-anual',    'label'=>'Fat. Anual',          'icon'=>'bi-calendar'],
                ['slug'=>'faturamento-cliente',  'label'=>'Fat. por Cliente',    'icon'=>'bi-person-lines-fill'],
                ['slug'=>'faturamento-canal',    'label'=>'Fat. por Canal',      'icon'=>'bi-diagram-3'],
                ['slug'=>'faturamento-supervisor', 'label'=>'Fat. por Supervisor', 'icon'=>'bi-person-badge'],
                ['slug'=>'faturamento-estado',   'label'=>'Fat. por Estado',     'icon'=>'bi-geo-alt'],
                ['slug'=>'faturamento-regiao',   'label'=>'Fat. por Região',     'icon'=>'bi-map'],
            ];
            ?>

            <!-- Flyout Comercial → Cadastros -->
            <ul class="nav flex-column border-top mt-2 pt-2">
                <li class="nav-item sidebar-flyout-wrap">
                    <a class="nav-link d-flex align-items-center justify-content-between <?= $cadAtivo ? 'active' : '' ?>"
                       href="#" id="cad-toggle">
                        <span><i class="bi bi-briefcase me-2"></i>Comercial</span>
                        <i class="bi bi-chevron-right small sidebar-chevron"></i>
                    </a>
                    <div class="sidebar-flyout-menu" id="submenu-cad">
                        <div class="sidebar-flyout-title">Cadastros</div>
                        <ul class="nav flex-column">
                            <?php foreach ($cadastroLinks as $lk): ?>
                            <li class="nav-item">
                                <a class="nav-link <?= $_pg === $lk['slug'] && $cadAtivo ? 'active' : '' ?>"
                                   href="<?= BASE_URL ?>/admin/cadastros/<?= $lk['slug'] ?>.php"
                                   data-bs-dismiss="offcanvas">
                                    <i class="bi <?= $lk['icon'] ?> me-2"></i><?= $lk['label'] ?>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </li>
            </ul>

            <!-- Flyout Relatórios -->
            <ul class="nav flex-column border-top mt-2 pt-2">
                <li class="nav-item sidebar-flyout-wrap">
                    <a class="nav-link d-flex align-items-center justify-content-between <?= $relAtivo ? 'active' : '' ?>"
                       href="#" id="rel-toggle">
                        <span><i class="bi bi-bar-chart-line me-2"></i>Relatórios</span>
                        <i class="bi bi-chevron-right small sidebar-chevron"></i>
                    </a>
                    <div class="sidebar-flyout-menu" id="submenu-rel">
                        <div class="sidebar-flyout-title">Relatórios</div>
                        <ul class="nav flex-column">
                            <?php foreach ($relLinks as $lk): ?>
                            <li class="nav-item">
                                <a class="nav-link <?= $_pg === $lk['slug'] ? 'active' : '' ?>"
                                   href="<?= BASE_URL ?>/admin/relatorios/<?= $lk['slug'] ?>.php"
                                   data-bs-dismiss="offcanvas">
                                    <i class="bi <?= $lk['icon'] ?> me-2"></i><?= $lk['label'] ?>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </li>
            </ul>

            <?php endif; // comercial/supervisor ?>

            <?php if (in_array($_usuario['tipo'], ['financeiro', 'tecnologia da informacao'])): ?>
            <?php
            $finModAtivo = $_inFinanceiro;
            $finLinks = [
                ['slug'=>'clientes',           'label'=>'Clientes',             'icon'=>'bi-people'],
                ['slug'=>'fornecedores',        'label'=>'Fornecedores',         'icon'=>'bi-building'],
                ['slug'=>'contas-receber',      'label'=>'Contas a Receber',    'icon'=>'bi-arrow-down-circle'],
                ['slug'=>'contas-pagar',        'label'=>'Contas a Pagar',      'icon'=>'bi-arrow-up-circle'],
                ['slug'=>'ordens-pagamento',    'label'=>'Ordens de Pagamento', 'icon'=>'bi-cash-stack'],
                ['slug'=>'ordens-investimento', 'label'=>'Ordens de Invest.',   'icon'=>'bi-graph-up-arrow'],
            ];
            $admAtivo = $_inCadastros && $_pg === 'usuarios';
            ?>
            <ul class="nav flex-column border-top mt-2 pt-2">
                <li class="nav-item sidebar-flyout-wrap">
                    <a class="nav-link d-flex align-items-center justify-content-between <?= $finModAtivo ? 'active' : '' ?>"
                       href="#" id="fin-mod-toggle">
                        <span><i class="bi bi-bank me-2"></i>Financeiro</span>
                        <i class="bi bi-chevron-right small sidebar-chevron"></i>
                    </a>
                    <div class="sidebar-flyout-menu" id="submenu-fin-mod">
                        <div class="sidebar-flyout-title">Financeiro</div>
                        <ul class="nav flex-column">
                            <?php foreach ($finLinks as $lk): ?>
                            <li class="nav-item">
                                <a class="nav-link <?= $_pg === $lk['slug'] && $finModAtivo ? 'active' : '' ?>"
                                   href="<?= BASE_URL ?>/admin/financeiro/<?= $lk['slug'] ?>.php"
                                   data-bs-dismiss="offcanvas">
                                    <i class="bi <?= $lk['icon'] ?> me-2"></i><?= $lk['label'] ?>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </li>
            </ul>
            <ul class="nav flex-column mt-1">
                <li class="nav-item">
                    <a class="nav-link <?= $_pg === 'bonus-desempenho' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>/admin/cadastros/bonus-desempenho.php"
                       data-bs-dismiss="offcanvas">
                        <i class="bi bi-trophy me-2"></i>Bônus Desempenho
                    </a>
                </li>
            </ul>
            <?php endif; // financeiro ?>

            <!-- Flyout Administração -->
            <ul class="nav flex-column border-top mt-2 pt-2">
                <li class="nav-item sidebar-flyout-wrap">
                    <a class="nav-link d-flex align-items-center justify-content-between <?= $admAtivo ? 'active' : '' ?>"
                       href="#" id="adm-toggle">
                        <span><i class="bi bi-gear me-2"></i>Administração</span>
                        <i class="bi bi-chevron-right small sidebar-chevron"></i>
                    </a>
                    <div class="sidebar-flyout-menu" id="submenu-adm">
                        <div class="sidebar-flyout-title">Administração</div>
                        <ul class="nav flex-column">
                            <li class="nav-item">
                                <a class="nav-link <?= $_pg === 'usuarios' && $admAtivo ? 'active' : '' ?>"
                                   href="<?= BASE_URL ?>/admin/cadastros/usuarios.php"
                                   data-bs-dismiss="offcanvas">
                                    <i class="bi bi-person-badge me-2"></i>Usuários
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>
            </ul>

        <?php endif; // tipo usuario ?>

        </div>
        </div><!-- /offcanvas-body -->
    </nav><!-- /sidebar -->

    <!-- Main content -->
    <main class="flex-grow-1 p-3 p-md-4" style="overflow-x: clip">
<?php endif; // usuario logado ?>

<?php if ($_flash): ?>
<div class="alert alert-<?= e($_flash['type']) ?> alert-dismissible fade show mb-4" role="alert">
    <i class="bi bi-<?= $_flash['type'] === 'success' ? 'check-circle' : ($_flash['type'] === 'danger' ? 'exclamation-triangle' : 'info-circle') ?> me-2"></i>
    <?= e($_flash['msg']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<script>
(function () {
    var menus = [
        ['cad-toggle',     'submenu-cad'],
        ['rel-toggle',     'submenu-rel'],
        ['fin-mod-toggle', 'submenu-fin-mod'],
        ['adm-toggle',     'submenu-adm'],
    ];

    var desktop = window.innerWidth >= 768;

    function setChevron(toggle, open) {
        var ch = toggle.querySelector('.sidebar-chevron');
        if (ch) ch.style.transform = open ? 'rotate(90deg)' : '';
    }

    // Desktop: exibe flyout fixo à direita via inline style (sem depender de CSS specificity)
    function showFlyout(toggle, menu) {
        var rect = toggle.getBoundingClientRect();
        menu.style.display   = 'block';
        menu.style.position  = 'fixed';
        menu.style.left      = '220px';
        menu.style.top       = rect.top + 'px';
        menu.style.zIndex    = '1049';
    }

    function hideFlyout(menu) {
        menu.style.display = 'none';
    }

    function closeAll(exceptId) {
        menus.forEach(function (p) {
            if (p[1] === exceptId) return;
            var m = document.getElementById(p[1]);
            var t = document.getElementById(p[0]);
            if (!m) return;
            if (desktop) {
                hideFlyout(m);
            } else {
                m.classList.remove('show');
            }
            if (t) setChevron(t, false);
        });
    }

    // Desktop: remove data-bs-dismiss de TODOS os links do sidebar e flyouts
    // (Bootstrap 5 chama preventDefault() em <a data-bs-dismiss>, bloqueando navegação)
    if (desktop) {
        var sidebar = document.getElementById('sidebarMenu');
        if (sidebar) {
            sidebar.querySelectorAll('[data-bs-dismiss]').forEach(function (el) {
                el.removeAttribute('data-bs-dismiss');
            });
        }
        menus.forEach(function (pair) {
            var m = document.getElementById(pair[1]);
            if (!m) return;
            m.querySelectorAll('[data-bs-dismiss]').forEach(function (el) {
                el.removeAttribute('data-bs-dismiss');
            });
            document.body.appendChild(m);
        });
    }

    menus.forEach(function (pair) {
        var toggle = document.getElementById(pair[0]);
        var menu   = document.getElementById(pair[1]);
        if (!toggle || !menu) return;

        // Marca chevron no mobile se a página atual estiver no submenu
        if (menu.querySelector('.nav-link.active')) {
            if (!desktop) {
                menu.classList.add('show');
            }
            setChevron(toggle, true);
        }

        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            var isOpen = desktop
                ? menu.style.display === 'block'
                : menu.classList.contains('show');

            closeAll(pair[1]);

            if (!isOpen) {
                if (desktop) {
                    showFlyout(toggle, menu);
                } else {
                    menu.classList.add('show');
                }
                setChevron(toggle, true);
            } else {
                if (desktop) {
                    hideFlyout(menu);
                } else {
                    menu.classList.remove('show');
                }
                setChevron(toggle, false);
            }
        });
    });

    // Fecha ao clicar fora (desktop)
    document.addEventListener('click', function (e) {
        if (!desktop) return;
        if (e.target.closest('#sidebarMenu') || e.target.closest('.sidebar-flyout-menu')) return;
        closeAll('__none__');
    });

    // Reposiciona ao rolar a página (desktop)
    window.addEventListener('scroll', function () {
        if (!desktop) return;
        menus.forEach(function (p) {
            var m = document.getElementById(p[1]);
            var t = document.getElementById(p[0]);
            if (m && m.style.display === 'block' && t) showFlyout(t, m);
        });
    }, true);
}());

// Alternância de tema claro/escuro (admin) — persiste em localStorage
(function () {
    var btn = document.getElementById('themeToggle');
    if (!btn) return;
    var icon = document.getElementById('themeIcon');
    function paint(t) {
        document.documentElement.setAttribute('data-bs-theme', t);
        if (icon) icon.className = 'bi fs-5 ' + (t === 'dark' ? 'bi-sun' : 'bi-moon-stars');
    }
    var cur = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
    paint(cur);
    btn.addEventListener('click', function () {
        cur = cur === 'dark' ? 'light' : 'dark';
        try { localStorage.setItem('sisped-theme', cur); } catch (e) {}
        paint(cur);
    });
}());
</script>
