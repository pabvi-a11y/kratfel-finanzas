<?php
declare(strict_types=1);

/**
 * Aplica db/schema.sql usando las credenciales del .env (vía PDO).
 * Uso (CLI):  php tools/migrate.php
 * No expone la contraseña en la línea de comandos.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo CLI.'); }

$sql = file_get_contents(__DIR__ . '/../db/schema.sql');
if ($sql === false) { fwrite(STDERR, "No se encontró db/schema.sql\n"); exit(1); }

try {
    $pdo = db();
    $pdo->exec($sql);
    echo "Esquema aplicado correctamente.\n";
    foreach ($pdo->query("SHOW TABLES") as $row) echo " - " . array_values($row)[0] . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
