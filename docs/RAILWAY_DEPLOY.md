# Deploy CasaClick on Railway

## 1. Create project

1. [railway.app](https://railway.app) → **New Project** → **Deploy from GitHub** → `binrazalimohammad/casaclick` → branch **`main`**.
2. **+ New** → **Database** → **MySQL**.
3. On the **web service** → **Variables** → add reference: **`DATABASE_URL`** from the MySQL service.

## 2. Required variables (web service)

| Variable | Example |
|----------|---------|
| `APP_ENV` | `prod` |
| `APP_DEBUG` | `0` |
| `APP_SECRET` | random 32+ chars |
| `DATABASE_URL` | reference from MySQL |
| `DEFAULT_URI` | `https://YOUR-APP.up.railway.app` |
| `JWT_PASSPHRASE` | same as local or new secret |
| `GOOGLE_OAUTH_CLIENT_ID` | Web client ID |
| `GOOGLE_OAUTH_CLIENT_SECRET` | from Google Console |
| `MAILER_DSN` | real SMTP (not `null://null`) if you need email verify |
| `CORS_ALLOW_ORIGIN` | include your Railway hostname |

Optional: `PAYMONGO_SECRET_KEY`, `PAYMONGO_DEV_MOCK=1` for demo checkout.

## 3. Public domain

**Settings** → **Networking** → **Generate Domain** → set `DEFAULT_URI` to that URL.

Add Google OAuth redirect:

`https://YOUR-APP.up.railway.app/connect/google/check`

## 4. Build vs start

- **Build** (`Dockerfile`): Composer, `npm run build`, JWT keys. No database migrations.
- **Start** (`scripts/railway-start.sh`): migrations, then PHP built-in server on `$PORT`.

`railway.toml` uses `builder = "DOCKERFILE"` (do not use the old `importmap:install` nginx Dockerfile).

## 5. Verify

- `https://YOUR-APP.up.railway.app/railway-health.php` → `{"ok":true}` (Railway health probe)
- `https://YOUR-APP.up.railway.app/api/mobile/health` → `"success": true` (Symfony API)
- `/home` loads with CSS (Webpack build runs in CI)

**Healthcheck failed but build succeeded?** Link **MySQL** and set `DATABASE_URL`. The start script now runs migrations in the background so the server still listens immediately.

## 6. If build still fails

Open **Deployments** → failed deploy → **View logs**. Common fixes:

| Log hint | Fix |
|----------|-----|
| `could not find driver` | MySQL PDO — `nixpacks.toml` includes `pdo_mysql` |
| `Connection refused` during **build** | Do not run migrations in build (already fixed in `railway.toml`) |
| `npm: not found` | Redeploy after `nixpacks.toml` is on `main` |
| JWT / passphrase | Set `JWT_PASSPHRASE` in Railway variables |

Run migrations manually:

```bash
railway run php bin/console doctrine:migrations:migrate --no-interaction
```
