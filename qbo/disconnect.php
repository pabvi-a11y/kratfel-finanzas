<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/qbo.php';

require_login();

// Solo POST con CSRF (acción que cambia estado)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Usa POST.'); }
csrf_check($_POST['csrf'] ?? null);

$row = qbo_connection();
if ($row) {
    try { qbo_revoke(crypto_decrypt($row['refresh_token_enc'])); } catch (Throwable $e) { /* best-effort */ }
    db()->prepare("DELETE FROM qbo_oauth WHERE id=:id")->execute([':id' => $row['id']]);
}

header('Location: ' . APP_BASE_URL . '/disconnected.html');
exit;
