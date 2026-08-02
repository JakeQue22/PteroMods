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
if [[ -d "$PANEL_ROOT/pteromods-src/src" ]]; then
    rm -rf "$PANEL_ROOT/pteromods-src/src"
    info "Removed legacy nested pteromods-src/src directory."
fi
mkdir -p "$PANEL_ROOT/pteromods-src"
cp -r "$REPO_ROOT/src/." "$PANEL_ROOT/pteromods-src/"
success "pteromods-src copied."

# ── step 3: patch composer.json autoload ─────────────────────────────────────

echo ""
echo "── Step 3: Registering autoload entries ──"

COMPOSER_JSON="$PANEL_ROOT/composer.json"
[[ -f "$COMPOSER_JSON" ]] || die "composer.json not found in $PANEL_ROOT"

# Only patch if the entries aren't already there
if grep -q '"PteroMods\\\\"' "$COMPOSER_JSON"; then
    warn "PteroMods autoload entry already present – skipping composer.json patch."
else
    php -r "
\$c = json_decode(file_get_contents('$COMPOSER_JSON'), true);
\$c['autoload']['psr-4']['PteroMods\\\\\\\\'] = 'pteromods-src/';
\$c['autoload']['classmap'][] = 'game-panel-mods/';
\$c['autoload']['classmap'] = array_unique(\$c['autoload']['classmap']);
file_put_contents('$COMPOSER_JSON', json_encode(\$c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
"
    success "composer.json updated."
fi

echo ""
echo "── Step 4: Regenerating Composer autoloader ──"
cd "$PANEL_ROOT" && composer dump-autoload --optimize --quiet
success "Autoloader regenerated."

# ── step 5: run SQL migrations ────────────────────────────────────────────────

echo ""
echo "── Step 5: Database migration ──"

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

# ── step 6: mark module state ────────────────────────────────────────────────

STATE_FILE="$PANEL_ROOT/game-panel-mods/.module-state.json"
if [[ ! -f "$STATE_FILE" ]] || [[ "$(cat "$STATE_FILE")" == "{}" ]]; then
    echo ""
    echo "── Step 6: Writing module state ──"
    printf '{\n    "dayz-manager": {\n        "installed": true,\n        "enabled": true,\n        "version": "1.0.0"\n    }\n}\n' \
        > "$STATE_FILE"
    success ".module-state.json written."
fi

# ── step 7: clear caches ─────────────────────────────────────────────────────

echo ""
echo "── Step 7: Clearing panel caches ──"
cd "$PANEL_ROOT"
php artisan route:clear  --quiet && info "route cache cleared."
php artisan config:clear --quiet && info "config cache cleared."
php artisan view:clear   --quiet && info "view cache cleared."
php artisan cache:clear  --quiet && info "app cache cleared."
success "All caches cleared."

# ── step 8: permissions ──────────────────────────────────────────────────────

echo ""
echo "── Step 8: File permissions ──"
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
echo "║  Almost done! One manual step remains:                      ║"
echo "║                                                              ║"
echo "║  Register the module routes in your panel's route files.    ║"
echo "║  See the README §5 for the exact snippet to add.            ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""
success "PteroMods installation complete."
echo ""
