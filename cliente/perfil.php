<?php
require_once __DIR__ . '/../config.php';
requireLogin();
$usr = usuario();
if ($usr['tipo'] !== 'cliente') {
    header('Location: ' . BASE_URL . '/admin/dashboard.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    try {
        if ($a === 'perfil') {
            $email = trim($_POST['email'] ?? '');
            if ($email) {
                $chk = db()->prepare('SELECT id FROM clientes WHERE email = ? AND id != ?');
                $chk->execute([$email, $usr['id']]);
                if ($chk->fetchColumn()) throw new Exception('Este e-mail já está em uso por outro cliente.');
            }
            db()->prepare('UPDATE clientes SET
                email=?,telefone1=?,telefone2=?,
                cep=?,endereco=?,numero=?,complemento=?,
                bairro=?,cidade=?,estado=?,pais=?
                WHERE id=?')
                ->execute([
                    $email,
                    trim($_POST['telefone1']   ?? ''),
                    trim($_POST['telefone2']   ?? ''),
                    trim($_POST['cep']         ?? ''),
                    trim($_POST['endereco']    ?? ''),
                    trim($_POST['numero']      ?? ''),
                    trim($_POST['complemento'] ?? ''),
                    trim($_POST['bairro']      ?? ''),
                    trim($_POST['cidade']      ?? ''),
                    trim($_POST['estado']      ?? ''),
                    trim($_POST['pais']        ?? ''),
                    $usr['id'],
                ]);
            $_SESSION['usuario']['email'] = $email;
            flash('success', 'Perfil atualizado com sucesso!');
        } elseif ($a === 'senha') {
            $nova = $_POST['nova_senha']      ?? '';
            $conf = $_POST['confirmar_senha'] ?? '';
            if (!$nova)          throw new Exception('Informe a nova senha.');
            if ($nova !== $conf) throw new Exception('As senhas não conferem.');
            if (strlen($nova) < 4) throw new Exception('A senha deve ter pelo menos 4 caracteres.');
            db()->prepare('UPDATE clientes SET senha=? WHERE id=?')->execute([$nova, $usr['id']]);
            flash('success', 'Senha alterada com sucesso!');
        }
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

    <!-- Coluna principal — dados editáveis -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="bi bi-pencil-square me-2 text-primary"></i>Contato e Endereço</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="perfil">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">E-mail <small class="text-muted fw-normal">(usado no login)</small></label>
                            <input type="email" name="email" class="form-control"
                                   value="<?= e($cliente['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Telefone 1</label>
                            <input type="text" name="telefone1" class="form-control"
                                   value="<?= e($cliente['telefone1'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Telefone 2</label>
                            <input type="text" name="telefone2" class="form-control"
                                   value="<?= e($cliente['telefone2'] ?? '') ?>">
                        </div>
                        <div class="col-12"><hr class="my-1"></div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">CEP</label>
                            <input type="text" name="cep" class="form-control"
                                   value="<?= e($cliente['cep'] ?? '') ?>">
                        </div>
                        <div class="col-md-7">
                            <label class="form-label fw-semibold">Endereço</label>
                            <input type="text" name="endereco" class="form-control"
                                   value="<?= e($cliente['endereco'] ?? '') ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Número</label>
                            <input type="text" name="numero" class="form-control"
                                   value="<?= e($cliente['numero'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Complemento</label>
                            <input type="text" name="complemento" class="form-control"
                                   value="<?= e($cliente['complemento'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Bairro</label>
                            <input type="text" name="bairro" class="form-control"
                                   value="<?= e($cliente['bairro'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Cidade</label>
                            <input type="text" name="cidade" class="form-control"
                                   value="<?= e($cliente['cidade'] ?? '') ?>">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label fw-semibold">UF</label>
                            <input type="text" name="estado" class="form-control"
                                   value="<?= e($cliente['estado'] ?? '') ?>" maxlength="2">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label fw-semibold">País</label>
                            <input type="text" name="pais" class="form-control"
                                   value="<?= e($cliente['pais'] ?? 'Brasil') ?>">
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-save me-1"></i>Salvar Alterações
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Coluna lateral -->
    <div class="col-lg-4">

        <!-- Dados da empresa (somente leitura) -->
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
                    <?php if (!empty($cliente['vendedor'])): ?>
                    <div class="col-12">
                        <div class="text-muted small fw-semibold text-uppercase">Vendedor</div>
                        <div><?= e($cliente['vendedor']) ?></div>
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
                <div class="mt-3 pt-2 border-top">
                    <small class="text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        Para alterar dados cadastrais, entre em contato com o suporte.
                    </small>
                </div>
            </div>
        </div>

        <!-- Alterar senha -->
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
