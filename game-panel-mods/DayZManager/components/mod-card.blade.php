@php
    $enabled = (bool) ($mod['enabled'] ?? false);
    $installed = (bool) ($mod['installed'] ?? true);
    $serverOnly = (bool) ($mod['server_only'] ?? false);
    $current = (string) ($mod['current_version'] ?? $mod['version'] ?? '');
    $latest = (string) ($mod['latest_version'] ?? '');
    $updateAvailable = $latest !== '' && $latest !== $current;
    $workshopId = (string) ($mod['workshop_id'] ?? '');
    $workshopUrl = (string) ($mod['workshop_url'] ?? '');
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
                <p>
                    @if ($workshopUrl !== '')
                        ID: <a href="{{ $workshopUrl }}" target="_blank" rel="noopener noreferrer">{{ $workshopId }}</a>
                    @else
                        ID: {{ $workshopId !== '' ? $workshopId : 'unknown' }}
                    @endif
                </p>
            </div>
            <span class="dz-badge {{ $enabled ? 'dz-badge-on' : 'dz-badge-off' }}">{{ $enabled ? 'In load order' : 'Not loaded' }}</span>
        </div>

        <dl class="dz-mod-meta">
            <div><dt>Folder</dt><dd>{{ $mod['folder_name'] ?? '—' }}</dd></div>
            <div><dt>Author</dt><dd>{{ ($mod['author'] ?? '') !== '' ? $mod['author'] : '—' }}</dd></div>
            <div><dt>Size</dt><dd>{{ ($mod['file_size'] ?? '') !== '' ? $mod['file_size'] : '—' }}</dd></div>
            <div>
                <dt>Version</dt>
                <dd>
                    {{ $current !== '' ? $current : '—' }}
                    @if ($updateAvailable)
                        <span class="dz-text-amber">&rarr; {{ $latest }}</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt>Load position</dt>
                <dd>{{ isset($mod['position']) ? ((int) $mod['position'] + 1) : '—' }}</dd>
            </div>
            <div>
                <dt>On disk</dt>
                <dd class="{{ $installed ? '' : 'dz-text-red' }}">{{ $installed ? 'Yes' : 'Missing' }}</dd>
            </div>
            @if ($serverOnly)
                <div><dt>Scope</dt><dd>Server-only (-serverMod)</dd></div>
            @endif
            @if (count($dependencies) > 0)
                <div><dt>Dependencies</dt><dd>{{ implode(', ', $dependencies) }}</dd></div>
            @endif
        </dl>

        <div class="dz-mod-actions">
            @if ($enabled)
                <button class="dz-btn dz-btn-sm dz-btn-amber" onclick="pteroModAction('{{ $workshopId !== '' ? $workshopId : ($mod['folder_name'] ?? '') }}', 'disable')">Disable</button>
            @else
                <button class="dz-btn dz-btn-sm dz-btn-green" onclick="pteroModAction('{{ $workshopId !== '' ? $workshopId : ($mod['folder_name'] ?? '') }}', 'enable')">Enable</button>
            @endif
            @if ($updateAvailable)
                <button class="dz-btn dz-btn-sm" onclick="pteroModAction('{{ $workshopId }}', 'update')">Update</button>
            @endif
            <button class="dz-btn dz-btn-sm dz-btn-red" onclick="pteroModAction('{{ $workshopId !== '' ? $workshopId : ($mod['folder_name'] ?? '') }}', 'remove')">Remove</button>
            @if (!empty($server_id) && $installed)
                <a class="dz-btn dz-btn-sm dz-btn-ghost" target="_blank" rel="noopener noreferrer"
                   href="/server/{{ rawurlencode($server_id) }}/files#/{{ rawurlencode((string) ($mod['folder_name'] ?? '')) }}">Files</a>
            @endif
        </div>
    </div>
</article>
