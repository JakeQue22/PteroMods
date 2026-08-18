<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use Throwable;

/**
 * Appends panel-originated administrative actions to the DayZ admin logs.
 *
 * Lines are written to a dedicated daily file inside the VPP Admin Tools
 * logging directory (`/profiles/VPPAdminTools/Logging`) so they appear in the
 * Admin Logs block of the Logs tab next to the in-game admin actions, and every
 * line records the panel username that performed the action.
 */
final class DayZAdminActionLogService
{
    private const LOG_DIRECTORY = '/profiles/VPPAdminTools/Logging';

    public function __construct(
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
    ) {
    }

    /**
     * Appends a single admin action line.
     *
     * @param array<string, mixed> $details
     */
    public function log(mixed $server, string $action, string $actor = '', string $playerName = '', string $playerId = '', array $details = []): bool
    {
        if ($server === null || trim($action) === '') {
            return false;
        }

        $line = $this->formatLine($action, $actor, $playerName, $playerId, $details);
        $path = $this->logPath();

        try {
            $existing = $this->gateway->readFileFresh($server, $path) ?? '';
            $content = $existing === '' ? $line . "\n" : rtrim($existing, "\r\n") . "\n" . $line . "\n";

            return $this->gateway->writeFile($server, $path, $content);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $details
     */
    private function formatLine(string $action, string $actor, string $playerName, string $playerId, array $details): string
    {
        $parts = [
            sprintf('[ %s | %s ]', date('H:i:s'), date('Y-m-d')),
            'PteroMods Panel',
            'Admin: ' . ($this->clean($actor) !== '' ? $this->clean($actor) : 'unknown panel user'),
            'Action: ' . $this->clean($action),
        ];

        $player = $this->clean($playerName);
        $identifier = $this->clean($playerId);

        if ($player !== '' || $identifier !== '') {
            $parts[] = 'Player: ' . trim($player . ($identifier !== '' ? ' (' . $identifier . ')' : ''));
        }

        foreach ($details as $key => $value) {
            if (is_scalar($value) && $this->clean((string) $value) !== '') {
                $parts[] = $this->clean((string) $key) . ': ' . $this->clean((string) $value);
            }
        }

        return implode(' | ', $parts);
    }

    private function logPath(): string
    {
        return self::LOG_DIRECTORY . '/PteroModsPanel_' . date('Y-m-d') . '.log';
    }

    private function clean(string $value): string
    {
        $value = str_replace(["\r", "\n", '|'], ' ', $value);

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
