<section class="dz-card">
    <h2>Server Control</h2>
    <p class="dz-sub">Power the server and review the launch parameters Pterodactyl actually starts it with.</p>
    @if (!empty($live))
        <dl class="dz-grid" id="dz-query-stats">
            <div class="dz-stat">
                <dt>Query Status</dt>
                <dd id="dz-query-status" class="{{ $live['online'] ? 'dz-text-green' : 'dz-text-red' }}">{{ $live['online'] ? 'Online' : 'Offline' }}</dd>
            </div>
            <div class="dz-stat"><dt>Query Endpoint</dt><dd id="dz-query-endpoint">{{ $live['endpoint'] ?? 'Unknown' }}</dd></div>
            <div class="dz-stat"><dt>Players</dt><dd id="dz-query-players">{{ $live['player_count'] ?? (($live['players'] ?? 0) . ' / ' . ($live['max_players'] ?? 64)) }}</dd></div>
            <div class="dz-stat"><dt>Version</dt><dd id="dz-query-version">{{ $live['version'] ?? 'N/A' }}</dd></div>
        </dl>
        <div class="dz-form" style="margin-top:0.75rem;">
            <button class="dz-btn dz-btn-sm dz-btn-ghost" onclick="pteroRefreshQueryStatus()" id="dz-query-refresh-btn">Refresh Status</button>
            <span id="dz-query-refresh-msg" class="dz-status dz-hidden"></span>
        </div>
    @endif
</section>

@php
    $restartSchedule = $restart_schedule ?? ['enabled' => false, 'interval_minutes' => 360, 'next_restart_at' => null];
    $intervalHours = max(1, (int) (($restartSchedule['interval_minutes'] ?? 360) / 60));
    $warningMinuteOptions = [180, 120, 60, 30, 20, 10, 5, 2, 1];
    $warningMinutesEnabled = array_map('intval', $restartSchedule['warning_minutes_enabled'] ?? $warningMinuteOptions);
    $warningMessages = is_array($restartSchedule['warning_messages'] ?? null) ? $restartSchedule['warning_messages'] : [];
@endphp

<section class="dz-card">
    <h2>Power Controls</h2>
    <p class="dz-sub">Signals are sent to the Pterodactyl daemon that runs this server. Enter a reason to announce it in global chat before the signal is sent.</p>
    <div class="dz-form">
        <input id="dz-restart-reason" class="dz-input" type="text" placeholder="Reason (optional), e.g. Mod update applied" />
        <button class="dz-btn dz-btn-green" onclick="pteroPowerSignal('start')">Start</button>
        <button class="dz-btn" onclick="pteroPowerSignal('restart')">Restart</button>
        <button class="dz-btn dz-btn-amber" onclick="pteroPowerSignal('stop')">Stop</button>
        <button class="dz-btn dz-btn-red" onclick="pteroPowerSignal('kill')">Kill</button>
    </div>
    <p id="dz-restart-status" class="dz-status dz-hidden"></p>
</section>

<section class="dz-card">
    <h2>Scheduled Restarts</h2>
    <p class="dz-sub">Set restart timing, customize warning text, and choose which warning windows are broadcast.</p>
    <div class="dz-form">
        <label class="dz-sub" style="display:flex;align-items:center;gap:0.45rem;">
            <input id="dz-restart-enabled" type="checkbox" {{ !empty($restartSchedule['enabled']) ? 'checked' : '' }} />
            Enable scheduled restarts
        </label>
        <select id="dz-restart-interval" class="dz-input" style="max-width:12rem;">
            @foreach ([1, 2, 3, 4, 6, 8, 12, 24] as $hours)
                <option value="{{ $hours }}" {{ $intervalHours === $hours ? 'selected' : '' }}>{{ $hours }} hour{{ $hours === 1 ? '' : 's' }}</option>
            @endforeach
        </select>
        <button class="dz-btn" onclick="pteroSaveRestartSchedule()">Save schedule</button>
    </div>
    <dl class="dz-grid" style="margin-top:0.9rem;">
        @foreach ($warningMinuteOptions as $minutes)
            <div class="dz-stat">
                <dt>{{ $minutes }} minute warning</dt>
                <dd>
                    <label class="dz-sub" style="display:flex;align-items:center;gap:0.4rem;margin-bottom:0.45rem;">
                        <input type="checkbox" class="dz-warning-enabled" data-warning-minute="{{ $minutes }}" {{ in_array($minutes, $warningMinutesEnabled, true) ? 'checked' : '' }} />
                        Enabled
                    </label>
                    <input
                        class="dz-input dz-warning-message"
                        data-warning-minute="{{ $minutes }}"
                        type="text"
                        placeholder="Server restart in {time}."
                        value="{{ $warningMessages[(string) $minutes] ?? '' }}"
                    />
                </dd>
            </div>
        @endforeach
    </dl>
    <p class="dz-sub" style="margin-top:0.65rem;">
        Use <code>{time}</code> in messages to inject the formatted countdown (for example, "10 minutes").
    </p>
    <p class="dz-sub" style="margin-top:0.35rem;">
        Recommended: keep 10, 2, and 1 minute warnings enabled so players can log out and save.
    </p>
    <p id="dz-restart-next" class="dz-sub">
        Next restart:
        {{ !empty($restartSchedule['next_restart_at']) ? $restartSchedule['next_restart_at'] : 'Not scheduled' }}
    </p>
    <p id="dz-restart-schedule-status" class="dz-status dz-hidden"></p>
</section>

<section class="dz-card">
    <h2>Timed Restart</h2>
    <p class="dz-sub">
        Restart the server after a countdown, with warning messages sent to global chat at each configured
        warning interval. Uses the same warning texts set in Scheduled Restarts above.
    </p>
    @php $timedRestartAt = $restart_schedule['timed_restart_at'] ?? null; @endphp
    @if (!empty($timedRestartAt))
        <p class="dz-sub dz-text-amber" id="dz-timed-restart-active">
            ⏳ Timed restart scheduled for: <strong>{{ $timedRestartAt }}</strong>
        </p>
        <div class="dz-form" style="margin-top:0.5rem;">
            <button class="dz-btn dz-btn-red" onclick="pteroCancelTimedRestart()">Cancel Timed Restart</button>
        </div>
    @else
        <p class="dz-sub dz-hidden dz-text-amber" id="dz-timed-restart-active">
            ⏳ Timed restart is active.
        </p>
    @endif
    <div class="dz-form" style="margin-top:0.75rem;" id="dz-timed-restart-form">
        <select id="dz-timed-restart-minutes" class="dz-input" style="max-width:12rem;">
            @foreach ([5, 10, 15, 20, 30, 60, 120] as $min)
                <option value="{{ $min }}">{{ $min }} minute{{ $min === 1 ? '' : 's' }}</option>
            @endforeach
        </select>
        <input id="dz-timed-restart-reason" class="dz-input" type="text" placeholder="Reason (optional, announced in global chat)" style="max-width:22rem;" />
        <button class="dz-btn dz-btn-amber" onclick="pteroStartTimedRestart()">Restart in …</button>
        <button class="dz-btn dz-btn-red dz-hidden" id="dz-timed-cancel-btn" onclick="pteroCancelTimedRestart()">Cancel</button>
    </div>
    <p id="dz-timed-restart-status" class="dz-status dz-hidden"></p>
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

<script>
(function () {
    const SERVER_ID = @json($server_id);

    window.pteroRefreshQueryStatus = async function () {
        const btn = document.getElementById('dz-query-refresh-btn');
        const msg = document.getElementById('dz-query-refresh-msg');
        const statusEl = document.getElementById('dz-query-status');
        const endpointEl = document.getElementById('dz-query-endpoint');
        const playersEl = document.getElementById('dz-query-players');
        const versionEl = document.getElementById('dz-query-version');

        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Refreshing…';
        }
        if (msg) {
            msg.classList.remove('dz-hidden');
            msg.textContent = 'Querying server…';
            msg.className = 'dz-status dz-text-muted';
        }

        try {
            const res = await fetch('/api/server/' + encodeURIComponent(SERVER_ID) + '/dayz/server/query-status', {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const data = await res.json().catch(() => ({}));

            if (res.ok) {
                const online = !!data.online;
                if (statusEl) {
                    statusEl.textContent = online ? 'Online' : 'Offline';
                    statusEl.className = online ? 'dz-text-green' : 'dz-text-red';
                }
                if (endpointEl) { endpointEl.textContent = data.endpoint || 'Unknown'; }
                if (playersEl) {
                    const pc = data.player_count || ((data.players || 0) + ' / ' + (data.max_players || 64));
                    playersEl.textContent = pc;
                }
                if (versionEl) { versionEl.textContent = data.version || 'N/A'; }
                if (msg) {
                    msg.textContent = online ? '✓ Server is online.' : '✗ Server is offline or unreachable.';
                    msg.className = 'dz-status ' + (online ? 'dz-text-green' : 'dz-text-red');
                }
            } else {
                if (msg) {
                    msg.textContent = '✗ Query failed (' + res.status + ')';
                    msg.className = 'dz-status dz-text-red';
                }
            }
        } catch (err) {
            if (msg) {
                msg.textContent = '✗ Network error: ' + err.message;
                msg.className = 'dz-status dz-text-red';
            }
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'Refresh Status';
            }
        }
    };

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

    window.pteroStartTimedRestart = async function () {
        const status = document.getElementById('dz-timed-restart-status');
        const active = document.getElementById('dz-timed-restart-active');
        const cancelBtn = document.getElementById('dz-timed-cancel-btn');
        const minutesEl = document.getElementById('dz-timed-restart-minutes');
        const reasonEl = document.getElementById('dz-timed-restart-reason');
        const meta = document.querySelector('meta[name="csrf-token"]');
        const minutes = minutesEl ? parseInt(minutesEl.value, 10) : 10;
        const reason = reasonEl ? reasonEl.value.trim() : '';

        if (!confirm('Schedule a server restart in ' + minutes + ' minute' + (minutes === 1 ? '' : 's') + '? '
            + 'Warning messages will be sent to global chat at the configured warning intervals.')) {
            return;
        }

        if (status) {
            status.classList.remove('dz-hidden');
            status.textContent = 'Scheduling timed restart…';
            status.className = 'dz-status dz-text-muted';
        }

        try {
            const res = await fetch('/api/server/' + encodeURIComponent(SERVER_ID) + '/dayz/server/timed-restart', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': meta ? meta.content : '',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ minutes: minutes, reason: reason }),
            });
            const data = await res.json().catch(() => ({}));

            if (res.ok && data.status === 'scheduled') {
                if (active) {
                    active.textContent = '⏳ Timed restart scheduled for: ' + (data.timed_restart_at || '');
                    active.classList.remove('dz-hidden');
                }
                if (cancelBtn) { cancelBtn.classList.remove('dz-hidden'); }
                if (reasonEl) { reasonEl.value = ''; }
                if (status) {
                    status.textContent = '✓ ' + (data.message || 'Timed restart scheduled.');
                    status.className = 'dz-status dz-text-green';
                }
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

    window.pteroCancelTimedRestart = async function () {
        const status = document.getElementById('dz-timed-restart-status');
        const active = document.getElementById('dz-timed-restart-active');
        const cancelBtn = document.getElementById('dz-timed-cancel-btn');
        const meta = document.querySelector('meta[name="csrf-token"]');

        if (!confirm('Cancel the scheduled timed restart?')) { return; }

        if (status) {
            status.classList.remove('dz-hidden');
            status.textContent = 'Cancelling timed restart…';
            status.className = 'dz-status dz-text-muted';
        }

        try {
            const res = await fetch('/api/server/' + encodeURIComponent(SERVER_ID) + '/dayz/server/timed-restart/cancel', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': meta ? meta.content : '',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({}),
            });
            const data = await res.json().catch(() => ({}));

            if (res.ok && data.status === 'cancelled') {
                if (active) { active.classList.add('dz-hidden'); }
                if (cancelBtn) { cancelBtn.classList.add('dz-hidden'); }
                if (status) {
                    status.textContent = '✓ ' + (data.message || 'Timed restart cancelled.');
                    status.className = 'dz-status dz-text-green';
                }
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

    window.pteroSaveRestartSchedule = async function () {
        const status = document.getElementById('dz-restart-schedule-status');
        const enabled = document.getElementById('dz-restart-enabled');
        const interval = document.getElementById('dz-restart-interval');
        const next = document.getElementById('dz-restart-next');
        const meta = document.querySelector('meta[name="csrf-token"]');
        const warningEnabled = Array.from(document.querySelectorAll('.dz-warning-enabled'));
        const warningMessages = {};
        const warningMinutesEnabled = warningEnabled
            .filter(function (input) { return input.checked; })
            .map(function (input) { return parseInt(input.dataset.warningMinute || '0', 10); })
            .filter(function (minute) { return minute > 0; });

        document.querySelectorAll('.dz-warning-message').forEach(function (input) {
            const minute = parseInt(input.dataset.warningMinute || '0', 10);
            if (minute <= 0) {
                return;
            }
            const value = (input.value || '').trim();
            if (value !== '') {
                warningMessages[String(minute)] = value;
            }
        });

        const recommendedWarnings = [10, 2, 1];
        const missingRecommended = recommendedWarnings.filter(function (minute) {
            return warningMinutesEnabled.indexOf(minute) === -1;
        });

        if (missingRecommended.length > 0 && !confirm(
            'We recommend keeping 10, 2, and 1 minute warnings enabled so players can log out and save. Continue anyway?'
        )) {
            return;
        }

        if (status) {
            status.classList.remove('dz-hidden');
            status.textContent = 'Saving restart schedule…';
            status.className = 'dz-status dz-text-muted';
        }

        try {
            const res = await fetch('/api/server/' + encodeURIComponent(SERVER_ID) + '/dayz/server/restart-schedule', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': meta ? meta.content : '',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    enabled: !!(enabled && enabled.checked),
                    interval_hours: interval ? interval.value : '6',
                    warning_minutes_enabled: warningMinutesEnabled,
                    warning_messages: warningMessages,
                }),
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.status !== 'failed') {
                if (next) {
                    next.textContent = 'Next restart: ' + (data.next_restart_at || 'Not scheduled');
                }
                // Repopulate message inputs with whatever was actually stored.
                if (data.warning_messages && typeof data.warning_messages === 'object') {
                    document.querySelectorAll('.dz-warning-message').forEach(function (input) {
                        const minute = input.dataset.warningMinute;
                        if (minute && Object.prototype.hasOwnProperty.call(data.warning_messages, minute)) {
                            input.value = data.warning_messages[minute];
                        }
                    });
                }
                if (status) {
                    status.textContent = '✓ ' + (data.message || 'Schedule saved.');
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

    window.pteroTickRestartSchedule = async function () {
        const enabled = document.getElementById('dz-restart-enabled');
        const active = document.getElementById('dz-timed-restart-active');
        const cancelBtn = document.getElementById('dz-timed-cancel-btn');
        const next = document.getElementById('dz-restart-next');
        const timedRestartActive = active && !active.classList.contains('dz-hidden');
        const scheduledEnabled = enabled && enabled.checked;

        // Tick whenever there is something to process: an active timed restart
        // OR scheduled restarts are enabled. This ensures timed restarts fire
        // even when scheduled restarts are turned off.
        if (!scheduledEnabled && !timedRestartActive) {
            return;
        }

        const meta = document.querySelector('meta[name="csrf-token"]');
        try {
            const res = await fetch('/api/server/' + encodeURIComponent(SERVER_ID) + '/dayz/server/restart-schedule/tick', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': meta ? meta.content : '',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({}),
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok) {
                if (next && data.next_restart_at) {
                    next.textContent = 'Next restart: ' + data.next_restart_at;
                }
                // If the timed restart completed or was cleared server-side, hide the banner.
                if (active && data.timed_restart_at === null && timedRestartActive) {
                    active.classList.add('dz-hidden');
                    if (cancelBtn) { cancelBtn.classList.add('dz-hidden'); }
                }
            }
        } catch (err) {
            // Scheduler tick failures are non-fatal.
        }
    };

    setInterval(window.pteroTickRestartSchedule, 60000);
}());
</script>
