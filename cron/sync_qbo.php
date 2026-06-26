<?php
declare(strict_types=1);

/**
 * Cron de sincronización con QuickBooks Online. Ejecutar cada 2-3 días:
 *   php /ruta/app/cron/sync_qbo.php
 *
 * Refresca el token (rotándolo), trae transacciones desde la última fecha en BD,
 * las normaliza con las MISMAS reglas que el importador .xlsx, e inserta idempotente.
 *
 * NOTA (probe-on-first-run): el reporte TransactionList devuelve Columns + Rows cuyo
 * orden/títulos conviene confirmar contra la respuesta real la primera vez. Este script
 * mapea por TÍTULO de columna y registra en log los títulos vistos para validarlos.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/qbo.php';
require_once __DIR__ . '/../lib/normalize.php';

function logln(string $m): void { fwrite(STDOUT, '[' . date('c') . "] $m\n"); }

try {
    $pdo = db();

    // Si QBO aún no está conectado, salir limpio (sin error) — datos llegan por xlsx mientras tanto.
    $c = $pdo->query("SELECT estado FROM qbo_oauth ORDER BY id DESC LIMIT 1")->fetch();
    if (!$c || $c['estado'] !== 'conectado') { logln('QBO no conectado aún; nada que sincronizar.'); exit(0); }

    // Fecha de inicio: desde la última transacción (con solape de 5 días por seguridad).
    $last = $pdo->query("SELECT MAX(fecha) m FROM transacciones")->fetch()['m'] ?? null;
    $start = $last ? (new DateTime($last))->modify('-5 days')->format('Y-m-d') : '2025-01-01';
    $end = date('Y-m-d');

    logln("Sync QBO $start..$end");
    $path = "reports/TransactionList?start_date=$start&end_date=$end"
          . "&columns=tx_date,name,memo,account_name,split_acc,subt_nat_amount";
    $rep = qbo_api_get($path);

    // Mapeo de columnas por título (robusto a reordenaciones)
    $cols = $rep['Columns']['Column'] ?? [];
    $idx = [];
    foreach ($cols as $i => $c) $idx[strtolower($c['ColTitle'] ?? $c['ColType'] ?? "col$i")] = $i;
    logln('Columnas QBO: ' . implode(' | ', array_map(fn($c) => $c['ColTitle'] ?? $c['ColType'] ?? '?', $cols)));

    $find = function (array $keys) use ($idx) {
        foreach ($keys as $k) foreach ($idx as $title => $i) if (str_contains($title, $k)) return $i;
        return null;
    };
    $iDate  = $find(['date']);
    $iName  = $find(['name']);
    $iMemo  = $find(['memo', 'description']);
    $iAcct  = $find(['account']);   // cuenta banco/tarjeta = Distribution account
    $iSplit = $find(['split']);     // categoría
    $iAmt   = $find(['amount']);

    if ($iDate === null || $iAcct === null || $iSplit === null || $iAmt === null) {
        throw new RuntimeException('No se pudieron mapear las columnas del reporte. Revisa los títulos en el log y ajusta el parser.');
    }

    // Aplanar filas (el reporte puede anidar secciones)
    $flat = [];
    $walk = function ($rows) use (&$walk, &$flat) {
        foreach ($rows as $r) {
            if (isset($r['ColData'])) $flat[] = $r['ColData'];
            if (isset($r['Rows']['Row'])) $walk($r['Rows']['Row']);
        }
    };
    $walk($rep['Rows']['Row'] ?? []);

    $batchId = null;
    $pdo->prepare("INSERT INTO import_batches (origen, nota) VALUES ('qbo_api', :n)")
        ->execute([':n' => "sync $start..$end"]);
    $batchId = (int)$pdo->lastInsertId();

    $nuevas = 0; $dup = 0;
    foreach ($flat as $cd) {
        $cell = fn($i) => $i === null ? '' : trim((string)($cd[$i]['value'] ?? ''));
        $fechaRaw = $cell($iDate);
        if ($fechaRaw === '') continue;
        $amount = (float)str_replace([',', '$'], '', $cell($iAmt));
        $desc = trim($cell($iName) . ' ' . $cell($iMemo));
        $cuenta = $cell($iAcct);
        $split = $cell($iSplit);
        if ($cuenta === '' || $split === '') continue;
        // Normaliza fecha a Y-m-d (el reporte suele venir ya en Y-m-d)
        $fecha = date('Y-m-d', strtotime($fechaRaw));
        $n = nz_row(['fecha' => $fecha, 'descripcion' => $desc, 'amount' => $amount, 'cuenta' => $cuenta, 'split' => $split]);
        $res = nz_upsert($pdo, $n, 'qbo_api', null, $batchId);
        $res === 'nueva' ? $nuevas++ : $dup++;
    }

    $pdo->prepare("UPDATE import_batches SET filas_nuevas=:a, filas_dup=:b WHERE id=:id")
        ->execute([':a' => $nuevas, ':b' => $dup, ':id' => $batchId]);
    $pdo->prepare("UPDATE qbo_oauth SET ultima_sync=NOW() WHERE estado='conectado'")->execute();

    logln("OK. Nuevas=$nuevas Duplicadas=$dup");
} catch (Throwable $e) {
    logln('ERROR: ' . $e->getMessage());
    exit(1);
}
