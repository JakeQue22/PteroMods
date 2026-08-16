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
                // Kick uses the live-player identifier first (DayZ UID), then fallback.
                $kickId = $uid ?? $playerId;
                $hasInventory = !empty($player['inventory']) && (is_array($player['inventory']) || (is_string($player['inventory']) && trim($player['inventory']) !== ''));
                $inventoryJson = $hasInventory ? json_encode($player['inventory'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
                // Normalise timestamps to DD-MM-YYYY HH:MM
                $fmtDate = fn($ts) => $ts ? date('d-m-Y H:i', strtotime($ts)) : null;
                // Normalise health: older bridge wrote 0-10000, current writes 0-100
                $rawHealth = isset($player['health']) && $player['health'] !== null ? (float) $player['health'] : null;
                $health = $rawHealth !== null ? ($rawHealth > 100 ? round($rawHealth / 100, 1) : round($rawHealth, 1)) : null;
                $pinnedSteam64 = (string) ($protected_steam64 ?? '');
                $isProtectedPlayer = $steam64 === $pinnedSteam64;
                $listFlags = is_array($player['lists'] ?? null) ? $player['lists'] : [];
                $listEntryIds = is_array($player['list_entry_ids'] ?? null) ? $player['list_entry_ids'] : [];
                $resetBackupId = isset($player['reset_backup_id']) ? (int) $player['reset_backup_id'] : 0;
                $resetBackupAt = !empty($player['reset_backup_at']) ? (string) $player['reset_backup_at'] : null;
                $isOnline = !empty($player['online']);
                $previousNames = is_array($player['previous_names'] ?? null) ? $player['previous_names'] : [];
                $canRemovePlayers = !empty($can_remove_players) && !$isProtectedPlayer;
                $resetBackupLabel = $resetBackupAt ? date('d-m-Y H:i', strtotime($resetBackupAt)) : null;
                $currentName = (string) ($player['name'] ?? '');
                $filteredPreviousNames = array_values(array_filter($previousNames, static fn ($pn) => ($pn['nickname'] ?? '') !== '' && ($pn['nickname'] ?? '') !== $currentName));
            @endphp
            <div class="dz-player-card" data-player-action-id="{{ $actionId }}" data-search="{{ strtolower(trim(($player['name'] ?? '') . ' ' . ($steam64 ?? '') . ' ' . $playerId . ' ' . ($uid ?? ''))) }}">
                <div class="dz-player-card__head">
                    <div>
                        <strong>{{ $player['name'] ?: $playerId }}</strong>
                    </div>
                    <div style="display:flex;align-items:center;gap:0.5rem;">
                        <span class="dz-badge {{ !empty($player['online']) ? 'dz-badge-on' : 'dz-badge-off' }}">
                            {{ !empty($player['online']) ? 'Online' : 'Offline' }}
                        </span>
                        @if ($canRemovePlayers)
                            <button class="dz-btn dz-btn-red" type="button" title="Remove player permanently" style="padding:0.15rem 0.45rem;font-size:0.85rem;line-height:1;" onclick="pteroRemovePlayer({{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode($player['name'] ?: $playerId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode((string) $playerId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }})">✕</button>
                        @endif
                    </div>
                </div>

                <dl class="dz-browse-meta" style="margin-top:0.75rem;">
                    <div><dt>Nickname</dt><dd>{{ $player['name'] ?: '—' }}</dd></div>
                    @if (!empty($filteredPreviousNames))
                        <div><dt>Previous names</dt><dd>
                            <span style="font-size:0.82rem;color:var(--dz-muted);">
                                @foreach ($filteredPreviousNames as $pn)
                                    {{ $pn['nickname'] }}@if (!$loop->last), @endif
                                @endforeach
                            </span>
                        </dd></div>
                    @endif
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
                    @if (!$isProtectedPlayer && !empty($player['online']) && $kickId)
                        <button class="dz-btn dz-btn-amber" type="button" onclick="pteroKickPlayer({{ json_encode((string) $kickId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }})">Kick</button>
                    @endif
                    @if (!$isProtectedPlayer)
                        @if (!empty($listFlags['ban']) && !empty($listEntryIds['ban']))
                            <button class="dz-btn dz-btn-red" type="button" onclick="pteroRemoveFromList('ban', {{ json_encode((string) $listEntryIds['ban'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }})">Remove from Ban List</button>
                        @else
                            <button class="dz-btn dz-btn-red" type="button" onclick="pteroBanPlayer({{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode((string) ($player['name'] ?: $playerId), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }})">Ban</button>
                        @endif
                    @endif
                    @if (!empty($listFlags['whitelist']) && !empty($listEntryIds['whitelist']))
                        <button class="dz-btn dz-btn-ghost" type="button" onclick="pteroRemoveFromList('whitelist', {{ json_encode((string) $listEntryIds['whitelist'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }})">Remove from Whitelist</button>
                    @else
                        <button class="dz-btn dz-btn-ghost" type="button" onclick="pteroAddToList('whitelist', {{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode((string) ($player['name'] ?: $playerId), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }})">Add to Whitelist</button>
                    @endif
                    @if (!empty($listFlags['priority']) && !empty($listEntryIds['priority']))
                        <button class="dz-btn dz-btn-ghost" type="button" onclick="pteroRemoveFromList('priority', {{ json_encode((string) $listEntryIds['priority'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }})">Remove from Priority Queue</button>
                    @else
                        <button class="dz-btn dz-btn-ghost" type="button" onclick="pteroAddToList('priority', {{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode((string) ($player['name'] ?: $playerId), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }})">Add to Priority Queue</button>
                    @endif
                    @if ($steam64)
                        @if (in_array($steam64, $superadmin_ids ?? [], true))
                            @if (!$isProtectedPlayer)
                                <button class="dz-btn dz-btn-red" type="button" onclick="pteroRemoveSuperadmin({{ json_encode($steam64, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode($player['name'] ?: $playerId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }})">Remove SuperAdmin</button>
                            @endif
                        @else
                            <button class="dz-btn dz-btn-ghost" type="button" style="color:var(--dz-accent);" onclick="pteroMakeSuperadmin({{ json_encode($steam64, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode($player['name'] ?: $playerId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }})">Make SuperAdmin</button>
                        @endif
                    @endif
                    @if (!$isProtectedPlayer)
                        <button class="dz-btn dz-btn-ghost" type="button" style="color:var(--dz-warn,#f59e0b);" onclick="pteroResetPlayer({{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode($player['name'] ?: $playerId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }})">Reset Data</button>
                    @endif
                    @if ($resetBackupId > 0)
                        <button class="dz-btn dz-btn-ghost" type="button" style="color:var(--dz-accent);" onclick="pteroRestorePlayer({{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode($player['name'] ?: $playerId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ $resetBackupId }}, {{ json_encode((string) ($resetBackupLabel ?? ''), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }})">Restore Data{{ $resetBackupLabel ? ' · ' . $resetBackupLabel : '' }}</button>
                    @endif
                    @if (!$isOnline || in_array($steam64, $superadmin_ids ?? [], true))
                        <button class="dz-btn dz-btn-ghost" type="button" style="color:var(--dz-success,#10b981);" onclick="pteroGiveMoney({{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode($player['name'] ?: $playerId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ $isOnline ? 'true' : 'false' }}, {{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode((string) ($uid ?? $playerId), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }})">Give Money</button>
                    @endif
                </div>
                <p class="dz-status dz-hidden dz-player-action-status" style="margin-top:0.75rem;"></p>
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
    <div style="background:var(--dz-surface,#1a1f2e);border:1px solid var(--dz-border,#2d3348);border-radius:0.5rem;max-width:900px;margin:4rem auto;padding:1.5rem;position:relative;">
        <button type="button" onclick="document.getElementById('dz-inventory-modal').style.display='none'" style="position:absolute;top:0.75rem;right:0.75rem;background:none;border:none;color:inherit;font-size:1.25rem;cursor:pointer;" aria-label="Close">✕</button>
        <h3 id="dz-inventory-modal-title" style="margin:0 0 1rem;"></h3>
        <div id="dz-inventory-modal-body"></div>
    </div>
</div>

@php
    $listPlayerLookup = [];
    foreach ($persisted_players ?? [] as $player) {
        $lookupKeys = array_filter([
            (string) ($player['steam64'] ?? ''),
            (string) ($player['player_id'] ?? ''),
            (string) ($player['player_uid'] ?? ''),
        ], static fn ($value) => $value !== '');
        $summary = [
            'name' => (string) (($player['name'] ?? '') !== '' ? $player['name'] : (($player['player_id'] ?? '') ?: 'Unknown')),
            'steam64' => trim((string) ($player['steam64'] ?? '')),
        ];
        foreach ($lookupKeys as $lookupKey) {
            $listPlayerLookup[$lookupKey] = $summary;
        }
    }
@endphp

@foreach (['ban' => 'Ban List', 'whitelist' => 'Whitelist', 'priority' => 'Priority Queue'] as $type => $label)
    <section class="dz-card">
        <h2>{{ $label }}</h2>
        <p class="dz-sub">{{ count($player_lists[$type] ?? []) }} entr(y/ies).</p>
        <ul class="dz-list">
            @forelse ($player_lists[$type] ?? [] as $entry)
                @php
                    $entryPlayerId = trim((string) ($entry['player_id'] ?? ''));
                    $summary = $listPlayerLookup[$entryPlayerId] ?? ['name' => ($entry['nickname'] ?? '') !== '' ? (string) $entry['nickname'] : ($entryPlayerId !== '' ? $entryPlayerId : 'Unknown'), 'steam64' => ''];
                    $displaySteamId = $summary['steam64'] !== '' ? $summary['steam64'] : ($entryPlayerId !== '' ? $entryPlayerId : 'Unknown');
                @endphp
                <li>
                    <div>
                        <strong>{{ $summary['name'] }} | {{ $displaySteamId }}</strong>
                        @if (!empty($entry['note']))
                            <div class="dz-text-muted">{{ $entry['note'] }}</div>
                        @endif
                    </div>
                    <button class="dz-btn dz-btn-red" type="button" onclick="pteroRemoveFromList({{ json_encode($type, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode($entryPlayerId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }})">Remove</button>
                </li>
            @empty
                <li class="dz-empty">No entries in this list.</li>
            @endforelse
        </ul>
    </section>
@endforeach

@if (!empty($give_money_queue))
<section class="dz-card">
    <h2>Give Money Queue</h2>
    <p class="dz-sub">Pending give-money requests. The bundled mission bridge template lives at <code>game-panel-mods/DayZManager/assets/bridge/pteromods_give_money.c</code> and reads <code>/profiles/PteroMods/give_money_&lt;uid&gt;.json</code> to add the requested MoneyRuble items to the player's inventory.</p>
    <p id="dz-give-money-queue-status" class="dz-status" style="margin:0 0 0.75rem;"></p>
    <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
        <thead>
            <tr style="border-bottom:1px solid var(--dz-border,#2d3348);">
                <th style="text-align:left;padding:0.25rem 0.5rem;">Player</th>
                <th style="text-align:left;padding:0.25rem 0.5rem;">Item</th>
                <th style="text-align:left;padding:0.25rem 0.5rem;">Qty</th>
                <th style="text-align:left;padding:0.25rem 0.5rem;">Status</th>
                <th style="text-align:left;padding:0.25rem 0.5rem;">Queued</th>
                <th style="text-align:left;padding:0.25rem 0.5rem;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($give_money_queue as $entry)
                <tr style="border-bottom:1px solid var(--dz-border,#2d3348);">
                    <td style="padding:0.25rem 0.5rem;">
                        {{ ($entry['player_name'] ?? '') !== '' ? $entry['player_name'] : ($entry['player_id'] ?? '—') }}
                    </td>
                    <td style="padding:0.25rem 0.5rem;font-family:monospace;">{{ $entry['item_class'] ?? '—' }}</td>
                    <td style="padding:0.25rem 0.5rem;">{{ $entry['quantity'] ?? 1 }}</td>
                    <td style="padding:0.25rem 0.5rem;">
                        <span style="color:{{ ($entry['status'] ?? '') === 'delivered' ? 'var(--dz-success,#10b981)' : (($entry['status'] ?? '') === 'pending' ? 'var(--dz-warn,#f59e0b)' : 'var(--dz-muted)') }}">
                            {{ ucfirst($entry['status'] ?? 'pending') }}
                        </span>
                    </td>
                    <td style="padding:0.25rem 0.5rem;color:var(--dz-muted);">{{ $entry['created_at_display'] ?? ($entry['created_at'] ?? '—') }}</td>
                    <td style="padding:0.25rem 0.5rem;">
                        @if (!empty($entry['id']))
                            <button class="dz-btn dz-btn-red" type="button" onclick="pteroRemoveGiveMoney({{ (int) $entry['id'] }}, {{ json_encode(($entry['player_name'] ?? '') !== '' ? $entry['player_name'] : ($entry['player_id'] ?? 'player'), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode((string) ($entry['item_class'] ?? 'item'), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ (int) ($entry['quantity'] ?? 1) }})">Remove</button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</section>
@endif

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

    function actionStatusEl(playerActionId) {
        if (playerActionId && String(playerActionId) === 'give-money-queue') {
            return document.getElementById('dz-give-money-queue-status');
        }

        if (playerActionId) {
            var cards = document.querySelectorAll('.dz-player-card');
            for (var i = 0; i < cards.length; i += 1) {
                if ((cards[i].getAttribute('data-player-action-id') || '') === String(playerActionId)) {
                    return cards[i].querySelector('.dz-player-action-status');
                }
            }
        }

        return document.getElementById('dz-player-action-status');
    }

    function setStatus(msg, ok, playerActionId) {
        var el = actionStatusEl(playerActionId);
        if (!el) { return; }
        el.textContent = msg;
        el.className = 'dz-status' + (ok === false ? ' dz-status-error' : '');
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function reloadSoon() {
        window.setTimeout(function () { window.location.reload(); }, 900);
    }

    window.pteroAddToList = function (listType, playerId, nickname, playerActionId) {
        var labels = { whitelist: 'Whitelist', priority: 'Priority Queue' };
        var label = labels[listType] || listType;
        var note = window.prompt('Optional note for ' + label + ':', '');
        if (note === null) { return; }
        setStatus('Adding player to ' + label + '…', undefined, playerActionId);
        req('POST', '/dayz/players/' + encodeURIComponent(listType), { player_id: playerId, nickname: nickname || '', note: note })
            .then(function (d) {
                var ok = d.status === 'saved';
                setStatus(d.message || (ok ? 'Player added to ' + label + '.' : 'Failed.'), ok ? undefined : false, playerActionId);
                if (ok) { reloadSoon(); }
            })
            .catch(function () { setStatus('Request failed.', false, playerActionId); });
    };

    window.pteroRemoveFromList = function (listType, playerId, playerActionId) {
        var labels = { ban: 'Ban List', whitelist: 'Whitelist', priority: 'Priority Queue' };
        var label = labels[listType] || listType;
        if (!confirm('Remove this player from ' + label + '?')) { return; }
        setStatus('Removing player from ' + label + '…', undefined, playerActionId);
        req('DELETE', '/dayz/players/' + encodeURIComponent(listType) + '/' + encodeURIComponent(playerId), null)
            .then(function (d) {
                var ok = d.status === 'deleted';
                setStatus(d.message || (ok ? 'Player removed from ' + label + '.' : 'Failed.'), ok ? undefined : false, playerActionId);
                if (ok) { reloadSoon(); }
            })
            .catch(function () { setStatus('Request failed.', false, playerActionId); });
    };

    window.pteroKickPlayer = function (playerId, playerActionId) {
        if (!confirm('Kick this player from the server now?')) { return; }
        setStatus('Sending kick command…', undefined, playerActionId);
        req('POST', '/dayz/player-actions/kick', { player_id: playerId })
            .then(function (d) { setStatus(d.message || (d.status === 'dispatched' ? 'Kick command sent.' : 'Kick failed.'), d.status === 'dispatched' ? undefined : false, playerActionId); })
            .catch(function () { setStatus('Kick request failed.', false, playerActionId); });
    };

    window.pteroBanPlayer = function (playerId, nickname, playerActionId) {
        var note = window.prompt('Optional ban note:', '');
        if (note === null) { return; }
        setStatus('Adding player to ban list…', undefined, playerActionId);
        req('POST', '/dayz/players/ban', { player_id: playerId, nickname: nickname || '', note: note })
            .then(function (d) {
                var ok = d.status === 'saved';
                setStatus(d.message || (ok ? 'Player added to ban list.' : 'Ban failed.'), ok ? undefined : false, playerActionId);
                if (ok) { reloadSoon(); }
            })
            .catch(function () { setStatus('Ban request failed.', false, playerActionId); });
    };

    window.pteroMakeSuperadmin = function (steam64, playerName, playerActionId) {
        if (!confirm('Add "' + playerName + '" as VPP SuperAdmin?\n\nThey will be added to SuperAdmins.txt. Changes take effect after the next server restart.')) { return; }
        setStatus('Adding SuperAdmin…', undefined, playerActionId);
        req('POST', '/dayz/players/superadmin', { steam64: steam64, nickname: playerName })
            .then(function (d) {
                setStatus(d.message || (d.status === 'added' ? 'Player added as SuperAdmin.' : 'Failed.'), d.status === 'added' ? undefined : false, playerActionId);
                if (d.status === 'added') { setTimeout(function () { window.location.reload(); }, 1500); }
            })
            .catch(function () { setStatus('Request failed.', false, playerActionId); });
    };

    window.pteroRemoveSuperadmin = function (steam64, playerName, playerActionId) {
        if (!confirm('Remove "' + playerName + '" from VPP SuperAdmins?\n\nThey will be removed from SuperAdmins.txt. Changes take effect after the next server restart.')) { return; }
        setStatus('Removing SuperAdmin…', undefined, playerActionId);
        req('DELETE', '/dayz/players/superadmin/' + encodeURIComponent(steam64), null)
            .then(function (d) {
                setStatus(d.message || (d.status === 'removed' ? 'Player removed from SuperAdmins.' : 'Failed.'), d.status === 'removed' ? undefined : false, playerActionId);
                if (d.status === 'removed') { setTimeout(function () { window.location.reload(); }, 1500); }
            })
            .catch(function () { setStatus('Request failed.', false, playerActionId); });
    };

    window.pteroResetPlayer = function (playerId, playerName, playerActionId) {
        var msg = 'Reset all tracked data for "' + playerName + '"?\n\nThis removes their last-seen position, health, inventory and timestamps from the panel database. They will reappear automatically the next time they connect.';
        if (!confirm(msg)) { return; }
        setStatus('Resetting player data…', undefined, playerActionId);
        req('DELETE', '/dayz/player-actions/observed/' + encodeURIComponent(playerId), null)
            .then(function (d) {
                setStatus(d.message || (d.status === 'reset' ? 'Player data reset.' : 'Reset failed.'), d.status === 'reset' ? undefined : false, playerActionId);
                if (d.status === 'reset') { setTimeout(function () { window.location.reload(); }, 900); }
            })
            .catch(function () { setStatus('Reset request failed.', false, playerActionId); });
    };

    window.pteroRestorePlayer = function (playerId, playerName, backupId, backupLabel, playerActionId) {
        var label = backupLabel ? (' from ' + backupLabel) : '';
        if (!confirm('Restore tracked data for "' + playerName + '"' + label + '?')) { return; }
        setStatus('Restoring player data…', undefined, playerActionId);
        req('POST', '/dayz/player-actions/observed/' + encodeURIComponent(playerId) + '/restore', { backup_id: backupId })
            .then(function (d) {
                setStatus(d.message || (d.status === 'restored' ? 'Player data restored.' : 'Restore failed.'), d.status === 'restored' ? undefined : false, playerActionId);
                if (d.status === 'restored') { setTimeout(function () { window.location.reload(); }, 900); }
            })
            .catch(function () { setStatus('Restore request failed.', false, playerActionId); });
    };

    window.pteroGiveMoney = function (playerId, playerName, isOnline, playerActionId, playerUid) {
        var msg = (isOnline ? '⚠️  This player appears online. The queued reward may be delivered after they reconnect.\n\n' : '⚠️  Recommended: give money while the player is OFFLINE so it can be delivered reliably.\n\n')
            + 'Give money to "' + playerName + '"?\n\n'
            + 'Select denomination:\n'
            + '  1   = MoneyRuble1  (1 coin)\n'
            + '  50  = MoneyRuble50 (50 coins)\n'
            + '  100 = MoneyRuble100 (100 coins)';
        var denom = window.prompt(msg + '\n\nEnter denomination (1 / 50 / 100):', '100');
        if (denom === null) { return; }
        denom = parseInt(denom, 10);
        if (denom !== 1 && denom !== 50 && denom !== 100) {
            alert('Invalid denomination. Please enter 1, 50, or 100.');
            return;
        }
        var qty = window.prompt('How many items of MoneyRuble' + denom + ' to give? (1–99)', '1');
        if (qty === null) { return; }
        qty = Math.max(1, Math.min(99, parseInt(qty, 10) || 1));
        setStatus('Queueing give money…', undefined, playerActionId);
        req('POST', '/dayz/player-actions/give-money', { player_id: playerId, player_uid: playerUid || playerId, player_name: playerName, denomination: denom, quantity: qty })
            .then(function (d) {
                var ok = d.status === 'queued';
                setStatus(d.message || (ok ? 'Money queued successfully.' : 'Failed to queue money.'), ok ? undefined : false, playerActionId);
                if (ok) { setTimeout(function () { window.location.reload(); }, 1200); }
            })
            .catch(function () { setStatus('Give money request failed.', false, playerActionId); });
    };

    window.pteroRemoveGiveMoney = function (queueId, playerName, itemClass, quantity) {
        if (!queueId) { return; }
        if (!confirm('Remove ' + quantity + '× ' + itemClass + ' queued for "' + playerName + '"?')) { return; }
        setStatus('Removing queued give money…', undefined, 'give-money-queue');
        req('DELETE', '/dayz/player-actions/give-money/' + encodeURIComponent(queueId), null)
            .then(function (d) {
                var ok = d.status === 'removed';
                setStatus(d.message || (ok ? 'Queued give money removed.' : 'Failed to remove queued give money.'), ok ? undefined : false, 'give-money-queue');
                if (ok) { setTimeout(function () { window.location.reload(); }, 900); }
            })
            .catch(function () { setStatus('Remove give money request failed.', false, 'give-money-queue'); });
    };

    window.pteroRemovePlayer = function (actionId, playerName, selectedPlayerId, playerActionId) {
        var msg = '⛔  PERMANENTLY REMOVE "' + playerName + '" from the players list?\n\n'
            + 'This action CANNOT be undone. The player will be hidden from the list permanently '
            + '(they will reappear if observed online again, but will be re-hidden immediately).';
        if (!confirm(msg)) { return; }
        var confirmInput = window.prompt('Type REMOVE to confirm permanent removal of "' + playerName + '":', '');
        if ((confirmInput || '').trim().toUpperCase() !== 'REMOVE') {
            setStatus('Removal cancelled — type REMOVE to confirm.', false, playerActionId || actionId);
            return;
        }
        setStatus('Removing player…', undefined, playerActionId || actionId);
        req('DELETE', '/dayz/player-actions/players/' + encodeURIComponent(actionId), { player_name: playerName, selected_player_id: selectedPlayerId || actionId })
            .then(function (d) {
                var ok = d.status === 'removed';
                setStatus(d.message || (ok ? 'Player removed.' : 'Removal failed.'), ok ? undefined : false, playerActionId || actionId);
                if (ok) { window.location.reload(); }
            })
            .catch(function () { setStatus('Remove request failed.', false, playerActionId || actionId); });
    };

    var INVENTORY_META_CACHE = Object.create(null);

    function dedupeStrings(list) {
        var out = [];
        var seen = Object.create(null);
        (list || []).forEach(function (entry) {
            var value = String(entry || '').trim();
            if (value === '' || seen[value]) { return; }
            seen[value] = true;
            out.push(value);
        });
        return out;
    }

    function humanizeInventoryClassName(value) {
        return String(value || '')
            .replace(/_/g, ' ')
            .replace(/([A-Z]+)([A-Z][a-z])/g, '$1 $2')
            .replace(/([a-z])([A-Z])/g, '$1 $2')
            .replace(/([A-Za-z])(\d)/g, '$1 $2')
            .replace(/(\d)([A-Za-z])/g, '$1 $2')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function inventoryLookupKey(item) {
        return [
            String((item && item.className) || ''),
            String((item && item.itemId) || ''),
            String((item && item.name) || '')
        ].join('|');
    }

    function fallbackInventoryLabel(item) {
        if (item && item.name) { return String(item.name); }
        if (item && item.className) { return humanizeInventoryClassName(item.className); }
        if (item && item.itemId) { return String(item.itemId); }
        return 'Unknown item';
    }

    function resolveInventoryBatch(items) {
        var pending = [];
        (items || []).forEach(function (item) {
            var key = inventoryLookupKey(item);
            if (!key || INVENTORY_META_CACHE[key]) { return; }
            pending.push(item);
        });

        if (!pending.length) {
            return Promise.resolve({});
        }

        var requestPromise = req('POST', '/dayz/player-actions/inventory/resolve', { items: pending })
            .then(function (payload) {
                var resolved = payload && payload.items && typeof payload.items === 'object' ? payload.items : {};
                pending.forEach(function (item) {
                    var key = inventoryLookupKey(item);
                    INVENTORY_META_CACHE[key] = resolved[key] || { resolved: false };
                });
                return resolved;
            })
            .catch(function () {
                pending.forEach(function (item) {
                    INVENTORY_META_CACHE[inventoryLookupKey(item)] = { resolved: false };
                });
                return {};
            });

        pending.forEach(function (item) {
            INVENTORY_META_CACHE[inventoryLookupKey(item)] = requestPromise.then(function (resolved) {
                return resolved[inventoryLookupKey(item)] || { resolved: false };
            });
        });

        return requestPromise;
    }

    window.pteroViewInventory = function (playerName, inventoryJson) {
        var modal = document.getElementById('dz-inventory-modal');
        var title = document.getElementById('dz-inventory-modal-title');
        var body  = document.getElementById('dz-inventory-modal-body');
        if (!modal || !title || !body) { return; }

        title.textContent = playerName + ' — Inventory';
        body.innerHTML = '';

        var parsed = null;
        try {
            parsed = typeof inventoryJson === 'string' ? JSON.parse(inventoryJson) : inventoryJson;
        } catch (e) { /* fall through */ }

        if (!parsed) {
            body.textContent = String(inventoryJson || '(no inventory data)');
            modal.style.display = 'block';
            return;
        }

        // Collect items. The bridge format is [{slot, className, contents:[…]}, …]
        // but older snapshots may use other shapes — we handle both.
        // Items inside containers (e.g. backpack contents) use slot "<parent>.cargo"
        // or "<parent>.attach" so they appear under a dedicated section.
        var items = []; // [{slot, className, isContainerContent}]

        function stringField(entry, keys) {
            for (var i = 0; i < keys.length; i += 1) {
                var value = entry ? entry[keys[i]] : '';
                if (typeof value === 'string' && value.trim() !== '') {
                    return value.trim();
                }
            }
            return '';
        }

        function collectEntry(entry, parentSlot) {
            if (!entry || typeof entry !== 'object') { return; }
            var cls = stringField(entry, ['className', 'classname', 'class', 'type', 'item', 'itemClass', 'item_class']);
            var itemId = stringField(entry, ['itemId', 'item_id', 'internalId', 'internal_id', 'identifier', 'id']);
            var itemName = stringField(entry, ['name', 'itemName', 'item_name', 'displayName', 'display_name', 'label', 'title']);
            var slot = typeof entry.slot === 'string' ? entry.slot : (parentSlot || '');
            if (cls !== '') {
                items.push({ slot: slot, className: cls, itemId: itemId, name: itemName, isContainerContent: !!parentSlot });
            }
            // Recurse into contents array (bridge v2 format with container contents).
            var contents = Array.isArray(entry.contents) ? entry.contents : null;
            if (contents && contents.length) {
                contents.forEach(function (sub) { collectEntry(sub, slot || 'Unknown'); });
            }
        }

        if (Array.isArray(parsed)) {
            parsed.forEach(function (entry) {
                if (typeof entry === 'string' && entry !== '') {
                    items.push({ slot: '', className: entry, itemId: '', name: '', isContainerContent: false });
                } else {
                    collectEntry(entry, '');
                }
            });
        }

        if (items.length === 0) {
            // Deep-walk fallback for any other inventory structure.
            (function walk(obj, parentSlot) {
                if (!obj || typeof obj !== 'object') { return; }
                if (Array.isArray(obj)) { obj.forEach(function (v) { walk(v, parentSlot); }); return; }
                var cls = stringField(obj, ['className', 'classname', 'class', 'type', 'item', 'itemClass', 'item_class']);
                if (cls && typeof cls === 'string') {
                    items.push({
                        slot: obj.slot || parentSlot || '',
                        className: cls,
                        itemId: stringField(obj, ['itemId', 'item_id', 'internalId', 'internal_id', 'identifier', 'id']),
                        name: stringField(obj, ['name', 'itemName', 'item_name', 'displayName', 'display_name', 'label', 'title']),
                        isContainerContent: !!parentSlot
                    });
                }
                Object.values(obj).forEach(function (v) { if (v && typeof v === 'object') { walk(v, obj.slot || parentSlot || ''); } });
            }(parsed, ''));
        }

        if (items.length === 0) {
            // Last resort: formatted JSON dump.
            var pre = document.createElement('pre');
            pre.style.cssText = 'white-space:pre-wrap;word-break:break-all;font-size:0.8rem;max-height:60vh;overflow-y:auto;background:var(--dz-surface-alt,#0b0f19);padding:1rem;border-radius:0.25rem;margin:0;';
            pre.textContent = JSON.stringify(parsed, null, 2);
            body.appendChild(pre);
            modal.style.display = 'block';
            return;
        }

        // Split into equipped items (top-level slots) and container contents.
        var equippedItems = items.filter(function (i) { return !i.isContainerContent; });
        var containerItems = items.filter(function (i) { return i.isContainerContent; });

        // Group items by slot for readability.
        var slotOrder = ['Hands', 'Headgear', 'Mask', 'Eyewear', 'Gloves', 'Armband', 'Body', 'Back', 'Vest', 'Hips', 'Legs', 'Feet', 'Shoulder', ''];
        var bySlot = {};
        equippedItems.forEach(function (item) {
            var s = item.slot || '';
            if (!bySlot[s]) { bySlot[s] = []; }
            bySlot[s].push(item);
        });

        var orderedSlots = slotOrder.filter(function (s) { return bySlot[s] && bySlot[s].length; });
        Object.keys(bySlot).forEach(function (s) {
            if (orderedSlots.indexOf(s) === -1) { orderedSlots.push(s); }
        });

        var cardStyle = 'background:var(--dz-surface-alt,#0b0f19);border:1px solid var(--dz-border,#2d3348);border-radius:0.35rem;padding:0.6rem 0.5rem 0.5rem;font-size:0.78rem;overflow:hidden;display:flex;flex-direction:column;align-items:center;gap:0.35rem;';
        var imgStyle  = 'width:80px;height:80px;object-fit:contain;display:block;';
        var emojiStyle = 'font-size:2.5rem;text-align:center;line-height:1;';

        function setCardImage(imgWrap, imgUrl, label) {
            imgWrap.innerHTML = '';
            if (!imgUrl) {
                imgWrap.innerHTML = '<span style="' + emojiStyle + '">📦</span>';
                return;
            }

            var img = document.createElement('img');
            img.src = imgUrl;
            img.alt = label;
            img.style.cssText = imgStyle;
            img.onerror = function () {
                imgWrap.innerHTML = '<span style="' + emojiStyle + '">📦</span>';
            };
            imgWrap.appendChild(img);
        }

        var cardsByLookup = Object.create(null);

        function applyResolvedMeta(cardBits, resolved, fallbackLabel) {
            if (!cardBits) { return; }
            var label = resolved && resolved.display_name ? resolved.display_name : fallbackLabel;

            cardBits.nameEl.innerHTML = '';

            if (resolved && resolved.resolved && resolved.wiki_url) {
                var link = document.createElement('a');
                link.href = resolved.wiki_url;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.textContent = label;
                link.style.cssText = 'color:var(--dz-accent);text-decoration:none;';
                link.title = [
                    resolved.classname || cardBits.item.className || '',
                    resolved.variant || '',
                    resolved.type || resolved.category || ''
                ].filter(Boolean).join(' · ');
                cardBits.nameEl.appendChild(link);
            } else {
                var span = document.createElement('span');
                span.textContent = label;
                span.title = (cardBits.item.className || label);
                cardBits.nameEl.appendChild(span);
            }

            if (resolved && resolved.image_url) {
                setCardImage(cardBits.imgWrap, resolved.image_url, label);
            }
        }

        function makeItemCard(item) {
            var label = fallbackInventoryLabel(item);
            var card = document.createElement('div');
            card.style.cssText = cardStyle;

            var imgWrap = document.createElement('div');
            imgWrap.style.cssText = 'width:80px;height:80px;display:flex;align-items:center;justify-content:center;';
            setCardImage(imgWrap, '', label);
            card.appendChild(imgWrap);

            var nameEl = document.createElement('div');
            nameEl.style.cssText = 'word-break:break-word;text-align:center;line-height:1.2;';
            nameEl.textContent = label;
            nameEl.title = item.className || label;
            card.appendChild(nameEl);

            var key = inventoryLookupKey(item);
            if (!cardsByLookup[key]) { cardsByLookup[key] = []; }
            cardsByLookup[key].push({ item: item, imgWrap: imgWrap, nameEl: nameEl });

            return card;
        }

        var frag = document.createDocumentFragment();

        orderedSlots.forEach(function (slot) {
            var section = document.createElement('div');
            section.style.cssText = 'margin-bottom:1rem;';

            if (slot !== '') {
                var heading = document.createElement('p');
                heading.textContent = slot;
                heading.style.cssText = 'font-size:0.72rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:var(--dz-muted);margin:0 0 0.4rem;';
                section.appendChild(heading);
            }

            var grid = document.createElement('div');
            grid.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:0.5rem;';

            bySlot[slot].forEach(function (item) { grid.appendChild(makeItemCard(item)); });

            section.appendChild(grid);
            frag.appendChild(section);
        });

        // Container contents section (backpack/vest/pants cargo from bridge v2).
        if (containerItems.length > 0) {
            var contSec = document.createElement('div');
            contSec.style.cssText = 'margin-bottom:1rem;';

            var contHead = document.createElement('p');
            contHead.textContent = 'Container Contents';
            contHead.style.cssText = 'font-size:0.72rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:var(--dz-muted);margin:0 0 0.4rem;border-top:1px solid var(--dz-border,#2d3348);padding-top:0.75rem;';
            contSec.appendChild(contHead);

            // Group container items by parent slot.
            var byContainer = {};
            containerItems.forEach(function (item) {
                var k = item.slot || 'Unknown';
                if (!byContainer[k]) { byContainer[k] = []; }
                byContainer[k].push(item);
            });

            Object.keys(byContainer).forEach(function (containerSlot) {
                var subHead = document.createElement('p');
                subHead.textContent = 'In: ' + containerSlot;
                subHead.style.cssText = 'font-size:0.7rem;color:var(--dz-muted);margin:0.5rem 0 0.25rem;';
                contSec.appendChild(subHead);

                var subGrid = document.createElement('div');
                subGrid.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:0.5rem;';
                byContainer[containerSlot].forEach(function (item) { subGrid.appendChild(makeItemCard(item)); });
                contSec.appendChild(subGrid);
            });

            frag.appendChild(contSec);
        }

        var footer = document.createElement('p');
        footer.style.cssText = 'margin:0.25rem 0 0;font-size:0.72rem;color:var(--dz-muted);';
        footer.textContent = equippedItems.length + ' item(s) equipped' + (containerItems.length ? ' · ' + containerItems.length + ' in containers' : '') + ' · Images and links from the DayZ wiki (dayz.wiki.gg)';
        frag.appendChild(footer);

        body.appendChild(frag);
        modal.style.display = 'block';

        var uniqueItems = [];
        var seenLookup = Object.create(null);
        items.forEach(function (item) {
            var key = inventoryLookupKey(item);
            if (!key || seenLookup[key]) { return; }
            seenLookup[key] = true;
            uniqueItems.push({ className: item.className || '', itemId: item.itemId || '', name: item.name || '' });
        });

        resolveInventoryBatch(uniqueItems).then(function () {
            Object.keys(cardsByLookup).forEach(function (key) {
                var maybePromise = INVENTORY_META_CACHE[key];
                Promise.resolve(maybePromise).then(function (resolved) {
                    (cardsByLookup[key] || []).forEach(function (cardBits) {
                        applyResolvedMeta(cardBits, resolved || null, fallbackInventoryLabel(cardBits.item));
                    });
                });
            });
        });
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
