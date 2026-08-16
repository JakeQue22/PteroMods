<section class="dz-card">
    <h2>Logs</h2>
    <p class="dz-sub">
        DayZ and supported-mod <code>.log</code> and <code>.rpt</code> files are grouped below.
        Select a file to open it in the panel file viewer.
    </p>
</section>

@foreach ($groups as $group)
    <section class="dz-card">
        <details open>
            <summary>
                <h2 style="display:inline;">{{ $group['label'] }}</h2>
                <span class="dz-text-muted" style="margin-left:.5rem;">({{ count($group['entries']) }} file(s))</span>
            </summary>
            <p class="dz-sub">
                <code>{{ $group['path'] }}</code> ·
                <a href="{{ $group['browse_url'] }}" target="_blank" rel="noopener noreferrer">Open directory</a>
            </p>
            <ul class="dz-list">
                @forelse ($group['entries'] as $entry)
                    <li>
                        <span>
                            <a href="{{ $entry['edit_url'] }}" target="_blank" rel="noopener noreferrer">{{ $entry['name'] }}</a>
                            <span class="dz-text-muted"> · {{ $entry['path'] }}</span>
                        </span>
                        <span class="dz-text-muted">
                            {{ $entry['size_display'] }}
                            @if ($entry['modified'] !== '')
                                · {{ $entry['modified'] }}
                            @endif
                        </span>
                    </li>
                @empty
                    <li class="dz-empty">No log files found in this section.</li>
                @endforelse
            </ul>
        </details>
    </section>
@endforeach
