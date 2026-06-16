<?php
require_once __DIR__ . '/config.php';

// Já logado: nada a verificar
if (usuario()) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

// Sem verificação pendente: volta ao login
$pendente = $_SESSION['login_2fa'] ?? null;
if (!$pendente) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

const MAX_TENTATIVAS = 5;
$flash = getFlash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? 'verificar';

    // Reenviar código
    if ($acao === 'reenviar') {
        $st = db()->prepare('SELECT celular FROM usuarios WHERE id = ?');
        $st->execute([(int)$pendente['usuario']['id']]);
        $tel = $st->fetchColumn();
        if ($tel) {
            $codigo  = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $enviado = enviarWhatsappCodigo($tel, $codigo, (int)$pendente['usuario']['id']);
            if ($enviado) {
                $_SESSION['login_2fa']['codigo_hash'] = password_hash($codigo, PASSWORD_DEFAULT);
                $_SESSION['login_2fa']['expira']      = time() + WHATSAPP_CODIGO_VALIDADE;
                $_SESSION['login_2fa']['tentativas']  = 0;
                flash('success', 'Novo código enviado por WhatsApp.');
            } else {
                flash('danger', 'Não foi possível reenviar o código. Tente novamente.');
            }
        } else {
            flash('danger', 'Celular não encontrado. Procure o administrador.');
        }
        header('Location: ' . BASE_URL . '/verificar-acesso.php');
        exit;
    }

    // Verificar código informado
    $codigo = preg_replace('/\D+/', '', $_POST['codigo'] ?? '');

    if (time() > (int)$pendente['expira']) {
        unset($_SESSION['login_2fa']);
        flash('danger', 'O código expirou. Faça login novamente.');
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }

    if ((int)$pendente['tentativas'] >= MAX_TENTATIVAS) {
        unset($_SESSION['login_2fa']);
        flash('danger', 'Número de tentativas excedido. Faça login novamente.');
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }

    if ($codigo && password_verify($codigo, $pendente['codigo_hash'])) {
        // Sucesso: efetiva o login
        $_SESSION['usuario'] = $pendente['usuario'];
        unset($_SESSION['login_2fa']);
        header('Location: ' . BASE_URL . '/admin/dashboard.php');
        exit;
    }

    $_SESSION['login_2fa']['tentativas'] = (int)$pendente['tentativas'] + 1;
    $restantes = MAX_TENTATIVAS - $_SESSION['login_2fa']['tentativas'];
    flash('danger', 'Código inválido.' . ($restantes > 0 ? " Tentativas restantes: {$restantes}." : ''));
    header('Location: ' . BASE_URL . '/verificar-acesso.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificação de Acesso — Sis_Ped</title>
    <!-- 2FA via WhatsApp para usuários externos -->

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f3f4f6; min-height: 100vh; font-family: 'Segoe UI', system-ui, sans-serif; }
        .login-card { border: none; border-radius: 16px; box-shadow: 0 16px 48px rgba(0,0,0,.2); }
        .login-header { background: linear-gradient(135deg, #004733, #2b6a4d); border-radius: 16px 16px 0 0; }
        .btn-primary { background-color: #2b6a4d; border-color: #2b6a4d; }
        .btn-primary:hover { background-color: #004733 !important; border-color: #004733 !important; }
        .form-control:focus { border-color: #568d66; box-shadow: 0 0 0 .2rem rgba(86,141,102,.2); }
        .form-control { border-radius: 8px; }
        .btn { border-radius: 8px; font-weight: 500; }
        .codigo-input { letter-spacing: .5rem; text-align: center; font-size: 1.5rem; font-weight: 600; }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center py-5">
    <div style="width:100%;max-width:420px" class="px-3">
        <div class="card login-card">
            <div class="login-header text-white text-center py-4">
                <i class="bi bi-whatsapp" style="font-size:3rem"></i>
                <div class="h5 mt-2 mb-0">Verificação de Acesso</div>
                <div class="small text-white-50">Confirme que é você</div>
            </div>

            <div class="card-body p-4">
                <?php if ($flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?> py-2 mb-3">
                    <i class="bi bi-exclamation-triangle me-1"></i><?= e($flash['msg']) ?>
                </div>
                <?php endif; ?>

                <p class="text-muted small mb-4">
                    Enviamos um código de verificação por <strong>WhatsApp</strong> para o celular terminado em
                    <strong><?= e(substr($pendente['telefone'], -2)) ?></strong>.
                    Digite o código de 6 dígitos para concluir o acesso.
                </p>

                <form method="POST" action="<?= BASE_URL ?>/verificar-acesso.php">
                    <input type="hidden" name="acao" value="verificar">
                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="codigo">
                            <i class="bi bi-key me-1"></i>Código de verificação
                        </label>
                        <input type="text" class="form-control form-control-lg codigo-input" id="codigo"
                               name="codigo" inputmode="numeric" maxlength="6" pattern="[0-9]{6}"
                               placeholder="••••••" required autofocus autocomplete="one-time-code">
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg w-100 fw-semibold">
                        <i class="bi bi-check2-circle me-2"></i>Verificar e Entrar
                    </button>
                </form>

                <form method="POST" action="<?= BASE_URL ?>/verificar-acesso.php" class="mt-3 text-center">
                    <input type="hidden" name="acao" value="reenviar">
                    <button type="submit" class="btn btn-link text-decoration-none text-muted small p-0">
                        <i class="bi bi-whatsapp me-1"></i>Não recebeu? Reenviar código
                    </button>
                </form>
            </div>

            <div class="card-footer text-center py-3">
                <a href="<?= BASE_URL ?>/login.php" class="text-decoration-none text-muted small">
                    <i class="bi bi-arrow-left me-1"></i>Voltar ao login
                </a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
