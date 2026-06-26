<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/qbo.php';

require_login();
auth_start();

$state = $_GET['state'] ?? '';
$code  = $_GET['code'] ?? '';
$realm = $_GET['realmId'] ?? '';

if (!$state || !hash_equals($_SESSION['qbo_state'] ?? '', $state)) {
    http_response_code(400);
    exit('State inválido (posible CSRF). Vuelve a intentar la conexión.');
}
unset($_SESSION['qbo_state']);

if (!$code || !$realm) {
    http_response_code(400);
    exit('Faltan parámetros de autorización.');
}

try {
    $tok = qbo_exchange_code($code);
    qbo_store_tokens($realm, $tok);
} catch (Throwable $e) {
    http_response_code(500);
    exit('No se pudo completar la conexión con QuickBooks: ' . htmlspecialchars($e->getMessage()));
}

header('Location: ' . APP_BASE_URL . '/?qbo=conectado');
exit;
