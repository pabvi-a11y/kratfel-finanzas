<?php
declare(strict_types=1);

/**
 * Crea o actualiza un usuario. Solo CLI.
 *   php tools/create_user.php correo@dominio.com "Nombre"
 * Pedirá la contraseña por stdin (no queda en el historial).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo CLI.'); }
$email = $argv[1] ?? null;
$nombre = $argv[2] ?? null;
if (!$email) { fwrite(STDERR, "Uso: php tools/create_user.php correo \"Nombre\"\n"); exit(1); }

fwrite(STDOUT, "Contraseña para $email: ");
@shell_exec('stty -echo 2>/dev/null');
$pass = trim((string)fgets(STDIN));
@shell_exec('stty echo 2>/dev/null');
fwrite(STDOUT, "\n");
if (strlen($pass) < 10) { fwrite(STDERR, "Mínimo 10 caracteres.\n"); exit(1); }

$hash = password_hash($pass, PASSWORD_DEFAULT);
$sql = "INSERT INTO usuarios (email, pass_hash, nombre) VALUES (:e,:h,:n)
        ON DUPLICATE KEY UPDATE pass_hash=:h2, nombre=:n2";
db()->prepare($sql)->execute([':e' => $email, ':h' => $hash, ':n' => $nombre, ':h2' => $hash, ':n2' => $nombre]);
fwrite(STDOUT, "Usuario $email listo.\n");
