<?php
declare(strict_types=1);

/**
 * Reglas de normalización v3 — COMPARTIDAS por la API de QBO y el importador .xlsx.
 * Una sola fuente de verdad para signo, tipo de cuenta y grupo de P&L.
 */

// Splits cuyo top-level es un nombre de cuenta = traspaso interno (excluir de P&L y burn).
const TRANSFER_SPLITS = [
    'NSCC 7403 - Corp',
    'NSCC 1433 - Corp',
    'NSBofACH 6878',
    'NSVantageCH 1779',
];

function nz_tipo_cuenta(string $cuenta): string {
    return str_starts_with($cuenta, 'NSCC') ? 'credit_card' : 'checking';
}

function nz_toplevel(string $split): string {
    $p = explode(':', $split, 2);
    return trim($p[0]);
}

function nz_subcategoria(string $split): ?string {
    $p = explode(':', $split, 2);
    return isset($p[1]) ? trim($p[1]) : null;
}

function nz_grupo(string $split): string {
    $tl = nz_toplevel($split);
    if ($tl === 'Cetera') return 'reserve_draw';
    if (in_array($tl, TRANSFER_SPLITS, true)) return 'transfer';
    if ($tl === 'Partner distributions') return 'distribucion';
    if ($tl === 'Taxes paid') return 'impuestos';
    return 'operativo';
}

/**
 * Monto canónico = cash_out (gasto positivo).
 *   - tarjeta: el gasto viene positivo  -> cash_out = +amount
 *   - cheques: la salida viene negativa -> cash_out = -amount
 * Para reserve_draw (entrada a checking desde Cetera) el "consumo" = -monto_canonico
 * (se calcula así en lib/runway.php).
 */
function nz_monto_canonico(float $amount, string $cuenta): float {
    return nz_tipo_cuenta($cuenta) === 'credit_card' ? $amount : -$amount;
}

function nz_dedupe_hash(string $fecha, float $monto, string $cuenta, string $desc): string {
    return hash('sha256', $fecha . '|' . number_format($monto, 2, '.', '') . '|' . $cuenta . '|' . $desc);
}

/**
 * Normaliza una fila cruda a la forma de la tabla `transacciones`.
 * $row = ['fecha'=>'YYYY-MM-DD','descripcion'=>..,'amount'=>float,'cuenta'=>..,'split'=>..]
 */
function nz_row(array $row): array {
    $cuenta = (string)$row['cuenta'];
    $split  = (string)$row['split'];
    $amount = (float)$row['amount'];
    $monto  = nz_monto_canonico($amount, $cuenta);
    $desc   = trim((string)($row['descripcion'] ?? ''));
    return [
        'fecha'          => $row['fecha'],
        'descripcion'    => $desc,
        'monto_canonico' => $monto,
        'cuenta'         => $cuenta,
        'tipo_cuenta'    => nz_tipo_cuenta($cuenta),
        'categoria'      => nz_toplevel($split),
        'subcategoria'   => nz_subcategoria($split),
        'grupo_pnl'      => nz_grupo($split),
        'dedupe_hash'    => nz_dedupe_hash($row['fecha'], $monto, $cuenta, $desc),
    ];
}

/** Inserta una fila normalizada de forma idempotente. Devuelve 'nueva' o 'dup'. */
function nz_upsert(PDO $pdo, array $n, string $origen, ?string $qboId, ?int $batchId): string {
    $sql = "INSERT INTO transacciones
        (qbo_id, fecha, descripcion, monto_canonico, cuenta, tipo_cuenta, categoria, subcategoria, grupo_pnl, origen, import_batch_id, dedupe_hash)
        VALUES (:qbo_id,:fecha,:descripcion,:monto,:cuenta,:tipo,:categoria,:subcategoria,:grupo,:origen,:batch,:hash)
        ON DUPLICATE KEY UPDATE id = id";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':qbo_id' => $qboId,
        ':fecha' => $n['fecha'],
        ':descripcion' => $n['descripcion'],
        ':monto' => $n['monto_canonico'],
        ':cuenta' => $n['cuenta'],
        ':tipo' => $n['tipo_cuenta'],
        ':categoria' => $n['categoria'],
        ':subcategoria' => $n['subcategoria'],
        ':grupo' => $n['grupo_pnl'],
        ':origen' => $origen,
        ':batch' => $batchId,
        ':hash' => $n['dedupe_hash'],
    ]);
    return $st->rowCount() > 0 ? 'nueva' : 'dup';
}
