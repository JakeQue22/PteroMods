<div class="space-y-6">
    <section class="rounded-xl border border-slate-700 bg-slate-800 p-6 text-white shadow-md">
        <h1 class="text-2xl font-semibold">Server Control</h1>
        <p class="mt-2 text-sm text-slate-300">Restart the server and review the active launch parameters.</p>
    </section>

    <section class="rounded-xl border border-slate-700 bg-slate-800 p-6 text-white shadow-md">
        <h2 class="text-lg font-semibold">Current Launch Parameters</h2>
        @if ($launch_parameters)
            <pre class="mt-4 overflow-x-auto rounded-lg bg-slate-900 p-4 text-sm text-emerald-300">{{ $launch_parameters }}</pre>
            <p class="mt-2 text-xs text-slate-400">{{ $mod_count }} enabled mod(s) included.</p>
        @else
            <p class="mt-4 text-sm text-slate-400">No mods enabled — launch parameters are empty.</p>
        @endif
    </section>

    <section class="rounded-xl border border-slate-700 bg-slate-800 p-6 text-white shadow-md">
        <h2 class="text-lg font-semibold">Restart Server</h2>
        <p class="mt-2 text-sm text-slate-300">
            Queues a graceful restart. Active players will be notified before the server goes offline.
        </p>
        <form method="POST" action="{{ url('/api/servers/' . $server . '/dayz/server/restart') }}" class="mt-4">
            @csrf
            <label class="block text-sm text-slate-300" for="restart-reason">Reason (optional)</label>
            <input id="restart-reason" name="reason" type="text"
                   placeholder="e.g. Mod update applied"
                   class="mt-2 w-full rounded-lg border border-slate-600 bg-slate-900 px-4 py-2 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-sky-500" />
            <button type="submit"
                    class="mt-4 rounded-lg bg-red-600 px-6 py-2 text-sm font-semibold text-white hover:bg-red-500 focus:outline-none focus:ring-2 focus:ring-red-400">
                Restart Now
            </button>
        </form>
    </section>
</div>
