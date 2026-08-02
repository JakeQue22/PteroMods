<section class="dz-card">
    <h2>Workshop Mod Manager</h2>
    <p class="dz-sub">Search, install, update, remove, and reorder DayZ mods with automatic dependency planning.</p>
    <dl class="dz-grid">
        @foreach ($settings as $key => $value)
            <div class="dz-stat">
                <dt>{{ str_replace('_', ' ', $key) }}</dt>
                <dd>{{ is_bool($value) ? ($value ? 'Enabled' : 'Disabled') : ($value === '' ? '—' : $value) }}</dd>
            </div>
        @endforeach
    </dl>
</section>

<section class="dz-card">
    <h2>Install Workshop Mod</h2>
    <p class="dz-sub">Enter a raw Workshop ID (e.g. <code>1559212036</code>) or a full Steam Workshop URL.</p>
    <div class="dz-form">
        <input id="ptero-workshop-ref" class="dz-input" type="text" placeholder="Workshop ID or Steam Workshop URL" />
        <button class="dz-btn" onclick="pteroInstallMod()">Install</button>
    </div>
    <p id="ptero-install-status" class="dz-status dz-hidden"></p>
</section>

<section class="dz-card">
    <h2>Installed Mods</h2>
    <p class="dz-sub">{{ count($installed_mods) }} mod(s) installed.</p>
    @if (count($installed_mods) > 0)
        <div class="dz-mod-grid">
            @foreach ($installed_mods as $mod)
                {!! $component('mod-card', ['mod' => $mod]) !!}
            @endforeach
        </div>
    @else
        <p class="dz-sub">No mods installed yet.</p>
    @endif
</section>

{!! $component('mod-actions', ['server_id' => $server_id]) !!}
