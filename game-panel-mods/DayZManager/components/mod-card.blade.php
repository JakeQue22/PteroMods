<article class="flex flex-col rounded-xl border border-slate-700 bg-slate-800 p-5 text-white shadow-md">
    @if (!empty($mod['thumbnail']))
        <img src="{{ $mod['thumbnail'] }}" alt="{{ $mod['title'] }} thumbnail"
             class="h-40 w-full rounded-lg object-cover" />
    @endif
    <div class="mt-4 flex items-start justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold">{{ $mod['title'] }}</h2>
            <p class="text-sm text-slate-400">{{ $mod['workshop_id'] }}</p>
        </div>
        <span class="shrink-0 rounded-full px-3 py-1 text-xs font-medium
            {{ ($mod['enabled'] ?? false) ? 'bg-emerald-500/20 text-emerald-200' : 'bg-amber-500/20 text-amber-100' }}">
            {{ ($mod['enabled'] ?? false) ? 'Enabled' : 'Disabled' }}
        </span>
    </div>

    <dl class="mt-4 space-y-2 text-sm text-slate-200">
        <div class="flex justify-between"><dt class="text-slate-400">Folder</dt><dd>{{ $mod['folder_name'] ?? '—' }}</dd></div>
        <div class="flex justify-between"><dt class="text-slate-400">Author</dt><dd>{{ $mod['author'] ?? '—' }}</dd></div>
        <div class="flex justify-between"><dt class="text-slate-400">File Size</dt><dd>{{ $mod['file_size'] ?? '—' }}</dd></div>
        <div class="flex justify-between">
            <dt class="text-slate-400">Version</dt>
            <dd>{{ $mod['current_version'] ?? '—' }}
                @if (!empty($mod['latest_version']) && $mod['latest_version'] !== ($mod['current_version'] ?? ''))
                    <span class="ml-1 text-amber-400">→ {{ $mod['latest_version'] }}</span>
                @endif
            </dd>
        </div>
        <div class="flex justify-between"><dt class="text-slate-400">Dependencies</dt><dd>{{ implode(', ', $mod['dependencies'] ?? []) ?: 'None' }}</dd></div>
    </dl>

    <div class="mt-auto pt-5 flex flex-wrap gap-2">
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
</article>
