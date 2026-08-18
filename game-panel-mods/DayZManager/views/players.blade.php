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
                    <div><dt>Money</dt><dd>
                        @if (($player['bank_money'] ?? null) !== null)
                            ${{ number_format((float) $player['bank_money']) }}
                        @else
                            —
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
                        <button class="dz-btn dz-btn-amber" type="button" onclick="pteroKickPlayer({{ json_encode((string) $kickId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode((string) ($player['name'] ?: $playerId), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }})">Kick</button>
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
                    <button class="dz-btn dz-btn-ghost" type="button" style="color:var(--dz-success,#10b981);" onclick="pteroGiveMoney({{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode($player['name'] ?: $playerId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ $isOnline ? 'true' : 'false' }}, {{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode((string) ($uid ?? $playerId), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }})">Give Cash</button>
                    @if ($steam64)
                        <button class="dz-btn dz-btn-ghost" type="button" style="color:var(--dz-success,#10b981);" onclick="pteroAlterBankMoney({{ json_encode((string) $steam64, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode($player['name'] ?: $playerId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode(($player['bank_money'] ?? null) !== null ? (string) $player['bank_money'] : '', JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}, {{ json_encode((string) $actionId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }})">Alter Bank Money</button>
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

<div id="dz-give-money-modal" class="dz-player-modal" style="display:none;">
    <div class="dz-player-modal__dialog">
        <button type="button" class="dz-player-modal__close" onclick="window.pteroCloseGiveMoneyModal && window.pteroCloseGiveMoneyModal()" aria-label="Close">✕</button>
        <div class="dz-player-modal__header">
            <p class="dz-player-modal__eyebrow">Give Cash</p>
            <h3 id="dz-give-money-modal-title" style="margin:0;">Queue cash</h3>
            <p class="dz-sub" style="margin:0;">Online players receive it instantly; offline players receive it after reconnecting.</p>
        </div>
        <form id="dz-give-money-form" class="dz-player-modal__form">
            <label class="dz-player-modal__field">
                <span>Denomination</span>
                <select id="dz-give-money-denomination" class="dz-input">
                    <option value="1">1 coin · MoneyRuble1</option>
                    <option value="5">5 coins · MoneyRuble5</option>
                    <option value="10">10 coins · MoneyRuble10</option>
                    <option value="25">25 coins · MoneyRuble25</option>
                    <option value="50">50 coins · MoneyRuble50</option>
                    <option value="100" selected>100 coins · MoneyRuble100</option>
                </select>
            </label>
            <label class="dz-player-modal__field">
                <span>Quantity</span>
                <input id="dz-give-money-quantity" class="dz-input" type="number" min="1" max="99" step="1" value="1" />
            </label>
            <div class="dz-player-modal__meta">
                <div>
                    <span class="dz-player-modal__meta-label">Item class</span>
                    <strong id="dz-give-money-class">MoneyRuble100</strong>
                </div>
                <div>
                    <span class="dz-player-modal__meta-label">Total value</span>
                    <strong id="dz-give-money-total">100 coins</strong>
                </div>
            </div>
            <p id="dz-give-money-modal-status" class="dz-status dz-hidden" style="margin:0;"></p>
            <div class="dz-player-modal__actions">
                <button type="button" class="dz-btn dz-btn-ghost" onclick="window.pteroCloseGiveMoneyModal && window.pteroCloseGiveMoneyModal()">Cancel</button>
                <button id="dz-give-money-submit" type="submit" class="dz-btn" style="background:var(--dz-success,#10b981);border-color:var(--dz-success,#10b981);">Queue Cash</button>
            </div>
        </form>
    </div>
</div>

{{-- Inventory viewer modal --}}
<div id="dz-inventory-modal" class="dz-player-modal" style="display:none;">
    <div class="dz-player-modal__dialog dz-player-modal__dialog--wide">
        <button type="button" class="dz-player-modal__close" onclick="document.getElementById('dz-inventory-modal').style.display='none'" aria-label="Close">✕</button>
        <div class="dz-player-modal__header">
            <p class="dz-player-modal__eyebrow">Inventory</p>
            <h3 id="dz-inventory-modal-title" style="margin:0;"></h3>
            <p class="dz-sub" style="margin:0;">Equipped items and nested container contents from the latest player snapshot.</p>
        </div>
        <div id="dz-inventory-modal-body" class="dz-inventory-modal__body"></div>
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

<style>
.dz-player-modal {
    display: flex;
    flex-direction: column;
    position: fixed;
    inset: 0;
    z-index: 9999;
    background: rgba(2, 6, 23, 0.82);
    padding: 2rem 1rem;
    overflow-y: auto;
    align-items: flex-start;
    justify-content: center;
}
.dz-player-modal__dialog {
    position: relative;
    width: min(560px, 100%);
    margin: 0 auto;
    padding: 1.4rem;
    border-radius: 1rem;
    border: 1px solid color-mix(in srgb, var(--dz-border, #2d3348) 78%, white 22%);
    background:
        linear-gradient(180deg, rgba(255,255,255,0.03), transparent 18%),
        var(--dz-surface, #1a1f2e);
    box-shadow: 0 24px 80px rgba(2, 6, 23, 0.45);
}
.dz-player-modal__dialog--wide {
    width: min(1040px, 100%);
}
.dz-player-modal__close {
    position: absolute;
    top: 0.85rem;
    right: 0.85rem;
    width: 2rem;
    height: 2rem;
    border: 1px solid var(--dz-border, #2d3348);
    border-radius: 999px;
    background: rgba(255,255,255,0.04);
    color: inherit;
    font-size: 1rem;
    cursor: pointer;
}
.dz-player-modal__header {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    margin-bottom: 1.1rem;
    padding-right: 2.5rem;
}
.dz-player-modal__eyebrow {
    margin: 0;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--dz-accent, #60a5fa);
}
.dz-player-modal__form {
    display: grid;
    gap: 0.95rem;
}
.dz-player-modal__field {
    display: grid;
    gap: 0.4rem;
}
.dz-player-modal__field > span,
.dz-player-modal__meta-label {
    font-size: 0.78rem;
    color: var(--dz-muted);
}
.dz-player-modal__meta {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 0.75rem;
    padding: 0.95rem 1rem;
    border: 1px solid var(--dz-border, #2d3348);
    border-radius: 0.8rem;
    background: rgba(11, 15, 25, 0.55);
}
.dz-player-modal__meta > div {
    display: grid;
    gap: 0.2rem;
}
.dz-player-modal__actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.7rem;
    margin-top: 0.25rem;
}
.dz-inventory-modal__body {
    display: grid;
    gap: 1rem;
}
</style>

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

    window.pteroKickPlayer = function (playerId, playerActionId, playerName) {
        if (!confirm('Kick this player from the server now?')) { return; }
        setStatus('Sending kick command…', undefined, playerActionId);
        req('POST', '/dayz/player-actions/kick', { player_id: playerId, player_name: playerName || '' })
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

    var GIVE_MONEY_CLASSES = {
        '1': 'MoneyRuble1',
        '5': 'MoneyRuble5',
        '10': 'MoneyRuble10',
        '25': 'MoneyRuble25',
        '50': 'MoneyRuble50',
        '100': 'MoneyRuble100'
    };
    var giveMoneyModal = document.getElementById('dz-give-money-modal');
    var giveMoneyForm = document.getElementById('dz-give-money-form');
    var giveMoneyState = null;

    function setGiveMoneyModalStatus(message, ok) {
        var el = document.getElementById('dz-give-money-modal-status');
        if (!el) { return; }
        if (!message) {
            el.textContent = '';
            el.className = 'dz-status dz-hidden';
            return;
        }
        el.textContent = message;
        el.className = 'dz-status' + (ok === false ? ' dz-status-error' : '');
    }

    function updateGiveMoneySummary() {
        var denomEl = document.getElementById('dz-give-money-denomination');
        var qtyEl = document.getElementById('dz-give-money-quantity');
        var classEl = document.getElementById('dz-give-money-class');
        var totalEl = document.getElementById('dz-give-money-total');
        if (!denomEl || !qtyEl || !classEl || !totalEl) { return; }
        var denom = String(denomEl.value || '100');
        var quantity = Math.max(1, Math.min(99, parseInt(qtyEl.value, 10) || 1));
        qtyEl.value = String(quantity);
        classEl.textContent = GIVE_MONEY_CLASSES[denom] || 'Unknown';
        totalEl.textContent = String((parseInt(denom, 10) || 0) * quantity) + ' coins';
    }

    window.pteroCloseGiveMoneyModal = function () {
        if (!giveMoneyModal) { return; }
        giveMoneyModal.style.display = 'none';
        setGiveMoneyModalStatus('', undefined);
        giveMoneyState = null;
    };

    window.pteroGiveMoney = function (playerId, playerName, isOnline, playerActionId, playerUid) {
        if (!giveMoneyModal) { return; }
        giveMoneyState = {
            playerId: playerId,
            playerUid: playerUid || playerId,
            playerName: playerName,
            playerActionId: playerActionId,
            isOnline: !!isOnline
        };
        var title = document.getElementById('dz-give-money-modal-title');
        var denomEl = document.getElementById('dz-give-money-denomination');
        var qtyEl = document.getElementById('dz-give-money-quantity');
        if (title) { title.textContent = 'Give cash to ' + playerName; }
        if (denomEl) { denomEl.value = '100'; }
        if (qtyEl) { qtyEl.value = '1'; }
        updateGiveMoneySummary();
        setGiveMoneyModalStatus('', undefined);
        giveMoneyModal.style.display = 'block';
    };

    if (giveMoneyForm) {
        giveMoneyForm.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!giveMoneyState) { return; }
            var denomEl = document.getElementById('dz-give-money-denomination');
            var qtyEl = document.getElementById('dz-give-money-quantity');
            var submitBtn = document.getElementById('dz-give-money-submit');
            var denomination = parseInt((denomEl && denomEl.value) || '100', 10);
            var quantity = Math.max(1, Math.min(99, parseInt((qtyEl && qtyEl.value) || '1', 10) || 1));
            if (!GIVE_MONEY_CLASSES[String(denomination)]) {
                setGiveMoneyModalStatus('Invalid denomination selected.', false);
                return;
            }
            if (submitBtn) { submitBtn.disabled = true; }
            setGiveMoneyModalStatus('Queueing give money…', undefined);
            setStatus('Queueing give money…', undefined, giveMoneyState.playerActionId);
            req('POST', '/dayz/player-actions/give-money', {
                player_id: giveMoneyState.playerId,
                player_uid: giveMoneyState.playerUid,
                player_name: giveMoneyState.playerName,
                denomination: denomination,
                quantity: quantity
            })
                .then(function (d) {
                    var ok = d.status === 'queued';
                    setGiveMoneyModalStatus(d.message || (ok ? 'Money queued successfully.' : 'Failed to queue money.'), ok ? undefined : false);
                    setStatus(d.message || (ok ? 'Money queued successfully.' : 'Failed to queue money.'), ok ? undefined : false, giveMoneyState.playerActionId);
                    if (ok) { setTimeout(function () { window.location.reload(); }, 1200); }
                })
                .catch(function () {
                    setGiveMoneyModalStatus('Give money request failed.', false);
                    setStatus('Give money request failed.', false, giveMoneyState.playerActionId);
                })
                .finally(function () {
                    if (submitBtn) { submitBtn.disabled = false; }
                });
        });
    }

    ['dz-give-money-denomination', 'dz-give-money-quantity'].forEach(function (id) {
        var field = document.getElementById(id);
        if (field) {
            field.addEventListener('input', updateGiveMoneySummary);
            field.addEventListener('change', updateGiveMoneySummary);
        }
    });

    window.pteroAlterBankMoney = function (steam64, playerName, currentMoney, playerActionId) {
        if (!steam64) { return; }
        var current = (currentMoney === null || currentMoney === undefined) ? '' : String(currentMoney);
        var answer = window.prompt('Set LB Banking money for "' + playerName + '"'
            + (current !== '' ? '\n\nCurrent balance: ' + current : '\n\nNo balance could be read from LB Banking.'), current);
        if (answer === null) { return; }
        answer = String(answer).trim();
        if (answer === '' || isNaN(Number(answer))) {
            setStatus('Enter a numeric bank money amount.', false, playerActionId);
            return;
        }
        setStatus('Updating bank money…', undefined, playerActionId);
        req('POST', '/dayz/player-actions/bank-money', {
            steam64: steam64,
            player_name: playerName,
            amount: Number(answer)
        })
            .then(function (d) {
                var ok = d.status === 'saved';
                setStatus(d.message || (ok ? 'Bank money updated.' : 'Failed to update bank money.'), ok ? undefined : false, playerActionId);
                if (ok) { setTimeout(function () { window.location.reload(); }, 1200); }
            })
            .catch(function () { setStatus('Bank money request failed.', false, playerActionId); });
    };

    window.pteroRemoveGiveMoney = function (queueId, playerName, itemClass, quantity) {        if (!queueId) { return; }
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

        function isContainerKey(key) {
            return ['contents', 'cargo', 'inventory', 'items', 'children', 'attachments', 'attached', 'pockets', 'storage', 'containers'].indexOf(String(key || '').toLowerCase()) !== -1;
        }

        function collectEntry(entry, parentSlot, parentClass, inContainer) {
            if (!entry || typeof entry !== 'object') { return; }
            var cls = stringField(entry, ['className', 'classname', 'class', 'type', 'item', 'itemClass', 'item_class']);
            var itemId = stringField(entry, ['itemId', 'item_id', 'internalId', 'internal_id', 'identifier', 'id']);
            var itemName = stringField(entry, ['name', 'itemName', 'item_name', 'displayName', 'display_name', 'label', 'title']);
            var slot = typeof entry.slot === 'string' ? entry.slot : (parentSlot || '');
            if (cls !== '') {
                items.push({
                    slot: slot,
                    className: cls,
                    itemId: itemId,
                    name: itemName,
                    isContainerContent: !!inContainer,
                    containerSlot: parentSlot || '',
                    containerClass: parentClass || ''
                });
            }
            ['contents', 'cargo', 'inventory', 'items', 'children', 'attachments', 'attached', 'pockets', 'storage', 'containers'].forEach(function (key) {
                var value = entry[key];
                if (Array.isArray(value)) {
                    value.forEach(function (sub) { collectEntry(sub, slot || parentSlot || 'Unknown', cls || parentClass || '', true); });
                    return;
                }
                if (value && typeof value === 'object') {
                    Object.keys(value).forEach(function (subKey) {
                        var nested = value[subKey];
                        if (Array.isArray(nested)) {
                            nested.forEach(function (sub) { collectEntry(sub, slot || parentSlot || subKey || 'Unknown', cls || parentClass || '', true); });
                        } else {
                            collectEntry(nested, slot || parentSlot || subKey || 'Unknown', cls || parentClass || '', true);
                        }
                    });
                }
            });

            Object.keys(entry).forEach(function (key) {
                if (isContainerKey(key) || key === 'slot') { return; }
                var value = entry[key];
                if (!value || typeof value !== 'object') { return; }
                if (Array.isArray(value)) {
                    value.forEach(function (sub) {
                        collectEntry(sub, slot || parentSlot || key || 'Unknown', cls || parentClass || '', !!inContainer);
                    });
                    return;
                }
                collectEntry(value, slot || parentSlot || key || 'Unknown', cls || parentClass || '', !!inContainer);
            });
        }

        if (Array.isArray(parsed)) {
            parsed.forEach(function (entry) {
                if (typeof entry === 'string' && entry !== '') {
                    items.push({ slot: '', className: entry, itemId: '', name: '', isContainerContent: false });
                } else {
                    collectEntry(entry, '', '', false);
                }
            });
        }

        if (items.length === 0) {
            // Deep-walk fallback for any other inventory structure.
            (function walk(obj, parentSlot, parentClass, inContainer) {
                if (!obj || typeof obj !== 'object') { return; }
                if (Array.isArray(obj)) { obj.forEach(function (v) { walk(v, parentSlot, parentClass, inContainer); }); return; }
                var cls = stringField(obj, ['className', 'classname', 'class', 'type', 'item', 'itemClass', 'item_class']);
                if (cls && typeof cls === 'string') {
                    items.push({
                        slot: obj.slot || parentSlot || '',
                        className: cls,
                        itemId: stringField(obj, ['itemId', 'item_id', 'internalId', 'internal_id', 'identifier', 'id']),
                        name: stringField(obj, ['name', 'itemName', 'item_name', 'displayName', 'display_name', 'label', 'title']),
                        isContainerContent: !!inContainer,
                        containerSlot: parentSlot || '',
                        containerClass: parentClass || ''
                    });
                }
                Object.keys(obj).forEach(function (key) {
                    var value = obj[key];
                    if (!value || typeof value !== 'object') { return; }
                    var keyName = String(key || '').toLowerCase();
                    var nextContainer = !!inContainer || isContainerKey(keyName);
                    var nextSlot = obj.slot || parentSlot || (nextContainer ? 'Unknown' : key);
                    walk(value, nextSlot, cls || parentClass || '', nextContainer);
                });
            }(parsed, '', '', false));
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
        var slotOrder = ['Hands', 'Headgear', 'Mask', 'Eyewear', 'Gloves', 'Armband', 'Body', 'Legs', 'Vest', 'Hips', 'Back', 'Feet', 'Shoulder', 'Melee', ''];
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

        var summary = document.createElement('div');
        summary.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:0.75rem;margin-bottom:0.25rem;';
        [
            { label: 'Equipped', value: String(equippedItems.length) },
            { label: 'Container items', value: String(containerItems.length) },
            { label: 'Sections', value: String(orderedSlots.length + (containerItems.length ? 1 : 0)) }
        ].forEach(function (meta) {
            var box = document.createElement('div');
            box.style.cssText = 'padding:0.9rem 1rem;border:1px solid var(--dz-border,#2d3348);border-radius:0.85rem;background:rgba(11,15,25,0.5);display:grid;gap:0.2rem;';
            box.innerHTML = '<span style="font-size:0.74rem;color:var(--dz-muted);">' + escapeHtml(meta.label) + '</span><strong style="font-size:1.15rem;">' + escapeHtml(meta.value) + '</strong>';
            summary.appendChild(box);
        });
        body.appendChild(summary);

        var cardStyle = 'background:linear-gradient(180deg, rgba(255,255,255,0.03), transparent 24%), var(--dz-surface-alt,#0b0f19);border:1px solid var(--dz-border,#2d3348);border-radius:0.85rem;padding:0.85rem 0.75rem 0.7rem;font-size:0.78rem;overflow:hidden;display:flex;flex-direction:column;align-items:center;gap:0.5rem;min-height:172px;box-shadow:0 12px 32px rgba(2,6,23,0.18);';
        var imgStyle  = 'width:84px;height:84px;object-fit:contain;display:block;';
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
            var label = fallbackLabel;

            cardBits.nameEl.innerHTML = '';
            var span = document.createElement('span');
            span.textContent = label;
            span.title = [
                cardBits.item.className || label,
                resolved && resolved.variant ? resolved.variant : '',
                resolved && (resolved.type || resolved.category) ? (resolved.type || resolved.category) : ''
            ].filter(Boolean).join(' · ');
            cardBits.nameEl.appendChild(span);

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
            nameEl.style.cssText = 'word-break:break-word;text-align:center;line-height:1.25;font-weight:600;';
            nameEl.textContent = label;
            nameEl.title = item.className || label;
            card.appendChild(nameEl);

            var metaEl = document.createElement('div');
            metaEl.style.cssText = 'font-size:0.7rem;color:var(--dz-muted);text-align:center;line-height:1.3;';
            metaEl.textContent = item.slot || item.containerSlot || 'Inventory';
            card.appendChild(metaEl);

            var key = inventoryLookupKey(item);
            if (!cardsByLookup[key]) { cardsByLookup[key] = []; }
            cardsByLookup[key].push({ item: item, imgWrap: imgWrap, nameEl: nameEl, metaEl: metaEl });

            return card;
        }

        var frag = document.createDocumentFragment();

        orderedSlots.forEach(function (slot) {
            var section = document.createElement('div');
            section.style.cssText = 'margin-bottom:1rem;padding:1rem;border:1px solid var(--dz-border,#2d3348);border-radius:0.95rem;background:rgba(11,15,25,0.38);';

            if (slot !== '') {
                var heading = document.createElement('p');
                heading.textContent = slot;
                heading.style.cssText = 'font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--dz-muted);margin:0 0 0.6rem;';
                section.appendChild(heading);
            }

            var grid = document.createElement('div');
            grid.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:0.5rem;';

            bySlot[slot].forEach(function (item) { grid.appendChild(makeItemCard(item)); });

            section.appendChild(grid);
            frag.appendChild(section);
        });

        // Container contents — one dedicated section per container (backpack, pants, etc.).
        if (containerItems.length > 0) {
            // Group container items by parent slot + class.
            var byContainer = {};
            var containerOrder = [];
            containerItems.forEach(function (item) {
                var k = [item.containerSlot || item.slot || 'Unknown', item.containerClass || ''].join('|');
                if (!byContainer[k]) { byContainer[k] = []; containerOrder.push(k); }
                byContainer[k].push(item);
            });
            var containerSlotOrder = ['Legs', 'Back', 'Body', 'Hips'];
            containerOrder.sort(function (left, right) {
                var leftIndex = containerSlotOrder.indexOf(left.split('|')[0]);
                var rightIndex = containerSlotOrder.indexOf(right.split('|')[0]);
                leftIndex = leftIndex === -1 ? containerSlotOrder.length : leftIndex;
                rightIndex = rightIndex === -1 ? containerSlotOrder.length : rightIndex;
                return leftIndex - rightIndex;
            });

            containerOrder.forEach(function (containerKey) {
                var keyParts = containerKey.split('|');
                var containerSlot = keyParts[0];
                var containerClass = keyParts.slice(1).join('|');
                var containerLabel = containerSlot === 'Back' ? 'Backpack' : (containerSlot === 'Legs' ? 'Pants' : (containerSlot === 'Body' ? 'Vest' : (containerSlot === 'Hips' ? 'Holster / Belt' : containerSlot)));

                var contSec = document.createElement('div');
                contSec.style.cssText = 'margin-bottom:1rem;padding:1rem;border:1px solid var(--dz-border,#2d3348);border-radius:0.95rem;background:rgba(11,15,25,0.38);';

                var contHead = document.createElement('p');
                contHead.textContent = containerLabel + (containerClass ? ' — ' + containerClass : '');
                contHead.style.cssText = 'font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--dz-muted);margin:0 0 0.75rem;';
                contSec.appendChild(contHead);

                var grid = document.createElement('div');
                grid.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:0.5rem;';
                byContainer[containerKey].forEach(function (item) { grid.appendChild(makeItemCard(item)); });
                contSec.appendChild(grid);

                frag.appendChild(contSec);
            });
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
    if (giveMoneyModal) {
        giveMoneyModal.addEventListener('click', function (e) {
            if (e.target === this) { window.pteroCloseGiveMoneyModal(); }
        });
    }
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
