<?php
declare(strict_types=1);

/**
 * Carga de configuración desde variables de entorno.
 * En producción (SiteGround) las variables se definen en el panel / .env fuera del webroot.
 * NUNCA hay secretos hardcodeados aquí.
 */

// Carga simple de .env si existe (en local). En SiteGround puedes usar variables del sistema.
(function () {
    $envFile = __DIR__ . '/.env';
    if (is_readable($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line === '' || $line[0] === '#') continue;
            if (!str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k); $v = trim($v);
            if ($k !== '' && getenv($k) === false) {
                putenv("$k=$v");
                $_ENV[$k] = $v;
            }
        }
    }
})();

function env(string $key, ?string $default = null): ?string {
    $v = getenv($key);
    if ($v === false || $v === '') return $default;
    return $v;
}

date_default_timezone_set(env('APP_TIMEZONE', 'America/Chicago'));

// Endpoints base según entorno QBO
$qboEnv = env('QBO_ENV', 'sandbox');
define('QBO_ENV', $qboEnv);
define('QBO_API_BASE', $qboEnv === 'production'
    ? 'https://quickbooks.api.intuit.com'
    : 'https://sandbox-quickbooks.api.intuit.com');
// Documento de descubrimiento OAuth2 (de aquí se leen los endpoints reales, no se hardcodean)
define('QBO_DISCOVERY_URL', $qboEnv === 'production'
    ? 'https://developer.api.intuit.com/.well-known/openid_configuration'
    : 'https://developer.api.intuit.com/.well-known/openid_sandbox_configuration');

define('QBO_CLIENT_ID', env('QBO_CLIENT_ID', ''));
define('QBO_CLIENT_SECRET', env('QBO_CLIENT_SECRET', ''));
define('QBO_REDIRECT_URI', env('QBO_REDIRECT_URI', ''));
define('QBO_SCOPE', 'com.intuit.quickbooks.accounting');

define('APP_BASE_URL', rtrim(env('APP_BASE_URL', ''), '/'));
define('SUPPORT_EMAIL', env('SUPPORT_EMAIL', 'admin@usuarioseri2y3.com'));
define('APP_ENCRYPTION_KEY', env('APP_ENCRYPTION_KEY', ''));

// Cookies de sesión seguras
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
