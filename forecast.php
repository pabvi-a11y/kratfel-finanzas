<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/runway.php';
$user = require_login();

// Saldo ancla (reserva Cetera)
$saldoRow = rw_saldo_actual();
$saldo = $saldoRow ? (float)$saldoRow['saldo'] : 0.0;
$asof = $saldoRow ? date('d/m/Y', strtotime($saldoRow['fecha'])) : '—';

// Promedio mensual por categoría (operativo) de los últimos 6 meses completos
$c1 = (new DateTime('first day of this month'))->modify('-6 month')->format('Y-m-d');
$c2 = (new DateTime('first day of this month'))->format('Y-m-d');
$rows = db()->prepare("SELECT categoria, SUM(monto_canonico) total
  FROM transacciones WHERE grupo_pnl='operativo' AND fecha>=:a AND fecha<:b
  GROUP BY categoria ORDER BY total DESC");
$rows->execute([':a'=>$c1, ':b'=>$c2]);
$cats = [];
foreach ($rows as $r) {
    $avg = round(((float)$r['total'])/6, 0);
    if ($avg > 0) $cats[] = ['n'=>$r['categoria'], 'avg'=>$avg];
}
// eventos (default 0, editables)
$eventos = [
  ['n'=>'Impuestos','avg'=>0],
  ['n'=>'Distribuciones a socios','avg'=>0],
];

$data = ['saldo'=>$saldo, 'asof'=>$asof, 'cats'=>$cats, 'eventos'=>$eventos, 'mes0'=>(int)date('n'), 'anio0'=>(int)date('Y')];
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><title>Forecast — KRATFEL Finanzas</title>
<style>
:root{--bg:#0f1320;--panel:#171c2e;--panel2:#1e2540;--line:#2a3252;--txt:#e8ecf7;--mut:#9aa6c7;--acc:#5b8cff;--good:#37d39b;--warn:#ffb454;--bad:#ff6b6b;--yellow:#3a3520;--yellowbd:#7a6a2a}
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--txt)}
header{display:flex;align-items:center;gap:16px;padding:14px 22px;border-bottom:1px solid var(--line)}
.brand{font-weight:800;font-size:18px;display:inline-flex;align-items:center;gap:7px}.brand span{color:var(--mut);font-weight:400;font-size:14px}
nav{display:flex;gap:6px;margin-left:8px}
nav a{color:var(--mut);padding:8px 14px;border-radius:10px;text-decoration:none;font-size:14px;font-weight:600}
nav a.active{background:var(--panel2);color:var(--txt)}nav a:hover{color:var(--txt)}
.who{margin-left:auto;color:var(--mut);font-size:13px}.who a{color:var(--mut)}
.wrap{max-width:1200px;margin:0 auto;padding:22px}
.toolbar{display:flex;align-items:center;gap:14px;margin-bottom:16px;flex-wrap:wrap}
.toolbar h2{margin:0;font-size:18px}
.seg{display:inline-flex;border:1px solid var(--line);border-radius:10px;overflow:hidden}
.seg button{background:var(--panel);border:none;color:var(--mut);padding:7px 14px;cursor:pointer;font-weight:600;font-size:13px}
.seg button.active{background:var(--acc);color:#fff}
.muted{color:var(--mut);font-size:12.5px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:8px;overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13px;min-width:680px}
th,td{padding:9px 12px;text-align:right;border-bottom:1px solid var(--line);font-variant-numeric:tabular-nums;white-space:nowrap}
th:first-child,td:first-child{text-align:left;position:sticky;left:0;background:var(--panel)}
thead th{color:var(--mut);font-size:11px;text-transform:uppercase;letter-spacing:.3px}
.sect td{background:var(--panel2);color:var(--mut);font-size:11px;text-transform:uppercase;letter-spacing:.3px;font-weight:700}
.tot td{font-weight:700;border-top:1px solid var(--line)}
.start td,.end td{font-weight:800}
.end td{border-top:2px solid var(--line)}
.neg{color:var(--bad)}.pos{color:var(--good)}
.cell-edit{background:var(--yellow);border:1px solid var(--yellowbd);color:#ffe39a;border-radius:6px;padding:5px 7px;width:92px;text-align:right;font-size:13px;font-variant-numeric:tabular-nums}
.cell-edit:focus{outline:2px solid var(--warn)}
.addrow{color:var(--acc);cursor:pointer;font-weight:600;font-size:12.5px}
.zero{color:var(--bad);font-weight:700}
.legend{margin-top:10px;color:var(--mut);font-size:12px}
.legend b{color:#ffe39a}
</style></head>
<body>
<header>
 <div class="brand"><img src="/assets/logo_kratfel.png" alt="Kratfel" style="height:24px"> <span>· Finanzas</span></div>
 <nav>
   <a href="/">Dashboard</a>
   <a href="/pnl.php">P&amp;L</a>
   <a class="active" href="/forecast.php">Forecast</a>
 </nav>
 <div class="who"><?= htmlspecialchars($user['nombre'] ?? $user['email']) ?> · <a href="/auth/logout.php">Salir</a></div>
</header>
<div class="wrap">
 <div class="toolbar">
   <h2>Forecast de reserva</h2>
   <span class="muted">Reserva Cetera hoy: <b><?= '$'.number_format($saldo,0,',','.') ?></b> · al <?= $asof ?></span>
   <div class="seg" id="seg" style="margin-left:auto">
     <button data-m="3" class="active">3 meses</button>
     <button data-m="6">6 meses</button>
     <button data-m="12">12 meses</button>
   </div>
 </div>
 <div class="card"><table id="grid"></table></div>
 <div class="legend">Las celdas <b>amarillas</b> son editables: cambia el saldo inicial o cualquier categoría para jugar con escenarios. El saldo final y la fecha de cero se recalculan al instante. Promedios por defecto = media de los últimos 6 meses reales.</div>
</div>
<script>
const D = <?= json_encode($data, JSON_UNESCAPED_UNICODE) ?>;
const fmt = n => (n<0?'-':'')+'$'+Math.abs(Math.round(n)).toLocaleString('es-ES');
const MES=['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
let months = 3;
// estado editable
let state = {
  start: D.saldo,
  income: 0,                  // ingreso mensual esperado
  order: {amount:0, month:1}, // orden puntual
  cats: D.cats.map(c=>({n:c.n, v:c.avg})),
  eventos: D.eventos.map(c=>({n:c.n, v:c.avg})),
};
function colLabels(){
  const out=[]; let m=D.mes0-1, y=D.anio0;
  for(let i=0;i<months;i++){ out.push(MES[m]+' '+String(y).slice(2)); m++; if(m>11){m=0;y++;} }
  return out;
}
function calc(){
  const labels=colLabels();
  const inc=[], exp=[], net=[], startArr=[], endArr=[];
  let bal=state.start; let zeroIdx=-1;
  for(let i=0;i<months;i++){
    startArr.push(bal);
    let income=state.income + (i+1===+state.order.month ? +state.order.amount : 0);
    let expense=state.cats.reduce((s,c)=>s+(+c.v||0),0) + state.eventos.reduce((s,c)=>s+(+c.v||0),0);
    let n=income-expense;
    bal=bal+n;
    inc.push(income); exp.push(expense); net.push(n); endArr.push(bal);
    if(bal<=0 && zeroIdx<0) zeroIdx=i;
  }
  return {labels,inc,exp,net,startArr,endArr,zeroIdx};
}
function num(v){return (+v||0);}
function render(){
  const c=calc();
  let h='<thead><tr><th>Concepto</th>';
  c.labels.forEach((l,i)=>h+='<th>'+l+(i===0?' · hoy':'')+'</th>'); h+='</tr></thead><tbody>';
  // Saldo inicial (editable primer mes)
  h+='<tr class="start"><td>Saldo inicial</td>';
  c.startArr.forEach((v,i)=>{
    h+= i===0 ? '<td><input class="cell-edit" id="edStart" value="'+Math.round(v)+'"></td>'
              : '<td>'+fmt(v)+'</td>';
  });
  h+='</tr>';
  // INGRESOS
  h+='<tr class="sect"><td>Ingresos</td>'+'<td></td>'.repeat(months)+'</tr>';
  h+='<tr><td>Ingreso mensual esperado</td>';
  for(let i=0;i<months;i++) h+= i===0?'<td><input class="cell-edit" id="edIncome" value="'+state.income+'"></td>':'<td>'+fmt(state.income)+'</td>';
  h+='</tr>';
  h+='<tr><td>Orden puntual <span class="muted">(mes <input class="cell-edit" id="edOrderM" style="width:46px" value="'+state.order.month+'">)</span></td>';
  for(let i=0;i<months;i++){ const on=(i+1===+state.order.month); h+= i===0?'<td><input class="cell-edit" id="edOrderA" value="'+state.order.amount+'"></td>':'<td>'+(on?fmt(+state.order.amount):'—')+'</td>'; }
  h+='</tr>';
  h+='<tr class="tot"><td>Total ingresos</td>';
  c.inc.forEach(v=>h+='<td class="'+(v>0?'pos':'')+'">'+fmt(v)+'</td>'); h+='</tr>';
  // GASTOS
  h+='<tr class="sect"><td>Gastos operativos</td>'+'<td></td>'.repeat(months)+'</tr>';
  state.cats.forEach((cat,idx)=>{
    h+='<tr><td>'+cat.n+'</td>';
    for(let i=0;i<months;i++) h+= i===0?'<td><input class="cell-edit" data-cat="'+idx+'" value="'+Math.round(cat.v)+'"></td>':'<td>'+fmt(cat.v)+'</td>';
    h+='</tr>';
  });
  h+='<tr class="sect"><td>Eventos (impuestos / distribuciones)</td>'+'<td></td>'.repeat(months)+'</tr>';
  state.eventos.forEach((cat,idx)=>{
    h+='<tr><td>'+cat.n+'</td>';
    for(let i=0;i<months;i++) h+= i===0?'<td><input class="cell-edit" data-ev="'+idx+'" value="'+Math.round(cat.v)+'"></td>':'<td>'+fmt(cat.v)+'</td>';
    h+='</tr>';
  });
  h+='<tr class="tot"><td>Total gastos</td>';
  c.exp.forEach(v=>h+='<td class="neg">'+fmt(v)+'</td>'); h+='</tr>';
  // NET
  h+='<tr class="tot"><td>Flujo neto</td>';
  c.net.forEach(v=>h+='<td class="'+(v<0?'neg':'pos')+'">'+fmt(v)+'</td>'); h+='</tr>';
  // SALDO FINAL
  h+='<tr class="end"><td>Saldo final</td>';
  c.endArr.forEach((v,i)=>h+='<td class="'+(v<=0?'zero':'pos')+'">'+fmt(v)+'</td>'); h+='</tr>';
  h+='</tbody>';
  document.getElementById('grid').innerHTML=h;

  // mensaje fecha cero
  // re-bind inputs
  document.getElementById('edStart').addEventListener('change',e=>{state.start=num(e.target.value);render();});
  document.getElementById('edIncome').addEventListener('change',e=>{state.income=num(e.target.value);render();});
  document.getElementById('edOrderA').addEventListener('change',e=>{state.order.amount=num(e.target.value);render();});
  document.getElementById('edOrderM').addEventListener('change',e=>{state.order.month=num(e.target.value);render();});
  document.querySelectorAll('[data-cat]').forEach(inp=>inp.addEventListener('change',e=>{state.cats[+e.target.dataset.cat].v=num(e.target.value);render();}));
  document.querySelectorAll('[data-ev]').forEach(inp=>inp.addEventListener('change',e=>{state.eventos[+e.target.dataset.ev].v=num(e.target.value);render();}));
}
document.querySelectorAll('#seg button').forEach(b=>b.addEventListener('click',()=>{
  document.querySelectorAll('#seg button').forEach(x=>x.classList.remove('active'));
  b.classList.add('active'); months=+b.dataset.m; render();
}));
render();
</script>
</body></html>
