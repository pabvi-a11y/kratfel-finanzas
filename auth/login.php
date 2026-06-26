<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/auth.php';
auth_start();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $email = trim($_POST['email'] ?? '');
    $pass = (string)($_POST['password'] ?? '');
    if (!login_rate_limit($email)) {
        $error = 'Demasiados intentos. Espera unos minutos.';
    } else {
        $st = db()->prepare("SELECT id, pass_hash FROM usuarios WHERE email=:e");
        $st->execute([':e' => $email]);
        $u = $st->fetch();
        if ($u && password_verify($pass, $u['pass_hash'])) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            header('Location: ' . APP_BASE_URL . '/');
            exit;
        }
        $error = 'Correo o contraseña incorrectos.';
    }
}
$csrf = csrf_token();
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><title>Entrar — KRATFEL Finanzas</title>
<style>body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#0f1320;color:#e8ecf7;display:flex;min-height:100vh;align-items:center;justify-content:center}
.card{background:#171c2e;border:1px solid #2a3252;border-radius:14px;padding:28px;width:320px}
h1{font-size:18px;margin:0 0 18px}label{font-size:12.5px;color:#9aa6c7}
input{width:100%;box-sizing:border-box;margin:6px 0 14px;background:#0e1322;border:1px solid #2a3252;color:#e8ecf7;border-radius:10px;padding:10px 12px;font-size:14px}
button{width:100%;background:#5b8cff;color:#fff;border:none;border-radius:10px;padding:11px;font-weight:700;font-size:14px;cursor:pointer}
.err{color:#ff6b6b;font-size:13px;margin-bottom:10px}</style></head>
<body><form class="card" method="post">
<h1><img src="/assets/logo_kratfel.png" alt="Kratfel" style="height:26px;vertical-align:middle"> · Finanzas</h1>
<?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
<label>Correo</label><input type="email" name="email" required autofocus>
<label>Contraseña</label><input type="password" name="password" required>
<button type="submit">Entrar</button>
</form></body></html>
