<section class="dz-card">
    <h2>Server Overview</h2>
    <p class="dz-sub">
        Map, player count, and version are read live from the game server
        @if ($query_endpoint !== 'Unknown' && $query_endpoint !== null)
            (query endpoint <code>{{ $query_endpoint }}</code>).
        @else
            once a Steam query port allocation is available.
        @endif
        @unless ($query_online)
            The server did not answer the last query, so live values show as N/A.
        @endunless
    </p>
    <dl class="dz-grid">
        @foreach ([
            'Server Name'    => $server_name,
            'Server Status'  => $server_status,
            'Installed Mods' => $installed_mods_count,
            'CPU'            => $cpu,
            'RAM'            => $ram,
            'Disk'           => $disk,
            'Current Map'    => $current_map,
            'Player Count'   => $player_count,
            'Server Version' => $server_version,
        ] as $label => $value)
            @php
                $statusClass = $label !== 'Server Status' ? '' : match (strtolower((string) $value)) {
                    'running'          => 'dz-text-green',
                    'offline',
                    'suspended',
                    'install failed'   => 'dz-text-red',
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
        {{ $installed_mods_count }} mod(s) installed. Manage them in detail on the
        <a href="{{ $base_url }}/mods">Workshop Mods</a> page.
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
                {!! $component('mod-card', ['mod' => $mod]) !!}
            @endforeach
        </div>
    @else
        <p class="dz-sub">No mods installed yet. Use the form above to install your first Workshop mod.</p>
    @endif
</section>

{!! $component('mod-actions', ['server_id' => $server_id]) !!}
