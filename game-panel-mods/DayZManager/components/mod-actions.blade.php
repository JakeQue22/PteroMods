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

    // Restores any install still in progress when the page loads, so a
    // refresh mid-download shows the persisted queue instead of nothing.
    async function resumeQueue() {
        const status = document.getElementById('ptero-install-status');
        if (!status) {
            return;
        }
        try {
            const res = await apiGet('install/queue');
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !Array.isArray(data.queue) || data.queue.length === 0) {
                return;
            }
            status.classList.remove('dz-hidden');
            status.textContent = (data.complete ? '✓ ' : '⧗ ') + describeQueue(data.queue);
            status.className = 'dz-status ' + (data.complete ? 'dz-text-green' : 'dz-text-muted');
            if (!data.complete) {
                pollInstallStatus(status, data.queue.map(function (entry) { return entry.workshop_id; }), 30);
            }
        } catch (err) {
            // No persisted queue, or the request failed; nothing to resume.
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', resumeQueue);
    } else {
        resumeQueue();
    }

    let lookupTimer = null;

    function closeLookupDropdown() {
        const dropdown = document.getElementById('ptero-workshop-lookup');
        if (dropdown) {
            dropdown.remove();
        }
    }

    function renderLookupDropdown(input, info) {
        closeLookupDropdown();
        if (!info || !info.found) {
            return;
        }
        const dropdown = document.createElement('div');
        dropdown.id = 'ptero-workshop-lookup';
        dropdown.className = 'dz-lookup-dropdown';
        dropdown.innerHTML = '<button type="button" class="dz-lookup-item">'
            + (info.thumbnail ? '<img src="' + info.thumbnail + '" alt="" loading="lazy" />' : '')
            + '<span>' + (info.title || ('Workshop ' + info.workshop_id)) + ' <small>(' + info.workshop_id + ')</small></span>'
            + '</button>';
        dropdown.querySelector('.dz-lookup-item').addEventListener('click', function () {
            input.value = info.workshop_id;
            closeLookupDropdown();
        });
        input.insertAdjacentElement('afterend', dropdown);
    }

    function attachLookup() {
        const input = document.getElementById('ptero-workshop-ref');
        if (!input || input.dataset.pteroLookupBound) {
            return;
        }
        input.dataset.pteroLookupBound = '1';
        input.addEventListener('input', function () {
            const value = input.value.trim();
            clearTimeout(lookupTimer);
            if (!value) {
                closeLookupDropdown();
                return;
            }
            lookupTimer = setTimeout(async function () {
                try {
                    const res = await apiGet('lookup', { reference: value });
                    const data = await res.json().catch(() => ({}));
                    if (res.ok) {
                        renderLookupDropdown(input, data);
                    }
                } catch (err) {
                    // Lookup failures just skip the preview dropdown.
                }
            }, 400);
        });
        document.addEventListener('click', function (event) {
            if (event.target !== input) {
                closeLookupDropdown();
            }
        });
    }

    attachLookup();

    async function loadBrowseResults(term, page) {
        const grid = document.getElementById('ptero-browse-grid');
        const message = document.getElementById('ptero-browse-message');
        if (!grid) {
            return;
        }
        grid.innerHTML = '<p class="dz-sub">Loading…</p>';
        if (message) {
            message.textContent = '';
        }
        try {
            const res = await apiGet('browse', { search: term || '', page: page || 1 });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                grid.innerHTML = '';
                if (message) {
                    message.textContent = data.message || 'Could not load the Workshop.';
                }
                return;
            }
            if (data.enabled === false) {
                grid.innerHTML = '';
                if (message) {
                    message.textContent = data.message || 'Workshop browsing is not configured.';
                }
                return;
            }
            if (!Array.isArray(data.items) || data.items.length === 0) {
                grid.innerHTML = '<p class="dz-sub">No mods found.</p>';
                return;
            }
            grid.innerHTML = '';
            data.items.forEach(function (item) {
                const card = document.createElement('button');
                card.type = 'button';
                card.className = 'dz-browse-card';
                card.innerHTML = (item.thumbnail ? '<img src="' + item.thumbnail + '" alt="" loading="lazy" />' : '<div class="dz-browse-noimg"></div>')
                    + '<span>' + item.title + '</span>';
                card.addEventListener('click', function () {
                    const input = document.getElementById('ptero-workshop-ref');
                    if (input) {
                        input.value = item.workshop_id;
                    }
                    window.pteroCloseBrowseModal();
                });
                grid.appendChild(card);
            });
        } catch (err) {
            grid.innerHTML = '';
            if (message) {
                message.textContent = 'Network error: ' + err.message;
            }
        }
    }

    window.pteroOpenBrowseModal = function () {
        const modal = document.getElementById('ptero-browse-modal');
        if (!modal) {
            return;
        }
        modal.classList.remove('dz-hidden');
        loadBrowseResults('', 1);
    };

    window.pteroCloseBrowseModal = function () {
        const modal = document.getElementById('ptero-browse-modal');
        if (modal) {
            modal.classList.add('dz-hidden');
        }
    };

    window.pteroBrowseSearch = function () {
        const searchInput = document.getElementById('ptero-browse-search');
        loadBrowseResults(searchInput ? searchInput.value.trim() : '', 1);
    };

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
