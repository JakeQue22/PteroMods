<div class="space-y-6">
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

    <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($installed_mods as $mod)
            @include('game-panel-mods.DayZManager.components.mod-card', ['mod' => $mod])
        @endforeach
    </section>
</div>
