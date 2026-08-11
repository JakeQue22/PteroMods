<section class="dz-card">
    <h2>Live Map</h2>
    <p class="dz-sub">
        Live player positions come from a server-side bridge snapshot in <code>/profiles/PteroMods/live_map_players.json</code>.
    </p>
    <div class="dz-form">
        <input id="dz-live-map-search" class="dz-input" type="search" placeholder="Search players by name or Steam64" />
        <button class="dz-btn dz-btn-ghost" type="button" onclick="window.pteroLiveMapResetCamera()">Reset Camera</button>
        <button class="dz-btn dz-btn-ghost" type="button" onclick="window.pteroLiveMapFullscreen()">Fullscreen</button>
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
        <h3>Players</h3>
        <div id="dz-live-map-status" class="dz-sub"></div>
        <ul id="dz-live-map-list" class="dz-live-map-list"></ul>
        <div id="dz-live-map-player" class="dz-live-map-player">
            <p class="dz-sub">Select a player marker to view details.</p>
        </div>
    </aside>
</section>

<script>
(function () {
    const SERVER_ID = @json($server_id);
    const SNAPSHOT_URL = '/api/server/' + encodeURIComponent(SERVER_ID) + '/dayz/live-map/snapshot';
    const POLL_MS = 7000;
    const state = {
        map: @json($map_definition ?? ['name' => 'ChernarusPlus', 'world_size' => 15360]),
        players: new Map(),
        list: [],
        selected: '',
        search: '',
        lastUpdate: '',
        renderer: null,
        scene: null,
        camera: null,
        controls: null,
        ground: null,
        raycaster: null,
        mouse: null,
        animation: 0,
        fallback2d: null,
    };

    const root = document.getElementById('dz-live-map-canvas');
    const statusEl = document.getElementById('dz-live-map-status');
    const listEl = document.getElementById('dz-live-map-list');
    const detailEl = document.getElementById('dz-live-map-player');
    const countEl = document.getElementById('dz-live-map-count');
    const updatedEl = document.getElementById('dz-live-map-updated');
    const mapNameEl = document.getElementById('dz-live-map-name');
    const searchEl = document.getElementById('dz-live-map-search');

    function loadScript(url) {
        return new Promise(function (resolve, reject) {
            const existing = document.querySelector('script[data-live-map="' + url + '"]');
            if (existing) {
                existing.addEventListener('load', function () { resolve(); }, { once: true });
                existing.addEventListener('error', reject, { once: true });
                if (existing.getAttribute('data-loaded') === '1') {
                    resolve();
                }
                return;
            }
            const script = document.createElement('script');
            script.src = url;
            script.async = true;
            script.setAttribute('data-live-map', url);
            script.addEventListener('load', function () { script.setAttribute('data-loaded', '1'); resolve(); }, { once: true });
            script.addEventListener('error', reject, { once: true });
            document.head.appendChild(script);
        });
    }

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    function apiGet() {
        return fetch(SNAPSHOT_URL, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
        }).then(function (res) { return res.json(); });
    }

    function worldToScene(x, z) {
        const size = Number(state.map.world_size || 15360);
        return {
            x: (x - (size / 2)),
            z: (z - (size / 2)),
        };
    }

    function worldToCanvas(x, z, width, height) {
        const size = Number(state.map.world_size || 15360);
        return {
            x: Math.max(0, Math.min(width, (x / size) * width)),
            y: Math.max(0, Math.min(height, (z / size) * height)),
        };
    }

    function sceneToScreen(vector) {
        const width = root.clientWidth || 1;
        const height = root.clientHeight || 1;
        const projected = vector.clone().project(state.camera);
        return {
            x: (projected.x * 0.5 + 0.5) * width,
            y: (projected.y * -0.5 + 0.5) * height,
            visible: projected.z < 1,
        };
    }

    function markerLabel(name) {
        const canvas = document.createElement('canvas');
        canvas.width = 256;
        canvas.height = 64;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = 'rgba(12,18,30,0.9)';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.strokeStyle = 'rgba(14,165,233,0.9)';
        ctx.strokeRect(0.5, 0.5, canvas.width - 1, canvas.height - 1);
        ctx.fillStyle = '#e6ebf5';
        ctx.font = '24px sans-serif';
        ctx.textBaseline = 'middle';
        ctx.fillText(name.slice(0, 24), 12, 32);
        const texture = new window.THREE.CanvasTexture(canvas);
        const sprite = new window.THREE.Sprite(new window.THREE.SpriteMaterial({ map: texture, transparent: true }));
        sprite.scale.set(220, 55, 1);
        return sprite;
    }

    function createMarker(player) {
        const geometry = new window.THREE.ConeGeometry(45, 140, 8);
        const material = new window.THREE.MeshBasicMaterial({ color: player.alive ? 0x34d399 : 0xf87171 });
        const cone = new window.THREE.Mesh(geometry, material);
        cone.userData.steam64 = player.steam64;
        cone.rotation.x = Math.PI;
        const label = markerLabel(player.name);
        const group = new window.THREE.Group();
        group.add(cone);
        group.add(label);
        label.position.set(0, 130, 0);
        state.scene.add(group);
        return { group: group, cone: cone, label: label, target: { x: 0, z: 0, dir: 0 }, data: player };
    }

    function applySnapshot(payload) {
        state.map = payload.map_definition || state.map;
        mapNameEl.textContent = state.map.name || payload.map || 'Unknown';
        updatedEl.textContent = new Date(payload.last_update || Date.now()).toLocaleString();
        state.lastUpdate = payload.last_update || '';
        statusEl.textContent = payload.status === 'waiting_for_bridge'
            ? 'Waiting for bridge data at ' + (payload.bridge_format && payload.bridge_format.path ? payload.bridge_format.path : '/profiles/PteroMods/live_map_players.json')
            : (payload.status === 'invalid_bridge_payload'
                ? 'Bridge snapshot exists but is invalid (or signature check failed).'
                : 'Live snapshot received.');

        const seen = new Set();
        const players = Array.isArray(payload.players) ? payload.players : [];
        players.forEach(function (player) {
            if (!player || !player.steam64) {
                return;
            }
            seen.add(player.steam64);
            const mapPos = worldToScene(Number(player.x || 0), Number(player.z || 0));
            if (!state.players.has(player.steam64)) {
                state.players.set(player.steam64, createMarker(player));
            }
            const marker = state.players.get(player.steam64);
            marker.data = player;
            marker.target.x = mapPos.x;
            marker.target.z = mapPos.z;
            marker.target.dir = Number(player.direction || 0);
            marker.cone.material.color.set(player.alive ? 0x34d399 : 0xf87171);
        });

        Array.from(state.players.keys()).forEach(function (steam64) {
            if (seen.has(steam64)) {
                return;
            }
            const marker = state.players.get(steam64);
            state.scene.remove(marker.group);
            state.players.delete(steam64);
            if (state.selected === steam64) {
                state.selected = '';
            }
        });

        state.list = players.slice();
        countEl.textContent = String(players.length);
        renderPlayerList();
        renderSelection();

        if (state.fallback2d) {
            draw2dFallback(players);
        }
    }

    function renderPlayerList() {
        const needle = (state.search || '').toLowerCase();
        const filtered = state.list.filter(function (player) {
            const hay = (String(player.name || '') + ' ' + String(player.steam64 || '')).toLowerCase();
            return needle === '' || hay.indexOf(needle) !== -1;
        });

        listEl.innerHTML = '';
        filtered.forEach(function (player) {
            const li = document.createElement('li');
            li.className = 'dz-live-map-list-item' + (state.selected === player.steam64 ? ' is-active' : '');
            li.innerHTML = '<button type="button"><span>' + escapeHtml(player.name) + '</span><small>' + escapeHtml(player.steam64) + '</small></button>';
            li.querySelector('button').addEventListener('click', function () {
                selectPlayer(player.steam64);
                focusPlayer(player.steam64);
            });
            listEl.appendChild(li);
        });
    }

    function renderSelection() {
        const selected = state.list.find(function (player) { return player.steam64 === state.selected; });
        if (!selected) {
            detailEl.innerHTML = '<p class="dz-sub">Select a player marker to view details.</p>';
            return;
        }
        detailEl.innerHTML =
            '<h4>' + escapeHtml(selected.name) + '</h4>'
            + '<dl class="dz-browse-meta">'
            + '<div><dt>Steam64</dt><dd>' + escapeHtml(selected.steam64) + '</dd></div>'
            + '<div><dt>Position</dt><dd>' + Number(selected.x || 0).toFixed(1) + ', ' + Number(selected.z || 0).toFixed(1) + '</dd></div>'
            + '<div><dt>Height</dt><dd>' + Number(selected.y || 0).toFixed(1) + '</dd></div>'
            + '<div><dt>Direction</dt><dd>' + Number(selected.direction || 0).toFixed(1) + '°</dd></div>'
            + '<div><dt>Status</dt><dd>' + (selected.alive ? 'Alive' : 'Dead') + '</dd></div>'
            + '<div><dt>Health</dt><dd>' + (selected.health == null ? 'N/A' : Number(selected.health).toFixed(1)) + '</dd></div>'
            + '</dl>';
    }

    function selectPlayer(steam64) {
        state.selected = steam64;
        renderPlayerList();
        renderSelection();
    }

    function focusPlayer(steam64) {
        const marker = state.players.get(steam64);
        if (!marker || !state.controls) {
            return;
        }
        state.controls.target.set(marker.group.position.x, 0, marker.group.position.z);
        state.controls.update();
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function resizeRenderer() {
        if (!state.renderer || !state.camera) {
            return;
        }
        const width = root.clientWidth || 1;
        const height = root.clientHeight || 1;
        state.renderer.setSize(width, height);
        state.camera.aspect = width / height;
        state.camera.updateProjectionMatrix();
    }

    function resetCamera() {
        if (!state.camera || !state.controls) {
            return;
        }
        const size = Number(state.map.world_size || 15360);
        state.camera.position.set(size * 0.25, size * 0.35, size * 0.25);
        state.controls.target.set(0, 0, 0);
        state.controls.update();
    }

    function toggleFullscreen() {
        if (!document.fullscreenElement) {
            root.requestFullscreen && root.requestFullscreen();
            return;
        }
        document.exitFullscreen && document.exitFullscreen();
    }

    function animate() {
        if (state.fallback2d) {
            return;
        }

        state.animation = requestAnimationFrame(animate);

        state.players.forEach(function (marker) {
            marker.group.position.x += (marker.target.x - marker.group.position.x) * 0.18;
            marker.group.position.z += (marker.target.z - marker.group.position.z) * 0.18;
            marker.group.rotation.y = (marker.target.dir * Math.PI / 180);
        });

        state.controls && state.controls.update();
        state.renderer && state.renderer.render(state.scene, state.camera);
    }

    function draw2dFallback(players) {
        if (!state.fallback2d) {
            return;
        }
        const canvas = state.fallback2d;
        const ctx = canvas.getContext('2d');
        const width = canvas.width;
        const height = canvas.height;

        ctx.fillStyle = '#0b0f19';
        ctx.fillRect(0, 0, width, height);
        ctx.strokeStyle = '#1f3a56';
        for (let i = 0; i <= 10; i++) {
            const x = (i / 10) * width;
            const y = (i / 10) * height;
            ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, height); ctx.stroke();
            ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(width, y); ctx.stroke();
        }

        players.forEach(function (player) {
            const pos = worldToCanvas(Number(player.x || 0), Number(player.z || 0), width, height);
            ctx.fillStyle = player.alive ? '#34d399' : '#f87171';
            ctx.beginPath();
            ctx.arc(pos.x, pos.y, 5, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = '#e6ebf5';
            ctx.font = '12px sans-serif';
            ctx.fillText(String(player.name || ''), pos.x + 8, pos.y - 8);
        });
    }

    function buildGround() {
        const size = Number(state.map.world_size || 15360);
        if (state.ground) {
            state.scene.remove(state.ground);
        }
        const geometry = new window.THREE.PlaneGeometry(size, size, 50, 50);
        const material = new window.THREE.MeshBasicMaterial({ color: 0x122033, wireframe: true });
        const mesh = new window.THREE.Mesh(geometry, material);
        mesh.rotation.x = -Math.PI / 2;
        state.scene.add(mesh);
        state.ground = mesh;
    }

    function onCanvasClick(event) {
        if (!state.raycaster || !state.camera) {
            return;
        }
        const rect = root.getBoundingClientRect();
        state.mouse.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
        state.mouse.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;
        state.raycaster.setFromCamera(state.mouse, state.camera);
        const meshes = [];
        state.players.forEach(function (marker) { meshes.push(marker.cone); });
        const hits = state.raycaster.intersectObjects(meshes, false);
        if (hits.length > 0 && hits[0].object && hits[0].object.userData) {
            const steam64 = hits[0].object.userData.steam64;
            selectPlayer(steam64);
            focusPlayer(steam64);
        }
    }

    async function poll() {
        try {
            const payload = await apiGet();
            applySnapshot(payload);
            buildGround();
        } catch (err) {
            statusEl.textContent = 'Failed to fetch live map snapshot.';
        } finally {
            setTimeout(poll, POLL_MS);
        }
    }

    async function boot() {
        try {
            await loadScript('https://unpkg.com/three@0.162.0/build/three.min.js');
            await loadScript('https://unpkg.com/three@0.162.0/examples/js/controls/OrbitControls.js');
        } catch (err) {
            statusEl.textContent = '3D map assets could not load. Using 2D fallback.';
            const canvas = document.createElement('canvas');
            canvas.width = 1200;
            canvas.height = 800;
            canvas.className = 'dz-live-map-fallback';
            root.appendChild(canvas);
            state.fallback2d = canvas;
            poll();
            return;
        }

        state.scene = new window.THREE.Scene();
        state.scene.background = new window.THREE.Color(0x0b0f19);
        state.camera = new window.THREE.PerspectiveCamera(55, 1, 1, 100000);
        state.renderer = new window.THREE.WebGLRenderer({ antialias: true });
        root.appendChild(state.renderer.domElement);
        state.controls = new window.THREE.OrbitControls(state.camera, state.renderer.domElement);
        state.controls.enablePan = true;
        state.controls.enableDamping = true;
        state.controls.maxPolarAngle = Math.PI / 2.05;
        state.raycaster = new window.THREE.Raycaster();
        state.mouse = new window.THREE.Vector2();

        const light = new window.THREE.AmbientLight(0xffffff, 1);
        state.scene.add(light);
        state.scene.add(new window.THREE.GridHelper(18000, 24, 0x1f3a56, 0x16283d));

        resetCamera();
        resizeRenderer();
        window.addEventListener('resize', resizeRenderer);
        root.addEventListener('click', onCanvasClick);
        searchEl.addEventListener('input', function () {
            state.search = searchEl.value || '';
            renderPlayerList();
        });

        window.pteroLiveMapResetCamera = resetCamera;
        window.pteroLiveMapFullscreen = toggleFullscreen;

        animate();
        poll();
    }

    boot();
}());
</script>
