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
- **DayZ Manager** – dashboard, Steam Workshop mod tooling, dependency-aware install planning, launch parameter generation, and configuration file editing from the panel.
- **Workshop dependency planner** – automatically resolves mod load order and injects CommunityFramework (CF, ID `1559212036`) when a mod requires it.
- **Launch parameter builder** – generates the correct `-mod=` string from your ordered, enabled mod list.
- **Configuration catalogue** – groups DayZ config files (`serverDZ.cfg`, `BEServer.cfg`, admin files, message files, etc.) into operator-facing categories.
- **Configuration backups** – the database migration creates a `dayz_configuration_backups` table so every save is versioned.
- **Extensible** – `ArkManager`, `RustManager`, and `MinecraftManager` module shells are included and follow the same installable contract.

---

## Installing on an existing Pterodactyl panel

These steps assume your panel is already running at `/var/www/pterodactyl`. Adjust the path if your installation differs.

> **Tip:** Run all commands as the user that owns the panel files (often `www-data` or a dedicated `pterodactyl` user). Prefix with `sudo -u www-data` if needed.

### 1. Download or clone PteroMods

```bash
cd /var/www/pterodactyl
git clone https://github.com/JakeQue22/PteroMods.git game-panel-mods-src
```

Or download and extract a release archive instead.

### 2. Copy the module files into the panel

Create a `game-panel-mods` directory inside your panel root and copy the modules into it:

```bash
mkdir -p /var/www/pterodactyl/game-panel-mods
cp -r game-panel-mods-src/game-panel-mods/* /var/www/pterodactyl/game-panel-mods/
cp -r game-panel-mods-src/src /var/www/pterodactyl/pteromods-src
```

### 3. Register the autoloader

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
composer dump-autoload --optimize
```

### 4. Run the database migrations

PteroMods ships plain SQL migration files instead of Laravel Migration classes. Execute the `up` statements for each module you want to install. For DayZ Manager:

```bash
php artisan tinker
```

Inside Tinker, paste and run the two statements from `game-panel-mods/DayZManager/database/migrations/2026_08_02_000001_create_dayz_manager_tables.php`:

```sql
CREATE TABLE IF NOT EXISTS dayz_mods (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workshop_id VARCHAR(32) NOT NULL,
    title VARCHAR(255) NOT NULL,
    folder_name VARCHAR(255) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    version VARCHAR(64) NOT NULL,
    latest_version VARCHAR(64) NOT NULL,
    dependencies TEXT NOT NULL,
    position INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS dayz_configuration_backups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    path VARCHAR(255) NOT NULL,
    content MEDIUMTEXT NOT NULL,
    created_at TIMESTAMP NULL
);
```

> For MySQL/MariaDB panels replace `INTEGER PRIMARY KEY AUTOINCREMENT` with `INT UNSIGNED AUTO_INCREMENT PRIMARY KEY` and wrap each statement in `DB::statement(...)` or run them via your database client directly.

### 5. Register module routes

Add the PteroMods routes to your panel's route loading bootstrap. A simple approach is to append an include to `routes/web.php` and `routes/api.php`:

```php
// routes/web.php – add near the bottom
foreach (glob(base_path('game-panel-mods/*/routes.php')) as $moduleRoutes) {
    $routes = require $moduleRoutes;
    foreach ($routes as $r) {
        Route::{strtolower($r['method'])}($r['uri'], $r['action']);
    }
}
```

```php
// routes/api.php – add near the bottom
foreach (glob(base_path('game-panel-mods/*/api/routes.php')) as $moduleApiRoutes) {
    $routes = require $moduleApiRoutes;
    foreach ($routes as $r) {
        Route::{strtolower($r['method'])}($r['uri'], $r['action']);
    }
}
```

Then clear the route cache:

```bash
php artisan route:clear
php artisan route:cache
```

### 6. Mark the module as installed and enabled

Use `ModuleLifecycleManager` or edit `game-panel-mods/.module-state.json` directly:

```json
{
    "dayz-manager": {
        "installed": true,
        "enabled": true,
        "version": "1.0.0"
    }
}
```

### 7. Clear caches and set permissions

```bash
php artisan config:clear
php artisan view:clear
php artisan cache:clear
chown -R www-data:www-data /var/www/pterodactyl/game-panel-mods
```

The DayZ Manager tabs will now be available to any server whose egg supports the `dayz` game type.

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

### Dashboard

URL: `GET /servers/{server}/dayz`

Displays a card grid with: server name, current map, server version, installed mod count, player count, CPU, RAM, disk usage, and server status.

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

| Method | URI | Controller action |
|---|---|---|
| GET | `/servers/{server}/dayz` | `DayZDashboardController@show` |
| GET | `/servers/{server}/dayz/mods` | `DayZWorkshopController@index` |
| GET | `/servers/{server}/dayz/configuration` | `DayZConfigurationController@index` |
| GET | `/api/servers/{server}/dayz/mods` | `DayZWorkshopController@index` |
| POST | `/api/servers/{server}/dayz/mods/install` | `DayZWorkshopController@install` |
| POST | `/api/servers/{server}/dayz/mods/remove` | `DayZWorkshopController@remove` |
| POST | `/api/servers/{server}/dayz/mods/update` | `DayZWorkshopController@update` |
| POST | `/api/servers/{server}/dayz/mods/enable` | `DayZWorkshopController@enable` |
| POST | `/api/servers/{server}/dayz/mods/disable` | `DayZWorkshopController@disable` |
| GET | `/api/servers/{server}/dayz/configuration` | `DayZConfigurationController@index` |
| PUT | `/api/servers/{server}/dayz/configuration` | `DayZConfigurationController@save` |

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

### Database schema

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
    position       INT          NOT NULL DEFAULT 0
);

-- Configuration file backups
CREATE TABLE dayz_configuration_backups (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    path       VARCHAR(255) NOT NULL,
    content    MEDIUMTEXT   NOT NULL,
    created_at TIMESTAMP    NULL
);
```

---

## Architecture

### Included modules

| Folder | Purpose |
|---|---|
| `src/` | Framework-agnostic, strongly typed services for module discovery, lifecycle state, and DayZ domain logic |
| `game-panel-mods/Shared` | Shared administration metadata |
| `game-panel-mods/DayZManager` | DayZ management module: dashboard, workshop, configuration |
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
Clear the route cache: `php artisan route:clear && php artisan route:cache`.

**Class not found errors**
Regenerate the Composer autoloader: `composer dump-autoload --optimize`.

**DayZ Manager tabs don't appear**
Confirm the server egg includes `dayz` in its supported games and that the module state in `game-panel-mods/.module-state.json` has `"enabled": true` for `dayz-manager`.

**Migration errors on MySQL**
The migration file uses SQLite syntax (`AUTOINCREMENT`). For MySQL replace `INTEGER PRIMARY KEY AUTOINCREMENT` with `INT UNSIGNED AUTO_INCREMENT PRIMARY KEY` when running the statements.

**`.module-state.json` is not writable**
Ensure the web server user has write access: `chown www-data:www-data game-panel-mods/.module-state.json`.

**Workshop URL not recognised**
`WorkshopReferenceParser` accepts a bare numeric ID (e.g. `1559212036`) or a full URL containing `?id=` or `&id=`. Any other format throws `InvalidArgumentException`.

---

## Validation

This repository currently has no pre-existing test framework. Changes can be validated with PHP syntax checks and by executing the shared services through Composer autoloading:

```bash
# Syntax-check all PHP files
find src game-panel-mods -name "*.php" | xargs php -l

# Dump and verify the autoloader
composer dump-autoload --optimize
```
