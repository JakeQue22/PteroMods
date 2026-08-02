<script>
(function () {
    const SERVER_ID = @json($server_id);
    const ENDPOINT_BASE = '/api/server/' + encodeURIComponent(SERVER_ID) + '/dayz/mods/';

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    async function apiPost(endpoint, body) {
        return fetch(ENDPOINT_BASE + endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'Accept': 'application/json',
            },
            body: JSON.stringify(body),
        });
    }

    async function apiGet(endpoint, query) {
        const params = new URLSearchParams(query || {});
        return fetch(ENDPOINT_BASE + endpoint + (params.toString() ? '?' + params.toString() : ''), {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        });
    }

    function describeQueue(queue) {
        if (!Array.isArray(queue) || queue.length === 0) {
            return '';
        }
        return queue.map(function (entry) {
            const label = entry.title ? (entry.title + ' (' + entry.workshop_id + ')') : entry.workshop_id;
            return label + ': ' + (entry.installed ? 'installed' : 'downloading…');
        }).join(' · ');
    }

    async function pollInstallStatus(status, workshopIds, attemptsLeft) {
        if (attemptsLeft <= 0) {
            return;
        }
        try {
            const res = await apiGet('install/status', { workshop_ids: JSON.stringify(workshopIds) });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !Array.isArray(data.queue)) {
                return;
            }
            status.textContent = (data.complete ? '✓ ' : '⧗ ') + describeQueue(data.queue);
            status.className = 'dz-status ' + (data.complete ? 'dz-text-green' : 'dz-text-muted');
            if (!data.complete) {
                setTimeout(function () { pollInstallStatus(status, workshopIds, attemptsLeft - 1); }, 4000);
            }
        } catch (err) {
            // Polling failures are silent; the last known status stays on screen.
        }
    }

    window.pteroInstallMod = async function () {
        const input = document.getElementById('ptero-workshop-ref');
        const status = document.getElementById('ptero-install-status');
        const ref = (input && input.value ? input.value : '').trim();
        if (!ref) {
            return;
        }
        status.classList.remove('dz-hidden');
        status.textContent = 'Looking up Workshop ID ' + ref + '…';
        status.className = 'dz-status dz-text-muted';
        try {
            const res = await apiPost('install', { reference: ref });
            const data = await res.json().catch(() => ({}));
            if (res.ok) {
                const label = data.title ? (data.title + ' (' + data.workshop_id + ')') : (data.workshop_id || ref);
                status.textContent = '⧗ Queued ' + label + '. ' + describeQueue(data.queue);
                status.className = 'dz-status dz-text-muted';
                if (input) {
                    input.value = '';
                }
                const ids = Array.isArray(data.install_order) && data.install_order.length > 0
                    ? data.install_order
                    : [data.workshop_id || ref];
                pollInstallStatus(status, ids, 30);
            } else {
                status.textContent = '✗ ' + (data.message || ('Request failed (' + res.status + ')'));
                status.className = 'dz-status dz-text-red';
            }
        } catch (err) {
            status.textContent = '✗ Network error: ' + err.message;
            status.className = 'dz-status dz-text-red';
        }
    };

    window.pteroModAction = async function (workshopId, action) {
        if (action === 'remove' && !confirm('Remove ' + workshopId + '? This drops it from the load order and deletes its folder from the server.')) {
            return;
        }
        try {
            const res = await apiPost(action, { workshop_id: workshopId });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.status !== 'failed') {
                if (data.message) {
                    alert(data.message);
                }
                if (data.status !== 'manual') {
                    location.reload();
                }
            } else {
                alert('Action failed: ' + (data.message || res.status));
            }
        } catch (err) {
            alert('Network error: ' + err.message);
        }
    };
}());
</script>
