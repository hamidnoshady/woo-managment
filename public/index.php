<?php

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/auth.php';

$pdo = Database::get();
$hasSuperadmin = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'superadmin'")->fetchColumn() > 0;

if (!$hasSuperadmin) {
    header('Location: /install.php');
    exit;
}

$user = current_user();

if ($user === null) {
    header('Location: /login.php');
} else {
    header('Location: /products.php');
}
exit;
