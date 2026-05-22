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
| `TRUSTED_PROXIES` | `127.0.0.1,REMOTE_ADDR` (optional if `config/packages/prod/framework.yaml` is deployed) |

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

## 6. Cannot log in on the deployed site?

Common causes:

| Symptom | Fix |
|---------|-----|
| “Invalid credentials” | Database has **no users** — run fixtures (step 7) or register a new account |
| “Email is not verified yet” | `MAILER_DSN=null` on Railway — emails never sent. Run `app:verify-legacy-user-emails` (step 7) or set real `MAILER_DSN` |
| Login refreshes, no error | HTTPS proxy — set `TRUSTED_PROXIES` / prod `framework.yaml` (included in repo) and redeploy |
| 500 after submit | Run migrations; check Deploy Logs |

**Demo accounts** (only after `doctrine:fixtures:load`):

- `admin@example.com` / `admin1234`
- `landlord@example.com` / `landlord3333`
- `tenant@example.com` / `tenant2222`

## 7. Login accounts (automatic)

On each container start, `scripts/railway-start.sh` runs:

1. `doctrine:migrations:migrate`
2. `app:bootstrap-users` — creates demo accounts **only if missing**:

| Role | Email | Password |
|------|--------|----------|
| Tenant | `tenant@example.com` | `tenant2222` |
| Landlord | `landlord@example.com` | `landlord3333` |
| Admin | `admin@example.com` | `admin1234` |

The login page shows these hints in **prod**.

**New registrations** on Railway (`MAILER_DSN=null`) are **auto-verified** so users can sign in without email.

Manual shell (optional):

```bash
php bin/console app:bootstrap-users
php bin/console app:verify-legacy-user-emails --yes
```

## 8. If build still fails

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
