<section class="dz-card">
    <h2>Server Control</h2>
    <p class="dz-sub">Power the server and review the launch parameters Pterodactyl actually starts it with.</p>
    @if (!empty($live))
        <dl class="dz-grid">
            <div class="dz-stat">
                <dt>Query Status</dt>
                <dd class="{{ $live['online'] ? 'dz-text-green' : 'dz-text-red' }}">{{ $live['online'] ? 'Online' : 'Offline' }}</dd>
            </div>
            <div class="dz-stat"><dt>Query Endpoint</dt><dd>{{ $live['endpoint'] ?? 'Unknown' }}</dd></div>
            <div class="dz-stat"><dt>Players</dt><dd>{{ $live['players'] === null ? 'N/A' : $live['players'] . ' / ' . ($live['max_players'] ?? '?') }}</dd></div>
            <div class="dz-stat"><dt>Version</dt><dd>{{ $live['version'] ?? 'N/A' }}</dd></div>
        </dl>
    @endif
</section>

<section class="dz-card">
    <h2>Current Launch Parameters</h2>
    @if ($startup_source === 'pterodactyl')
        <p class="dz-sub">Loaded from the Pterodactyl startup command and this server's egg variables.</p>
        <pre class="dz-pre">{{ $startup_rendered !== '' ? $startup_rendered : $startup_raw }}</pre>

        @if (count($startup_parameters) > 0)
            <ul class="dz-tags">
                @foreach ($startup_parameters as $parameter)
                    <li>{{ $parameter }}</li>
                @endforeach
            </ul>
        @endif

        <dl class="dz-grid">
            <div class="dz-stat">
                <dt>Mod Parameter</dt>
                <dd>{{ $launch_parameters !== '' ? $launch_parameters : '—' }}</dd>
            </div>
            <div class="dz-stat"><dt>Client Mods</dt><dd>{{ $mod_count }}</dd></div>
            <div class="dz-stat">
                <dt>Server-only Mods</dt>
                <dd>{{ count($server_mods) > 0 ? implode(', ', $server_mods) : '—' }}</dd>
            </div>
        </dl>
    @else
        <p class="dz-sub">
            The startup command could not be read from Pterodactyl. Check that the module can reach the
            panel database and that the server still exists.
        </p>
    @endif
</section>

@if (count($startup_variables) > 0)
    <section class="dz-card">
        <h2>Startup Variables</h2>
        <p class="dz-sub">Egg variables resolved for this server, in the order they are substituted.</p>
        <ul class="dz-list">
            @foreach ($startup_variables as $name => $value)
                <li>
                    <span>{{ $name }}</span>
                    <span class="dz-text-muted">{{ $value === '' ? '—' : $value }}</span>
                </li>
            @endforeach
        </ul>
    </section>
@endif

<section class="dz-card">
    <h2>Power Controls</h2>
    <p class="dz-sub">Signals are sent to the Pterodactyl daemon that runs this server.</p>
    <div class="dz-form">
        <input id="dz-restart-reason" class="dz-input" type="text" placeholder="Reason (optional), e.g. Mod update applied" />
        <button class="dz-btn dz-btn-green" onclick="pteroPowerSignal('start')">Start</button>
        <button class="dz-btn" onclick="pteroPowerSignal('restart')">Restart</button>
        <button class="dz-btn dz-btn-amber" onclick="pteroPowerSignal('stop')">Stop</button>
        <button class="dz-btn dz-btn-red" onclick="pteroPowerSignal('kill')">Kill</button>
    </div>
    <p id="dz-restart-status" class="dz-status dz-hidden"></p>
</section>

<script>
(function () {
    const SERVER_ID = @json($server_id);

    window.pteroPowerSignal = async function (signal) {
        const status = document.getElementById('dz-restart-status');
        const reason = document.getElementById('dz-restart-reason');
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (!confirm('Send "' + signal + '" to this server?')) {
            return;
        }
        status.classList.remove('dz-hidden');
        status.textContent = 'Sending ' + signal + '…';
        status.className = 'dz-status dz-text-muted';
        const endpoint = signal === 'restart' ? 'restart' : 'power';
        try {
            const res = await fetch('/api/server/' + encodeURIComponent(SERVER_ID) + '/dayz/server/' + endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': meta ? meta.content : '',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ signal: signal, reason: reason ? reason.value : '' }),
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.status !== 'failed' && data.status !== 'rejected') {
                status.textContent = '✓ ' + (data.message || 'Signal sent.');
                status.className = 'dz-status dz-text-green';
            } else {
                status.textContent = '✗ ' + (data.message || ('Request failed (' + res.status + ')'));
                status.className = 'dz-status dz-text-red';
            }
        } catch (err) {
            status.textContent = '✗ Network error: ' + err.message;
            status.className = 'dz-status dz-text-red';
        }
    };

    window.pteroRestartServer = function () {
        return window.pteroPowerSignal('restart');
    };
}());
</script>
