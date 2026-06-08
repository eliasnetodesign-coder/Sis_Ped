<?php
require_once __DIR__ . '/config.php';
$u = usuario();
if (!$u) {
    header('Location: ' . BASE_URL . '/login.php');
} elseif ($u['tipo'] === 'cliente') {
    header('Location: ' . BASE_URL . '/cliente/dashboard.php');
} else {
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
}
exit;
