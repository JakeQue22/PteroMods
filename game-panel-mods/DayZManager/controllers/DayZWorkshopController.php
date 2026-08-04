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
    public function install(mixed $server = null, string $reference = '', array $metadata = []): mixed
    {
        try {
            $model = $this->manage($server);

            $reference = $reference !== '' ? $reference : $this->context->stringInput('reference');
            $forceRestart = filter_var(
                $this->context->input('force_restart', false),
                FILTER_VALIDATE_BOOL,
                FILTER_NULL_ON_FAILURE,
            ) ?? false;

            return $this->service->installPlan($reference, $metadata, $model, $forceRestart);
        } catch (Throwable $exception) {
            return $this->errorResponse($exception->getMessage());
        }
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
    public function browse(mixed $server = null, string $term = '', int $page = 0): array
    {
        $term = $term !== '' ? $term : $this->context->stringInput('search');
        // $page has no matching {page} route placeholder, so Laravel's method
        // binding never populates it from the query string; it always keeps
        // its default value. That default must therefore be falsy (0), or the
        // `?: stringInput('page')` fallback below would never run and every
        // request — including "next page" clicks — would silently stay on
        // page 1 regardless of the `page` query parameter actually sent.
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
     * @return mixed
     */
    public function update(mixed $server = null, string $workshopId = ''): mixed
    {
        try {
            $this->manage($server);

            return $this->service->update($this->workshopId($workshopId));
        } catch (Throwable $exception) {
            return $this->errorResponse($exception->getMessage());
        }
    }

    /**
     * @return mixed
     */
    public function remove(mixed $server = null, string $workshopId = ''): mixed
    {
        try {
            $model = $this->manage($server);
            $queueOnly = filter_var(
                $this->context->input('queue_only', false),
                FILTER_VALIDATE_BOOL,
                FILTER_NULL_ON_FAILURE,
            ) ?? false;

            return $queueOnly
                ? $this->service->removeQueued($this->workshopId($workshopId), $model)
                : $this->service->remove($this->workshopId($workshopId), $model);
        } catch (Throwable $exception) {
            return $this->errorResponse($exception->getMessage());
        }
    }

    /**
     * @return mixed
     */
    public function enable(mixed $server = null, string $workshopId = ''): mixed
    {
        try {
            $model = $this->manage($server);

            return $this->service->toggle($this->workshopId($workshopId), true, $model);
        } catch (Throwable $exception) {
            return $this->errorResponse($exception->getMessage());
        }
    }

    /**
     * @return mixed
     */
    public function disable(mixed $server = null, string $workshopId = ''): mixed
    {
        try {
            $model = $this->manage($server);

            return $this->service->toggle($this->workshopId($workshopId), false, $model);
        } catch (Throwable $exception) {
            return $this->errorResponse($exception->getMessage());
        }
    }

    /**
     * @param list<string> $orderedWorkshopIds  Workshop IDs in the desired load order.
     * @return mixed
     */
    public function reorder(mixed $server = null, array $orderedWorkshopIds = []): mixed
    {
        try {
            $model = $this->manage($server);

            if ($orderedWorkshopIds === []) {
                $input = $this->context->input('ordered_ids', []);
                $orderedWorkshopIds = is_array($input) ? array_values($input) : [];
            }

            return $this->service->reorder($orderedWorkshopIds, $model);
        } catch (Throwable $exception) {
            return $this->errorResponse($exception->getMessage());
        }
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

    /**
     * Turns an uncaught exception into a well-formed JSON error payload
     * instead of letting it bubble up as a raw framework 500. The Browse
     * Workshop / queue JS reads `data.message` regardless of HTTP status, so
     * callers still see a meaningful reason for the failure instead of a
     * bare, body-less 500 (which is what the user saw when queueing a mod
     * from Browse Workshop, even though the queue persistence had already
     * happened before the exception was thrown).
     */
    private function errorResponse(string $message): mixed
    {
        $payload = ['status' => 'failed', 'message' => $message !== '' ? $message : 'Request failed.'];

        if (function_exists('response')) {
            return response($payload, 500);
        }

        return $payload;
    }
}
