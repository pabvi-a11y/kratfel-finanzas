<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Motor de runway por VELOCIDAD DE CONSUMO de la reserva (retiros de Cetera).
 * consumo_mensual = -SUM(monto_canonico) WHERE grupo_pnl='reserve_draw'
 * (monto_canonico de una entrada a checking es negativo; lo negamos para obtener el retiro positivo).
 */

// Meses outlier a excluir de la velocidad (capitalización inicial, etc.)
const RUNWAY_OUTLIERS = ['2025-04'];

function rw_consumo_mensual(): array {
    $sql = "SELECT DATE_FORMAT(fecha,'%Y-%m') ym, -SUM(monto_canonico) consumo
            FROM transacciones WHERE grupo_pnl='reserve_draw'
            GROUP BY ym ORDER BY ym";
    $out = [];
    foreach (db()->query($sql) as $r) $out[$r['ym']] = (float)$r['consumo'];
    return $out;
}

/** Velocidad = media de los últimos $n meses COMPLETOS, excluyendo outliers y el mes en curso. */
function rw_velocidad(int $n = 6): array {
    $ser = rw_consumo_mensual();
    $mesActual = date('Y-m');
    $meses = array_filter(array_keys($ser), fn($m) => $m !== $mesActual && !in_array($m, RUNWAY_OUTLIERS, true));
    sort($meses);
    $use = array_slice($meses, -$n);
    if (!$use) return ['velocidad' => 0.0, 'meses' => []];
    $vals = array_map(fn($m) => $ser[$m], $use);
    return ['velocidad' => array_sum($vals) / count($vals), 'meses' => $use];
}

function rw_saldo_actual(): ?array {
    $r = db()->query("SELECT fecha, saldo FROM saldos ORDER BY fecha DESC, id DESC LIMIT 1")->fetch();
    return $r ?: null;
}

/**
 * Proyección. $overrides: ['consumo_mult'=>0.8, 'ingresos'=>[['mes'=>3,'monto'=>120000]]]
 * Devuelve meses_restantes, fecha_cero y la serie de saldos.
 */
function rw_proyeccion(float $saldo, float $velocidad, array $overrides = [], int $horizonte = 12): array {
    $v = $velocidad * (float)($overrides['consumo_mult'] ?? 1.0);
    $ingresos = [];
    foreach ($overrides['ingresos'] ?? [] as $i) $ingresos[(int)$i['mes']] = ($ingresos[(int)$i['mes']] ?? 0) + (float)$i['monto'];

    $serie = [['mes' => 0, 'etiqueta' => 'hoy', 'saldo' => round($saldo, 2)]];
    $bal = $saldo; $cero = null;
    for ($i = 1; $i <= $horizonte; $i++) {
        if (isset($ingresos[$i])) $bal += $ingresos[$i];
        $bal -= $v;
        $et = (new DateTime('first day of this month'))->modify("+$i month")->format('y/m');
        $serie[] = ['mes' => $i, 'etiqueta' => $et, 'saldo' => round($bal, 2)];
        if ($bal <= 0 && $cero === null) $cero = $et;
    }
    // meses_restantes con fracción
    $b = $saldo; $ml = 0.0;
    for ($i = 1; $i <= 600; $i++) {
        if (isset($ingresos[$i])) $b += $ingresos[$i];
        if ($v <= 0) { $ml = INF; break; }
        if ($b - $v <= 0) { $ml = ($i - 1) + ($b / $v); break; }
        $b -= $v; $ml = $i;
    }
    return ['velocidad' => $v, 'meses_restantes' => $ml, 'fecha_cero' => $cero, 'serie' => $serie];
}

/** P&L por grupo (gasto positivo) en un rango. */
function rw_pnl(string $desde, string $hasta): array {
    $sql = "SELECT grupo_pnl, categoria, SUM(monto_canonico) total
            FROM transacciones
            WHERE grupo_pnl IN ('operativo','distribucion','impuestos') AND fecha>=:d AND fecha<:h
            GROUP BY grupo_pnl, categoria ORDER BY total DESC";
    $st = db()->prepare($sql); $st->execute([':d' => $desde, ':h' => $hasta]);
    return $st->fetchAll();
}
