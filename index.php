<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/runway.php';
$user = require_login();

$vel = rw_velocidad(6);
$saldoRow = rw_saldo_actual();
$saldo = $saldoRow ? (float)$saldoRow['saldo'] : 0.0;
$asof = $saldoRow ? date('d/m/Y', strtotime($saldoRow['fecha'])) : '—';
$proj = rw_proyeccion($saldo, $vel['velocidad']);
$MES=['','ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];

// Reserva histórica mensual (12m) para la proyección
$consumo = rw_consumo_mensual(); $cur = date('Y-m');
$seq=[]; $d=new DateTime('first day of this month'); $d->modify('-12 month');
for($i=0;$i<12;$i++){ $seq[]=$d->format('Y-m'); $d->modify('+1 month'); }
$histRes=array_fill(0,12,0.0); $acc=$saldo+($consumo[$cur]??0); $histRes[11]=$acc;
for($j=10;$j>=0;$j--){ $acc+=$consumo[$seq[$j+1]]??0; $histRes[$j]=$acc; }
$histLabels=[]; foreach($seq as $m){ [$y,$mo]=explode('-',$m); $histLabels[]=$MES[(int)$mo].' '.substr($y,2); }

// --- Serie SEMANAL (52 semanas): egresos, retiro de reserva, reserva ---
$wkStart=(new DateTime('monday this week'))->modify('-51 week')->format('Y-m-d');
$wq=db()->prepare("SELECT YEARWEEK(fecha,3) yw,
   SUM(CASE WHEN grupo_pnl='operativo' THEN monto_canonico ELSE 0 END) eg,
   SUM(CASE WHEN grupo_pnl='reserve_draw' THEN -monto_canonico ELSE 0 END) dr
   FROM transacciones WHERE fecha>=:s GROUP BY yw");
$wq->execute([':s'=>$wkStart]);
$byw=[]; foreach($wq as $r){ $byw[(int)$r['yw']]=['eg'=>(float)$r['eg'],'dr'=>(float)$r['dr']]; }
$weeks=[]; $dt=new DateTime('monday this week'); $dt->modify('-51 week');
for($i=0;$i<52;$i++){ $weeks[]=['lab'=>$dt->format('j').' '.$MES[(int)$dt->format('n')],'yw'=>(int)$dt->format('oW')]; $dt->modify('+1 week'); }
$egW=[];$drW=[];
foreach($weeks as $w){ $egW[]=isset($byw[$w['yw']])?round($byw[$w['yw']]['eg']):0; $drW[]=isset($byw[$w['yw']])?round($byw[$w['yw']]['dr']):0; }
$resW=array_fill(0,52,0.0); $resW[51]=$saldo;
for($j=50;$j>=0;$j--){ $resW[$j]=$resW[$j+1]+($drW[$j+1]??0); }
$wLabels=array_map(fn($w)=>$w['lab'],$weeks);

$conn = db()->query("SELECT estado, ultima_sync FROM qbo_oauth ORDER BY id DESC LIMIT 1")->fetch();
$data = ['saldo'=>$saldo,'vel'=>$vel['velocidad'],'histLabels'=>$histLabels,'histRes'=>array_map(fn($v)=>round($v),$histRes),
  'wLabels'=>$wLabels,'eg'=>$egW,'dr'=>$drW,'res'=>array_map(fn($v)=>round($v),$resW)];
$hayDatos=!empty($consumo)||$saldo>0;
function money($n){ return '$'.number_format((float)$n,0,',','.'); }
$ml=$proj['meses_restantes']; $mlTxt=is_finite($ml)?number_format($ml,1,',','.'):'∞';
$cls=!is_finite($ml)?'good':($ml<3?'bad':($ml<6?'warn':'good'));
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><title>KRATFEL · Finanzas</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
:root{--bg:#0f1320;--panel:#171c2e;--panel2:#1e2540;--line:#2a3252;--txt:#e8ecf7;--mut:#9aa6c7;--acc:#5b8cff;--good:#37d39b;--warn:#ffb454;--bad:#ff6b6b}
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--txt)}
header{display:flex;align-items:center;gap:16px;padding:14px 22px;border-bottom:1px solid var(--line)}
.brand{font-weight:800;font-size:18px;display:inline-flex;align-items:center;gap:7px}.brand span{color:var(--mut);font-weight:400;font-size:14px}
.who{margin-left:auto;color:var(--mut);font-size:13px}.who a{color:var(--mut)}
.wrap{max-width:1100px;margin:0 auto;padding:22px}
.fresh{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;font-size:12.5px;color:var(--mut)}
.chip{display:flex;align-items:center;gap:8px;background:var(--panel);border:1px solid var(--line);border-radius:999px;padding:6px 12px}
.dot{width:8px;height:8px;border-radius:50%;background:var(--good)}.dot.w{background:var(--warn)}.dot.b{background:var(--bad)}
.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px}
.kpi{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:16px 18px}
.kpi .lbl{color:var(--mut);font-size:12px;font-weight:600;text-transform:uppercase}.kpi .val{font-size:25px;font-weight:800;margin-top:8px}
.kpi.good .val{color:var(--good)}.kpi.warn .val{color:var(--warn)}.kpi.bad .val{color:var(--bad)}
.card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:16px 18px;margin-bottom:16px}
.card h3{margin:0 0 4px;font-size:15px}.cap{color:var(--mut);font-size:12.5px;margin:0 0 12px}
.scen{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px}
.scen button{background:var(--panel2);border:1px solid var(--line);color:var(--mut);padding:7px 12px;border-radius:999px;font-size:12.5px;cursor:pointer;font-weight:600}
.scen button.active{border-color:var(--acc);color:var(--txt);background:#1d2748}
.banner{background:#2a2140;border:1px solid #4a3a6a;border-radius:12px;padding:12px 14px;font-size:13px;margin-bottom:16px}
.legend{display:flex;gap:16px;font-size:12px;color:var(--mut);margin-top:8px;flex-wrap:wrap}
.legend i{display:inline-block;width:18px;height:0;border-top:3px solid var(--acc);vertical-align:middle;margin-right:6px}
.legend i.d{border-top-style:dashed;border-color:#7aa2ff}.legend i.b{height:12px;width:12px;border:0;border-radius:3px;vertical-align:middle}
nav{display:flex;gap:6px;margin-left:8px}
</style></head>
<body>
<header><div class="brand"><img src="/assets/logo_kratfel.png" alt="Kratfel" style="height:24px;vertical-align:middle"> <span>· Finanzas</span></div>
<nav style="display:flex;gap:6px;margin-left:8px"><a href="/" style="color:#e8ecf7;background:#1e2540;padding:8px 14px;border-radius:10px;text-decoration:none;font-size:14px;font-weight:600">Dashboard</a><a href="/pnl.php" style="color:#9aa6c7;padding:8px 14px;text-decoration:none;font-size:14px;font-weight:600">Reportes</a><a href="/forecast.php" style="color:#9aa6c7;padding:8px 14px;text-decoration:none;font-size:14px;font-weight:600">Forecast</a><a href="/flujo.php" style="color:#9aa6c7;padding:8px 14px;text-decoration:none;font-size:14px;font-weight:600">Flujo</a></nav>
<div class="who"><?= htmlspecialchars($user['nombre'] ?? $user['email']) ?> · <a href="/auth/logout.php">Salir</a></div></header>
<div class="wrap">
<?php if(!$hayDatos): ?><div class="banner"><b>Sin datos todavía.</b></div><?php endif; ?>
<div class="fresh">
  <div class="chip"><span class="dot <?= ($conn && $conn['estado']==='conectado')?'':'b' ?>"></span>QBO: <b><?= $conn?htmlspecialchars($conn['estado']):'no conectado' ?></b><?php if($conn&&$conn['ultima_sync']): ?> · última sync <?= date('d/m/Y',strtotime($conn['ultima_sync'])) ?><?php endif; ?></div>
  <div class="chip"><span class="dot w"></span>Saldo Cetera al: <b><?= $asof ?></b></div>
  <?php if(!$conn||$conn['estado']!=='conectado'): ?><div class="chip"><a href="/qbo/connect.php" style="color:var(--acc);font-weight:700">Conectar con QuickBooks →</a></div><?php endif; ?>
</div>
<div class="kpis">
  <div class="kpi"><div class="lbl">Reserva Cetera hoy</div><div class="val"><?= money($saldo) ?></div></div>
  <div class="kpi"><div class="lbl">Consumo mensual</div><div class="val" id="kVel"><?= money($vel['velocidad']) ?></div></div>
  <div class="kpi <?= $cls ?>"><div class="lbl">Meses restantes</div><div class="val" id="kMeses"><?= $mlTxt ?></div></div>
  <div class="kpi <?= $cls ?>"><div class="lbl">Fecha estimada de cero</div><div class="val" id="kCero"><?= htmlspecialchars($proj['fecha_cero']??'—') ?></div></div>
</div>
<div class="card">
  <h3>Reserva: historial y proyección de oxígeno</h3>
  <p class="cap">Línea sólida = reserva real de los últimos 12 meses. Punteada = proyección al ritmo de consumo. Cambia el escenario para mover la fecha de cero.</p>
  <div class="scen" id="scen"></div>
  <div style="height:300px"><canvas id="chart"></canvas></div>
  <div class="legend"><span><i></i>Reserva (real)</span><span><i class="d"></i>Proyección</span></div>
</div>
<div class="card">
  <h3>Egresos y reserva · por semana (52 semanas)</h3>
  <p class="cap">Detalle semanal: egresos operativos y retiro de reserva (barras), reserva (línea). Sin ventas, cada semana se cubre retirando de Cetera.</p>
  <div style="height:330px"><canvas id="chartW"></canvas></div>
  <div class="legend"><span><i class="b" style="background:#ff6b6b"></i>Egresos operativos</span><span><i class="b" style="background:#64748b"></i>Retiro de reserva</span><span><i style="border-color:#ffb454"></i>Reserva (saldo, eje der.)</span></div>
</div>
</div>
<script>
const D = <?= json_encode($data, JSON_UNESCAPED_UNICODE) ?>;
const fmt=n=>(n<0?'-':'')+'$'+Math.abs(Math.round(n)).toLocaleString('es-ES');
const MESJS=['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
const scen=[{id:'base',name:'Base',mult:1,inc:0,im:0},{id:'c20',name:'Recorte −20%',mult:.8,inc:0,im:0},{id:'c35',name:'Austeridad −35%',mult:.65,inc:0,im:0},{id:'ord',name:'Orden $120k (mes 3)',mult:1,inc:120000,im:3}];
let cur='base';
const scEl=document.getElementById('scen');
scen.forEach(s=>{const b=document.createElement('button');b.textContent=s.name;if(s.id==='base')b.classList.add('active');b.onclick=()=>{cur=s.id;[...scEl.children].forEach(x=>x.classList.remove('active'));b.classList.add('active');render();};scEl.appendChild(b);});
function project(s){const v=D.vel*s.mult;let bal=D.saldo;const lbl=['hoy'],val=[Math.round(bal)];let cero=null,b2=bal,ml=0;
 for(let i=1;i<=12;i++){if(i===s.im&&s.inc)bal+=s.inc;bal-=v;const d=new Date(new Date().getFullYear(),(new Date().getMonth())+i,1);lbl.push(MESJS[d.getMonth()]+' '+String(d.getFullYear()%100));val.push(Math.round(bal));if(bal<=0&&cero===null)cero=lbl[i];}
 for(let i=1;i<=600;i++){if(i===s.im&&s.inc)b2+=s.inc;if(v<=0){ml=Infinity;break;}if(b2-v<=0){ml=(i-1)+(b2/v);break;}b2-=v;ml=i;}
 return {v,lbl,val,cero,ml};}
let chart;
function render(){const s=scen.find(x=>x.id===cur),p=project(s);
 document.getElementById('kVel').textContent=fmt(p.v);
 document.getElementById('kMeses').textContent=isFinite(p.ml)?p.ml.toFixed(1):'∞';
 document.getElementById('kCero').textContent=p.cero||'—';
 const nh=D.histLabels.length, labels=[...D.histLabels,...p.lbl];
 const solid=[...D.histRes,D.saldo,...Array(p.lbl.length-1).fill(null)];
 const dashed=[...Array(nh).fill(null),...p.val];
 const ds=[{label:'Reserva (real)',data:solid,borderColor:'#5b8cff',backgroundColor:'#5b8cff',pointRadius:2,tension:.2},
           {label:'Proyección',data:dashed,borderColor:'#7aa2ff',borderDash:[6,5],pointRadius:0,tension:.15}];
 if(cur!=='base'){const bp=project(scen[0]);ds.push({label:'Base',data:[...Array(nh).fill(null),...bp.val],borderColor:'#41507a',borderDash:[2,4],pointRadius:0});}
 if(chart)chart.destroy();
 chart=new Chart(document.getElementById('chart'),{type:'line',data:{labels,datasets:ds},options:{maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>c.dataset.label+': '+fmt(c.parsed.y)}}},scales:{y:{grid:{color:'#222a45'},ticks:{color:'#9aa6c7',callback:v=>fmt(v)}},x:{grid:{display:false},ticks:{color:'#9aa6c7',maxRotation:0,autoSkip:true,maxTicksLimit:13}}}}});
}
render();
// --- gráfica semanal ---
new Chart(document.getElementById('chartW'),{data:{labels:D.wLabels,datasets:[
  {type:'bar',label:'Egresos operativos',data:D.eg,backgroundColor:'#ff6b6b',yAxisID:'y',order:2},
  {type:'bar',label:'Retiro de reserva',data:D.dr,backgroundColor:'#64748b',yAxisID:'y',order:2},
  {type:'line',label:'Reserva',data:D.res,borderColor:'#ffb454',backgroundColor:'#ffb454',yAxisID:'y1',tension:.2,pointRadius:0,borderWidth:2,order:1},
]},options:{maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>c.dataset.label+': '+fmt(c.parsed.y)}}},
 scales:{y:{position:'left',grid:{color:'#222a45'},ticks:{color:'#9aa6c7',callback:v=>fmt(v)}},
  y1:{position:'right',grid:{display:false},ticks:{color:'#ffb454',callback:v=>fmt(v)}},
  x:{grid:{display:false},ticks:{color:'#9aa6c7',maxRotation:0,autoSkip:true,maxTicksLimit:14}}}}});
</script>
</body></html>
