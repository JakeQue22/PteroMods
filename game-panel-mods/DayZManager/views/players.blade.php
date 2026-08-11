<section class="dz-card">
    <h2>Player Manager</h2>
    <p class="dz-sub">View persisted character records and currently online players, then manage the ban list, whitelist, and priority queue.</p>
</section>

<section class="dz-card">
    <h2>Players</h2>
    <p class="dz-sub">
        {{ count($persisted_players ?? []) }} persisted player record(s)
        @if (!empty($persistence_source_path))
            · Source: <code>{{ $persistence_source_path }}</code>
        @endif
        @if (!empty($map_definition['name']))
            · Map: {{ $map_definition['name'] }}
        @endif
    </p>

    @if (!empty($map_definition['locations']))
        <ul class="dz-tags">
            @foreach (array_slice($map_definition['locations'], 0, 8) as $location)
                <li>{{ $location['name'] }} ({{ (int) $location['x'] }}, {{ (int) $location['z'] }})</li>
            @endforeach
        </ul>
    @endif

    <div class="dz-list">
        @forelse ($persisted_players ?? [] as $player)
            <div class="dz-player-card">
                <div class="dz-player-card__head">
                    <div>
                        <strong>{{ $player['name'] ?: ($player['player_id'] ?? $player['steam64']) }}</strong>
                        <div class="dz-sub">
                            {{ $player['player_id'] ?? $player['steam64'] }}
                            @if (!empty($player['last_seen_at']))
                                · Last seen {{ $player['last_seen_at'] }}
                            @endif
                        </div>
                    </div>
                    <span class="dz-badge {{ ($player['position_status'] ?? '') === 'valid' ? 'dz-badge-on' : 'dz-badge-off' }}">
                        @if (($player['position_status'] ?? '') === 'valid')
                            In Bounds
                        @elseif (($player['position_status'] ?? '') === 'out_of_bounds')
                            Out of Bounds
                        @else
                            Position Unknown
                        @endif
                    </span>
                </div>

                <dl class="dz-browse-meta" style="margin-top:0.75rem;">
                    <div><dt>Coordinates</dt><dd>{{ $player['x'] ?? '—' }}, {{ $player['z'] ?? '—' }}</dd></div>
                    <div><dt>Height</dt><dd>{{ $player['y'] ?? '—' }}</dd></div>
                    <div><dt>Status</dt><dd>
                        @if (array_key_exists('alive', $player) && $player['alive'] !== null)
                            {{ !empty($player['alive']) ? 'Alive' : 'Dead / last known dead' }}
                        @else
                            Unknown
                        @endif
                    </dd></div>
                </dl>

                <div class="dz-mod-actions" style="margin-top:0.9rem;">
                    <button class="dz-btn dz-btn-red" type="button" onclick="pteroBanPlayer({{ json_encode((string) ($player['player_id'] ?? $player['steam64'] ?? ''), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }})">Ban</button>
                </div>
            </div>
        @empty
            <div class="dz-empty">
                @if (($persistence_status ?? '') === 'not_found')
                    No readable characters.db/players.db file was found in common DayZ persistence paths.
                @else
                    No persisted player records were found.
                @endif
            </div>
        @endforelse
    </div>
</section>

<section class="dz-card">
    <h2>Live Players</h2>
    <p class="dz-sub">{{ (int) ($online_count ?? 0) }} online now.</p>

    <div class="dz-list">
        @forelse ($live_players ?? [] as $player)
            <div class="dz-player-card">
                <div class="dz-player-card__head">
                    <div>
                        <strong>{{ $player['name'] ?? $player['steam64'] }}</strong>
                        <div class="dz-sub">{{ $player['steam64'] ?? '—' }}</div>
                    </div>
                    <span class="dz-badge dz-badge-on">Online</span>
                </div>

                <dl class="dz-browse-meta" style="margin-top:0.75rem;">
                    <div><dt>Coordinates</dt><dd>{{ $player['x'] ?? '—' }}, {{ $player['z'] ?? '—' }}</dd></div>
                    <div><dt>Height</dt><dd>{{ $player['y'] ?? '—' }}</dd></div>
                    <div><dt>Direction</dt><dd>{{ isset($player['direction']) ? $player['direction'] . '°' : '—' }}</dd></div>
                    <div><dt>Status</dt><dd>{{ !empty($player['alive']) ? 'Alive' : 'Dead / reported dead' }}</dd></div>
                    <div><dt>Health</dt><dd>{{ $player['health'] ?? 'N/A' }}</dd></div>
                </dl>

                <div class="dz-mod-actions" style="margin-top:0.9rem;">
                    @if (!empty($player['steam64']))
                        <button class="dz-btn dz-btn-amber" type="button" onclick="pteroKickPlayer('{{ $player['steam64'] }}')">Kick</button>
                        <button class="dz-btn dz-btn-red" type="button" onclick="pteroBanPlayer('{{ $player['steam64'] }}')">Ban</button>
                    @endif
                </div>
            </div>
        @empty
            <div class="dz-empty">No players are currently online.</div>
        @endforelse
    </div>

    <p id="dz-player-action-status" class="dz-status dz-hidden" style="margin-top:0.75rem;"></p>
</section>

@foreach (['ban' => 'Ban List', 'whitelist' => 'Whitelist', 'priority' => 'Priority Queue'] as $type => $label)
    <section class="dz-card">
        <h2>{{ $label }}</h2>
        <p class="dz-sub">{{ count($player_lists[$type] ?? []) }} entr(y/ies).</p>
        <ul class="dz-list">
            @forelse ($player_lists[$type] ?? [] as $entry)
                <li>
                    <span>{{ $entry['player_id'] }}</span>
                    @if (!empty($entry['note']))
                        <span class="dz-text-muted">{{ $entry['note'] }}</span>
                    @endif
                </li>
            @empty
                <li class="dz-empty">No entries in this list.</li>
            @endforelse
        </ul>
    </section>
@endforeach

<script>
(function () {
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    function apiBase() {
        var m = window.location.pathname.match(/^\/(?:servers?|admin\/servers\/view)\/([^/]+)\/dayz/);
        return m ? '/api/server/' + encodeURIComponent(m[1]) : '';
    }

    function req(method, path, body) {
        var headers = {
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        };
        var opts = { method: method, headers: headers };
        if (body) {
            headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
        return fetch(apiBase() + path, opts).then(function (r) { return r.json(); });
    }

    function setStatus(msg, ok) {
        var el = document.getElementById('dz-player-action-status');
        if (!el) { return; }
        el.textContent = msg;
        el.className = 'dz-status' + (ok === false ? ' dz-status-error' : '');
    }

    window.pteroKickPlayer = function (playerId) {
        if (!confirm('Kick this player from the server now?')) { return; }
        setStatus('Sending kick command…');
        req('POST', '/dayz/player-actions/kick', { player_id: playerId })
            .then(function (d) { setStatus(d.message || (d.status === 'dispatched' ? 'Kick command sent.' : 'Kick failed.'), d.status === 'dispatched' ? undefined : false); })
            .catch(function () { setStatus('Kick request failed.', false); });
    };

    window.pteroBanPlayer = function (playerId) {
        var note = window.prompt('Optional ban note:', '');
        if (note === null) { return; }
        setStatus('Adding player to ban list…');
        req('POST', '/dayz/players/ban', { player_id: playerId, note: note })
            .then(function (d) { setStatus(d.message || (d.status === 'saved' ? 'Player added to ban list.' : 'Ban failed.'), d.status === 'saved' ? undefined : false); })
            .catch(function () { setStatus('Ban request failed.', false); });
    };
}());
</script>
