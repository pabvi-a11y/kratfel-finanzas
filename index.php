<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/runway.php';

$user = require_login();
$csrf = csrf_token();

// Datos
$vel = rw_velocidad(6);
$saldoRow = rw_saldo_actual();
$saldo = $saldoRow ? (float)$saldoRow['saldo'] : 0.0;
$asof = $saldoRow ? date('d/m/Y', strtotime($saldoRow['fecha'])) : '—';
$proj = rw_proyeccion($saldo, $vel['velocidad']);

// P&L últimos 6 meses
$desde = (new DateTime('first day of this month'))->modify('-6 month')->format('Y-m-d');
$hasta = (new DateTime('first day of this month'))->format('Y-m-d');
$pnlRaw = rw_pnl($desde, $hasta);
$pnl = [];
foreach ($pnlRaw as $r) {
    $g = $r['grupo_pnl'];
    $pnl[$g]['total'] = ($pnl[$g]['total'] ?? 0) + (float)$r['total'];
    $pnl[$g]['cats'][] = ['n' => $r['categoria'], 'm' => (float)$r['total']];
}
$GRP = ['operativo' => 'Gasto operativo', 'distribucion' => 'Distribuciones a socios', 'impuestos' => 'Impuestos'];

// Serie de consumo de reserva
$consumo = rw_consumo_mensual();

// Estado QBO
$conn = db()->query("SELECT estado, ultima_sync FROM qbo_oauth ORDER BY id DESC LIMIT 1")->fetch();

$data = [
    'saldo' => $saldo, 'asof' => $asof, 'vel' => $vel['velocidad'],
    'serie' => $proj['serie'],
    'consumo_lbl' => array_keys($consumo),
    'consumo_val' => array_map(fn($v) => round($v, 2), array_values($consumo)),
];
$hayDatos = !empty($consumo) || $saldo > 0;
function money($n){ return '$' . number_format((float)$n, 0, ',', '.'); }
$ml = $proj['meses_restantes'];
$mlTxt = is_finite($ml) ? number_format($ml, 1, ',', '.') : '∞';
$cls = !is_finite($ml) ? 'good' : ($ml < 3 ? 'bad' : ($ml < 6 ? 'warn' : 'good'));
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
table{width:100%;border-collapse:collapse;font-size:13px}th,td{text-align:left;padding:8px;border-bottom:1px solid var(--line)}
td.num,th.num{text-align:right}th{color:var(--mut);font-size:11px;text-transform:uppercase}
.banner{background:#2a2140;border:1px solid #4a3a6a;border-radius:12px;padding:12px 14px;font-size:13px;margin-bottom:16px}
form.inline{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
input{background:#0e1322;border:1px solid var(--line);color:var(--txt);border-radius:10px;padding:9px 11px;font-size:14px}
.btn{background:var(--acc);color:#fff;border:none;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer}
</style></head>
<body>
<header><div class="brand"><img src="/assets/logo_kratfel.png" alt="Kratfel" style="height:24px;vertical-align:middle"> <span>· Finanzas</span></div>
<nav style="display:flex;gap:6px;margin-left:8px"><a href="/" style="color:#e8ecf7;background:#1e2540;padding:8px 14px;border-radius:10px;text-decoration:none;font-size:14px;font-weight:600">Dashboard</a><a href="/pnl.php" style="color:#9aa6c7;padding:8px 14px;text-decoration:none;font-size:14px;font-weight:600">P&amp;L</a><a href="/forecast.php" style="color:#9aa6c7;padding:8px 14px;text-decoration:none;font-size:14px;font-weight:600">Forecast</a></nav>
<div class="who"><?= htmlspecialchars($user['nombre'] ?? $user['email']) ?> · <a href="<?= APP_BASE_URL ?>/auth/logout.php">Salir</a></div></header>
<div class="wrap">

<?php if (!$hayDatos): ?>
<div class="banner"><b>Sin datos todavía.</b> Conecta QuickBooks (o sube el .xlsx de respaldo) y captura el saldo de Cetera para ver el runway.</div>
<?php endif; ?>

<div class="fresh">
  <div class="chip"><span class="dot <?= ($conn && $conn['estado']==='conectado') ? '' : 'b' ?>"></span>QBO: <b><?= $conn ? htmlspecialchars($conn['estado']) : 'no conectado' ?></b><?php if($conn && $conn['ultima_sync']): ?> · última sync <?= date('d/m/Y', strtotime($conn['ultima_sync'])) ?><?php endif; ?></div>
  <div class="chip"><span class="dot w"></span>Saldo Cetera al: <b><?= $asof ?></b></div>
  <?php if(!$conn || $conn['estado']!=='conectado'): ?><div class="chip"><a href="<?= APP_BASE_URL ?>/qbo/connect.php" style="color:var(--acc);font-weight:700">Conectar con QuickBooks →</a></div><?php endif; ?>
</div>

<div class="kpis">
  <div class="kpi"><div class="lbl">Reserva Cetera hoy</div><div class="val"><?= money($saldo) ?></div></div>
  <div class="kpi"><div class="lbl">Consumo mensual</div><div class="val" id="kVel"><?= money($vel['velocidad']) ?></div></div>
  <div class="kpi <?= $cls ?>"><div class="lbl">Meses restantes</div><div class="val" id="kMeses"><?= $mlTxt ?></div></div>
  <div class="kpi <?= $cls ?>"><div class="lbl">Fecha estimada de cero</div><div class="val" id="kCero"><?= htmlspecialchars($proj['fecha_cero'] ?? '—') ?></div></div>
</div>

<div class="card">
  <h3>Reserva y proyección de oxígeno</h3>
  <p class="cap">Proyección desde el saldo de hoy al ritmo de consumo. Cambia el escenario para mover la fecha de cero.</p>
  <div class="scen" id="scen"></div>
  <div style="height:300px"><canvas id="chart"></canvas></div>
</div>

<div class="card">
  <h3>P&amp;L por grupo · últimos 6 meses</h3>
  <table><thead><tr><th>Grupo</th><th class="num">Importe</th></tr></thead><tbody>
  <?php foreach ($GRP as $k => $label): if (!isset($pnl[$k])) continue; ?>
    <tr><td><?= $label ?></td><td class="num"><?= money($pnl[$k]['total']) ?></td></tr>
  <?php endforeach; if (!$pnl): ?><tr><td colspan="2" style="color:var(--mut)">Sin transacciones aún.</td></tr><?php endif; ?>
  </tbody></table>
</div>

</div>
<script>
const D = <?= json_encode($data, JSON_UNESCAPED_UNICODE) ?>;
const fmt = n => '$' + Math.round(n).toLocaleString('es-ES');
const scen = [{id:'base',name:'Base',mult:1,inc:0,im:0},{id:'c20',name:'Recorte −20%',mult:.8,inc:0,im:0},{id:'c35',name:'Austeridad −35%',mult:.65,inc:0,im:0},{id:'ord',name:'Orden $120k (mes 3)',mult:1,inc:120000,im:3}];
let cur='base';
const scEl=document.getElementById('scen');
scen.forEach(s=>{const b=document.createElement('button');b.textContent=s.name;if(s.id==='base')b.classList.add('active');b.onclick=()=>{cur=s.id;[...scEl.children].forEach(x=>x.classList.remove('active'));b.classList.add('active');render();};scEl.appendChild(b);});
function project(s){const v=D.vel*s.mult;let bal=D.serie[0].saldo;const lbl=['hoy'],val=[bal];let cero=null,b2=bal,ml=0;
 for(let i=1;i<=12;i++){if(i===s.im&&s.inc)bal+=s.inc;bal-=v;const d=new Date(new Date().getFullYear(),(new Date().getMonth())+i,1);lbl.push(String(d.getFullYear()%100).padStart(2,'0')+'/'+String(d.getMonth()+1).padStart(2,'0'));val.push(Math.round(bal));if(bal<=0&&cero===null)cero=lbl[i];}
 for(let i=1;i<=600;i++){if(i===s.im&&s.inc)b2+=s.inc;if(v<=0){ml=Infinity;break;}if(b2-v<=0){ml=(i-1)+(b2/v);break;}b2-=v;ml=i;}
 return {v,lbl,val,cero,ml};}
let chart;
function render(){const s=scen.find(x=>x.id===cur),p=project(s);
 document.getElementById('kVel').textContent=fmt(p.v);
 document.getElementById('kMeses').textContent=isFinite(p.ml)?p.ml.toFixed(1):'∞';
 document.getElementById('kCero').textContent=p.cero||'—';
 const ds=[{label:'Proyección',data:p.val,borderColor:'#7aa2ff',borderDash:[6,5],pointRadius:0,tension:.15}];
 if(cur!=='base'){const bp=project(scen[0]);ds.push({label:'Base',data:bp.val,borderColor:'#41507a',borderDash:[2,4],pointRadius:0});}
 if(chart)chart.destroy();
 chart=new Chart(document.getElementById('chart'),{type:'line',data:{labels:p.lbl,datasets:ds},options:{maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>c.dataset.label+': '+fmt(c.parsed.y)}}},scales:{y:{ticks:{color:'#9aa6c7',callback:v=>fmt(v)},grid:{color:'#222a45'}},x:{ticks:{color:'#9aa6c7'},grid:{display:false}}}}});
}
render();
</script>
</body></html>
