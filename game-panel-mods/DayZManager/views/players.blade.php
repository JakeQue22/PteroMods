<section class="dz-card">
    <h2>Player Lists</h2>
    <p class="dz-sub">Manage the DayZ ban list, whitelist, and priority queue by Steam64 ID or GUID.</p>
</section>

@foreach (['ban' => 'Ban List', 'whitelist' => 'Whitelist', 'priority' => 'Priority Queue'] as $type => $label)
    <section class="dz-card">
        <h2>{{ $label }}</h2>
        <p class="dz-sub">{{ count($players[$type] ?? []) }} entr(y/ies).</p>
        <ul class="dz-list">
            @forelse ($players[$type] ?? [] as $entry)
                <li>
                    <span>{{ $entry['player_id'] }}</span>
                    @if (!empty($entry['note']))
                        <span class="dz-text-muted">{{ $entry['note'] }}</span>
                    @endif
                </li>
            @empty
                <li class="dz-empty">No entries in this list.</li>
            @endforelse
        </ul>
    </section>
@endforeach
