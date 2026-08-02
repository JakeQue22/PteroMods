<?php

declare(strict_types=1);

namespace GamePanelMods\Shared\Services;

use PteroMods\Services\AdminCatalogBuilder;
use PteroMods\Services\ModuleLifecycleManager;
use PteroMods\Services\ModuleScanner;
use PteroMods\Services\ModuleStateStore;

/**
 * Adapts shared framework services for the Shared module.
 */
final class SharedModuleService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function catalog(): array
    {
        $modulesPath = dirname(__DIR__, 2);
        $builder = new AdminCatalogBuilder(
            new ModuleScanner(),
            new ModuleLifecycleManager(new ModuleStateStore($modulesPath . '/.module-state.json')),
        );

        return $builder->build($modulesPath);
    }
}
