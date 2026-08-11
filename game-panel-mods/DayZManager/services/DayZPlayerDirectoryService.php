<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

/**
 * Merges every source of player information the module has into a single
 * directory that both the player manager and the live map render.
 *
 * Sources, in order of trust for each field:
 *
 *  - the live bridge snapshot (online players: Steam64, nickname, position);
 *  - the observed-player table (previously seen Steam64 / nickname pairs);
 *  - the persistence databases (`players.db` identity, `characters.db` state).
 *
 * Records are keyed by Steam64 when it is known, otherwise by the DayZ /
 * Bohemia UID used by the persistence database; a persisted record without a
 * Steam64 is matched to an observed one by nickname so the panel can still
 * show and act on the correct Steam64.
 */
final class DayZPlayerDirectoryService
{
    public function __construct(
        private readonly DayZPersistencePlayerService $persistence = new DayZPersistencePlayerService(),
        private readonly DayZObservedPlayerService $observed = new DayZObservedPlayerService(),
        private readonly DayZPlayerService $lists = new DayZPlayerService(),
    ) {
    }

    /**
     * @param list<array<string, mixed>> $livePlayers
     * @return array{status: string, source_path: string|null, source_paths: list<string>, players: list<array<string, mixed>>}
     */
    public function directory(mixed $server, string $mapName = 'ChernarusPlus', array $livePlayers = []): array
    {
        $persisted = $this->persistence->snapshot($server, $mapName);
        $observed = $this->observed->activity($server, $livePlayers);
        $listFlags = $this->listFlags();

        $players = [];
        $byName = [];

        foreach ($observed as $entry) {
            $steam64 = trim((string) ($entry['steam64'] ?? ''));

            if ($steam64 === '') {
                continue;
            }

            $players[$steam64] = [
                'id' => $steam64,
                'steam64' => $steam64,
                'player_id' => $steam64,
                'player_uid' => null,
                'name' => trim((string) ($entry['name'] ?? '')) ?: $steam64,
                'online' => (bool) ($entry['online'] ?? false),
                'alive' => $entry['alive'] ?? null,
                'health' => $entry['health'] ?? null,
                'x' => $entry['x'] ?? null,
                'y' => $entry['y'] ?? null,
                'z' => $entry['z'] ?? null,
                'position_status' => ($entry['x'] ?? null) === null ? 'missing' : 'valid',
                'direction' => $entry['direction'] ?? null,
                'first_seen_at' => $entry['first_seen_at'] ?? null,
                'last_seen_at' => $entry['last_seen_at'] ?? null,
                'map' => $entry['map'] ?? $mapName,
                'sources' => ['observed'],
            ];

            $key = $this->nameKey((string) ($entry['name'] ?? ''));

            if ($key !== '') {
                $byName[$key] ??= $steam64;
            }
        }

        // Online players are always listed, even when the observed-player
        // table is unavailable (fresh install, missing migration).
        foreach ($livePlayers as $live) {
            $steam64 = trim((string) (is_array($live) ? ($live['steam64'] ?? '') : ''));

            if ($steam64 === '') {
                continue;
            }

            $existing = $players[$steam64] ?? null;
            $players[$steam64] = [
                'id' => $steam64,
                'steam64' => $steam64,
                'player_id' => $steam64,
                'player_uid' => $existing['player_uid'] ?? null,
                'name' => trim((string) ($live['name'] ?? '')) ?: (string) ($existing['name'] ?? $steam64),
                'online' => true,
                'alive' => $live['alive'] ?? ($existing['alive'] ?? null),
                'health' => $live['health'] ?? ($existing['health'] ?? null),
                'x' => $live['x'] ?? ($existing['x'] ?? null),
                'y' => $live['y'] ?? ($existing['y'] ?? null),
                'z' => $live['z'] ?? ($existing['z'] ?? null),
                'position_status' => ($live['x'] ?? null) === null ? (string) ($existing['position_status'] ?? 'missing') : 'valid',
                'direction' => $live['direction'] ?? ($existing['direction'] ?? null),
                'first_seen_at' => $existing['first_seen_at'] ?? null,
                'last_seen_at' => $existing['last_seen_at'] ?? null,
                'map' => $existing['map'] ?? $mapName,
                'sources' => array_values(array_unique(array_merge(
                    is_array($existing['sources'] ?? null) ? $existing['sources'] : [],
                    ['live'],
                ))),
            ];

            $key = $this->nameKey((string) ($live['name'] ?? ''));

            if ($key !== '') {
                $byName[$key] ??= $steam64;
            }
        }

        foreach ($persisted['players'] ?? [] as $record) {
            $uid = trim((string) ($record['player_id'] ?? ''));
            $steam64 = trim((string) ($record['steam64'] ?? ''));
            $nameKey = $this->nameKey((string) ($record['name'] ?? ''));

            if ($steam64 === '' && !empty($record['has_name']) && isset($byName[$nameKey])) {
                $steam64 = $byName[$nameKey];
            }

            $key = $steam64 !== '' ? $steam64 : $uid;

            if ($key === '') {
                continue;
            }

            $existing = $players[$key] ?? null;
            $players[$key] = [
                'id' => $key,
                'steam64' => $steam64 !== '' ? $steam64 : null,
                'player_id' => $uid !== '' ? $uid : ($existing['player_id'] ?? $key),
                'player_uid' => $uid !== '' && $uid !== $steam64 ? $uid : ($existing['player_uid'] ?? null),
                'name' => !empty($record['has_name'])
                    ? (string) $record['name']
                    : (string) ($existing['name'] ?? $record['name'] ?? $key),
                'online' => (bool) ($existing['online'] ?? false),
                'alive' => $existing['alive'] ?? $record['alive'] ?? null,
                'health' => $existing['health'] ?? null,
                'x' => $existing['x'] ?? $record['x'] ?? null,
                'y' => $existing['y'] ?? $record['y'] ?? null,
                'z' => $existing['z'] ?? $record['z'] ?? null,
                'position_status' => $existing !== null && ($existing['position_status'] ?? '') === 'valid'
                    ? 'valid'
                    : (string) ($record['position_status'] ?? 'missing'),
                'direction' => $existing['direction'] ?? null,
                'first_seen_at' => $existing['first_seen_at'] ?? null,
                'last_seen_at' => $this->latest($existing['last_seen_at'] ?? null, $record['last_seen_at'] ?? null),
                'map' => $existing['map'] ?? $mapName,
                'sources' => array_values(array_unique(array_merge(
                    is_array($existing['sources'] ?? null) ? $existing['sources'] : [],
                    is_array($record['source_paths'] ?? null) ? $record['source_paths'] : [],
                ))),
            ];
        }

        foreach ($players as $key => $player) {
            $players[$key]['position_valid'] = ($player['position_status'] ?? '') === 'valid';
            $players[$key]['lists'] = [
                'ban' => isset($listFlags['ban'][$key]) || isset($listFlags['ban'][(string) ($player['player_id'] ?? '')]),
                'whitelist' => isset($listFlags['whitelist'][$key]) || isset($listFlags['whitelist'][(string) ($player['player_id'] ?? '')]),
                'priority' => isset($listFlags['priority'][$key]) || isset($listFlags['priority'][(string) ($player['player_id'] ?? '')]),
            ];
            $players[$key]['steam_profile_url'] = ($player['steam64'] ?? null) !== null
                ? 'https://steamcommunity.com/profiles/' . $player['steam64']
                : null;
        }

        $players = array_values($players);

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

        return [
            'status' => (string) ($persisted['status'] ?? 'not_found'),
            'source_path' => $persisted['source_path'] ?? null,
            'source_paths' => is_array($persisted['source_paths'] ?? null) ? $persisted['source_paths'] : [],
            'players' => $players,
        ];
    }

    /**
     * @return array<string, array<string, true>>
     */
    private function listFlags(): array
    {
        $flags = ['ban' => [], 'whitelist' => [], 'priority' => []];

        foreach ($this->lists->allLists() as $type => $entries) {
            if (!isset($flags[$type]) || !is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                $playerId = trim((string) (is_array($entry) ? ($entry['player_id'] ?? '') : ''));

                if ($playerId !== '') {
                    $flags[$type][$playerId] = true;
                }
            }
        }

        return $flags;
    }

    private function nameKey(string $name): string
    {
        return strtolower(trim($name));
    }

    private function latest(mixed $left, mixed $right): ?string
    {
        $leftText = trim((string) ($left ?? ''));
        $rightText = trim((string) ($right ?? ''));

        if ($leftText === '') {
            return $rightText === '' ? null : $rightText;
        }

        if ($rightText === '') {
            return $leftText;
        }

        return (strtotime($rightText) ?: 0) > (strtotime($leftText) ?: 0) ? $rightText : $leftText;
    }
}
