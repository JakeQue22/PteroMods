<link id="dz-leaflet-css" rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous"
      onerror="this.onerror=null;this.href='https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css';" />

<section class="dz-card">
    <h2>Live Map</h2>
    <p class="dz-sub">
        Live player positions come from a server-side bridge snapshot in <code>/profiles/PteroMods/live_map_players.json</code>.
        Overlay markers (named locations, animal and infected territories, loot and helicopter crash
        events, vehicles, and player spawn points) are read from the server's own mission files and can
        be switched on individually from the layer control in the top-right corner of the map.
        Public viewer: <a href="https://dayz.xam.nu" target="_blank" rel="noopener noreferrer">dayz.xam.nu</a>.
        This panel uses the raw xam.nu tile template behind that viewer
        (configurable via <a href="{{ $base_url }}/settings">Settings → Live Map tile URL</a>).
    </p>
    <div class="dz-form">
        <input id="dz-live-map-search" class="dz-input" type="search" placeholder="Search players by name or Steam64" />
        <button class="dz-btn dz-btn-ghost" type="button" onclick="window.pteroLiveMapResetCamera()">Reset View</button>
        <button class="dz-btn dz-btn-ghost" type="button" onclick="window.pteroLiveMapFullscreen()">Fullscreen</button>
    </div>
    <div class="dz-form" style="margin-top:0.65rem;">
        <button id="dz-live-map-bridge-refresh" class="dz-btn dz-btn-ghost" type="button">Bridge Status</button>
        <button id="dz-live-map-bridge-deploy" class="dz-btn" type="button">Deploy Bridge</button>
        <button id="dz-live-map-bridge-remove" class="dz-btn dz-btn-red" type="button">Remove Bridge</button>
        <span id="dz-live-map-bridge-msg" class="dz-status dz-hidden"></span>
    </div>
    <p class="dz-sub" id="dz-live-map-meta">
        <span id="dz-live-map-count">0</span> player(s) online ·
        Last update: <span id="dz-live-map-updated">—</span> ·
        Map: <span id="dz-live-map-name">{{ $map ?? 'ChernarusPlus' }}</span>
    </p>
</section>

<section class="dz-card dz-live-map-layout">
    <div id="dz-live-map-canvas" class="dz-live-map-canvas" aria-label="DayZ live map viewer"></div>
    <aside class="dz-live-map-sidebar">
        <h3 id="dz-live-map-players-heading">Players (0/64)</h3>
        <div id="dz-live-map-status" class="dz-sub"></div>
        <ul id="dz-live-map-list" class="dz-live-map-list"></ul>
        <div id="dz-live-map-player" class="dz-live-map-player">
            <p class="dz-sub">Select a player marker to view details.</p>
        </div>
    </aside>
</section>

<script>
(function () {
    const SERVER_ID   = @json($server_id);
    const SNAPSHOT_URL = '/api/server/' + encodeURIComponent(SERVER_ID) + '/dayz/live-map/snapshot';
    const MARKERS_URL  = '/api/server/' + encodeURIComponent(SERVER_ID) + '/dayz/live-map/markers';
    const BRIDGE_STATUS_URL = '/api/server/' + encodeURIComponent(SERVER_ID) + '/dayz/live-map/bridge-status';
    const BRIDGE_DEPLOY_URL = '/api/server/' + encodeURIComponent(SERVER_ID) + '/dayz/live-map/setup-bridge';
    const BRIDGE_REMOVE_URL = '/api/server/' + encodeURIComponent(SERVER_ID) + '/dayz/live-map/remove-bridge';
    const PLAYERS_URL  = @json($base_url) + '/players';
    const POLL_MS      = 7000;
    const PLAYER_CAPACITY = 64;
    const PINNED_STEAM64 = '76561197992590837';
    // Refreshing the mission-derived overlays is expensive (it reads the
    // server's mission XML through Wings), so they are reloaded far less often
    // than the player snapshot.
    const MARKERS_MS   = 300000;

    // ?focus=<steam64|uid> opens the map centred on a single player, which is
    // what the "Show on live map" links on the player manager page use.
    const FOCUS_ID = (new URLSearchParams(window.location.search).get('focus') || '').trim();

    // Tile URL template from panel settings, e.g.:
    // https://static.xam.nu/dayz/maps/{map}/1.27/satellite/{z}/{x}/{y}.webp
    // The public viewer URL (https://dayz.xam.nu/#...) is not a Leaflet tile template.
    // {map} is replaced with the map's tile id; {z}/{x}/{y} are Leaflet placeholders.
    const TILE_URL_TPL = @json($tile_url ?? '');
    const INITIAL_SUPERADMINS = @json(array_values($superadmin_ids ?? []));

    const state = {
        mapDef: @json($map_definition),
        players: new Map(),   // steam64 → player data
        markers: new Map(),   // steam64 → L.Marker
        superadmins: new Set((Array.isArray(INITIAL_SUPERADMINS) ? INITIAL_SUPERADMINS : []).map(function (id) {
            return String(id || '').trim();
        }).filter(Boolean)),
        selected: '',
        search: '',
        list: [],
        leafletMap: null,
        tileLayer: null,
        locationLayer: null,
        gridLayer: null,
        playerLayer: null,
        markerLayers: {},
        layerControl: null,
        directory: new Map(),  // steam64/uid → persisted player record
        focused: false,
        tileError: false,
        notice: '',
        statusText: '',
    };

    const root      = document.getElementById('dz-live-map-canvas');
    const statusEl  = document.getElementById('dz-live-map-status');
    const listEl    = document.getElementById('dz-live-map-list');
    const detailEl  = document.getElementById('dz-live-map-player');
    const playersHeadingEl = document.getElementById('dz-live-map-players-heading');
    const countEl   = document.getElementById('dz-live-map-count');
    const updatedEl = document.getElementById('dz-live-map-updated');
    const mapNameEl = document.getElementById('dz-live-map-name');
    const searchEl  = document.getElementById('dz-live-map-search');
    const bridgeRefreshBtn = document.getElementById('dz-live-map-bridge-refresh');
    const bridgeDeployBtn  = document.getElementById('dz-live-map-bridge-deploy');
    const bridgeRemoveBtn  = document.getElementById('dz-live-map-bridge-remove');
    const bridgeMsgEl      = document.getElementById('dz-live-map-bridge-msg');

    // ── Coordinate system ────────────────────────────────────────────────────
    // DayZ uses a flat world grid: x = east, z = south (increasing).
    // Leaflet L.CRS.Simple uses (lat, lng).  With the transformation below,
    // world coord (x, z) maps to L.latLng(z, x) and the tile layer lines up.
    //
    // L.Transformation(a, b, c, d):
    //   px_x = a * lng + b  →  a = 256/worldSize, b = 0     (east → right)
    //   px_y = c * lat + d  →  c = -256/worldSize, d = 256  (south → down)
    //
    // Source: Sk3tch-Dev-Ux/citadel-server-manager InteractiveMap.jsx
    function buildCRS(worldSize) {
        const f = 256 / worldSize;
        return L.Util.extend({}, L.CRS.Simple, {
            transformation: new L.Transformation(f, 0, -f, 256),
        });
    }

    function dayzToLatLng(x, z) {
        return L.latLng(z, x);
    }

    function normaliseId(value) {
        return String(value || '').trim();
    }

    function applySuperadmins(ids) {
        state.superadmins = new Set((Array.isArray(ids) ? ids : []).map(normaliseId).filter(Boolean));
    }

    function playerIds(player) {
        const entry = directoryEntry(player) || {};
        const ids = [
            player && player.real_steam64,
            player && player.steam64_raw,
            player && player.steam64,
            entry.steam64,
        ].map(normaliseId).filter(Boolean);

        return ids.filter(function (id, index) {
            return ids.indexOf(id) === index;
        });
    }

    function isSuperadmin(player) {
        return playerIds(player).some(function (id) {
            return state.superadmins.has(id);
        });
    }

    function isPinnedPlayer(player) {
        return playerIds(player).some(function (id) {
            return id === PINNED_STEAM64;
        });
    }

    function superadminIconHtml() {
        return '<span class="dz-live-map-superadmin-icon" title="SuperAdmin" aria-label="SuperAdmin">🛡️</span>';
    }

    function playerNameHtml(player) {
        const name = escapeHtml(player.name || player.steam64 || 'Unknown player');
        return isSuperadmin(player) ? superadminIconHtml() + '<span>' + name + '</span>' : '<span>' + name + '</span>';
    }

    function worldBounds(mapDef) {
        const worldSize = Number(mapDef.world_size || 15360);

        return L.latLngBounds(dayzToLatLng(0, 0), dayzToLatLng(worldSize, worldSize));
    }

    // ── Map name → xam.nu tile ID ─────────────────────────────────────────
    function tileId(mapDef) {
        return (mapDef.id || mapDef.name || 'chernarusplus').toLowerCase().replace(/[^a-z0-9]/g, '');
    }

    function buildTileUrl(mapDef) {
        if (!TILE_URL_TPL) {
            return null;
        }
        return TILE_URL_TPL.replace('{map}', tileId(mapDef));
    }

    // ── Player marker icon ────────────────────────────────────────────────
    function playerIcon(alive, selected) {
        const fill   = alive !== false ? '#34d399' : '#f87171';
        const stroke = selected ? '#ffffff' : '#0b0f19';
        const sw     = selected ? 2.5 : 1.5;
        const r      = selected ? 7 : 6;
        const size   = selected ? 20 : 16;
        return L.divIcon({
            html: '<svg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size + '">'
                + '<circle cx="' + (size / 2) + '" cy="' + (size / 2) + '" r="' + r + '" '
                + 'fill="' + fill + '" stroke="' + stroke + '" stroke-width="' + sw + '"/></svg>',
            className: '',
            iconSize:   [size, size],
            iconAnchor: [size / 2, size / 2],
            tooltipAnchor: [0, -(size / 2 + 2)],
        });
    }

    // ── Initialise Leaflet ────────────────────────────────────────────────
    function initLeaflet() {
        const worldSize = Number(state.mapDef.world_size || 15360);
        const crs = buildCRS(worldSize);

        state.leafletMap = L.map(root, {
            crs:        crs,
            minZoom:    -2,
            maxZoom:    7,
            zoomSnap:   0.25,
            zoomDelta:  0.5,
            attributionControl: false,
        });

        L.control.attribution({ prefix: false })
            .addAttribution('Tiles © <a href="https://dayz.xam.nu" target="_blank">xam.nu</a>')
            .addTo(state.leafletMap);

        applyTileLayer(state.mapDef);
        applyGridLayer(state.mapDef);

        // Keep player icons above all other marker overlays.
        state.leafletMap.createPane('pteromodsPlayers');
        state.leafletMap.getPane('pteromodsPlayers').style.zIndex = '650';

        // The player overlay is a layer of its own so it can be toggled from
        // the layer control like every other marker category.
        state.playerLayer = L.layerGroup().addTo(state.leafletMap);
        state.layerControl = L.control.layers(null, { '👤 Players': state.playerLayer }, {
            collapsed: true,
            position: 'topright',
        }).addTo(state.leafletMap);

        const center = dayzToLatLng(worldSize / 2, worldSize / 2);
        state.leafletMap.setView(center, 0);

        state.leafletMap.whenReady(function () {
            state.leafletMap.invalidateSize(false);
            state.leafletMap.fitBounds(worldBounds(state.mapDef));
        });

        // The canvas is laid out by CSS grid, and fullscreen changes its size,
        // so Leaflet needs to re-measure or it renders an empty viewport.
        window.addEventListener('resize', function () {
            state.leafletMap.invalidateSize(false);
        });
        document.addEventListener('fullscreenchange', function () {
            state.leafletMap.invalidateSize(false);
        });
    }

    function applyTileLayer(mapDef) {
        if (state.tileLayer) {
            state.leafletMap.removeLayer(state.tileLayer);
            state.tileLayer = null;
        }

        state.tileError = false;

        const url = buildTileUrl(mapDef);
        if (!url) {
            setNotice('No live map tile URL is configured — showing the coordinate grid only. '
                + 'Set one in Settings → Live Map tile URL.');
            return;
        }

        setNotice('');

        state.tileLayer = L.tileLayer(url, {
            tileSize:        256,
            minNativeZoom:   0,
            maxNativeZoom:   7,
            minZoom:        -2,
            maxZoom:         7,
            noWrap:          true,
            // Never request tiles outside the world, so a missing edge tile
            // cannot be mistaken for a broken tile server.
            bounds:          worldBounds(mapDef),
            errorTileUrl:    '',
        });

        state.tileLayer.on('tileerror', function () {
            if (state.tileError) {
                return;
            }

            state.tileError = true;
            setNotice('Map tiles could not be loaded from the configured tile URL — showing the '
                + 'coordinate grid only. Check Settings → Live Map tile URL.');
        });

        state.tileLayer.addTo(state.leafletMap);
    }

    // Always-visible world outline and 1 km grid, so the viewer still shows a
    // usable map when the external tile server is unreachable or unconfigured.
    function applyGridLayer(mapDef) {
        if (state.gridLayer) {
            state.leafletMap.removeLayer(state.gridLayer);
            state.gridLayer = null;
        }

        const worldSize = Number(mapDef.world_size || 15360);
        const step      = 1000;

        state.gridLayer = L.layerGroup();

        for (let coord = 0; coord <= worldSize; coord += step) {
            L.polyline([dayzToLatLng(coord, 0), dayzToLatLng(coord, worldSize)], {
                color: '#1f2937', weight: 1, interactive: false,
            }).addTo(state.gridLayer);

            L.polyline([dayzToLatLng(0, coord), dayzToLatLng(worldSize, coord)], {
                color: '#1f2937', weight: 1, interactive: false,
            }).addTo(state.gridLayer);
        }

        L.rectangle([dayzToLatLng(0, 0), dayzToLatLng(worldSize, worldSize)], {
            color: '#334155', weight: 1, fill: false, interactive: false,
        }).addTo(state.gridLayer);

        state.gridLayer.addTo(state.leafletMap);
    }

    function applyLocationLayer(mapDef) {
        if (state.locationLayer) {
            state.leafletMap.removeLayer(state.locationLayer);
            state.locationLayer = null;
        }

        const locations = Array.isArray(mapDef.locations) ? mapDef.locations : [];
        if (locations.length === 0) {
            return;
        }

        state.locationLayer = L.layerGroup();

        locations.forEach(function (loc) {
            const latlng = dayzToLatLng(Number(loc.x || 0), Number(loc.z || 0));
            L.circleMarker(latlng, {
                radius:      3,
                color:       '#fbbf24',
                fillColor:   '#fbbf24',
                fillOpacity: 1,
                weight:      0,
                interactive: false,
            }).addTo(state.locationLayer);

            L.marker(latlng, {
                icon: L.divIcon({
                    html: '<span class="dz-map-loc">' + escapeHtml(String(loc.name || '')) + '</span>',
                    className: '',
                    iconAnchor: [-5, 6],
                }),
                interactive: false,
            }).addTo(state.locationLayer);
        });

        state.locationLayer.addTo(state.leafletMap);
    }

    // ── Marker overlays ───────────────────────────────────────────────────
    // Categories (animals, infected, loot, vehicles, helicopter crashes,
    // player spawns, named locations, …) come from the panel, which reads the
    // server's own mission files, so every icon option the server actually
    // provides is offered here.
    function categoryIcon(icon, color) {
        return L.divIcon({
            html: '<span class="dz-map-marker" style="border-color:' + color + '">' + icon + '</span>',
            className: '',
            iconSize: [18, 18],
            iconAnchor: [9, 9],
            tooltipAnchor: [0, -11],
        });
    }

    function clearMarkerLayers() {
        Object.keys(state.markerLayers).forEach(function (key) {
            const layer = state.markerLayers[key];

            if (state.layerControl) {
                state.layerControl.removeLayer(layer);
            }

            state.leafletMap.removeLayer(layer);
        });

        state.markerLayers = {};
    }

    function applyMarkerGroups(groups) {
        clearMarkerLayers();

        if (!Array.isArray(groups) || groups.length === 0) {
            // Nothing came back from the server, so fall back to the built-in
            // named locations of the map definition.
            applyLocationLayer(state.mapDef);
            return;
        }

        if (state.locationLayer) {
            state.leafletMap.removeLayer(state.locationLayer);
            state.locationLayer = null;
        }

        groups.forEach(function (group) {
            const layer   = L.layerGroup();
            const icon    = categoryIcon(group.icon || '📍', group.color || '#fbbf24');
            const markers = Array.isArray(group.markers) ? group.markers : [];

            markers.forEach(function (marker) {
                const latlng = dayzToLatLng(Number(marker.x || 0), Number(marker.z || 0));
                const name   = escapeHtml(marker.name || group.label);
                const detail = escapeHtml(marker.detail || group.label);

                L.marker(latlng, { icon: icon })
                    .bindTooltip(name, { direction: 'top' })
                    .bindPopup('<strong>' + name + '</strong><br>' + detail
                        + '<br>' + Number(marker.x || 0).toFixed(0) + ', ' + Number(marker.z || 0).toFixed(0)
                        + (marker.radius ? '<br>Radius: ' + Number(marker.radius).toFixed(0) + ' m' : ''))
                    .addTo(layer);
            });

            state.markerLayers[group.key] = layer;
            state.layerControl.addOverlay(layer, (group.icon || '📍') + ' ' + (group.label || group.key)
                + ' (' + (group.count || markers.length) + ')');

            if (group.default) {
                layer.addTo(state.leafletMap);
            }
        });
    }

    function applyDirectory(entries) {
        state.directory.clear();

        (Array.isArray(entries) ? entries : []).forEach(function (entry) {
            if (!entry) {
                return;
            }

            [entry.steam64, entry.player_id, entry.player_uid].forEach(function (key) {
                if (key) {
                    state.directory.set(String(key), entry);
                }
            });
        });
    }

    async function fetchMarkers() {
        try {
            const res = await fetch(MARKERS_URL, {
                method:      'GET',
                credentials: 'same-origin',
                headers:     { 'Accept': 'application/json' },
            });

            if (!res.ok) {
                throw new Error('HTTP ' + res.status);
            }

            const payload = await res.json();
            applyMarkerGroups(payload.groups);
            applyDirectory(payload.player_directory);
            state.players.forEach(function (player, steam64) {
                const marker = state.markers.get(steam64);
                if (marker) {
                    marker.setPopupContent(playerPopupHtml(player));
                }
            });
            renderPlayerList();
            renderSelection();
        } catch (err) {
            applyMarkerGroups([]);
        }
    }

    async function loadMarkers() {
        await fetchMarkers();
        setTimeout(loadMarkers, MARKERS_MS);
    }

    // ── Snapshot application ─────────────────────────────────────────────
    function applySnapshot(payload) {
        const incomingMapDef = payload.map_definition || null;

        if (incomingMapDef && incomingMapDef.name !== state.mapDef.name) {
            state.mapDef = incomingMapDef;

            const worldSize = Number(state.mapDef.world_size || 15360);
            // Rebuild CRS and reinitialise layers for new map.
            state.leafletMap.options.crs = buildCRS(worldSize);
            applyTileLayer(state.mapDef);
            applyGridLayer(state.mapDef);
            fetchMarkers();
            state.leafletMap.fitBounds(worldBounds(state.mapDef));

            // Remove all existing markers (they belong to the old map).
            state.playerLayer.clearLayers();
            state.markers.clear();
            state.players.clear();
        } else if (incomingMapDef) {
            state.mapDef = incomingMapDef;
        }

        mapNameEl.textContent = state.mapDef.name || payload.map || 'Unknown';
        updatedEl.textContent = new Date(payload.last_update || Date.now()).toLocaleString();

        switch (payload.status) {
            case 'waiting_for_bridge':
                setStatusText('Waiting for bridge snapshot — deploy bridge and restart the server.');
                break;
            case 'stale_snapshot':
                setStatusText('Bridge deployed but the server has not started writing snapshots yet — restart the server so the bridge script runs.');
                break;
            case 'invalid_bridge_payload':
                setStatusText('Bridge snapshot exists but could not be decoded (check secret config).');
                break;
            case 'error':
                setStatusText('Snapshot error: ' + (payload.message || 'Unknown error.'));
                break;
            default:
                setStatusText('');
        }

        applySuperadmins(payload.superadmin_ids);

        const players = Array.isArray(payload.players) ? payload.players : [];
        const seen    = new Set();

        players.forEach(function (player) {
            if (!player || !player.steam64) {
                return;
            }

            seen.add(player.steam64);
            state.players.set(player.steam64, player);

            const pos     = dayzToLatLng(Number(player.x || 0), Number(player.z || 0));
            const sel     = state.selected === player.steam64;
            const icon    = playerIcon(player.alive, sel);
            const tooltip = escapeHtml(player.name || player.steam64);

            if (!state.markers.has(player.steam64)) {
                const marker = L.marker(pos, { icon: icon, pane: 'pteromodsPlayers', zIndexOffset: sel ? 1000 : 0 })
                    .addTo(state.playerLayer)
                    .bindTooltip(tooltip, { direction: 'top', permanent: false })
                    .bindPopup(playerPopupHtml(player));

                marker.on('click', function () {
                    selectPlayer(player.steam64);
                });

                state.markers.set(player.steam64, marker);
            } else {
                const marker = state.markers.get(player.steam64);
                marker.setLatLng(pos);
                marker.setIcon(icon);
                marker.setZIndexOffset(sel ? 1000 : 0);
                marker.setTooltipContent(tooltip);
                marker.setPopupContent(playerPopupHtml(player));
            }
        });

        // Remove departed players.
        Array.from(state.markers.keys()).forEach(function (steam64) {
            if (!seen.has(steam64)) {
                state.playerLayer.removeLayer(state.markers.get(steam64));
                state.markers.delete(steam64);
                state.players.delete(steam64);

                if (state.selected === steam64) {
                    state.selected = '';
                }
            }
        });

        state.list = players.slice();
        countEl.textContent = String(players.length);
        if (playersHeadingEl) {
            playersHeadingEl.textContent = 'Players (' + String(players.length) + '/' + String(PLAYER_CAPACITY) + ')';
        }
        renderPlayerList();
        renderSelection();

        // Honour ?focus=… once, as soon as that player shows up.
        const focusId = resolveFocusId();

        if (focusId !== '' && !state.focused) {
            state.focused = true;
            selectPlayer(focusId);
            focusPlayer(focusId);
            state.leafletMap.setZoom(Math.max(state.leafletMap.getZoom(), 3));
            const focusMarker = state.markers.get(focusId);
            if (focusMarker) {
                focusMarker.openPopup();
            }
        }
    }

    // The player manager links with ?focus=<steam64|DayZ UID>, while markers
    // are keyed by Steam64, so a UID is translated through the directory.
    function resolveFocusId() {
        if (FOCUS_ID === '') {
            return '';
        }

        if (state.markers.has(FOCUS_ID)) {
            return FOCUS_ID;
        }

        const entry = state.directory.get(FOCUS_ID);
        const steam64 = entry && entry.steam64 ? String(entry.steam64) : '';

        return steam64 !== '' && state.markers.has(steam64) ? steam64 : '';
    }

    // ── Player details ────────────────────────────────────────────────────
    // Live snapshot data is merged with the persisted record of the same
    // player (nickname, Steam64, DayZ UID, ban/whitelist state) so the map
    // shows exactly the same information as the player manager page.
    function directoryEntry(player) {
        return state.directory.get(String(player.player_uid || ''))
            || state.directory.get(String(player.steam64 || ''))
            || state.directory.get(String(player.name || ''))
            || null;
    }

    function playerRow(label, value) {
        return '<div><dt>' + escapeHtml(label) + '</dt><dd>' + value + '</dd></div>';
    }

    function playerDetailRows(player) {
        const entry   = directoryEntry(player) || {};
        const lists   = Object.keys(entry.lists || {}).filter(function (key) { return entry.lists[key]; });

        let html = '';

        html += playerRow('Position', Number(player.x || 0).toFixed(1) + ', ' + Number(player.z || 0).toFixed(1));
        html += playerRow('Height', Number(player.y || 0).toFixed(1));
        html += playerRow('Direction', Number(player.direction || 0).toFixed(1) + '°');
        html += playerRow('Status', player.alive !== false ? 'Alive' : 'Dead');

        if (player.health != null) {
            var h = Number(player.health);
            // Older bridge deployments sent health on a 0-10000 scale; normalise.
            if (h > 100) { h = h / 100; }
            html += playerRow('Health', h.toFixed(1) + '%');
        } else {
            html += playerRow('Health', 'N/A');
        }

        if (entry.last_seen_at) {
            html += playerRow('Last seen', escapeHtml(formatDateDMY(entry.last_seen_at)));
        }

        if (lists.length > 0) {
            html += playerRow('Lists', escapeHtml(lists.join(', ')));
        }

        return html;
    }

    function managementLink(player) {
        const needle = String(player.player_uid || player.steam64 || player.name || '');

        return '<a class="dz-map-popup-link" href="'
            + escapeHtml(PLAYERS_URL + '?search=' + encodeURIComponent(needle))
            + '">Manage in Player Manager</a>';
    }

    function playerPopupHtml(player) {
        return '<div class="dz-map-popup"><strong class="dz-live-map-player-heading">' + playerNameHtml(player) + '</strong>'
            + '<dl class="dz-browse-meta">' + playerDetailRows(player) + '</dl>'
            + managementLink(player) + '</div>';
    }

    function roughLocationName(player) {
        const locations = Array.isArray(state.mapDef && state.mapDef.locations) ? state.mapDef.locations : [];

        if (locations.length === 0) {
            return 'Unknown location';
        }

        const x = Number(player.x || 0);
        const z = Number(player.z || 0);
        let nearest = null;
        let nearestDist = Number.POSITIVE_INFINITY;

        locations.forEach(function (loc) {
            const lx = Number(loc.x || 0);
            const lz = Number(loc.z || 0);
            const dx = x - lx;
            const dz = z - lz;
            const distSq = (dx * dx) + (dz * dz);

            if (distSq < nearestDist) {
                nearestDist = distSq;
                nearest = loc;
            }
        });

        return nearest && nearest.name ? String(nearest.name) : 'Unknown location';
    }

    // ── Player list ───────────────────────────────────────────────────────
    function renderPlayerList() {
        const needle   = (state.search || '').toLowerCase();
        const filtered = state.list.map(function (player, index) {
            return { player: player, index: index };
        }).filter(function (entry) {
            const p = entry.player;
            const hay = (String(p.name || '') + ' ' + String(p.steam64 || '')).toLowerCase();
            return needle === '' || hay.indexOf(needle) !== -1;
        }).sort(function (left, right) {
            const leftPinned = isPinnedPlayer(left.player) ? 1 : 0;
            const rightPinned = isPinnedPlayer(right.player) ? 1 : 0;

            if (leftPinned !== rightPinned) {
                return rightPinned - leftPinned;
            }

            const leftAdmin = isSuperadmin(left.player) ? 1 : 0;
            const rightAdmin = isSuperadmin(right.player) ? 1 : 0;

            if (leftAdmin !== rightAdmin) {
                return rightAdmin - leftAdmin;
            }

            return left.index - right.index;
        });

        listEl.innerHTML = '';

        filtered.forEach(function (entry) {
            const player = entry.player;
            const li  = document.createElement('li');
            li.className = 'dz-live-map-list-item' + (state.selected === player.steam64 ? ' is-active' : '');
            li.innerHTML = '<button type="button"><span class="dz-live-map-list-main">' + playerNameHtml(player) + '</span>'
                + '<small>' + escapeHtml(roughLocationName(player)) + '</small></button>';
            li.querySelector('button').addEventListener('click', function () {
                selectPlayer(player.steam64);
                focusPlayer(player.steam64);
                openPlayerPopup(player.steam64);
            });
            listEl.appendChild(li);
        });
    }

    function renderSelection() {
        const player = state.list.find(function (p) { return p.steam64 === state.selected; });

        if (!player) {
            detailEl.innerHTML = '<p class="dz-sub">Select a player marker to view details.</p>';
            return;
        }

        detailEl.innerHTML =
            '<h4 class="dz-live-map-player-heading">' + playerNameHtml(player) + '</h4>'
            + '<dl class="dz-browse-meta">' + playerDetailRows(player) + '</dl>'
            + managementLink(player);
    }

    function selectPlayer(steam64) {
        const prev = state.selected;
        state.selected = steam64;

        // Refresh icon for previous selection.
        if (prev && state.markers.has(prev)) {
            const prevPlayer = state.players.get(prev);
            state.markers.get(prev).setIcon(playerIcon(prevPlayer && prevPlayer.alive, false));
            state.markers.get(prev).setZIndexOffset(0);
        }

        // Highlight new selection.
        if (state.markers.has(steam64)) {
            const selPlayer = state.players.get(steam64);
            state.markers.get(steam64).setIcon(playerIcon(selPlayer && selPlayer.alive, true));
            state.markers.get(steam64).setZIndexOffset(1000);
        }

        renderPlayerList();
        renderSelection();
    }

    function focusPlayer(steam64) {
        const marker = state.markers.get(steam64);
        if (marker && state.leafletMap) {
            state.leafletMap.panTo(marker.getLatLng(), { animate: true, duration: 0.4 });
        }
    }

    function openPlayerPopup(steam64) {
        const marker = state.markers.get(steam64);
        if (marker) {
            marker.openPopup();
        }
    }

    // ── Utilities ─────────────────────────────────────────────────────────
    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // Format an ISO / MySQL timestamp as DD-MM-YYYY HH:MM.
    function formatDateDMY(value) {
        if (!value) { return ''; }
        var d = new Date(String(value).replace(' ', 'T'));
        if (isNaN(d.getTime())) { return String(value); }
        var dd = String(d.getUTCDate()).padStart(2, '0');
        var mm = String(d.getUTCMonth() + 1).padStart(2, '0');
        var yyyy = d.getUTCFullYear();
        var hh = String(d.getUTCHours()).padStart(2, '0');
        var min = String(d.getUTCMinutes()).padStart(2, '0');
        return dd + '-' + mm + '-' + yyyy + ' ' + hh + ':' + min;
    }

    // Snapshot status (bridge state) and viewer notices (tiles/library) are
    // tracked separately so neither overwrites the other.
    function renderStatus() {
        statusEl.textContent = [state.statusText, state.notice].filter(Boolean).join(' · ');
    }

    function setStatusText(text) {
        state.statusText = text || '';
        renderStatus();
    }

    function setNotice(text) {
        state.notice = text || '';
        renderStatus();
    }

    function showFatal(message) {
        root.innerHTML = '<p class="dz-live-map-fallback-message">' + escapeHtml(message) + '</p>';
        setStatusText('');
        setNotice('');
    }

    function resetView() {
        if (!state.leafletMap) {
            return;
        }

        state.leafletMap.fitBounds(worldBounds(state.mapDef));
    }

    function toggleFullscreen() {
        if (!document.fullscreenElement) {
            root.requestFullscreen && root.requestFullscreen();
            return;
        }

        document.exitFullscreen && document.exitFullscreen();
    }

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? String(meta.getAttribute('content') || '') : '';
    }

    function setBridgeMessage(text, kind) {
        if (!bridgeMsgEl) {
            return;
        }

        bridgeMsgEl.classList.remove('dz-hidden');
        bridgeMsgEl.className = 'dz-status';

        if (kind === 'success') {
            bridgeMsgEl.classList.add('dz-text-green');
        } else if (kind === 'error') {
            bridgeMsgEl.classList.add('dz-text-red');
        } else {
            bridgeMsgEl.classList.add('dz-text-muted');
        }

        bridgeMsgEl.textContent = text;
    }

    function setBridgeButtonsBusy(busy) {
        if (bridgeRefreshBtn) {
            bridgeRefreshBtn.disabled = busy;
        }
        if (bridgeDeployBtn) {
            bridgeDeployBtn.disabled = busy;
        }
        if (bridgeRemoveBtn) {
            bridgeRemoveBtn.disabled = busy;
        }
    }

    async function refreshBridgeStatus() {
        try {
            const res = await fetch(BRIDGE_STATUS_URL, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const data = await res.json().catch(function () { return {}; });

            if (!res.ok) {
                throw new Error('HTTP ' + res.status);
            }

            const script = !!data.script;
            const initC  = !!data.init_c;
            const snapshot = !!data.snapshot;
            const legacy = !!data.init_c_legacy;

            if (data.deployed) {
                setBridgeMessage('Bridge deployed (script, init.c, snapshot all present).', 'success');
                return;
            }

            if (legacy) {
                setBridgeMessage('Legacy bridge block detected in init.c — remove and redeploy bridge.', 'error');
                return;
            }

            const parts = [];
            parts.push('script: ' + (script ? 'yes' : 'no'));
            parts.push('init.c: ' + (initC ? 'yes' : 'no'));
            parts.push('snapshot: ' + (snapshot ? 'yes' : 'no'));
            setBridgeMessage('Bridge not fully deployed (' + parts.join(', ') + ').', 'warn');
        } catch (err) {
            setBridgeMessage('Failed to load bridge status.', 'error');
        }
    }

    async function postBridge(url, actionLabel) {
        setBridgeButtonsBusy(true);
        setBridgeMessage(actionLabel + '…', 'warn');

        try {
            const res = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
            });
            const data = await res.json().catch(function () { return {}; });

            if (!res.ok) {
                throw new Error('HTTP ' + res.status);
            }

            setBridgeMessage(String(data.message || (actionLabel + ' complete.')), data.deployed === false || data.undeployed === false ? 'error' : 'success');
            await refreshBridgeStatus();
        } catch (err) {
            setBridgeMessage(actionLabel + ' failed.', 'error');
        } finally {
            setBridgeButtonsBusy(false);
        }
    }

    // ── Poll loop ─────────────────────────────────────────────────────────
    async function poll() {
        try {
            const res = await fetch(SNAPSHOT_URL, {
                method:      'GET',
                credentials: 'same-origin',
                headers:     { 'Accept': 'application/json' },
            });

            if (!res.ok) {
                throw new Error('HTTP ' + res.status);
            }

            applySnapshot(await res.json());
        } catch (err) {
            setStatusText('Failed to fetch live map snapshot.');
        } finally {
            setTimeout(poll, POLL_MS);
        }
    }

    // ── Leaflet loader ────────────────────────────────────────────────────
    // The panel serves module pages standalone, so Leaflet is loaded from a
    // CDN.  A single hard-coded <script> tag fails silently when the CDN is
    // blocked (offline panel, CSP, ad blocker) and leaves an empty canvas, so
    // every mirror is tried in turn and any failure is reported in the UI.
    const LEAFLET_SOURCES = [
        'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
        'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js',
        'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js',
    ];
    // Verified against the leaflet@1.9.4 npm tarball (dist/leaflet.js), which
    // is what all three mirrors serve.
    const LEAFLET_INTEGRITY = 'sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=';

    function loadLeaflet(index) {
        if (window.L && window.L.map) {
            boot();
            return;
        }

        if (index >= LEAFLET_SOURCES.length) {
            showFatal('Leaflet could not be loaded from any CDN, so the live map cannot be drawn. '
                + 'Allow unpkg.com, jsdelivr.net, or cdnjs.cloudflare.com for this panel.');
            return;
        }

        const script = document.createElement('script');
        script.src = LEAFLET_SOURCES[index];
        script.integrity = LEAFLET_INTEGRITY;
        script.crossOrigin = 'anonymous';
        script.async = false;
        script.onload = function () {
            if (window.L && window.L.map) {
                boot();
                return;
            }

            loadLeaflet(index + 1);
        };
        script.onerror = function () {
            loadLeaflet(index + 1);
        };

        document.head.appendChild(script);
    }

    // ── Boot ──────────────────────────────────────────────────────────────
    function boot() {
        try {
            initLeaflet();
        } catch (err) {
            showFatal('The live map failed to initialise: ' + (err && err.message ? err.message : 'unknown error') + '.');
            return;
        }

        poll();
        loadMarkers();
    }

    window.pteroLiveMapResetCamera = resetView;
    window.pteroLiveMapFullscreen  = toggleFullscreen;

    searchEl.addEventListener('input', function () {
        state.search = searchEl.value || '';
        renderPlayerList();
    });
    if (bridgeRefreshBtn) {
        bridgeRefreshBtn.addEventListener('click', refreshBridgeStatus);
    }
    if (bridgeDeployBtn) {
        bridgeDeployBtn.addEventListener('click', function () {
            postBridge(BRIDGE_DEPLOY_URL, 'Deploying bridge');
        });
    }
    if (bridgeRemoveBtn) {
        bridgeRemoveBtn.addEventListener('click', function () {
            postBridge(BRIDGE_REMOVE_URL, 'Removing bridge');
        });
    }

    setStatusText('Loading map…');
    refreshBridgeStatus();
    loadLeaflet(0);
}());
</script>

<style>
/* Leaflet dark-theme overrides */
.leaflet-container {
    background: #0b0f19;
    font-family: inherit;
}
.leaflet-bar a,
.leaflet-bar a:hover {
    background: var(--dz-surface-alt);
    color: var(--dz-text);
    border-color: var(--dz-border);
}
.leaflet-bar a:hover {
    color: var(--dz-accent);
}
.leaflet-popup-content-wrapper,
.leaflet-popup-tip {
    background: var(--dz-surface);
    color: var(--dz-text);
    border: 1px solid var(--dz-border);
    box-shadow: none;
}
.leaflet-tooltip {
    background: rgba(11,15,25,0.92);
    color: var(--dz-text);
    border: 1px solid var(--dz-border);
    border-radius: 4px;
    font-size: 0.78rem;
    padding: 2px 6px;
    white-space: nowrap;
}
.leaflet-tooltip-top:before {
    border-top-color: var(--dz-border);
}
.leaflet-control-attribution {
    background: rgba(11,15,25,0.7);
    color: var(--dz-muted);
    font-size: 0.65rem;
}
.leaflet-control-attribution a { color: var(--dz-accent); }
/* Category markers (locations, animals, loot, vehicles, spawns, …) */
.dz-map-marker {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    border: 1px solid;
    border-radius: 50%;
    background: rgba(11,15,25,0.75);
    font-size: 11px;
    line-height: 1;
}
.dz-map-popup dl { margin: 0.4rem 0 0.5rem; }
.dz-map-popup-link {
    color: var(--dz-accent);
    font-size: 0.75rem;
}
.dz-map-popup {
    min-width: 200px;
    max-width: min(280px, 80vw);
    font-size: 0.8rem;
}
.dz-map-popup .dz-browse-meta dd {
    word-break: break-all;
    overflow-wrap: anywhere;
}
.leaflet-popup-content {
    margin: 0.6rem 0.8rem;
}
.leaflet-popup-content-wrapper {
    min-width: 0;
}
/* Named location labels */
.dz-map-loc {
    font-size: 0.65rem;
    color: rgba(230,235,245,0.85);
    white-space: nowrap;
    text-shadow: 1px 1px 2px #0b0f19, -1px -1px 2px #0b0f19;
    pointer-events: none;
}
</style>
