# PteroMods

PteroMods is a modular "Game Panel Mods" framework scaffold for Pterodactyl-style panels, with a first-class DayZ server management module.

---

## Table of contents

1. [Requirements](#requirements)
2. [Features](#features)
3. [Installing on an existing Pterodactyl panel](#installing-on-an-existing-pterodactyl-panel)
4. [Module lifecycle](#module-lifecycle)
5. [DayZ Manager](#dayz-manager)
   - [Dashboard](#dashboard)
   - [Workshop mod management](#workshop-mod-management)
   - [Player list management](#player-list-management)
   - [Server control](#server-control)
   - [Configuration editor](#configuration-editor)
   - [API endpoints](#api-endpoints)
   - [Permissions](#permissions)
   - [Database schema](#database-schema)
6. [Architecture](#architecture)
   - [Module contract](#module-contract)
   - [Runtime notes](#runtime-notes)
7. [Troubleshooting](#troubleshooting)
8. [Validation](#validation)

---

## Requirements

| Requirement | Minimum version |
|---|---|
| PHP | 8.2 |
| Pterodactyl Panel | 1.x (Laravel-based) |
| Composer | 2.x |
| DayZ Dedicated Server egg | any |

---

## Features

- **Modular architecture** – scan, install, enable, and disable independent game-server modules without touching panel core files.
- **DayZ Manager** – dashboard, Steam Workshop mod tooling, dependency-aware install planning, mod reordering, player list management, server restart controls, and configuration file editing from the panel.
- **Workshop dependency planner** – automatically resolves mod load order and injects CommunityFramework (CF, ID `1559212036`) when a mod requires it.
- **Launch parameter builder** – generates the correct `-mod=` string from your ordered, enabled mod list; preview endpoint keeps operators informed.
- **Mod reorder** – drag-and-drop position management persists to `dayz_mods.position` and immediately rebuilds the `-mod=` launch string.
- **Player list management** – ban, whitelist, and priority queue management by Steam64 ID or GUID, backed by the `dayz_player_lists` database table.
- **Server restart** – schedule a graceful server restart with an optional reason, directly from the panel.
- **Configuration catalogue** – groups DayZ config files (`serverDZ.cfg`, `BEServer.cfg`, admin files, message files, etc.) into operator-facing categories with per-save backups.
- **Configuration backups** – every config save is versioned to the `dayz_configuration_backups` table.
- **Easy SQL install** – a single `install.sql` file covers the complete MySQL schema. Run it in one command; no Tinker, no copy-pasting.
- **Automated install script** – `bin/install.sh` handles file copying, autoloader patching, SQL import, cache clearing, and permissions in one shot.
- **Extensible** – `ArkManager`, `RustManager`, and `MinecraftManager` module shells follow the same installable contract.

---

## Installing on an existing Pterodactyl panel

These steps assume your panel is already running at `/var/www/pterodactyl`. Adjust the path if your installation differs.

> **Tip:** Run all commands as the user that owns the panel files (often `www-data` or a dedicated `pterodactyl` user). Prefix with `sudo -u www-data` if needed.

### Option A — Automated install script (recommended)

The `bin/install.sh` script handles steps 1–7 in one command. Pass the panel root and your MySQL credentials:

```bash
# Clone PteroMods somewhere temporary
git clone https://github.com/JakeQue22/PteroMods.git /tmp/pteromods

# Run the installer (panel root, DB user, DB password, DB name)
bash /tmp/pteromods/bin/install.sh /var/www/pterodactyl pterodactyl mypassword panel
```

The script copies files, patches `composer.json`, runs `composer dump-autoload`, imports the SQL schema, writes `.module-state.json`, and clears all panel caches. After it finishes, complete **Step 5** (route registration) from the manual steps below.

---

### Option B — Manual steps

#### 1. Download or clone PteroMods

```bash
git clone https://github.com/JakeQue22/PteroMods.git /tmp/pteromods
```

#### 2. Copy the module files into the panel

```bash
mkdir -p /var/www/pterodactyl/game-panel-mods
cp -r /tmp/pteromods/game-panel-mods/* /var/www/pterodactyl/game-panel-mods/
mkdir -p /var/www/pterodactyl/pteromods-src
cp -r /tmp/pteromods/src/.          /var/www/pterodactyl/pteromods-src/
```

#### 3. Register the autoloader

Add the PteroMods namespaces to your panel's `composer.json` (under `autoload.psr-4` and `autoload.classmap`):

```jsonc
// /var/www/pterodactyl/composer.json  (relevant section only)
"autoload": {
    "psr-4": {
        "Pterodactyl\\": "app/",
        "PteroMods\\": "pteromods-src/"   // <-- add this
    },
    "classmap": [
        "game-panel-mods/"               // <-- add this
    ]
}
```

Then regenerate the autoloader:

```bash
cd /var/www/pterodactyl
composer dump-autoload --optimize
```

#### 4. Run the database migrations

PteroMods ships a ready-to-run MySQL SQL file. Import it directly — no Tinker, no copy-pasting:

```bash
mysql -u pterodactyl -p panel \
  < /tmp/pteromods/game-panel-mods/DayZManager/database/install.sql
```

Replace `pterodactyl`, `panel`, and the file path to match your setup. The file creates all three tables (`dayz_mods`, `dayz_configuration_backups`, `dayz_player_lists`) with `IF NOT EXISTS` guards so it is safe to re-run.

> **Existing installs** that already ran the v1.0 PHP migration only need the player-lists table. Run the incremental migration instead:
>
> ```bash
> mysql -u pterodactyl -p panel -e "
> CREATE TABLE IF NOT EXISTS \`dayz_player_lists\` (
>     \`id\`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
>     \`list_type\`  ENUM('ban','whitelist','priority') NOT NULL,
>     \`player_id\`  VARCHAR(64)   NOT NULL,
>     \`note\`       VARCHAR(255)  NOT NULL DEFAULT '',
>     \`added_by\`   VARCHAR(64)   NOT NULL DEFAULT '',
>     \`created_at\` TIMESTAMP     NULL DEFAULT NULL,
>     PRIMARY KEY (\`id\`),
>     UNIQUE KEY \`uq_dayz_player_lists_type_player\` (\`list_type\`, \`player_id\`)
> ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
> ```

#### 5. Register module routes

PteroMods ships a route loader that registers the page routes (`routes.php`) and
JSON endpoints (`api/routes.php`) of every **enabled** module behind the panel's
`auth` middleware.

Add this single line to your panel's web route file — `routes/base.php` on
current Pterodactyl versions, `routes/web.php` on older ones — **immediately
after the opening `<?php` tag**:

```php
require base_path('game-panel-mods/routes-loader.php');
```

> The loader must run *before* the panel's catch-all route (the one that
> forwards every unknown URI to the JavaScript client). If it is appended at the
> bottom of the file instead, the client application answers `/server/{id}/dayz`
> first and the module pages appear as 404s.

`bin/install.sh` performs this patch automatically (step 5) and skips it when the
line is already present.

Then rebuild the route cache:

```bash
php artisan route:clear
php artisan route:cache
```

Verify with `php artisan route:list | grep dayz` — you should see both the
`/server/{server}/dayz*` page routes and the `/api/server/{server}/dayz/*`
endpoints.

#### 6. Mark the module as installed and enabled

Edit `game-panel-mods/.module-state.json`:

```json
{
    "dayz-manager": {
        "installed": true,
        "enabled": true,
        "version": "1.2.0"
    }
}
```

#### 7. Clear caches and set permissions

```bash
php artisan config:clear
php artisan view:clear
php artisan cache:clear
chown -R www-data:www-data /var/www/pterodactyl/game-panel-mods
```

The DayZ Manager tabs will now be available to any server whose egg resolves to one of the supported DayZ identifiers: `dayz`, `dayz-dedicated`, `source-engine-dayz`, `source-engine`, or `source`.

---

## Module lifecycle

Module state is stored in `game-panel-mods/.module-state.json`. The `ModuleLifecycleManager` service exposes five transitions:

| Method | Effect |
|---|---|
| `install($manifest)` | Marks module installed, disabled |
| `enable($manifest)` | Marks module installed and enabled |
| `disable($manifest)` | Marks module installed but disabled |
| `update($manifest, $version)` | Updates stored version without changing install/enable flags |
| `uninstall($manifest)` | Removes the module entry entirely |

`ModuleScanner::scan($modulesPath)` validates that every required path exists and returns a sorted list of `{ manifest, valid, missing }` entries you can display in an admin catalogue.

---

## DayZ Manager

> Route style note: every DayZ endpoint documented with `/servers/{server}` also has a singular alias using `/server/{server}` (including API routes under `/api/server/{server}/...`).

### Dashboard

URL: `GET /servers/{server}/dayz`

Displays a card grid with: server name, current map, server version, installed mod count, player count, CPU, RAM, disk usage, and server status.

**Live values.** Current map, player count, and server version are read from the
game server itself over the Steam query protocol (A2S_INFO) by
`SourceQueryClient` and `DayZServerQueryService`:

- the query host comes from the server's primary allocation (falling back to the node FQDN when the allocation is bound to `0.0.0.0`);
- the query port is taken from a `STEAM_QUERY_PORT`/`QUERY_PORT` egg variable, otherwise from an allocation matching the standard DayZ query port, otherwise from the game port plus the standard DayZ offset (`2302` → `27016`);
- results are cached for 15 seconds, and mission names such as `dayzOffline.chernarusplus` are shown as friendly map names (`Chernarus+`);
- when the server does not answer, those cards show `N/A` and the status card reads `offline`.

**Rendering.** Module pages are rendered by `DayZPageRenderer` into a
self-contained, panel-themed HTML document with the module stylesheet inlined,
so they display correctly regardless of which assets the panel front end
exposes. Every page shares the same tab navigation and a link back to the
server.

### Workshop mod management

URL: `GET /servers/{server}/dayz/mods`

| Action | Method | Endpoint |
|---|---|---|
| List installed mods | GET | `/servers/{server}/dayz/mods` |
| Install mod | POST | `/api/servers/{server}/dayz/mods/install` |
| Remove mod | POST | `/api/servers/{server}/dayz/mods/remove` |
| Update mod | POST | `/api/servers/{server}/dayz/mods/update` |
| Enable mod | POST | `/api/servers/{server}/dayz/mods/enable` |
| Disable mod | POST | `/api/servers/{server}/dayz/mods/disable` |
| Reorder mods | POST | `/api/servers/{server}/dayz/mods/reorder` |

**Install payload** — pass either a raw Workshop ID or a full Steam Workshop URL:

```json
{
    "reference": "https://steamcommunity.com/sharedfiles/filedetails/?id=1559212036",
    "metadata": {
        "1559212036": { "requires_cf": false }
    }
}
```

The `WorkshopReferenceParser` normalises both numeric IDs and `?id=` query-string URLs. The `WorkshopDependencyPlanner` resolves the full load order including transitive dependencies and auto-injects CommunityFramework (ID `1559212036`) for any mod that declares `"requires_cf": true`.

**Reorder payload** — pass Workshop IDs in the desired load order:

```json
{
    "ordered_workshop_ids": ["1559212036", "2545327648", "1564026768"]
}
```

The response includes the updated `-mod=` launch parameter string built from enabled mods in that order.

### Player list management

URL: `GET /servers/{server}/dayz/players`

Manage the DayZ ban list, whitelist, and priority queue by Steam64 ID or GUID. All changes are persisted to the `dayz_player_lists` database table.

| Action | Method | Endpoint |
|---|---|---|
| List entries | GET | `/api/servers/{server}/dayz/players/{list_type}` |
| Add entry | POST | `/api/servers/{server}/dayz/players/{list_type}` |
| Remove entry | DELETE | `/api/servers/{server}/dayz/players/{list_type}/{id}` |

`{list_type}` must be one of `ban`, `whitelist`, or `priority`.

**Add payload:**

```json
{
    "player_id": "76561198012345678",
    "note": "Cheating",
    "added_by": "admin"
}
```

### Server control

URL: `GET /servers/{server}/dayz/server`

Displays the current active launch parameters and provides a restart form.

| Action | Method | Endpoint |
|---|---|---|
| Get launch parameters | GET | `/api/servers/{server}/dayz/server/launch-parameters` |
| Restart server | POST | `/api/servers/{server}/dayz/server/restart` |

**Restart payload** (optional):

```json
{
    "reason": "Mod update applied"
}
```

### Configuration editor

URL: `GET /servers/{server}/dayz/configuration`

| Action | Method | Endpoint |
|---|---|---|
| List config files | GET | `/api/servers/{server}/dayz/configuration` |
| Save config file | PUT | `/api/servers/{server}/dayz/configuration` |

The `ConfigurationCatalog` service groups files into three operator-facing categories:

| Category | Files |
|---|---|
| **Default** | `serverDZ.cfg`, `BEServer.cfg`, `messages.xml`, `priority.txt`, `ban.txt`, `whitelist.txt`, `scripts.log`, `storage_1`, `storage_2`, and any `.bat`, `.cfg`, `.xml`, or `.txt` |
| **Server Messages** | `Messages.bat`, `messages.cfg`, `settings.cfg` |
| **Admin Tools** | `credentials.txt`, `SuperAdmins.txt`, `admins.xml` |

Every save is backed up to the `dayz_configuration_backups` table before the new content is written.

### API endpoints

Full reference:

> For panels that use singular server paths, replace `/servers/{server}` with `/server/{server}` and `/api/servers/{server}` with `/api/server/{server}`. Both route styles are registered by DayZ Manager.

| Method | URI | Controller action |
|---|---|---|
| GET | `/servers/{server}/dayz` | `DayZDashboardController@show` |
| GET | `/servers/{server}/dayz/mods` | `DayZWorkshopController@index` |
| GET | `/servers/{server}/dayz/configuration` | `DayZConfigurationController@index` |
| GET | `/servers/{server}/dayz/players` | `DayZPlayerController@index` |
| GET | `/servers/{server}/dayz/server` | `DayZServerController@launchParameters` |
| GET | `/api/servers/{server}/dayz/mods` | `DayZWorkshopController@index` |
| POST | `/api/servers/{server}/dayz/mods/install` | `DayZWorkshopController@install` |
| POST | `/api/servers/{server}/dayz/mods/remove` | `DayZWorkshopController@remove` |
| POST | `/api/servers/{server}/dayz/mods/update` | `DayZWorkshopController@update` |
| POST | `/api/servers/{server}/dayz/mods/enable` | `DayZWorkshopController@enable` |
| POST | `/api/servers/{server}/dayz/mods/disable` | `DayZWorkshopController@disable` |
| POST | `/api/servers/{server}/dayz/mods/reorder` | `DayZWorkshopController@reorder` |
| GET | `/api/servers/{server}/dayz/configuration` | `DayZConfigurationController@index` |
| PUT | `/api/servers/{server}/dayz/configuration` | `DayZConfigurationController@save` |
| GET | `/api/servers/{server}/dayz/players/{list_type}` | `DayZPlayerController@index` |
| POST | `/api/servers/{server}/dayz/players/{list_type}` | `DayZPlayerController@add` |
| DELETE | `/api/servers/{server}/dayz/players/{list_type}/{id}` | `DayZPlayerController@remove` |
| POST | `/api/servers/{server}/dayz/server/restart` | `DayZServerController@restart` |
| GET | `/api/servers/{server}/dayz/server/launch-parameters` | `DayZServerController@launchParameters` |

### Access control

Module pages and endpoints are registered behind the panel's `auth` middleware,
and `DayZServerContext` additionally verifies that the authenticated user is an
administrator, the server owner, or a subuser of the requested server before any
server data is rendered. Everyone else receives a `403`.

### Permissions

Assign these permission keys to panel roles as required:

| Key | Description |
|---|---|
| `dayz.mods.install` | Install Steam Workshop mods |
| `dayz.mods.remove` | Remove Steam Workshop mods |
| `dayz.mods.update` | Update Steam Workshop mods |
| `dayz.mods.manage` | Enable, disable, reorder, and bulk manage mods |
| `dayz.config.edit` | Edit DayZ configuration files |
| `dayz.config.view` | View DayZ configuration files |
| `dayz.admin.manage` | Manage DayZ administration tooling and settings |
| `dayz.players.ban` | Add and remove entries from the ban list |
| `dayz.players.whitelist` | Add and remove entries from the whitelist |
| `dayz.players.priority` | Add and remove entries from the priority queue |
| `dayz.server.restart` | Schedule a server restart |

### Database schema

The full schema is in `game-panel-mods/DayZManager/database/install.sql`. Summary:

```sql
-- Installed mod catalogue
CREATE TABLE dayz_mods (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workshop_id    VARCHAR(32)  NOT NULL,
    title          VARCHAR(255) NOT NULL,
    folder_name    VARCHAR(255) NOT NULL,
    enabled        TINYINT(1)   NOT NULL DEFAULT 1,
    version        VARCHAR(64)  NOT NULL,
    latest_version VARCHAR(64)  NOT NULL,
    dependencies   TEXT         NOT NULL,
    position       INT          NOT NULL DEFAULT 0,
    UNIQUE KEY uq_dayz_mods_workshop_id (workshop_id)
);

-- Configuration file backups
CREATE TABLE dayz_configuration_backups (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    path       VARCHAR(255) NOT NULL,
    content    MEDIUMTEXT   NOT NULL,
    created_at TIMESTAMP    NULL
);

-- Ban list, whitelist, and priority queue
CREATE TABLE dayz_player_lists (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    list_type  ENUM('ban','whitelist','priority') NOT NULL,
    player_id  VARCHAR(64)  NOT NULL COMMENT 'Steam64 ID or GUID',
    note       VARCHAR(255) NOT NULL DEFAULT '',
    added_by   VARCHAR(64)  NOT NULL DEFAULT '',
    created_at TIMESTAMP    NULL,
    UNIQUE KEY uq_dayz_player_lists_type_player (list_type, player_id)
);
```

---

## Architecture

### Included modules

| Folder | Purpose |
|---|---|
| `src/` | Framework-agnostic, strongly typed services for module discovery, lifecycle state, DayZ domain logic, and the Steam A2S query client |
| `game-panel-mods/Shared` | Shared administration metadata |
| `game-panel-mods/DayZManager` | DayZ management module: dashboard, workshop, mod reorder, player lists, server control, configuration |
| `game-panel-mods/ArkManager` | ARK manager shell |
| `game-panel-mods/RustManager` | Rust manager shell |
| `game-panel-mods/MinecraftManager` | Minecraft manager shell |

### Module contract

Every module folder must contain:

```
manifest.json
routes.php
permissions.php
controllers/
services/
views/
components/
assets/
api/
database/migrations/
```

`manifest.json` fields:

| Field | Description |
|---|---|
| `name` | Human-readable module name |
| `slug` | Unique kebab-case identifier used as the state store key |
| `version` | SemVer string |
| `author` | Author handle |
| `description` | Short description |
| `supports` | Array of game type strings (e.g. `["dayz"]`) |
| `tabs` | Array of tab labels shown in the panel UI |

### Runtime notes

- `ModuleScanner` scans `game-panel-mods/*/manifest.json`, validates all required paths, and returns sorted results.
- `ModuleLifecycleManager` applies install/enable/disable/update/uninstall transitions and persists them to `game-panel-mods/.module-state.json` via `ModuleStateStore`.
- `AdminCatalogBuilder` composes scanner results with lifecycle state for display in an admin catalogue view.

---

## Troubleshooting

**Routes return 404 after install**
Make sure `require base_path('game-panel-mods/routes-loader.php');` sits at the *top* of `routes/base.php` (or `routes/web.php`). The panel's catch-all route forwards unknown URIs to the JavaScript client, so module routes registered after it never match and every DayZ page renders the client's 404 screen.
Clear the route cache: `php artisan route:clear && php artisan route:cache`.
If your panel uses singular server paths (`/server/{server}`), ensure you are on a version of PteroMods that includes singular route aliases in addition to `/servers/{server}`.
Confirm the routes are actually registered: `php artisan route:list | grep dayz` (you should see both `/servers/{server}/dayz` and `/server/{server}/dayz` entries).
If `route:list` does not show DayZ routes, re-check Step 5 and make sure the route loader snippet was added to the correct files for your panel version (`routes/web.php` + `routes/api.php`, or `routes/base.php` + relevant API route files), then rebuild the route cache again.
If routes are present but the browser still serves a stale 404 page, restart PHP-FPM/web server after clearing caches to flush opcode/cache layers.

**Module pages are unstyled (white page, single column)**
That happens when a page is served without the module layout. Re-copy `game-panel-mods/` (or re-run `bin/install.sh`), then run `php artisan view:clear`: pages are rendered by `DayZPageRenderer`, which inlines `assets/dayz-manager.css` into `views/layout.blade.php`.

**`/dayz/mods` returns a 500 error**
Older builds included the mod card component by file path, which Blade cannot resolve. Update to this version (components are rendered through the `$component(...)` callable) and clear the view cache with `php artisan view:clear`.

**Class not found errors**
Regenerate the Composer autoloader: `composer dump-autoload --optimize`.

**PSR-4 warnings for `PteroMods\\`**
Use this exact mapping in your panel `composer.json` (note: one backslash in the JSON key, written as `\\` in the JSON file):

```json
"PteroMods\\": "pteromods-src/"
```

If a previous install created a double-backslash key (`"PteroMods\\\\"`) or pointed at `pteromods-src/src/`, fix it by re-running `bin/install.sh`, which now writes the correct single-backslash key. Alternatively, edit `composer.json` manually so the `psr-4` section matches the snippet above, then run `composer dump-autoload --optimize`.

**DayZ Manager tabs don't appear**
Confirm the server egg resolves to one of `dayz`, `dayz-dedicated`, `source-engine-dayz`, `source-engine`, or `source` (for example Nest `Source Engine` + egg `DayZ`), and that the module state in `game-panel-mods/.module-state.json` has `"enabled": true` for `dayz-manager`.
For custom eggs, also check the egg's short identifier/slug used by the panel API (not just the display name in the admin UI) and make sure it maps to one of the supported values above.
From your DayZ server page specifically, test both URL variants directly: `/servers/{server}/dayz` and `/server/{server}/dayz`; if one works and the other 404s, your panel route style and registered aliases are out of sync.

**`.module-state.json` is not writable**
Ensure the web server user has write access: `chown www-data:www-data game-panel-mods/.module-state.json`.

**Workshop URL not recognised**
`WorkshopReferenceParser` accepts a bare numeric ID (e.g. `1559212036`) or a full URL containing `?id=` or `&id=`. Any other format throws `InvalidArgumentException`.

**Player list type rejected**
`DayZPlayerService` only accepts `ban`, `whitelist`, or `priority` as the list type. Any other value throws `InvalidArgumentException`.

**SQL import errors**
The `install.sql` file targets MySQL 5.7+ / MariaDB 10.3+. If you are on an older version, remove the `COLLATE=utf8mb4_unicode_ci` clause or use `utf8_general_ci`.

---

## Validation

This repository currently has no pre-existing test framework. Changes can be validated with PHP syntax checks and by executing the shared services through Composer autoloading:

```bash
# Syntax-check all PHP files
find src game-panel-mods -name "*.php" | xargs php -l

# Dump and verify the autoloader
composer dump-autoload --optimize
```
