# KRATFEL Finanzas — app

Dashboard privado de reserva/runway (PHP + MySQL + Chart.js). Datos de QuickBooks Online por API (cron) y saldo de Cetera manual. Deploy GitHub → SiteGround. **Nada toca el Mac de Pablo.**

## Estructura

```
config.php            # carga env, toggle sandbox/producción
index.php             # dashboard (tras login)
saldo.php             # guardar saldo de Cetera (POST)
auth/                 # login, logout
qbo/                  # connect.php, callback.php, disconnect.php (OAuth2)
cron/sync_qbo.php     # sincronización por API (cada 2-3 días)
lib/                  # crypto, db, qbo, normalize, runway, import_xlsx, auth
db/schema.sql         # esquema MySQL v3
tools/create_user.php # crea/actualiza usuarios (CLI)
privacy.html · eula.html · disconnected.html   # páginas públicas (Intuit)
.github/workflows/deploy.yml                    # CI/CD a SiteGround
```

El **docroot del subdominio** apunta a esta carpeta. Así `privacy.html`/`eula.html`/`disconnected.html` quedan públicas en la raíz, e `index.php` exige login.

## Puesta en marcha (una vez)

1. **Subdominio** `finanzas.usuarioseri2y3.com` en SiteGround, docroot = esta carpeta.
2. **Base de datos** MySQL en SiteGround → importar `db/schema.sql`.
3. **`.env`** en el servidor (fuera del repo): copia `.env.example` a `.env` y rellena DB, `APP_ENCRYPTION_KEY` (`php -r "echo base64_encode(random_bytes(32));"`), y las llaves QBO.
4. **Usuarios** (Pablo y Rafa): `php tools/create_user.php pablo@... "Pablo"` (pide la contraseña por stdin).
5. **Backfill histórico** (opcional): `php lib/import_xlsx.php /ruta/Yearly_Transaction_Detail.xlsx`.
6. **Conectar QBO**: entra a la app → "Conectar con QuickBooks" → consent una vez.
7. **Cron** en SiteGround cada 3 días: `php /ruta/app/cron/sync_qbo.php`.

## Deploy

GitHub Actions despliega en cada push a `main` (`.github/workflows/deploy.yml`). Configura los 5 secrets SSH indicados en ese archivo. El `.env` real **no** se sube (excluido del rsync).

## Seguridad

- Tokens QBO cifrados en reposo (AES-256-GCM, `lib/crypto.php`); clave en env.
- Secretos solo en `.env` del servidor, nunca en el repo.
- Login con contraseña + (pendiente MVP-3) passkey WebAuthn; rate-limit; sesiones seguras; HTTPS; datos nunca en la URL.
- El cron rota y **guarda siempre el nuevo refresh token**; correr cada 2-3 días mantiene vivo el de 100 días.

## Pendiente (siguientes MVP)

- **Validar el parser de `cron/sync_qbo.php`** contra la respuesta real del reporte TransactionList la primera vez (los títulos de columna se registran en el log) y ajustar el mapeo si hace falta.
- Drill-down de transacciones en el P&L del dashboard (el mock ya lo muestra).
- Passkey WebAuthn (lbuchs/WebAuthn).
- Escenarios guardables en BD (`escenarios.json_overrides`).
