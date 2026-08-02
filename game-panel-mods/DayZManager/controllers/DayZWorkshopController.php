<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Controllers;

use GamePanelMods\DayZManager\Services\DayZWorkshopService;

/**
 * Produces Workshop management payloads for DayZ mods.
 */
final class DayZWorkshopController
{
    public function __construct(private readonly DayZWorkshopService $service = new DayZWorkshopService())
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function index(): array
    {
        return [
            'settings' => $this->service->settings(),
            'installed_mods' => $this->service->installedMods(),
        ];
    }

    /**
     * @param array<string, array{dependencies?: list<string>, requires_cf?: bool}> $metadata
     * @return array<string, mixed>
     */
    public function install(string $reference, array $metadata = []): array
    {
        return $this->service->installPlan($reference, $metadata);
    }

    /**
     * @return array<string, string>
     */
    public function update(string $workshopId): array
    {
        return $this->service->update($workshopId);
    }

    /**
     * @return array<string, string>
     */
    public function remove(string $workshopId): array
    {
        return $this->service->remove($workshopId);
    }

    /**
     * @return array<string, string>
     */
    public function enable(string $workshopId): array
    {
        return $this->service->toggle($workshopId, true);
    }

    /**
     * @return array<string, string>
     */
    public function disable(string $workshopId): array
    {
        return $this->service->toggle($workshopId, false);
    }

    /**
     * @param list<string> $orderedWorkshopIds  Workshop IDs in the desired load order.
     * @return array<string, mixed>
     */
    public function reorder(array $orderedWorkshopIds): array
    {
        return $this->service->reorder($orderedWorkshopIds);
    }
}
