<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST']); exit; }
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$to = filter_var(trim((string)($in['to'] ?? '')), FILTER_VALIDATE_EMAIL);
if (!$to) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'correo inválido']); exit; }
$title = substr(strip_tags((string)($in['title'] ?? 'Reporte')), 0, 120);
$html  = (string)($in['html'] ?? '');
// Convertir inputs editables (forecast) a su valor de texto
$html = preg_replace('/<input\b[^>]*\bvalue="([^"]*)"[^>]*>/i', '$1', $html);
// Quitar elementos no deseados
$html = preg_replace('#<(script|style)\b.*?</\1>#is', '', $html);

$body = '<div style="font-family:Arial,Helvetica,sans-serif;color:#111;max-width:900px">'
  . '<h2 style="font-size:18px;margin:0 0 12px">' . htmlspecialchars($title) . '</h2>'
  . '<style>table{border-collapse:collapse;width:100%;font-size:12px;margin:8px 0}'
  . 'th,td{border:1px solid #e2e4ea;padding:5px 8px;text-align:right}'
  . 'th:first-child,td:first-child{text-align:left}thead th{background:#f3f4f6;color:#333}</style>'
  . $html
  . '<p style="color:#888;font-size:11px;margin-top:14px">KRATFEL Finanzas · ' . date('d/m/Y H:i') . '</p></div>';

$subject = '=?UTF-8?B?' . base64_encode('KRATFEL · ' . $title) . '?=';
$from = 'noreply@usuarioseri2y3.com';
$headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: KRATFEL Finanzas <{$from}>\r\n";
$ok = @mail($to, $subject, $body, $headers);
echo json_encode(['ok' => (bool)$ok]);
