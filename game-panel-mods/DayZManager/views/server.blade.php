<section class="dz-card">
    <h2>Server Control</h2>
    <p class="dz-sub">Restart the server and review the active launch parameters.</p>
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
    @if ($launch_parameters)
        <pre class="dz-pre">{{ $launch_parameters }}</pre>
        <p class="dz-sub">{{ $mod_count }} enabled mod(s) included.</p>
    @else
        <p class="dz-sub">No mods enabled — launch parameters are empty.</p>
    @endif
</section>

<section class="dz-card">
    <h2>Restart Server</h2>
    <p class="dz-sub">Queues a graceful restart. Active players will be notified before the server goes offline.</p>
    <div class="dz-form">
        <input id="dz-restart-reason" class="dz-input" type="text" placeholder="Reason (optional), e.g. Mod update applied" />
        <button class="dz-btn dz-btn-red" onclick="pteroRestartServer()">Restart Now</button>
    </div>
    <p id="dz-restart-status" class="dz-status dz-hidden"></p>
</section>

<script>
(function () {
    const SERVER_ID = @json($server_id);

    window.pteroRestartServer = async function () {
        const status = document.getElementById('dz-restart-status');
        const reason = document.getElementById('dz-restart-reason');
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (!confirm('Queue a server restart?')) {
            return;
        }
        status.textContent = 'Queuing restart…';
        status.className = 'dz-status dz-text-muted';
        try {
            const res = await fetch('/api/server/' + encodeURIComponent(SERVER_ID) + '/dayz/server/restart', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': meta ? meta.content : '',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ reason: reason ? reason.value : '' }),
            });
            if (res.ok) {
                status.textContent = '✓ Restart queued.';
                status.className = 'dz-status dz-text-green';
            } else {
                status.textContent = '✗ Restart request failed (' + res.status + ').';
                status.className = 'dz-status dz-text-red';
            }
        } catch (err) {
            status.textContent = '✗ Network error: ' + err.message;
            status.className = 'dz-status dz-text-red';
        }
    };
}());
</script>
