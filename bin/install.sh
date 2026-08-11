#!/usr/bin/env bash
# PteroMods install helper
# Usage: bash bin/install.sh [PANEL_ROOT] [DB_USER] [DB_PASS] [DB_NAME]
#
# Defaults:
#   PANEL_ROOT = /var/www/pterodactyl
#   DB_USER    = pterodactyl
#   DB_NAME    = panel
#
# Run as the user that owns the panel files (e.g. sudo -u www-data bash bin/install.sh)

set -euo pipefail

PANEL_ROOT="${1:-/var/www/pterodactyl}"
DB_USER="${2:-pterodactyl}"
DB_PASS="${3:-}"
DB_NAME="${4:-panel}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"
SQL_FILE="$REPO_ROOT/game-panel-mods/DayZManager/database/install.sql"

# ── helpers ──────────────────────────────────────────────────────────────────

info()    { echo "  [info]  $*"; }
success() { echo "  [ok]    $*"; }
warn()    { echo "  [warn]  $*" >&2; }
die()     { echo "  [error] $*" >&2; exit 1; }

require_cmd() { command -v "$1" &>/dev/null || die "'$1' not found. Please install it first."; }

# ── preflight ────────────────────────────────────────────────────────────────

echo ""
echo "╔══════════════════════════════════════════╗"
echo "║        PteroMods – Install Helper        ║"
echo "╚══════════════════════════════════════════╝"
echo ""

[[ -d "$PANEL_ROOT" ]]              || die "Panel root not found: $PANEL_ROOT"
[[ -f "$PANEL_ROOT/artisan" ]]      || die "No artisan file found in $PANEL_ROOT – is this a Pterodactyl panel?"
require_cmd php
require_cmd composer

info "Panel root : $PANEL_ROOT"
info "Repo root  : $REPO_ROOT"
echo ""

# ── step 1: copy modules ─────────────────────────────────────────────────────

echo "── Step 1: Copying game-panel-mods ──"
cp -r "$REPO_ROOT/game-panel-mods/." "$PANEL_ROOT/game-panel-mods/"
success "game-panel-mods copied."

echo ""
echo "── Step 2: Copying PteroMods src ──"
mkdir -p "$PANEL_ROOT/pteromods-src"
cp -r "$REPO_ROOT/src/." "$PANEL_ROOT/pteromods-src/"

# Clean up nested src/ sub-directory left by any previous install that used the
# pteromods-src/src/ layout, so only the flat layout remains and there are no
# duplicate class files that would confuse Composer's PSR-4 scanner.
rm -rf "$PANEL_ROOT/pteromods-src/src"

success "pteromods-src copied and legacy layout cleaned."

# ── step 3: patch composer.json autoload ─────────────────────────────────────

echo ""
echo "── Step 3: Registering autoload entries ──"

COMPOSER_JSON="$PANEL_ROOT/composer.json"
[[ -f "$COMPOSER_JSON" ]] || die "composer.json not found in $PANEL_ROOT"

# Write the patcher to a temp file so that backslash characters in PHP string
# literals are not mangled by bash's double-quote escaping rules.
# The single-quoted heredoc ('PHPEOF') passes PHP source verbatim.
cat > /tmp/pteromods-composer-patch.php << 'PHPEOF'
<?php
declare(strict_types=1);
$composerJson = $argv[1] ?? '';
if ($composerJson === '' || !is_file($composerJson)) {
    fwrite(STDERR, 'composer.json not found: ' . $composerJson . PHP_EOL);
    exit(1);
}
$c = json_decode(file_get_contents($composerJson), true);
if (!is_array($c)) {
    fwrite(STDERR, 'Invalid composer.json' . PHP_EOL);
    exit(1);
}
$changed = false;
$c['autoload'] = is_array($c['autoload'] ?? null) ? $c['autoload'] : [];
$c['autoload']['psr-4'] = is_array($c['autoload']['psr-4'] ?? null) ? $c['autoload']['psr-4'] : [];
$c['autoload']['classmap'] = is_array($c['autoload']['classmap'] ?? null) ? $c['autoload']['classmap'] : [];
// PSR-4 prefix requires exactly one trailing backslash: 'PteroMods\'
if (($c['autoload']['psr-4']['PteroMods\\'] ?? null) !== 'pteromods-src/') {
    $c['autoload']['psr-4']['PteroMods\\'] = 'pteromods-src/';
    $changed = true;
}
if (!in_array('game-panel-mods/', $c['autoload']['classmap'], true)) {
    $c['autoload']['classmap'][] = 'game-panel-mods/';
    $changed = true;
}
if ($changed) {
    file_put_contents($composerJson, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    echo 'patched';
} else {
    echo 'unchanged';
}
PHPEOF

PATCH_STATUS="$(php /tmp/pteromods-composer-patch.php "$COMPOSER_JSON")"
rm -f /tmp/pteromods-composer-patch.php

if [[ "$PATCH_STATUS" == "patched" ]]; then
    success "composer.json autoload normalized."
else
    info "composer.json autoload already normalized."
fi

echo ""
echo "── Step 4: Regenerating Composer autoloader ──"
cd "$PANEL_ROOT" && composer dump-autoload --optimize --quiet
success "Autoloader regenerated."

echo ""
echo "── Step 5: Registering module routes ──"

cat > /tmp/pteromods-routes-patch.php << 'PHPEOF'
<?php
declare(strict_types=1);
$panelRoot = $argv[1] ?? '';
$candidates = ['routes/base.php', 'routes/web.php'];
$include = "\nrequire base_path('game-panel-mods/routes-loader.php');\n";
$patched = [];
$found = false;
foreach ($candidates as $candidate) {
    $file = $panelRoot . '/' . $candidate;
    if (!is_file($file)) {
        continue;
    }
    $found = true;
    $contents = (string) file_get_contents($file);
    if (str_contains($contents, 'game-panel-mods/routes-loader.php')) {
        continue;
    }
    // The panel registers a catch-all route that forwards unknown URIs to the
    // JavaScript client, so module routes must be registered before it: insert
    // the loader immediately after the opening PHP tag.
    $position = strpos($contents, '<?php');
    if ($position === false) {
        continue;
    }
    $offset = $position + strlen('<?php');
    $contents = substr($contents, 0, $offset) . $include . substr($contents, $offset);
    file_put_contents($file, $contents);
    $patched[] = $candidate;
}
if (!$found) {
    echo 'missing';
    exit(0);
}
echo $patched === [] ? 'unchanged' : implode(',', $patched);
PHPEOF

ROUTE_STATUS="$(php /tmp/pteromods-routes-patch.php "$PANEL_ROOT")"
rm -f /tmp/pteromods-routes-patch.php

case "$ROUTE_STATUS" in
    missing)   warn "No routes/base.php or routes/web.php found – register routes manually (README §5)." ;;
    unchanged) info "Module routes already registered." ;;
    *)         success "Module routes registered in: $ROUTE_STATUS" ;;
esac

echo ""
echo "── Step 5b: Registering navigation tab script ──"

cat > /tmp/pteromods-tab-patch.php << 'PHPEOF'
<?php
declare(strict_types=1);
$panelRoot = $argv[1] ?? '';
// The client area is a JavaScript application and the admin area is Blade, so
// the tab script is loaded from both layouts; it decides where to inject the
// "DayZ Manager" link based on the current URL.
$candidates = ['resources/views/templates/wrapper.blade.php', 'resources/views/layouts/admin.blade.php'];
$tag = '<script src="/game-panel-mods/dayz-manager/tab.js"></script>';
$patched = [];
$found = false;
foreach ($candidates as $candidate) {
    $file = $panelRoot . '/' . $candidate;
    if (!is_file($file)) {
        continue;
    }
    $found = true;
    $contents = (string) file_get_contents($file);
    if (str_contains($contents, 'dayz-manager/tab.js')) {
        continue;
    }
    $position = strripos($contents, '</body>');
    if ($position === false) {
        continue;
    }
    $contents = substr($contents, 0, $position) . '        ' . $tag . "\n" . substr($contents, $position);
    file_put_contents($file, $contents);
    $patched[] = $candidate;
}
if (!$found) {
    echo 'missing';
    exit(0);
}
echo $patched === [] ? 'unchanged' : implode(',', $patched);
PHPEOF

TAB_STATUS="$(php /tmp/pteromods-tab-patch.php "$PANEL_ROOT")"
rm -f /tmp/pteromods-tab-patch.php

case "$TAB_STATUS" in
    missing)   warn "Panel layouts not found - add the tab script manually (README, navigation tab)." ;;
    unchanged) info "Navigation tab script already registered." ;;
    *)         success "Navigation tab script registered in: $TAB_STATUS" ;;
esac

# ── step 6: run SQL migrations ────────────────────────────────────────────────

echo ""
echo "── Step 6: Database migration ──"

if [[ -n "$DB_PASS" ]]; then
    mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$SQL_FILE" \
        && success "SQL schema applied to '$DB_NAME'." \
        || warn "MySQL import failed. Run it manually (see step below)."
else
    warn "No DB password supplied – skipping automatic migration."
    echo ""
    echo "  Run the SQL file manually:"
    echo "    mysql -u $DB_USER -p $DB_NAME < $SQL_FILE"
fi

# ── step 7: mark module state ────────────────────────────────────────────────

STATE_FILE="$PANEL_ROOT/game-panel-mods/.module-state.json"
if [[ ! -f "$STATE_FILE" ]] || [[ "$(cat "$STATE_FILE")" == "{}" ]]; then
    echo ""
    echo "── Step 7: Writing module state ──"
    printf '{\n    "dayz-manager": {\n        "installed": true,\n        "enabled": true,\n        "version": "1.3.0"\n    }\n}\n' \
        > "$STATE_FILE"
    success ".module-state.json written."
fi

# ── step 7b: live map bridge template ────────────────────────────────────────
#
# The bridge SQF script (pteromods_live_map.sqf) is bundled with the module and
# needs to be present in the panel's asset directory so the panel can push it to
# each game-server container via the Wings file API on first use.
#
# The panel deploys it automatically the first time a user opens the Live Map
# page for any DayZ server.  To force re-deployment for all servers you can also
# POST to /api/server/{server}/dayz/live-map/setup-bridge from the panel.

echo ""
echo "── Step 7b: Verifying live map bridge template ──"

BRIDGE_TEMPLATE="$PANEL_ROOT/game-panel-mods/DayZManager/assets/bridge/pteromods_live_map.sqf"

if [[ -f "$BRIDGE_TEMPLATE" ]]; then
    success "Bridge template present: $BRIDGE_TEMPLATE"
else
    warn "Bridge template not found at $BRIDGE_TEMPLATE"
    warn "Re-run install.sh or manually copy:"
    warn "  game-panel-mods/DayZManager/assets/bridge/pteromods_live_map.sqf"
    warn "  → $BRIDGE_TEMPLATE"
fi

echo ""
echo "  The bridge script is deployed to each DayZ server's"
echo "  /profiles/PteroMods/ directory automatically when you"
echo "  first open the Live Map page for that server, or via:"
echo "  POST /api/server/{server}/dayz/live-map/setup-bridge"
echo ""
echo "  Once deployed, add this line to your mission's init.sqf:"
echo "    if (isServer) then { execVM \"profiles\\PteroMods\\pteromods_live_map.sqf\"; };"

# ── step 8: clear caches ─────────────────────────────────────────────────────

echo ""
echo "── Step 8: Clearing panel caches ──"
cd "$PANEL_ROOT"
php artisan route:clear  --quiet && info "route cache cleared."
php artisan config:clear --quiet && info "config cache cleared."
php artisan view:clear   --quiet && info "view cache cleared."
php artisan cache:clear  --quiet && info "app cache cleared."
success "All caches cleared."

# ── step 9: permissions ──────────────────────────────────────────────────────

echo ""
echo "── Step 9: File permissions ──"
WEB_USER="www-data"
if id "$WEB_USER" &>/dev/null; then
    chown -R "$WEB_USER:$WEB_USER" "$PANEL_ROOT/game-panel-mods" 2>/dev/null \
        && success "Ownership set to $WEB_USER." \
        || warn "Could not set ownership – run: sudo chown -R $WEB_USER:$WEB_USER $PANEL_ROOT/game-panel-mods"
else
    warn "Web user '$WEB_USER' not found – set permissions manually."
fi

# ── final instructions ────────────────────────────────────────────────────────

echo ""
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║  Open /server/<server>/dayz on a DayZ server to verify.      ║"
echo "║  If routes 404, see README §5 (route registration).          ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""
success "PteroMods installation complete."
echo ""
