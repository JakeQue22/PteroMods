<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Controllers;

use GamePanelMods\DayZManager\Services\DayZDashboardService;
use GamePanelMods\DayZManager\Services\DayZPageRenderer;
use GamePanelMods\DayZManager\Services\DayZServerContext;
use GamePanelMods\DayZManager\Services\DayZServerQueryService;
use GamePanelMods\DayZManager\Services\DayZServerService;
use GamePanelMods\DayZManager\Services\DayZWorkshopService;
use Throwable;

/**
 * Handles server restart and launch parameter preview.
 */
final class DayZServerController
{
    public function __construct(
        private readonly DayZServerService $service = new DayZServerService(),
        private readonly DayZWorkshopService $workshop = new DayZWorkshopService(),
        private readonly DayZServerQueryService $query = new DayZServerQueryService(),
        private readonly DayZDashboardService $dashboard = new DayZDashboardService(),
        private readonly DayZPageRenderer $renderer = new DayZPageRenderer(),
        private readonly DayZServerContext $context = new DayZServerContext(),
    ) {
    }

    /**
     * Returns live query status for the server, bypassing the short-lived
     * cache so the operator can force a fresh result.
     *
     * @return array<string, mixed>
     */
    public function queryStatus(mixed $server = null): array
    {
        $resolved = $this->context->resolve($server);
        $this->query->clearCache($resolved['model']);
        $live = $this->query->query($resolved['model']);
        $live['player_count'] = $this->dashboard->formatPlayerCount($live);

        return $live;
    }

    /**
     * @return array<string, mixed>
     */
    public function restart(mixed $server = null, string $reason = ''): array
    {
        $reason = $reason !== '' ? $reason : $this->context->stringInput('reason');

        $model = $this->context->resolve($server)['model'];
        $this->context->authorizeManage($model);

        return $this->service->restart($reason, $model);
    }

    /**
     * Sends an arbitrary power signal (`start`, `stop`, `restart`, `kill`).
     *
     * @return array<string, mixed>
     */
    public function power(mixed $server = null, string $signal = ''): array
    {
        $signal = $signal !== '' ? $signal : $this->context->stringInput('signal');

        $model = $this->context->resolve($server)['model'];
        $this->context->authorizeManage($model);

        return $this->service->power($model, $signal);
    }

    /**
     * @return array<string, mixed>
     */
    public function saveRestartSchedule(mixed $server = null): array
    {
        $model = $this->context->resolve($server)['model'];
        $this->context->authorizeManage($model);

        $enabled = filter_var(
            $this->context->input('enabled', true),
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        ) ?? false;
        $intervalHours = (int) $this->context->stringInput('interval_hours', '6');
        $warningMinutesInput = $this->context->input('warning_minutes_enabled', []);
        $warningMinutesEnabled = is_array($warningMinutesInput)
            ? array_values(array_map(static fn (mixed $value): int => (int) $value, $warningMinutesInput))
            : [];
        $warningMessagesInput = $this->context->input('warning_messages', []);
        $warningMessages = is_array($warningMessagesInput) ? $warningMessagesInput : [];

        return $this->service->saveRestartSchedule(
            $model,
            max(1, $intervalHours) * 60,
            $enabled,
            $warningMinutesEnabled,
            $warningMessages,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function tickRestartSchedule(mixed $server = null): array
    {
        $model = $this->context->resolve($server)['model'];
        $this->context->authorizeManage($model);

        return $this->service->tickRestartSchedule($model);
    }

    /**
     * Schedules a one-time timed restart with countdown warnings.
     *
     * @return array<string, mixed>
     */
    public function timedRestart(mixed $server = null): array
    {
        $model = $this->context->resolve($server)['model'];
        $this->context->authorizeManage($model);

        $minutes = (int) $this->context->stringInput('minutes', '10');

        return $this->service->startTimedRestart($model, max(1, $minutes));
    }

    /**
     * Cancels a pending one-time timed restart.
     *
     * @return array<string, mixed>
     */
    public function cancelTimedRestart(mixed $server = null): array
    {
        $model = $this->context->resolve($server)['model'];
        $this->context->authorizeManage($model);

        return $this->service->cancelTimedRestart($model);
    }

    /**
     * Renders the DZSA Launcher tab, or returns the server's DZSA endpoint for API requests.
     *
     * @return mixed
     */
    public function dzsa(mixed $server = null)
    {
        $resolved = $this->context->resolve($server);

        $dzsaEndpoint = $this->query->dzsaEndpoint($resolved['model']);
        $payload = [
            'dzsa_ip'         => $dzsaEndpoint !== null ? $dzsaEndpoint['ip'] : '',
            'dzsa_query_port' => $dzsaEndpoint !== null ? $dzsaEndpoint['query_port'] : 0,
            'dzsa_url'        => $dzsaEndpoint !== null
                ? 'https://dayzsalauncher.com/#/servercheck/' . $dzsaEndpoint['ip'] . ':' . $dzsaEndpoint['query_port']
                : '',
        ];

        if ($this->context->expectsJson()) {
            return $payload;
        }

        return $this->renderer->render('dzsa', $payload, 'dzsa', $resolved['id'], $resolved['name']);
    }

    /**
     * Renders the server control page, or returns launch parameters for API requests.
     *
     * @param list<string> $enabledFolders
     * @return mixed
     */
    public function launchParameters(mixed $server = null, array $enabledFolders = [])
    {
        $resolved = $this->context->resolve($server);

        try {
            $folders = $enabledFolders !== [] ? $enabledFolders : $this->workshop->enabledFolders($resolved['model']);
            $payload = $this->service->launchParameters($resolved['model'], $folders);
        } catch (Throwable $exception) {
            return $this->renderer->renderError($exception->getMessage(), 'server', $resolved['id'], $resolved['name']);
        }

        if ($this->context->expectsJson()) {
            return $payload;
        }

        $payload['live'] = $this->query->query($resolved['model']);
        $payload['live']['player_count'] = $this->dashboard->formatPlayerCount($payload['live']);

        return $this->renderer->render('server', $payload, 'server', $resolved['id'], $resolved['name']);
    }
}
