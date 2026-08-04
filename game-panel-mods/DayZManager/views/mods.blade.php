<section class="dz-card">
    <h2>Workshop Mod Manager</h2>
    <p class="dz-sub">
        Mods are read from this server: every <code>{{ '@' }}</code> folder in the server directory is inspected,
        and the load order comes from the <code>-mod=</code> launch parameter Pterodactyl boots the server with.
    </p>
    <dl class="dz-grid">
        @foreach ($settings as $key => $value)
            <div class="dz-stat">
                <dt>{{ str_replace('_', ' ', $key) }}</dt>
                <dd>{{ is_bool($value) ? ($value ? 'Enabled' : 'Disabled') : ($value === '' ? '—' : $value) }}</dd>
            </div>
        @endforeach
    </dl>
</section>

<section class="dz-card">
    <h2>Install Workshop Mod</h2>
    <p class="dz-sub">Enter a raw Workshop ID (e.g. <code>1559212036</code>) or a full Steam Workshop URL, or browse the Workshop below.</p>
    <div class="dz-form">
        <div class="dz-input-wrap" style="flex:1 1 18rem;min-width:0;">
            <input id="ptero-workshop-ref" class="dz-input" type="text" placeholder="Workshop ID or Steam Workshop URL" autocomplete="off" />
        </div>
        <button class="dz-btn" onclick="pteroInstallMod()">Install</button>
        <button class="dz-btn dz-btn-amber" onclick="pteroInstallMod(true)">Install + Restart</button>
        @if (!empty($browse_enabled))
            <button class="dz-btn dz-btn-ghost" onclick="pteroOpenBrowseModal()">Browse Workshop</button>
        @endif
    </div>
    <p class="dz-sub">Default install action only queues mods. Use restart actions when you're ready to apply queued installs.</p>
    <p id="ptero-install-status" class="dz-status dz-hidden"></p>
    <div id="ptero-install-queue" class="dz-queue-list dz-hidden"></div>
    <div id="ptero-queue-actions" class="dz-form dz-hidden">
        <button class="dz-btn dz-btn-amber" onclick="pteroRestartQueuedInstall()">Restart server to install queued mods</button>
    </div>
</section>

<div id="ptero-browse-modal" class="dz-modal dz-hidden">
    <div class="dz-modal-content">
        <div class="dz-modal-head">
            <h3>Browse DayZ Workshop</h3>
            <button type="button" class="dz-btn dz-btn-sm" onclick="pteroCloseBrowseModal()">✕ Close</button>
        </div>
        <div class="dz-form dz-browse-toolbar">
            <input id="ptero-browse-search" class="dz-input" type="text" placeholder="Search Workshop mods…"
                   onkeydown="if (event.key === 'Enter') { pteroBrowseSearch(); }" />
            <select id="ptero-browse-sort" class="dz-input" onchange="pteroBrowseApplyOptions()">
                <option value="most_popular">Most Popular</option>
                <option value="most_subscribed">Most Subscribed</option>
                <option value="last_updated">Last Updated</option>
                <option value="new">New</option>
            </select>
            <button class="dz-btn" onclick="pteroBrowseSearch()">Search</button>
            <button id="ptero-browse-queue-selected" class="dz-btn dz-btn-amber" onclick="pteroQueueCheckedMods()" disabled>Queue selected</button>
        </div>
        <p id="ptero-browse-message" class="dz-sub"></p>
        <div class="dz-browse-layout">
            <aside class="dz-browse-sidebar">
                <div class="dz-browse-sidebar-section">
                    <h4>Type</h4>
                    <div id="ptero-browse-filter-type" class="dz-browse-filter-group"></div>
                </div>
                <div class="dz-browse-sidebar-section">
                    <h4>Mod Type</h4>
                    <div id="ptero-browse-filter-mod-type" class="dz-browse-filter-group"></div>
                </div>
                <div class="dz-browse-sidebar-section">
                    <h4>Required DLC</h4>
                    <div id="ptero-browse-filter-required-dlc" class="dz-browse-filter-group"></div>
                </div>
            </aside>
            <div class="dz-browse-main">
                <div id="ptero-browse-grid" class="dz-browse-grid"></div>
                <div class="dz-browse-footer">
                    <button type="button" id="ptero-browse-prev" class="dz-btn dz-btn-ghost dz-btn-sm" onclick="pteroBrowsePage(-1)">← Previous</button>
                    <span id="ptero-browse-page-label" class="dz-sub">Page 1</span>
                    <button type="button" id="ptero-browse-next" class="dz-btn dz-btn-ghost dz-btn-sm" onclick="pteroBrowsePage(1)">Next →</button>
                </div>
            </div>
            <aside id="ptero-browse-details" class="dz-browse-details">
                <p class="dz-sub">Select a Workshop item to view details.</p>
            </aside>
        </div>
    </div>
</div>

<section class="dz-card">
    <h2>Installed Mods</h2>
    <p class="dz-sub">
        {{ count($installed_mods) }} mod(s) detected.
        <input id="dz-mod-filter" class="dz-input" type="search" placeholder="Filter by name, folder, or Workshop ID"
               oninput="pteroFilterMods(this.value)" />
    </p>
    @if (count($installed_mods) > 0)
        <p class="dz-sub">Drag a mod card to change its load order.</p>
        <div class="dz-mod-grid" id="dz-mod-grid">
            @foreach ($installed_mods as $mod)
                <div class="dz-mod-wrap" draggable="true"
                     data-mod-ref="{{ ($mod['workshop_id'] ?? '') !== '' ? $mod['workshop_id'] : ($mod['folder_name'] ?? '') }}"
                     data-search="{{ strtolower(($mod['title'] ?? '') . ' ' . ($mod['folder_name'] ?? '') . ' ' . ($mod['workshop_id'] ?? '')) }}">
                    {!! $component('mod-card', ['mod' => $mod, 'server_id' => $client_id]) !!}
                </div>
            @endforeach
        </div>
    @else
        <p class="dz-sub">
            No mods found. Either none are installed, or the Pterodactyl daemon for this node could not
            be reached — check the node status if you expect mods here.
        </p>
    @endif
</section>

<script>
window.pteroFilterMods = function (term) {
    const needle = (term || '').trim().toLowerCase();
    document.querySelectorAll('#dz-mod-grid .dz-mod-wrap').forEach(function (wrap) {
        const haystack = wrap.getAttribute('data-search') || '';
        wrap.style.display = needle === '' || haystack.indexOf(needle) !== -1 ? '' : 'none';
    });
};

(function () {
    const grid = document.getElementById('dz-mod-grid');
    if (!grid) {
        return;
    }

    let dragged = null;

    function currentOrder() {
        return Array.from(grid.querySelectorAll('.dz-mod-wrap')).map(function (wrap) {
            return wrap.getAttribute('data-mod-ref') || '';
        }).filter(function (ref) { return ref !== ''; });
    }

    grid.addEventListener('dragstart', function (event) {
        const wrap = event.target.closest('.dz-mod-wrap');
        if (!wrap) {
            return;
        }
        dragged = wrap;
        wrap.classList.add('dz-dragging');
        event.dataTransfer.effectAllowed = 'move';
        try {
            event.dataTransfer.setData('text/plain', wrap.getAttribute('data-mod-ref') || '');
        } catch (err) { /* Some browsers restrict setData outside real drags; ignore. */ }
    });

    grid.addEventListener('dragover', function (event) {
        if (!dragged) {
            return;
        }
        event.preventDefault();
        const target = event.target.closest('.dz-mod-wrap');
        if (!target || target === dragged) {
            return;
        }
        const rect = target.getBoundingClientRect();
        const before = (event.clientY - rect.top) < rect.height / 2;
        grid.insertBefore(dragged, before ? target : target.nextSibling);
    });

    grid.addEventListener('dragend', async function () {
        if (!dragged) {
            return;
        }
        dragged.classList.remove('dz-dragging');
        dragged = null;

        if (typeof window.pteroReorderMods === 'function') {
            await window.pteroReorderMods(currentOrder());
        }
    });
})();
</script>

<style>
.dz-mod-wrap { cursor: grab; }
.dz-mod-wrap.dz-dragging { opacity: 0.5; cursor: grabbing; }
</style>

{!! $component('mod-actions', [
    'server_id' => $server_id,
    'installed_workshop_ids' => array_values(array_filter(array_map(
        static fn (array $mod): string => trim((string) ($mod['workshop_id'] ?? '')),
        $installed_mods,
    ))),
]) !!}
