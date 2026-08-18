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

            $registered = Route::{$method}('/' . ltrim((string) $route['uri'], '/'), $route['action']);
            $middleware = !empty($route['signed_public']) ? [] : ['auth'];

            if ($middleware !== []) {
                $registered->middleware($middleware);
            }
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

        // Register Artisan console commands when running in CLI context.
        if (PHP_SAPI === 'cli' && is_dir($moduleDirectory . '/console')) {
            foreach (glob($moduleDirectory . '/console/*Command.php') ?: [] as $commandFile) {
                $className = null;
                $contents = (string) file_get_contents($commandFile);

                if (preg_match('/namespace\s+([\w\\\\]+)/', $contents, $nsMatch)
                    && preg_match('/class\s+(\w+)/', $contents, $clsMatch)) {
                    $className = $nsMatch[1] . '\\' . $clsMatch[1];
                }

                if ($className !== null && class_exists($className)) {
                    try {
                        \Illuminate\Support\Facades\Artisan::starting(function ($artisan) use ($className) {
                            $artisan->resolve($className);
                        });
                    } catch (\Throwable) {
                        // Best-effort command registration.
                    }
                }
            }
        }
    }
})();
