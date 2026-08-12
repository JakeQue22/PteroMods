<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use Throwable;

/**
 * Persists and serves observed player activity for a DayZ server.
 */
final class DayZObservedPlayerService
{
    public function __construct(
        private readonly DayZServerContext $context = new DayZServerContext(),
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
    ) {
    }

    /**
     * @param list<array<string, mixed>> $players
     */
    public function trackSnapshot(mixed $server, string $mapName, array $players): void
    {
        $serverId = $this->serverId($server);

        if ($serverId === '' || $players === [] || !$this->tableExists()) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        foreach ($players as $player) {
            $playerId = trim((string) ($player['steam64'] ?? $player['player_uid'] ?? ''));

            if ($playerId === '') {
                continue;
            }

            // real_steam64 is set by normalizePlayers() when the identifier is
            // a genuine 17-digit Steam ID, and stored separately from player_id.
            $realSteam64 = isset($player['real_steam64']) && preg_match('/^\d{17}$/', (string) $player['real_steam64']) === 1
                ? (string) $player['real_steam64']
                : null;

            try {
                $query = \Illuminate\Support\Facades\DB::table('dayz_observed_players')
                    ->where('server_id', $serverId)
                    ->where('player_id', $playerId);
                $exists = $query->exists();
                $payload = [
                    'player_name'     => trim((string) ($player['name'] ?? $playerId)),
                    'last_map'        => $mapName,
                    'last_x'          => $this->floatValue($player['x'] ?? null),
                    'last_y'          => $this->floatValue($player['y'] ?? null),
                    'last_z'          => $this->floatValue($player['z'] ?? null),
                    'last_direction'  => $this->floatValue($player['direction'] ?? null),
                    'last_alive'      => (bool) ($player['alive'] ?? true),
                    'last_health'     => $this->floatValue($player['health'] ?? null),
                    'inventory_json'  => $this->encodeJson($player['inventory'] ?? null),
                    'metadata_json'   => $this->encodeJson($player['metadata'] ?? null),
                    'last_seen_at'    => $now,
                    'updated_at'      => $now,
                ];

                if ($exists) {
                    $query->update($payload);
                } else {
                    \Illuminate\Support\Facades\DB::table('dayz_observed_players')->insert($payload + [
                        'server_id'     => $serverId,
                        'player_id'     => $playerId,
                        'first_seen_at' => $now,
                        'created_at'    => $now,
                    ]);
                }
            } catch (Throwable) {
                // Best-effort tracking only.
                continue;
            }

            // Store the real Steam64 in its own column (added by a later migration).
            // This is a separate best-effort update so a missing column never
            // blocks the primary player-tracking write above.
            if ($realSteam64 !== null) {
                try {
                    \Illuminate\Support\Facades\DB::table('dayz_observed_players')
                        ->where('server_id', $serverId)
                        ->where('player_id', $playerId)
                        ->update(['steam64' => $realSteam64]);
                } catch (Throwable) {
                    // Column may not exist on older installs without the migration.
                }
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $livePlayers
     * @return list<array<string, mixed>>
     */
    public function activity(mixed $server, array $livePlayers = []): array
    {
        $serverId = $this->serverId($server);

        if ($serverId === '') {
            return [];
        }

        $liveIndex = [];

        foreach ($livePlayers as $player) {
            if (!is_array($player)) {
                continue;
            }

            $playerId = trim((string) ($player['steam64'] ?? ''));

            if ($playerId === '') {
                continue;
            }

            $liveIndex[$playerId] = $player;
        }

        $rows = [];

        if ($this->tableExists()) {
            try {
                $rows = \Illuminate\Support\Facades\DB::table('dayz_observed_players')
                    ->where('server_id', $serverId)
                    ->orderByDesc('last_seen_at')
                    ->orderBy('player_name')
                    ->get()
                    ->all();
            } catch (Throwable) {
                $rows = [];
            }
        }

        $players = [];

        foreach ($rows as $row) {
            $record = (array) $row;
            $playerId = trim((string) ($record['player_id'] ?? ''));

            if ($playerId === '') {
                continue;
            }

            $live = $liveIndex[$playerId] ?? [];

            // steam64 column was added in a later migration; fall back to null when absent.
            $dbSteam64 = isset($record['steam64']) && preg_match('/^\d{17}$/', (string) $record['steam64']) === 1
                ? (string) $record['steam64']
                : null;
            // Live snapshot may carry a real_steam64 resolved from persistence.
            $liveSteam64 = isset($live['real_steam64']) && preg_match('/^\d{17}$/', (string) $live['real_steam64']) === 1
                ? (string) $live['real_steam64']
                : null;
            $steam64 = $liveSteam64 ?? $dbSteam64;

            $rawHealth = $this->coalesceFloat($live['health'] ?? null, $record['last_health'] ?? null);
            $health = $rawHealth === null ? null : ($rawHealth > 100 ? round($rawHealth / 100, 2) : $rawHealth);

            $players[$playerId] = [
                'player_id'      => $playerId,
                'steam64'        => $steam64,
                'name'           => trim((string) (($live['name'] ?? null) ?: ($record['player_name'] ?? $playerId))),
                'map'            => trim((string) (($record['last_map'] ?? '') ?: ($live['map'] ?? ''))),
                'x'              => $this->coalesceFloat($live['x'] ?? null, $record['last_x'] ?? null),
                'y'              => $this->coalesceFloat($live['y'] ?? null, $record['last_y'] ?? null),
                'z'              => $this->coalesceFloat($live['z'] ?? null, $record['last_z'] ?? null),
                'direction'      => $this->coalesceFloat($live['direction'] ?? null, $record['last_direction'] ?? null),
                'alive'          => array_key_exists($playerId, $liveIndex)
                    ? (bool) ($live['alive'] ?? true)
                    : (bool) ($record['last_alive'] ?? false),
                'health'         => $health,
                'online'         => array_key_exists($playerId, $liveIndex),
                'first_seen_at'  => isset($record['first_seen_at']) ? (string) $record['first_seen_at'] : null,
                'last_seen_at'   => isset($record['last_seen_at']) ? (string) $record['last_seen_at'] : null,
                'inventory'      => $this->decodeJson(($live['inventory'] ?? null) !== null ? $live['inventory'] : ($record['inventory_json'] ?? null)),
                'metadata'       => $this->decodeJson(($live['metadata'] ?? null) !== null ? $live['metadata'] : ($record['metadata_json'] ?? null)),
            ];
        }

        foreach ($liveIndex as $playerId => $live) {
            if (isset($players[$playerId])) {
                continue;
            }

            $liveSteam64 = isset($live['real_steam64']) && preg_match('/^\d{17}$/', (string) $live['real_steam64']) === 1
                ? (string) $live['real_steam64']
                : null;
            $rawHealth = $this->floatValue($live['health'] ?? null);
            $health = $rawHealth === null ? null : ($rawHealth > 100 ? round($rawHealth / 100, 2) : $rawHealth);

            $players[$playerId] = [
                'player_id'      => $playerId,
                'steam64'        => $liveSteam64,
                'name'           => trim((string) (($live['name'] ?? null) ?: $playerId)),
                'map'            => trim((string) ($live['map'] ?? '')),
                'x'              => $this->floatValue($live['x'] ?? null),
                'y'              => $this->floatValue($live['y'] ?? null),
                'z'              => $this->floatValue($live['z'] ?? null),
                'direction'      => $this->floatValue($live['direction'] ?? null),
                'alive'          => (bool) ($live['alive'] ?? true),
                'health'         => $health,
                'online'         => true,
                'first_seen_at'  => null,
                'last_seen_at'   => null,
                'inventory'      => $this->decodeJson($live['inventory'] ?? null),
                'metadata'       => $this->decodeJson($live['metadata'] ?? null),
            ];
        }

        usort($players, static function (array $left, array $right): int {
            $online = ((int) ($right['online'] ?? false)) <=> ((int) ($left['online'] ?? false));

            if ($online !== 0) {
                return $online;
            }

            $rightSeen = strtotime((string) ($right['last_seen_at'] ?? '')) ?: 0;
            $leftSeen = strtotime((string) ($left['last_seen_at'] ?? '')) ?: 0;

            if ($rightSeen !== $leftSeen) {
                return $rightSeen <=> $leftSeen;
            }

            return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });

        return array_values($players);
    }

    /**
     * Deletes a player's tracked record from the observed players table, resetting
     * all stored data (name, position, health, inventory, seen timestamps).
     * The record is recreated automatically the next time the player comes online.
     *
     * @return array<string, mixed>
     */
    public function reset(mixed $server, string $playerId): array
    {
        $playerId = trim($playerId);

        if ($playerId === '') {
            return ['status' => 'error', 'message' => 'Player ID is required.'];
        }

        $serverId = $this->serverId($server);

        if ($serverId === '' || !$this->tableExists()) {
            return ['status' => 'error', 'message' => 'Player tracking table is unavailable.'];
        }

        try {
            $deleted = \Illuminate\Support\Facades\DB::table('dayz_observed_players')
                ->where('server_id', $serverId)
                ->where('player_id', $playerId)
                ->delete();

            return [
                'status'    => 'reset',
                'player_id' => $playerId,
                'message'   => $deleted > 0
                    ? 'Player data has been reset. They will reappear once they next connect.'
                    : 'No tracked record found for that player.',
            ];
        } catch (Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function kick(mixed $server, string $playerId): array
    {
        $playerId = trim($playerId);

        if ($playerId === '') {
            return ['status' => 'error', 'message' => 'Player ID is required.'];
        }

        $dispatched = $this->gateway->sendCommand($server, 'kick ' . $playerId);

        return [
            'status'    => $dispatched ? 'dispatched' : 'failed',
            'player_id' => $playerId,
            'message'   => $dispatched
                ? 'Kick command sent to the server console.'
                : 'The server did not accept the kick command.',
        ];
    }

    private function serverId(mixed $server): string
    {
        return $this->context->attribute($server, ['uuid', 'uuidShort', 'id']);
    }

    private function tableExists(): bool
    {
        if (!class_exists('Illuminate\\Support\\Facades\\Schema')) {
            return false;
        }

        try {
            return \Illuminate\Support\Facades\Schema::hasTable('dayz_observed_players');
        } catch (Throwable) {
            return false;
        }
    }

    private function floatValue(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function coalesceFloat(mixed $preferred, mixed $fallback): ?float
    {
        $value = $this->floatValue($preferred);

        return $value ?? $this->floatValue($fallback);
    }

    private function encodeJson(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            return trim($value) === '' ? null : $value;
        }

        try {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            return is_string($encoded) ? $encoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function decodeJson(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}
