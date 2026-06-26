<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/runway.php';
$user = require_login();

$saldoRow = rw_saldo_actual();
$saldo = $saldoRow ? (float)$saldoRow['saldo'] : 0.0;
$asof = $saldoRow ? date('d/m/Y', strtotime($saldoRow['fecha'])) : '—';

// Últimos 3 meses COMPLETOS (excluye el mes en curso)
$firstThis = (new DateTime('first day of this month'))->format('Y-m-d');
$start3 = (new DateTime('first day of this month'))->modify('-3 month')->format('Y-m-d');
$m1 = (new DateTime('first day of this month'))->modify('-1 month')->format('Y-m'); // último mes completo

$q = db()->prepare("SELECT categoria, grupo_pnl, DATE_FORMAT(fecha,'%Y-%m') ym, SUM(monto_canonico) t
  FROM transacciones WHERE grupo_pnl IN ('operativo','distribucion','impuestos')
  AND fecha >= :s AND fecha < :e GROUP BY categoria, grupo_pnl, ym");
$q->execute([':s'=>$start3, ':e'=>$firstThis]);

$op = [];   // categoria => ['sum3'=>, 'last'=>]
$grp = ['distribucion'=>['sum3'=>0,'last'=>0], 'impuestos'=>['sum3'=>0,'last'=>0]];
foreach ($q as $r) {
    $v=(float)$r['t']; $g=$r['grupo_pnl']; $c=$r['categoria']; $ym=$r['ym'];
    if ($g==='operativo') {
        if(!isset($op[$c])) $op[$c]=['sum3'=>0,'last'=>0];
        $op[$c]['sum3']+=$v;
        if($ym===$m1) $op[$c]['last']+=$v;
    } else {
        $grp[$g]['sum3']+=$v;
        if($ym===$m1) $grp[$g]['last']+=$v;
    }
}
$cats=[];
foreach($op as $c=>$d){ $a3=round($d['sum3']/3); if($a3>0||round($d['last'])>0) $cats[]=['n'=>$c,'avg3'=>$a3,'last'=>round($d['last'])]; }
usort($cats, fn($a,$b)=>$b['avg3']<=>$a['avg3']);
$eventos=[
  ['n'=>'Distribuciones a socios','avg3'=>round($grp['distribucion']['sum3']/3),'last'=>round($grp['distribucion']['last'])],
  ['n'=>'Impuestos','avg3'=>round($grp['impuestos']['sum3']/3),'last'=>round($grp['impuestos']['last'])],
];
$data=['saldo'=>$saldo,'asof'=>$asof,'cats'=>$cats,'eventos'=>$eventos,'mes0'=>(int)date('n'),'anio0'=>(int)date('Y')];
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><title>Forecast — KRATFEL Finanzas</title>
<style>
:root{--bg:#0f1320;--panel:#171c2e;--panel2:#1e2540;--line:#2a3252;--txt:#e8ecf7;--mut:#9aa6c7;--acc:#5b8cff;--good:#37d39b;--warn:#ffb454;--bad:#ff6b6b;--yellow:#3a3520;--yellowbd:#7a6a2a}
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--txt)}
header{display:flex;align-items:center;gap:16px;padding:14px 22px;border-bottom:1px solid var(--line)}
.brand{font-weight:800;font-size:18px;display:inline-flex;align-items:center;gap:7px}.brand span{color:var(--mut);font-weight:400;font-size:14px}
nav{display:flex;gap:6px;margin-left:8px}nav a{color:var(--mut);padding:8px 14px;border-radius:10px;text-decoration:none;font-size:14px;font-weight:600}
nav a.active{background:var(--panel2);color:var(--txt)}nav a:hover{color:var(--txt)}
.who{margin-left:auto;color:var(--mut);font-size:13px}.who a{color:var(--mut)}
.wrap{max-width:1200px;margin:0 auto;padding:22px}
.toolbar{display:flex;align-items:center;gap:14px;margin-bottom:14px;flex-wrap:wrap}
.toolbar h2{margin:0;font-size:18px}
.seg{display:inline-flex;border:1px solid var(--line);border-radius:10px;overflow:hidden}
.seg button{background:var(--panel);border:none;color:var(--mut);padding:7px 12px;cursor:pointer;font-weight:600;font-size:12.5px}
.seg button.active{background:var(--acc);color:#fff}
.muted{color:var(--mut);font-size:12.5px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:8px;overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13px;min-width:720px}
th,td{padding:8px 12px;text-align:right;border-bottom:1px solid var(--line);font-variant-numeric:tabular-nums;white-space:nowrap}
th:first-child,td:first-child{text-align:left;position:sticky;left:0;background:var(--panel);min-width:230px}
thead th{color:var(--mut);font-size:11px;text-transform:uppercase;letter-spacing:.3px}
.sect td{background:var(--panel2);color:var(--mut);font-size:11px;text-transform:uppercase;letter-spacing:.3px;font-weight:700}
.tot td{font-weight:700;border-top:1px solid var(--line)}
.start td,.end td{font-weight:800}
.end td{border-top:2px solid var(--line)}
.neg{color:var(--bad)}.pos{color:var(--good)}
.cell-edit{background:var(--yellow);border:1px solid var(--yellowbd);color:#ffe39a;border-radius:6px;padding:5px 7px;width:96px;text-align:right;font-size:13px;font-variant-numeric:tabular-nums}
.cell-edit:focus{outline:2px solid var(--warn)}
.badges{margin-top:3px;font-size:10.5px;color:var(--mut);display:flex;gap:8px}
.badges b{color:var(--txt)}
.badge{cursor:pointer;border:1px solid var(--line);border-radius:999px;padding:1px 7px}
.badge:hover{border-color:var(--acc);color:var(--txt)}
.badge.on{border-color:var(--acc);color:#cfe0ff;background:#1d2748}
.zero{color:var(--bad);font-weight:700}
.legend{margin-top:10px;color:var(--mut);font-size:12px}.legend b{color:#ffe39a}
.catname{font-weight:600}
</style></head>
<body>
<header>
 <div class="brand"><img src="/assets/logo_kratfel.png" alt="Kratfel" style="height:24px"> <span>· Finanzas</span></div>
 <nav><a href="/">Dashboard</a><a href="/pnl.php">P&amp;L</a><a class="active" href="/forecast.php">Forecast</a><a href="/flujo.php">Flujo</a><a href="/balance.php">Balance</a></nav>
 <div class="who"><?= htmlspecialchars($user['nombre'] ?? $user['email']) ?> · <a href="/auth/logout.php">Salir</a></div>
</header>
<div class="wrap">
 <div class="toolbar">
   <h2>Forecast de reserva</h2>
   <span class="muted">Reserva Cetera hoy: <b><?= '$'.number_format($saldo,0,',','.') ?></b> · al <?= $asof ?></span>
   <div style="margin-left:auto;display:flex;gap:14px;align-items:center;flex-wrap:wrap">
     <span class="muted">Base de gasto:</span>
     <div class="seg" id="base"><button data-b="avg3" class="active">Prom 3 meses</button><button data-b="last">Último mes</button></div>
     <div class="seg" id="seg"><button data-m="3" class="active">3m</button><button data-m="6">6m</button><button data-m="12">12m</button></div>
   </div>
 </div>
 <div class="card"><table id="grid"></table></div>
 <div class="legend">Celdas <b>amarillas</b> = editables (manual). Usa <b>Base de gasto</b> para llenar todas con el promedio de 3 meses o el último mes; o haz clic en los badges <b>3m</b> / <b>Últ</b> de cada categoría. El saldo final y la fecha de cero se recalculan al instante.</div>
</div>
<script>
const D = <?= json_encode($data, JSON_UNESCAPED_UNICODE) ?>;
const fmt = n => (n<0?'-':'')+'$'+Math.abs(Math.round(n)).toLocaleString('es-ES');
const MES=['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
let months=3, base='avg3';
let state={
  start:D.saldo, income:0, order:{amount:0,month:1},
  cats:D.cats.map(c=>({...c, v:c.avg3, mode:'avg3'})),
  eventos:D.eventos.map(c=>({...c, v:0, mode:'manual'})),
};
function colLabels(){const out=[];let m=D.mes0-1,y=D.anio0;for(let i=0;i<months;i++){out.push(MES[m]+' '+String(y).slice(2));m++;if(m>11){m=0;y++;}}return out;}
function calc(){
  const labels=colLabels(); const inc=[],exp=[],net=[],sa=[],ea=[]; let bal=state.start,zi=-1;
  const monthlyExp=state.cats.reduce((s,c)=>s+(+c.v||0),0)+state.eventos.reduce((s,c)=>s+(+c.v||0),0);
  for(let i=0;i<months;i++){
    sa.push(bal);
    let income=(+state.income||0)+((i+1===+state.order.month)?(+state.order.amount||0):0);
    let expense=monthlyExp; let n=income-expense; bal+=n;
    inc.push(income);exp.push(expense);net.push(n);ea.push(bal);
    if(bal<=0&&zi<0)zi=i;
  }
  return {labels,inc,exp,net,sa,ea,zi};
}
function num(v){return (+v||0);}
function rowCat(arr,key,cat,idx){
  const badges='<div class="badges">'
    +'<span class="badge '+(cat.mode==='avg3'?'on':'')+'" data-set="'+key+'" data-i="'+idx+'" data-to="avg3">3m '+fmt(cat.avg3)+'</span>'
    +'<span class="badge '+(cat.mode==='last'?'on':'')+'" data-set="'+key+'" data-i="'+idx+'" data-to="last">Últ '+fmt(cat.last)+'</span>'
    +'</div>';
  let h='<tr><td><div class="catname">'+cat.n+'</div>'+badges+'</td>';
  for(let i=0;i<months;i++) h+= i===0?'<td><input class="cell-edit" data-'+key+'="'+idx+'" value="'+Math.round(cat.v)+'"></td>':'<td>'+fmt(cat.v)+'</td>';
  return h+'</tr>';
}
function render(){
  const c=calc(); let h='<thead><tr><th>Concepto</th>';
  c.labels.forEach((l,i)=>h+='<th>'+l+(i===0?' · hoy':'')+'</th>'); h+='</tr></thead><tbody>';
  h+='<tr class="start"><td>Saldo inicial</td>';
  c.sa.forEach((v,i)=>h+= i===0?'<td><input class="cell-edit" id="edStart" value="'+Math.round(v)+'"></td>':'<td>'+fmt(v)+'</td>'); h+='</tr>';
  h+='<tr class="sect"><td>Ingresos</td>'+'<td></td>'.repeat(months)+'</tr>';
  h+='<tr><td>Ingreso mensual esperado</td>';
  for(let i=0;i<months;i++) h+= i===0?'<td><input class="cell-edit" id="edIncome" value="'+state.income+'"></td>':'<td>'+fmt(state.income)+'</td>'; h+='</tr>';
  h+='<tr><td>Orden puntual <span class="muted">(mes <input class="cell-edit" id="edOrderM" style="width:46px" value="'+state.order.month+'">)</span></td>';
  for(let i=0;i<months;i++){const on=(i+1===+state.order.month);h+= i===0?'<td><input class="cell-edit" id="edOrderA" value="'+state.order.amount+'"></td>':'<td>'+(on?fmt(+state.order.amount):'—')+'</td>';} h+='</tr>';
  h+='<tr class="tot"><td>Total ingresos</td>'; c.inc.forEach(v=>h+='<td class="'+(v>0?'pos':'')+'">'+fmt(v)+'</td>'); h+='</tr>';
  h+='<tr class="sect"><td>Gastos operativos</td>'+'<td></td>'.repeat(months)+'</tr>';
  state.cats.forEach((cat,idx)=>h+=rowCat(state.cats,'cat',cat,idx));
  h+='<tr class="sect"><td>Eventos (impuestos / distribuciones)</td>'+'<td></td>'.repeat(months)+'</tr>';
  state.eventos.forEach((cat,idx)=>h+=rowCat(state.eventos,'ev',cat,idx));
  h+='<tr class="tot"><td>Total gastos</td>'; c.exp.forEach(v=>h+='<td class="neg">'+fmt(v)+'</td>'); h+='</tr>';
  h+='<tr class="tot"><td>Flujo neto</td>'; c.net.forEach(v=>h+='<td class="'+(v<0?'neg':'pos')+'">'+fmt(v)+'</td>'); h+='</tr>';
  h+='<tr class="end"><td>Saldo final</td>'; c.ea.forEach(v=>h+='<td class="'+(v<=0?'zero':'pos')+'">'+fmt(v)+'</td>'); h+='</tr>';
  h+='</tbody>'; document.getElementById('grid').innerHTML=h;
  // binds
  document.getElementById('edStart').onchange=e=>{state.start=num(e.target.value);render();};
  document.getElementById('edIncome').onchange=e=>{state.income=num(e.target.value);render();};
  document.getElementById('edOrderA').onchange=e=>{state.order.amount=num(e.target.value);render();};
  document.getElementById('edOrderM').onchange=e=>{state.order.month=num(e.target.value);render();};
  document.querySelectorAll('[data-cat]').forEach(i=>i.onchange=e=>{state.cats[+e.target.dataset.cat].v=num(e.target.value);state.cats[+e.target.dataset.cat].mode='manual';render();});
  document.querySelectorAll('[data-ev]').forEach(i=>i.onchange=e=>{state.eventos[+e.target.dataset.ev].v=num(e.target.value);state.eventos[+e.target.dataset.ev].mode='manual';render();});
  document.querySelectorAll('.badge').forEach(b=>b.onclick=e=>{
    const k=e.target.dataset.set, i=+e.target.dataset.i, to=e.target.dataset.to;
    const arr= k==='cat'?state.cats:state.eventos; arr[i].v=arr[i][to]; arr[i].mode=to; render();
  });
}
document.querySelectorAll('#seg button').forEach(b=>b.onclick=()=>{document.querySelectorAll('#seg button').forEach(x=>x.classList.remove('active'));b.classList.add('active');months=+b.dataset.m;render();});
document.querySelectorAll('#base button').forEach(b=>b.onclick=()=>{
  document.querySelectorAll('#base button').forEach(x=>x.classList.remove('active'));b.classList.add('active');base=b.dataset.b;
  state.cats.forEach(c=>{c.v=c[base];c.mode=base;}); state.eventos.forEach(c=>{c.v=c[base];c.mode=base;}); render();
});
render();
</script>
</body></html>
