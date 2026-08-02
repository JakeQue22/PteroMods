<div class="space-y-6">
    <section class="rounded-xl border border-slate-700 bg-slate-800 p-6 text-white shadow-md">
        <h1 class="text-2xl font-semibold">Player Lists</h1>
        <p class="mt-2 text-sm text-slate-300">Manage the DayZ ban list, whitelist, and priority queue by Steam64 ID or GUID.</p>
    </section>

    @foreach (['ban' => 'Ban List', 'whitelist' => 'Whitelist', 'priority' => 'Priority Queue'] as $type => $label)
        <section class="rounded-xl border border-slate-700 bg-slate-800 p-6 text-white shadow-md">
            <h2 class="text-lg font-semibold">{{ $label }}</h2>
            <ul class="mt-4 space-y-2 text-sm text-slate-200">
                @forelse ($players[$type] ?? [] as $entry)
                    <li class="flex items-center justify-between rounded-lg bg-slate-900 px-4 py-2">
                        <span>{{ $entry['player_id'] }}</span>
                        @if ($entry['note'])
                            <span class="text-xs text-slate-400">{{ $entry['note'] }}</span>
                        @endif
                    </li>
                @empty
                    <li class="text-slate-400">No entries in this list.</li>
                @endforelse
            </ul>
        </section>
    @endforeach
</div>
