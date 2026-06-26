<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/qbo.php';

require_login();
auth_start();

// state anti-CSRF para el flujo OAuth
$state = bin2hex(random_bytes(24));
$_SESSION['qbo_state'] = $state;

header('Location: ' . qbo_auth_url($state));
exit;
