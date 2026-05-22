# Mobile app integration guide

CasaClick does not ship a native project inside this repository. Any iOS/Android client can integrate using HTTPS + JSON + JWT. Below is a minimal **JavaScript (React Native / Expo–style)** flow you can translate to Dart, Kotlin, or Swift.

## Base URL

| Environment | Base URL |
|-------------|----------|
| **Railway (production)** | `https://web-production-6bdab.up.railway.app` (your real domain) |
| **Local + USB** | `http://127.0.0.1:8000` only after `adb reverse` + PHP server on PC |
| **Local + Wi‑Fi** | `http://YOUR_PC_LAN_IP:8000` |

**Do not** use `127.0.0.1` on a physical phone for production — you will see *Cannot reach CasaClick API*. See [MOBILE_CONNECT_RAILWAY.md](MOBILE_CONNECT_RAILWAY.md).

Set the same origin in server **CORS** (`CORS_ALLOW_ORIGIN` in `.env` / Railway variables).

## 1. Register and verify

- `POST /api/mobile/register`  
  Body: `{ "name", "email", "password", "role": "ROLE_TENANT" }`  
  Or use `POST /api/register` with `username`, `email`, `password`.

- Open the link from the verification email, or  
  `GET /api/mobile/verify-email/{token}`

Until verified, **login will fail** (by design).

## 2. Login (JWT)

```http
POST /api/login_check
Content-Type: application/json

{ "email": "tenant@example.com", "password": "your-password" }
```

Response contains `token`. Send on every protected request:

```http
Authorization: Bearer <token>
```

## 3. Browse listings (no auth)

```javascript
const base = 'https://your-host';
const res = await fetch(`${base}/api/mobile/listings`);
const json = await res.json();
// json.success, json.data[], json.meta.count
```

Image paths are site-relative (e.g. `/uploads/images/...`); prepend `base` when building URIs.

## 4. Customer profile and bookings

```javascript
const headers = {
  Authorization: `Bearer ${token}`,
  'Content-Type': 'application/json',
};

// Profile
let r = await fetch(`${base}/api/mobile/customer/profile`, { headers });
// PATCH name
r = await fetch(`${base}/api/mobile/customer/profile`, {
  method: 'PATCH',
  headers,
  body: JSON.stringify({ name: 'New Name' }),
});

// Create booking
r = await fetch(`${base}/api/mobile/customer/bookings`, {
  method: 'POST',
  headers,
  body: JSON.stringify({
    listingId: 1,
    message: 'I would like to view the unit.',
  }),
});

// List bookings
r = await fetch(`${base}/api/mobile/customer/bookings`, { headers });
```

## 5. Orders & payments

- **Orders:** `GET /api/mobile/customer/orders` — applications in `approved` or `completed` status.
- **List payments:** `GET /api/mobile/customer/payments`
- **Submit payment** (after landlord approved the application):

```javascript
await fetch(`${base}/api/mobile/customer/payments`, {
  method: 'POST',
  headers,
  body: JSON.stringify({
    applicationId: 5,
    paymentMethod: 'gcash', // cash | bank_transfer | gcash | paymaya | credit_card
    amount: '12000.50',     // optional; defaults to listing price
    notes: 'Ref ABC123',
  }),
});
```

Landlord confirms payment in the **web** dashboard; until then status stays `pending`.

### Paymongo (online checkout)

Tenants must choose **online (GCash/Maya)** or **card**, then fill channel-specific fields before checkout.

**Step 1 — load form schema**

```http
GET /api/mobile/payments/paymongo/options
Authorization: Bearer <token>
```

Response `data.categories[]` has payment types; each channel includes `requirements` (field keys, labels, required flags).

**Step 2 — start checkout** (application must be `approved`)

```javascript
const r = await fetch(`${base}/api/mobile/applications/${applicationId}/payments/paymongo`, {
  method: 'POST',
  headers,
  body: JSON.stringify({
    paymentChannel: 'gcash', // gcash | paymaya | card
    payerName: 'Juan Dela Cruz',
    payerContact: '09171234567', // required for gcash / paymaya
    payerEmail: 'juan@example.com', // required for card
    referenceNote: 'Unit 2B', // optional
    amount: '12000', // optional; defaults to listing price
    appOrigin: 'http://192.168.1.10:8000', // optional; used for dev mock checkout URLs
  }),
});
const json = await r.json();
// json.data.checkoutUrl — open in browser / WebView
```

On the **website**, tenants use **Pay with Paymongo** on an approved booking (`/payment/application/{id}/paymongo`) for the same flow.

With `PAYMONGO_DEV_MOCK=1` and no secret key, `checkoutUrl` opens a local demo page; tap **Complete** to mark the payment paid.

## 6. Real-time sync with web (local + Railway)

Web and mobile share **one MySQL database**. When someone books, pays, or approves on either side, the other side should refresh within a few seconds.

### Mobile (JWT)

Poll while the app is open (recommended every **5 seconds**):

```http
GET /api/mobile/sync/revision
Authorization: Bearer <token>
```

When `revision` changes, refetch the screens you are showing (`/api/mobile/customer/bookings`, listings, payments, etc.).

**Production:** set the app base URL to your Railway host, e.g. `https://web-production-6bdab.up.railway.app` — not `127.0.0.1`.

### Website (session cookie)

Logged-in pages poll `GET /sync/feed` every **5 seconds** and reload automatically when data changes. No extra setup on Railway beyond a working `DATABASE_URL`.

### Public listings (no login)

```http
GET /api/mobile/listings/revision
```

Poll every ~8s on browse screens; refetch `GET /api/mobile/listings` when the revision changes.

After actions on web (approve application, complete payment), you can also pull to refresh; polling handles cross-device updates automatically.

### Ready-made poll helper

Copy [mobile-live-sync-poll.js](mobile-live-sync-poll.js) into your mobile project:

```javascript
import { startLiveSyncPoll, stopLiveSyncPoll } from './mobile-live-sync-poll';

// After login:
startLiveSyncPoll({
  baseUrl: 'https://YOUR-APP.up.railway.app',
  token,
  onChange: async () => {
    await reloadBookings();
    await reloadPayments();
  },
});

// On logout / unmount:
stopLiveSyncPoll();
```

## 7. Error handling

Always check `res.ok` and parse JSON:

```javascript
const json = await res.json();
if (!json.success) {
  const msg = json.error || (json.errors && json.errors.join(', '));
  // show msg to user
}
```

HTTP codes: `401` missing/invalid JWT, `403` wrong role (e.g. admin token on customer routes), `404` missing resource, `409` conflict (duplicate booking), `422` business rule (e.g. payment before approval).
