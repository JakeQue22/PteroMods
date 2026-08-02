<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Controllers;

use GamePanelMods\DayZManager\Services\DayZPageRenderer;
use GamePanelMods\DayZManager\Services\DayZServerContext;
use GamePanelMods\DayZManager\Services\DayZWorkshopService;
use Throwable;

/**
 * Produces Workshop management payloads for DayZ mods.
 */
final class DayZWorkshopController
{
    public function __construct(
        private readonly DayZWorkshopService $service = new DayZWorkshopService(),
        private readonly DayZPageRenderer $renderer = new DayZPageRenderer(),
        private readonly DayZServerContext $context = new DayZServerContext(),
    ) {
    }

    /**
     * @return mixed
     */
    public function index(mixed $server = null)
    {
        $resolved = $this->context->resolve($server);

        try {
            $data = [
                'settings'       => $this->service->settings($resolved['model']),
                'installed_mods' => $this->service->installedMods($resolved['model']),
            ];
        } catch (Throwable $exception) {
            return $this->renderer->renderError($exception->getMessage(), 'mods', $resolved['id'], $resolved['name']);
        }

        if ($this->context->expectsJson()) {
            return ['server_id' => $resolved['id']] + $data;
        }

        return $this->renderer->render('mods', $data, 'mods', $resolved['id'], $resolved['name']);
    }

    /**
     * Queues a Workshop install. The Workshop reference is read from the request
     * body when it is not supplied explicitly.
     *
     * @param array<string, array{dependencies?: list<string>, requires_cf?: bool}> $metadata
     * @return array<string, mixed>
     */
    public function install(mixed $server = null, string $reference = '', array $metadata = []): array
    {
        $reference = $reference !== '' ? $reference : $this->context->stringInput('reference');

        return $this->service->installPlan($reference, $metadata);
    }

    /**
     * @return array<string, string>
     */
    public function update(mixed $server = null, string $workshopId = ''): array
    {
        return $this->service->update($this->workshopId($workshopId));
    }

    /**
     * @return array<string, string>
     */
    public function remove(mixed $server = null, string $workshopId = ''): array
    {
        return $this->service->remove($this->workshopId($workshopId));
    }

    /**
     * @return array<string, string>
     */
    public function enable(mixed $server = null, string $workshopId = ''): array
    {
        return $this->service->toggle($this->workshopId($workshopId), true);
    }

    /**
     * @return array<string, string>
     */
    public function disable(mixed $server = null, string $workshopId = ''): array
    {
        return $this->service->toggle($this->workshopId($workshopId), false);
    }

    /**
     * @param list<string> $orderedWorkshopIds  Workshop IDs in the desired load order.
     * @return array<string, mixed>
     */
    public function reorder(mixed $server = null, array $orderedWorkshopIds = []): array
    {
        if ($orderedWorkshopIds === []) {
            $input = $this->context->input('ordered_ids', []);
            $orderedWorkshopIds = is_array($input) ? array_values($input) : [];
        }

        return $this->service->reorder($orderedWorkshopIds, $this->context->resolve($server)['model']);
    }

    private function workshopId(string $workshopId): string
    {
        return $workshopId !== '' ? $workshopId : $this->context->stringInput('workshop_id');
    }
}
