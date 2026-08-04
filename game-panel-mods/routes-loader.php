<?php

declare(strict_types=1);

/**
 * PteroMods route loader.
 *
 * Include this file from the panel's web route file (`routes/base.php` on
 * current Pterodactyl versions, `routes/web.php` on older ones) as early as
 * possible: the panel registers a catch-all route that forwards every unknown
 * `/server/...` URI to the JavaScript client, which renders a "not found" page.
 * Module routes must therefore be registered *before* that catch-all.
 *
 *     require base_path('game-panel-mods/routes-loader.php');
 *
 * Both the page routes (`routes.php`) and the JSON endpoints (`api/routes.php`)
 * of every enabled module are registered here, behind the panel's `auth`
 * middleware so module pages are never reachable by anonymous visitors.
 */

use Illuminate\Support\Facades\Route;

(static function (): void {
    $modulesPath = __DIR__;

    $enabled = static function (string $moduleDirectory) use ($modulesPath): bool {
        $stateFile = $modulesPath . '/.module-state.json';
        $manifestFile = $moduleDirectory . '/manifest.json';

        if (!is_file($manifestFile) || !is_file($stateFile)) {
            return is_file($manifestFile);
        }

        $manifest = json_decode((string) file_get_contents($manifestFile), true);
        $state = json_decode((string) file_get_contents($stateFile), true);

        if (!is_array($manifest) || !is_array($state)) {
            return true;
        }

        $slug = is_string($manifest['slug'] ?? null) ? $manifest['slug'] : '';

        if ($slug === '' || !array_key_exists($slug, $state)) {
            return true;
        }

        return (bool) ($state[$slug]['enabled'] ?? true);
    };

    $register = static function (string $file): void {
        $routes = require $file;

        if (!is_array($routes)) {
            return;
        }

        foreach ($routes as $route) {
            if (!is_array($route) || !isset($route['method'], $route['uri'], $route['action'])) {
                continue;
            }

            $method = strtolower((string) $route['method']);

            if (!in_array($method, ['get', 'post', 'put', 'patch', 'delete', 'options'], true)) {
                continue;
            }

            Route::{$method}('/' . ltrim((string) $route['uri'], '/'), $route['action'])
                ->middleware(['auth']);
        }
    };

    foreach (glob($modulesPath . '/*', GLOB_ONLYDIR) ?: [] as $moduleDirectory) {
        if (!$enabled($moduleDirectory)) {
            continue;
        }

        foreach ([$moduleDirectory . '/routes.php', $moduleDirectory . '/api/routes.php'] as $routeFile) {
            if (is_file($routeFile)) {
                $register($routeFile);
            }
        }
    }
})();
