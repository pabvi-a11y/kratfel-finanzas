<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $fecha = $_POST['fecha'] ?? date('Y-m-d');
    $saldo = (float)str_replace([',', '$'], '', (string)($_POST['saldo'] ?? '0'));
    db()->prepare("INSERT INTO saldos (cuenta, fecha, saldo, fuente) VALUES ('Cetera',:f,:s,'adviceworks_manual')")
        ->execute([':f' => $fecha, ':s' => $saldo]);
    header('Location: ' . APP_BASE_URL . '/?saldo=ok');
    exit;
}
header('Location: ' . APP_BASE_URL . '/');
