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
        load order). Manage them in detail on the <a href="{{ $base_url }}/mods">Workshop Mods</a> page.
    </p>

    <div class="dz-form">
        <input id="ptero-workshop-ref" class="dz-input" type="text"
               placeholder="Workshop ID (e.g. 1559212036) or Steam Workshop URL" />
        <button class="dz-btn" onclick="pteroInstallMod()">Install</button>
    </div>
    <p id="ptero-install-status" class="dz-status dz-hidden"></p>

    @if (count($installed_mods) > 0)
        <div class="dz-mod-grid">
            @foreach ($installed_mods as $mod)
                {!! $component('mod-card', ['mod' => $mod, 'server_id' => $server_id]) !!}
            @endforeach
        </div>
    @else
        <p class="dz-sub">
            No mods found in the server directory. Install a mod above, or check that the daemon is
            reachable if mods are already installed.
        </p>
    @endif
</section>

{!! $component('mod-actions', ['server_id' => $server_id]) !!}
