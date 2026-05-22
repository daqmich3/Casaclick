# Course rubric ↔ CasaClick implementation

Use this document when grading or demonstrating the project. Point numbers match typical capstone-style sheets (total 100).

## 1. Customer mobile app integration (15 pts)

**Implemented in repo**

- REST API consumed by any mobile client (React Native, Flutter, Kotlin, etc.) over HTTPS with JSON and Bearer JWT.
- Public catalogue endpoints need no token; customer actions use `/api/mobile/customer/*` after login.
- Responsive web UI remains the parallel experience for small screens (Twig + CSS).

**How to demo**

1. Register and verify email (mobile register or `/api/register`).
2. `POST /api/login_check` → store `token`.
3. Call listings, create booking, refresh list — data matches **Doctrine entities** used by the web app (`Application`, `Payment`, `Product`).

**Deliverable for a native app:** Follow [MOBILE_APP_INTEGRATION.md](MOBILE_APP_INTEGRATION.md) (example fetch flow). Postman collection exercises the same flows.

---

## 2. Customer API development (15 pts)

**At least five functional REST areas (more than five endpoints):**

| Area | HTTP | Route |
|------|------|--------|
| Products (listings) | GET | `/api/mobile/listings`, `/api/mobile/listings/{id}` |
| Categories | GET | `/api/mobile/categories` |
| Profile | GET, PATCH | `/api/mobile/customer/profile` |
| Bookings | GET, POST, GET | `/api/mobile/customer/bookings`, `.../bookings/{id}` |
| Orders | GET | `/api/mobile/customer/orders` (approved / completed applications) |
| Payments | GET, POST | `/api/mobile/customer/payments` |

Plus: `POST /api/mobile/register`, `GET /api/mobile/verify-email/{token}`, `GET /api/mobile/me`.

JSON shape is normalized: `success`, `data`, optional `meta`, `errors` / `error`, with appropriate status codes (`400`, `401`, `403`, `404`, `409`, `422`, `201`).

---

## 3. Authentication & security (15 pts)

- **JWT** for API (`/api/login_check`, Lexik bundle, stateless `api` firewall).
- **Session + form login** for web (`main` firewall).
- **UserChecker:** disabled accounts and **unverified email** blocked at login (web and API).
- Argon/bcrypt password hashing via Symfony `security.password_hashers`.
- **CORS** via Nelmio — configure `CORS_ALLOW_ORIGIN` for your mobile or web dev origin.
- Sensitive config in `.env.local` / environment — not committed (see `.gitignore` for `*.local`).

---

## 4. Role-based access control — customer, staff, admin (10 pts)

- **Hierarchy** in `config/packages/security.yaml`: e.g. `ROLE_ADMIN` ⊃ `ROLE_STAFF` ⊃ `ROLE_TENANT`, `ROLE_LANDLORD` ⊃ `ROLE_TENANT`.
- **Web:** Dashboard `/dashboard` → `ROLE_STAFF`; user management → `ROLE_ADMIN`; tenant vs landlord routes under `/product`, `/application`, `/payment`, etc.
- **API:** `/api/mobile/customer/*` returns **403** when `User::getPrimaryRole()` is `ROLE_ADMIN`, `ROLE_STAFF`, or `ROLE_LANDLORD` so staff/admin/landlord cannot use the *customer* mobile contract by mistake.

---

## 5. Mobile & web synchronization (10 pts)

- Single **MySQL/Doctrine** database: web dashboard and API read/write the same `Application`, `Payment`, and `Product` rows.
- **Near real-time (5s):** Web polls `GET /sync/feed` (session); mobile polls `GET /api/mobile/sync/revision` (JWT). Same `LiveSyncRevisionService` on Railway and local.
- Public browse: `GET /api/mobile/listings/revision` (~8s). Sample mobile poller: [mobile-live-sync-poll.js](mobile-live-sync-poll.js). Postman folder **Live sync**.

---

## 6. Database design & data management (10 pts)

- Relational schema: `User`, `Product` (listings), `Category`, `Application` (booking), `Payment`, plus landlord/tenant profile entities as applicable.
- Foreign keys: application → listing, tenant, landlord; payment → application.
- CRUD via Doctrine repositories; migrations under `migrations/`.

---

## 7. Error handling & validation (10 pts)

- Customer API validates payloads (required ids, payment methods enum, positive amounts, duplicate / business rules e.g. occupied listing).
- HTTP status codes and structured JSON errors (see §2).
- Web: Symfony forms + flash messages; API mirrors business rules from `ApplicationController` / `PaymentController`.

---

## 8. UI/UX & branding consistency (5 pts)

- Shared CasaClick styling (`assets/styles/casaclick-brand.css`, theme, public-facing layouts).
- Admin/tenant navigation and role badges in base templates.

---

## 9. Deployment & system stability (5 pts)

- **Docker Compose** provides production-like MySQL (and phpMyAdmin) — see root `docker-compose.yaml`.
- App runs on PHP built-in server or Symfony CLI for demos; point `DATABASE_URL` at the container DB when using Compose.

---

## 10. Documentation & presentation (5 pts)

- This file + **README.md** (install, JWT, API table).
- **Postman:** `postman/CasaClick-API.postman_collection.json`.
- **Mobile integration:** `docs/MOBILE_APP_INTEGRATION.md`.
- Optional OpenAPI: `/api/docs.json` if API Platform is enabled for your build.
