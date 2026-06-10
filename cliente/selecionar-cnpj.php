<?php
require_once __DIR__ . '/../config.php';
requireCliente();

// Só acessa se veio do login com grupo detectado
if (empty($_SESSION['grupo_selecao'])) {
    header('Location: ' . BASE_URL . '/cliente/dashboard.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clienteId = (int)($_POST['cliente_id'] ?? 0);

    // Valida que o ID selecionado está na lista de opções da sessão
    $ids = array_column($_SESSION['grupo_selecao'], 'id');
    if (!in_array($clienteId, $ids)) {
        flash('danger', 'Seleção inválida.');
        header('Location: ' . BASE_URL . '/cliente/selecionar-cnpj.php'); exit;
    }

    // Busca dados do cliente selecionado
    $stmt = db()->prepare('SELECT id, razao_social, email, cnpj FROM clientes WHERE id = ? AND status = "ativo"');
    $stmt->execute([$clienteId]);
    $cli = $stmt->fetch();
    if (!$cli) {
        flash('danger', 'Empresa não encontrada.');
        header('Location: ' . BASE_URL . '/cliente/selecionar-cnpj.php'); exit;
    }

    // Atualiza a sessão para o cliente selecionado
    $_SESSION['usuario']['id']   = $cli['id'];
    $_SESSION['usuario']['nome'] = $cli['razao_social'];
    $_SESSION['usuario']['cnpj'] = $cli['cnpj'] ?? '';
    unset($_SESSION['grupo_selecao']);

    header('Location: ' . BASE_URL . '/cliente/dashboard.php'); exit;
}

$opcoes = $_SESSION['grupo_selecao'];
$u      = usuario();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Selecionar Empresa — Sis_Ped</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f3f4f6; min-height: 100vh; font-family: 'Segoe UI', system-ui, sans-serif; }
        .sel-card { border: none; border-radius: 16px; box-shadow: 0 16px 48px rgba(0,0,0,.15); max-width: 520px; width: 100%; }
        .sel-header { background: linear-gradient(135deg, #004733, #2b6a4d); border-radius: 16px 16px 0 0; }
        .empresa-card { cursor: pointer; border: 2px solid #e0e0e0; border-radius: 12px; transition: all .15s; }
        .empresa-card:hover { border-color: #2b6a4d; background: #f0faf5; }
        .empresa-card input[type=radio]:checked ~ * { color: #004733; }
        .empresa-card.selected { border-color: #2b6a4d; background: #f0faf5; }
        .btn-confirmar { background: #2b6a4d; border-color: #2b6a4d; }
        .btn-confirmar:hover { background: #004733; border-color: #004733; }
        .btn-confirmar:disabled { opacity: .5; }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center py-5">
<div class="px-3 w-100 d-flex justify-content-center">
    <div class="sel-card card">
        <div class="sel-header text-white text-center py-4 px-4">
            <i class="bi bi-building display-6 d-block mb-2"></i>
            <h5 class="fw-bold mb-1">Selecionar Empresa</h5>
            <div class="small text-white-50">
                Olá, <strong class="text-white"><?= e($u['nome']) ?></strong>.<br>
                Com qual CNPJ deseja realizar o pedido?
            </div>
        </div>
        <div class="card-body p-4">
            <form method="POST" id="formSelecao">
                <div class="d-flex flex-column gap-3 mb-4">
                    <?php foreach ($opcoes as $op): ?>
                    <label class="empresa-card p-3 d-flex align-items-center gap-3"
                           for="cli_<?= $op['id'] ?>">
                        <input type="radio" name="cliente_id" id="cli_<?= $op['id'] ?>"
                               value="<?= $op['id'] ?>" class="form-check-input flex-shrink-0 mt-0"
                               style="width:1.3em;height:1.3em"
                               <?= $op['id'] == $u['id'] ? 'checked' : '' ?>>
                        <div class="flex-grow-1">
                            <div class="fw-bold" style="font-size:.95rem"><?= e($op['razao_social']) ?></div>
                            <div class="text-muted small">
                                <?php if (!empty($op['cnpj'])): ?>
                                <i class="bi bi-upc me-1"></i><?= e($op['cnpj']) ?>
                                <?php endif; ?>
                                <span class="ms-2 badge bg-secondary" style="font-size:.7rem"><?= e($op['codigo_cliente']) ?></span>
                            </div>
                        </div>
                        <i class="bi bi-check-circle-fill text-success fs-5" style="display:none" aria-hidden="true"></i>
                    </label>
                    <?php endforeach; ?>
                </div>

                <button type="submit" class="btn btn-confirmar btn-lg w-100 fw-semibold text-white" id="btnConfirmar">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Continuar
                </button>
            </form>

            <div class="text-center mt-3">
                <a href="<?= BASE_URL ?>/logout.php" class="text-muted small text-decoration-none">
                    <i class="bi bi-box-arrow-left me-1"></i>Sair e entrar com outra conta
                </a>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.empresa-card').forEach(function(card) {
    var radio   = card.querySelector('input[type=radio]');
    var checkIc = card.querySelector('.bi-check-circle-fill');

    function update() {
        document.querySelectorAll('.empresa-card').forEach(function(c) {
            var r = c.querySelector('input[type=radio]');
            var i = c.querySelector('.bi-check-circle-fill');
            c.classList.toggle('selected', r.checked);
            if (i) i.style.display = r.checked ? '' : 'none';
        });
    }

    card.addEventListener('click', function() { radio.checked = true; update(); });
    radio.addEventListener('change', update);
    update();
});
</script>
</body>
</html>
