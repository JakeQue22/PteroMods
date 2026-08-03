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

    // ---------------------------------------------------------------------------
    // Queue display helpers
    // ---------------------------------------------------------------------------

    function renderQueueList(queue, complete) {
        const container = document.getElementById('ptero-install-queue');
        const status = document.getElementById('ptero-install-status');
        if (!container) return;

        if (!Array.isArray(queue) || queue.length === 0) {
            container.classList.add('dz-hidden');
            return;
        }

        container.classList.remove('dz-hidden');
        container.innerHTML = '';

        queue.forEach(function (entry) {
            const label = entry.title ? (entry.title + ' (' + entry.workshop_id + ')') : entry.workshop_id;
            const done = entry.installed;
            const row = document.createElement('div');
            row.className = 'dz-queue-item';
            row.innerHTML = (entry.thumbnail
                ? '<img src="' + entry.thumbnail + '" alt="" loading="lazy" class="dz-queue-thumb" />'
                : '<div class="dz-queue-thumb dz-queue-noimg"></div>')
                + '<span class="dz-queue-label">' + label + '</span>'
                + '<span class="dz-queue-status ' + (done ? 'dz-text-green' : 'dz-text-amber') + '">'
                + (done ? '✓ Installed' : '⧗ Downloading…') + '</span>';
            container.appendChild(row);
        });

        if (status) {
            status.classList.remove('dz-hidden');
            status.textContent = complete
                ? '✓ All mods installed.'
                : '⧗ Download in progress — check back or restart the server if this persists.';
            status.className = 'dz-status ' + (complete ? 'dz-text-green' : 'dz-text-muted');
        }
    }

    async function pollInstallStatus(workshopIds, attemptsLeft) {
        if (attemptsLeft <= 0) {
            return;
        }
        try {
            const res = await apiGet('install/status', { workshop_ids: JSON.stringify(workshopIds) });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !Array.isArray(data.queue)) {
                return;
            }
            renderQueueList(data.queue, data.complete);
            if (!data.complete) {
                setTimeout(function () { pollInstallStatus(workshopIds, attemptsLeft - 1); }, 4000);
            }
        } catch (err) {
            // Polling failures are silent; the last known status stays on screen.
        }
    }

    // Restores any install still in progress when the page loads.
    async function resumeQueue() {
        try {
            const res = await apiGet('install/queue');
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !Array.isArray(data.queue) || data.queue.length === 0) {
                return;
            }
            renderQueueList(data.queue, data.complete);
            if (!data.complete) {
                pollInstallStatus(data.queue.map(function (e) { return e.workshop_id; }), 90);
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

    // ---------------------------------------------------------------------------
    // Lookup dropdown
    // ---------------------------------------------------------------------------

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
            + '<span class="dz-text-muted" style="font-size:0.72rem;margin-left:auto;">Click to install</span>'
            + '</button>';
        dropdown.querySelector('.dz-lookup-item').addEventListener('click', function () {
            input.value = info.workshop_id;
            closeLookupDropdown();
            window.pteroInstallMod();
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
            const dropdown = document.getElementById('ptero-workshop-lookup');
            if (dropdown && !dropdown.contains(event.target) && event.target !== input) {
                closeLookupDropdown();
            }
        });
    }

    attachLookup();

    // ---------------------------------------------------------------------------
    // Browse modal
    // ---------------------------------------------------------------------------

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
                    window.pteroInstallMod();
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

    // ---------------------------------------------------------------------------
    // Install
    // ---------------------------------------------------------------------------

    window.pteroInstallMod = async function () {
        const input = document.getElementById('ptero-workshop-ref');
        const status = document.getElementById('ptero-install-status');
        const ref = (input && input.value ? input.value : '').trim();
        if (!ref) {
            return;
        }
        if (input) { input.value = ''; }
        closeLookupDropdown();
        if (status) {
            status.classList.remove('dz-hidden');
            status.textContent = 'Queueing install for ' + ref + '…';
            status.className = 'dz-status dz-text-muted';
        }
        try {
            const res = await apiPost('install', { reference: ref });
            const data = await res.json().catch(() => ({}));
            if (res.ok) {
                if (status) {
                    status.textContent = data.message || '⧗ Install queued.';
                    status.className = 'dz-status dz-text-muted';
                }
                if (Array.isArray(data.queue) && data.queue.length > 0) {
                    renderQueueList(data.queue, false);
                }
                const ids = Array.isArray(data.install_order) && data.install_order.length > 0
                    ? data.install_order
                    : [data.workshop_id || ref];
                pollInstallStatus(ids, 90);
            } else {
                if (status) {
                    status.textContent = '✗ ' + (data.message || ('Request failed (' + res.status + ')'));
                    status.className = 'dz-status dz-text-red';
                }
            }
        } catch (err) {
            if (status) {
                status.textContent = '✗ Network error: ' + err.message;
                status.className = 'dz-status dz-text-red';
            }
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
