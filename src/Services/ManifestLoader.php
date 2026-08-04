<?php

declare(strict_types=1);

namespace PteroMods\Services;

use PteroMods\ValueObjects\ModuleManifest;
use RuntimeException;

/**
 * Loads module manifest JSON files into typed value objects.
 */
final class ManifestLoader
{
    public function load(string $manifestPath): ModuleManifest
    {
        $json = @file_get_contents($manifestPath);

        if ($json === false) {
            throw new RuntimeException(sprintf('Unable to read manifest: %s', $manifestPath));
        }

        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return ModuleManifest::fromArray($payload, dirname($manifestPath));
    }
}
