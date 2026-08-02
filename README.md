# PteroMods

PteroMods is a modular "Game Panel Mods" framework scaffold for Pterodactyl-style panels.

## Included architecture

- `src/` contains framework-agnostic, strongly typed services for module discovery, lifecycle state, and DayZ domain logic.
- `game-panel-mods/` contains installable module folders that can be scanned independently.
- `game-panel-mods/Shared` provides shared administration metadata.
- `game-panel-mods/DayZManager` provides a DayZ-focused management module with dashboard, workshop, and configuration endpoints.
- `game-panel-mods/ArkManager`, `RustManager`, and `MinecraftManager` provide manager module shells with the same installable contract.

## Module contract

Every module folder contains:

- `manifest.json`
- `routes.php`
- `controllers/`
- `services/`
- `views/`
- `components/`
- `assets/`
- `api/`
- `permissions.php`
- `database/migrations/`

## Runtime notes

The shared services scan `game-panel-mods/*/manifest.json`, validate the expected module structure, and manage install/enable/disable state in `game-panel-mods/.module-state.json`.

The DayZ services include unit-testable logic for:

- Workshop URL and ID parsing
- Dependency-aware install planning with optional CF bootstrapping
- Launch parameter generation for `-mod=` ordering
- Configuration file categorisation

## Validation

This repository currently has no pre-existing test framework. Changes can be validated with PHP syntax checks and by executing the shared services through Composer autoloading.
