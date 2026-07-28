<?php
define('DB_HOST', 'mariadb');
define('DB_NAME', 'sis_ped');
define('DB_USER', 'sis_ped_user');
define('DB_PASS', 'WFZ9STR344a1EbRDVzZf');

$msg = '';
$error = '';

if (isset($_POST['instalar'])) {
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $sql = file_get_contents(__DIR__ . '/sis_ped.sql');
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt) $pdo->exec($stmt);
        }
        $msg = 'Banco de dados instalado com sucesso! <a href="/Sis_Ped/login.php">Ir para o login</a>';
    } catch (Exception $e) {
        $error = 'Erro: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalação — Sis_Ped</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f3f4f6; min-height: 100vh; }
        .install-card { border: none; border-radius: 16px; box-shadow: 0 16px 48px rgba(0,0,0,.2); }
        .install-header { background: linear-gradient(135deg, #004733, #2b6a4d); border-radius: 16px 16px 0 0; }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center py-5">
    <div style="width:100%;max-width:480px" class="px-3">
        <div class="card install-card">
            <div class="install-header text-white text-center py-4">
                <h4 class="mb-0 fw-bold">Sis_Ped — Instalação</h4>
                <div class="small mt-1 text-white-50">Sistema de Pedidos</div>
            </div>
            <div class="card-body p-4">
                <?php if ($msg): ?>
                <div class="alert alert-success"><?= $msg ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <p class="text-muted small">Este script criará o banco de dados <strong>sis_ped</strong> e todas as tabelas necessárias, além de inserir dados de exemplo.</p>
                <ul class="small text-muted mb-4">
                    <li>Host: <strong><?= DB_HOST ?></strong></li>
                    <li>Banco: <strong><?= DB_NAME ?></strong></li>
                    <li>Usuário: <strong><?= DB_USER ?></strong></li>
                </ul>
                <form method="POST">
                    <button type="submit" name="instalar" class="btn btn-success w-100 fw-semibold">
                        Instalar Banco de Dados
                    </button>
                </form>
            </div>
            <div class="card-footer text-center text-muted py-3 small">
                Usuários de teste após instalação:<br>
                <strong>Cliente:</strong> cliente@teste.com | Senha: 123<br>
                <strong>Comercial:</strong> comercial@teste.com | Senha: 123<br>
                <strong>Financeiro:</strong> financeiro@teste.com | Senha: 123
            </div>
        </div>
    </div>
</body>
</html>
