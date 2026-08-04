<section class="dz-card">
    <h2>Configuration Files</h2>
    <p class="dz-sub">
        Every file below is read from the server itself and links straight to its location in the
        panel file manager. Text files open in the text editor, structured files (<code>.cfg</code>,
        <code>.xml</code>, <code>.json</code>) open in the code editor with matching syntax highlighting.
    </p>
</section>

@foreach ($groups as $group)
    <section class="dz-card">
        <details>
            <summary>
                <h2 style="display:inline;">{{ $group['label'] }}</h2>
                <span class="dz-text-muted" style="margin-left:.5rem;">({{ count($group['entries']) }} file(s))</span>
            </summary>
            <p class="dz-sub">
                <code>{{ $group['path'] }}</code> ·
                <a href="{{ $group['browse_url'] }}" target="_blank" rel="noopener noreferrer">Open directory</a> ·
                {{ count($group['entries']) }} file(s)
            </p>
            <ul class="dz-list">
                @forelse ($group['entries'] as $entry)
                    <li>
                        <span>
                            <a href="{{ $entry['edit_url'] }}" target="_blank" rel="noopener noreferrer">{{ $entry['name'] }}</a>
                            <span class="dz-text-muted"> · {{ $entry['path'] }}</span>
                        </span>
                        <span class="dz-text-muted">
                            {{ $entry['category'] }} · {{ $entry['editor'] }}
                            @if ($entry['language'] !== 'plaintext')
                                ({{ $entry['language'] }})
                            @endif
                            @if ($entry['exists'])
                                · {{ $entry['size'] }}
                            @else
                                · not found
                            @endif
                        </span>
                    </li>
                @empty
                    <li class="dz-empty">No configuration files in this directory.</li>
                @endforelse
            </ul>
        </details>
    </section>
@endforeach

<section class="dz-card">
    <h2>Editor Capabilities</h2>
    <ul class="dz-tags">
        @foreach ($editor_features as $feature)
            <li>{{ $feature }}</li>
        @endforeach
    </ul>
</section>
