<?php

declare(strict_types=1);

namespace PteroMods\Services;

use PteroMods\ValueObjects\ModuleManifest;

/**
 * Scans a module directory and validates required files and folders.
 */
final class ModuleScanner
{
    private const REQUIRED_PATHS = [
        'manifest.json',
        'routes.php',
        'controllers',
        'services',
        'views',
        'components',
        'assets',
        'api',
        'permissions.php',
        'database/migrations',
    ];

    public function __construct(private readonly ManifestLoader $loader = new ManifestLoader())
    {
    }

    /**
     * @return list<array{manifest: ModuleManifest, valid: bool, missing: list<string>}>
     */
    public function scan(string $modulesPath): array
    {
        $results = [];
        $paths = glob(rtrim($modulesPath, '/'). '/*/manifest.json') ?: [];

        foreach ($paths as $manifestPath) {
            $manifest = $this->loader->load($manifestPath);
            $missing = $this->missingPaths($manifest->path);
            $results[] = [
                'manifest' => $manifest,
                'valid' => $missing === [],
                'missing' => $missing,
            ];
        }

        usort(
            $results,
            static fn (array $left, array $right): int => strcmp($left['manifest']->name, $right['manifest']->name),
        );

        return $results;
    }

    /**
     * @return list<string>
     */
    private function missingPaths(string $modulePath): array
    {
        $missing = [];

        foreach (self::REQUIRED_PATHS as $path) {
            if (!file_exists($modulePath . '/' . $path)) {
                $missing[] = $path;
            }
        }

        return $missing;
    }
}
