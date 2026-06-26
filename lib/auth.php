<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function auth_start(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
}

function current_user(): ?array {
    auth_start();
    if (empty($_SESSION['uid'])) return null;
    $st = db()->prepare("SELECT id,email,nombre FROM usuarios WHERE id=:id");
    $st->execute([':id' => $_SESSION['uid']]);
    return $st->fetch() ?: null;
}

function require_login(): array {
    $u = current_user();
    if (!$u) {
        header('Location: ' . APP_BASE_URL . '/auth/login.php');
        exit;
    }
    return $u;
}

function csrf_token(): string {
    auth_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_check(?string $t): void {
    auth_start();
    if (!$t || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) {
        http_response_code(400);
        exit('CSRF inválido.');
    }
}

function login_rate_limit(string $email): bool {
    auth_start();
    $key = 'lr_' . md5($email);
    $now = time();
    $win = $_SESSION[$key] ?? ['n' => 0, 't' => $now];
    if ($now - $win['t'] > 900) $win = ['n' => 0, 't' => $now]; // ventana 15 min
    $win['n']++;
    $_SESSION[$key] = $win;
    return $win['n'] <= 8; // máx 8 intentos / 15 min
}
