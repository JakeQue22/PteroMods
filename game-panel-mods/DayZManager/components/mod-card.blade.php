@php
    $enabled = (bool) ($mod['enabled'] ?? false);
    $current = (string) ($mod['current_version'] ?? $mod['version'] ?? '');
    $latest = (string) ($mod['latest_version'] ?? '');
    $updateAvailable = $latest !== '' && $latest !== $current;
    $dependencies = $mod['dependencies'] ?? [];
    $dependencies = is_array($dependencies) ? $dependencies : array_filter(explode(',', (string) $dependencies));
@endphp
<article class="dz-mod-card">
    @if (!empty($mod['thumbnail']))
        <img src="{{ $mod['thumbnail'] }}" alt="{{ $mod['title'] ?? 'Mod' }} thumbnail" loading="lazy" />
    @endif
    <div class="dz-mod-body">
        <div class="dz-mod-head">
            <div>
                <h3>{{ $mod['title'] ?? 'Unknown mod' }}</h3>
                <p>ID: {{ $mod['workshop_id'] ?? '—' }}</p>
            </div>
            <span class="dz-badge {{ $enabled ? 'dz-badge-on' : 'dz-badge-off' }}">{{ $enabled ? 'Enabled' : 'Disabled' }}</span>
        </div>

        <dl class="dz-mod-meta">
            <div><dt>Folder</dt><dd>{{ $mod['folder_name'] ?? '—' }}</dd></div>
            <div><dt>Author</dt><dd>{{ $mod['author'] ?? '—' }}</dd></div>
            <div><dt>Size</dt><dd>{{ $mod['file_size'] ?? '—' }}</dd></div>
            <div>
                <dt>Version</dt>
                <dd>
                    {{ $current !== '' ? $current : '—' }}
                    @if ($updateAvailable)
                        <span class="dz-text-amber">&rarr; {{ $latest }}</span>
                    @endif
                </dd>
            </div>
            <div><dt>Dependencies</dt><dd>{{ count($dependencies) > 0 ? implode(', ', $dependencies) : 'None' }}</dd></div>
        </dl>

        <div class="dz-mod-actions">
            @if ($enabled)
                <button class="dz-btn dz-btn-sm dz-btn-amber" onclick="pteroModAction('{{ $mod['workshop_id'] ?? '' }}', 'disable')">Disable</button>
            @else
                <button class="dz-btn dz-btn-sm dz-btn-green" onclick="pteroModAction('{{ $mod['workshop_id'] ?? '' }}', 'enable')">Enable</button>
            @endif
            @if ($updateAvailable)
                <button class="dz-btn dz-btn-sm" onclick="pteroModAction('{{ $mod['workshop_id'] ?? '' }}', 'update')">Update</button>
            @endif
            <button class="dz-btn dz-btn-sm dz-btn-red" onclick="pteroModAction('{{ $mod['workshop_id'] ?? '' }}', 'remove')">Remove</button>
        </div>
    </div>
</article>
