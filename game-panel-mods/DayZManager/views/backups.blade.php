<section class="dz-card">
    <h2>DayZ Server Backups</h2>
    <p class="dz-sub">
        Backups compress the DayZ persistence folder
        (<code>/mpmissions/&lt;mission&gt;/storage_1</code>) on the game server into a
        <code>.tar.gz</code> archive stored at
        <code>/mpmissions/&lt;mission&gt;/storage_1_backups/</code>.
        A safety backup of the current <code>storage_1</code> is taken automatically before
        every Restore or Restore &amp; Restart so you can always roll back if needed.
    </p>
</section>

<section class="dz-card">
    <h2>Create Backup</h2>
    <div class="dz-form" style="align-items:flex-end;flex-wrap:wrap;gap:0.5rem;">
        <div class="dz-input-wrap" style="flex:1 1 16rem;min-width:0;">
            <input id="dz-backup-label" class="dz-input" type="text"
                   placeholder="Label (optional, e.g. pre-update)" />
        </div>
        <button class="dz-btn" onclick="pteroBackupCreate()">Take Backup Now</button>
    </div>
    <p id="dz-backup-create-status" class="dz-status dz-hidden" style="margin-top:0.5rem;"></p>
</section>

<section class="dz-card">
    <h2>Auto-Backup Settings</h2>
    <p class="dz-sub">Configure automatic backups taken in the background while the DayZ Manager page is open.</p>

    <dl class="dz-grid" style="row-gap:1rem;">
        <div class="dz-stat" style="grid-column:1/-1;">
            <dt>
                <label for="dz-ab-enabled" style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
                    <input id="dz-ab-enabled" type="checkbox"
                           {{ $settings['auto_backup_enabled'] ? 'checked' : '' }} />
                    Enable automatic backups
                </label>
            </dt>
        </div>

        <div class="dz-stat" style="grid-column:1/-1;">
            <dt><label for="dz-ab-interval">Backup interval</label></dt>
            <dd>
                <div class="dz-form" style="gap:0.5rem;margin-top:0.25rem;">
                    <select id="dz-ab-interval" class="dz-input" style="flex:0 0 auto;">
                        @foreach ([
                            60    => 'Every hour',
                            360   => 'Every 6 hours',
                            720   => 'Every 12 hours',
                            1440  => 'Every 24 hours (daily)',
                            2880  => 'Every 48 hours',
                            10080 => 'Every week',
                        ] as $minutes => $label)
                            <option value="{{ $minutes }}"
                                {{ (int) $settings['auto_backup_interval_minutes'] === $minutes ? 'selected' : '' }}>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </dd>
        </div>

        <div class="dz-stat" style="grid-column:1/-1;">
            <dt><label for="dz-ab-keep">Backups to keep</label></dt>
            <dd>
                <div class="dz-form" style="gap:0.5rem;margin-top:0.25rem;">
                    <input id="dz-ab-keep" class="dz-input" type="number"
                           min="1" max="100" step="1"
                           value="{{ (int) $settings['auto_backup_keep'] }}"
                           style="flex:0 0 6rem;" />
                    <span class="dz-sub" style="align-self:center;">oldest backups are pruned automatically</span>
                </div>
            </dd>
        </div>
    </dl>

    <div style="margin-top:0.75rem;">
        <button class="dz-btn" onclick="pteroBackupSaveSettings()">Save Settings</button>
    </div>
    <p id="dz-ab-settings-status" class="dz-status dz-hidden" style="margin-top:0.5rem;"></p>
</section>

<section class="dz-card">
    <h2>Stored Backups</h2>
    @if (count($backups) > 0)
        <p class="dz-sub">{{ count($backups) }} backup(s) stored for this server.
            Manual backups are kept regardless of the retention limit.</p>
        <div id="dz-backup-list" style="display:flex;flex-direction:column;gap:0.5rem;margin-top:0.75rem;">
            @foreach ($backups as $backup)
                <div class="dz-backup-row" data-backup-id="{{ $backup['id'] }}" style="display:flex;align-items:center;gap:0.75rem;padding:0.5rem 0.75rem;border:1px solid var(--dz-border,#334155);border-radius:0.375rem;flex-wrap:wrap;">
                    <span style="flex:1 1 12rem;min-width:0;font-size:0.875rem;">
                        <strong>{{ $backup['label'] }}</strong>
                        <span class="dz-sub" style="display:block;font-size:0.75rem;">
                            {{ $backup['created_at'] }}
                            &middot; {{ $backup['trigger'] === 'auto' ? 'automatic' : 'manual' }}
                        </span>
                        @if (!empty($backup['archive_path']))
                            <span class="dz-sub" style="display:block;font-size:0.75rem;word-break:break-all;">
                                Archive: <code>{{ $backup['archive_path'] }}</code>
                            </span>
                        @endif
                    </span>
                    <div style="display:flex;gap:0.4rem;flex-shrink:0;flex-wrap:wrap;">
                        <button class="dz-btn dz-btn-sm dz-btn-amber"
                                onclick="pteroBackupRestore({{ $backup['id'] }}, false)">Restore</button>
                        <button class="dz-btn dz-btn-sm dz-btn-amber"
                                onclick="pteroBackupRestore({{ $backup['id'] }}, true)">Restore &amp; Restart</button>
                        <button class="dz-btn dz-btn-sm dz-btn-ghost"
                                onclick="pteroBackupDelete({{ $backup['id'] }})">Delete</button>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <p class="dz-sub">No backups yet. Click <em>Take Backup Now</em> above to create your first one.</p>
    @endif
    <p id="dz-backup-action-status" class="dz-status dz-hidden" style="margin-top:0.75rem;"></p>
</section>

<script>
(function () {
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    function apiBase() {
        var m = window.location.pathname.match(/^\/(?:servers?|admin\/servers\/view)\/([^/]+)\/dayz/);
        return m ? '/api/server/' + encodeURIComponent(m[1]) : '';
    }

    function req(method, path, body) {
        var headers = {
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        };
        var opts = { method: method, headers: headers };
        if (body) {
            headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
        return fetch(apiBase() + path, opts).then(function (r) { return r.json(); });
    }

    function setStatus(id, msg, ok) {
        var el = document.getElementById(id);
        if (!el) { return; }
        el.textContent = msg;
        el.className = 'dz-status' + (ok === false ? ' dz-status-error' : '');
    }

    window.pteroBackupCreate = function () {
        var label = (document.getElementById('dz-backup-label') || {}).value || '';
        setStatus('dz-backup-create-status', 'Creating backup…');
        req('POST', '/dayz/backups/create', { label: label })
            .then(function (d) {
                if (d.status === 'created') {
                    setStatus('dz-backup-create-status', 'Backup created. Reload to see it in the list.');
                } else {
                    setStatus('dz-backup-create-status', d.message || 'Failed.', false);
                }
            })
            .catch(function () { setStatus('dz-backup-create-status', 'Request failed.', false); });
    };

    window.pteroBackupRestore = function (id, restart) {
        if (!confirm('Restore this backup? A safety backup of the current storage_1 will be taken first.' + (restart ? ' The server will then be restarted.' : ''))) { return; }
        setStatus('dz-backup-action-status', 'Taking safety backup then restoring… this may take a minute.');
        req('POST', '/dayz/backups/restore', { backup_id: id, restart: restart })
            .then(function (d) {
                if (d.status === 'restored') {
                    setStatus('dz-backup-action-status', 'Restored.' + (d.restarted ? ' Server is restarting.' : ' Reload to see the updated backup list.'));
                } else {
                    setStatus('dz-backup-action-status', d.message || 'Restore failed.', false);
                }
            })
            .catch(function () { setStatus('dz-backup-action-status', 'Request failed.', false); });
    };

    window.pteroBackupDelete = function (id) {
        if (!confirm('Delete this backup? This cannot be undone.')) { return; }
        setStatus('dz-backup-action-status', 'Deleting…');
        req('DELETE', '/dayz/backups/' + id, null)
            .then(function (d) {
                if (d.status === 'deleted') {
                    var row = document.querySelector('[data-backup-id="' + id + '"]');
                    if (row) { row.remove(); }
                    setStatus('dz-backup-action-status', 'Backup deleted.');
                } else {
                    setStatus('dz-backup-action-status', d.message || 'Delete failed.', false);
                }
            })
            .catch(function () { setStatus('dz-backup-action-status', 'Request failed.', false); });
    };

    window.pteroBackupSaveSettings = function () {
        var enabled  = document.getElementById('dz-ab-enabled');
        var interval = document.getElementById('dz-ab-interval');
        var keep     = document.getElementById('dz-ab-keep');
        setStatus('dz-ab-settings-status', 'Saving…');
        req('POST', '/dayz/backups/settings', {
            settings: {
                auto_backup_enabled:          enabled  ? (enabled.checked ? '1' : '0') : '0',
                auto_backup_interval_minutes: interval ? interval.value : '1440',
                auto_backup_keep:             keep     ? keep.value : '10',
            },
        })
        .then(function (d) {
            setStatus('dz-ab-settings-status', d.status === 'saved' ? 'Saved.' : (d.message || 'Failed.'), d.status === 'saved' ? undefined : false);
        })
        .catch(function () { setStatus('dz-ab-settings-status', 'Request failed.', false); });
    };

    // Trigger auto-backup tick silently (fire and forget).
    req('POST', '/dayz/backups/tick', {});
}());
</script>
