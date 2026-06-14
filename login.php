<?php
require_once __DIR__ . '/config.php';

if (usuario()) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$flash = getFlash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $senha = trim($_POST['senha'] ?? '');

    if ($login && $senha) {
        // Verificar clientes pelo e-mail
        $stmt = db()->prepare('SELECT * FROM clientes WHERE email = ? AND status = "ativo"');
        $stmt->execute([$login]);
        $cliente = $stmt->fetch();

        if ($cliente && $cliente['senha'] === $senha) {
            $_SESSION['usuario'] = [
                'id'    => $cliente['id'],
                'nome'  => $cliente['razao_social'],
                'email' => $cliente['email'],
                'cnpj'  => $cliente['cnpj'] ?? '',
                'tipo'  => 'cliente',
                'must_change' => ((int)($cliente['senha_temporaria'] ?? 0) === 1),
            ];

            // Verifica se pertence a um grupo de empresas com outros membros
            $grupoStmt = db()->prepare("
                SELECT DISTINCT c.id, c.razao_social, c.cnpj, c.codigo_cliente
                FROM grupo_empresas_clientes gc1
                JOIN grupo_empresas_clientes gc2 ON gc2.grupo_id = gc1.grupo_id
                JOIN clientes c ON c.id = gc2.cliente_id
                WHERE gc1.cliente_id = ? AND c.status = 'ativo'
                ORDER BY c.razao_social
            ");
            $grupoStmt->execute([$cliente['id']]);
            $grupoMembros = $grupoStmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($grupoMembros) > 1) {
                $_SESSION['grupo_opcoes']  = $grupoMembros;
                $_SESSION['grupo_selecao'] = $grupoMembros;
                header('Location: ' . BASE_URL . '/cliente/selecionar-cnpj.php');
            } else {
                header('Location: ' . BASE_URL . '/cliente/dashboard.php');
            }
            exit;
        }

        // Verificar usuários admin pelo e-mail
        $stmt = db()->prepare('SELECT * FROM usuarios WHERE email = ? AND status = "ativo"');
        $stmt->execute([$login]);
        $usuario = $stmt->fetch();

        if ($usuario && $usuario['senha'] === $senha) {
            $_SESSION['usuario'] = [
                'id'    => $usuario['id'],
                'nome'  => $usuario['nome'],
                'email' => $usuario['email'],
                'tipo'  => $usuario['tipo_acesso'],
                'must_change' => ((int)($usuario['senha_temporaria'] ?? 0) === 1),
            ];
            header('Location: ' . BASE_URL . '/admin/dashboard.php');
            exit;
        }

        flash('danger', 'E-mail ou senha inválidos.');
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    } else {
        flash('danger', 'Preencha o e-mail e a senha.');
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Sis_Ped</title>
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
    </style>
</head>
<body class="d-flex align-items-center justify-content-center py-5">
    <div style="width:100%;max-width:420px" class="px-3">
        <div class="card login-card">
            <div class="login-header text-white text-center py-4">
                <img src="/sistema/assets/img/Logo.png"
                     alt="Itallian Hairtech"
                     style="max-height:180px; width:auto; filter:invert(1); mix-blend-mode:screen;"
                     onerror="this.style.display='none'">
                <div class="small text-white-50 mt-2">Portal de Acesso</div>
            </div>

            <div class="card-body p-4">
                <?php if ($flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?> py-2 mb-3">
                    <i class="bi bi-exclamation-triangle me-1"></i><?= e($flash['msg']) ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="<?= BASE_URL ?>/login.php" novalidate>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="login">
                            <i class="bi bi-envelope me-1"></i>E-mail
                        </label>
                        <input type="email" class="form-control form-control-lg" id="login"
                               name="login" placeholder="Seu e-mail de acesso"
                               value="<?= e($_POST['login'] ?? '') ?>"
                               required autofocus autocomplete="email">
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="senha">
                            <i class="bi bi-lock me-1"></i>Senha
                        </label>
                        <div class="input-group">
                            <input type="password" class="form-control form-control-lg" id="senha"
                                   name="senha" placeholder="••••••"
                                   required autocomplete="current-password">
                            <button type="button" class="btn btn-outline-secondary"
                                    onclick="toggleSenha()">
                                <i class="bi bi-eye" id="eyeIcon"></i>
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg w-100 fw-semibold">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Entrar
                    </button>
                </form>
            </div>

            <div class="card-footer text-center text-muted py-3 small">
                <strong>Usuários de teste</strong><br>
                Cliente: <code>cliente@teste.com</code> &bull; Admin: <code>comercial@teste.com</code><br>
                Senha: <code>123</code>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function toggleSenha() {
        var input = document.getElementById('senha');
        var icon  = document.getElementById('eyeIcon');
        if (input.type === 'password') {
            input.type = 'text';
            icon.className = 'bi bi-eye-slash';
        } else {
            input.type = 'password';
            icon.className = 'bi bi-eye';
        }
    }
    </script>
</body>
</html>
