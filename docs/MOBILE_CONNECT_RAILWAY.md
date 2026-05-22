# Connect the mobile app to Railway (not 127.0.0.1)

If login shows:

> **Cannot reach CasaClick API at `http://127.0.0.1:8000`**

the phone is trying to talk to your **PC**, not Railway. `127.0.0.1` on a phone means the phone itself — not your laptop and not production.

---

## Production (deployed website + same DB)

In your **mobile app project** (separate repo / folder), set the API base URL to:

```text
https://web-production-6bdab.up.railway.app
```

Replace with your real Railway domain if different (**Settings → Networking → Generate Domain**).

### Where to change it (common patterns)

Search the mobile project for `127.0.0.1` or `8000` and update **one** central config:

| Stack | Typical file |
|--------|----------------|
| Expo | `.env` → `EXPO_PUBLIC_API_URL=https://web-production-6bdab.up.railway.app` |
| React Native | `src/config/api.ts`, `src/constants/config.js`, or `.env` |
| Flutter | `lib/config/api_config.dart` or `.env` |

**No** `npm run android:reverse` and **no** `npm run server` are needed for Railway — those are only for USB/local dev.

### After changing the URL

1. Save the file.
2. **Fully restart** the app (stop Metro / Expo, run again).
3. Sign in with demo user (created on Railway deploy):
   - `tenant@example.com` / `tenant2222`

### Quick API test (browser or Postman)

```http
GET https://web-production-6bdab.up.railway.app/api/mobile/health
```

Should return JSON with `"success": true`.

Login:

```http
POST https://web-production-6bdab.up.railway.app/api/login_check
Content-Type: application/json

{"email":"tenant@example.com","password":"tenant2222"}
```

Use the `token` in `Authorization: Bearer …` for other calls.

### Live sync on mobile

After login, poll every 5 seconds:

```http
GET /api/mobile/sync/revision
Authorization: Bearer <token>
```

Copy-paste helper: [mobile-live-sync-poll.js](mobile-live-sync-poll.js).

---

## Local dev only (USB / same Wi‑Fi)

Use `http://127.0.0.1:8000` **only** when:

1. Symfony is running on the PC (`php -S 127.0.0.1:8000 -t public` or `.\scripts\start-dev.ps1`).
2. Phone is connected by USB and you ran `adb reverse tcp:8000 tcp:8000` (or your project’s `npm run android:reverse`).

Or use your PC’s LAN IP, e.g. `http://192.168.1.10:8000`, and set `CORS_ALLOW_ORIGIN` in `.env` to match.

---

## Railway variables (web service)

Ensure these are set so login and sync work:

| Variable | Notes |
|----------|--------|
| `DATABASE_URL` | From Railway MySQL (not localhost) |
| `DEFAULT_URI` | `https://YOUR-APP.up.railway.app` |
| `CORS_ALLOW_ORIGIN` | Regex that includes your app / Expo dev origins |
| `JWT_PASSPHRASE` | Same as used to generate keys in build |

Import Postman environment: `postman/CasaClick-Railway.postman_environment.json`.
