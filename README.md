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
   - [Navigation tab](#navigation-tab)
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
- **DayZ Manager** – dashboard, Steam Workshop mod tooling, dependency-aware install planning, mod reordering, player list management, power controls, and configuration file editing from the panel.
- **Live data from Pterodactyl** – installed mods, power state, resource usage, launch parameters, and configuration files are read from the panel database and the Wings daemon, never from sample data.
- **Navigation tab** – a "DayZ Manager" entry is injected into the client server navigation (`/server/{id}`) and the admin server tabs (`/admin/servers/view/{id}`) of DayZ servers.
- **Workshop dependency planner** – automatically resolves mod load order and injects CommunityFramework (CF, ID `1559212036`) when a mod requires it.
- **Launch parameter builder** – generates the correct `-mod=` string from your ordered, enabled mod list; preview endpoint keeps operators informed.
- **Mod reorder** – drag-and-drop position management persists to `dayz_mods.position` and immediately rebuilds the `-mod=` launch string.
- **Player list management** – ban, whitelist, and priority queue management by Steam64 ID or GUID, backed by the `dayz_player_lists` database table.
- **Power controls** – start, restart, stop, and kill the server through the Pterodactyl daemon, with an optional reason.
- **Configuration catalogue** – lists the configuration files that actually exist on the server (server root, `config/`, mission folders, BattlEye, profiles) and deep-links each one to the panel file editor, with the syntax mode matching its extension.
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

#### 5b. Register the navigation tab script

To show a **DayZ Manager** entry in the panel navigation, load the module's small
tab script from both panel layouts. Add this line immediately before `</body>`:

- `resources/views/templates/wrapper.blade.php` (client area)
- `resources/views/layouts/admin.blade.php` (admin area)

```html
<script src="/game-panel-mods/dayz-manager/tab.js"></script>
```

`bin/install.sh` performs this patch automatically (step 5b) and skips it when
the line is already present.

#### 6. Mark the module as installed and enabled

Edit `game-panel-mods/.module-state.json`:

```json
{
    "dayz-manager": {
        "installed": true,
        "enabled": true,
        "version": "1.3.0"
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

**Live values.** The dashboard combines three sources:

| Source | Values |
|---|---|
| Panel database | server name, CPU/RAM/disk limits, installation and suspension state |
| Wings daemon (`DayZPanelGateway`) | power state (`running`, `starting`, `stopping`, `offline`), CPU/RAM/disk usage, uptime |
| Steam query (`SourceQueryClient`) | current map, player count, server version |

**Server status** is taken from the daemon first, because `servers.status` in the
panel database only describes installation/transfer states and is `null` for a
healthy server. The Steam query result is used only when the daemon cannot be
reached, so an online server is never reported as offline just because its query
port is firewalled. The card footnote names the source that was used.

**Addressing.** The query host and the advertised connect address are resolved by
`HostAddressResolver`, in this order:

1. the allocation's public alias (`ip_alias`);
2. the node FQDN from Pterodactyl;
3. the raw allocation IP.

Private, loopback, and wildcard addresses (for example a node's internal
`10.2.2.105`) are only used when nothing routable is configured, so the module
keeps working when the panel and the node run on different machines. The query
port is resolved most-authoritative first: the `steamQueryPort` set in the
server's own `serverDZ.cfg` (the actual, operator-controlled Pterodactyl
setting), then a `STEAM_QUERY_PORT`/`QUERY_PORT` egg variable, then an
allocation matching the standard DayZ query port, then the game port plus
DayZ's real default offset (`2302` → `2305`, i.e. `+3`), then the flat `27016`
Steam default. Results are cached for 15 seconds, and mission names such as
`dayzOffline.chernarusplus` are shown as friendly map names (`Chernarus+`).
Player counts default to `0 / 64` (instead of `N/A`) whenever the query cannot
be answered, so the dashboard and Server pages always show a slot count.

**Rendering.** Module pages are rendered by `DayZPageRenderer` into a
self-contained, panel-themed HTML document with the module stylesheet inlined,
so they display correctly regardless of which assets the panel front end
exposes. Every page shares the same tab navigation and a link back to the
server.

### Workshop mod management

URL: `GET /servers/{server}/dayz/mods`

**Mods are discovered from the server itself.** `DayZWorkshopService` lists the
server root through the Pterodactyl daemon, treats every `@Folder` as a mod, and
reads the Workshop ID, title, author, and version from that folder's `meta.cpp`
and `mod.cpp`. The load order and the enabled flag come from the `-mod=` (and
`-serverMod=`) launch parameter Pterodactyl boots the server with, so the page
mirrors the real installation. Mods listed in the startup command but missing on
disk are shown as *Missing*. If the daemon cannot be reached, the `dayz_mods`
table is used as a fallback.

**Enable, disable, reorder, and remove write back to Pterodactyl.** The load
order is stored where the panel reads it from: when the startup command uses a
placeholder for `-mod=` (for example `{{MOD_LIST}}`), the matching server
variable is updated; otherwise the `-mod=` value inside the server's startup
command itself is rewritten. Removing a mod also deletes its `@Folder` through
the daemon. Changes apply on the next server restart, which the page states after
each action. Installing or updating a mod downloads files with SteamCMD, which is
the egg's responsibility, so those actions return the required steps instead of
silently doing nothing.

**Installing queues a download and persists it.** Queued Workshop installs are
written to the `dayz_mod_install_queue` table (keyed by server and Workshop ID),
so the "downloading…" status shown on the page survives a reload instead of
disappearing; `GET /mods/install/queue` restores it. A `say` console command is
also sent to the server so operators watching the live console see the request.
Installing or updating a mod still downloads files with SteamCMD, which is the
egg's responsibility — the module tracks progress by watching for the mod's
folder to appear on disk, it does not run SteamCMD itself.

**Browse and search the Workshop.** The mods page has a "Browse Workshop" button
that lists DayZ Workshop items with thumbnails (`GET /mods/browse`), and the
install box shows a live thumbnail+name preview dropdown as a Workshop ID or URL
is typed (`GET /mods/lookup`). Browsing uses Steam's
`IPublishedFileService/QueryFiles`, which requires a Steam Web API key; set the
`STEAM_WEB_API_KEY` environment variable on the panel to enable it (the single-item
lookup used for the preview dropdown needs no key).

Only administrators and the server owner may change the load order, the startup
command, or configuration files; other subusers keep read-only access.

| Action | Method | Endpoint |
|---|---|---|
| List installed mods | GET | `/servers/{server}/dayz/mods` |
| Install mod | POST | `/api/servers/{server}/dayz/mods/install` |
| Install progress | GET | `/api/servers/{server}/dayz/mods/install/status` |
| Persisted install queue | GET | `/api/servers/{server}/dayz/mods/install/queue` |
| Workshop ID/URL preview | GET | `/api/servers/{server}/dayz/mods/lookup` |
| Browse Workshop | GET | `/api/servers/{server}/dayz/mods/browse` |
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

Shows the launch parameters Pterodactyl actually starts the server with and
provides power controls.

**Launch parameters** are loaded from the panel: `DayZStartupService` reads the
startup command stored on the server (falling back to the egg's command), merges
the egg variable defaults with the values configured for the server, and
`StartupCommandRenderer` substitutes every `{{VARIABLE}}` placeholder. The page
lists the rendered command, its individual parameters, the resolved variables,
and the detected client/server mod lists.

| Action | Method | Endpoint |
|---|---|---|
| Get launch parameters | GET | `/api/servers/{server}/dayz/server/launch-parameters` |
| Restart server | POST | `/api/servers/{server}/dayz/server/restart` |
| Send a power signal | POST | `/api/servers/{server}/dayz/server/power` |

**Restart payload** (optional):

```json
{
    "reason": "Mod update applied"
}
```

**Power payload** — `start`, `stop`, `restart`, or `kill`:

```json
{
    "signal": "restart"
}
```

Power signals are forwarded to the Wings daemon, so they behave exactly like the
console buttons in the panel.

### Configuration editor

URL: `GET /servers/{server}/dayz/configuration`

| Action | Method | Endpoint |
|---|---|---|
| List config files | GET | `/api/servers/{server}/dayz/configuration` |
| Save config file | PUT | `/api/servers/{server}/dayz/configuration` |

Configuration files are discovered on the server through the Pterodactyl daemon.
The server root, `config/`, `profiles/`, `battleye/`, and every mission folder in
`mpmissions/` (including its `db/` directory) are scanned, and each file is
listed with its real path, size, and a deep link into the panel file manager:

| File type | Opens in |
|---|---|
| `.cfg`, `.xml`, `.json`, `.ini`, `.conf`, `.c`, `.bat`, `.sh` | panel code editor with matching syntax highlighting |
| `.txt`, `.log`, `.md` | panel text editor |
| anything else | file browser (download) |

The `ConfigurationCatalog` service groups files into three operator-facing categories:

| Category | Files |
|---|---|
| **Default** | `serverDZ.cfg`, `BEServer.cfg`, `messages.xml`, `priority.txt`, `ban.txt`, `whitelist.txt`, `scripts.log`, `storage_1`, `storage_2`, and any `.bat`, `.cfg`, `.xml`, or `.txt` |
| **Server Messages** | `Messages.bat`, `messages.cfg`, `settings.cfg` |
| **Admin Tools** | `credentials.txt`, `SuperAdmins.txt`, `admins.xml` |

Saving through the API writes the file back to the container through the daemon,
and the previous contents are stored in `dayz_configuration_backups` first.

### Navigation tab

The script served at `/game-panel-mods/dayz-manager/tab.js` adds a **DayZ
Manager** entry to the panel navigation:

| Area | Where the link appears | Target |
|---|---|---|
| Client | server sub-navigation on `/server/{id}` | `/server/{id}/dayz` |
| Admin | server tabs on `/admin/servers/view/{id}` | `/admin/servers/view/{id}/dayz` |

The script asks `/api/server/{id}/dayz/tab` whether the server is a DayZ server
(`DayZEggDetector` inspects the egg name, docker image, and startup command)
and only injects the link when it is. Existing navigation entries are cloned, so
the link always matches the active panel theme, and it is re-injected whenever
the client application re-renders its navigation.

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
| POST | `/api/servers/{server}/dayz/server/power` | `DayZServerController@power` |
| GET | `/api/servers/{server}/dayz/server/launch-parameters` | `DayZServerController@launchParameters` |
| GET | `/api/servers/{server}/dayz/dashboard` | `DayZDashboardController@show` |
| GET | `/api/servers/{server}/dayz/tab` | `DayZTabController@status` |
| GET | `/game-panel-mods/dayz-manager/tab.js` | `DayZTabController@script` |

Every page route is also registered under `/admin/servers/view/{server}/dayz…`
so the module can be opened from the admin area with the numeric server id.

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
- `DayZPanelGateway` talks to Wings for power state, resource usage, and container files. It prefers the panel's own `DaemonServerRepository`/`DaemonFileRepository`, and falls back to a direct daemon call using the node token. Every call degrades gracefully (`null`/`[]`) so pages still render when a node is unreachable; details are cached for 5 seconds and file listings for 30 seconds.
- `DayZStartupService` resolves the real startup command and egg variables; `StartupCommandRenderer` substitutes `{{VARIABLE}}` placeholders and `ModMetaParser` extracts mod folders from `-mod=`/`-serverMod=` and mod metadata from `meta.cpp`/`mod.cpp`.
- `HostAddressResolver` keeps private node addresses out of query endpoints and connect strings.

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

**Query Status shows Offline even though the server is running**
Older builds guessed the Steam query port as `game port + 24714`, an offset that
does not apply to DayZ and never matches a real server. The query port is now
read from `steamQueryPort` in the server's own `serverDZ.cfg` first (the
setting an operator actually controls), then an egg variable, then extra
allocations, then DayZ's real default offset (`+3`). If the status is still
offline, confirm the query port is open on the node's firewall and matches what
`serverDZ.cfg` declares.

**PSR-4 warnings for `PteroMods\\`**
Use this exact mapping in your panel `composer.json` (note: one backslash in the JSON key, written as `\\` in the JSON file):

```json
"PteroMods\\": "pteromods-src/"
```

If a previous install created a double-backslash key (`"PteroMods\\\\"`) or pointed at `pteromods-src/src/`, fix it by re-running `bin/install.sh`, which now writes the correct single-backslash key. Alternatively, edit `composer.json` manually so the `psr-4` section matches the snippet above, then run `composer dump-autoload --optimize`.

**DayZ Manager tabs don't appear**
Confirm the tab script is loaded from both panel layouts (see step 5b) and that
`/game-panel-mods/dayz-manager/tab.js` returns JavaScript when opened in the
browser. The link only appears when `/api/server/{id}/dayz/tab` reports
`"supported": true`, which requires the egg name, docker image, or startup command
to mention DayZ.
Confirm the server egg resolves to one of `dayz`, `dayz-dedicated`, `source-engine-dayz`, `source-engine`, or `source` (for example Nest `Source Engine` + egg `DayZ`), and that the module state in `game-panel-mods/.module-state.json` has `"enabled": true` for `dayz-manager`.
For custom eggs, also check the egg's short identifier/slug used by the panel API (not just the display name in the admin UI) and make sure it maps to one of the supported values above.
From your DayZ server page specifically, test both URL variants directly: `/servers/{server}/dayz` and `/server/{server}/dayz`; if one works and the other 404s, your panel route style and registered aliases are out of sync.

**Server status shows "offline" while the server is running**
The status card prefers the daemon state, so this means the panel could not reach
Wings. Check the node status in the admin area, and confirm the panel can call the
daemon (`https://{node-fqdn}:8080`). If the daemon is unreachable the module falls
back to a Steam query, which also fails when the query port is firewalled.

**Query endpoint shows an internal IP (for example `10.2.2.105:27032`)**
Set a public **IP alias** on the server's allocation, or a publicly resolvable
**FQDN** on the node, in the panel admin area. The module only falls back to the
raw allocation IP when neither is routable.

**No mods are listed although mods are installed**
Mods are read from the container, so the daemon must be reachable. Verify the mod
folders exist in the server root (they must start with `@`), and that the startup
command contains the `-mod=` parameter that defines the load order.

**No configuration files are listed**
Same cause: the file listing comes from the daemon. When it is unreachable the
page falls back to the standard DayZ file names, which are marked *not found*.

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
