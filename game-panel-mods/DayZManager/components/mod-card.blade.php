<article class="rounded-xl border border-slate-700 bg-slate-800 p-5 text-white shadow-md">
    <img src="{{ $mod['thumbnail'] }}" alt="{{ $mod['title'] }} thumbnail" class="h-40 w-full rounded-lg object-cover" />
    <div class="mt-4 flex items-start justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold">{{ $mod['title'] }}</h2>
            <p class="text-sm text-slate-400">{{ $mod['workshop_id'] }}</p>
        </div>
        <span class="rounded-full px-3 py-1 text-xs font-medium {{ $mod['enabled'] ? 'bg-emerald-500/20 text-emerald-200' : 'bg-amber-500/20 text-amber-100' }}">
            {{ $mod['enabled'] ? 'Enabled' : 'Disabled' }}
        </span>
    </div>

    <dl class="mt-4 space-y-2 text-sm text-slate-200">
        <div class="flex justify-between"><dt>Folder</dt><dd>{{ $mod['folder_name'] }}</dd></div>
        <div class="flex justify-between"><dt>Author</dt><dd>{{ $mod['author'] }}</dd></div>
        <div class="flex justify-between"><dt>File Size</dt><dd>{{ $mod['file_size'] }}</dd></div>
        <div class="flex justify-between"><dt>Current Version</dt><dd>{{ $mod['current_version'] }}</dd></div>
        <div class="flex justify-between"><dt>Latest Version</dt><dd>{{ $mod['latest_version'] }}</dd></div>
        <div class="flex justify-between"><dt>Dependencies</dt><dd>{{ implode(', ', $mod['dependencies']) ?: 'None' }}</dd></div>
    </dl>
</article>
