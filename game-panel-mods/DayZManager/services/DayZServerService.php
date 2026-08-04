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
    private const RESTART_WARNINGS_MINUTES = [180, 120, 60, 30, 20, 15, 10, 5, 2, 1];
    private const FOLLOW_UP_RUNNING_GRACE_SECONDS = 15;

    /**
     * How long tickModInstallFollowUp() waits for the console to confirm the
     * mod update finished before restarting anyway. Restarting mid-download
     * (e.g. because logs are unavailable on this panel fork) would otherwise
     * interrupt SteamCMD and risk a corrupt/incomplete mod folder, but the
     * feature must still degrade gracefully rather than never restart.
     */
    private const FOLLOW_UP_LOG_CONFIRM_TIMEOUT_SECONDS = 60;

    /** Marks that the previous startup's mod update has finished downloading. */
    private const LOG_MOD_UPDATE_SUCCESSFUL = '[UPDATE]: Mod download/update successful!';

    /** Marks that SteamCMD is done checking every Workshop mod for updates. */
    private const LOG_WORKSHOP_CHECK_COMPLETE = '[UPDATE]: Steam Workshop mod update check complete!';

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

        // Announce before executing so players see the message while the server is still running.
        if ($reason !== '' && in_array($signal, ['stop', 'restart', 'kill'], true)) {
            $label = match ($signal) {
                'restart' => 'restarting',
                'stop'    => 'shutting down',
                default   => 'being killed',
            };
            $this->gateway->sendCommand(
                $server,
                "say -1 <t color='#ff0000'>Server is " . $label . '. Reason: ' . $reason . '</t>',
            );
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
                'timed_restart_at' => null,
                'warnings_sent' => [],
                'warning_minutes_enabled' => self::RESTART_WARNINGS_MINUTES,
                'warning_messages' => [],
            ];
        }

        return [
            'enabled' => (bool) ($schedule['enabled'] ?? false),
            'interval_minutes' => (int) ($schedule['interval_minutes'] ?? 0),
            'next_restart_at' => (string) ($schedule['next_restart_at'] ?? ''),
            'timed_restart_at' => $this->hasScheduleColumn('timed_restart_at')
                ? ($schedule['timed_restart_at'] ?? null)
                : null,
            'warnings_sent' => $this->parseWarnings((string) ($schedule['warnings_sent'] ?? '')),
            'warning_minutes_enabled' => $this->enabledWarningMinutes($schedule),
            'warning_messages' => $this->warningMessages($schedule),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function saveRestartSchedule(
        mixed $server,
        int $intervalMinutes,
        bool $enabled,
        array $warningMinutesEnabled = [],
        array $warningMessages = [],
    ): array
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
        $warningMinutesEnabled = $this->normalizeWarningMinutes($warningMinutesEnabled);
        $warningMessages = $this->normalizeWarningMessages($warningMessages);

        // Pre-mark intervals >= intervalMinutes as already sent so the first tick
        // does not fire them all at once. The intervals that are shorter than the
        // restart delay will still fire at the appropriate times.
        $preFilledWarnings = $enabled ? array_values(array_filter(
            $warningMinutesEnabled,
            static fn (int $m): bool => $m >= $intervalMinutes,
        )) : [];

        $update = [
            'enabled' => $enabled ? 1 : 0,
            'interval_minutes' => $intervalMinutes,
            'next_restart_at' => $nextRestart,
            'warnings_sent' => implode(',', $preFilledWarnings),
            'updated_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ];

        if ($this->hasScheduleColumn('warning_minutes_enabled')) {
            $update['warning_minutes_enabled'] = implode(',', $warningMinutesEnabled);
        }

        if ($this->hasScheduleColumn('warning_messages')) {
            $encoded = json_encode($warningMessages);
            $update['warning_messages'] = is_string($encoded) ? $encoded : null;
        }

        try {
            \Illuminate\Support\Facades\DB::table('dayz_restart_schedules')->updateOrInsert(
                ['server_id' => $serverId],
                $update,
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

        if ($schedule === null) {
            return ['status' => 'idle', 'message' => 'Restart scheduling is disabled.'];
        }

        $now = time();
        $results = [];

        // ── One-time timed restart ────────────────────────────────────────────
        if ($this->hasScheduleColumn('timed_restart_at')) {
            $timedAt = $schedule['timed_restart_at'] ?? null;
            $timedNext = $timedAt !== null ? strtotime((string) $timedAt) : false;

            if ($timedNext !== false && $timedNext > 0) {
                // When the restart time has already passed (e.g. the tick was
                // delayed), fire the restart immediately without sending
                // retrospective warning messages that would spam global chat.
                if ($now >= $timedNext) {
                    $this->gateway->sendCommand($server, "say -1 <t color='#ff0000'>Restarting now.</t>");
                    $this->gateway->power($server, 'restart');
                    $this->clearTimedRestart($serverId);

                    return [
                        'status'          => 'restarted',
                        'messages_sent'   => $results,
                        'next_restart_at' => null,
                        'timed_restart_at' => null,
                        'interval_minutes' => (int) ($schedule['interval_minutes'] ?? 0),
                        'restarted'       => true,
                    ];
                }

                $timedWarningsSent = $this->parseWarnings((string) ($schedule['timed_warnings_sent'] ?? ''));
                $enabledWarningMinutes = $this->enabledWarningMinutes($schedule);
                $warningMessages = $this->warningMessages($schedule);

                foreach ($enabledWarningMinutes as $minutes) {
                    if ($now >= ($timedNext - ($minutes * 60)) && !in_array($minutes, $timedWarningsSent, true)) {
                        $message = $this->warningMessage($minutes, $warningMessages);

                        if ($this->gateway->sendCommand($server, 'say -1 ' . $message)) {
                            $timedWarningsSent[] = $minutes;
                            $results[] = $message;
                        }
                    }
                }

                if ($timedWarningsSent !== $this->parseWarnings((string) ($schedule['timed_warnings_sent'] ?? ''))) {
                    $this->storeTimedWarnings($serverId, $timedWarningsSent);
                }
            }
        }

        // ── Recurring scheduled restart ───────────────────────────────────────
        if (!(bool) ($schedule['enabled'] ?? false)) {
            return [
                'status'          => $results === [] ? 'idle' : 'warning_sent',
                'messages_sent'   => $results,
                'next_restart_at' => null,
                'interval_minutes' => 0,
                'restarted'       => false,
            ];
        }

        $interval = max(60, (int) ($schedule['interval_minutes'] ?? 0));
        $next = strtotime((string) ($schedule['next_restart_at'] ?? ''));

        if ($next === false) {
            $next = time() + ($interval * 60);
        }

        $restarted = false;

        if ($now >= $next) {
            // Restart time has passed; restart immediately without sending
            // any retrospective warning messages.
            $this->gateway->sendCommand($server, "say -1 <t color='#ff0000'>Restarting now.</t>");
            $restarted = $this->gateway->power($server, 'restart');
            $next = $next + ($interval * 60);
            $warningsSent = [];
        } else {
            $warningsSent = $this->parseWarnings((string) ($schedule['warnings_sent'] ?? ''));
            $enabledWarningMinutes = $this->enabledWarningMinutes($schedule);
            $warningMessages = $this->warningMessages($schedule);

            foreach ($enabledWarningMinutes as $minutes) {
                if ($now >= ($next - ($minutes * 60)) && !in_array($minutes, $warningsSent, true)) {
                    $message = $this->warningMessage($minutes, $warningMessages);

                    if ($this->gateway->sendCommand($server, 'say -1 ' . $message)) {
                        $warningsSent[] = $minutes;
                        $results[] = $message;
                    }
                }
            }
        }

        $this->storeScheduleTick($serverId, $schedule, $interval, $next, $warningsSent, true);

        return [
            'status'          => $restarted ? 'restarted' : ($results === [] ? 'waiting' : 'warning_sent'),
            'messages_sent'   => $results,
            'next_restart_at' => date('c', $next),
            'interval_minutes' => $interval,
            'restarted'       => $restarted,
        ];
    }

    /**
     * Handles the follow-up restart and DZSA Launcher submission after
     * queued mods finish installing. Called periodically by client-side
     * polling (mirroring tickRestartSchedule()) so DayZ Manager can react
     * to state changes without a page reload.
     *
     * Behaviour is gated by two independent settings:
     * - `auto_restart_after_mod_install`: once mods have been marked pending
     *   (see DayZWorkshopService::markPendingModInstallFollowUp()), restart
     *   the server so the egg's startup script actually loads them.
     * - `auto_submit_dzsa`: once the server is confirmed running again,
     *   best-effort submit a server-check request to DZSA Launcher.
     *
     * @return array<string, mixed>
     */
    public function tickModInstallFollowUp(mixed $server): array
    {
        $serverId = $this->serverIdentifier($server);
        $pending = $this->pendingModInstallRow($serverId);

        if ($pending === null) {
            return ['status' => 'idle'];
        }

        $settings = new DayZManagerSettingsService();
        $status = (string) ($pending['status'] ?? 'waiting');
        $state = $this->gateway->state($server);

        if ($status === 'waiting') {
            if (!(bool) $settings->get('auto_restart_after_mod_install', false)) {
                // Auto-restart disabled: leave the row as a marker for the
                // operator (dzsa.blade.php / mods page can surface it) but
                // do nothing further until they restart manually.
                return ['status' => 'awaiting_manual_restart'];
            }

            // Don't restart yet: wait for the console to confirm the
            // in-progress mod download/update actually finished first (see
            // the 'confirming' branch below). Restarting while SteamCMD is
            // still mid-download risks an incomplete mod folder and a crash
            // on the next boot.
            $this->storePendingModInstallStatus($serverId, 'confirming', date('Y-m-d H:i:s'));

            return ['status' => 'awaiting_log_confirmation'];
        }

        if ($status === 'confirming') {
            $updatedAt = strtotime((string) ($pending['updated_at'] ?? ''));
            $elapsed = $updatedAt !== false ? time() - $updatedAt : PHP_INT_MAX;

            $confirmed = $this->modUpdateConfirmedInLogs($server);

            if (!$confirmed && $elapsed < self::FOLLOW_UP_LOG_CONFIRM_TIMEOUT_SECONDS) {
                return ['status' => 'awaiting_log_confirmation'];
            }

            // Either the console confirmed the previous mod update/startup
            // cycle finished, or the timeout elapsed (e.g. logs aren't
            // available on this panel fork) — restart now so it never gets
            // stuck waiting forever.
            $this->gateway->sendCommand(
                $server,
                "say -1 <t color='#ff0000'>Restarting to enable newly installed mods.</t>",
            );
            $this->gateway->power($server, 'restart');
            $this->storePendingModInstallStatus($serverId, 'restarting', date('Y-m-d H:i:s'));

            return ['status' => 'restarted'];
        }

        if ($status === 'restarting') {
            if ($state !== 'running') {
                return ['status' => 'waiting_for_running'];
            }

            $updatedAt = strtotime((string) ($pending['updated_at'] ?? ''));

            if ($updatedAt !== false && (time() - $updatedAt) < self::FOLLOW_UP_RUNNING_GRACE_SECONDS) {
                return ['status' => 'waiting_for_running'];
            }

            if (!(bool) $settings->get('auto_submit_dzsa', false)) {
                $this->clearPendingModInstall($serverId);

                return ['status' => 'restart_complete'];
            }

            $submitted = $this->submitDzsa($server);
            $this->clearPendingModInstall($serverId);

            return ['status' => $submitted ? 'dzsa_submitted' : 'dzsa_submit_failed'];
        }

        $this->clearPendingModInstall($serverId);

        return ['status' => 'idle'];
    }

    /**
     * Whether the console log shows the previous startup's mod update cycle
     * finished — both `[UPDATE]: Mod download/update successful!` and
     * `[UPDATE]: Steam Workshop mod update check complete!` appearing, which
     * the egg logs right before its own `[STARTUP]: Starting server with the
     * following startup command` line. Only the most recent startup cycle is
     * considered (the search starts from the last `[STARTUP]:` line found),
     * so a stale confirmation from a much older boot can't be reused.
     *
     * Returns true (skip waiting) when logs can't be read at all, since the
     * feature must degrade gracefully rather than block forever on panel
     * forks without a console-log endpoint.
     */
    private function modUpdateConfirmedInLogs(mixed $server): bool
    {
        $lines = $this->gateway->consoleLogs($server);

        if ($lines === []) {
            return true;
        }

        $lastStartupIndex = null;

        foreach ($lines as $index => $line) {
            if (str_contains($line, '[STARTUP]: Starting server with the following startup command')) {
                $lastStartupIndex = $index;
            }
        }

        $window = $lastStartupIndex === null ? $lines : array_slice($lines, 0, $lastStartupIndex + 1);

        $sawModUpdate = false;
        $sawWorkshopCheck = false;

        foreach ($window as $line) {
            if (str_contains($line, self::LOG_MOD_UPDATE_SUCCESSFUL)) {
                $sawModUpdate = true;
            }

            if (str_contains($line, self::LOG_WORKSHOP_CHECK_COMPLETE)) {
                $sawWorkshopCheck = true;
            }
        }

        return $sawModUpdate && $sawWorkshopCheck;
    }

    /**
     * Best-effort GET to the DZSA Launcher server-check page so the
     * launcher re-scans this server's mod listing without requiring the
     * operator to open the tab manually.
     */
    private function submitDzsa(mixed $server): bool
    {
        if (!class_exists('Illuminate\\Support\\Facades\\Http')) {
            return false;
        }

        $endpoint = (new DayZServerQueryService())->dzsaEndpoint($server);

        if ($endpoint === null) {
            return false;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(5)->get(
                'https://dayzsalauncher.com/#/servercheck/' . $endpoint['ip'] . ':' . $endpoint['query_port'],
            );

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pendingModInstallRow(string $serverId): ?array
    {
        if ($serverId === ''
            || !class_exists('Illuminate\\Support\\Facades\\Schema')
            || !class_exists('Illuminate\\Support\\Facades\\DB')) {
            return null;
        }

        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('dayz_dzsa_pending')) {
                return null;
            }

            $row = \Illuminate\Support\Facades\DB::table('dayz_dzsa_pending')
                ->where('server_id', $serverId)
                ->first();

            return $row === null ? null : (array) $row;
        } catch (Throwable) {
            return null;
        }
    }

    private function storePendingModInstallStatus(string $serverId, string $status, ?string $updatedAt = null): void
    {
        if ($serverId === ''
            || !class_exists('Illuminate\\Support\\Facades\\Schema')
            || !class_exists('Illuminate\\Support\\Facades\\DB')) {
            return;
        }

        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('dayz_dzsa_pending')) {
                return;
            }

            \Illuminate\Support\Facades\DB::table('dayz_dzsa_pending')
                ->where('server_id', $serverId)
                ->update(['status' => $status, 'updated_at' => $updatedAt ?? date('Y-m-d H:i:s')]);
        } catch (Throwable) {
            // Best-effort; worst case the next tick re-evaluates from 'waiting'.
        }
    }

    private function clearPendingModInstall(string $serverId): void
    {
        if ($serverId === ''
            || !class_exists('Illuminate\\Support\\Facades\\Schema')
            || !class_exists('Illuminate\\Support\\Facades\\DB')) {
            return;
        }

        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('dayz_dzsa_pending')) {
                return;
            }

            \Illuminate\Support\Facades\DB::table('dayz_dzsa_pending')->where('server_id', $serverId)->delete();
        } catch (Throwable) {
            // Best-effort cleanup only.
        }
    }

    /**
     * Schedules a one-time timed restart that will send warnings in the global
     * chat and restart the server after `$minutes` minutes.
     *
     * The timed restart runs independently of the recurring schedule: both can
     * be active at the same time and the tick method handles each separately.
     *
     * @return array<string, mixed>
     */
    public function startTimedRestart(mixed $server, int $minutes, string $reason = ''): array
    {
        $serverId = $this->serverIdentifier($server);

        if ($serverId === '') {
            return ['status' => 'failed', 'message' => 'Server identifier unavailable.'];
        }

        if ($minutes < 1 || $minutes > 24 * 60) {
            return ['status' => 'failed', 'message' => 'Restart delay must be between 1 minute and 24 hours.'];
        }

        if (!$this->hasScheduleTable()) {
            return ['status' => 'failed', 'message' => 'Restart schedule storage is not available.'];
        }

        if (!$this->hasScheduleColumn('timed_restart_at')) {
            return ['status' => 'failed', 'message' => 'Run the latest database migration to enable timed restarts.'];
        }

        $timedAt = date('Y-m-d H:i:s', time() + ($minutes * 60));

        // Pre-mark warning intervals that are already irrelevant for this restart
        // delay (i.e., M >= $minutes) so the first tick does not fire them all at
        // once. The immediate announcement below already covers the "restart in N
        // minutes" message; shorter-interval warnings will fire at the right time.
        $alreadySent = array_values(array_filter(
            self::RESTART_WARNINGS_MINUTES,
            static fn (int $m): bool => $m >= $minutes,
        ));

        $update = [
            'timed_restart_at'    => $timedAt,
            'timed_warnings_sent' => implode(',', $alreadySent),
            'updated_at'          => date('Y-m-d H:i:s'),
            'created_at'          => date('Y-m-d H:i:s'),
        ];

        try {
            \Illuminate\Support\Facades\DB::table('dayz_restart_schedules')->updateOrInsert(
                ['server_id' => $serverId],
                $update,
            );
        } catch (Throwable) {
            return ['status' => 'failed', 'message' => 'Failed to save timed restart.'];
        }

        // Announce immediately to global chat.
        $announcement = 'Server will restart in ' . $this->formatMinutes($minutes) . '.';

        if ($reason !== '') {
            $announcement .= ' Reason: ' . $reason;
        }

        $this->gateway->sendCommand($server, "say -1 <t color='#ff0000'>" . $announcement . '</t>');

        return [
            'status'           => 'scheduled',
            'timed_restart_at' => $timedAt,
            'minutes'          => $minutes,
            'message'          => sprintf('Server will restart in %s.', $this->formatMinutes($minutes)),
        ];
    }

    /**
     * Cancels a pending one-time timed restart.
     *
     * @return array<string, mixed>
     */
    public function cancelTimedRestart(mixed $server): array
    {
        $serverId = $this->serverIdentifier($server);

        if ($serverId === '' || !$this->hasScheduleTable() || !$this->hasScheduleColumn('timed_restart_at')) {
            return ['status' => 'failed', 'message' => 'No timed restart is active.'];
        }

        // Announce cancellation in global chat before clearing the schedule.
        $this->gateway->sendCommand(
            $server,
            "say -1 <t color='#00ff00'>The scheduled restart has been cancelled.</t>",
        );

        try {
            \Illuminate\Support\Facades\DB::table('dayz_restart_schedules')
                ->where('server_id', $serverId)
                ->update(['timed_restart_at' => null, 'timed_warnings_sent' => '', 'updated_at' => date('Y-m-d H:i:s')]);
        } catch (Throwable) {
            return ['status' => 'failed', 'message' => 'Failed to cancel timed restart.'];
        }

        return ['status' => 'cancelled', 'message' => 'Timed restart cancelled.'];
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
    private function storeScheduleTick(string $serverId, array $schedule, int $interval, int $next, array $warningsSent, bool $enabled): void
    {
        if ($serverId === '' || !$this->hasScheduleTable()) {
            return;
        }

        $update = [
            'enabled' => $enabled ? 1 : 0,
            'interval_minutes' => $interval,
            'next_restart_at' => date('Y-m-d H:i:s', $next),
            'warnings_sent' => implode(',', $warningsSent),
            'updated_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ];

        if ($this->hasScheduleColumn('warning_minutes_enabled')) {
            $update['warning_minutes_enabled'] = implode(',', $this->enabledWarningMinutes($schedule));
        }

        if ($this->hasScheduleColumn('warning_messages')) {
            $encoded = json_encode($this->warningMessages($schedule));
            $update['warning_messages'] = is_string($encoded) ? $encoded : null;
        }

        try {
            \Illuminate\Support\Facades\DB::table('dayz_restart_schedules')->updateOrInsert(
                ['server_id' => $serverId],
                $update,
            );
        } catch (Throwable) {
            // Best effort.
        }
    }

    /**
     * @param list<int> $timedWarningsSent
     */
    private function storeTimedWarnings(string $serverId, array $timedWarningsSent): void
    {
        if ($serverId === '' || !$this->hasScheduleTable() || !$this->hasScheduleColumn('timed_warnings_sent')) {
            return;
        }

        try {
            \Illuminate\Support\Facades\DB::table('dayz_restart_schedules')
                ->where('server_id', $serverId)
                ->update(['timed_warnings_sent' => implode(',', $timedWarningsSent), 'updated_at' => date('Y-m-d H:i:s')]);
        } catch (Throwable) {
            // Best effort.
        }
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function clearTimedRestart(string $serverId): void
    {
        if ($serverId === '' || !$this->hasScheduleTable() || !$this->hasScheduleColumn('timed_restart_at')) {
            return;
        }

        try {
            \Illuminate\Support\Facades\DB::table('dayz_restart_schedules')
                ->where('server_id', $serverId)
                ->update(['timed_restart_at' => null, 'timed_warnings_sent' => '', 'updated_at' => date('Y-m-d H:i:s')]);
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

    private function hasScheduleColumn(string $column): bool
    {
        if (!class_exists('Illuminate\\Support\\Facades\\Schema')) {
            return false;
        }

        try {
            return \Illuminate\Support\Facades\Schema::hasColumn('dayz_restart_schedules', $column);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $schedule
     * @return list<int>
     */
    private function enabledWarningMinutes(array $schedule): array
    {
        return $this->normalizeWarningMinutes($this->parseWarnings((string) ($schedule['warning_minutes_enabled'] ?? '')));
    }

    /**
     * @param array<string, mixed> $schedule
     * @return array<string, string>
     */
    private function warningMessages(array $schedule): array
    {
        $raw = (string) ($schedule['warning_messages'] ?? '');

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return [];
        }

        return $this->normalizeWarningMessages($decoded);
    }

    /**
     * @param list<int> $minutes
     * @return list<int>
     */
    private function normalizeWarningMinutes(array $minutes): array
    {
        $allowed = array_flip(self::RESTART_WARNINGS_MINUTES);
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (mixed $minute): int => (int) $minute,
            $minutes,
        ), static function (int $minute) use ($allowed): bool {
            return isset($allowed[$minute]);
        })));
        rsort($normalized);

        return $normalized === [] ? self::RESTART_WARNINGS_MINUTES : $normalized;
    }

    /**
     * @param array<string, mixed> $messages
     * @return array<string, string>
     */
    private function normalizeWarningMessages(array $messages): array
    {
        $allowed = array_flip(self::RESTART_WARNINGS_MINUTES);
        $normalized = [];

        foreach ($messages as $key => $message) {
            $minute = (int) $key;

            if (!isset($allowed[$minute])) {
                continue;
            }

            $value = trim((string) $message);

            if ($value === '') {
                continue;
            }

            $normalized[(string) $minute] = function_exists('mb_substr')
                ? mb_substr($value, 0, 240)
                : substr($value, 0, 240);
        }

        return $normalized;
    }

    /**
     * @param array<string, string> $customMessages
     */
    private function warningMessage(int $minutes, array $customMessages): string
    {
        $template = $customMessages[(string) $minutes] ?? 'Server restart in {time}.';
        $message = str_replace('{time}', $this->formatMinutes($minutes), $template);

        if (preg_match('/<t\b/i', $message) !== 1) {
            $message = "<t color='#ff0000'>" . $message . '</t>';
        }

        return $message;
    }
}
