<script>
(function () {
    const SERVER_ID = @json($server_id);
    const ENDPOINT_BASE = '/api/server/' + encodeURIComponent(SERVER_ID) + '/dayz/mods/';
    const queuedWorkshopIds = new Set();
    const installedWorkshopIds = new Set(@json($installed_workshop_ids ?? []));
    let installPollToken = 0;
    let browseFetchController = null;
    const browseState = {
        term: '',
        page: 1,
        hasMore: false,
        sort: 'most_popular',
        filters: { type: '', mod_type: '', required_dlc: '' },
        items: [],
        selectedId: '',
        checkedIds: new Set(),
    };

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatNumber(value) {
        const number = Number(value || 0);
        return Number.isFinite(number) ? number.toLocaleString() : '0';
    }

    function formatFileSize(bytes) {
        const size = Number(bytes || 0);
        if (!Number.isFinite(size) || size <= 0) {
            return 'Unknown size';
        }
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let value = size;
        let unit = 0;
        while (value >= 1024 && unit < units.length - 1) {
            value /= 1024;
            unit += 1;
        }
        return (value >= 10 || unit === 0 ? value.toFixed(0) : value.toFixed(1)) + ' ' + units[unit];
    }

    function formatTimestamp(value) {
        const timestamp = Number(value || 0);
        if (!Number.isFinite(timestamp) || timestamp <= 0) {
            return 'Unknown';
        }
        try {
            return new Date(timestamp * 1000).toLocaleDateString();
        } catch (err) {
            return 'Unknown';
        }
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

    function normalizeWorkshopId(value) {
        return String(value || '').trim();
    }

    function isBrowseModalOpen() {
        const modal = document.getElementById('ptero-browse-modal');
        return !!modal && !modal.classList.contains('dz-hidden');
    }

    function refreshQueueActions() {
        const actions = document.getElementById('ptero-queue-actions');
        if (!actions) {
            return;
        }
        actions.classList.toggle('dz-hidden', queuedWorkshopIds.size === 0);
    }

    function renderQueueList(queue, complete) {
        const container = document.getElementById('ptero-install-queue');
        const status = document.getElementById('ptero-install-status');
        if (!container) {
            return;
        }

        queuedWorkshopIds.clear();

        if (!Array.isArray(queue) || queue.length === 0) {
            container.classList.add('dz-hidden');
            container.innerHTML = '';
            refreshQueueActions();
            if (isBrowseModalOpen()) {
                renderBrowseGrid();
            }
            return;
        }

        container.classList.remove('dz-hidden');
        container.innerHTML = '';

        queue.forEach(function (entry) {
            const workshopId = normalizeWorkshopId(entry.workshop_id);
            const label = entry.title ? (entry.title + ' (' + entry.workshop_id + ')') : entry.workshop_id;
            const done = entry.installed;
            if (workshopId !== '' && !done) {
                queuedWorkshopIds.add(workshopId);
            }
            if (workshopId !== '' && done) {
                installedWorkshopIds.add(workshopId);
            }
            const row = document.createElement('div');
            row.className = 'dz-queue-item';
            row.innerHTML = (entry.thumbnail
                ? '<img src="' + escapeHtml(entry.thumbnail) + '" alt="" loading="lazy" class="dz-queue-thumb" />'
                : '<div class="dz-queue-thumb dz-queue-noimg"></div>')
                + '<span class="dz-queue-label">' + escapeHtml(label) + '</span>'
                + '<span class="dz-queue-status ' + (done ? 'dz-text-green' : 'dz-text-amber') + '">'
                + (done ? '✓ Installed' : '⧗ Queued') + '</span>'
                + '<button type="button" class="dz-btn dz-btn-sm dz-btn-red" data-queue-remove="' + escapeHtml(workshopId) + '">✕</button>';
            container.appendChild(row);

            const removeBtn = row.querySelector('[data-queue-remove]');
            if (removeBtn) {
                removeBtn.addEventListener('click', function () {
                    window.pteroRemoveQueuedInstall(workshopId);
                });
            }
        });

        if (status) {
            status.classList.remove('dz-hidden');
            status.textContent = complete
                ? '✓ All mods installed.'
                : '⧗ Mods are queued. Restart the server to install queued mods.';
            status.className = 'dz-status ' + (complete ? 'dz-text-green' : 'dz-text-muted');
        }

        refreshQueueActions();

        if (isBrowseModalOpen()) {
            renderBrowseGrid();
        }
    }

    function startInstallPolling(workshopIds) {
        installPollToken += 1;
        const token = installPollToken;
        pollInstallStatus(workshopIds, 90, token);
    }

    async function pollInstallStatus(workshopIds, attemptsLeft, token) {
        if (attemptsLeft <= 0 || token !== installPollToken) {
            return;
        }
        try {
            const res = await apiGet('install/status', { workshop_ids: JSON.stringify(workshopIds) });
            const data = await res.json().catch(() => ({}));
            if (token !== installPollToken || !res.ok || !Array.isArray(data.queue)) {
                return;
            }
            renderQueueList(data.queue, data.complete);
            if (!data.complete) {
                setTimeout(function () { pollInstallStatus(workshopIds, attemptsLeft - 1, token); }, 4000);
            }
        } catch (err) {
            // Polling failures are silent; the last known status stays on screen.
        }
    }

    async function resumeQueue() {
        try {
            const res = await apiGet('install/queue');
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !Array.isArray(data.queue) || data.queue.length === 0) {
                return;
            }
            renderQueueList(data.queue, data.complete);
            if (!data.complete) {
                startInstallPolling(data.queue.map(function (entry) { return entry.workshop_id; }));
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
        const workshopId = normalizeWorkshopId(info.workshop_id);
        const alreadyQueued = workshopId !== '' && queuedWorkshopIds.has(workshopId);
        const alreadyInstalled = workshopId !== '' && installedWorkshopIds.has(workshopId);
        const disabled = alreadyQueued || alreadyInstalled;
        const dropdown = document.createElement('div');
        dropdown.id = 'ptero-workshop-lookup';
        dropdown.className = 'dz-lookup-dropdown';
        dropdown.innerHTML = '<button type="button" class="dz-lookup-item' + (disabled ? ' is-disabled' : '') + '"'
            + (disabled ? ' disabled' : '')
            + '>'
            + (info.thumbnail ? '<img src="' + escapeHtml(info.thumbnail) + '" alt="" loading="lazy" />' : '')
            + '<span>' + escapeHtml(info.title || ('Workshop ' + info.workshop_id)) + ' <small>(' + escapeHtml(info.workshop_id) + ')</small></span>'
            + '<span class="dz-text-muted" style="font-size:0.72rem;margin-left:auto;">'
            + (alreadyInstalled ? 'Already installed' : (alreadyQueued ? 'Already queued' : 'Click to queue'))
            + '</span>'
            + '</button>';
        if (!disabled) {
            dropdown.querySelector('.dz-lookup-item').addEventListener('click', function () {
                input.value = info.workshop_id;
                closeLookupDropdown();
                window.pteroInstallMod();
            });
        }
        const wrap = input.closest('.dz-input-wrap');
        if (wrap) {
            wrap.appendChild(dropdown);
        } else {
            input.insertAdjacentElement('afterend', dropdown);
        }
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

    function browseControls() {
        return {
            grid: document.getElementById('ptero-browse-grid'),
            message: document.getElementById('ptero-browse-message'),
            details: document.getElementById('ptero-browse-details'),
            pageLabel: document.getElementById('ptero-browse-page-label'),
            prev: document.getElementById('ptero-browse-prev'),
            next: document.getElementById('ptero-browse-next'),
            search: document.getElementById('ptero-browse-search'),
            sort: document.getElementById('ptero-browse-sort'),
            type: document.getElementById('ptero-browse-filter-type'),
            modType: document.getElementById('ptero-browse-filter-mod-type'),
            requiredDlc: document.getElementById('ptero-browse-filter-required-dlc'),
            queueSelected: document.getElementById('ptero-browse-queue-selected'),
        };
    }

    function refreshBrowseQueueButton() {
        const btn = document.getElementById('ptero-browse-queue-selected');
        if (!btn) {
            return;
        }
        const count = browseState.checkedIds.size;
        btn.textContent = count > 0 ? 'Queue selected (' + count + ')' : 'Queue selected';
        btn.disabled = count === 0;
    }

    function selectedBrowseItem() {
        const selectedId = browseState.selectedId;
        if (!selectedId) {
            return browseState.items[0] || null;
        }
        return browseState.items.find(function (item) {
            return normalizeWorkshopId(item.workshop_id) === selectedId;
        }) || browseState.items[0] || null;
    }

    function renderBrowseFilters(definitions, group, container) {
        if (!container) {
            return;
        }
        container.innerHTML = '';
        const allButton = document.createElement('button');
        allButton.type = 'button';
        allButton.className = 'dz-browse-filter' + (!browseState.filters[group] ? ' is-active' : '');
        allButton.textContent = 'All';
        allButton.addEventListener('click', function () {
            browseState.filters[group] = '';
            loadBrowseResults(browseState.term, 1);
        });
        container.appendChild(allButton);

        (definitions || []).forEach(function (definition) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'dz-browse-filter' + (browseState.filters[group] === definition.value ? ' is-active' : '');
            button.innerHTML = '<span>' + escapeHtml(definition.label) + '</span>'
                + (definition.count ? '<small>' + escapeHtml(definition.count) + '</small>' : '');
            button.addEventListener('click', function () {
                browseState.filters[group] = browseState.filters[group] === definition.value ? '' : definition.value;
                loadBrowseResults(browseState.term, 1);
            });
            container.appendChild(button);
        });
    }

    function renderBrowseDetails(item) {
        const controls = browseControls();
        if (!controls.details) {
            return;
        }
        if (!item) {
            controls.details.innerHTML = '<p class="dz-sub">Select a Workshop item to view details.</p>';
            return;
        }
        const workshopId = normalizeWorkshopId(item.workshop_id);
        const alreadyQueued = workshopId !== '' && queuedWorkshopIds.has(workshopId);
        const alreadyInstalled = (workshopId !== '' && installedWorkshopIds.has(workshopId)) || !!item.installed;
        const disabled = alreadyQueued || alreadyInstalled;
        const description = String(item.description || '').trim();
        const tags = Array.isArray(item.tags) ? item.tags.filter(Boolean) : [];
        controls.details.innerHTML = ''
            + '<div class="dz-browse-details-card">'
            + (item.thumbnail ? '<img src="' + escapeHtml(item.thumbnail) + '" alt="" loading="lazy" class="dz-browse-details-thumb" />' : '<div class="dz-browse-details-thumb dz-browse-noimg"></div>')
            + '<h4>' + escapeHtml(item.title || ('Workshop ' + workshopId)) + '</h4>'
            + '<p class="dz-sub">Workshop ID: <code>' + escapeHtml(workshopId) + '</code></p>'
            + '<dl class="dz-browse-meta">'
            + '<div><dt>Updated</dt><dd>' + escapeHtml(formatTimestamp(item.time_updated)) + '</dd></div>'
            + '<div><dt>Published</dt><dd>' + escapeHtml(formatTimestamp(item.time_created)) + '</dd></div>'
            + '<div><dt>Subscribers</dt><dd>' + escapeHtml(formatNumber(item.subscriptions)) + '</dd></div>'
            + '<div><dt>Votes</dt><dd>' + escapeHtml(formatNumber(item.votes_up)) + '</dd></div>'
            + '<div><dt>Size</dt><dd>' + escapeHtml(formatFileSize(item.file_size)) + '</dd></div>'
            + '</dl>'
            + (tags.length > 0 ? '<div class="dz-tags dz-browse-tags">' + tags.map(function (tag) {
                return '<span>' + escapeHtml(tag) + '</span>';
            }).join('') + '</div>' : '')
            + '<p class="dz-browse-description">' + escapeHtml(description || 'No description provided.') + '</p>'
            + '<div class="dz-browse-detail-actions">'
            + '<button type="button" class="dz-btn dz-btn-sm" data-browse-install'
            + (disabled ? ' disabled' : '')
            + '>' + (alreadyInstalled ? 'Already installed' : (alreadyQueued ? 'Already queued' : 'Queue install')) + '</button>'
            + '<a class="dz-btn dz-btn-sm dz-btn-ghost" href="' + escapeHtml(item.view_url || ('https://steamcommunity.com/sharedfiles/filedetails/?id=' + workshopId)) + '" target="_blank" rel="noopener noreferrer">Open on Steam</a>'
            + '</div>'
            + '</div>';

        const installButton = controls.details.querySelector('[data-browse-install]');
        if (installButton && !disabled) {
            installButton.addEventListener('click', function () {
                pteroQueueFromBrowse(workshopId);
            });
        }
    }

    function renderBrowseGrid() {
        const controls = browseControls();
        if (!controls.grid) {
            return;
        }
        if (!Array.isArray(browseState.items) || browseState.items.length === 0) {
            controls.grid.innerHTML = '<p class="dz-sub">No mods found.</p>';
            renderBrowseDetails(null);
            return;
        }
        const selected = selectedBrowseItem();
        browseState.selectedId = selected ? normalizeWorkshopId(selected.workshop_id) : '';
        controls.grid.innerHTML = '';

        browseState.items.forEach(function (item) {
            const workshopId = normalizeWorkshopId(item.workshop_id);
            const alreadyQueued = workshopId !== '' && queuedWorkshopIds.has(workshopId);
            const alreadyInstalled = (workshopId !== '' && installedWorkshopIds.has(workshopId)) || !!item.installed;
            const disabled = alreadyQueued || alreadyInstalled;
            // Remove from checked set if the mod became queued/installed.
            if (disabled) {
                browseState.checkedIds.delete(workshopId);
            }
            const isChecked = !disabled && browseState.checkedIds.has(workshopId);
            const card = document.createElement('div');
            card.className = 'dz-browse-card' + (browseState.selectedId === workshopId ? ' is-selected' : '') + (disabled ? ' is-disabled' : '') + (isChecked ? ' is-checked' : '');
            card.innerHTML = ''
                + (!disabled ? '<label class="dz-browse-check" title="Select for batch queue"><input type="checkbox" data-browse-checkbox' + (isChecked ? ' checked' : '') + ' /><span></span></label>' : '')
                + (item.thumbnail ? '<img src="' + escapeHtml(item.thumbnail) + '" alt="" loading="lazy" />' : '<div class="dz-browse-noimg"></div>')
                + '<div class="dz-browse-card-body">'
                + '<strong>' + escapeHtml(item.title || ('Workshop ' + workshopId)) + '</strong>'
                + '<span class="dz-text-muted">ID ' + escapeHtml(workshopId) + '</span>'
                + '<span class="dz-text-muted">' + escapeHtml(formatNumber(item.subscriptions)) + ' subscribers</span>'
                + (alreadyInstalled ? '<span class="dz-text-green">Already installed</span>' : (alreadyQueued ? '<span class="dz-text-amber">Already queued</span>' : ''))
                + '</div>'
                + '<div class="dz-browse-card-actions">'
                + '<button type="button" class="dz-btn dz-btn-sm dz-btn-ghost" data-browse-details>View Details</button>'
                + '<button type="button" class="dz-btn dz-btn-sm" data-browse-install' + (disabled ? ' disabled' : '') + '>'
                + (alreadyInstalled ? 'Installed' : (alreadyQueued ? 'Queued' : 'Queue')) + '</button>'
                + '</div>';
            controls.grid.appendChild(card);

            const select = function () {
                browseState.selectedId = workshopId;
                renderBrowseGrid();
                renderBrowseDetails(item);
            };

            const detailsButton = card.querySelector('[data-browse-details]');
            if (detailsButton) {
                detailsButton.addEventListener('click', select);
            }

            card.querySelector('img, .dz-browse-noimg, .dz-browse-card-body')?.addEventListener('click', select);

            const checkbox = card.querySelector('[data-browse-checkbox]');
            if (checkbox) {
                checkbox.addEventListener('change', function () {
                    if (checkbox.checked) {
                        browseState.checkedIds.add(workshopId);
                        card.classList.add('is-checked');
                    } else {
                        browseState.checkedIds.delete(workshopId);
                        card.classList.remove('is-checked');
                    }
                    refreshBrowseQueueButton();
                });
            }

            const installButton = card.querySelector('[data-browse-install]');
            if (installButton && !disabled) {
                installButton.addEventListener('click', function () {
                    pteroQueueFromBrowse(workshopId);
                });
            }
        });

        refreshBrowseQueueButton();

        renderBrowseDetails(selectedBrowseItem());
    }

    function updateBrowsePagination() {
        const controls = browseControls();
        if (controls.pageLabel) {
            controls.pageLabel.textContent = 'Page ' + browseState.page;
        }
        if (controls.prev) {
            controls.prev.disabled = browseState.page <= 1;
        }
        if (controls.next) {
            controls.next.disabled = !browseState.hasMore;
        }
    }

    async function loadBrowseResults(term, page) {
        const controls = browseControls();
        if (!controls.grid) {
            return;
        }
        browseState.term = term || '';
        browseState.page = Math.max(1, Number(page || 1));
        browseState.sort = controls.sort ? controls.sort.value : browseState.sort;
        controls.grid.innerHTML = '<p class="dz-sub">Loading…</p>';
        if (controls.message) {
            controls.message.textContent = '';
        }

        // Abort any previous in-flight browse request so that rapid page
        // navigation or filter changes never append stale results to the grid.
        if (browseFetchController) {
            browseFetchController.abort();
        }
        browseFetchController = typeof AbortController !== 'undefined' ? new AbortController() : null;
        const signal = browseFetchController ? browseFetchController.signal : undefined;

        try {
            const url = ENDPOINT_BASE + 'browse?' + new URLSearchParams({
                search: browseState.term,
                page: browseState.page,
                sort: browseState.sort,
                type: browseState.filters.type || '',
                mod_type: browseState.filters.mod_type || '',
                required_dlc: browseState.filters.required_dlc || '',
            }).toString();
            const res = await fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
                signal: signal,
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                controls.grid.innerHTML = '';
                if (controls.message) {
                    controls.message.textContent = data.message || 'Could not load the Workshop.';
                }
                return;
            }
            if (data.enabled === false) {
                controls.grid.innerHTML = '';
                if (controls.message) {
                    controls.message.textContent = data.message || 'Workshop browsing is not configured.';
                }
                return;
            }
            browseState.items = Array.isArray(data.items) ? data.items : [];
            browseState.hasMore = !!data.has_more;
            browseState.sort = String(data.sort || browseState.sort || 'most_popular');
            browseState.filters = Object.assign({ type: '', mod_type: '', required_dlc: '' }, data.filters || browseState.filters);
            browseState.selectedId = browseState.items.some(function (item) {
                return normalizeWorkshopId(item.workshop_id) === browseState.selectedId;
            }) ? browseState.selectedId : '';
            // Clear selections when the results change so the checked set does not
            // hold IDs that are no longer visible on the current page.
            browseState.checkedIds.clear();

            if (controls.sort) {
                controls.sort.value = browseState.sort;
            }

            renderBrowseFilters((data.available_filters || {}).type || [], 'type', controls.type);
            renderBrowseFilters((data.available_filters || {}).mod_type || [], 'mod_type', controls.modType);
            renderBrowseFilters((data.available_filters || {}).required_dlc || [], 'required_dlc', controls.requiredDlc);
            renderBrowseGrid();
            updateBrowsePagination();
        } catch (err) {
            if (err && err.name === 'AbortError') {
                // A newer request was started; silently discard this stale response.
                return;
            }
            controls.grid.innerHTML = '';
            if (controls.message) {
                controls.message.textContent = 'Network error: ' + err.message;
            }
        }
    }

    window.pteroOpenBrowseModal = function () {
        const modal = document.getElementById('ptero-browse-modal');
        const controls = browseControls();
        if (!modal) {
            return;
        }
        modal.classList.remove('dz-hidden');
        if (controls.search) {
            controls.search.value = browseState.term;
        }
        if (controls.sort) {
            controls.sort.value = browseState.sort;
        }
        loadBrowseResults(browseState.term, browseState.page);
    };

    window.pteroCloseBrowseModal = function () {
        const modal = document.getElementById('ptero-browse-modal');
        if (modal) {
            modal.classList.add('dz-hidden');
        }
    };

    function bindBrowseModalClose() {
        const modal = document.getElementById('ptero-browse-modal');
        if (!modal || modal.dataset.pteroBrowseBound) {
            return;
        }
        modal.dataset.pteroBrowseBound = '1';
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                window.pteroCloseBrowseModal();
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                window.pteroCloseBrowseModal();
            }
        });
    }

    window.pteroBrowseSearch = function () {
        const controls = browseControls();
        loadBrowseResults(controls.search ? controls.search.value.trim() : '', 1);
    };

    window.pteroBrowseApplyOptions = function () {
        window.pteroBrowseSearch();
    };

    window.pteroBrowsePage = function (direction) {
        const nextPage = browseState.page + Number(direction || 0);
        if (nextPage < 1 || (direction > 0 && !browseState.hasMore)) {
            return;
        }
        loadBrowseResults(browseState.term, nextPage);
    };

    bindBrowseModalClose();

    // Queues a mod directly from the Browse Workshop modal without touching the
    // shared input field, so multiple mods can be stacked without racing.
    async function pteroQueueFromBrowse(workshopId) {
        if (!workshopId) {
            return;
        }
        // Optimistically mark as queued so the button updates immediately.
        queuedWorkshopIds.add(workshopId);
        renderBrowseGrid();

        const status = document.getElementById('ptero-install-status');
        if (status) {
            status.classList.remove('dz-hidden');
            status.textContent = 'Queueing install for ' + workshopId + '…';
            status.className = 'dz-status dz-text-muted';
        }
        try {
            const res = await apiPost('install', { reference: workshopId, force_restart: false });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.status !== 'failed') {
                if (status) {
                    status.textContent = data.message || '⧗ Install queued.';
                    status.className = 'dz-status dz-text-muted';
                }
                if (Array.isArray(data.queue)) {
                    renderQueueList(data.queue, data.complete);
                }
                const ids = Array.isArray(data.install_order) && data.install_order.length > 0
                    ? data.install_order
                    : [data.workshop_id || workshopId];
                if (ids.length > 0) {
                    startInstallPolling(ids);
                }
                if (isBrowseModalOpen()) {
                    renderBrowseGrid();
                }
            } else {
                // Rollback the optimistic mark on failure.
                queuedWorkshopIds.delete(workshopId);
                if (status) {
                    status.textContent = '✗ ' + (data.message || ('Request failed (' + res.status + ')'));
                    status.className = 'dz-status dz-text-red';
                }
                if (isBrowseModalOpen()) {
                    renderBrowseGrid();
                }
            }
        } catch (err) {
            queuedWorkshopIds.delete(workshopId);
            if (status) {
                status.textContent = '✗ Network error: ' + err.message;
                status.className = 'dz-status dz-text-red';
            }
            if (isBrowseModalOpen()) {
                renderBrowseGrid();
            }
        }
    }

    // Queues all checked (multi-selected) mods from the Browse Workshop modal
    // sequentially so the server receives one install request per mod.
    window.pteroQueueCheckedMods = async function () {
        const ids = Array.from(browseState.checkedIds);
        if (ids.length === 0) {
            return;
        }
        browseState.checkedIds.clear();
        refreshBrowseQueueButton();
        for (const workshopId of ids) {
            await pteroQueueFromBrowse(workshopId);
        }
    };

    window.pteroInstallMod = async function (forceRestart) {
        const input = document.getElementById('ptero-workshop-ref');
        const status = document.getElementById('ptero-install-status');
        const restartNow = !!forceRestart;
        const ref = (input && input.value ? input.value : '').trim();
        if (!ref) {
            return;
        }
        if (restartNow && !confirm('Queue this install and restart the server now? Warning: this will restart the server immediately.')) {
            return;
        }
        if (input) {
            input.value = '';
        }
        closeLookupDropdown();
        if (status) {
            status.classList.remove('dz-hidden');
            status.textContent = 'Queueing install for ' + ref + '…';
            status.className = 'dz-status dz-text-muted';
        }
        try {
            const res = await apiPost('install', { reference: ref, force_restart: restartNow });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.status !== 'failed') {
                if (status) {
                    status.textContent = data.message || '⧗ Install queued.';
                    status.className = 'dz-status dz-text-muted';
                }
                if (Array.isArray(data.queue)) {
                    renderQueueList(data.queue, data.complete);
                }
                const ids = Array.isArray(data.install_order) && data.install_order.length > 0
                    ? data.install_order
                    : [data.workshop_id || ref];
                if (ids.length > 0) {
                    startInstallPolling(ids);
                }
                if (isBrowseModalOpen()) {
                    loadBrowseResults(browseState.term, browseState.page);
                }
            } else if (status) {
                status.textContent = '✗ ' + (data.message || ('Request failed (' + res.status + ')'));
                status.className = 'dz-status dz-text-red';
            }
        } catch (err) {
            if (status) {
                status.textContent = '✗ Network error: ' + err.message;
                status.className = 'dz-status dz-text-red';
            }
        }
    };

    window.pteroRestartQueuedInstall = async function () {
        const status = document.getElementById('ptero-install-status');
        if (!confirm('Restart server to process queued workshop installs? Warning: connected players will be disconnected.')) {
            return;
        }
        if (status) {
            status.classList.remove('dz-hidden');
            status.textContent = 'Sending restart signal…';
            status.className = 'dz-status dz-text-muted';
        }
        try {
            const res = await fetch('/api/server/' + encodeURIComponent(SERVER_ID) + '/dayz/server/restart', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ reason: 'Apply queued DayZ Workshop installs' }),
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.status !== 'failed' && data.status !== 'rejected') {
                if (status) {
                    status.textContent = '✓ ' + (data.message || 'Restart signal sent. The queued installs should start now.');
                    status.className = 'dz-status dz-text-green';
                }
            } else if (status) {
                status.textContent = '✗ ' + (data.message || ('Request failed (' + res.status + ')'));
                status.className = 'dz-status dz-text-red';
            }
        } catch (err) {
            if (status) {
                status.textContent = '✗ Network error: ' + err.message;
                status.className = 'dz-status dz-text-red';
            }
        }
    };

    window.pteroRemoveQueuedInstall = async function (workshopId) {
        const status = document.getElementById('ptero-install-status');
        if (!workshopId) {
            return;
        }
        if (!confirm('Remove ' + workshopId + ' from the install queue?')) {
            return;
        }
        installPollToken += 1;
        try {
            const res = await apiPost('remove', { workshop_id: workshopId, queue_only: true });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.status !== 'failed') {
                if (Array.isArray(data.queue)) {
                    renderQueueList(data.queue, data.queue.length > 0 && data.queue.every(function (entry) { return !!entry.installed; }));
                    if (data.queue.length > 0) {
                        startInstallPolling(data.queue.map(function (entry) { return entry.workshop_id; }));
                    }
                } else {
                    await resumeQueue();
                }
                if (status) {
                    status.classList.remove('dz-hidden');
                    status.textContent = data.message || 'Removed from queue.';
                    status.className = 'dz-status dz-text-green';
                }
                if (isBrowseModalOpen()) {
                    loadBrowseResults(browseState.term, browseState.page);
                }
                return;
            }

            if (status) {
                status.classList.remove('dz-hidden');
                status.textContent = '✗ ' + (data.message || ('Request failed (' + res.status + ')'));
                status.className = 'dz-status dz-text-red';
            }
        } catch (err) {
            if (status) {
                status.classList.remove('dz-hidden');
                status.textContent = '✗ Network error: ' + err.message;
                status.className = 'dz-status dz-text-red';
            }
        }
    };

    window.pteroModAction = async function (workshopId, action) {
        let payload = { workshop_id: workshopId };
        if (action === 'remove' && !confirm('Remove ' + workshopId + '? This drops it from the load order and deletes its folder from the server.')) {
            return;
        }
        try {
            let res = await apiPost(action, payload);
            let data = await res.json().catch(() => ({}));

            if (action === 'remove' && res.ok && data.status === 'dependency_prompt') {
                const choice = prompt(
                    'Other mods depend on this one.\nType:\n- cancel\n- remove_single\n- remove_all',
                    'cancel'
                );
                const normalized = (choice || 'cancel').trim().toLowerCase();
                if (normalized === 'cancel' || normalized === '') {
                    return;
                }
                payload = { workshop_id: workshopId, dependency_action: normalized };
                res = await apiPost(action, payload);
                data = await res.json().catch(() => ({}));
            }
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

    window.pteroReorderMods = async function (orderedIds) {
        if (!Array.isArray(orderedIds) || orderedIds.length === 0) {
            return;
        }
        try {
            const res = await apiPost('reorder', { ordered_ids: orderedIds });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.status === 'failed') {
                alert('Reorder failed: ' + (data.message || res.status));
                location.reload();
                return;
            }
            location.reload();
        } catch (err) {
            alert('Network error: ' + err.message);
            location.reload();
        }
    };
}());
</script>
