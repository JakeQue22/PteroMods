<section class="dz-card">
    <h2>DayZ SA Launcher — Server Check</h2>
    <p class="dz-sub">
        The <strong>DayZ Standalone Launcher (DZSA)</strong> maintains its own server listing that tracks which
        mods a server runs. After adding or removing mods you should trigger a server listing update so players
        see the correct mod list in DZSA.
    </p>
    @if ($dzsa_url !== '')
        <dl class="dz-grid">
            <div class="dz-stat">
                <dt>Server IP</dt>
                <dd>{{ $dzsa_ip }}</dd>
            </div>
            <div class="dz-stat">
                <dt>Query Port</dt>
                <dd>{{ $dzsa_query_port }}</dd>
            </div>
            <div class="dz-stat">
                <dt>DZSA Check URL</dt>
                <dd style="word-break:break-all;font-size:0.85em;">{{ $dzsa_url }}</dd>
            </div>
        </dl>
        <div class="dz-form" style="margin-top:0.9rem;">
            <button class="dz-btn" onclick="pteroOpenDzsa()">Update DZSA Server Listing</button>
        </div>
        <p class="dz-sub" style="margin-top:0.5rem;">
            Clicking the button above will ask the DZSA service to re-query your server and refresh its mod
            list. This is needed after every mod change so DZSA shows the correct mods to players.
        </p>
    @else
        <p class="dz-sub dz-text-amber">
            The server's public numeric IP address could not be determined. Make sure the server has a primary
            allocation with a public IP address or a resolvable hostname configured as its alias.
        </p>
    @endif
</section>

<script>
(function () {
    const DZSA_URL = @json($dzsa_url);

    window.pteroOpenDzsa = function () {
        if (!DZSA_URL) {
            alert('DZSA URL is not available. Check that this server has a public IP address configured.');
            return;
        }

        if (!confirm(
            'This will open the DayZ SA Launcher server-check page for your server.\n\n'
            + 'DZSA will re-query your server and update its mod listing.\n\n'
            + 'Press OK to open: ' + DZSA_URL
        )) {
            return;
        }

        window.open(DZSA_URL, '_blank', 'noopener,noreferrer');
    };
}());
</script>
