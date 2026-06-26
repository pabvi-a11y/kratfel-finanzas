<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/auth.php';
auth_start();
$_SESSION = [];
session_destroy();
header('Location: ' . APP_BASE_URL . '/auth/login.php');
exit;
