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
    private const LAUNCH_CACHE_SECONDS = 120;

    public function __construct(
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
        private readonly DayZStartupService $startup = new DayZStartupService(),
        private readonly DayZServerContext $context = new DayZServerContext(),
        private readonly DayZStaleCacheService $staleCache = new DayZStaleCacheService(),
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

        // Manual restarts count towards "Last restart" so the server page always
        // reflects when the server was actually last cycled by the panel.
        if ($dispatched && in_array($signal, ['restart', 'start'], true)) {
            $this->storeLastRestart($this->serverIdentifier($server), time());
        }

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
                'start_time' => '',
                'next_restart_at' => null,
                'next_restart_at_display' => null,
                'last_restart_at' => null,
                'last_restart_at_display' => null,
                'timed_restart_at' => null,
                'timed_restart_at_display' => null,
                'warnings_sent' => [],
                'warning_minutes_enabled' => self::RESTART_WARNINGS_MINUTES,
                'warning_messages' => [],
            ];
        }

        $nextRestartAt = (string) ($schedule['next_restart_at'] ?? '');
        $lastRestartAt = $this->hasScheduleColumn('last_restart_at')
            ? (string) ($schedule['last_restart_at'] ?? '')
            : '';
        $timedRestartAt = $this->hasScheduleColumn('timed_restart_at')
            ? ($schedule['timed_restart_at'] ?? null)
            : null;

        return [
            'enabled' => (bool) ($schedule['enabled'] ?? false),
            'interval_minutes' => (int) ($schedule['interval_minutes'] ?? 0),
            'start_time' => $this->normalizeStartTime((string) ($schedule['start_time'] ?? '')),
            'next_restart_at' => $nextRestartAt,
            'next_restart_at_display' => $this->displayTime($nextRestartAt),
            'last_restart_at' => $lastRestartAt === '' ? null : $lastRestartAt,
            'last_restart_at_display' => $this->displayTime($lastRestartAt),
            'timed_restart_at' => $timedRestartAt,
            'timed_restart_at_display' => $this->displayTime((string) ($timedRestartAt ?? '')),
            'warnings_sent' => $this->parseWarnings((string) ($schedule['warnings_sent'] ?? '')),
            'warning_minutes_enabled' => $this->enabledWarningMinutes($schedule),
            'warning_messages' => $this->warningMessages($schedule),
        ];
    }

    /**
     * Formats a stored timestamp for display as `DD-MM-YYYY HH:MM`.
     */
    private function displayTime(string $timestamp): ?string
    {
        $time = trim($timestamp) === '' ? false : strtotime($timestamp);

        return $time === false ? null : date('d-m-Y H:i', $time);
    }

    /**
     * Normalises a `HH:MM` restart start time; an empty string means the
     * schedule is not anchored to a clock time.
     */
    private function normalizeStartTime(string $startTime): string
    {
        $startTime = trim($startTime);

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $startTime, $match) !== 1) {
            return '';
        }

        $hours = (int) $match[1];
        $minutes = (int) $match[2];

        if ($hours > 23 || $minutes > 59) {
            return '';
        }

        return sprintf('%02d:%02d', $hours, $minutes);
    }

    /**
     * The first restart moment strictly after `$after` for a schedule that
     * starts at `$startTime` and repeats every `$intervalMinutes`.
     *
     * Anchoring to the configured clock time is what keeps restarts on the
     * exact times the operator asked for (for example 04:00, 10:00, 16:00,
     * 22:00 for a six-hour schedule starting at 04:00) instead of drifting
     * away from them by however long each restart or tick took.
     */
    private function nextRestartTimestamp(string $startTime, int $intervalMinutes, int $after): int
    {
        $intervalSeconds = max(60, $intervalMinutes * 60);
        $startTime = $this->normalizeStartTime($startTime);

        if ($startTime === '') {
            return $after + $intervalSeconds;
        }

        [$hours, $minutes] = array_map('intval', explode(':', $startTime));
        $anchor = mktime($hours, $minutes, 0, (int) date('n', $after), (int) date('j', $after), (int) date('Y', $after));

        if ($anchor === false) {
            return $after + $intervalSeconds;
        }

        // Step back a whole day first so an anchor later today is not skipped,
        // then forward in whole intervals until the slot is in the future.
        $anchor -= 86400;

        while ($anchor <= $after) {
            $anchor += $intervalSeconds;
        }

        return $anchor;
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
        string $startTime = '',
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

        $startTime = $this->normalizeStartTime($startTime);
        $now = time();
        $nextTimestamp = $enabled ? $this->nextRestartTimestamp($startTime, $intervalMinutes, $now) : null;
        $nextRestart = $nextTimestamp === null ? null : date('Y-m-d H:i:s', $nextTimestamp);
        $warningMinutesEnabled = $this->normalizeWarningMinutes($warningMinutesEnabled);
        $warningMessages = $this->normalizeWarningMessages($warningMessages);

        // Pre-mark warnings whose window has already opened for the first cycle
        // so the next tick does not fire them all at once. Warnings that are due
        // later in the cycle will still fire at the appropriate times.
        $preFilledWarnings = $nextTimestamp === null ? [] : array_values(array_filter(
            $warningMinutesEnabled,
            static fn (int $m): bool => $now >= ($nextTimestamp - ($m * 60)),
        ));

        $update = [
            'enabled' => $enabled ? 1 : 0,
            'interval_minutes' => $intervalMinutes,
            'next_restart_at' => $nextRestart,
            'warnings_sent' => implode(',', $preFilledWarnings),
            'updated_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ];

        if ($this->hasScheduleColumn('start_time')) {
            $update['start_time'] = $startTime;
        }

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
                    $this->storeLastRestart($serverId, $now);

                    return [
                        'status'          => 'restarted',
                        'messages_sent'   => $results,
                        'next_restart_at' => null,
                        'timed_restart_at' => null,
                        'last_restart_at' => date('Y-m-d H:i:s', $now),
                        'last_restart_at_display' => date('d-m-Y H:i', $now),
                        'interval_minutes' => (int) ($schedule['interval_minutes'] ?? 0),
                        'restarted'       => true,
                    ];
                }

                $timedWarningsSent = $this->parseWarnings((string) ($schedule['timed_warnings_sent'] ?? ''));
                $warning = $this->dueWarning(
                    $timedNext,
                    $now,
                    $this->enabledWarningMinutes($schedule),
                    $timedWarningsSent,
                );

                if ($warning !== null) {
                    $message = $this->warningMessage($warning['minutes'], $this->warningMessages($schedule), $warning['remaining_minutes']);

                    if ($this->gateway->sendCommand($server, 'say -1 ' . $message)) {
                        $results[] = $message;
                        $timedWarningsSent = $warning['sent'];
                    }
                }

                if ($timedWarningsSent !== $this->parseWarnings((string) ($schedule['timed_warnings_sent'] ?? ''))) {
                    $this->storeTimedWarnings($serverId, $timedWarningsSent);
                }
            }
        }

        // ── Recurring scheduled restart ───────────────────────────────────────
        // While a one-time timed restart is pending, the scheduled restart is
        // suppressed so the timed override is the sole authority for when the
        // next restart and its warning messages will fire.
        if ($this->hasScheduleColumn('timed_restart_at')) {
            $pendingTimed = $schedule['timed_restart_at'] ?? null;
            $pendingTimedTs = $pendingTimed !== null ? strtotime((string) $pendingTimed) : false;
            if ($pendingTimedTs !== false && $pendingTimedTs > $now) {
                return [
                    'status'          => $results === [] ? 'idle' : 'warning_sent',
                    'messages_sent'   => $results,
                    'next_restart_at' => (string) ($schedule['next_restart_at'] ?? ''),
                    'interval_minutes' => (int) ($schedule['interval_minutes'] ?? 0),
                    'restarted'       => false,
                ];
            }
        }

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
        $startTime = $this->hasScheduleColumn('start_time')
            ? $this->normalizeStartTime((string) ($schedule['start_time'] ?? ''))
            : '';
        $next = strtotime((string) ($schedule['next_restart_at'] ?? ''));

        if ($next === false) {
            $next = $this->nextRestartTimestamp($startTime, $interval, $now);
        }

        $restarted = false;
        $lastRestart = null;

        if ($now >= $next) {
            // Restart time has passed; restart immediately without sending
            // any retrospective warning messages.
            $this->gateway->sendCommand($server, "say -1 <t color='#ff0000'>Restarting now.</t>");
            $restarted = $this->gateway->power($server, 'restart');
            $lastRestart = $now;

            // Advance in whole intervals (anchored to the configured start time
            // when one is set) until the next slot is in the future, so a late
            // tick cannot leave a due timestamp behind and restart in a loop.
            $next = $this->nextRestartTimestamp($startTime, $interval, max($now, $next));
            $warningsSent = [];
        } else {
            $warningsSent = $this->parseWarnings((string) ($schedule['warnings_sent'] ?? ''));
            $warning = $this->dueWarning($next, $now, $this->enabledWarningMinutes($schedule), $warningsSent);

            if ($warning !== null) {
                $message = $this->warningMessage($warning['minutes'], $this->warningMessages($schedule), $warning['remaining_minutes']);

                if ($this->gateway->sendCommand($server, 'say -1 ' . $message)) {
                    $results[] = $message;
                    $warningsSent = $warning['sent'];
                }
            }
        }

        $this->storeScheduleTick($serverId, $schedule, $interval, $next, $warningsSent, true, $lastRestart);

        $storedLastRestart = $lastRestart !== null
            ? date('Y-m-d H:i:s', $lastRestart)
            : ($this->hasScheduleColumn('last_restart_at') ? (string) ($schedule['last_restart_at'] ?? '') : '');

        return [
            'status'          => $restarted ? 'restarted' : ($results === [] ? 'waiting' : 'warning_sent'),
            'messages_sent'   => $results,
            'next_restart_at' => date('c', $next),
            'next_restart_at_display' => date('d-m-Y H:i', $next),
            'last_restart_at' => $storedLastRestart === '' ? null : $storedLastRestart,
            'last_restart_at_display' => $this->displayTime($storedLastRestart),
            'interval_minutes' => $interval,
            'restarted'       => $restarted,
        ];
    }

    /**
     * Picks the single warning that is due right now.
     *
     * Every warning whose window has already opened is marked as sent, but only
     * the most relevant (smallest remaining time) one is announced. That keeps
     * the countdown accurate and avoids a burst of stale "restart in 3 hours"
     * style messages when ticks resume after a gap.
     *
     * The returned `remaining_minutes` equals the threshold key (`minutes`) so
     * the in-game message always reads the configured value (e.g. "2 hours") and
     * is never off by a minute due to scheduler tick timing drift.
     *
     * @param list<int> $enabledMinutes
     * @param list<int> $alreadySent
     * @return array{minutes: int, remaining_minutes: int, sent: list<int>}|null
     */
    private function dueWarning(int $next, int $now, array $enabledMinutes, array $alreadySent): ?array
    {
        $due = array_values(array_filter(
            $enabledMinutes,
            static fn (int $minutes): bool => $now >= ($next - ($minutes * 60))
                && !in_array($minutes, $alreadySent, true),
        ));

        if ($due === []) {
            return null;
        }

        sort($due);

        // Use the threshold key as the display value so that minor scheduler
        // tick drift (a few seconds late) never causes the message to read
        // "1 hour 59 min" instead of "2 hours", etc.
        return [
            'minutes'          => $due[0],
            'remaining_minutes' => $due[0],
            'sent'             => array_values(array_unique(array_merge($alreadySent, $due))),
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
        //
        // Additionally, if the recurring schedule has a next_restart_at that is
        // sooner than the timed delay, also pre-mark thresholds covered by that
        // proximity: those warnings would have been sent by the scheduled path and
        // the timed override should start its countdown from where the schedule
        // left off.
        $schedule = $this->scheduleRow($serverId);
        $scheduledNextTs = $schedule !== null
            ? strtotime((string) ($schedule['next_restart_at'] ?? ''))
            : false;
        $minutesUntilScheduled = ($scheduledNextTs !== false && $scheduledNextTs > time())
            ? (int) ceil(($scheduledNextTs - time()) / 60)
            : 0;

        $alreadySent = array_values(array_filter(
            self::RESTART_WARNINGS_MINUTES,
            static fn (int $m): bool => $m >= $minutes || ($minutesUntilScheduled > 0 && $m >= $minutesUntilScheduled),
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
            'timed_restart_at_display' => $this->displayTime($timedAt),
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
        $cacheKey = 'pteromods.dayz.server.launch.' . md5($this->serverIdentifier($server));
        $fallback = [
            'launch_parameters' => (new LaunchParameterBuilder())->build($enabledFolders),
            'mod_count' => count(array_filter($enabledFolders, static fn (string $f): bool => $f !== '')),
            'startup_raw' => '',
            'startup_rendered' => '',
            'startup_parameters' => [],
            'startup_variables' => [],
            'startup_source' => 'unavailable',
            'mods' => [],
            'server_mods' => [],
            'restart_schedule' => [
                'enabled' => false,
                'interval_minutes' => 0,
                'start_time' => '',
                'next_restart_at' => null,
                'next_restart_at_display' => null,
                'last_restart_at' => null,
                'last_restart_at_display' => null,
                'timed_restart_at' => null,
                'timed_restart_at_display' => null,
                'warnings_sent' => [],
                'warning_minutes_enabled' => self::RESTART_WARNINGS_MINUTES,
                'warning_messages' => [],
            ],
        ];

        $payload = $this->staleCache->remember(
            $cacheKey,
            self::LAUNCH_CACHE_SECONDS,
            self::LAUNCH_CACHE_SECONDS * 20,
            function () use ($server, $enabledFolders): array {
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
            },
            $fallback,
        );

        $payload = is_array($payload) ? $payload : $fallback;

        // The restart schedule is a single cheap row read and must never be
        // served stale, otherwise the page shows an outdated next/last restart.
        $payload['restart_schedule'] = $this->restartSchedule($server);

        return $payload;
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
    private function storeScheduleTick(string $serverId, array $schedule, int $interval, int $next, array $warningsSent, bool $enabled, ?int $lastRestart = null): void
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

        if ($this->hasScheduleColumn('start_time')) {
            $update['start_time'] = $this->normalizeStartTime((string) ($schedule['start_time'] ?? ''));
        }

        if ($lastRestart !== null && $this->hasScheduleColumn('last_restart_at')) {
            $update['last_restart_at'] = date('Y-m-d H:i:s', $lastRestart);
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
     * Records the moment the server was last restarted by the panel.
     */
    private function storeLastRestart(string $serverId, int $timestamp): void
    {
        if ($serverId === '' || !$this->hasScheduleTable() || !$this->hasScheduleColumn('last_restart_at')) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        try {
            $updated = \Illuminate\Support\Facades\DB::table('dayz_restart_schedules')
                ->where('server_id', $serverId)
                ->update(['last_restart_at' => date('Y-m-d H:i:s', $timestamp), 'updated_at' => $now]);

            if ($updated === 0) {
                // No schedule row yet: insert a complete disabled row so every
                // NOT NULL column gets a sane value under strict SQL modes.
                \Illuminate\Support\Facades\DB::table('dayz_restart_schedules')->insert([
                    'server_id' => $serverId,
                    'enabled' => 0,
                    'interval_minutes' => 360,
                    'next_restart_at' => null,
                    'last_restart_at' => date('Y-m-d H:i:s', $timestamp),
                    'warnings_sent' => '',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
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
     * @param int                   $minutes         Warning threshold key (used for custom message lookup).
     * @param array<string, string> $customMessages  Map of threshold → template string.
     * @param int|null              $remainingMinutes Actual remaining minutes to the restart;
     *                                               when provided it replaces the threshold
     *                                               value in the {time} placeholder so the
     *                                               in-game countdown is always accurate.
     */
    private function warningMessage(int $minutes, array $customMessages, ?int $remainingMinutes = null): string
    {
        $displayMinutes = $remainingMinutes ?? $minutes;
        $template = $customMessages[(string) $minutes] ?? 'Server restart in {time}.';
        $message = str_replace('{time}', $this->formatMinutes($displayMinutes), $template);

        if (preg_match('/<t\b/i', $message) !== 1) {
            $message = "<t color='#ff0000'>" . $message . '</t>';
        }

        return $message;
    }
}
