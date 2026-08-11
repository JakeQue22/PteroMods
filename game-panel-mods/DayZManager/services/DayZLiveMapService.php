<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

/**
 * Supplies transient live-map player snapshots from server-side bridge files.
 */
final class DayZLiveMapService
{
    private const BRIDGE_FILES = [
        '/profiles/PteroMods/live_map_players.json',
        '/profiles/live_map_players.json',
    ];

    public function __construct(
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
        private readonly DayZServerQueryService $query = new DayZServerQueryService(),
        private readonly DayZManagerSettingsService $settings = new DayZManagerSettingsService(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(mixed $server): array
    {
        $raw = null;
        $source = '';

        foreach (self::BRIDGE_FILES as $path) {
            $raw = $this->gateway->readFile($server, $path);

            if (is_string($raw) && trim($raw) !== '') {
                $source = $path;
                break;
            }
        }

        $data = $this->decodeSnapshot($raw, $server);
        $query = $this->query->query($server);
        $mapName = $this->resolveMapName($data, $query);
        $players = $this->normalizePlayers(is_array($data) ? ($data['players'] ?? []) : []);
        $updatedAt = $this->normalizeUpdatedAt(is_array($data) ? ($data['updated_at'] ?? '') : '');

        $status = $source === '' ? 'waiting_for_bridge' : (is_array($data) ? 'ok' : 'invalid_bridge_payload');

        return [
            'status' => $status,
            'source' => $source,
            'map' => $mapName,
            'map_definition' => $this->mapDefinition($mapName),
            'players' => $players,
            'online_count' => count($players),
            'last_update' => $updatedAt,
            'query_online' => (bool) ($query['online'] ?? false),
            'query_player_count' => (int) (($query['players'] ?? 0) ?: 0),
            'bridge_format' => [
                'path' => self::BRIDGE_FILES[0],
                'schema' => [
                    'updated_at' => '2026-08-11T11:20:14Z',
                    'map' => 'ChernarusPlus',
                    'players' => [[
                        'steam64' => '76561198000000000',
                        'name' => 'PlayerName',
                        'x' => 7500.0,
                        'y' => 50.0,
                        'z' => 7200.0,
                        'direction' => 180.0,
                        'alive' => true,
                        'health' => 92.5,
                    ]],
                ],
            ],
        ];
    }

    /**
     * @param mixed $raw
     * @return list<array<string, mixed>>
     */
    private function normalizePlayers(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $players = [];

        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $steam64 = trim((string) ($entry['steam64'] ?? $entry['steam_id'] ?? ''));
            $name = trim((string) ($entry['name'] ?? ''));
            $x = $this->floatValue($entry['x'] ?? ($entry['position']['x'] ?? null));
            $y = $this->floatValue($entry['y'] ?? ($entry['position']['y'] ?? null));
            $z = $this->floatValue($entry['z'] ?? ($entry['position']['z'] ?? null));

            if ($steam64 === '' || $name === '' || $x === null || $z === null) {
                continue;
            }

            $players[] = [
                'steam64' => $steam64,
                'name' => $name,
                'x' => $x,
                'y' => $y ?? 0.0,
                'z' => $z,
                'direction' => $this->floatValue($entry['direction'] ?? $entry['yaw'] ?? 0.0) ?? 0.0,
                'alive' => (bool) ($entry['alive'] ?? true),
                'health' => $this->floatValue($entry['health'] ?? null),
            ];
        }

        return $players;
    }

    /**
     * @param array<string, mixed>|null $data
     * @param array<string, mixed> $query
     */
    private function resolveMapName(?array $data, array $query): string
    {
        $map = trim((string) (($data['map'] ?? '') ?: ($query['map'] ?? '')));

        if ($map === '') {
            return 'ChernarusPlus';
        }

        return match (strtolower($map)) {
            'dayzoffline.chernarusplus', 'chernarusplus', 'chernarus' => 'ChernarusPlus',
            'dayzoffline.enoch', 'enoch', 'livonia' => 'Livonia',
            'dayzoffline.sakhal', 'sakhal' => 'Sakhal',
            default => $map,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function mapDefinition(string $map): array
    {
        $definitions = [
            'ChernarusPlus' => ['id' => 'chernarusplus', 'name' => 'ChernarusPlus', 'world_size' => 15360.0],
            'Livonia' => ['id' => 'livonia', 'name' => 'Livonia', 'world_size' => 12800.0],
            'Sakhal' => ['id' => 'sakhal', 'name' => 'Sakhal', 'world_size' => 12800.0],
        ];

        $base = $definitions[$map] ?? ['id' => strtolower($map), 'name' => $map, 'world_size' => 15360.0];

        return $base + [
            'origin' => ['x' => 0.0, 'z' => 0.0],
            'axis' => ['x' => 'east', 'z' => 'south'],
        ];
    }

    private function normalizeUpdatedAt(mixed $value): string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return gmdate('c');
        }

        $timestamp = strtotime($text);

        return $timestamp === false ? gmdate('c') : gmdate('c', $timestamp);
    }

    private function floatValue(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Accepts either a direct payload or a signed envelope:
     * {"payload": {...}, "signature": "hex-hmac-sha256"}.
     *
     * @return array<string, mixed>|null
     */
    private function decodeSnapshot(?string $raw, mixed $server): ?array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return null;
        }

        $secret = trim((string) $this->settings->get('live_map_bridge_secret', ''));

        if (!isset($decoded['payload']) || !is_array($decoded['payload'])) {
            return $decoded;
        }

        if ($secret === '') {
            return $decoded['payload'];
        }

        $signature = trim((string) ($decoded['signature'] ?? ''));

        if ($signature === '') {
            return null;
        }

        $serverId = (new DayZServerContext())->attribute($server, ['uuid', 'uuidShort', 'id']);
        $base = json_encode($decoded['payload'], JSON_UNESCAPED_SLASHES) ?: '';
        $expected = hash_hmac('sha256', $serverId . ':' . $base, $secret);

        return hash_equals($expected, strtolower($signature)) ? $decoded['payload'] : null;
    }
}
