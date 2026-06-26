<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/runway.php';
$user = require_login();

// Saldo ancla
$sr = rw_saldo_actual();
$anchor = $sr ? (float)$sr['saldo'] : 0.0;

// Meses: 12 previos + actual (13), cronológico
$ms = []; $d = new DateTime('first day of this month'); $d->modify('-12 month');
for ($i=0;$i<13;$i++){ $ms[]=$d->format('Y-m'); $d->modify('+1 month'); }
$start = $ms[0].'-01';

// Egresos operativos por mes
$eg = array_fill_keys($ms, 0.0);
$q = db()->prepare("SELECT DATE_FORMAT(fecha,'%Y-%m') ym, SUM(monto_canonico) t FROM transacciones
  WHERE grupo_pnl='operativo' AND fecha>=:s GROUP BY ym");
$q->execute([':s'=>$start]);
foreach($q as $r){ if(isset($eg[$r['ym']])) $eg[$r['ym']]=(float)$r['t']; }

// Retiros de reserva (aporte que financia la operación) por mes
$consumo = rw_consumo_mensual();
$draw = []; foreach($ms as $m){ $draw[$m] = round($consumo[$m] ?? 0); }

// Reserva (balance) reconstruida: ancla hoy, hacia atrás sumando los retiros posteriores
$res = array_fill_keys($ms, 0.0);
$res[$ms[12]] = $anchor;
for($i=11;$i>=0;$i--){ $res[$ms[$i]] = $res[$ms[$i+1]] + ($draw[$ms[$i+1]] ?? 0); }

$MES=['','ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
$labels=[]; foreach($ms as $m){ [$y,$mo]=explode('-',$m); $labels[]=$MES[(int)$mo].' '.substr($y,2); }
$data=[
  'labels'=>$labels,
  'egresos'=>array_map(fn($m)=>round($eg[$m]), $ms),
  'aporte'=>array_map(fn($m)=>$draw[$m], $ms),
  'reserva'=>array_map(fn($m)=>round($res[$m]), $ms),
  'ingresos'=>array_fill(0,13,0),
];
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><title>Ingresos y Egresos — KRATFEL Finanzas</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
:root{--bg:#0f1320;--panel:#171c2e;--panel2:#1e2540;--line:#2a3252;--txt:#e8ecf7;--mut:#9aa6c7;--acc:#5b8cff;--good:#37d39b;--warn:#ffb454;--bad:#ff6b6b}
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--txt)}
header{display:flex;align-items:center;gap:16px;padding:14px 22px;border-bottom:1px solid var(--line)}
.brand{font-weight:800;font-size:18px;display:inline-flex;align-items:center;gap:7px}.brand span{color:var(--mut);font-weight:400;font-size:14px}
nav{display:flex;gap:6px;margin-left:8px}nav a{color:var(--mut);padding:8px 14px;border-radius:10px;text-decoration:none;font-size:14px;font-weight:600}
nav a.active{background:var(--panel2);color:var(--txt)}nav a:hover{color:var(--txt)}
.who{margin-left:auto;color:var(--mut);font-size:13px}.who a{color:var(--mut)}
.wrap{max-width:1150px;margin:0 auto;padding:22px}
h2{font-size:18px;margin:0 0 4px}.cap{color:var(--mut);font-size:12.5px;margin:0 0 16px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:16px 18px;margin-bottom:16px}
.legend{display:flex;gap:18px;flex-wrap:wrap;font-size:12.5px;color:var(--mut);margin-top:10px}
.legend i{display:inline-block;width:12px;height:12px;border-radius:3px;vertical-align:middle;margin-right:6px}
.legend i.l{height:0;width:18px;border-top:3px solid var(--warn);border-radius:0}
.kpis{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:8px}
.kpi{background:var(--panel2);border:1px solid var(--line);border-radius:12px;padding:10px 14px;font-size:13px;color:var(--mut)}
.kpi b{color:var(--txt);font-size:16px;display:block;margin-top:2px}
</style></head>
<body>
<header>
 <div class="brand"><img src="/assets/logo_kratfel.png" alt="Kratfel" style="height:24px"> <span>· Finanzas</span></div>
 <nav><a href="/">Dashboard</a><a href="/pnl.php">P&amp;L</a><a href="/forecast.php">Forecast</a><a class="active" href="/flujo.php">Flujo</a><a href="/balance.php">Balance</a></nav>
 <div class="who"><?= htmlspecialchars($user['nombre'] ?? $user['email']) ?> · <a href="/auth/logout.php">Salir</a></div>
</header>
<div class="wrap">
 <h2>Ingresos, egresos y reserva</h2>
 <p class="cap">Últimos 12 meses + actual. Sin ventas: la operación (egresos) se cubre retirando de la reserva de Cetera (aporte), que por eso va bajando.</p>
 <div class="kpis">
   <div class="kpi">Egresos 12m<b id="kEg"></b></div>
   <div class="kpi">Retirado de reserva 12m<b id="kAp"></b></div>
   <div class="kpi">Ingresos por ventas 12m<b style="color:var(--bad)">$0</b></div>
   <div class="kpi">Reserva hoy<b><?= '$'.number_format($anchor,0,',','.') ?></b></div>
 </div>
 <div class="card">
   <div style="height:380px"><canvas id="ch"></canvas></div>
   <div class="legend"><span><i style="background:#ff6b6b"></i>Egresos operativos</span><span><i style="background:#64748b"></i>Retiro de reserva (traspaso)</span><span><i style="background:#5b8cff"></i>Ingresos (ventas)</span><span><i class="l"></i>Reserva (saldo, eje der.)</span></div>
 </div>
</div>
<script>
const D = <?= json_encode($data, JSON_UNESCAPED_UNICODE) ?>;
const fmt=n=>'$'+Math.round(n).toLocaleString('es-ES');
const sum=a=>a.reduce((s,x)=>s+x,0);
document.getElementById('kEg').textContent=fmt(sum(D.egresos));
document.getElementById('kAp').textContent=fmt(sum(D.aporte));
new Chart(document.getElementById('ch'),{
 data:{labels:D.labels,datasets:[
   {type:'bar',label:'Egresos operativos',data:D.egresos,backgroundColor:'#ff6b6b',yAxisID:'y',order:2},
   {type:'bar',label:'Retiro de reserva (traspaso)',data:D.aporte,backgroundColor:'#64748b',yAxisID:'y',order:2},
   {type:'bar',label:'Ingresos (ventas)',data:D.ingresos,backgroundColor:'#5b8cff',yAxisID:'y',order:2},
   {type:'line',label:'Reserva (saldo)',data:D.reserva,borderColor:'#ffb454',backgroundColor:'#ffb454',yAxisID:'y1',tension:.2,pointRadius:2,order:1},
 ]},
 options:{maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>c.dataset.label+': '+fmt(c.parsed.y)}}},
  scales:{
   y:{position:'left',grid:{color:'#222a45'},ticks:{color:'#9aa6c7',callback:v=>fmt(v)},title:{display:true,text:'Mensual',color:'#9aa6c7'}},
   y1:{position:'right',grid:{display:false},ticks:{color:'#ffb454',callback:v=>fmt(v)},title:{display:true,text:'Reserva',color:'#ffb454'}},
   x:{grid:{display:false},ticks:{color:'#9aa6c7'}}
  }}
});
</script>
</body></html>
