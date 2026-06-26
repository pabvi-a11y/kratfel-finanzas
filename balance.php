<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/runway.php';
$user = require_login();

// Cetera real (live) desde saldos
$sr = rw_saldo_actual();
$cetera = $sr ? (float)$sr['saldo'] : 0.0;
$ceteraFecha = $sr ? date('d/m/Y', strtotime($sr['fecha'])) : '—';
$qboCetera = 547554.65; // valor desactualizado que traía QBO (referencia)

$rows = db()->query("SELECT seccion,grupo,cuenta,monto FROM bs_lineas ORDER BY seccion, orden")->fetchAll();
$act=[]; $pas=[]; $eq=[];
foreach($rows as $r){
  if($r['seccion']==='activo') $act[$r['grupo']][]=$r;
  elseif($r['seccion']==='pasivo') $pas[$r['grupo']][]=$r;
  else $eq[]=$r;
}
// Cetera entra arriba en Bank Accounts
$activoTotal = $cetera; foreach($rows as $r){ if($r['seccion']==='activo') $activoTotal+=(float)$r['monto']; }
$pasivoTotal = 0; foreach($rows as $r){ if($r['seccion']==='pasivo') $pasivoTotal+=(float)$r['monto']; }
$patrimonio = $activoTotal - $pasivoTotal;
$eqQBO=0; foreach($eq as $r) $eqQBO+=(float)$r['monto'];
function m($n){ $n=(float)$n; return ($n<0?'-':'').'$'.number_format(abs($n),2,',','.'); }
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><title>Balance — KRATFEL Finanzas</title>
<style>
:root{--bg:#0f1320;--panel:#171c2e;--panel2:#1e2540;--line:#2a3252;--txt:#e8ecf7;--mut:#9aa6c7;--acc:#5b8cff;--good:#37d39b;--bad:#ff6b6b;--warn:#ffb454}
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--txt)}
header{display:flex;align-items:center;gap:16px;padding:14px 22px;border-bottom:1px solid var(--line)}
.brand{font-weight:800;font-size:18px;display:inline-flex;align-items:center;gap:7px}.brand span{color:var(--mut);font-weight:400;font-size:14px}
nav{display:flex;gap:6px;margin-left:8px}nav a{color:var(--mut);padding:8px 14px;border-radius:10px;text-decoration:none;font-size:14px;font-weight:600}
nav a.active{background:var(--panel2);color:var(--txt)}nav a:hover{color:var(--txt)}
.who{margin-left:auto;color:var(--mut);font-size:13px}.who a{color:var(--mut)}
.wrap{max-width:760px;margin:0 auto;padding:22px}
h2{font-size:18px;margin:0 0 2px}.cap{color:var(--mut);font-size:12.5px;margin:0 0 16px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:16px 20px;margin-bottom:16px}
table{width:100%;border-collapse:collapse;font-size:14px}
td{padding:7px 4px;border-bottom:1px solid var(--line);font-variant-numeric:tabular-nums}
td.num{text-align:right;white-space:nowrap}
.sec td{color:var(--mut);font-size:11px;text-transform:uppercase;letter-spacing:.4px;font-weight:700;padding-top:14px;border-bottom:none}
.grp td{color:var(--mut);font-size:12.5px;padding-left:4px;border-bottom:none;padding-top:8px}
.it td:first-child{padding-left:18px;color:var(--txt)}
.tot td{font-weight:800;border-top:2px solid var(--line);border-bottom:none}
.note{color:var(--warn);font-size:11px}
.big{display:flex;justify-content:space-between;align-items:baseline;background:var(--panel2);border:1px solid var(--line);border-radius:12px;padding:14px 18px;margin-top:6px}
.big b{font-size:22px;font-weight:800;color:var(--good)}
.neg{color:var(--bad)}
small{color:var(--mut)}
</style></head>
<body>
<header><div class="brand"><img src="/assets/logo_kratfel.png" alt="Kratfel" style="height:24px"> <span>· Finanzas</span></div>
<nav><a href="/">Dashboard</a><a href="/pnl.php">P&amp;L</a><a href="/forecast.php">Forecast</a><a href="/flujo.php">Flujo</a><a class="active" href="/balance.php">Balance</a></nav>
<div class="who"><?= htmlspecialchars($user['nombre']??$user['email']) ?> · <a href="/auth/logout.php">Salir</a></div></header>
<div class="wrap">
 <h2>Balance general</h2>
 <p class="cap">Al 26/06/2026 · Cetera a su valor real ($<?= number_format($cetera,0,',','.') ?>, al <?= $ceteraFecha ?>); QBO lo tenía en $<?= number_format($qboCetera,0,',','.') ?> (desactualizado).</p>

 <div class="card">
  <table>
   <tr class="sec"><td colspan="2">Activos</td></tr>
   <tr class="grp"><td colspan="2">Bancos y reserva</td></tr>
   <tr class="it"><td>Cetera (reserva, valor real) <span class="note">· QBO: $<?= number_format($qboCetera,0,',','.') ?></span></td><td class="num"><?= m($cetera) ?></td></tr>
   <?php foreach(($act['Bank Accounts']??[]) as $r): ?><tr class="it"><td><?= htmlspecialchars($r['cuenta']) ?></td><td class="num"><?= m($r['monto']) ?></td></tr><?php endforeach; ?>
   <?php foreach(['Other Current Assets'=>'Otros activos corrientes','Other Assets'=>'Otros activos'] as $gk=>$gl): if(empty($act[$gk]))continue; ?>
     <tr class="grp"><td colspan="2"><?= $gl ?></td></tr>
     <?php foreach($act[$gk] as $r): ?><tr class="it"><td><?= htmlspecialchars($r['cuenta']) ?></td><td class="num"><?= m($r['monto']) ?></td></tr><?php endforeach; ?>
   <?php endforeach; ?>
   <tr class="tot"><td>Total activos</td><td class="num"><?= m($activoTotal) ?></td></tr>
  </table>
 </div>

 <div class="card">
  <table>
   <tr class="sec"><td colspan="2">Pasivos</td></tr>
   <tr class="grp"><td colspan="2">Tarjetas de crédito</td></tr>
   <?php foreach(($pas['Credit Cards']??[]) as $r): ?><tr class="it"><td><?= htmlspecialchars($r['cuenta']) ?></td><td class="num <?= $r['monto']<0?'neg':'' ?>"><?= m($r['monto']) ?></td></tr><?php endforeach; ?>
   <tr class="tot"><td>Total pasivos</td><td class="num"><?= m($pasivoTotal) ?></td></tr>
  </table>
  <div class="big"><span>Patrimonio neto <small>(activos − pasivos)</small></span><b><?= m($patrimonio) ?></b></div>
 </div>

 <div class="card">
  <table>
   <tr class="sec"><td colspan="2">Capital contable · según libros de QBO <span class="note">(usa el Cetera desactualizado, no refleja el patrimonio real de arriba)</span></td></tr>
   <?php foreach($eq as $r): ?><tr class="it"><td><?= htmlspecialchars($r['cuenta']) ?></td><td class="num <?= $r['monto']<0?'neg':'' ?>"><?= m($r['monto']) ?></td></tr><?php endforeach; ?>
   <tr class="tot"><td>Total capital (QBO)</td><td class="num"><?= m($eqQBO) ?></td></tr>
  </table>
 </div>
 <p class="cap">Saldos de banco/tarjetas tomados del Balance Sheet de QBO (subido manualmente). Cuando conectemos la API de QBO se actualizarán solos; Cetera siempre con el valor real de la cuenta.</p>
</div>
</body></html>
