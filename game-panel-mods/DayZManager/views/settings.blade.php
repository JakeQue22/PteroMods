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
    </dl>

    <p id="dz-settings-status" class="dz-status dz-hidden" style="margin-top:0.75rem;"></p>
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
</script>
