# Google Sign-In setup (web + mobile)

## 1. Google Cloud Console

1. Open [Google Cloud Console → Credentials](https://console.cloud.google.com/apis/credentials).
2. Create **OAuth 2.0 Client ID** → type **Web application**.
3. Copy **Client ID** and **Client secret**.

### Authorized JavaScript origins

Add every host you use:

```text
http://127.0.0.1:8000
http://localhost:8000
https://web-production-6bdab.up.railway.app
```

(Replace with your real Railway URL.)

### Authorized redirect URIs

Must match **exactly** (scheme + host + path):

```text
http://127.0.0.1:8000/connect/google/check
http://localhost:8000/connect/google/check
https://web-production-6bdab.up.railway.app/connect/google/check
```

### OAuth consent screen

- User type: **External** (for personal Gmail).
- While **Testing**: add each Gmail under **Test users**.
- Or **Publish** the app for production.

---

## 2. Symfony / Railway variables (web service)

| Variable | Value |
|----------|--------|
| `GOOGLE_OAUTH_CLIENT_ID` | Web client ID (`…apps.googleusercontent.com`) |
| `GOOGLE_OAUTH_CLIENT_SECRET` | **Required** — from the same Web client |
| `DEFAULT_URI` | `https://YOUR-APP.up.railway.app` (no trailing slash) |
| `GOOGLE_MOBILE_WEB_CLIENT_ID` | Optional — Android client ID if different from web |

**Without `GOOGLE_OAUTH_CLIENT_SECRET`, the “Sign in with Google” buttons are hidden and login will fail.**

After changing variables, **redeploy** the web service.

---

## 3. Website login

| Button | Role created (new user) |
|--------|-------------------------|
| **Sign in with Google** | Customer (`ROLE_TENANT`) |
| **Sign in with Google (Staff)** | Staff (`ROLE_STAFF`) |

Existing users are matched by Google account or email.

---

## 4. Mobile app (native)

1. Use **Web application** client ID as `webClientId` in the app (React Native Google Sign-In).
2. `POST /api/auth/google` with JSON:

```json
{
  "idToken": "<from Google Sign-In>",
  "role": "ROLE_TENANT"
}
```

3. Server must list your client ID in `GOOGLE_OAUTH_CLIENT_ID` and/or `GOOGLE_MOBILE_WEB_CLIENT_ID`.

---

## 5. Troubleshooting

| Problem | Fix |
|---------|-----|
| Buttons missing on login | Set `GOOGLE_OAUTH_CLIENT_SECRET` on Railway |
| `redirect_uri_mismatch` | Add exact `/connect/google/check` URL in Google Console |
| `invalid_client` | Wrong secret or client ID; no extra spaces in Railway vars |
| Google 403 / access denied | Add your Gmail as **Test user** or publish consent screen |
| Works on localhost, not Railway | Set `DEFAULT_URI` to Railway HTTPS URL and redeploy |

Check callback URL Symfony expects:

```bash
php bin/console router:match /connect/google/check --env=prod
```

Or read `DEFAULT_URI` + `/connect/google/check`.
