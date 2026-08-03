<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use PteroMods\Services\DayZ\HostAddressResolver;
use PteroMods\Services\DayZ\SourceQueryClient;
use Throwable;

/**
 * Obtains live DayZ server facts (map, player count, version) by querying the
 * game server over the Steam query protocol.
 *
 * The query endpoint is derived from the server's own configured settings,
 * most authoritative first: the `steamQueryPort` set in the server's
 * `serverDZ.cfg` (the setting an operator actually controls), an explicit
 * query-port egg variable, extra port allocations, and finally the two most
 * common conventions (`game port + 3` and the flat `27016` default). The
 * result is cached briefly to keep page loads fast even when a server is
 * offline.
 */
final class DayZServerQueryService
{
    /**
     * DayZ's actual default offset between the game port (`-port=`, e.g. 2302)
     * and the Steam query port: `serverDZ.cfg` defaults `steamQueryPort` to
     * `gameport + 3` (2305 for the default 2302 game port). Older revisions of
     * this client guessed `+24714`, an offset that does not apply to DayZ at
     * all, which made the query silently fail against every real DayZ server
     * and report "Offline" even while the server was running.
     */
    private const DAYZ_QUERY_PORT_OFFSET = 3;

    /** Steam's flat default query port, used when a server never changed it. */
    private const DEFAULT_STEAM_QUERY_PORT = 27016;

    private const QUERY_PORT_VARIABLES = [
        'STEAM_QUERY_PORT', 'QUERY_PORT', 'STEAMQUERYPORT', 'SERVER_QUERY_PORT',
        'QUERYPORT', 'DAYZ_QUERY_PORT', 'GAME_QUERY_PORT', 'STEAM_PORT',
    ];

    /** Config file names that may carry an explicit `steamQueryPort` setting. */
    private const QUERY_PORT_CONFIG_FILES = ['/serverDZ.cfg', '/config/serverDZ.cfg'];

    private const CACHE_SECONDS = 15;

    public function __construct(
        private readonly SourceQueryClient $client = new SourceQueryClient(),
        private readonly DayZServerContext $context = new DayZServerContext(),
        private readonly HostAddressResolver $addresses = new HostAddressResolver(),
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
    ) {
    }

    /**
     * Clears the cached query result for a server so the next `query()` call
     * fetches live data from the game server.
     */
    public function clearCache(mixed $server): void
    {
        $candidates = $this->resolveEndpointCandidates($server);

        if ($candidates === []) {
            return;
        }

        $cacheKey = 'pteromods.dayz.query.' . md5(implode(',', array_map(
            static fn (array $candidate): string => $candidate[0] . ':' . $candidate[1],
            $candidates,
        )));

        if (!class_exists('Illuminate\\Support\\Facades\\Cache')) {
            return;
        }

        try {
            \Illuminate\Support\Facades\Cache::forget($cacheKey);
        } catch (Throwable) {
            // Best-effort cache clearing.
        }
    }

    /**
     * Queries the game server.
     *
     * Several candidate query ports are tried (in order of confidence) because
     * DayZ has no single reliable convention for deriving the Steam query port
     * from the game port: an explicit egg variable, a matching allocation, and
     * the two most common conventions (`game port + 3` and the flat
     * `27016` default) are all attempted until one actually answers, instead
     * of trusting a single guess and reporting the server offline when it
     * merely guessed the wrong port.
     *
     * @return array{online: bool, map: string|null, players: int|null, max_players: int|null, version: string|null, name: string|null, endpoint: string|null}
     */
    public function query(mixed $server): array
    {
        $candidates = $this->resolveEndpointCandidates($server);

        if ($candidates === []) {
            return $this->offline(null);
        }

        $cacheKey = 'pteromods.dayz.query.' . md5(implode(',', array_map(
            static fn (array $candidate): string => $candidate[0] . ':' . $candidate[1],
            $candidates,
        )));
        $cached = $this->fromCache($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $result = null;

        foreach ($candidates as [$host, $port]) {
            $endpointLabel = $host . ':' . $port;
            $info = $this->client->info($host, $port);

            if ($info !== null) {
                $result = [
                    'online'      => true,
                    'map'         => $info['map'] !== '' ? $info['map'] : null,
                    'players'     => $info['players'],
                    'max_players' => $info['max_players'],
                    'version'     => $info['version'] !== '' ? $info['version'] : null,
                    'name'        => $info['name'] !== '' ? $info['name'] : null,
                    'endpoint'    => $endpointLabel,
                ];

                break;
            }
        }

        $result ??= $this->offline($candidates[0][0] . ':' . $candidates[0][1]);

        $this->toCache($cacheKey, $result);

        return $result;
    }

    /**
     * Resolves the host and Steam query port for a server model.
     *
     * @return array{0: string, 1: int}|null
     */
    public function resolveEndpoint(mixed $server): ?array
    {
        return $this->resolveEndpointCandidates($server)[0] ?? null;
    }

    /**
     * Every plausible host/query-port combination for a server, most likely
     * first.
     *
     * @return list<array{0: string, 1: int}>
     */
    public function resolveEndpointCandidates(mixed $server): array
    {
        if ($server === null) {
            return [];
        }

        $allocation = $this->primaryAllocation($server);

        if ($allocation === null) {
            return [];
        }

        $gamePort = (int) ($this->context->rawAttribute($allocation, 'port') ?? 0);

        // Allocations usually hold the node's *internal* address, which the
        // panel cannot reach when the node runs on a different machine, so the
        // public alias and the node FQDN are preferred over it.
        $host = $this->addresses->resolve([
            $this->attributeString($allocation, ['ip_alias']),
            $this->nodeHost($server),
            $this->attributeString($allocation, ['ip']),
        ]);

        if ($host === '' || $gamePort <= 0) {
            return [];
        }

        // Resolve a hostname to its numeric IP so the UDP query socket does not
        // rely on the panel container's DNS configuration, which may not resolve
        // the node's FQDN from inside the panel environment.
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            $resolved = gethostbyname($host);

            if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP) !== false) {
                $host = $resolved;
            }
        }

        $ports = [];

        // The most authoritative source: the `steamQueryPort` an operator set
        // in the server's own `serverDZ.cfg`, which is exactly the setting
        // Pterodactyl exposes to the operator for this server.
        $configPort = $this->queryPortConfig($server);

        if ($configPort !== null) {
            $ports[] = $configPort;
        }

        $variablePort = $this->queryPortVariable($server);

        if ($variablePort !== null) {
            $ports[] = $variablePort;
        }

        foreach ($this->allocationPorts($server) as $allocationPort) {
            if ($allocationPort !== $gamePort) {
                $ports[] = $allocationPort;
            }
        }

        // Some hosts configure DayZ's Steam query port to match the game port.
        $ports[] = $gamePort;
        $ports[] = $gamePort + self::DAYZ_QUERY_PORT_OFFSET;
        $ports[] = self::DEFAULT_STEAM_QUERY_PORT;

        $ports = array_values(array_unique(array_filter(
            $ports,
            static fn (int $port): bool => $port >= 1 && $port <= 65535,
        )));

        return array_map(static fn (int $port): array => [$host, $port], $ports);
    }

    /**
     * The address players (and query tools) can actually reach.
     */
    public function connectionAddress(mixed $server): ?string
    {
        if ($server === null) {
            return null;
        }

        $allocation = $this->primaryAllocation($server);

        if ($allocation === null) {
            return null;
        }

        $port = (int) ($this->context->rawAttribute($allocation, 'port') ?? 0);
        $host = $this->addresses->resolve([
            $this->attributeString($allocation, ['ip_alias']),
            $this->nodeHost($server),
            $this->attributeString($allocation, ['ip']),
        ]);

        return $host === '' || $port <= 0 ? null : $host . ':' . $port;
    }

    /**
     * Numeric IP address and query port suitable for the DZSA Launcher checker.
     *
     * DZSA requires a dotted-decimal IPv4 address, not a hostname. The raw
     * allocation IP is preferred because it is already numeric; if it is a
     * wildcard or private address the ip_alias is resolved via DNS.
     *
     * @return array{ip: string, query_port: int}|null
     */
    public function dzsaEndpoint(mixed $server): ?array
    {
        if ($server === null) {
            return null;
        }

        $allocation = $this->primaryAllocation($server);

        if ($allocation === null) {
            return null;
        }

        $gamePort = (int) ($this->context->rawAttribute($allocation, 'port') ?? 0);

        if ($gamePort <= 0) {
            return null;
        }

        // Build a list of candidate addresses, preferring the ones that are
        // already numeric public IPs so we avoid unnecessary DNS lookups.
        $candidates = [
            $this->attributeString($allocation, ['ip']),
            $this->attributeString($allocation, ['ip_alias']),
            $this->nodeHost($server),
        ];

        $numericIp = '';

        foreach ($candidates as $host) {
            $host = trim((string) $host);

            if ($host === '' || $host === '0.0.0.0' || in_array($host, ['[::]', '::', '*'], true)) {
                continue;
            }

            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                $numericIp = $host;
                break;
            }

            // Resolve a hostname to a numeric IP (DZSA does not accept DNS names).
            if (filter_var($host, FILTER_VALIDATE_IP) === false) {
                $resolved = gethostbyname($host);

                if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP) !== false) {
                    $numericIp = $resolved;
                    break;
                }
            }
        }

        if ($numericIp === '') {
            return null;
        }

        // Determine the Steam query port using the same priority chain used
        // for the live server query, so DZSA sees the same port the panel uses.
        $candidates = $this->resolveEndpointCandidates($server);
        $queryPort = $candidates !== [] ? $candidates[0][1] : ($gamePort + self::DAYZ_QUERY_PORT_OFFSET);

        return ['ip' => $numericIp, 'query_port' => $queryPort];
    }

    /**
     * @return array{online: bool, map: null, players: null, max_players: null, version: null, name: null, endpoint: string|null}
     */
    private function offline(?string $endpoint): array
    {
        return [
            'online'      => false,
            'map'         => null,
            'players'     => null,
            'max_players' => null,
            'version'     => null,
            'name'        => null,
            'endpoint'    => $endpoint,
        ];
    }

    private function primaryAllocation(mixed $server): mixed
    {
        $allocation = $this->context->rawAttribute($server, 'allocation');

        if ($allocation !== null) {
            return $allocation;
        }

        $allocations = $this->allocations($server);

        return $allocations[0] ?? null;
    }

    /**
     * @return list<mixed>
     */
    private function allocations(mixed $server): array
    {
        $allocations = $this->context->rawAttribute($server, 'allocations');

        if ($allocations === null) {
            return [];
        }

        if (is_object($allocations) && method_exists($allocations, 'all')) {
            try {
                $allocations = $allocations->all();
            } catch (Throwable) {
                return [];
            }
        }

        return is_array($allocations) ? array_values($allocations) : [];
    }

    /**
     * Other ports allocated to the server, most likely query port first.
     *
     * Extra allocations beyond the primary game port are frequently the
     * Steam query port a host assigned manually, so every one of them is a
     * plausible candidate, not just the one matching a guessed offset.
     *
     * @return list<int>
     */
    private function allocationPorts(mixed $server): array
    {
        $expected = 0;
        $primary = $this->primaryAllocation($server);

        if ($primary !== null) {
            $expected = (int) ($this->context->rawAttribute($primary, 'port') ?? 0) + self::DAYZ_QUERY_PORT_OFFSET;
        }

        $ports = [];

        foreach ($this->allocations($server) as $allocation) {
            $port = (int) ($this->context->rawAttribute($allocation, 'port') ?? 0);

            if ($port > 0) {
                $ports[] = $port;
            }
        }

        // Ports matching a well-known convention are tried before the rest.
        usort($ports, static fn (int $a, int $b): int => (
            (int) ($b === $expected || $b === self::DEFAULT_STEAM_QUERY_PORT)
            <=> (int) ($a === $expected || $a === self::DEFAULT_STEAM_QUERY_PORT)
        ));

        return $ports;
    }

    /**
     * Reads `steamQueryPort` from `serverDZ.cfg` (or its `config/` copy),
     * whichever the daemon can read first.
     */
    private function queryPortConfig(mixed $server): ?int
    {
        foreach (self::QUERY_PORT_CONFIG_FILES as $path) {
            $contents = $this->gateway->readFile($server, $path);

            if ($contents === null || $contents === '') {
                continue;
            }

            if (preg_match('/steamQueryPort\s*=\s*(\d+)\s*;/i', $contents, $matches) !== 1) {
                continue;
            }

            $port = (int) $matches[1];

            if ($port > 0 && $port <= 65535) {
                return $port;
            }
        }

        return null;
    }

    private function queryPortVariable(mixed $server): ?int
    {
        $serverId = (int) ($this->context->rawAttribute($server, 'id') ?? 0);

        if ($serverId <= 0
            || !class_exists('Illuminate\\Support\\Facades\\DB')
            || !class_exists('Illuminate\\Support\\Facades\\Schema')) {
            return null;
        }

        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('server_variables')
                || !\Illuminate\Support\Facades\Schema::hasTable('egg_variables')) {
                return null;
            }

            $value = \Illuminate\Support\Facades\DB::table('server_variables')
                ->join('egg_variables', 'egg_variables.id', '=', 'server_variables.variable_id')
                ->where('server_variables.server_id', $serverId)
                ->whereIn('egg_variables.env_variable', self::QUERY_PORT_VARIABLES)
                ->value('server_variables.variable_value');
        } catch (Throwable) {
            return null;
        }

        if (!is_scalar($value) || !ctype_digit(trim((string) $value))) {
            return null;
        }

        $port = (int) trim((string) $value);

        return $port > 0 && $port <= 65535 ? $port : null;
    }

    private function nodeHost(mixed $server): string
    {
        $node = $this->context->rawAttribute($server, 'node');

        return $node === null ? '' : $this->attributeString($node, ['fqdn']);
    }

    /**
     * @param list<string> $keys
     */
    private function attributeString(mixed $source, array $keys): string
    {
        return $this->context->attribute($source, $keys);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fromCache(string $key): ?array
    {
        if (!class_exists('Illuminate\\Support\\Facades\\Cache')) {
            return null;
        }

        try {
            $cached = \Illuminate\Support\Facades\Cache::get($key);

            return is_array($cached) ? $cached : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private function toCache(string $key, array $value): void
    {
        if (!class_exists('Illuminate\\Support\\Facades\\Cache')) {
            return;
        }

        try {
            \Illuminate\Support\Facades\Cache::put($key, $value, self::CACHE_SECONDS);
        } catch (Throwable) {
            // Caching is best-effort only.
        }
    }
}
