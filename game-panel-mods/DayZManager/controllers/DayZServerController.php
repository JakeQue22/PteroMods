<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Controllers;

use GamePanelMods\DayZManager\Services\DayZDashboardService;
use GamePanelMods\DayZManager\Services\DayZCacheWarmService;
use GamePanelMods\DayZManager\Services\DayZPageRenderer;
use GamePanelMods\DayZManager\Services\DayZPanelGateway;
use GamePanelMods\DayZManager\Services\DayZProfileLogScrubService;
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
        private readonly DayZCacheWarmService $warmer = new DayZCacheWarmService(),
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
        private readonly DayZDashboardService $dashboard = new DayZDashboardService(),
        private readonly DayZPageRenderer $renderer = new DayZPageRenderer(),
        private readonly DayZServerContext $context = new DayZServerContext(),
        private readonly DayZProfileLogScrubService $logScrub = new DayZProfileLogScrubService(),
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
        $this->warmer->tick($resolved['model']);
        $this->query->clearCache($resolved['model']);
        $live = $this->query->query($resolved['model'], true);
        $details = $this->gateway->details($resolved['model']);
        $powerState = $this->gateway->state($resolved['model']) ?? '';
        $live['player_count'] = $this->dashboard->formatPlayerCount($live);
        $live['power_state'] = $powerState;
        $live['panel_running'] = in_array($powerState, ['running', 'starting', 'stopping'], true);
        $live['version'] = $this->dashboard->resolveServerVersion($resolved['model'], $live);
        $live['server_status'] = $this->dashboard->resolveStatus($resolved['model'], $live, $details);
        $live['status_source'] = $this->dashboard->statusSource($resolved['model'], $live, $details);

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
        $reason = $this->context->stringInput('reason');

        $model = $this->context->resolve($server)['model'];
        $this->context->authorizeManage($model);

        return $this->service->power($model, $signal, $reason);
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
        $startTime = $this->context->stringInput('start_time', '');

        return $this->service->saveRestartSchedule(
            $model,
            max(1, $intervalHours) * 60,
            $enabled,
            $warningMinutesEnabled,
            $warningMessages,
            $startTime,
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
     * Progresses the pending follow-up restart / DZSA Launcher submission
     * after queued mods finish installing. See
     * DayZServerService::tickModInstallFollowUp() for the state machine.
     *
     * @return array<string, mixed>
     */
    public function tickModInstallFollowUp(mixed $server = null): array
    {
        $model = $this->context->resolve($server)['model'];
        $this->context->authorizeManage($model);

        return $this->service->tickModInstallFollowUp($model);
    }

    /**
     * Removes old profile logs according to DayZ Manager retention settings.
     *
     * @return array<string, mixed>
     */
    public function tickProfileLogScrub(mixed $server = null): array
    {
        $model = $this->context->resolve($server)['model'];
        $this->context->authorizeManage($model);
        $force = filter_var(
            $this->context->input('force', false),
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        ) ?? false;

        return $this->logScrub->tick($model, $force);
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
        $reason  = $this->context->stringInput('reason');

        return $this->service->startTimedRestart($model, max(1, $minutes), $reason);
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
     * Sends a global message to all connected players via `say -1`.
     *
     * @return array<string, mixed>
     */
    public function sendMessage(mixed $server = null): array
    {
        $model = $this->context->resolve($server)['model'];
        $this->context->authorizeManage($model);

        $message = trim($this->context->stringInput('message'));

        if ($message === '') {
            return ['status' => 'failed', 'message' => 'Message cannot be empty.'];
        }

        $sent = $this->gateway->sendCommand($model, 'say -1 ' . $message);

        return [
            'status' => $sent ? 'sent' : 'failed',
            'message' => $sent ? 'Global message sent.' : 'Failed to send message (server may be offline).',
        ];
    }

    /**
     * Renders the DZSA Launcher tab, or returns the server's DZSA endpoint for API requests.
     *
     * @return mixed
     */
    public function dzsa(mixed $server = null)
    {
        $resolved = $this->context->resolve($server);
        $this->warmer->tick($resolved['model']);

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
        $this->warmer->tick($resolved['model']);

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

        $details = $this->gateway->details($resolved['model']);
        $payload['live']['version'] = $this->dashboard->resolveServerVersion($resolved['model'], $payload['live']);
        $payload['live']['server_status'] = $this->dashboard->resolveStatus($resolved['model'], $payload['live'], $details);
        $payload['live']['status_source'] = $this->dashboard->statusSource($resolved['model'], $payload['live'], $details);

        return $this->renderer->render('server', $payload, 'server', $resolved['id'], $resolved['name']);
    }
}
