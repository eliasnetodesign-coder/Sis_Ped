<?php
require_once __DIR__ . '/../config.php';
requireCliente();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/cliente/dashboard.php'); exit;
}

$clienteId = (int)($_POST['cliente_id'] ?? 0);
$voltar    = $_POST['voltar'] ?? BASE_URL . '/cliente/dashboard.php';

// Valida que o ID pertence às opções do grupo na sessão
$ids = array_column($_SESSION['grupo_opcoes'] ?? [], 'id');
if (!$clienteId || !in_array($clienteId, $ids)) {
    flash('danger', 'Seleção inválida.');
    header('Location: ' . BASE_URL . '/cliente/dashboard.php'); exit;
}

$stmt = db()->prepare('SELECT id, razao_social, email, cnpj FROM clientes WHERE id = ? AND status = "ativo"');
$stmt->execute([$clienteId]);
$cli = $stmt->fetch();
if (!$cli) {
    flash('danger', 'Empresa não encontrada.');
    header('Location: ' . BASE_URL . '/cliente/dashboard.php'); exit;
}

$_SESSION['usuario']['id']   = $cli['id'];
$_SESSION['usuario']['nome'] = $cli['razao_social'];
$_SESSION['usuario']['cnpj'] = $cli['cnpj'] ?? '';

// Redireciona de volta à página anterior (ou dashboard)
$voltar = filter_var($voltar, FILTER_SANITIZE_URL);
if (strpos($voltar, BASE_URL) !== 0) $voltar = BASE_URL . '/cliente/dashboard.php';
header('Location: ' . $voltar); exit;
