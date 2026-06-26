<?php
declare(strict_types=1);

/**
 * Lector .xlsx SIN dependencias (ZipArchive + SimpleXML) + importador del
 * reporte "Yearly Transaction Detail" de QBO. Usado para backfill/fallback.
 *
 * Uso CLI:  php lib/import_xlsx.php /ruta/al/reporte.xlsx
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/normalize.php';

/** Devuelve un array de filas; cada fila es un array indexado de strings por columna. */
function xlsx_read(string $path): array {
    if (!is_readable($path)) throw new RuntimeException("No se puede leer: $path");
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('No es un .xlsx válido.');

    // Shared strings
    $shared = [];
    if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $xml = simplexml_load_string($ss);
        foreach ($xml->si as $si) {
            // texto directo o concatenación de runs <r><t>
            $t = '';
            if (isset($si->t)) $t = (string)$si->t;
            else foreach ($si->r as $r) $t .= (string)$r->t;
            $shared[] = $t;
        }
    }

    // Primera hoja
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheet === false) throw new RuntimeException('No se encontró sheet1.');
    $xml = simplexml_load_string($sheet);

    $colToIdx = function (string $ref): int {
        // 'B12' -> 1 (0-based)
        preg_match('/^[A-Z]+/', $ref, $m);
        $letters = $m[0]; $n = 0;
        for ($i = 0; $i < strlen($letters); $i++) $n = $n * 26 + (ord($letters[$i]) - 64);
        return $n - 1;
    };

    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            $idx = $colToIdx($ref);
            $val = (string)$c->v;
            if ((string)$c['t'] === 's') $val = $shared[(int)$val] ?? '';
            $cells[$idx] = $val;
        }
        if ($cells) {
            $max = max(array_keys($cells));
            $line = [];
            for ($i = 0; $i <= $max; $i++) $line[$i] = $cells[$i] ?? '';
            $rows[] = $line;
        } else {
            $rows[] = [];
        }
    }
    return $rows;
}

/** Importa el reporte. Devuelve [nuevas, dup]. */
function xlsx_import_qbo(string $path): array {
    $rows = xlsx_read($path);
    // Localiza la fila de encabezado (la que contiene "Transaction date")
    $hdr = null; $hi = -1;
    foreach ($rows as $i => $r) {
        if (in_array('Transaction date', array_map('trim', $r), true)) { $hdr = array_map('trim', $r); $hi = $i; break; }
    }
    if ($hdr === null) throw new RuntimeException('No se encontró el encabezado "Transaction date".');

    $col = fn(string $name) => array_search($name, $hdr, true);
    $cDate = $col('Transaction date');
    $cDesc = $col('Description');
    $cAmt  = $col('Amount');
    $cAcct = $col('Distribution account');
    $cSplit = $col('Split');
    if ($cDate === false || $cAmt === false || $cAcct === false || $cSplit === false) {
        throw new RuntimeException('Faltan columnas esperadas en el encabezado.');
    }

    $pdo = db();
    $pdo->prepare("INSERT INTO import_batches (origen, archivo, nota) VALUES ('qbo_xlsx', :a, 'backfill')")
        ->execute([':a' => basename($path)]);
    $batchId = (int)$pdo->lastInsertId();

    $nuevas = 0; $dup = 0;
    foreach (array_slice($rows, $hi + 1) as $r) {
        $fechaRaw = trim($r[$cDate] ?? '');
        if ($fechaRaw === '' || strtoupper($fechaRaw) === 'TOTAL') continue;
        $amt = trim((string)($r[$cAmt] ?? ''));
        if ($amt === '') continue;
        $cuenta = trim($r[$cAcct] ?? '');
        $split = trim($r[$cSplit] ?? '');
        if ($cuenta === '' || $split === '') continue;
        $fecha = date('Y-m-d', strtotime(str_replace('/', '-', $fechaRaw)));
        $n = nz_row([
            'fecha' => $fecha,
            'descripcion' => trim($r[$cDesc] ?? ''),
            'amount' => (float)str_replace([',', '$'], '', $amt),
            'cuenta' => $cuenta,
            'split' => $split,
        ]);
        $res = nz_upsert($pdo, $n, 'qbo_xlsx', null, $batchId);
        $res === 'nueva' ? $nuevas++ : $dup++;
    }
    $pdo->prepare("UPDATE import_batches SET filas_nuevas=:a, filas_dup=:b WHERE id=:id")
        ->execute([':a' => $nuevas, ':b' => $dup, ':id' => $batchId]);
    return [$nuevas, $dup];
}

// Ejecución por CLI
if (PHP_SAPI === 'cli' && isset($argv[1])) {
    [$n, $d] = xlsx_import_qbo($argv[1]);
    fwrite(STDOUT, "Importadas: nuevas=$n duplicadas=$d\n");
}
