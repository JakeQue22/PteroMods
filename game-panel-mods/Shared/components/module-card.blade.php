<article class="rounded-xl border border-slate-700 bg-slate-800 p-5 shadow-md">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold text-white">{{ $module['name'] }}</h2>
            <p class="mt-1 text-sm text-slate-300">{{ $module['description'] }}</p>
        </div>
        <span class="rounded-full px-3 py-1 text-xs font-medium {{ $module['enabled'] ? 'bg-emerald-500/20 text-emerald-200' : 'bg-slate-700 text-slate-200' }}">
            {{ $module['enabled'] ? 'Enabled' : ($module['installed'] ? 'Disabled' : 'Available') }}
        </span>
    </div>

    <dl class="mt-4 space-y-2 text-sm text-slate-200">
        <div class="flex justify-between"><dt>Version</dt><dd>{{ $module['version'] }}</dd></div>
        <div class="flex justify-between"><dt>Author</dt><dd>{{ $module['author'] }}</dd></div>
        <div class="flex justify-between"><dt>Supports</dt><dd>{{ implode(', ', $module['supports']) }}</dd></div>
    </dl>
</article>
