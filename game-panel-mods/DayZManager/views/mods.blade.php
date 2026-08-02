<div class="space-y-8">

    {{-- ── Tab navigation ─────────────────────────────────────────────────── --}}
    @php $base = '/server/' . $server_id . '/dayz'; @endphp
    <nav class="flex flex-wrap gap-2" aria-label="DayZ Manager navigation">
        <a href="{{ $base }}"
           class="rounded-lg bg-slate-700 px-4 py-2 text-sm font-semibold text-slate-200 hover:bg-slate-600 transition-colors">
            Dashboard
        </a>
        <a href="{{ $base }}/mods"
           class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-semibold text-white shadow">
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

    {{-- ── Workshop settings ───────────────────────────────────────────────── --}}
    <section class="rounded-xl border border-slate-700 bg-slate-800 p-6 text-white shadow-md">
        <h1 class="text-2xl font-semibold">Workshop Mod Manager</h1>
        <p class="mt-2 text-sm text-slate-300">Search, install, update, remove, and reorder DayZ mods with automatic dependency planning.</p>
        <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($settings as $key => $value)
                <div class="rounded-lg bg-slate-900 p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-400">{{ str_replace('_', ' ', $key) }}</p>
                    <p class="mt-1 text-sm text-slate-100">{{ is_bool($value) ? ($value ? 'Enabled' : 'Disabled') : $value }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ── Install new mod ─────────────────────────────────────────────────── --}}
    <section class="rounded-xl border border-slate-700 bg-slate-800 p-6 text-white shadow-md">
        <h2 class="text-lg font-semibold">Install Workshop Mod</h2>
        <p class="mt-1 text-sm text-slate-300">
            Enter a raw Workshop ID (e.g. <code class="text-emerald-300">1559212036</code>)
            or a full Steam Workshop URL.
        </p>
        <div class="mt-3 flex flex-wrap gap-3">
            <input id="ptero-workshop-ref"
                   type="text"
                   placeholder="Workshop ID or Steam Workshop URL"
                   class="flex-1 min-w-0 rounded-lg border border-slate-600 bg-slate-900 px-4 py-2 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-sky-500" />
            <button onclick="pteroInstallMod()"
                    class="rounded-lg bg-sky-600 px-5 py-2 text-sm font-semibold text-white hover:bg-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-400 transition-colors">
                Install
            </button>
        </div>
        <p id="ptero-install-status" class="mt-2 text-xs hidden"></p>
    </section>

    {{-- ── Installed mods ──────────────────────────────────────────────────── --}}
    <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($installed_mods as $mod)
            @include(realpath(__DIR__ . '/../components/mod-card.blade.php'), ['mod' => $mod])
        @endforeach
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
        if (!ref) return;
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
        if (action === 'remove' && !confirm('Remove mod ' + workshopId + '?')) return;
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

