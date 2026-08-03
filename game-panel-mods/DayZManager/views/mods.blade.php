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
        <div class="dz-form">
            <input id="ptero-browse-search" class="dz-input" type="text" placeholder="Search Workshop mods…"
                   onkeydown="if (event.key === 'Enter') { pteroBrowseSearch(); }" />
            <button class="dz-btn" onclick="pteroBrowseSearch()">Search</button>
        </div>
        <p id="ptero-browse-message" class="dz-sub"></p>
        <div id="ptero-browse-grid" class="dz-browse-grid"></div>
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
        <div class="dz-mod-grid" id="dz-mod-grid">
            @foreach ($installed_mods as $mod)
                <div class="dz-mod-wrap" data-search="{{ strtolower(($mod['title'] ?? '') . ' ' . ($mod['folder_name'] ?? '') . ' ' . ($mod['workshop_id'] ?? '')) }}">
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
</script>

{!! $component('mod-actions', ['server_id' => $server_id]) !!}
