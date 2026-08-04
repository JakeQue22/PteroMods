<section class="dz-card">
    <h2>Server Overview</h2>
    <p class="dz-sub">
        Status and resource usage come from the Pterodactyl daemon
        ({{ $status_source }}); map, player count, and version are read live from the game server
        @if ($query_endpoint !== 'Unknown' && $query_endpoint !== null)
            (query endpoint <code>{{ $query_endpoint }}</code>).
        @else
            once a Steam query port allocation is available.
        @endif
        @unless ($query_online)
            The server did not answer the last Steam query, so those values show as N/A.
        @endunless
    </p>
    <dl class="dz-grid">
        @foreach ([
            'Server Name'     => $server_name,
            'Server Status'   => $server_status,
            'Connect Address' => $connection_address,
            'Installed Mods'  => $installed_mods_count . ' (' . $enabled_mods_count . ' enabled)',
            'CPU'             => $cpu,
            'RAM'             => $ram,
            'Disk'            => $disk,
            'Uptime'          => $uptime,
            'Current Map'     => $current_map,
            'Player Count'    => $player_count,
            'Server Version'  => $server_version,
        ] as $label => $value)
            @php
                $statusClass = $label !== 'Server Status' ? '' : match (strtolower((string) $value)) {
                    'running'          => 'dz-text-green',
                    'offline',
                    'stopping',
                    'suspended',
                    'install failed'   => 'dz-text-red',
                    'starting',
                    'installing',
                    'restoring backup' => 'dz-text-amber',
                    default            => '',
                };
            @endphp
            <div class="dz-stat">
                <dt>{{ $label }}</dt>
                <dd class="{{ $statusClass }}">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>
</section>

<section class="dz-card">
    <h2>Workshop Mods</h2>
    <p class="dz-sub">
        {{ $installed_mods_count }} mod(s) detected on this server ({{ $enabled_mods_count }} in the
        load order). Manage them in detail on the <a href="{{ $base_url }}/mods" target="_blank" rel="noopener noreferrer">Workshop Mods</a> page.
    </p>

    <div class="dz-form">
        <input id="ptero-workshop-ref" class="dz-input" type="text"
               placeholder="Workshop ID (e.g. 1559212036) or Steam Workshop URL" />
        <button class="dz-btn" onclick="pteroInstallMod()">Queue</button>
    </div>
    <p id="ptero-install-status" class="dz-status dz-hidden"></p>

    @if (count($installed_mods) > 0)
        <p class="dz-sub">Drag a mod card to change its load order.</p>
        <div class="dz-mod-grid" id="dz-dashboard-mod-grid">
            @foreach ($installed_mods as $mod)
                <div class="dz-mod-wrap" draggable="true"
                     data-mod-ref="{{ ($mod['workshop_id'] ?? '') !== '' ? $mod['workshop_id'] : ($mod['folder_name'] ?? '') }}">
                    {!! $component('mod-card', ['mod' => $mod, 'server_id' => $client_id]) !!}
                </div>
            @endforeach
        </div>
    @else
        <p class="dz-sub">
            No mods found in the server directory. Install a mod above, or check that the daemon is
            reachable if mods are already installed.
        </p>
    @endif
</section>

{!! $component('mod-actions', [
    'server_id' => $server_id,
    'installed_workshop_ids' => array_values(array_filter(array_map(
        static fn (array $mod): string => trim((string) ($mod['workshop_id'] ?? '')),
        $installed_mods,
    ))),
]) !!}

<script>
(function () {
    const grid = document.getElementById('dz-dashboard-mod-grid');
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
}());
</script>

<style>
.dz-mod-wrap { cursor: grab; }
.dz-mod-wrap.dz-dragging { opacity: 0.5; cursor: grabbing; }
</style>
