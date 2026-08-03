<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use PteroMods\Services\DayZ\LaunchParameterBuilder;
use Throwable;

/**
 * Server-level controls: power actions and the live launch parameters.
 */
final class DayZServerService
{
    /** @var list<int> */
    private const RESTART_WARNINGS_MINUTES = [180, 120, 60, 30, 20, 10, 5, 2, 1];

    public function __construct(
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
        private readonly DayZStartupService $startup = new DayZStartupService(),
        private readonly DayZServerContext $context = new DayZServerContext(),
    ) {
    }

    /**
     * Sends a power signal to the server through the Pterodactyl daemon.
     *
     * @return array<string, mixed>
     */
    public function power(mixed $server, string $signal, string $reason = ''): array
    {
        $signal = strtolower(trim($signal));

        if (!in_array($signal, ['start', 'stop', 'restart', 'kill'], true)) {
            return [
                'status'  => 'rejected',
                'action'  => $signal,
                'message' => 'Unsupported power signal.',
            ];
        }

        $dispatched = $this->gateway->power($server, $signal);

        return [
            'status'    => $dispatched ? 'dispatched' : 'failed',
            'action'    => $signal,
            'reason'    => $reason,
            'queued_at' => date('c'),
            'message'   => $dispatched
                ? 'Power signal sent to the daemon.'
                : 'The daemon did not accept the power signal.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function restart(string $reason = '', mixed $server = null): array
    {
        return $this->power($server, 'restart', $reason);
    }

    /**
     * @return array<string, mixed>
     */
    public function restartSchedule(mixed $server): array
    {
        $serverId = $this->serverIdentifier($server);
        $schedule = $this->scheduleRow($serverId);

        if ($schedule === null) {
            return [
                'enabled' => false,
                'interval_minutes' => 0,
                'next_restart_at' => null,
                'warnings_sent' => [],
            ];
        }

        return [
            'enabled' => (bool) ($schedule['enabled'] ?? false),
            'interval_minutes' => (int) ($schedule['interval_minutes'] ?? 0),
            'next_restart_at' => (string) ($schedule['next_restart_at'] ?? ''),
            'warnings_sent' => $this->parseWarnings((string) ($schedule['warnings_sent'] ?? '')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function saveRestartSchedule(mixed $server, int $intervalMinutes, bool $enabled): array
    {
        $serverId = $this->serverIdentifier($server);

        if ($serverId === '') {
            return ['status' => 'failed', 'message' => 'Server identifier unavailable.'];
        }

        if ($intervalMinutes < 60 || $intervalMinutes > 24 * 60) {
            return ['status' => 'failed', 'message' => 'Restart interval must be between 1 and 24 hours.'];
        }

        if (!$this->hasScheduleTable()) {
            return ['status' => 'failed', 'message' => 'Restart schedule storage is not available.'];
        }

        $nextRestart = $enabled ? date('Y-m-d H:i:s', time() + ($intervalMinutes * 60)) : null;

        try {
            \Illuminate\Support\Facades\DB::table('dayz_restart_schedules')->updateOrInsert(
                ['server_id' => $serverId],
                [
                    'enabled' => $enabled ? 1 : 0,
                    'interval_minutes' => $intervalMinutes,
                    'next_restart_at' => $nextRestart,
                    'warnings_sent' => '',
                    'updated_at' => date('Y-m-d H:i:s'),
                    'created_at' => date('Y-m-d H:i:s'),
                ],
            );
        } catch (Throwable) {
            return ['status' => 'failed', 'message' => 'Failed to save restart schedule.'];
        }

        return [
            'status' => 'applied',
            'message' => $enabled
                ? 'Scheduled restarts enabled.'
                : 'Scheduled restarts disabled.',
        ] + $this->restartSchedule($server);
    }

    /**
     * @return array<string, mixed>
     */
    public function tickRestartSchedule(mixed $server): array
    {
        $serverId = $this->serverIdentifier($server);
        $schedule = $this->scheduleRow($serverId);

        if ($schedule === null || !(bool) ($schedule['enabled'] ?? false)) {
            return ['status' => 'idle', 'message' => 'Restart scheduling is disabled.'];
        }

        $interval = max(60, (int) ($schedule['interval_minutes'] ?? 0));
        $next = strtotime((string) ($schedule['next_restart_at'] ?? ''));

        if ($next === false) {
            $next = time() + ($interval * 60);
        }

        $now = time();
        $warningsSent = $this->parseWarnings((string) ($schedule['warnings_sent'] ?? ''));
        $messagesSent = [];

        foreach (self::RESTART_WARNINGS_MINUTES as $minutes) {
            if ($now >= ($next - ($minutes * 60)) && !in_array($minutes, $warningsSent, true)) {
                $message = sprintf("<t color='#ff0000'>Server restart in %s.</t>", $this->formatMinutes($minutes));
                if ($this->gateway->sendCommand($server, 'say -1 ' . $message)) {
                    $warningsSent[] = $minutes;
                    $messagesSent[] = $message;
                }
            }
        }

        $restarted = false;

        if ($now >= $next) {
            $this->gateway->sendCommand($server, "say -1 <t color='#ff0000'>Restarting now.</t>");
            $restarted = $this->gateway->power($server, 'restart');
            $next = $now + ($interval * 60);
            $warningsSent = [];
        }

        $this->storeScheduleTick($serverId, $interval, $next, $warningsSent, true);

        return [
            'status' => $restarted ? 'restarted' : ($messagesSent === [] ? 'waiting' : 'warning_sent'),
            'messages_sent' => $messagesSent,
            'next_restart_at' => date('c', $next),
            'interval_minutes' => $interval,
            'restarted' => $restarted,
        ];
    }

    /**
     * The launch parameters Pterodactyl actually starts the server with.
     *
     * @param list<string> $enabledFolders Ordered folder names of enabled mods.
     * @return array<string, mixed>
     */
    public function launchParameters(mixed $server = null, array $enabledFolders = []): array
    {
        $startup = $this->startup->startup($server);
        $modFolders = $startup['mods'] !== [] ? $startup['mods'] : $enabledFolders;
        $schedule = $this->restartSchedule($server);

        return [
            'launch_parameters'  => (new LaunchParameterBuilder())->build($modFolders),
            'mod_count'          => count(array_filter($modFolders, static fn (string $f): bool => $f !== '')),
            'startup_raw'        => $startup['raw'],
            'startup_rendered'   => $startup['rendered'],
            'startup_parameters' => $startup['parameters'],
            'startup_variables'  => $startup['variables'],
            'startup_source'     => $startup['source'],
            'mods'               => $startup['mods'],
            'server_mods'        => $startup['server_mods'],
            'restart_schedule'   => $schedule,
        ];
    }

    private function serverIdentifier(mixed $server): string
    {
        return $server === null ? '' : $this->context->attribute($server, ['uuid', 'uuidShort', 'id']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function scheduleRow(string $serverId): ?array
    {
        if ($serverId === '' || !$this->hasScheduleTable()) {
            return null;
        }

        try {
            $row = \Illuminate\Support\Facades\DB::table('dayz_restart_schedules')
                ->where('server_id', $serverId)
                ->first();
        } catch (Throwable) {
            return null;
        }

        return $row === null ? null : (array) $row;
    }

    private function hasScheduleTable(): bool
    {
        if (!class_exists('Illuminate\\Support\\Facades\\DB')
            || !class_exists('Illuminate\\Support\\Facades\\Schema')) {
            return false;
        }

        try {
            return \Illuminate\Support\Facades\Schema::hasTable('dayz_restart_schedules');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param list<int> $warningsSent
     */
    private function storeScheduleTick(string $serverId, int $interval, int $next, array $warningsSent, bool $enabled): void
    {
        if ($serverId === '' || !$this->hasScheduleTable()) {
            return;
        }

        try {
            \Illuminate\Support\Facades\DB::table('dayz_restart_schedules')->updateOrInsert(
                ['server_id' => $serverId],
                [
                    'enabled' => $enabled ? 1 : 0,
                    'interval_minutes' => $interval,
                    'next_restart_at' => date('Y-m-d H:i:s', $next),
                    'warnings_sent' => implode(',', $warningsSent),
                    'updated_at' => date('Y-m-d H:i:s'),
                    'created_at' => date('Y-m-d H:i:s'),
                ],
            );
        } catch (Throwable) {
            // Best effort.
        }
    }

    /**
     * @return list<int>
     */
    private function parseWarnings(string $warnings): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $entry): int => (int) trim($entry),
            explode(',', $warnings),
        ), static fn (int $value): bool => $value > 0)));
    }

    private function formatMinutes(int $minutes): string
    {
        if ($minutes >= 60) {
            $hours = (int) floor($minutes / 60);
            $remaining = $minutes % 60;

            if ($remaining === 0) {
                return $hours . ' hour' . ($hours === 1 ? '' : 's');
            }

            return sprintf('%d hour%s %d min', $hours, $hours === 1 ? '' : 's', $remaining);
        }

        return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    }
}
