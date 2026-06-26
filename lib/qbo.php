<?php
declare(strict_types=1);

/**
 * Cliente OAuth2 + API de QuickBooks Online.
 * - Lee endpoints del discovery document (no hardcodea el flujo).
 * - Tokens cifrados en reposo (lib/crypto.php).
 * - El refresh ROTA el refresh_token: SIEMPRE se guarda el nuevo.
 */

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/db.php';

const QBO_REVOKE_URL = 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke';

function qbo_http(string $method, string $url, array $opts = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $opts['headers'] ?? [],
    ]);
    if (isset($opts['body'])) curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
    if (isset($opts['userpwd'])) curl_setopt($ch, CURLOPT_USERPWD, $opts['userpwd']);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) throw new RuntimeException("HTTP error: $err");
    return ['status' => $code, 'body' => $resp, 'json' => json_decode($resp, true)];
}

function qbo_discovery(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $r = qbo_http('GET', QBO_DISCOVERY_URL);
    if ($r['status'] !== 200 || !is_array($r['json'])) {
        throw new RuntimeException('No se pudo leer el discovery document de Intuit.');
    }
    return $cfg = $r['json'];
}

function qbo_auth_url(string $state): string {
    $cfg = qbo_discovery();
    $q = http_build_query([
        'client_id' => QBO_CLIENT_ID,
        'response_type' => 'code',
        'scope' => QBO_SCOPE,
        'redirect_uri' => QBO_REDIRECT_URI,
        'state' => $state,
    ]);
    return $cfg['authorization_endpoint'] . '?' . $q;
}

function qbo_token_request(array $form): array {
    $cfg = qbo_discovery();
    $r = qbo_http('POST', $cfg['token_endpoint'], [
        'headers' => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        'userpwd' => QBO_CLIENT_ID . ':' . QBO_CLIENT_SECRET,
        'body' => http_build_query($form),
    ]);
    if ($r['status'] !== 200 || empty($r['json']['access_token'])) {
        throw new RuntimeException('Fallo al obtener token QBO: ' . $r['body']);
    }
    return $r['json'];
}

function qbo_exchange_code(string $code): array {
    return qbo_token_request([
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => QBO_REDIRECT_URI,
    ]);
}

/** Guarda (o actualiza) tokens cifrados para un realm. */
function qbo_store_tokens(string $realm, array $tok): void {
    $expiresAt = (new DateTime('now'))->modify('+' . (int)($tok['expires_in'] ?? 3600) . ' seconds')->format('Y-m-d H:i:s');
    $sql = "INSERT INTO qbo_oauth (realm_id, access_token_enc, refresh_token_enc, expires_at, estado)
            VALUES (:r,:a,:rf,:e,'conectado')
            ON DUPLICATE KEY UPDATE access_token_enc=:a2, refresh_token_enc=:rf2, expires_at=:e2, estado='conectado'";
    $st = db()->prepare($sql);
    $a = crypto_encrypt($tok['access_token']);
    $rf = crypto_encrypt($tok['refresh_token']);
    $st->bindValue(':r', $realm);
    $st->bindValue(':a', $a, PDO::PARAM_LOB);
    $st->bindValue(':rf', $rf, PDO::PARAM_LOB);
    $st->bindValue(':e', $expiresAt);
    $st->bindValue(':a2', $a, PDO::PARAM_LOB);
    $st->bindValue(':rf2', $rf, PDO::PARAM_LOB);
    $st->bindValue(':e2', $expiresAt);
    $st->execute();
}

function qbo_connection(): ?array {
    $row = db()->query("SELECT * FROM qbo_oauth ORDER BY id DESC LIMIT 1")->fetch();
    return $row ?: null;
}

/** Devuelve un access token válido, refrescando (y rotando el refresh) si hace falta. */
function qbo_valid_access_token(): array {
    $row = qbo_connection();
    if (!$row) throw new RuntimeException('QBO no conectado. Haz el consent primero.');
    $realm = $row['realm_id'];
    $expira = new DateTime($row['expires_at']);
    $ahora = new DateTime('now');
    if ($expira->getTimestamp() - $ahora->getTimestamp() > 120) {
        return ['realm' => $realm, 'access_token' => crypto_decrypt($row['access_token_enc'])];
    }
    // Refrescar
    $refresh = crypto_decrypt($row['refresh_token_enc']);
    try {
        $tok = qbo_token_request(['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
    } catch (Throwable $e) {
        db()->prepare("UPDATE qbo_oauth SET estado='desconectado' WHERE realm_id=:r")
            ->execute([':r' => $realm]);
        throw $e; // el dashboard mostrará "Re-autorizar"
    }
    // Intuit a veces no reenvía refresh_token; conservar el anterior si falta.
    if (empty($tok['refresh_token'])) $tok['refresh_token'] = $refresh;
    qbo_store_tokens($realm, $tok);
    return ['realm' => $realm, 'access_token' => $tok['access_token']];
}

/** GET a la API v3 de la company. $path p.ej. "reports/TransactionList?start_date=2026-01-01" */
function qbo_api_get(string $path): array {
    $t = qbo_valid_access_token();
    $url = QBO_API_BASE . '/v3/company/' . $t['realm'] . '/' . $path;
    $sep = str_contains($path, '?') ? '&' : '?';
    $url .= $sep . 'minorversion=70';
    $r = qbo_http('GET', $url, ['headers' => [
        'Authorization: Bearer ' . $t['access_token'],
        'Accept: application/json',
    ]]);
    // intuit_tid para diagnóstico (recomendado por Intuit)
    if ($r['status'] >= 400) {
        throw new RuntimeException("QBO API $path -> HTTP {$r['status']}: {$r['body']}");
    }
    return $r['json'] ?? [];
}

function qbo_revoke(string $token): void {
    qbo_http('POST', QBO_REVOKE_URL, [
        'headers' => ['Accept: application/json', 'Content-Type: application/json'],
        'userpwd' => QBO_CLIENT_ID . ':' . QBO_CLIENT_SECRET,
        'body' => json_encode(['token' => $token]),
    ]);
}
