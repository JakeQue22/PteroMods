<div class="space-y-8">

    {{-- ── Tab navigation ─────────────────────────────────────────────────── --}}
    @php
        $base = '/server/' . $server_id . '/dayz';
    @endphp
    <nav class="flex flex-wrap gap-2" aria-label="DayZ Manager navigation">
        <a href="{{ $base }}"
           class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-semibold text-white shadow">
            Dashboard
        </a>
        <a href="{{ $base }}/mods"
           class="rounded-lg bg-slate-700 px-4 py-2 text-sm font-semibold text-slate-200 hover:bg-slate-600 transition-colors">
            Workshop Mods
        </a>
        <a href="{{ $base }}/players"
           class="rounded-lg bg-slate-700 px-4 py-2 text-sm font-semibold text-slate-200 hover:bg-slate-600 transition-colors">
            Player Lists
        </a>
        <a href="{{ $base }}/server"
           class="rounded-lg bg-slate-700 px-4 py-2 text-sm font-semibold text-slate-200 hover:bg-slate-600 transition-colors">
            Server Control
        </a>
        <a href="{{ $base }}/configuration"
           class="rounded-lg bg-slate-700 px-4 py-2 text-sm font-semibold text-slate-200 hover:bg-slate-600 transition-colors">
            Configuration
        </a>
    </nav>

    {{-- ── Server overview stats ───────────────────────────────────────────── --}}
    <section class="rounded-xl border border-slate-700 bg-slate-800 p-6 text-white shadow-md">
        <h2 class="text-xl font-semibold">Server Overview</h2>
        <p class="mt-1 text-xs text-slate-400">
            Map, version, and player count require a live game-server query and show N/A until that is wired up.
        </p>
        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
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
                    $isStatus = $label === 'Server Status';
                    $statusColor = match(strtolower((string) $value)) {
                        'running'           => 'text-emerald-400',
                        'suspended'         => 'text-red-400',
                        'installing'        => 'text-amber-400',
                        'restoring backup'  => 'text-amber-400',
                        'transferring'      => 'text-sky-400',
                        'install failed'    => 'text-red-500',
                        default             => 'text-slate-100',
                    };
                @endphp
                <article class="rounded-lg border border-slate-700 bg-slate-900 p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-400">{{ $label }}</p>
                    <p class="mt-1 text-lg font-semibold {{ $isStatus ? $statusColor : 'text-slate-100' }}">
                        {{ $value }}
                    </p>
                </article>
            @endforeach
        </div>
    </section>

    {{-- ── Workshop mod manager ────────────────────────────────────────────── --}}
    <section class="rounded-xl border border-slate-700 bg-slate-800 p-6 text-white shadow-md">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-semibold">Workshop Mods</h2>
                <p class="mt-1 text-sm text-slate-300">
                    {{ $installed_mods_count }} mod(s) installed.
                    Use the form below to add new mods, or manage them from the
                    <a href="{{ $base }}/mods" class="text-sky-400 hover:underline">Workshop Mods</a> page.
                </p>
            </div>
        </div>

        {{-- Install form --}}
        <div class="mt-6 rounded-lg border border-slate-700 bg-slate-900 p-4">
            <h3 class="text-sm font-semibold text-slate-200">Install Workshop Mod</h3>
            <p class="mt-1 text-xs text-slate-400">
                Enter a raw Workshop ID (e.g. <code class="text-emerald-300">1559212036</code>)
                or a full Steam Workshop URL.
            </p>
            <div class="mt-3 flex flex-wrap gap-3">
                <input id="ptero-workshop-ref"
                       type="text"
                       placeholder="Workshop ID or Steam Workshop URL"
                       class="flex-1 min-w-0 rounded-lg border border-slate-600 bg-slate-800 px-4 py-2 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-sky-500" />
                <button onclick="pteroInstallMod()"
                        class="rounded-lg bg-sky-600 px-5 py-2 text-sm font-semibold text-white hover:bg-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-400 transition-colors">
                    Install
                </button>
            </div>
            <p id="ptero-install-status" class="mt-2 text-xs hidden"></p>
        </div>

        {{-- Installed mod cards --}}
        @if (count($installed_mods) > 0)
            <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($installed_mods as $mod)
                    <article class="flex flex-col rounded-xl border border-slate-700 bg-slate-900 text-white shadow">
                        @if (!empty($mod['thumbnail']))
                            <img src="{{ $mod['thumbnail'] }}"
                                 alt="{{ $mod['title'] }} thumbnail"
                                 class="h-36 w-full rounded-t-xl object-cover" />
                        @endif
                        <div class="flex flex-1 flex-col p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3 class="font-semibold leading-tight">{{ $mod['title'] }}</h3>
                                    <p class="text-xs text-slate-400">ID: {{ $mod['workshop_id'] }}</p>
                                </div>
                                <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium
                                    {{ ($mod['enabled'] ?? false) ? 'bg-emerald-500/20 text-emerald-300' : 'bg-amber-500/20 text-amber-200' }}">
                                    {{ ($mod['enabled'] ?? false) ? 'Enabled' : 'Disabled' }}
                                </span>
                            </div>

                            <dl class="mt-3 space-y-1 text-xs text-slate-300">
                                <div class="flex justify-between">
                                    <dt class="text-slate-400">Folder</dt>
                                    <dd>{{ $mod['folder_name'] ?? '—' }}</dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-slate-400">Author</dt>
                                    <dd>{{ $mod['author'] ?? '—' }}</dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-slate-400">Version</dt>
                                    <dd>{{ $mod['current_version'] ?? '—' }}
                                        @if (!empty($mod['latest_version']) && $mod['latest_version'] !== ($mod['current_version'] ?? ''))
                                            <span class="ml-1 text-amber-400">→ {{ $mod['latest_version'] }}</span>
                                        @endif
                                    </dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-slate-400">Size</dt>
                                    <dd>{{ $mod['file_size'] ?? '—' }}</dd>
                                </div>
                                @if (!empty($mod['dependencies']))
                                    <div class="flex justify-between">
                                        <dt class="text-slate-400">Deps</dt>
                                        <dd>{{ implode(', ', $mod['dependencies']) }}</dd>
                                    </div>
                                @endif
                            </dl>

                            {{-- Action buttons --}}
                            <div class="mt-auto pt-4 flex flex-wrap gap-2">
                                @if ($mod['enabled'] ?? false)
                                    <button onclick="pteroModAction('{{ $mod['workshop_id'] }}', 'disable')"
                                            class="rounded-lg bg-amber-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-600 transition-colors">
                                        Disable
                                    </button>
                                @else
                                    <button onclick="pteroModAction('{{ $mod['workshop_id'] }}', 'enable')"
                                            class="rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-600 transition-colors">
                                        Enable
                                    </button>
                                @endif
                                @if (!empty($mod['latest_version']) && $mod['latest_version'] !== ($mod['current_version'] ?? ''))
                                    <button onclick="pteroModAction('{{ $mod['workshop_id'] }}', 'update')"
                                            class="rounded-lg bg-sky-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-sky-600 transition-colors">
                                        Update
                                    </button>
                                @endif
                                <button onclick="pteroModAction('{{ $mod['workshop_id'] }}', 'remove')"
                                        class="rounded-lg bg-red-800 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-700 transition-colors">
                                    Remove
                                </button>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @else
            <p class="mt-6 text-sm text-slate-400">No mods installed yet. Use the form above to install your first Workshop mod.</p>
        @endif
    </section>

</div>

<script>
(function () {
    const SERVER_ID = '{{ addslashes($server_id) }}';

    function csrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    }

    async function apiPost(endpoint, body) {
        return fetch('/api/server/' + SERVER_ID + '/dayz/mods/' + endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'Accept': 'application/json',
            },
            body: JSON.stringify(body),
        });
    }

    window.pteroInstallMod = async function () {
        const input = document.getElementById('ptero-workshop-ref');
        const status = document.getElementById('ptero-install-status');
        const ref = (input?.value ?? '').trim();
        if (!ref) {
            return;
        }
        status.textContent = 'Queuing install…';
        status.className = 'mt-2 text-xs text-slate-300';
        status.classList.remove('hidden');
        try {
            const res = await apiPost('install', { reference: ref });
            const data = await res.json();
            if (res.ok) {
                status.textContent = '✓ Install queued for Workshop ID ' + (data.workshop_id ?? ref);
                status.className = 'mt-2 text-xs text-emerald-400';
                if (input) input.value = '';
            } else {
                status.textContent = '✗ ' + (data.message ?? 'Request failed');
                status.className = 'mt-2 text-xs text-red-400';
            }
        } catch (err) {
            status.textContent = '✗ Network error: ' + err.message;
            status.className = 'mt-2 text-xs text-red-400';
        }
    };

    window.pteroModAction = async function (workshopId, action) {
        if (action === 'remove' && !confirm('Remove mod ' + workshopId + '?')) {
            return;
        }
        try {
            const res = await apiPost(action, { workshop_id: workshopId });
            if (res.ok) {
                location.reload();
            } else {
                const data = await res.json().catch(() => ({}));
                alert('Action failed: ' + (data.message ?? res.status));
            }
        } catch (err) {
            alert('Network error: ' + err.message);
        }
    };
}());
</script>

