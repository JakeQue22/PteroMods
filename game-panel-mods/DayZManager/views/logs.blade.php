<section class="dz-card">
    <h2>Logs</h2>
    <p class="dz-sub">
        DayZ and supported-mod <code>.log</code> and <code>.rpt</code> files are grouped below.
        Select a file to open it in the panel file viewer.
    </p>
    <div class="dz-form" style="margin-top:0.75rem;">
        <button class="dz-btn dz-btn-ghost" type="button" onclick="pteroDzLogsCollapseAll()">Collapse all</button>
        <button class="dz-btn dz-btn-ghost" type="button" onclick="pteroDzLogsExpandAll()">Expand all</button>
    </div>
</section>

@foreach ($groups as $group)
    <section class="dz-card">
        <details open data-log-group="{{ $group['key'] }}">
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
                            @if (($entry['modified_display'] ?? '') !== '')
                                · {{ $entry['modified_display'] }}
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

<script>
(function () {
    var details = Array.prototype.slice.call(document.querySelectorAll('details[data-log-group]'));
    var storageKey = 'pteromods.dayz.logs.state:' + window.location.pathname;

    function loadState() {
        try {
            var raw = window.localStorage.getItem(storageKey);
            var parsed = raw ? JSON.parse(raw) : null;
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (e) {
            return {};
        }
    }

    function saveState(state) {
        try {
            window.localStorage.setItem(storageKey, JSON.stringify(state));
        } catch (e) {
            // ignore
        }
    }

    function setAll(open) {
        var state = loadState();
        details.forEach(function (el) {
            var key = el.getAttribute('data-log-group') || '';
            el.open = open;
            if (key !== '') {
                state[key] = open;
            }
        });
        saveState(state);
    }

    var state = loadState();
    details.forEach(function (el) {
        var key = el.getAttribute('data-log-group') || '';
        if (key !== '' && Object.prototype.hasOwnProperty.call(state, key)) {
            el.open = !!state[key];
        }
        el.addEventListener('toggle', function () {
            var next = loadState();
            if (key !== '') {
                next[key] = el.open;
                saveState(next);
            }
        });
    });

    window.pteroDzLogsCollapseAll = function () { setAll(false); };
    window.pteroDzLogsExpandAll = function () { setAll(true); };
}());
</script>
