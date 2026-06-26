<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/runway.php';
$user = require_login();

/* ---------- P&L (13 cols: 12 meses + actual) ---------- */
$cols = []; $d = new DateTime('first day of this month'); $d->modify('-12 month');
for ($i=0;$i<13;$i++){ $cols[]=$d->format('Y-m'); $d->modify('+1 month'); }
$startP = $cols[0].'-01';
$q = db()->prepare("SELECT categoria, grupo_pnl, DATE_FORMAT(fecha,'%Y-%m') ym, SUM(monto_canonico) t
  FROM transacciones WHERE grupo_pnl IN ('operativo','distribucion','impuestos') AND fecha >= :s
  GROUP BY categoria, grupo_pnl, ym");
$q->execute([':s'=>$startP]);
$piv=[]; $grTot=[]; $colTot=[];
foreach($q as $r){ $g=$r['grupo_pnl'];$c=$r['categoria'];$ym=$r['ym'];$v=(float)$r['t'];
  $piv[$g][$c][$ym]=($piv[$g][$c][$ym]??0)+$v; $grTot[$g][$ym]=($grTot[$g][$ym]??0)+$v; $colTot[$ym]=($colTot[$ym]??0)+$v; }
$GRP=['operativo'=>'Gasto operativo','distribucion'=>'Distribuciones a socios','impuestos'=>'Impuestos'];
$MES=['','ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
function lbl($ym){global $MES;[$y,$m]=explode('-',$ym);return $MES[(int)$m].' '.substr($y,2);}
function f($n){$n=round((float)$n);return ($n<0?'-':'').'$'.number_format(abs($n),0,',','.');}
$curM=(new DateTime('first day of this month'))->format('Y-m');

/* ---------- Balance ---------- */
$sr=rw_saldo_actual(); $cetera=$sr?(float)$sr['saldo']:0.0; $ceteraFecha=$sr?date('d/m/Y',strtotime($sr['fecha'])):'—';
$qboCetera=547554.65;
$bs=db()->query("SELECT seccion,grupo,cuenta,monto FROM bs_lineas ORDER BY seccion,orden")->fetchAll();
$act=[];$pas=[];$eq=[];
foreach($bs as $r){ if($r['seccion']==='activo')$act[$r['grupo']][]=$r; elseif($r['seccion']==='pasivo')$pas[$r['grupo']][]=$r; else $eq[]=$r; }
$activoTotal=$cetera; $pasivoTotal=0; $eqQBO=0;
foreach($bs as $r){ if($r['seccion']==='activo')$activoTotal+=(float)$r['monto']; if($r['seccion']==='pasivo')$pasivoTotal+=(float)$r['monto']; if($r['seccion']==='equity')$eqQBO+=(float)$r['monto']; }
$patrimonio=$activoTotal-$pasivoTotal;
function m2($n){$n=(float)$n;return ($n<0?'-':'').'$'.number_format(abs($n),2,',','.');}
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><title>Reportes — KRATFEL Finanzas</title>
<style>
:root{--bg:#0f1320;--panel:#171c2e;--panel2:#1e2540;--line:#2a3252;--txt:#e8ecf7;--mut:#9aa6c7;--acc:#5b8cff;--good:#37d39b;--bad:#ff6b6b;--warn:#ffb454}
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--txt)}
header{display:flex;align-items:center;gap:16px;padding:14px 22px;border-bottom:1px solid var(--line)}
.brand{font-weight:800;font-size:18px;display:inline-flex;align-items:center;gap:7px}.brand span{color:var(--mut);font-weight:400;font-size:14px}
nav{display:flex;gap:6px;margin-left:8px}nav a{color:var(--mut);padding:8px 14px;border-radius:10px;text-decoration:none;font-size:14px;font-weight:600}
nav a.active{background:var(--panel2);color:var(--txt)}nav a:hover{color:var(--txt)}
.who{margin-left:auto;color:var(--mut);font-size:13px}.who a{color:var(--mut)}
.wrap{max-width:1300px;margin:0 auto;padding:22px}
h2{font-size:18px;margin:24px 0 12px}h2:first-child{margin-top:6px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:8px;overflow-x:auto}
/* P&L table */
.pnl{width:100%;border-collapse:collapse;font-size:12.5px;min-width:900px}
.pnl th,.pnl td{padding:7px 10px;text-align:right;border-bottom:1px solid var(--line);font-variant-numeric:tabular-nums;white-space:nowrap}
.pnl th:first-child,.pnl td:first-child{text-align:left;position:sticky;left:0;background:var(--panel);min-width:200px}
.pnl thead th{color:var(--mut);font-size:11px;text-transform:uppercase}.pnl thead th.cur{color:var(--acc)}
.pnl .sect td{background:var(--panel2);color:var(--mut);font-size:11px;text-transform:uppercase;font-weight:700}
.pnl .tot td{font-weight:700;border-top:1px solid var(--line)}.pnl .grand td{font-weight:800;border-top:2px solid var(--line)}
.pnl td.v0{color:#444c6b}
/* Balance */
.bsgrid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.bsbox{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:16px 20px}
.bsbox table{width:100%;border-collapse:collapse;font-size:13.5px}
.bsbox td{padding:6px 4px;border-bottom:1px solid var(--line);font-variant-numeric:tabular-nums}
.bsbox td.num{text-align:right;white-space:nowrap}
.bsbox .sec td{color:var(--mut);font-size:11px;text-transform:uppercase;letter-spacing:.4px;font-weight:700;border-bottom:none;padding-top:4px}
.bsbox .grp td{color:var(--mut);font-size:12px;border-bottom:none;padding-top:8px}
.bsbox .it td:first-child{padding-left:16px}
.bsbox .tot td{font-weight:800;border-top:2px solid var(--line);border-bottom:none}
.bsbox .note{color:var(--warn);font-size:10.5px}.bsbox .neg{color:var(--bad)}
.big{display:flex;justify-content:space-between;align-items:baseline;background:var(--panel2);border:1px solid var(--line);border-radius:12px;padding:14px 18px;margin-top:10px}
.big b{font-size:20px;font-weight:800;color:var(--good)}
.cap{color:var(--mut);font-size:12px;margin:4px 0 0}
@media(max-width:820px){.bsgrid{grid-template-columns:1fr}}
</style></head>
<body>
<header>
 <div class="brand"><img src="/assets/logo_kratfel.png" alt="Kratfel" style="height:24px"> <span>· Finanzas</span></div>
 <nav><a href="/">Dashboard</a><a class="active" href="/pnl.php">Reportes</a><a href="/forecast.php">Forecast</a><a href="/flujo.php">Flujo</a></nav>
 <div class="who"><?= htmlspecialchars($user['nombre']??$user['email']) ?> · <a href="/auth/logout.php">Salir</a></div>
</header>
<div class="wrap">

 <h2>P&amp;L mensual · últimos 12 meses + actual</h2>
 <div class="card"><table class="pnl">
  <thead><tr><th>Categoría</th><?php foreach($cols as $ym): ?><th class="<?= $ym===$curM?'cur':'' ?>"><?= lbl($ym) ?><?= $ym===$curM?' · hoy':'' ?></th><?php endforeach; ?></tr></thead>
  <tbody>
  <?php foreach($GRP as $g=>$label): if(empty($piv[$g]))continue; ?>
    <tr class="sect"><td><?= $label ?></td><?php foreach($cols as $ym)echo '<td></td>'; ?></tr>
    <?php $cs=[]; foreach($piv[$g] as $c=>$bym)$cs[$c]=array_sum($bym); arsort($cs);
      foreach(array_keys($cs) as $c): ?>
      <tr><td><?= htmlspecialchars($c) ?></td><?php foreach($cols as $ym){$v=$piv[$g][$c][$ym]??0;echo '<td class="'.($v==0?'v0':'').'">'.($v==0?'·':f($v)).'</td>';} ?></tr>
    <?php endforeach; ?>
    <tr class="tot"><td>Total <?= $label ?></td><?php foreach($cols as $ym)echo '<td>'.f($grTot[$g][$ym]??0).'</td>'; ?></tr>
  <?php endforeach; ?>
    <tr class="grand"><td>TOTAL GASTOS</td><?php foreach($cols as $ym)echo '<td>'.f($colTot[$ym]??0).'</td>'; ?></tr>
  </tbody>
 </table></div>
 <p class="cap">Excluye traspasos internos y retiros de reserva. Gasto en positivo. El mes actual está en curso.</p>

 <h2>Balance general</h2>
 <p class="cap" style="margin-bottom:12px">Al 26/06/2026 · Cetera a su valor real ($<?= number_format($cetera,0,',','.') ?>, al <?= $ceteraFecha ?>); QBO lo tenía en $<?= number_format($qboCetera,0,',','.') ?> (desactualizado).</p>
 <div class="bsgrid">
  <div class="bsbox">
   <table>
    <tr class="sec"><td colspan="2">Activos</td></tr>
    <tr class="grp"><td colspan="2">Bancos y reserva</td></tr>
    <tr class="it"><td>Cetera (reserva, valor real) <span class="note">· QBO: $<?= number_format($qboCetera,0,',','.') ?></span></td><td class="num"><?= m2($cetera) ?></td></tr>
    <?php foreach(($act['Bank Accounts']??[]) as $r): ?><tr class="it"><td><?= htmlspecialchars($r['cuenta']) ?></td><td class="num"><?= m2($r['monto']) ?></td></tr><?php endforeach; ?>
    <?php foreach(['Other Current Assets'=>'Otros activos corrientes','Other Assets'=>'Otros activos'] as $gk=>$gl): if(empty($act[$gk]))continue; ?>
      <tr class="grp"><td colspan="2"><?= $gl ?></td></tr>
      <?php foreach($act[$gk] as $r): ?><tr class="it"><td><?= htmlspecialchars($r['cuenta']) ?></td><td class="num"><?= m2($r['monto']) ?></td></tr><?php endforeach; ?>
    <?php endforeach; ?>
    <tr class="tot"><td>Total activos</td><td class="num"><?= m2($activoTotal) ?></td></tr>
   </table>
  </div>
  <div class="bsbox">
   <table>
    <tr class="sec"><td colspan="2">Pasivos</td></tr>
    <tr class="grp"><td colspan="2">Tarjetas de crédito</td></tr>
    <?php foreach(($pas['Credit Cards']??[]) as $r): ?><tr class="it"><td><?= htmlspecialchars($r['cuenta']) ?></td><td class="num <?= $r['monto']<0?'neg':'' ?>"><?= m2($r['monto']) ?></td></tr><?php endforeach; ?>
    <tr class="tot"><td>Total pasivos</td><td class="num"><?= m2($pasivoTotal) ?></td></tr>
   </table>
   <div class="big"><span>Patrimonio neto <small style="color:var(--mut)">(activos − pasivos)</small></span><b><?= m2($patrimonio) ?></b></div>
  </div>
 </div>
 <div class="bsbox" style="margin-top:16px">
  <table>
   <tr class="sec"><td colspan="2">Capital contable · libros QBO <span class="note">(usa el Cetera desactualizado; no refleja el patrimonio real de arriba)</span></td></tr>
   <?php foreach($eq as $r): ?><tr class="it"><td><?= htmlspecialchars($r['cuenta']) ?></td><td class="num <?= $r['monto']<0?'neg':'' ?>"><?= m2($r['monto']) ?></td></tr><?php endforeach; ?>
   <tr class="tot"><td>Total capital (QBO)</td><td class="num"><?= m2($eqQBO) ?></td></tr>
  </table>
 </div>
 <p class="cap" style="margin-top:10px">Saldos de banco/tarjetas del Balance Sheet de QBO (subido manualmente); se actualizarán solos al conectar la API. Cetera siempre con el valor real.</p>
</div>
</body></html>
