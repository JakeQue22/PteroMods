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

    window.pteroInstallMod = async function () {
        const input = document.getElementById('ptero-workshop-ref');
        const status = document.getElementById('ptero-install-status');
        const ref = (input && input.value ? input.value : '').trim();
        if (!ref) {
            return;
        }
        status.textContent = 'Queuing install…';
        status.className = 'dz-status dz-text-muted';
        try {
            const res = await apiPost('install', { reference: ref });
            const data = await res.json().catch(() => ({}));
            if (res.ok) {
                status.textContent = '✓ Install queued for Workshop ID ' + (data.workshop_id || ref);
                status.className = 'dz-status dz-text-green';
                if (input) {
                    input.value = '';
                }
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
        if (action === 'remove' && !confirm('Remove mod ' + workshopId + '?')) {
            return;
        }
        try {
            const res = await apiPost(action, { workshop_id: workshopId });
            if (res.ok) {
                location.reload();
            } else {
                const data = await res.json().catch(() => ({}));
                alert('Action failed: ' + (data.message || res.status));
            }
        } catch (err) {
            alert('Network error: ' + err.message);
        }
    };
}());
</script>
