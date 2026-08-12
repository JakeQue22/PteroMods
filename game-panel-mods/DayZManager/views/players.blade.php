<section class="dz-card">
    <h2>Player Manager</h2>
    <p class="dz-sub">
        Every known player of this server, merged from <code>players.db</code> (account identity),
        <code>characters.db</code> (character state) and the live map bridge (online players).
    </p>
</section>

<section class="dz-card">
    <h2>Players</h2>
    <p class="dz-sub">
        {{ count($persisted_players ?? []) }} known player(s) · {{ (int) ($online_count ?? 0) }} online now
        @if (!empty($persistence_source_paths))
            · Persistence: @foreach ($persistence_source_paths as $path)<code>{{ $path }}</code>@if (!$loop->last) · @endif @endforeach
        @elseif (!empty($persistence_source_path))
            · Persistence: <code>{{ $persistence_source_path }}</code>
        @endif
        @if (!empty($map_definition['name']))
            · Map: {{ $map_definition['name'] }}
        @endif
    </p>

    <div class="dz-form">
        <input id="dz-player-search" class="dz-input" type="search" placeholder="Search by nickname, Steam64 or DayZ UID" />
    </div>

    <div class="dz-list" id="dz-player-list">
        @forelse ($persisted_players ?? [] as $player)
            @php
                $steam64 = $player['steam64'] ?? null;
                $playerUid = $player['player_uid'] ?? null;
                $playerId = (string) ($player['player_id'] ?? $steam64 ?? '');
                $uid = $playerUid;
                // Action target: prefer real Steam64, else the DayZ UID/player_id
                $actionId = $steam64 ?? $playerId;
                $hasInventory = !empty($player['inventory']) && (is_array($player['inventory']) || (is_string($player['inventory']) && trim($player['inventory']) !== ''));
                $inventoryJson = $hasInventory ? json_encode($player['inventory'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
                // Normalise timestamps to DD-MM-YYYY HH:MM
                $fmtDate = fn($ts) => $ts ? date('d-m-Y H:i', strtotime($ts)) : null;
                // Normalise health: older bridge wrote 0-10000, current writes 0-100
                $rawHealth = isset($player['health']) && $player['health'] !== null ? (float) $player['health'] : null;
                $health = $rawHealth !== null ? ($rawHealth > 100 ? round($rawHealth / 100, 1) : round($rawHealth, 1)) : null;
            @endphp
            <div class="dz-player-card" data-search="{{ strtolower(trim(($player['name'] ?? '') . ' ' . ($steam64 ?? '') . ' ' . $playerId . ' ' . ($uid ?? ''))) }}">
                <div class="dz-player-card__head">
                    <div>
                        <strong>{{ $player['name'] ?: $playerId }}</strong>
                        <div class="dz-sub">
                            @if ($steam64)
                                Steam64:
                                <a href="https://steamcommunity.com/profiles/{{ $steam64 }}" target="_blank" rel="noopener noreferrer">{{ $steam64 }}</a>
                            @elseif ($uid)
                                DayZ UID: <code>{{ $uid }}</code>
                            @else
                                ID: {{ $playerId }}
                            @endif
                            @if ($uid && $steam64)
                                · DayZ UID: <code>{{ $uid }}</code>
                            @endif
                        </div>
                    </div>
                    <span class="dz-badge {{ !empty($player['online']) ? 'dz-badge-on' : 'dz-badge-off' }}">
                        {{ !empty($player['online']) ? 'Online' : 'Offline' }}
                    </span>
                </div>

                <dl class="dz-browse-meta" style="margin-top:0.75rem;">
                    <div><dt>Nickname</dt><dd>{{ $player['name'] ?: '—' }}</dd></div>
                    <div><dt>Steam64</dt><dd>
                        @if ($steam64)
                            <a href="https://steamcommunity.com/profiles/{{ $steam64 }}" target="_blank" rel="noopener noreferrer">{{ $steam64 }}</a>
                        @else
                            Unknown
                        @endif
                    </dd></div>
                    @if ($uid)
                        <div><dt>DayZ UID</dt><dd><code style="word-break:break-all;">{{ $uid }}</code></dd></div>
                    @endif
                    <div><dt>Coordinates</dt><dd>
                        @if (($player['position_status'] ?? '') === 'valid')
                            X: {{ number_format((float) $player['x'], 1) }}, Z: {{ number_format((float) $player['z'], 1) }}
                        @else
                            Unknown
                        @endif
                    </dd></div>
                    <div><dt>Height (Y)</dt><dd>
                        @if (isset($player['y']) && $player['y'] !== null)
                            {{ number_format((float) $player['y'], 1) }} m
                        @else
                            —
                        @endif
                    </dd></div>
                    @if (isset($player['direction']) && $player['direction'] !== null)
                        <div><dt>Direction</dt><dd>{{ number_format((float) $player['direction'], 1) }}°</dd></div>
                    @endif
                    <div><dt>Status</dt><dd>
                        @if (array_key_exists('alive', $player) && $player['alive'] !== null)
                            {{ !empty($player['alive']) ? '✅ Alive' : '💀 Dead / last known dead' }}
                        @else
                            Unknown
                        @endif
                    </dd></div>
                    <div><dt>Health</dt><dd>
                        @if ($health !== null)
                            {{ $health }}%
                        @else
                            N/A
                        @endif
                    </dd></div>
                    @if (!empty($player['first_seen_at']))
                        <div><dt>First seen</dt><dd>{{ $fmtDate($player['first_seen_at']) }}</dd></div>
                    @endif
                    @if (!empty($player['last_seen_at']))
                        <div><dt>Last seen</dt><dd>{{ $fmtDate($player['last_seen_at']) }}</dd></div>
                    @endif
                    <div><dt>Lists</dt><dd>
                        @php
                            $flags = array_keys(array_filter($player['lists'] ?? []));
                        @endphp
                        {{ $flags === [] ? 'None' : implode(', ', array_map('ucfirst', $flags)) }}
                    </dd></div>
                    <div><dt>Sources</dt><dd>{{ implode(', ', array_map(fn ($source) => basename((string) $source), $player['sources'] ?? [])) ?: '—' }}</dd></div>
                </dl>

                <div class="dz-mod-actions" style="margin-top:0.9rem;">
                    @if (($player['position_status'] ?? '') === 'valid')
                        <a class="dz-btn dz-btn-ghost" href="{{ $base_url }}/live-map?focus={{ urlencode($actionId) }}">Show on live map</a>
                    @endif
                    @if ($hasInventory)
                        <button class="dz-btn dz-btn-ghost" type="button" onclick="pteroViewInventory({{ json_encode($player['name'] ?: $playerId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode($inventoryJson, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }})">View Inventory</button>
                    @endif
                    @if (!empty($player['online']) && $actionId)
                        <button class="dz-btn dz-btn-amber" type="button" onclick="pteroKickPlayer({{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }})">Kick</button>
                    @endif
                    <button class="dz-btn dz-btn-red" type="button" onclick="pteroBanPlayer({{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }})">Ban</button>
                    <button class="dz-btn dz-btn-ghost" type="button" onclick="pteroAddToList('whitelist', {{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }})">Add to Whitelist</button>
                    <button class="dz-btn dz-btn-ghost" type="button" onclick="pteroAddToList('priority', {{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }})">Add to Priority Queue</button>
                    <button class="dz-btn dz-btn-ghost" type="button" style="color:var(--dz-warn,#f59e0b);" onclick="pteroResetPlayer({{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode($player['name'] ?: $playerId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }})">Reset Data</button>
                </div>
            </div>
        @empty
            <div class="dz-empty">
                @if (($persistence_status ?? '') === 'not_found')
                    No readable characters.db/players.db file was found in common DayZ persistence paths, and no player has been observed online yet.
                @else
                    No player records were found.
                @endif
            </div>
        @endforelse
    </div>

    <p id="dz-player-action-status" class="dz-status dz-hidden" style="margin-top:0.75rem;"></p>
</section>

{{-- Inventory viewer modal --}}
<div id="dz-inventory-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.7);overflow-y:auto;">
    <div style="background:var(--dz-surface,#1a1f2e);border:1px solid var(--dz-border,#2d3348);border-radius:0.5rem;max-width:640px;margin:4rem auto;padding:1.5rem;position:relative;">
        <button type="button" onclick="document.getElementById('dz-inventory-modal').style.display='none'" style="position:absolute;top:0.75rem;right:0.75rem;background:none;border:none;color:inherit;font-size:1.25rem;cursor:pointer;" aria-label="Close">✕</button>
        <h3 id="dz-inventory-modal-title" style="margin:0 0 1rem;"></h3>
        <div id="dz-inventory-modal-body"></div>
    </div>
</div>

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

    window.pteroAddToList = function (listType, playerId) {
        var labels = { whitelist: 'Whitelist', priority: 'Priority Queue' };
        var label = labels[listType] || listType;
        var note = window.prompt('Optional note for ' + label + ':', '');
        if (note === null) { return; }
        setStatus('Adding player to ' + label + '…');
        req('POST', '/dayz/players/' + encodeURIComponent(listType), { player_id: playerId, note: note })
            .then(function (d) { setStatus(d.message || (d.status === 'saved' ? 'Player added to ' + label + '.' : 'Failed.'), d.status === 'saved' ? undefined : false); })
            .catch(function () { setStatus('Request failed.', false); });
    };

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

    window.pteroResetPlayer = function (playerId, playerName) {
        var msg = 'Reset all tracked data for "' + playerName + '"?\n\nThis removes their last-seen position, health, inventory and timestamps from the panel database. They will reappear automatically the next time they connect.';
        if (!confirm(msg)) { return; }
        setStatus('Resetting player data…');
        req('DELETE', '/dayz/player-actions/observed/' + encodeURIComponent(playerId), null)
            .then(function (d) { setStatus(d.message || (d.status === 'reset' ? 'Player data reset.' : 'Reset failed.'), d.status === 'reset' ? undefined : false); })
            .catch(function () { setStatus('Reset request failed.', false); });
    };

    window.pteroViewInventory = function (playerName, inventoryJson) {
        var modal = document.getElementById('dz-inventory-modal');
        var title = document.getElementById('dz-inventory-modal-title');
        var body  = document.getElementById('dz-inventory-modal-body');
        if (!modal || !title || !body) { return; }

        title.textContent = playerName + ' — Inventory';

        var parsed = null;
        try {
            parsed = typeof inventoryJson === 'string' ? JSON.parse(inventoryJson) : inventoryJson;
        } catch (e) { /* fall through */ }

        if (!parsed) {
            body.textContent = String(inventoryJson || '(no inventory data)');
            modal.style.display = 'block';
            return;
        }

        // Collect all item class names from the inventory tree.
        var items = [];
        function walk(obj) {
            if (!obj || typeof obj !== 'object') { return; }
            if (Array.isArray(obj)) { obj.forEach(walk); return; }
            var cls = obj.className || obj.class || obj.type || obj.item || obj.name;
            if (cls && typeof cls === 'string') { items.push(cls); }
            Object.values(obj).forEach(function (v) {
                if (v && typeof v === 'object') { walk(v); }
            });
        }
        walk(parsed);

        if (items.length === 0) {
            // No recognised item list structure — fall back to formatted JSON.
            body.innerHTML = '';
            var pre = document.createElement('pre');
            pre.style.cssText = 'white-space:pre-wrap;word-break:break-all;font-size:0.8rem;max-height:60vh;overflow-y:auto;background:var(--dz-surface-alt,#0b0f19);padding:1rem;border-radius:0.25rem;margin:0;';
            pre.textContent = JSON.stringify(parsed, null, 2);
            body.appendChild(pre);
            modal.style.display = 'block';
            return;
        }

        // Render a grid of items with wiki links.
        var html = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:0.5rem;max-height:65vh;overflow-y:auto;">';
        items.forEach(function (cls) {
            var label = cls.replace(/_/g, ' ').replace(/([a-z])([A-Z])/g, '$1 $2');
            var wikiUrl = 'https://dayz.wiki.gg/wiki/' + encodeURIComponent(cls);
            html += '<div style="background:var(--dz-surface-alt,#0b0f19);border:1px solid var(--dz-border,#2d3348);border-radius:0.35rem;padding:0.5rem;font-size:0.78rem;overflow:hidden;">'
                + '<div style="font-size:1.5rem;text-align:center;margin-bottom:0.25rem;">📦</div>'
                + '<div style="word-break:break-word;text-align:center;">'
                + '<a href="' + wikiUrl + '" target="_blank" rel="noopener noreferrer" style="color:var(--dz-accent);text-decoration:none;" title="' + cls + '">' + label + '</a>'
                + '</div>'
                + '</div>';
        });
        html += '</div><p style="margin:0.5rem 0 0;font-size:0.72rem;color:var(--dz-muted);">' + items.length + ' item(s) · Links open the DayZ wiki page for each item.</p>';
        body.innerHTML = html;
        modal.style.display = 'block';
    };

    // Close modal on backdrop click.
    document.getElementById('dz-inventory-modal').addEventListener('click', function (e) {
        if (e.target === this) { this.style.display = 'none'; }
    });

    var searchEl = document.getElementById('dz-player-search');

    function applySearch() {
        var needle = (searchEl.value || '').toLowerCase();
        var cards = document.querySelectorAll('#dz-player-list .dz-player-card');

        Array.prototype.forEach.call(cards, function (card) {
            var hay = card.getAttribute('data-search') || '';
            card.style.display = needle === '' || hay.indexOf(needle) !== -1 ? '' : 'none';
        });
    }

    if (searchEl) {
        // The live map links here with ?search=<steam64|nickname> so a player
        // picked on the map opens straight into their record.
        var preset = new URLSearchParams(window.location.search).get('search');

        if (preset) {
            searchEl.value = preset;
            applySearch();
        }

        searchEl.addEventListener('input', applySearch);
    }
}());
</script>
