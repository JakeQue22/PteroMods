<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use PteroMods\Services\DayZ\WorkshopDependencyPlanner;
use PteroMods\Services\DayZ\WorkshopReferenceParser;
use PteroMods\Services\DayZ\LaunchParameterBuilder;
use PteroMods\ValueObjects\DayZInstalledMod;

/**
 * Supplies installed mod cards and server-level workshop settings.
 */
final class DayZWorkshopService
{
    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $folders = array_map(
            static fn (array $mod): string => $mod['enabled'] ? $mod['folder_name'] : '',
            $this->installedMods(),
        );

        return [
            'automatic_updates' => true,
            'automatic_dependency_installation' => true,
            'auto_restart' => true,
            'steamcmd_path' => '/usr/games/steamcmd',
            'workshop_download_path' => '/home/container/steamapps/workshop/content/221100',
            'mod_cache' => '/home/container/.cache/dayz-mods',
            'cleanup_old_versions' => true,
            'launch_parameters' => (new LaunchParameterBuilder())->build(array_values(array_filter($folders))),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function installedMods(): array
    {
        return array_map(
            static fn (DayZInstalledMod $mod): array => $mod->toArray(),
            [
            new DayZInstalledMod(
                workshopId: '1559212036',
                title: 'CF',
                folderName: '@CF',
                author: 'Arkensor',
                thumbnail: 'https://steamuserimages-a.akamaihd.net/ugc/placeholder-cf.jpg',
                currentVersion: '1.0.0',
                latestVersion: '1.0.0',
                fileSize: '512 MB',
                enabled: true,
                dependencies: [],
            ),
            new DayZInstalledMod(
                workshopId: '2545327648',
                title: 'VPPAdminTools',
                folderName: '@VPPAdminTools',
                author: 'VanillaPlusPlus',
                thumbnail: 'https://steamuserimages-a.akamaihd.net/ugc/placeholder-vpp.jpg',
                currentVersion: '3.8.2',
                latestVersion: '3.8.4',
                fileSize: '243 MB',
                enabled: true,
                dependencies: ['1559212036'],
            ),
            new DayZInstalledMod(
                workshopId: '1564026768',
                title: 'Community Online Tools',
                folderName: '@Community-Online-Tools',
                author: 'drgullen',
                thumbnail: 'https://steamuserimages-a.akamaihd.net/ugc/placeholder-cot.jpg',
                currentVersion: '1.4.0',
                latestVersion: '1.4.0',
                fileSize: '188 MB',
                enabled: false,
                dependencies: ['1559212036'],
            ),
            ],
        );
    }

    /**
     * @param array<string, array{dependencies?: list<string>, requires_cf?: bool}> $metadata
     * @return array<string, mixed>
     */
    public function installPlan(string $reference, array $metadata = []): array
    {
        $workshopId = (new WorkshopReferenceParser())->parse($reference);
        $plan = (new WorkshopDependencyPlanner())->buildPlan($workshopId, $metadata + [
            $workshopId => $metadata[$workshopId] ?? ['dependencies' => [], 'requires_cf' => true],
        ]);

        return [
            'workshop_id' => $workshopId,
            'install_order' => $plan,
            'restart_after_update' => true,
            'auto_dependency_installation' => true,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function update(string $workshopId): array
    {
        return ['status' => 'queued', 'action' => 'update', 'workshop_id' => $workshopId];
    }

    /**
     * @return array<string, string>
     */
    public function remove(string $workshopId): array
    {
        return ['status' => 'queued', 'action' => 'remove', 'workshop_id' => $workshopId];
    }

    /**
     * @return array<string, string>
     */
    public function toggle(string $workshopId, bool $enabled): array
    {
        return [
            'status' => 'queued',
            'action' => $enabled ? 'enable' : 'disable',
            'workshop_id' => $workshopId,
        ];
    }
}
