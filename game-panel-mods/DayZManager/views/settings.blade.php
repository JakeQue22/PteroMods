<section class="dz-card">
    <h2>DayZ Manager Settings</h2>
    <p class="dz-sub">
        Panel-wide settings that apply across all DayZ servers. Changes take effect immediately.
    </p>
</section>

<section class="dz-card">
    <h2>Workshop Integration</h2>
    <p class="dz-sub">Control how DayZ Manager interacts with the Steam Workshop.</p>

    <dl class="dz-grid" style="row-gap:1.25rem;">
        @foreach ($labels as $key => $label)
            <div class="dz-stat" style="grid-column:1/-1;">
                <dt style="margin-bottom:0.4rem;">
                    <label for="dz-setting-{{ $key }}" style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
                        <input
                            id="dz-setting-{{ $key }}"
                            type="checkbox"
                            data-setting="{{ $key }}"
                            {{ !empty($settings[$key]) ? 'checked' : '' }}
                            onchange="pteroDzSettingChanged(this)"
                        />
                        {{ $label }}
                    </label>
                </dt>
                @if (!empty($descriptions[$key]))
                    <dd class="dz-sub" style="margin-left:1.6rem;">{{ $descriptions[$key] }}</dd>
                @endif
            </div>
        @endforeach

        @foreach ($text_labels ?? [] as $key => $label)
            <div class="dz-stat" style="grid-column:1/-1;">
                <dt style="margin-bottom:0.4rem;">
                    <label for="dz-text-setting-{{ $key }}">{{ $label }}</label>
                </dt>
                <dd>
                    <div class="dz-form" style="gap:0.5rem;margin-top:0.25rem;">
                        <input
                            id="dz-text-setting-{{ $key }}"
                            class="dz-input"
                            type="{{ str_ends_with($key, '_days') ? 'number' : 'text' }}"
                            data-setting="{{ $key }}"
                            value="{{ $settings[$key] ?? '' }}"
                            placeholder="Enter {{ strtolower($label) }}…"
                            {{ str_ends_with($key, '_days') ? 'min=1 max=3650 step=1' : '' }}
                            style="flex:1 1 20rem;min-width:0;"
                        />
                        <button class="dz-btn" onclick="pteroDzTextSettingSave('{{ $key }}')">Save</button>
                    </div>
                    @if (!empty($descriptions[$key]))
                        <p class="dz-sub" style="margin-top:0.35rem;">{{ $descriptions[$key] }}</p>
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>

    <p id="dz-settings-status" class="dz-status dz-hidden" style="margin-top:0.75rem;"></p>
    <div class="dz-form" style="margin-top:0.75rem;">
        <button class="dz-btn" type="button" onclick="pteroDzRefreshCachesNow()">Update Cache</button>
        <button class="dz-btn dz-btn-ghost" type="button" onclick="pteroDzClearLogsNow()">Prune Logs</button>
    </div>
</section>

<script>
window.pteroDzSettingChanged = function (checkbox) {
    var key = checkbox.getAttribute('data-setting');
    var value = checkbox.checked;
    var status = document.getElementById('dz-settings-status');
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';

    var body = {};
    body['settings[' + key + ']'] = value ? '1' : '0';

    var params = Object.keys(body).map(function (k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(body[k]);
    }).join('&');

    status.className = 'dz-status';
    status.textContent = 'Saving…';

    fetch(window.location.pathname + '/save', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        },
        body: params,
    })
    .then(function (res) { return res.json(); })
    .then(function (data) {
        if (data.status === 'saved') {
            status.textContent = 'Settings saved.';
        } else {
            status.textContent = 'Could not save settings.';
            status.className = 'dz-status dz-status-error';
            checkbox.checked = !checkbox.checked;
        }
    })
    .catch(function () {
        status.textContent = 'Request failed. Please try again.';
        status.className = 'dz-status dz-status-error';
        checkbox.checked = !checkbox.checked;
    });
};

window.pteroDzTextSettingSave = function (key) {
    var input = document.getElementById('dz-text-setting-' + key);
    var status = document.getElementById('dz-settings-status');
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';

    if (!input) { return; }

    var body = 'settings%5B' + encodeURIComponent(key) + '%5D=' + encodeURIComponent(input.value);

    status.className = 'dz-status';
    status.textContent = 'Saving…';

    fetch(window.location.pathname + '/save', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        },
        body: body,
    })
    .then(function (res) { return res.json(); })
    .then(function (data) {
        if (data.status === 'saved') {
            status.textContent = 'Settings saved.';
            status.className = 'dz-status';
        } else {
            status.textContent = 'Could not save settings.';
            status.className = 'dz-status dz-status-error';
        }
    })
    .catch(function () {
        status.textContent = 'Request failed. Please try again.';
        status.className = 'dz-status dz-status-error';
    });
};

window.pteroDzClearLogsNow = function () {
    var status = document.getElementById('dz-settings-status');
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';
    var match = window.location.pathname.match(/^\/(?:servers?|admin\/servers\/view)\/([^/]+)\/dayz/);
    if (!match) {
        status.textContent = 'Could not determine the server for log cleanup.';
        status.className = 'dz-status dz-status-error';
        return;
    }

    status.className = 'dz-status';
    status.textContent = 'Pruning old logs now…';

    fetch('/api/server/' + encodeURIComponent(match[1]) + '/dayz/server/log-scrub/tick', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        },
        body: JSON.stringify({ force: true }),
    })
    .then(function (res) { return res.json(); })
    .then(function (data) {
        if (data.status === 'scrubbed' || data.status === 'throttled') {
            var deleted = Number(data.deleted || 0);
            var scanned = Number(data.scanned || 0);
            status.textContent = 'Log pruning complete. Scanned ' + scanned + ' file(s), deleted ' + deleted + '.';
            status.className = 'dz-status';
        } else {
            status.textContent = data.message || 'Could not prune logs.';
            status.className = 'dz-status dz-status-error';
        }
    })
    .catch(function () {
        status.textContent = 'Request failed. Please try again.';
        status.className = 'dz-status dz-status-error';
    });
};

window.pteroDzRefreshCachesNow = function () {
    var status = document.getElementById('dz-settings-status');
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';
    var match = window.location.pathname.match(/^\/(?:servers?|admin\/servers\/view)\/([^/]+)\/dayz/);
    if (!match) {
        status.textContent = 'Could not determine the server for cache refresh.';
        status.className = 'dz-status dz-status-error';
        return;
    }

    status.className = 'dz-status';
    status.textContent = 'Refreshing DayZ Manager caches…';

    fetch('/api/server/' + encodeURIComponent(match[1]) + '/dayz/settings/cache-refresh', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        },
        body: '{}',
    })
    .then(function (res) { return res.json(); })
    .then(function (data) {
        if (data.status === 'refreshed') {
            var count = Number(data.servers_warmed || 0);
            status.textContent = 'Cache refresh complete. Warmed ' + count + ' server(s).';
            status.className = 'dz-status';
        } else {
            status.textContent = data.message || 'Could not refresh caches.';
            status.className = 'dz-status dz-status-error';
        }
    })
    .catch(function () {
        status.textContent = 'Request failed. Please try again.';
        status.className = 'dz-status dz-status-error';
    });
};
</script>
