<?php
require_once __DIR__ . '/config.php';
requireLogin();
$u = usuario();

$dest = ($u['tipo'] === 'cliente')
    ? BASE_URL . '/cliente/dashboard.php'
    : BASE_URL . '/admin/dashboard.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nova = trim($_POST['nova_senha'] ?? '');
    $conf = trim($_POST['confirmar_senha'] ?? '');
    try {
        if (!$nova)             throw new Exception('Informe a nova senha.');
        if ($nova !== $conf)    throw new Exception('As senhas não conferem.');
        if (strlen($nova) < 4)  throw new Exception('A senha deve ter pelo menos 4 caracteres.');
        if ($nova === '123')    throw new Exception('Escolha uma senha diferente da senha padrão.');

        $tabela = ($u['tipo'] === 'cliente') ? 'clientes' : 'usuarios';
        db()->prepare("UPDATE $tabela SET senha=?, senha_temporaria=0 WHERE id=?")
           ->execute([$nova, $u['id']]);

        $_SESSION['usuario']['must_change'] = false;
        flash('success', 'Senha alterada com sucesso!');
    } catch (Exception $e) {
        flash('danger', 'Erro: ' . $e->getMessage());
    }
    header('Location: ' . $dest);
    exit;
}

header('Location: ' . $dest);
exit;
