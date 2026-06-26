<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/runway.php';
$user = require_login();

$vel = rw_velocidad(3);
$saldoRow = rw_saldo_actual();
$saldo = $saldoRow ? (float)$saldoRow['saldo'] : 0.0;
$asof = $saldoRow ? date('d/m/Y', strtotime($saldoRow['fecha'])) : '—';
$proj = rw_proyeccion($saldo, $vel['velocidad']);

// --- Serie SEMANAL: 52 históricas + 34 de proyección ---
$histW=52; $fwdW=34; $totW=$histW+$fwdW;
$wkStart=(new DateTime('monday this week'))->modify('-'.($histW-1).' week')->format('Y-m-d');
$wq=db()->prepare("SELECT YEARWEEK(fecha,3) yw,
   SUM(CASE WHEN grupo_pnl='operativo' THEN monto_canonico ELSE 0 END) eg,
   SUM(CASE WHEN grupo_pnl='reserve_draw' THEN -monto_canonico ELSE 0 END) dr
   FROM transacciones WHERE fecha>=:s GROUP BY yw");
$wq->execute([':s'=>$wkStart]);
$byw=[]; foreach($wq as $r){ $byw[(int)$r['yw']]=['eg'=>(float)$r['eg'],'dr'=>(float)$r['dr']]; }
$dt=new DateTime('monday this week'); $dt->modify('-'.($histW-1).' week');
$labels=[]; $eg=[]; $dr=[]; $prevM='';
for($i=0;$i<$totW;$i++){
  $yw=(int)$dt->format('oW'); $mk=$dt->format('m/y'); $mon=($mk!==$prevM)?$mk:''; $prevM=$mk;
  $labels[]=[ (int)$dt->format('W'), $mon ];
  if($i<$histW){ $eg[]=isset($byw[$yw])?round($byw[$yw]['eg']):0; $dr[]=isset($byw[$yw])?round($byw[$yw]['dr']):0; }
  else { $eg[]=null; $dr[]=null; }
  $dt->modify('+1 week');
}
$resH=array_fill(0,$histW,0.0); $resH[$histW-1]=$saldo;
for($j=$histW-2;$j>=0;$j--){ $resH[$j]=$resH[$j+1]+($dr[$j+1]??0); }

$conn = db()->query("SELECT estado, ultima_sync FROM qbo_oauth ORDER BY id DESC LIMIT 1")->fetch();
$data=['saldo'=>$saldo,'vel'=>$vel['velocidad'],'velWeek'=>$vel['velocidad']/4.345,
  'histW'=>$histW,'totW'=>$totW,'labels'=>$labels,'eg'=>$eg,'dr'=>$dr,'resH'=>array_map(fn($v)=>round($v),$resH)];
function money($n){ return '$'.number_format((float)$n,0,'.',','); }
$ml=$proj['meses_restantes']; $mlTxt=is_finite($ml)?number_format($ml,1,'.',','):'∞';
$cls=!is_finite($ml)?'good':($ml<3?'bad':($ml<6?'warn':'good'));
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/png" href="/assets/favicon.png"><title>KRATFEL · Finanzas</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
:root{--bg:#0f1320;--panel:#171c2e;--panel2:#1e2540;--line:#2a3252;--txt:#e8ecf7;--mut:#9aa6c7;--acc:#5b8cff;--good:#37d39b;--warn:#ffb454;--bad:#ff6b6b;--violet:#9b6bff}
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--txt)}
header{display:flex;align-items:center;gap:16px;padding:14px 22px;border-bottom:1px solid var(--line)}
.brand{display:flex;flex-direction:column;gap:3px;text-decoration:none;align-items:flex-end}.brand img{height:20px;display:block}.brand .tag{font-size:9.5px;letter-spacing:.22em;text-transform:uppercase;color:var(--mut)}.hdiv{width:1px;height:26px;background:var(--line);flex:none}
.who{margin-left:auto;color:var(--mut);font-size:13px}.who a{color:var(--mut)}
.wrap{max-width:1200px;margin:0 auto;padding:22px}
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
.legend{display:flex;gap:16px;font-size:12px;color:var(--mut);margin-top:8px;flex-wrap:wrap}
.legend i{display:inline-block;width:12px;height:12px;border-radius:3px;vertical-align:middle;margin-right:6px}
.legend i.l{height:0;width:18px;border-top:3px solid var(--violet);border-radius:0}.legend i.d{height:0;width:18px;border-top:3px dashed var(--violet);border-radius:0}
nav a{padding:8px 14px;border-radius:10px;text-decoration:none;font-size:14px;font-weight:600}
.kpi{border-top:3px solid var(--acc);transition:transform .12s ease,border-color .12s ease}.kpi.good{border-top-color:var(--good)}.kpi.warn{border-top-color:var(--warn)}.kpi.bad{border-top-color:var(--bad)}.kpi:hover{transform:translateY(-2px)}.card{transition:border-color .15s ease}.card:hover{border-color:#33406b}.ftr{max-width:1200px;margin:0 auto;padding:6px 22px 30px;color:var(--mut);font-size:11px;opacity:.6}</style></head>
<body>
<header><a class="brand" href="/"><img src="/assets/logo_kratfel.png" alt="Kratfel"><span class="tag">Finanzas</span></a><span class="hdiv"></span>
<nav style="display:flex;gap:6px;margin-left:8px"><a href="/" style="color:#e8ecf7;background:#1e2540">Dashboard</a><a href="/pnl.php" style="color:#9aa6c7">Reportes</a><a href="/forecast.php" style="color:#9aa6c7">Forecast</a></nav>
<div class="who"><?= htmlspecialchars($user['nombre'] ?? $user['email']) ?> · <a href="/settings.php">Ajustes</a> · <a href="/auth/logout.php">Salir</a></div></header>
<div class="wrap">
<div class="fresh">
  <div class="chip"><span class="dot w"></span>Saldo Cetera al: <b><?= $asof ?></b></div>
</div>
<div class="kpis">
  <div class="kpi"><div class="lbl">Reserva Cetera hoy</div><div class="val"><?= money($saldo) ?></div></div>
  <div class="kpi"><div class="lbl">Consumo mensual</div><div class="val" id="kVel"><?= money($vel['velocidad']) ?></div><div style="color:var(--mut);font-size:11px;margin-top:4px">prom. últimos 3 meses · retiros de reserva</div></div>
  <div class="kpi <?= $cls ?>"><div class="lbl">Meses restantes</div><div class="val" id="kMeses"><?= $mlTxt ?></div></div>
  <div class="kpi <?= $cls ?>"><div class="lbl">Fecha estimada de cero</div><div class="val" id="kCero"><?= htmlspecialchars($proj['fecha_cero']??'—') ?></div></div>
</div>
<div class="card">
  <h3>Egresos, retiro de reserva y proyección · por semana</h3>
  <p class="cap">Barras = egresos y retiro de reserva por semana (histórico). Línea morada = reserva real (sólida) y proyección (punteada). Las líneas verticales separan los meses. La proyección parte del consumo promedio de los <b>últimos 3 meses</b> de retiros de reserva (más conservador que 6 meses). Cambia el escenario para mover la fecha de cero.</p>
  <div class="scen" id="scen"></div>
  <div style="height:380px"><canvas id="chart"></canvas></div>
  <div class="legend"><span><i style="background:#ff6b6b"></i>Egresos operativos</span><span><i style="background:#64748b"></i>Retiro de reserva</span><span><i class="l"></i>Reserva real</span><span><i class="d"></i>Proyección</span></div>
</div>
</div>
<script>
const D = <?= json_encode($data, JSON_UNESCAPED_UNICODE) ?>;
const fmt=n=>(n<0?'-':'')+'$'+Math.abs(Math.round(n)).toLocaleString('en-US');
const MESJS=['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
const monthSep={id:'monthSep',afterDraw(c){const x=c.scales.x,a=c.chartArea,ctx=c.ctx;const half=(x.getPixelForValue(1)-x.getPixelForValue(0))/2;const starts=[];D.labels.forEach((l,i)=>{if(l[1])starts.push(i);});ctx.save();ctx.strokeStyle='rgba(154,166,199,.16)';ctx.lineWidth=1;starts.forEach(i=>{const px=x.getPixelForValue(i)-half;ctx.beginPath();ctx.moveTo(px,a.top);ctx.lineTo(px,a.bottom);ctx.stroke();});ctx.fillStyle='#9aa6c7';ctx.font='10px -apple-system,Segoe UI,sans-serif';ctx.textAlign='center';ctx.textBaseline='top';for(let k=0;k<starts.length;k++){const i=starts[k];const left=x.getPixelForValue(i)-half;const right=(k+1<starts.length)?x.getPixelForValue(starts[k+1])-half:a.right;if(right-left<26)continue;ctx.fillText(D.labels[i][1],(left+right)/2,a.bottom+8);}const hx=x.getPixelForValue(D.histW-1);ctx.setLineDash([4,3]);ctx.strokeStyle='rgba(123,162,255,.75)';ctx.lineWidth=1.5;ctx.beginPath();ctx.moveTo(hx,a.top);ctx.lineTo(hx,a.bottom);ctx.stroke();ctx.setLineDash([]);ctx.fillStyle='#7aa2ff';ctx.font='bold 10px sans-serif';ctx.textAlign='center';ctx.textBaseline='top';ctx.fillText('hoy',hx,a.top+2);ctx.restore();}};
const scen=[{id:'base',name:'Base',mult:1,inc:0,im:0},{id:'c20',name:'Recorte −20%',mult:.8,inc:0,im:0},{id:'c35',name:'Austeridad −35%',mult:.65,inc:0,im:0},{id:'ord',name:'Orden $120k (mes 3)',mult:1,inc:120000,im:3}];
let cur='base';
const scEl=document.getElementById('scen');
scen.forEach(s=>{const b=document.createElement('button');b.textContent=s.name;if(s.id==='base')b.classList.add('active');b.onclick=()=>{cur=s.id;[...scEl.children].forEach(x=>x.classList.remove('active'));b.classList.add('active');render();};scEl.appendChild(b);});
// KPIs mensuales
function monthly(s){const v=D.vel*s.mult;let b=D.saldo,ml=0,cero=null;
 for(let i=1;i<=600;i++){if(i===s.im&&s.inc)b+=s.inc;if(v<=0){ml=Infinity;break;}if(b-v<=0){ml=(i-1)+(b/v);const d=new Date(new Date().getFullYear(),(new Date().getMonth())+i,1);cero=MESJS[d.getMonth()]+' '+String(d.getFullYear()%100);break;}b-=v;ml=i;}
 return {v,ml,cero};}
// proyección semanal de la reserva
function weeklyForward(s){const vw=D.velWeek*s.mult;const arr=Array(D.totW).fill(null);let bal=D.saldo;arr[D.histW-1]=Math.round(bal);
 const ordWeek=D.histW-1+Math.round(s.im*4.345);
 for(let k=D.histW;k<D.totW;k++){ if(k===ordWeek&&s.inc)bal+=s.inc; bal-=vw; if(bal<=0){arr[k]=0;break;} arr[k]=Math.round(bal);} return arr;}
let chart;
function render(){const s=scen.find(x=>x.id===cur);const mo=monthly(s);
 document.getElementById('kVel').textContent=fmt(mo.v);
 document.getElementById('kMeses').textContent=isFinite(mo.ml)?mo.ml.toFixed(1):'∞';
 document.getElementById('kCero').textContent=mo.cero||'—';
 const solid=[...D.resH, ...Array(D.totW-D.histW).fill(null)];
 const dotted=weeklyForward(s);
 const ds=[
  {type:'bar',label:'Egresos operativos',data:D.eg,backgroundColor:'#ff6b6b',yAxisID:'y',order:3},
  {type:'bar',label:'Retiro de reserva',data:D.dr,backgroundColor:'#64748b',yAxisID:'y',order:3},
  {type:'line',label:'Reserva real',data:solid,borderColor:'#9b6bff',backgroundColor:'#9b6bff',yAxisID:'y1',pointRadius:0,borderWidth:2,tension:.2,order:1},
  {type:'line',label:'Proyección',data:dotted,borderColor:'#9b6bff',borderDash:[6,5],yAxisID:'y1',pointRadius:0,borderWidth:2,tension:.15,order:2},
 ];
 if(chart)chart.destroy();
 chart=new Chart(document.getElementById('chart'),{data:{labels:D.labels,datasets:ds},plugins:[monthSep],options:{maintainAspectRatio:false,layout:{padding:{bottom:22}},
  plugins:{legend:{display:false},tooltip:{callbacks:{title:items=>{const i=items[0].dataIndex;const l=D.labels[i];return 'Semana '+l[0]+(l[1]?' · '+l[1]:'');},label:c=>c.dataset.label+': '+fmt(c.parsed.y)}}},
  scales:{
   y:{position:'left',grid:{color:'#222a45'},ticks:{color:'#9aa6c7',callback:v=>fmt(v)},title:{display:true,text:'Semanal',color:'#9aa6c7'}},
   y1:{position:'right',grid:{display:false},ticks:{color:'#9b6bff',callback:v=>fmt(v)},title:{display:true,text:'Reserva',color:'#9b6bff'}},
   x:{grid:{display:false},ticks:{color:'#9aa6c7',font:{size:10},autoSkip:false,maxRotation:0,minRotation:0,
      callback:function(){return '';}}}
  }}});
}
render();
</script>
<footer class="ftr">KRATFEL Finanzas · Datos de QuickBooks (gastos) y Cetera (reserva). Las proyecciones son estimaciones y no constituyen asesoría financiera.</footer>
</body></html>
