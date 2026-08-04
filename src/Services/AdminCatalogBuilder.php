<?php

declare(strict_types=1);

namespace PteroMods\Services;

/**
 * Builds admin-facing module catalog payloads.
 */
final class AdminCatalogBuilder
{
    public function __construct(
        private readonly ModuleScanner $scanner,
        private readonly ModuleLifecycleManager $lifecycleManager,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function build(string $modulesPath): array
    {
        $catalog = [];

        foreach ($this->scanner->scan($modulesPath) as $entry) {
            $manifest = $entry['manifest'];
            $status = $this->lifecycleManager->status($manifest);

            $catalog[] = [
                'name' => $manifest->name,
                'slug' => $manifest->slug,
                'version' => $status['version'],
                'author' => $manifest->author,
                'description' => $manifest->description,
                'supports' => $manifest->supports,
                'tabs' => $manifest->tabs,
                'installed' => $status['installed'],
                'enabled' => $status['enabled'],
                'valid' => $entry['valid'],
                'missing' => $entry['missing'],
            ];
        }

        return $catalog;
    }
}
