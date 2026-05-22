/**
 * Copy into your React Native / Expo app. Polls the same revision as the website.
 *
 *   import { startLiveSyncPoll, stopLiveSyncPoll } from './mobile-live-sync-poll';
 *   startLiveSyncPoll({ baseUrl: 'https://YOUR-APP.up.railway.app', token, onChange: reloadScreens });
 */
const DEFAULT_INTERVAL_MS = 5000;

let timerId = null;
let lastRevision = null;

/**
 * @param {object} options
 * @param {string} options.baseUrl - Railway or local origin (no trailing slash)
 * @param {string} options.token - JWT from POST /api/login_check
 * @param {(payload: object) => void} options.onChange - refetch bookings/listings/payments
 * @param {number} [options.intervalMs=5000]
 */
export function startLiveSyncPoll({ baseUrl, token, onChange, intervalMs = DEFAULT_INTERVAL_MS }) {
  stopLiveSyncPoll();
  const url = `${baseUrl.replace(/\/$/, '')}/api/mobile/sync/revision`;

  async function tick() {
    try {
      const res = await fetch(url, {
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
      });
      const data = await res.json();
      if (!data?.success) {
        return;
      }
      if (lastRevision !== null && data.revision !== lastRevision) {
        onChange(data);
      }
      lastRevision = data.revision;
    } catch {
      /* network blip — retry next tick */
    }
  }

  tick();
  timerId = setInterval(tick, intervalMs);
}

export function stopLiveSyncPoll() {
  if (timerId !== null) {
    clearInterval(timerId);
    timerId = null;
  }
  lastRevision = null;
}

/**
 * Public marketplace (no JWT). Poll GET /api/mobile/listings/revision.
 */
export function startListingsSyncPoll({ baseUrl, onChange, intervalMs = 8000 }) {
  let listingsTimer = null;
  let listingsRev = null;
  const url = `${baseUrl.replace(/\/$/, '')}/api/mobile/listings/revision`;

  async function tick() {
    try {
      const res = await fetch(url, { headers: { Accept: 'application/json' } });
      const data = await res.json();
      if (!data?.success) {
        return;
      }
      const rev = data.revision ?? data.data?.revision;
      if (listingsRev !== null && rev !== listingsRev) {
        onChange(data);
      }
      listingsRev = rev;
    } catch {
      /* ignore */
    }
  }

  tick();
  listingsTimer = setInterval(tick, intervalMs);

  return () => {
    if (listingsTimer) {
      clearInterval(listingsTimer);
    }
    listingsRev = null;
  };
}
