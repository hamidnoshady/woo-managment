<?php

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

$user = current_user();

if ($user === null) {
    header('Location: /login.php');
} else {
    header('Location: /products.php');
}
exit;
