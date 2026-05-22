/**
 * Polls /sync/feed while logged in; reloads when revision changes (mobile ↔ web, same DB).
 */
(function () {
    const body = document.body;
    if (!body || body.dataset.liveSync !== '1') {
        return;
    }

    const feedUrl = body.dataset.liveSyncUrl;
    if (!feedUrl) {
        return;
    }

    const intervalMs = Math.max(3000, parseInt(body.dataset.liveSyncInterval || '5000', 10) || 5000);
    let lastRevision = null;
    let inFlight = false;

    function poll() {
        if (inFlight) {
            return;
        }
        inFlight = true;
        fetch(feedUrl, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (!data || !data.success) {
                    return;
                }
                if (lastRevision !== null && data.revision !== lastRevision) {
                    window.location.reload();
                    return;
                }
                lastRevision = data.revision;
            })
            .catch(function () {})
            .finally(function () {
                inFlight = false;
            });
    }

    poll();
    window.setInterval(poll, intervalMs);
})();
