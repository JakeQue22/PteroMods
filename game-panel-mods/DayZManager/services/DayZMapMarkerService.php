<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use Throwable;

/**
 * Builds the categorised marker layers drawn on top of the live map.
 *
 * Two kinds of markers are produced:
 *
 *  - Named places, from the bundled catalogue below (cities, towns, villages,
 *    military installations, airfields, and landmarks).
 *  - Everything the running server itself declares in its mission files:
 *    animal and infected territories (`<mission>/env/*_territories.xml`),
 *    event spawn positions such as helicopter crashes, police cars, boats,
 *    vehicles, and contaminated areas (`cfgeventspawns.xml`), and the player
 *    spawn points (`cfgplayerspawnpoints.xml`).
 *
 * Mission XML is scanned with tolerant regular expressions rather than a DOM
 * parser: {@see DayZPanelGateway} caps file reads, so a large `cfgeventspawns.xml`
 * can arrive truncated and would fail strict XML parsing outright.
 */
final class DayZMapMarkerService
{
    private const CACHE_SECONDS = 300;

    /**
     * How long marker data is kept as stale-but-servable after a background
     * refresh stops happening (e.g. panel idle overnight).  The warmer
     * refreshes every 60 s during active use, so this ceiling only matters
     * when nobody has visited any DayZ page for many hours.
     */
    private const CACHE_STALE_SECONDS = self::CACHE_SECONDS * 288; // 24 h

    private const MAX_MARKERS_PER_GROUP = 1500;

    /** Known airdrop marker/location files, probed before scanning `/profiles`. */
    private const AIRDROP_PATHS = [
        '/profiles/VPPMapAirdrop.json',
        '/profiles/VPPAdminTools/VPPMapAirdrop.json',
        '/profiles/VPPAdminTools/Config/VPPMapAirdrop.json',
        '/profiles/Airdrop/AirdropSettings.json',
    ];

    /**
     * Marker categories, in the order they are listed in the layer control.
     *
     * `default` decides whether the layer starts switched on.
     *
     * @var array<string, array{label: string, icon: string, color: string, default: bool}>
     */
    private const CATEGORIES = [
        'city'            => ['label' => 'Cities',              'icon' => '🏙️', 'color' => '#fbbf24', 'default' => true],
        'town'            => ['label' => 'Towns',               'icon' => '🏘️', 'color' => '#fcd34d', 'default' => true],
        'village'         => ['label' => 'Villages',            'icon' => '🏠', 'color' => '#fde68a', 'default' => true],
        'military'        => ['label' => 'Military',            'icon' => '🎖️', 'color' => '#4ade80', 'default' => true],
        'airfield'        => ['label' => 'Airfields',           'icon' => '✈️', 'color' => '#38bdf8', 'default' => true],
        'trader'          => ['label' => 'Traders',              'icon' => '🛒', 'color' => '#34d399', 'default' => true],
        'airdrop'         => ['label' => 'Airdrops',             'icon' => '🪂', 'color' => '#fb7185', 'default' => true],
        'landmark'        => ['label' => 'Landmarks',           'icon' => '⛰️', 'color' => '#a78bfa', 'default' => false],
        'player_spawn'    => ['label' => 'Player spawns',       'icon' => '🚩', 'color' => '#f472b6', 'default' => false],
        'heli_crash'      => ['label' => 'Helicopter crashes',  'icon' => '🚁', 'color' => '#f97316', 'default' => false],
        'police'          => ['label' => 'Police cars',         'icon' => '🚓', 'color' => '#60a5fa', 'default' => false],
        'vehicle'         => ['label' => 'Vehicles',            'icon' => '🚗', 'color' => '#22d3ee', 'default' => false],
        'boat'            => ['label' => 'Boats',               'icon' => '⛵', 'color' => '#2dd4bf', 'default' => false],
        'loot'            => ['label' => 'Loot events',         'icon' => '🎁', 'color' => '#facc15', 'default' => false],
        'contaminated'    => ['label' => 'Contaminated areas',  'icon' => '☣️', 'color' => '#a3e635', 'default' => false],
        'infected'        => ['label' => 'Infected territories','icon' => '🧟', 'color' => '#84cc16', 'default' => false],
        'animal_wolf'     => ['label' => 'Wolves',              'icon' => '🐺', 'color' => '#94a3b8', 'default' => false],
        'animal_bear'     => ['label' => 'Bears',               'icon' => '🐻', 'color' => '#b45309', 'default' => false],
        'animal_deer'     => ['label' => 'Deer',                'icon' => '🦌', 'color' => '#d97706', 'default' => false],
        'animal_boar'     => ['label' => 'Boar',                'icon' => '🐗', 'color' => '#92400e', 'default' => false],
        'animal_cow'      => ['label' => 'Cattle',              'icon' => '🐄', 'color' => '#e5e7eb', 'default' => false],
        'animal_sheep'    => ['label' => 'Sheep & goats',       'icon' => '🐑', 'color' => '#f5f5f4', 'default' => false],
        'animal_pig'      => ['label' => 'Pigs',                'icon' => '🐖', 'color' => '#fbcfe8', 'default' => false],
        'animal_hen'      => ['label' => 'Hens',                'icon' => '🐔', 'color' => '#fda4af', 'default' => false],
        'animal_hare'     => ['label' => 'Hares',               'icon' => '🐇', 'color' => '#ddd6fe', 'default' => false],
        'animal_other'    => ['label' => 'Other animals',       'icon' => '🐾', 'color' => '#cbd5e1', 'default' => false],
        'event'           => ['label' => 'Other events',         'icon' => '📍', 'color' => '#e879f9', 'default' => false],
    ];

    /**
     * Named places per map.  Coordinates are the in-game world coordinates
     * (the same `x` / `z` pair the live map and the mission files use) of the
     * settlement centre.
     *
     * @var array<string, list<array{0: string, 1: float, 2: float, 3: string}>>
     */
    private const PLACES = [
        'chernarusplus' => [
            ['Chernogorsk', 6600.0, 2700.0, 'city'],
            ['Elektrozavodsk', 10450.0, 2250.0, 'city'],
            ['Berezino', 12100.0, 9150.0, 'city'],
            ['Novodmitrovsk', 11400.0, 14400.0, 'city'],
            ['Severograd', 8100.0, 12700.0, 'city'],
            ['Zelenogorsk', 2600.0, 5300.0, 'town'],
            ['Solnichniy', 13450.0, 4200.0, 'town'],
            ['Krasnostav', 11200.0, 12200.0, 'town'],
            ['Svetlojarsk', 14100.0, 13300.0, 'town'],
            ['Gorka', 9550.0, 8850.0, 'town'],
            ['Stary Sobor', 6150.0, 7750.0, 'town'],
            ['Novy Sobor', 7050.0, 7700.0, 'town'],
            ['Vybor', 3800.0, 8900.0, 'town'],
            ['Grishino', 5900.0, 10200.0, 'town'],
            ['Kamyshovo', 12100.0, 3400.0, 'town'],
            ['Balota', 4500.0, 2450.0, 'village'],
            ['Komarovo', 3700.0, 2400.0, 'village'],
            ['Kamenka', 1900.0, 2200.0, 'village'],
            ['Pavlovo', 2000.0, 3200.0, 'village'],
            ['Prigorodki', 8000.0, 3300.0, 'village'],
            ['Kabanino', 5000.0, 8500.0, 'village'],
            ['Nadezhdino', 4600.0, 9200.0, 'village'],
            ['Pustoshka', 2900.0, 7300.0, 'village'],
            ['Sosnovka', 2500.0, 6650.0, 'village'],
            ['Myshkino', 2000.0, 7600.0, 'village'],
            ['Bor', 3300.0, 6100.0, 'village'],
            ['Pogorevka', 4400.0, 6600.0, 'village'],
            ['Guglovo', 7100.0, 8500.0, 'village'],
            ['Dolina', 11200.0, 7000.0, 'village'],
            ['Msta', 11400.0, 5800.0, 'village'],
            ['Polana', 10600.0, 6600.0, 'village'],
            ['Staroye', 10150.0, 5400.0, 'village'],
            ['Dubrovka', 9950.0, 10100.0, 'village'],
            ['Nizhnoye', 13100.0, 10400.0, 'village'],
            ['Shakhovka', 10400.0, 11500.0, 'village'],
            ['Gvozdno', 8900.0, 11800.0, 'village'],
            ['Novaya Petrovka', 6900.0, 12300.0, 'village'],
            ['Kamensk', 7000.0, 14400.0, 'village'],
            ['Bereznik', 6400.0, 11400.0, 'village'],
            ['Rogovo', 4700.0, 10600.0, 'village'],
            ['Lopatino', 2000.0, 10600.0, 'village'],
            ['Grabin', 1400.0, 9000.0, 'village'],
            ['Zaprudnoye', 8800.0, 6100.0, 'village'],
            ['Orlovets', 11000.0, 8000.0, 'village'],
            ['Solnechny Kray', 13400.0, 11800.0, 'village'],
            ['Tisy Military Base', 1650.0, 14350.0, 'military'],
            ['Vybor Military Base', 4400.0, 8300.0, 'military'],
            ['Zeleno Military Base', 2600.0, 5000.0, 'military'],
            ['Pavlovo Military Base', 1900.0, 3700.0, 'military'],
            ['Kamensk Military Base', 7000.0, 14700.0, 'military'],
            ['Myshkino Military Tents', 2000.0, 7900.0, 'military'],
            ['Zelenogorsk Airstrip', 2900.0, 5900.0, 'airfield'],
            ['Balota Airstrip', 4900.0, 2500.0, 'airfield'],
            ['Krasnostav Airfield', 12000.0, 12500.0, 'airfield'],
            ['Northwest Airfield', 4600.0, 10300.0, 'airfield'],
            ['Green Mountain', 3700.0, 6000.0, 'landmark'],
            ['Devil\'s Castle', 6800.0, 11400.0, 'landmark'],
            ['Zub Castle', 6600.0, 5600.0, 'landmark'],
            ['Rify Shipwreck', 13800.0, 13700.0, 'landmark'],
            ['Prison Island (Skalisty)', 13000.0, 1900.0, 'landmark'],
            ['Klen (Altar)', 8100.0, 9300.0, 'landmark'],
        ],
        'enoch' => [
            ['Topolin', 9800.0, 10250.0, 'city'],
            ['Nadbór', 7850.0, 5950.0, 'town'],
            ['Brena', 5600.0, 5700.0, 'town'],
            ['Gliniska', 2800.0, 6500.0, 'town'],
            ['Sitnik', 6650.0, 9450.0, 'town'],
            ['Grabin', 4400.0, 8000.0, 'village'],
            ['Swarog', 3500.0, 10100.0, 'village'],
            ['Radunin', 5700.0, 11400.0, 'village'],
            ['Kolembrody', 7500.0, 12000.0, 'village'],
            ['Lukow', 10800.0, 8000.0, 'village'],
            ['Bielawa', 10500.0, 5800.0, 'village'],
            ['Zalesie', 6600.0, 3800.0, 'village'],
            ['Dolnik', 8600.0, 3400.0, 'village'],
            ['Tarnow', 3300.0, 3200.0, 'village'],
            ['Bor Military Base', 4500.0, 4700.0, 'military'],
            ['Nadbór Military Base', 8800.0, 6300.0, 'military'],
            ['Lukow Airfield', 11300.0, 8600.0, 'airfield'],
            ['Radunin Bunker', 5400.0, 11600.0, 'landmark'],
        ],
        'sakhal' => [
            ['Sakhal City', 6400.0, 6800.0, 'city'],
            ['Ayan', 2875.0, 2650.0, 'town'],
            ['Yasny', 7200.0, 3150.0, 'town'],
            ['Tungar', 9850.0, 8900.0, 'town'],
            ['Nizhnoye', 6400.0, 11200.0, 'town'],
            ['Boreal Ridge', 4200.0, 7800.0, 'village'],
            ['Kotelnoye', 3400.0, 9700.0, 'village'],
            ['Vodyanoy', 8600.0, 5400.0, 'village'],
            ['Mirny Military Base', 5300.0, 4600.0, 'military'],
            ['Sakhal Airfield', 8100.0, 9700.0, 'airfield'],
            ['Volcano', 5600.0, 8600.0, 'landmark'],
        ],
    ];

    public function __construct(
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
        private readonly DayZServerContext $context = new DayZServerContext(),
        private readonly DayZStaleCacheService $staleCache = new DayZStaleCacheService(),
    ) {
    }

    /**
     * @return array<string, array{label: string, icon: string, color: string, default: bool}>
     */
    public function categories(): array
    {
        return self::CATEGORIES;
    }

    /**
     * @return array{status: string, map: string, groups: list<array<string, mixed>>, sources: list<string>}
     */
    public function markers(mixed $server, string $mapName): array
    {
        $cacheKey = $this->cacheKey($server, $mapName);

        if ($cacheKey !== '') {
            /** @var array{status: string, map: string, groups: list<array<string, mixed>>, sources: list<string>>}|null $result */
            $result = $this->staleCache->remember(
                $cacheKey,
                self::CACHE_SECONDS,
                self::CACHE_STALE_SECONDS,
                fn (): array => $this->buildMarkers($server, $mapName),
            );

            if (is_array($result)) {
                return $result;
            }
        }

        return $this->buildMarkers($server, $mapName);
    }

    /**
     * @return array{status: string, map: string, groups: list<array<string, mixed>>, sources: list<string>}
     */
    private function buildMarkers(mixed $server, string $mapName): array
    {
        $markers = [];
        $sources = [];

        foreach ($this->places($mapName) as $place) {
            $markers[$place[3]][] = ['name' => $place[0], 'x' => $place[1], 'z' => $place[2], 'detail' => 'Named location'];
        }

        $missionPath = $this->missionPath($server);

        if ($missionPath !== '') {
            $this->collectTerritories($server, $missionPath, $markers, $sources);
            $this->collectEventSpawns($server, $missionPath, $markers, $sources);
            $this->collectPlayerSpawns($server, $missionPath, $markers, $sources);
        }

        $this->collectTraders($server, $markers, $sources);
        $this->collectAirdrops($server, $markers, $sources);

        $result = [
            'status' => $missionPath === '' ? 'mission_not_found' : 'ok',
            'map' => $mapName,
            'groups' => $this->buildGroups($markers),
            'sources' => $sources,
        ];

        return $result;
    }

    /**
     * @return list<array{0: string, 1: float, 2: float, 3: string}>
     */
    private function places(string $mapName): array
    {
        $key = strtolower(preg_replace('/[^a-z0-9]/i', '', $mapName) ?? '');

        $key = match ($key) {
            'chernarus', 'chernarusplus', 'dayzofflinechernarusplus' => 'chernarusplus',
            'livonia', 'enoch', 'dayzofflineenoch' => 'enoch',
            'sakhal', 'dayzofflinesakhal' => 'sakhal',
            default => $key,
        };

        return self::PLACES[$key] ?? [];
    }

    /**
     * @param array<string, list<array<string, mixed>>> $markers
     * @return list<array<string, mixed>>
     */
    private function buildGroups(array $markers): array
    {
        $groups = [];

        foreach (self::CATEGORIES as $key => $meta) {
            $entries = $markers[$key] ?? [];

            // Always include dynamic/server-sourced categories (like airdrop and
            // trader) so they are visible in the layer control even before any
            // data files are present on the server.
            if ($entries === [] && !in_array($key, ['airdrop', 'trader'], true)) {
                continue;
            }

            $groups[] = [
                'key' => $key,
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'color' => $meta['color'],
                'default' => $meta['default'],
                'count' => count($entries),
                'markers' => array_slice($entries, 0, self::MAX_MARKERS_PER_GROUP),
            ];
        }

        return $groups;
    }

    /**
     * Animal and infected spawn territories live in `<mission>/env/*.xml`.
     *
     * @param array<string, list<array<string, mixed>>> $markers
     * @param list<string> $sources
     */
    private function collectTerritories(mixed $server, string $missionPath, array &$markers, array &$sources): void
    {
        foreach ($this->gateway->listDirectory($server, $missionPath . '/env') as $entry) {
            if (!is_array($entry) || !empty($entry['directory'])) {
                continue;
            }

            $name = trim((string) ($entry['name'] ?? ''));

            if ($name === '' || !str_ends_with(strtolower($name), '.xml')) {
                continue;
            }

            $raw = $this->gateway->readFile($server, $missionPath . '/env/' . $name);

            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }

            $category = $this->territoryCategory($name);
            $label = $this->humanise($name);
            $added = 0;

            foreach ($this->matchZones($raw) as $zone) {
                $markers[$category][] = [
                    'name' => $zone['name'] !== '' ? $zone['name'] : $label,
                    'x' => $zone['x'],
                    'z' => $zone['z'],
                    'radius' => $zone['r'],
                    'detail' => $label,
                ];
                $added++;
            }

            if ($added > 0) {
                $sources[] = $missionPath . '/env/' . $name;
            }
        }
    }

    /**
     * @param array<string, list<array<string, mixed>>> $markers
     * @param list<string> $sources
     */
    private function collectEventSpawns(mixed $server, string $missionPath, array &$markers, array &$sources): void
    {
        $raw = $this->gateway->readFile($server, $missionPath . '/cfgeventspawns.xml');

        if (!is_string($raw) || trim($raw) === '') {
            return;
        }

        $added = 0;

        foreach ($this->matchEvents($raw) as $event) {
            $category = $this->eventCategory($event['name']);
            $label = $this->humanise($event['name']);

            foreach ($event['positions'] as $position) {
                $markers[$category][] = [
                    'name' => $label,
                    'x' => $position[0],
                    'z' => $position[1],
                    'detail' => 'Event: ' . $event['name'],
                ];
                $added++;
            }
        }

        if ($added > 0) {
            $sources[] = $missionPath . '/cfgeventspawns.xml';
        }
    }

    /**
     * @param array<string, list<array<string, mixed>>> $markers
     * @param list<string> $sources
     */
    private function collectPlayerSpawns(mixed $server, string $missionPath, array &$markers, array &$sources): void
    {
        $raw = $this->gateway->readFile($server, $missionPath . '/cfgplayerspawnpoints.xml');

        if (!is_string($raw) || trim($raw) === '') {
            return;
        }

        $added = 0;

        foreach ($this->matchSpawnSections($raw) as $section => $positions) {
            foreach ($positions as $position) {
                $markers['player_spawn'][] = [
                    'name' => $this->humanise($section) . ' spawn',
                    'x' => $position[0],
                    'z' => $position[1],
                    'detail' => 'Player spawn point',
                ];
                $added++;
            }
        }

        if ($added > 0) {
            $sources[] = $missionPath . '/cfgplayerspawnpoints.xml';
        }
    }

    /**
     * @return list<array{name: string, x: float, z: float, r: float|null}>
     */
    private function matchZones(string $raw): array
    {
        if (preg_match_all('/<zone\b[^>]*>/i', $raw, $matches) === false) {
            return [];
        }

        $zones = [];

        foreach ($matches[0] as $tag) {
            $x = $this->attribute($tag, 'x');
            $z = $this->attribute($tag, 'z');

            if ($x === null || $z === null) {
                continue;
            }

            $radius = $this->attribute($tag, 'r');

            $zones[] = [
                'name' => trim((string) ($this->stringAttribute($tag, 'name') ?? '')),
                'x' => $x,
                'z' => $z,
                'r' => $radius,
            ];
        }

        return $zones;
    }

    /**
     * @return list<array{name: string, positions: list<array{0: float, 1: float}>}>
     */
    private function matchEvents(string $raw): array
    {
        if (preg_match_all('/<event\b[^>]*name\s*=\s*"([^"]+)"[^>]*>(.*?)<\/event>/is', $raw, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $events = [];

        foreach ($matches as $match) {
            $positions = $this->matchPositions($match[2]);

            if ($positions === []) {
                continue;
            }

            $events[] = ['name' => trim($match[1]), 'positions' => $positions];
        }

        return $events;
    }

    /**
     * @return array<string, list<array{0: float, 1: float}>>
     */
    private function matchSpawnSections(string $raw): array
    {
        $sections = [];

        foreach (['fresh', 'hop', 'travel'] as $section) {
            if (preg_match('/<' . $section . '\b[^>]*>(.*?)<\/' . $section . '>/is', $raw, $match) !== 1) {
                continue;
            }

            $positions = $this->matchPositions($match[1]);

            if ($positions !== []) {
                $sections[$section] = $positions;
            }
        }

        return $sections;
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    private function matchPositions(string $raw): array
    {
        if (preg_match_all('/<pos\b[^>]*>/i', $raw, $matches) === false) {
            return [];
        }

        $positions = [];

        foreach ($matches[0] as $tag) {
            $x = $this->attribute($tag, 'x');
            $z = $this->attribute($tag, 'z');

            if ($x === null || $z === null) {
                continue;
            }

            $positions[] = [$x, $z];
        }

        return $positions;
    }

    private function attribute(string $tag, string $name): ?float
    {
        $value = $this->stringAttribute($tag, $name);

        return is_string($value) && is_numeric($value) ? (float) $value : null;
    }

    private function stringAttribute(string $tag, string $name): ?string
    {
        return preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*"([^"]*)"/i', $tag, $match) === 1
            ? $match[1]
            : null;
    }

    private function territoryCategory(string $fileName): string
    {
        $name = strtolower($fileName);

        return match (true) {
            str_contains($name, 'zombie'), str_contains($name, 'infected') => 'infected',
            str_contains($name, 'wolf') => 'animal_wolf',
            str_contains($name, 'bear') => 'animal_bear',
            str_contains($name, 'deer'), str_contains($name, 'roe') => 'animal_deer',
            str_contains($name, 'boar') => 'animal_boar',
            str_contains($name, 'cattle'), str_contains($name, 'cow') => 'animal_cow',
            str_contains($name, 'sheep'), str_contains($name, 'goat') => 'animal_sheep',
            str_contains($name, 'pig') => 'animal_pig',
            str_contains($name, 'hen'), str_contains($name, 'chicken'), str_contains($name, 'rooster') => 'animal_hen',
            str_contains($name, 'hare'), str_contains($name, 'rabbit') => 'animal_hare',
            str_contains($name, 'animal'), str_contains($name, 'domestic') => 'animal_other',
            default => 'event',
        };
    }

    private function eventCategory(string $eventName): string
    {
        $name = strtolower($eventName);

        return match (true) {
            str_contains($name, 'heli') => 'heli_crash',
            str_contains($name, 'police') => 'police',
            str_contains($name, 'contamin'), str_contains($name, 'toxic') => 'contaminated',
            str_contains($name, 'boat'), str_contains($name, 'sea') => 'boat',
            str_contains($name, 'vehicle'), str_contains($name, 'car'), str_contains($name, 'truck') => 'vehicle',
            str_contains($name, 'wolf') => 'animal_wolf',
            str_contains($name, 'bear') => 'animal_bear',
            str_contains($name, 'deer') => 'animal_deer',
            str_contains($name, 'boar') => 'animal_boar',
            str_contains($name, 'cow'), str_contains($name, 'cattle') => 'animal_cow',
            str_contains($name, 'sheep'), str_contains($name, 'goat') => 'animal_sheep',
            str_contains($name, 'pig') => 'animal_pig',
            str_contains($name, 'hen'), str_contains($name, 'chicken') => 'animal_hen',
            str_contains($name, 'hare'), str_contains($name, 'rabbit') => 'animal_hare',
            str_contains($name, 'animal') => 'animal_other',
            str_contains($name, 'infected'), str_contains($name, 'zombie') => 'infected',
            str_contains($name, 'loot'), str_contains($name, 'stash'), str_contains($name, 'crate'),
            str_contains($name, 'container'), str_contains($name, 'treasure'), str_contains($name, 'static') => 'loot',
            default => 'event',
        };
    }

    private function humanise(string $value): string
    {
        $text = preg_replace('/\.xml$/i', '', $value) ?? $value;
        $text = str_replace(['_', '-'], ' ', $text);
        $text = preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $text) ?? $text;

        return ucwords(trim(preg_replace('/\s+/', ' ', $text) ?? $text));
    }

    /**
     * Reads `/profiles/Trader/TraderObjects.txt` (Dr. Jones Trader mod).
     *
     * Supports both the common tagged format:
     *   // Main Airfield:
     *   <TraderMarkerPosition> 5833, 74, 3806
     * and older/custom `Location:` blocks with `TraderMarkerPosition = ...`.
     *
     * @param array<string, list<array<string, mixed>>> $markers
     * @param list<string> $sources
     */
    private function collectTraders(mixed $server, array &$markers, array &$sources): void
    {
        $path = '/profiles/Trader/TraderObjects.txt';

        try {
            $raw = $this->gateway->readFile($server, $path);
        } catch (Throwable) {
            return;
        }

        if (!is_string($raw) || trim($raw) === '') {
            return;
        }

        $added = $this->collectTaggedTraders($raw, $markers);

        if ($added < 1) {
            $added = $this->collectSectionTraders($raw, $markers);
        }

        if ($added > 0) {
            $sources[] = $path;
        }
    }

    /**
     * @param array<string, list<array<string, mixed>>> $markers
     * @param list<string> $sources
     */
    private function collectAirdrops(mixed $server, array &$markers, array &$sources): void
    {
        $seen = [];

        foreach ($this->airdropFilePaths($server) as $path) {
            try {
                $raw = $this->gateway->readFile($server, $path);
            } catch (Throwable) {
                continue;
            }

            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }

            // Strip UTF-8 BOM if present; json_decode fails silently on BOM-prefixed input.
            if (str_starts_with($raw, "\xef\xbb\xbf")) {
                $raw = substr($raw, 3);
            }

            $decoded = json_decode($raw, true);

            if (!is_array($decoded)) {
                continue;
            }

            $added = 0;
            $walk = function (array $node) use (&$walk, &$markers, &$added, &$seen): void {
                $position = $this->airdropNodePosition($node);

                if ($position !== null) {
                    $name = $this->airdropNodeName($node);
                    $key = strtolower($name) . '|' . round($position[0], 1) . '|' . round($position[1], 1);

                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $markers['airdrop'][] = [
                            'name' => $name,
                            'x' => $position[0],
                            'z' => $position[1],
                            'detail' => 'Airdrop location',
                        ];
                        $added++;
                    }
                }

                foreach ($node as $value) {
                    if (is_array($value)) {
                        $walk($value);
                    }
                }
            };
            $walk($decoded);

            if ($added > 0) {
                $sources[] = $path;
            }
        }
    }

    /**
     * Airdrop mods write their marker/location file to several different places
     * (VPP Admin Tools, the standalone Airdrop mod, custom mod folders), so the
     * known paths are probed first and `/profiles` is then scanned (two levels
     * deep) for any other JSON file whose name mentions "airdrop".
     *
     * @return list<string>
     */
    private function airdropFilePaths(mixed $server): array
    {
        $paths = self::AIRDROP_PATHS;
        $pending = ['/profiles'];
        $depth = 0;

        while ($pending !== [] && $depth < 2) {
            $next = [];

            foreach ($pending as $directory) {
                try {
                    $entries = $this->gateway->listDirectory($server, $directory);
                } catch (Throwable) {
                    continue;
                }

                foreach ($entries as $entry) {
                    $name = trim((string) ($entry['name'] ?? ''));

                    if ($name === '') {
                        continue;
                    }

                    $path = rtrim($directory, '/') . '/' . $name;

                    if ($entry['directory'] ?? false) {
                        $next[] = $path;
                        continue;
                    }

                    if (stripos($name, 'airdrop') !== false
                        && strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) === 'json'
                    ) {
                        $paths[] = $path;
                    }
                }
            }

            $pending = $next;
            $depth++;
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param array<mixed> $node
     * @return array{0:float,1:float}|null
     */
    private function airdropNodePosition(array $node): ?array
    {
        foreach (['M_POSITION', 'Position', 'position', 'POSITION', 'pos', 'Pos', 'Coordinates', 'coordinates'] as $key) {
            if (array_key_exists($key, $node)) {
                $position = $this->airdropPosition($node[$key]);

                if ($position !== null) {
                    return $position;
                }
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $node
     */
    private function airdropNodeName(array $node): string
    {
        foreach (['M_MARKER_NAME', 'MarkerName', 'markerName', 'Name', 'name', 'Location', 'location', 'Title', 'title'] as $key) {
            $value = $node[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return 'Airdrop';
    }

    /**
     * @return array{0:float,1:float}|null
     */
    private function airdropPosition(mixed $value): ?array
    {
        if (is_array($value)) {
            $values = array_values($value);

            if (count($values) >= 3 && is_numeric($values[0]) && is_numeric($values[2])) {
                return [(float) $values[0], (float) $values[2]];
            }

            $x = $value['x'] ?? $value['X'] ?? null;
            $z = $value['z'] ?? $value['Z'] ?? null;

            if (is_numeric($x) && is_numeric($z)) {
                return [(float) $x, (float) $z];
            }
        }

        if (is_string($value)
            && preg_match_all('/-?\d+(?:\.\d+)?/', $value, $matches) >= 3
        ) {
            return [(float) $matches[0][0], (float) $matches[0][2]];
        }

        return null;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $markers
     */
    private function collectTaggedTraders(string $raw, array &$markers): int
    {
        $added = 0;
        $locationName = 'Trader';
        $lines = preg_split('/\R/', $raw) ?: [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            if (str_starts_with($trimmed, '//')) {
                $comment = trim(substr($trimmed, 2));

                if ($comment !== '' && !str_starts_with($comment, '<')) {
                    $locationName = rtrim($comment, " \t\n\r\0\x0B:");
                }

                continue;
            }

            if (!preg_match('/^<TraderMarkerPosition>\s*([^\r\n]+)/i', $trimmed, $lineMatch)) {
                continue;
            }

            if (preg_match_all('/[-+]?\d*\.?\d+/', (string) ($lineMatch[1] ?? ''), $numbers) === false
                || count($numbers[0] ?? []) < 3) {
                continue;
            }

            $x = (float) $numbers[0][0];
            $z = (float) $numbers[0][2];

            if ($x === 0.0 && $z === 0.0) {
                continue;
            }

            $markers['trader'][] = [
                'name'   => $locationName !== '' ? $locationName : 'Trader',
                'x'      => $x,
                'z'      => $z,
                'detail' => 'Trader location',
            ];
            $added++;
        }

        return $added;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $markers
     */
    private function collectSectionTraders(string $raw, array &$markers): int
    {
        $sectionsPattern = '/^\s*Location\s*:\s*(.*?)(?:\r\n|\r|\n)([\s\S]*?)(?=^\s*Location\s*:|\z)/im';

        if (preg_match_all($sectionsPattern, $raw, $sections, PREG_SET_ORDER) === false
            || $sections === []) {
            return 0;
        }

        $added = 0;

        foreach ($sections as $section) {
            $locationName = trim((string) ($section[1] ?? ''));
            $body = (string) ($section[2] ?? '');

            if (!preg_match('/TraderMarkerPosition(?:\[\])?\s*=\s*([^\r\n;]+)/i', $body, $line)) {
                continue;
            }

            if (preg_match_all('/[-+]?\d*\.?\d+/', (string) ($line[1] ?? ''), $numbers) === false
                || count($numbers[0] ?? []) < 3) {
                continue;
            }

            $x = (float) $numbers[0][0];
            $z = (float) $numbers[0][2];

            if ($x === 0.0 && $z === 0.0) {
                continue;
            }

            $markers['trader'][] = [
                'name'   => $locationName !== '' ? $locationName : 'Trader',
                'x'      => $x,
                'z'      => $z,
                'detail' => 'Trader location',
            ];
            $added++;
        }

        return $added;
    }

    private function missionPath(mixed $server): string
    {
        $missions = [];

        try {
            foreach ($this->gateway->listDirectory($server, '/mpmissions') as $entry) {
                if (!is_array($entry) || empty($entry['directory'])) {
                    continue;
                }

                $name = trim((string) ($entry['name'] ?? ''));

                if ($name !== '') {
                    $missions[] = $name;
                }
            }
        } catch (Throwable) {
            return '';
        }

        foreach ($missions as $mission) {
            if (str_starts_with(strtolower($mission), 'dayzoffline.')) {
                return '/mpmissions/' . $mission;
            }
        }

        return $missions === [] ? '' : '/mpmissions/' . $missions[0];
    }

    private function cacheKey(mixed $server, string $mapName): string
    {
        $serverId = $this->context->attribute($server, ['uuid', 'uuidShort', 'id']);

        return $serverId === '' ? '' : 'pteromods.dayz.live_map.markers.' . md5($serverId . '|' . strtolower($mapName));
    }
}
