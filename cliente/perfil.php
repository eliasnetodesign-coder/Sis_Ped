<?php
require_once __DIR__ . '/../config.php';
requireLogin();
$usr = usuario();
if ($usr['tipo'] !== 'cliente') {
    header('Location: ' . BASE_URL . '/admin/dashboard.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'senha') {
    try {
        $nova = $_POST['nova_senha']      ?? '';
        $conf = $_POST['confirmar_senha'] ?? '';
        if (!$nova)            throw new Exception('Informe a nova senha.');
        if ($nova !== $conf)   throw new Exception('As senhas não conferem.');
        if (strlen($nova) < 4) throw new Exception('A senha deve ter pelo menos 4 caracteres.');
        db()->prepare('UPDATE clientes SET senha=? WHERE id=?')->execute([$nova, $usr['id']]);
        flash('success', 'Senha alterada com sucesso!');
    } catch (Exception $e) {
        flash('danger', 'Erro: ' . $e->getMessage());
    }
    header('Location: ' . BASE_URL . '/cliente/perfil.php'); exit;
}

$cliente = db()->prepare('SELECT c.*, cv.canal FROM clientes c LEFT JOIN canal_venda cv ON cv.id = c.canal_venda_id WHERE c.id = ?');
$cliente->execute([$usr['id']]);
$cliente = $cliente->fetch();

$pageTitle = 'Meu Perfil';
require_once LAYOUT_PATH . '/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-person-circle me-2"></i>Meu Perfil</h4>
</div>

<div class="row g-4">

    <div class="col-lg-8">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="bi bi-person-lines-fill me-2 text-primary"></i>Contato e Endereço</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-muted small">E-mail <small class="fw-normal">(login)</small></label>
                        <input type="text" class="form-control bg-light" readonly value="<?= e($cliente['email'] ?? '—') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold text-muted small">Telefone 1</label>
                        <input type="text" class="form-control bg-light" readonly value="<?= e($cliente['telefone1'] ?? '—') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold text-muted small">Telefone 2</label>
                        <input type="text" class="form-control bg-light" readonly value="<?= e($cliente['telefone2'] ?? '—') ?>">
                    </div>
                    <div class="col-12"><hr class="my-1"></div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold text-muted small">CEP</label>
                        <input type="text" class="form-control bg-light" readonly value="<?= e($cliente['cep'] ?? '—') ?>">
                    </div>
                    <div class="col-md-7">
                        <label class="form-label fw-semibold text-muted small">Endereço</label>
                        <input type="text" class="form-control bg-light" readonly value="<?= e($cliente['endereco'] ?? '—') ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold text-muted small">Número</label>
                        <input type="text" class="form-control bg-light" readonly value="<?= e($cliente['numero'] ?? '—') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold text-muted small">Complemento</label>
                        <input type="text" class="form-control bg-light" readonly value="<?= e($cliente['complemento'] ?? '—') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold text-muted small">Bairro</label>
                        <input type="text" class="form-control bg-light" readonly value="<?= e($cliente['bairro'] ?? '—') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold text-muted small">Cidade</label>
                        <input type="text" class="form-control bg-light" readonly value="<?= e($cliente['cidade'] ?? '—') ?>">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label fw-semibold text-muted small">UF</label>
                        <input type="text" class="form-control bg-light" readonly value="<?= e($cliente['estado'] ?? '—') ?>">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label fw-semibold text-muted small">País</label>
                        <input type="text" class="form-control bg-light" readonly value="<?= e($cliente['pais'] ?? 'Brasil') ?>">
                    </div>
                </div>
                <div class="mt-3 pt-2 border-top">
                    <small class="text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        Para alterar seus dados, entre em contato com o suporte.
                    </small>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="bi bi-building me-2 text-primary"></i>Dados Cadastrais</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="text-muted small fw-semibold text-uppercase">Código</div>
                        <div class="fw-bold text-primary fs-5"><?= e($cliente['codigo_cliente'] ?? '—') ?></div>
                    </div>
                    <div class="col-12">
                        <div class="text-muted small fw-semibold text-uppercase">Razão Social</div>
                        <div class="fw-semibold"><?= e($cliente['razao_social'] ?? '—') ?></div>
                    </div>
                    <?php if (!empty($cliente['cnpj'])): ?>
                    <div class="col-12">
                        <div class="text-muted small fw-semibold text-uppercase">CNPJ</div>
                        <div><?= e($cliente['cnpj']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php $_supCli = $cliente['supervisor'] ?? $cliente['vendedor'] ?? ''; ?>
                    <?php if (!empty($_supCli)): ?>
                    <div class="col-12">
                        <div class="text-muted small fw-semibold text-uppercase">Supervisor</div>
                        <div><?= e($_supCli) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($cliente['canal'])): ?>
                    <div class="col-12">
                        <div class="text-muted small fw-semibold text-uppercase">Canal de Venda</div>
                        <div><?= e($cliente['canal']) ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="col-6">
                        <div class="text-muted small fw-semibold text-uppercase">Status</div>
                        <div><?= statusBadge($cliente['status'] ?? 'ativo') ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm border-start border-warning border-4">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="bi bi-shield-lock me-2 text-warning"></i>Alterar Senha</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="senha">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Nova Senha <span class="text-danger">*</span></label>
                            <input type="password" name="nova_senha" class="form-control"
                                   placeholder="••••••" autocomplete="new-password">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Confirmar Senha <span class="text-danger">*</span></label>
                            <input type="password" name="confirmar_senha" class="form-control"
                                   placeholder="••••••" autocomplete="new-password">
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-warning w-100">
                            <i class="bi bi-key me-1"></i>Alterar Senha
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>

</div>

<?php require_once LAYOUT_PATH . '/footer.php'; ?>
