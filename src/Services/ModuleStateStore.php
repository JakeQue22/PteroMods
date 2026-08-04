<?php

declare(strict_types=1);

namespace PteroMods\Services;

/**
 * Persists module install and enablement state in JSON.
 */
final class ModuleStateStore
{
    public function __construct(private readonly string $stateFile)
    {
    }

    /**
     * @return array<string, array{installed: bool, enabled: bool, version: string}>
     */
    public function all(): array
    {
        if (!is_file($this->stateFile)) {
            return [];
        }

        $json = file_get_contents($this->stateFile);

        if ($json === false || trim($json) === '') {
            return [];
        }

        /** @var array<string, array{installed: bool, enabled: bool, version: string}> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    public function put(string $slug, bool $installed, bool $enabled, string $version): void
    {
        $state = $this->all();
        $state[$slug] = [
            'installed' => $installed,
            'enabled' => $enabled,
            'version' => $version,
        ];

        $directory = dirname($this->stateFile);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents(
            $this->stateFile,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    public function remove(string $slug): void
    {
        $state = $this->all();
        unset($state[$slug]);

        file_put_contents(
            $this->stateFile,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }
}
