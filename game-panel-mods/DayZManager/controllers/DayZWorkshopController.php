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
                'client_id'      => $this->context->clientIdentifier($resolved['model'], $resolved['id']),
                'settings'       => $this->service->settings($resolved['model']),
                'installed_mods' => $this->service->installedMods($resolved['model']),
                'browse_enabled' => $this->service->isBrowseEnabled(),
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
        $model = $this->manage($server);

        $reference = $reference !== '' ? $reference : $this->context->stringInput('reference');
        $forceRestart = filter_var(
            $this->context->input('force_restart', false),
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        ) ?? false;

        return $this->service->installPlan($reference, $metadata, $model, $forceRestart);
    }

    /**
     * Reports install/download progress for a previously queued plan, so the
     * page can poll it until every mod in the plan has appeared on the server.
     *
     * @param list<string> $workshopIds
     * @return array<string, mixed>
     */
    public function installStatus(mixed $server = null, array $workshopIds = []): array
    {
        $model = $this->context->resolve($server)['model'];

        if ($workshopIds === []) {
            $input = $this->context->input('workshop_ids', []);

            if (is_string($input) && $input !== '') {
                $decoded = json_decode($input, true);
                $input = is_array($decoded) ? $decoded : [];
            }

            $workshopIds = is_array($input) ? array_values(array_map('strval', $input)) : [];
        }

        if ($workshopIds === []) {
            $single = $this->context->stringInput('workshop_id');
            $workshopIds = $single === '' ? [] : [$single];
        }

        return $this->service->installStatus($workshopIds, $model);
    }

    /**
     * The persisted install queue for this server, used to restore the
     * "downloading…" banner on `/mods` after a page reload.
     *
     * @return array<string, mixed>
     */
    public function queue(mixed $server = null): array
    {
        $model = $this->context->resolve($server)['model'];

        return $this->service->queue($model);
    }

    /**
     * Live preview of a Workshop reference, used to render a thumbnail+name
     * dropdown under the install search box as the operator types.
     *
     * @return array<string, mixed>
     */
    public function lookup(mixed $server = null, string $reference = ''): array
    {
        $reference = $reference !== '' ? $reference : $this->context->stringInput('reference');
        $model = $this->context->resolve($server)['model'];

        return $this->service->lookup($reference, $model);
    }

    /**
     * Browses the DayZ Workshop by search term, for the "Browse Workshop" modal.
     *
     * @return array<string, mixed>
     */
    public function browse(mixed $server = null, string $term = '', int $page = 1): array
    {
        $term = $term !== '' ? $term : $this->context->stringInput('search');
        $page = $page > 0 ? $page : (int) $this->context->stringInput('page', '1');
        $model = $this->context->resolve($server)['model'];
        $options = [
            'sort' => $this->context->stringInput('sort'),
            'type' => $this->context->stringInput('type'),
            'mod_type' => $this->context->stringInput('mod_type'),
            'required_dlc' => $this->context->stringInput('required_dlc'),
        ];

        return $this->service->browse($term, max(1, $page), $model, $options);
    }

    /**
     * @return array<string, mixed>
     */
    public function update(mixed $server = null, string $workshopId = ''): array
    {
        $this->manage($server);

        return $this->service->update($this->workshopId($workshopId));
    }

    /**
     * @return array<string, mixed>
     */
    public function remove(mixed $server = null, string $workshopId = ''): array
    {
        $model = $this->manage($server);
        $queueOnly = filter_var(
            $this->context->input('queue_only', false),
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        ) ?? false;

        return $queueOnly
            ? $this->service->removeQueued($this->workshopId($workshopId), $model)
            : $this->service->remove($this->workshopId($workshopId), $model);
    }

    /**
     * @return array<string, mixed>
     */
    public function enable(mixed $server = null, string $workshopId = ''): array
    {
        $model = $this->manage($server);

        return $this->service->toggle($this->workshopId($workshopId), true, $model);
    }

    /**
     * @return array<string, mixed>
     */
    public function disable(mixed $server = null, string $workshopId = ''): array
    {
        $model = $this->manage($server);

        return $this->service->toggle($this->workshopId($workshopId), false, $model);
    }

    /**
     * @param list<string> $orderedWorkshopIds  Workshop IDs in the desired load order.
     * @return array<string, mixed>
     */
    public function reorder(mixed $server = null, array $orderedWorkshopIds = []): array
    {
        $model = $this->manage($server);

        if ($orderedWorkshopIds === []) {
            $input = $this->context->input('ordered_ids', []);
            $orderedWorkshopIds = is_array($input) ? array_values($input) : [];
        }

        return $this->service->reorder($orderedWorkshopIds, $model);
    }

    /**
     * Resolves the server and ensures the caller may change its load order.
     */
    private function manage(mixed $server): mixed
    {
        $model = $this->context->resolve($server)['model'];
        $this->context->authorizeManage($model);

        return $model;
    }

    private function workshopId(string $workshopId): string
    {
        return $workshopId !== '' ? $workshopId : $this->context->stringInput('workshop_id');
    }
}
