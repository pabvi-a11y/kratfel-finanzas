<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/runway.php';
$user = require_login();

/* ---------- P&L: 13 cols (12 meses + actual), tabla PLANA ordenable ---------- */
$cols=[]; $d=new DateTime('first day of this month'); $d->modify('-12 month');
for($i=0;$i<13;$i++){ $cols[]=$d->format('Y-m'); $d->modify('+1 month'); }
$startP=$cols[0].'-01';
$q=db()->prepare("SELECT categoria, grupo_pnl, DATE_FORMAT(fecha,'%Y-%m') ym, SUM(monto_canonico) t
  FROM transacciones WHERE grupo_pnl IN ('operativo','distribucion','impuestos') AND fecha>=:s
  GROUP BY categoria, grupo_pnl, ym");
$q->execute([':s'=>$startP]);
$GRP=['operativo'=>'Gasto operativo','distribucion'=>'Distribuciones a socios','impuestos'=>'Impuestos'];
$rows=[]; $colTot=array_fill_keys($cols,0.0);
foreach($q as $r){
  $key=$r['categoria'];
  if(!isset($rows[$key])) $rows[$key]=['cat'=>$r['categoria'],'grupo'=>$GRP[$r['grupo_pnl']]??$r['grupo_pnl'],'vals'=>array_fill_keys($cols,0.0),'total'=>0.0];
  $v=(float)$r['t']; $ym=$r['ym'];
  if(isset($rows[$key]['vals'][$ym])){ $rows[$key]['vals'][$ym]+=$v; $rows[$key]['total']+=$v; $colTot[$ym]+=$v; }
}
usort($rows, fn($a,$b)=>$b['total']<=>$a['total']);
$grand=array_sum($colTot);
$MES=['','ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
function lbl($ym){global $MES;[$y,$m]=explode('-',$ym);return $MES[(int)$m].' '.substr($y,2);}
function f($n){$n=round((float)$n);return ($n<0?'-':'').'$'.number_format(abs($n),0,',','.');}
$curM=(new DateTime('first day of this month'))->format('Y-m');

/* ---------- Balance ---------- */
$sr=rw_saldo_actual(); $cetera=$sr?(float)$sr['saldo']:0.0; $ceteraFecha=$sr?date('d/m/Y',strtotime($sr['fecha'])):'—';
$qboCetera=547554.65;
$bs=db()->query("SELECT seccion,grupo,cuenta,monto FROM bs_lineas ORDER BY seccion,orden")->fetchAll();
$act=[];$cards=[];
foreach($bs as $r){ if($r['seccion']==='activo')$act[$r['grupo']][]=$r; elseif($r['seccion']==='pasivo')$cards[]=$r; }
// agrupar tarjetas por familia
$ccG=['NSCC 1433'=>[],'NSCC 7403'=>[],'Otras'=>[]];
foreach($cards as $r){ $c=$r['cuenta'];
  if(strpos($c,'NSCC 1433')===0) $ccG['NSCC 1433'][]=$r;
  elseif(strpos($c,'NSCC 7403')===0) $ccG['NSCC 7403'][]=$r;
  else $ccG['Otras'][]=$r; }
$activoTotal=$cetera; foreach($bs as $r){ if($r['seccion']==='activo')$activoTotal+=(float)$r['monto']; }
$pasivoTotal=0; foreach($cards as $r)$pasivoTotal+=(float)$r['monto'];
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
.pnl{width:100%;border-collapse:collapse;font-size:12.5px;min-width:1000px}
.pnl th,.pnl td{padding:7px 10px;text-align:right;border-bottom:1px solid var(--line);font-variant-numeric:tabular-nums;white-space:nowrap}
.pnl th:first-child,.pnl td:first-child{text-align:left;position:sticky;left:0;background:var(--panel);min-width:190px}
.pnl thead th{color:var(--mut);font-size:11px;text-transform:uppercase;cursor:pointer;user-select:none}
.pnl thead th:hover{color:var(--txt)}.pnl thead th.cur{color:var(--acc)}.pnl thead th .ar{opacity:.5;font-size:9px}
.pnl td.v0{color:#444c6b}.pnl td.grp{color:var(--mut);text-align:left}
.pnl tfoot .tot td{font-weight:800;border-top:2px solid var(--line);background:var(--panel2)}
.bsgrid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.bsbox{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:16px 20px}
.bsbox table{width:100%;border-collapse:collapse;font-size:13.5px}
.bsbox td{padding:6px 4px;border-bottom:1px solid var(--line);font-variant-numeric:tabular-nums}
.bsbox td.num{text-align:right;white-space:nowrap}
.bsbox .sec td{color:var(--mut);font-size:11px;text-transform:uppercase;letter-spacing:.4px;font-weight:700;border-bottom:none;padding-top:4px}
.bsbox .grp td{color:var(--txt);font-size:12.5px;font-weight:700;border-bottom:none;padding-top:10px}
.bsbox .it td:first-child{padding-left:16px}
.bsbox .sub td{font-weight:700;border-top:1px solid var(--line);color:var(--mut)}
.bsbox .tot td{font-weight:800;border-top:2px solid var(--line);border-bottom:none}
.bsbox .note{color:var(--warn);font-size:10.5px}.bsbox .neg{color:var(--bad)}
.big{display:flex;justify-content:space-between;align-items:baseline;background:var(--panel2);border:1px solid var(--line);border-radius:12px;padding:14px 18px;margin-top:10px}
.big b{font-size:20px;font-weight:800;color:var(--good)}
.cap{color:var(--mut);font-size:12px;margin:4px 0 0}
</style></head>
<body>
<header>
 <div class="brand"><img src="/assets/logo_kratfel.png" alt="Kratfel" style="height:24px"> <span>· Finanzas</span></div>
 <nav><a href="/">Dashboard</a><a class="active" href="/pnl.php">Reportes</a><a href="/forecast.php">Forecast</a><a href="/flujo.php">Flujo</a></nav>
 <div class="who"><?= htmlspecialchars($user['nombre']??$user['email']) ?> · <a href="/auth/logout.php">Salir</a></div>
</header>
<div class="wrap">

 <h2>P&amp;L mensual · últimos 12 meses + actual</h2>
 <div class="card"><table class="pnl sortable" id="tpnl">
  <thead><tr>
   <th data-type="text">Categoría <span class="ar">↕</span></th>
   <th data-type="text">Grupo <span class="ar">↕</span></th>
   <?php foreach($cols as $ym): ?><th data-type="num" class="<?= $ym===$curM?'cur':'' ?>"><?= lbl($ym) ?><?= $ym===$curM?'·hoy':'' ?> <span class="ar">↕</span></th><?php endforeach; ?>
   <th data-type="num">Total <span class="ar">↕</span></th>
  </tr></thead>
  <tbody>
  <?php foreach($rows as $r): ?>
   <tr>
    <td><?= htmlspecialchars($r['cat']) ?></td>
    <td class="grp" data-v="<?= htmlspecialchars($r['grupo']) ?>"><?= htmlspecialchars($r['grupo']) ?></td>
    <?php foreach($cols as $ym){ $v=$r['vals'][$ym]; echo '<td data-v="'.$v.'" class="'.($v==0?'v0':'').'">'.($v==0?'·':f($v)).'</td>'; } ?>
    <td data-v="<?= $r['total'] ?>"><strong><?= f($r['total']) ?></strong></td>
   </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot><tr class="tot noSort">
    <td>TOTAL GASTOS</td><td></td>
    <?php foreach($cols as $ym)echo '<td>'.f($colTot[$ym]).'</td>'; ?>
    <td><?= f($grand) ?></td>
  </tr></tfoot>
 </table></div>
 <p class="cap">Clic en cualquier título para ordenar. Excluye traspasos internos y retiros de reserva. Gasto en positivo.</p>

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
    <tr class="sec"><td colspan="2">Pasivos · tarjetas de crédito</td></tr>
    <?php foreach($ccG as $gname=>$items): if(!$items) continue; $sub=0; ?>
      <tr class="grp"><td colspan="2"><?= $gname ?></td></tr>
      <?php foreach($items as $r): $sub+=(float)$r['monto']; ?>
        <tr class="it"><td><?= htmlspecialchars($r['cuenta']) ?></td><td class="num <?= $r['monto']<0?'neg':'' ?>"><?= m2($r['monto']) ?></td></tr>
      <?php endforeach; ?>
      <tr class="sub"><td>Subtotal <?= $gname ?></td><td class="num <?= $sub<0?'neg':'' ?>"><?= m2($sub) ?></td></tr>
    <?php endforeach; ?>
    <tr class="tot"><td>Total pasivos</td><td class="num"><?= m2($pasivoTotal) ?></td></tr>
   </table>
   <div class="big"><span>Patrimonio neto <small style="color:var(--mut)">(activos − pasivos)</small></span><b><?= m2($patrimonio) ?></b></div>
  </div>
 </div>
</div>
<script>
function makeSortable(table){
  const thead=table.tHead; if(!thead) return;
  const ths=thead.rows[0].cells;
  [...ths].forEach((th,idx)=>{
    th.addEventListener('click',()=>{
      const tb=table.tBodies[0];
      const rows=[...tb.rows];
      const asc = th.getAttribute('data-dir')!=='asc';
      [...ths].forEach(x=>x.removeAttribute('data-dir')); th.setAttribute('data-dir',asc?'asc':'desc');
      const numeric = th.dataset.type==='num';
      rows.sort((a,b)=>{
        const ca=a.cells[idx], cb=b.cells[idx];
        let av=ca?(ca.getAttribute('data-v')??ca.textContent):'', bv=cb?(cb.getAttribute('data-v')??cb.textContent):'';
        let c = numeric ? (parseFloat(av)||0)-(parseFloat(bv)||0) : String(av).localeCompare(String(bv),'es');
        return asc?c:-c;
      });
      rows.forEach(r=>tb.appendChild(r));
    });
  });
}
document.querySelectorAll('table.sortable').forEach(makeSortable);
</script>
</body></html>
