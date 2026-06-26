<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/runway.php';
$user = require_login();
$csrf = csrf_token();
$conn = db()->query("SELECT realm_id, estado, ultima_sync FROM qbo_oauth ORDER BY id DESC LIMIT 1")->fetch();
$sr = rw_saldo_actual();
$ok = ($conn && $conn['estado']==='conectado');
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/png" href="/assets/favicon.png"><title>Ajustes — KRATFEL Finanzas</title>
<style>
:root{--bg:#0f1320;--panel:#171c2e;--panel2:#1e2540;--line:#2a3252;--txt:#e8ecf7;--mut:#9aa6c7;--acc:#5b8cff;--good:#37d39b;--bad:#ff6b6b;--warn:#ffb454}
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--txt)}
header{display:flex;align-items:center;gap:16px;padding:14px 22px;border-bottom:1px solid var(--line)}
.brand{display:flex;flex-direction:column;gap:3px;text-decoration:none}.brand img{height:20px;display:block}.brand .tag{font-size:9.5px;letter-spacing:.22em;text-transform:uppercase;color:var(--mut);padding-left:2px}.hdiv{width:1px;height:26px;background:var(--line);flex:none}
nav{display:flex;gap:6px;margin-left:8px}nav a{color:var(--mut);padding:8px 14px;border-radius:10px;text-decoration:none;font-size:14px;font-weight:600}nav a:hover{color:var(--txt)}
.who{margin-left:auto;color:var(--mut);font-size:13px}.who a{color:var(--mut)}.who a.act{color:var(--txt)}
.wrap{max-width:720px;margin:0 auto;padding:22px}
h2{font-size:18px;margin:6px 0 14px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:18px 20px;margin-bottom:16px}
.card h3{margin:0 0 12px;font-size:15px;display:flex;align-items:center;gap:9px}
.dot{width:9px;height:9px;border-radius:50%;background:var(--good)}.dot.b{background:var(--bad)}
.kv{display:grid;grid-template-columns:160px 1fr;gap:8px 16px;font-size:13.5px;margin:10px 0 14px}
.kv div:nth-child(odd){color:var(--mut)}
.btn{background:var(--acc);color:#fff;border:none;padding:10px 16px;border-radius:10px;font-weight:700;cursor:pointer;font-size:14px;text-decoration:none;display:inline-block}
.btn.ghost{background:var(--panel2);color:var(--txt);border:1px solid var(--line)}
.cap{color:var(--mut);font-size:12.5px;margin-top:8px}
.card{transition:border-color .15s ease}.card:hover{border-color:#33406b}.ftr{max-width:720px;margin:0 auto;padding:6px 22px 30px;color:var(--mut);font-size:11px;opacity:.6}</style></head>
<body>
<header>
 <a class="brand" href="/"><img src="/assets/logo_kratfel.png" alt="Kratfel"><span class="tag">Finanzas</span></a><span class="hdiv"></span>
 <nav><a href="/">Dashboard</a><a href="/pnl.php">Reportes</a><a href="/forecast.php">Forecast</a></nav>
 <div class="who"><?= htmlspecialchars($user['nombre']??$user['email']) ?> · <a class="act" href="/settings.php">Ajustes</a> · <a href="/auth/logout.php">Salir</a></div>
</header>
<div class="wrap">
 <h2>Ajustes y conexiones</h2>

 <div class="card">
  <h3><span class="dot <?= $ok?'':'b' ?>"></span>QuickBooks Online</h3>
  <div class="kv">
   <div>Estado</div><div><b style="color:<?= $ok?'var(--good)':'var(--bad)' ?>"><?= $ok?'Conectado':'No conectado' ?></b></div>
   <?php if($conn && $conn['realm_id']): ?><div>Company (realm)</div><div><?= htmlspecialchars($conn['realm_id']) ?></div><?php endif; ?>
   <div>Última sincronización</div><div><?= ($conn && $conn['ultima_sync'])? date('d/m/Y H:i', strtotime($conn['ultima_sync'])) : '—' ?></div>
   <div>Sincronización automática</div><div>Diaria (cron del servidor)</div>
  </div>
  <?php if($ok): ?>
   <a class="btn ghost" href="/qbo/connect.php">Re-autorizar</a>
   <form method="post" action="/qbo/disconnect.php" style="display:inline">
     <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
     <button class="btn ghost" type="submit" style="color:var(--bad)">Desconectar</button>
   </form>
  <?php else: ?>
   <a class="btn" href="/qbo/connect.php">Conectar con QuickBooks</a>
   <p class="cap">Mientras no esté conectado, los datos se cargan desde los reportes que subes manualmente.</p>
  <?php endif; ?>
 </div>

 <div class="card">
  <h3>Reserva (Cetera)</h3>
  <div class="kv">
   <div>Saldo actual</div><div><b><?= $sr? '$'.number_format((float)$sr['saldo'],2,'.',',') : '—' ?></b></div>
   <div>Capturado al</div><div><?= $sr? date('d/m/Y', strtotime($sr['fecha'])) : '—' ?></div>
  </div>
  <p class="cap">Cetera/AdviceWorks no tiene API; el saldo lo actualiza el administrador con el valor real de la cuenta.</p>
 </div>
</div>
<footer class="ftr">KRATFEL Finanzas · Datos de QuickBooks (gastos) y Cetera (reserva). Las proyecciones son estimaciones y no constituyen asesoría financiera.</footer>
</body></html>
