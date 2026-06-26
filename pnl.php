<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';
$user = require_login();

// 13 columnas: 12 meses previos + el mes actual
$cols = [];
$d = new DateTime('first day of this month');
$d->modify('-12 month');
for ($i=0; $i<13; $i++) { $cols[] = $d->format('Y-m'); $d->modify('+1 month'); }
$start = $cols[0] . '-01';

$q = db()->prepare("SELECT categoria, grupo_pnl, DATE_FORMAT(fecha,'%Y-%m') ym, SUM(monto_canonico) t
  FROM transacciones WHERE grupo_pnl IN ('operativo','distribucion','impuestos') AND fecha >= :s
  GROUP BY categoria, grupo_pnl, ym");
$q->execute([':s'=>$start]);
$piv = []; // [grupo][categoria][ym] = val
$grTot = []; // [grupo][ym]
$colTot = []; // [ym]
foreach ($q as $r) {
    $g=$r['grupo_pnl']; $c=$r['categoria']; $ym=$r['ym']; $v=(float)$r['t'];
    $piv[$g][$c][$ym] = ($piv[$g][$c][$ym] ?? 0) + $v;
    $grTot[$g][$ym] = ($grTot[$g][$ym] ?? 0) + $v;
    $colTot[$ym] = ($colTot[$ym] ?? 0) + $v;
}
$GRP = ['operativo'=>'Gasto operativo','distribucion'=>'Distribuciones a socios','impuestos'=>'Impuestos'];
$MES=['','ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
function lbl($ym){global $MES; [$y,$m]=explode('-',$ym); return $MES[(int)$m].' '.substr($y,2);}
function f($n){ $n=round((float)$n); return ($n<0?'-':'').'$'.number_format(abs($n),0,',','.'); }
$cur = (new DateTime('first day of this month'))->format('Y-m');
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><title>P&L — KRATFEL Finanzas</title>
<style>
:root{--bg:#0f1320;--panel:#171c2e;--panel2:#1e2540;--line:#2a3252;--txt:#e8ecf7;--mut:#9aa6c7;--acc:#5b8cff}
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--txt)}
header{display:flex;align-items:center;gap:16px;padding:14px 22px;border-bottom:1px solid var(--line)}
.brand{font-weight:800;font-size:18px;display:inline-flex;align-items:center;gap:7px}.brand span{color:var(--mut);font-weight:400;font-size:14px}
nav{display:flex;gap:6px;margin-left:8px}nav a{color:var(--mut);padding:8px 14px;border-radius:10px;text-decoration:none;font-size:14px;font-weight:600}
nav a.active{background:var(--panel2);color:var(--txt)}nav a:hover{color:var(--txt)}
.who{margin-left:auto;color:var(--mut);font-size:13px}.who a{color:var(--mut)}
.wrap{max-width:1300px;margin:0 auto;padding:22px}
h2{font-size:18px;margin:0 0 14px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:8px;overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:12.5px;min-width:900px}
th,td{padding:7px 10px;text-align:right;border-bottom:1px solid var(--line);font-variant-numeric:tabular-nums;white-space:nowrap}
th:first-child,td:first-child{text-align:left;position:sticky;left:0;background:var(--panel);min-width:200px}
thead th{color:var(--mut);font-size:11px;text-transform:uppercase}
thead th.cur{color:var(--acc)}
.sect td{background:var(--panel2);color:var(--mut);font-size:11px;text-transform:uppercase;font-weight:700}
.tot td{font-weight:700;border-top:1px solid var(--line)}
.grand td{font-weight:800;border-top:2px solid var(--line)}
td.v0{color:#444c6b}
</style></head>
<body>
<header>
 <div class="brand"><img src="/assets/logo_kratfel.png" alt="Kratfel" style="height:24px"> <span>· Finanzas</span></div>
 <nav><a href="/">Dashboard</a><a class="active" href="/pnl.php">P&amp;L</a><a href="/forecast.php">Forecast</a></nav>
 <div class="who"><?= htmlspecialchars($user['nombre'] ?? $user['email']) ?> · <a href="/auth/logout.php">Salir</a></div>
</header>
<div class="wrap">
 <h2>P&amp;L mensual · últimos 12 meses + actual</h2>
 <div class="card"><table>
  <thead><tr><th>Categoría</th>
  <?php foreach($cols as $ym): ?><th class="<?= $ym===$cur?'cur':'' ?>"><?= lbl($ym) ?><?= $ym===$cur?' · hoy':'' ?></th><?php endforeach; ?>
  </tr></thead>
  <tbody>
  <?php foreach($GRP as $g=>$label): if(empty($piv[$g])) continue; ?>
    <tr class="sect"><td><?= $label ?></td><?php foreach($cols as $ym) echo '<td></td>'; ?></tr>
    <?php
      // ordenar categorías por total desc
      $catsum=[]; foreach($piv[$g] as $c=>$bym){ $catsum[$c]=array_sum($bym); }
      arsort($catsum);
      foreach(array_keys($catsum) as $c): ?>
      <tr><td><?= htmlspecialchars($c) ?></td>
        <?php foreach($cols as $ym){ $v=$piv[$g][$c][$ym] ?? 0; echo '<td class="'.($v==0?'v0':'').'">'.($v==0?'·':f($v)).'</td>'; } ?>
      </tr>
    <?php endforeach; ?>
    <tr class="tot"><td>Total <?= $label ?></td>
      <?php foreach($cols as $ym) echo '<td>'.f($grTot[$g][$ym] ?? 0).'</td>'; ?>
    </tr>
  <?php endforeach; ?>
    <tr class="grand"><td>TOTAL GASTOS</td>
      <?php foreach($cols as $ym) echo '<td>'.f($colTot[$ym] ?? 0).'</td>'; ?>
    </tr>
  </tbody>
 </table></div>
 <p style="color:var(--mut);font-size:12px;margin-top:10px">Excluye traspasos internos y retiros de reserva. Gasto en positivo. El mes actual está en curso.</p>
</div>
</body></html>
