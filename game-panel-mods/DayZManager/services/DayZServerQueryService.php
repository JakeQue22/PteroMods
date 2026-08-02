<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use PteroMods\Services\DayZ\SourceQueryClient;
use Throwable;

/**
 * Obtains live DayZ server facts (map, player count, version) by querying the
 * game server over the Steam query protocol.
 *
 * The panel database only stores allocations and egg variables, so the query
 * endpoint is derived from the server's allocations (preferring an explicit
 * query-port egg variable) and the result is cached briefly to keep page loads
 * fast even when a server is offline.
 */
final class DayZServerQueryService
{
    /** Standard DayZ offset between the game port (2302) and query port (27016). */
    private const DAYZ_QUERY_PORT_OFFSET = 24714;

    private const QUERY_PORT_VARIABLES = ['STEAM_QUERY_PORT', 'QUERY_PORT', 'STEAMQUERYPORT', 'SERVER_QUERY_PORT'];

    private const CACHE_SECONDS = 15;

    public function __construct(
        private readonly SourceQueryClient $client = new SourceQueryClient(),
        private readonly DayZServerContext $context = new DayZServerContext(),
    ) {
    }

    /**
     * Queries the game server.
     *
     * @return array{online: bool, map: string|null, players: int|null, max_players: int|null, version: string|null, name: string|null, endpoint: string|null}
     */
    public function query(mixed $server): array
    {
        $endpoint = $this->resolveEndpoint($server);

        if ($endpoint === null) {
            return $this->offline(null);
        }

        [$host, $port] = $endpoint;
        $endpointLabel = $host . ':' . $port;

        $cacheKey = 'pteromods.dayz.query.' . md5($endpointLabel);
        $cached = $this->fromCache($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $info = $this->client->info($host, $port);

        $result = $info === null
            ? $this->offline($endpointLabel)
            : [
                'online'      => true,
                'map'         => $info['map'] !== '' ? $info['map'] : null,
                'players'     => $info['players'],
                'max_players' => $info['max_players'],
                'version'     => $info['version'] !== '' ? $info['version'] : null,
                'name'        => $info['name'] !== '' ? $info['name'] : null,
                'endpoint'    => $endpointLabel,
            ];

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
        if ($server === null) {
            return null;
        }

        $allocation = $this->primaryAllocation($server);

        if ($allocation === null) {
            return null;
        }

        $host = $this->attributeString($allocation, ['ip_alias', 'ip']);
        $gamePort = (int) ($this->context->rawAttribute($allocation, 'port') ?? 0);

        if ($host === '' || $gamePort <= 0) {
            return null;
        }

        if (in_array($host, ['0.0.0.0', '::'], true)) {
            $nodeHost = $this->nodeHost($server);
            $host = $nodeHost !== '' ? $nodeHost : $host;
        }

        $queryPort = $this->queryPortVariable($server)
            ?? $this->allocatedQueryPort($server, $gamePort)
            ?? $gamePort + self::DAYZ_QUERY_PORT_OFFSET;

        if ($queryPort < 1 || $queryPort > 65535) {
            return null;
        }

        return [$host, $queryPort];
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
     * Looks for an allocation that matches a well-known DayZ query port.
     */
    private function allocatedQueryPort(mixed $server, int $gamePort): ?int
    {
        $expected = $gamePort + self::DAYZ_QUERY_PORT_OFFSET;

        foreach ($this->allocations($server) as $allocation) {
            $port = (int) ($this->context->rawAttribute($allocation, 'port') ?? 0);

            if ($port === $expected || $port === 27016) {
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

        return $node === null ? '' : $this->attributeString($node, ['fqdn', 'name']);
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
