<?php

declare(strict_types=1);

namespace PteroMods\Services;

use PteroMods\ValueObjects\ModuleManifest;

/**
 * Applies install, enable, disable, update, and uninstall transitions.
 */
final class ModuleLifecycleManager
{
    public function __construct(private readonly ModuleStateStore $stateStore)
    {
    }

    /**
     * @return array{installed: bool, enabled: bool, version: string}
     */
    public function status(ModuleManifest $manifest): array
    {
        $all = $this->stateStore->all();

        return $all[$manifest->slug] ?? [
            'installed' => false,
            'enabled' => false,
            'version' => $manifest->version,
        ];
    }

    public function install(ModuleManifest $manifest): void
    {
        $this->stateStore->put($manifest->slug, true, false, $manifest->version);
    }

    public function enable(ModuleManifest $manifest): void
    {
        $this->stateStore->put($manifest->slug, true, true, $manifest->version);
    }

    public function disable(ModuleManifest $manifest): void
    {
        $this->stateStore->put($manifest->slug, true, false, $manifest->version);
    }

    public function update(ModuleManifest $manifest, string $version): void
    {
        $status = $this->status($manifest);
        $this->stateStore->put($manifest->slug, $status['installed'], $status['enabled'], $version);
    }

    public function uninstall(ModuleManifest $manifest): void
    {
        $this->stateStore->remove($manifest->slug);
    }
}
