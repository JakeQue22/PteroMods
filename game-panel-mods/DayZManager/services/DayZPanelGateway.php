<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use Throwable;

/**
 * Talks to the Pterodactyl daemon (Wings) that runs a server.
 *
 * Everything the DayZ Manager needs that is *not* stored in the panel database
 * — the live power state, the resource utilisation, and the files inside the
 * container — comes from Wings. The panel's own repositories are used when they
 * are available (they already handle authentication, retries, and node
 * selection); otherwise the daemon is called directly with the node's token, so
 * the module also works on panel forks that renamed those classes.
 *
 * Every method degrades to `null`/`[]` instead of throwing: the module pages
 * must still render when a node is unreachable.
 */
final class DayZPanelGateway
{
    private const DETAILS_CACHE_SECONDS = 5;

    private const FILES_CACHE_SECONDS = 30;

    private const MAX_FILE_BYTES = 262144;

    public function __construct(
        private readonly DayZServerContext $context = new DayZServerContext(),
        private readonly DayZStaleCacheService $staleCache = new DayZStaleCacheService(),
    ) {
    }

    /**
     * Live server details as reported by Wings.
     *
     * @return array{state: string, is_suspended: bool, utilization: array<string, mixed>}|null
     */
    public function details(mixed $server): ?array
    {
        if ($server === null) {
            return null;
        }

        $cacheKey = $this->cacheKey('details', $server, '');

        /** @var array{state: string, is_suspended: bool, utilization: array<string, mixed>}|null $details */
        $details = $this->staleCache->remember(
            $cacheKey,
            self::DETAILS_CACHE_SECONDS,
            self::DETAILS_CACHE_SECONDS * 20,
            fn (): ?array => $this->fetchDetails($server),
            null,
        );

        return $details;
    }

    /**
     * The live power state (`running`, `starting`, `stopping`, `offline`).
     */
    public function state(mixed $server): ?string
    {
        $details = $this->details($server);
        $state = $details['state'] ?? null;

        return is_string($state) && $state !== '' ? strtolower($state) : null;
    }

    /**
     * Clears the cached directory listing for a server.
     *
     * Pass an explicit `$path` to clear only that directory's listing (e.g.
     * `/profiles` after a log-scrub run).  When `$path` is `null` the default
     * well-known paths (server root and the Workshop download directory) are
     * cleared, which is the behaviour used by the mod-install pipeline.
     */
    public function clearFileListingCache(mixed $server, ?string $path = null): void
    {
        if ($server === null) {
            return;
        }

        $paths = $path !== null
            ? [$path]
            : ['/', '/steamapps/workshop/content/221100'];

        foreach ($paths as $p) {
            $this->forget($this->cacheKey('files', $server, $this->normalizePath($p)));
        }
    }

    /**
     * Lists a directory inside the server container.
     *
     * @return list<array{name: string, directory: bool, file: bool, size: int, mime: string, modified: string}>
     */
    public function listDirectory(mixed $server, string $path = '/'): array
    {
        if ($server === null) {
            return [];
        }

        $path = $this->normalizePath($path);
        $cacheKey = $this->cacheKey('files', $server, $path);
        $entries = $this->staleCache->remember(
            $cacheKey,
            self::FILES_CACHE_SECONDS,
            self::FILES_CACHE_SECONDS * 20,
            fn (): array => array_map(
                fn (array $entry): array => $this->normalizeEntry($entry),
                $this->fetchDirectory($server, $path),
            ),
            [],
        );

        return is_array($entries) ? $entries : [];
    }

    /**
     * Reads a (small) file from the server container.
     */
    public function readFile(mixed $server, string $path): ?string
    {
        if ($server === null) {
            return null;
        }

        $path = $this->normalizePath($path);
        $cacheKey = $this->cacheKey('file', $server, $path);
        $contents = $this->staleCache->remember(
            $cacheKey,
            self::FILES_CACHE_SECONDS,
            self::FILES_CACHE_SECONDS * 20,
            fn (): ?string => $this->fetchFile($server, $path),
            null,
        );

        return is_string($contents) ? $contents : null;
    }

    /**
     * Writes a file inside the server container.
     */
    public function writeFile(mixed $server, string $path, string $content): bool
    {
        if ($server === null) {
            return false;
        }

        $path = $this->normalizePath($path);
        $repository = $this->fileRepository($server);

        if ($repository !== null && method_exists($repository, 'putContent')) {
            try {
                $repository->putContent($path, $content);
                $this->forget($this->cacheKey('file', $server, $path));

                return true;
            } catch (Throwable) {
                return false;
            }
        }

        if (!class_exists('Illuminate\\Support\\Facades\\Http')) {
            return false;
        }

        $written = $this->daemonRequest($server, 'POST', '/files/write', ['file' => $path], false, $content) !== null;

        if ($written) {
            $this->forget($this->cacheKey('file', $server, $path));
        }

        return $written;
    }

    /**
     * Renames a file or directory inside the server container.
     *
     * Both `$from` and `$to` are file/directory names relative to `$root`
     * (not full paths). Returns true when the rename succeeds.
     */
    public function renameFile(mixed $server, string $root, string $from, string $to): bool
    {
        if ($server === null || $from === '' || $to === '' || $from === $to) {
            return false;
        }

        $root = $this->normalizePath($root);
        $repository = $this->fileRepository($server);

        if ($repository !== null && method_exists($repository, 'renameFiles')) {
            try {
                $repository->renameFiles($root, [['from' => $from, 'to' => $to]]);
                $this->forget($this->cacheKey('files', $server, $root));

                return true;
            } catch (Throwable) {
                return false;
            }
        }

        if (!class_exists('Illuminate\\Support\\Facades\\Http')) {
            return false;
        }

        $renamed = $this->daemonRequest($server, 'PUT', '/files/rename', ['root' => $root, 'files' => [['from' => $from, 'to' => $to]]]) !== null;

        if ($renamed) {
            $this->forget($this->cacheKey('files', $server, $root));
        }

        return $renamed;
    }

    /**
     * Deletes a file or directory inside the server container.
     */
    public function deletePath(mixed $server, string $path): bool
    {
        if ($server === null) {
            return false;
        }

        $path = $this->normalizePath($path);

        if ($path === '/' || $path === '') {
            return false;
        }

        $root = rtrim(substr($path, 0, (int) strrpos($path, '/')), '/');
        $root = $root === '' ? '/' : $root;
        $name = substr($path, (int) strrpos($path, '/') + 1);

        if ($name === '' || $name === '.' || $name === '..') {
            return false;
        }

        $repository = $this->fileRepository($server);

        if ($repository !== null && method_exists($repository, 'deleteFiles')) {
            try {
                $repository->deleteFiles($root, [$name]);
                $this->forget($this->cacheKey('files', $server, $root));

                return true;
            } catch (Throwable) {
                return false;
            }
        }

        $deleted = $this->daemonRequest($server, 'POST', '/files/delete', ['root' => $root, 'files' => [$name]]) !== null;

        if ($deleted) {
            $this->forget($this->cacheKey('files', $server, $root));
        }

        return $deleted;
    }

    /**
     * Sends a power signal (`start`, `stop`, `restart`, `kill`) to the server.
     */
    public function power(mixed $server, string $signal): bool
    {
        if ($server === null || !in_array($signal, ['start', 'stop', 'restart', 'kill'], true)) {
            return false;
        }

        // Wings exposes power actions through `DaemonPowerRepository::send()`,
        // not `DaemonServerRepository` (which has no `power` method at all);
        // calling the wrong repository silently fails, so it is tried first.
        $repository = $this->powerRepository($server);

        if ($repository !== null && method_exists($repository, 'send')) {
            try {
                $repository->send($signal);

                return true;
            } catch (Throwable) {
                return false;
            }
        }

        // Fall back to a generic `power()` method for panel forks that expose
        // the action differently.
        $repository = $this->serverRepository($server);

        if ($repository !== null && method_exists($repository, 'power')) {
            try {
                $repository->power($signal);

                return true;
            } catch (Throwable) {
                return false;
            }
        }

        return $this->daemonRequest($server, 'POST', '/power', ['action' => $signal]) !== null;
    }

    /**
     * Sends a console command to the running server process (equivalent to
     * typing it into the panel's live console), so queued actions such as a
     * Workshop install are visible to anyone watching the console.
     */
    public function sendCommand(mixed $server, string $command): bool
    {
        if ($server === null || $command === '') {
            return false;
        }

        $repository = $this->repository('Pterodactyl\\Repositories\\Wings\\DaemonCommandRepository', $server);

        if ($repository !== null && method_exists($repository, 'send')) {
            try {
                $repository->send($command);

                return true;
            } catch (Throwable) {
                return false;
            }
        }

        return $this->daemonRequest($server, 'POST', '/commands', ['commands' => [$command]]) !== null;
    }

    /**
     * Tail of the server's live console output (stdout/stderr from the game
     * process), used to confirm specific startup/mod-update log lines have
     * actually appeared before acting on them (e.g. before restarting again).
     *
     * Not cached: callers poll this repeatedly for a short window right
     * after a restart, and stale data would defeat the purpose.
     *
     * @return list<string> Lines, oldest first.
     */
    public function consoleLogs(mixed $server): array
    {
        if ($server === null) {
            return [];
        }

        $repository = $this->serverRepository($server);

        if ($repository !== null && method_exists($repository, 'getLogs')) {
            try {
                $logs = $repository->getLogs();

                return $this->normalizeLogs($logs);
            } catch (Throwable) {
                // Fall through to the direct daemon call below.
            }
        }

        $response = $this->daemonRequest($server, 'GET', '/logs');

        return $this->normalizeLogs($response['data'] ?? $response ?? []);
    }

    /**
     * @return list<string>
     */
    private function normalizeLogs(mixed $logs): array
    {
        if (is_string($logs)) {
            $logs = preg_split('/\r\n|\r|\n/', $logs) ?: [];
        }

        if (!is_array($logs)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $line): string => (string) $line,
            array_filter($logs, static fn (mixed $line): bool => is_scalar($line)),
        ));
    }

    /**
     * @return array{state: string, is_suspended: bool, utilization: array<string, mixed>}|null
     */
    private function fetchDetails(mixed $server): ?array
    {
        $repository = $this->serverRepository($server);

        if ($repository !== null) {
            try {
                $details = $repository->getDetails();

                if (is_array($details)) {
                    return $this->normalizeDetails($details);
                }
            } catch (Throwable) {
                // Fall through to the direct daemon call.
            }
        }

        $response = $this->daemonRequest($server, 'GET', '');

        return is_array($response) ? $this->normalizeDetails($response) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchDirectory(mixed $server, string $path): array
    {
        $repository = $this->fileRepository($server);

        if ($repository !== null) {
            try {
                $entries = $repository->getDirectory($path);

                if (is_array($entries)) {
                    return array_values(array_filter($entries, 'is_array'));
                }
            } catch (Throwable) {
                // Fall through to the direct daemon call.
            }
        }

        $response = $this->daemonRequest($server, 'GET', '/files/list-directory', ['directory' => $path]);

        return is_array($response) ? array_values(array_filter($response, 'is_array')) : [];
    }

    private function fetchFile(mixed $server, string $path): ?string
    {
        $repository = $this->fileRepository($server);

        if ($repository !== null) {
            try {
                $contents = $repository->getContent($path, self::MAX_FILE_BYTES);

                if (is_string($contents)) {
                    return $contents;
                }
            } catch (Throwable) {
                // Fall through to the direct daemon call.
            }
        }

        $response = $this->daemonRequest($server, 'GET', '/files/contents', ['file' => $path], false);

        return is_string($response) ? $response : null;
    }

    /**
     * @param array<string, mixed> $details
     * @return array{state: string, is_suspended: bool, utilization: array<string, mixed>}
     */
    private function normalizeDetails(array $details): array
    {
        $utilization = $details['utilization'] ?? $details['resources'] ?? [];

        return [
            'state'        => is_string($details['state'] ?? null) ? strtolower($details['state']) : '',
            'is_suspended' => (bool) ($details['is_suspended'] ?? false),
            'utilization'  => is_array($utilization) ? $utilization : [],
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array{name: string, directory: bool, file: bool, size: int, mime: string, modified: string}
     */
    private function normalizeEntry(array $entry): array
    {
        $mime = (string) ($entry['mime'] ?? $entry['mimetype'] ?? '');
        $isFile = array_key_exists('file', $entry)
            ? (bool) $entry['file']
            : $mime !== 'inode/directory';

        return [
            'name'      => (string) ($entry['name'] ?? ''),
            'directory' => array_key_exists('directory', $entry) ? (bool) $entry['directory'] : !$isFile,
            'file'      => $isFile,
            'size'      => (int) ($entry['size'] ?? 0),
            'mime'      => $mime,
            'modified'  => (string) ($entry['modified_at'] ?? $entry['modified'] ?? ''),
        ];
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = '/' . ltrim($path, '/');

        // Traversal segments would let a crafted request escape the server root.
        $segments = array_values(array_filter(
            explode('/', $path),
            static fn (string $segment): bool => $segment !== '' && $segment !== '.' && $segment !== '..',
        ));

        return '/' . implode('/', $segments);
    }

    private function serverRepository(mixed $server): ?object
    {
        return $this->repository('Pterodactyl\\Repositories\\Wings\\DaemonServerRepository', $server);
    }

    private function powerRepository(mixed $server): ?object
    {
        return $this->repository('Pterodactyl\\Repositories\\Wings\\DaemonPowerRepository', $server);
    }

    private function fileRepository(mixed $server): ?object
    {
        return $this->repository('Pterodactyl\\Repositories\\Wings\\DaemonFileRepository', $server);
    }

    private function repository(string $class, mixed $server): ?object
    {
        if (!is_object($server) || !class_exists($class) || !function_exists('app')) {
            return null;
        }

        try {
            $repository = app($class);

            if (!is_object($repository) || !method_exists($repository, 'setServer')) {
                return null;
            }

            $bound = $repository->setServer($server);

            return is_object($bound) ? $bound : $repository;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Direct Wings call used when the panel repositories are unavailable.
     *
     * @param array<string, mixed> $payload
     * @return mixed Decoded JSON (or the raw body when $json is false), null on failure.
     */
    private function daemonRequest(
        mixed $server,
        string $method,
        string $endpoint,
        array $payload = [],
        bool $json = true,
        ?string $body = null,
    ): mixed {
        $node = $this->context->rawAttribute($server, 'node');
        $uuid = $this->context->attribute($server, ['uuid']);

        if ($node === null || $uuid === '' || !class_exists('Illuminate\\Support\\Facades\\Http')) {
            return null;
        }

        $scheme = $this->context->attribute($node, ['scheme']) ?: 'https';
        $host = $this->context->attribute($node, ['fqdn', 'name']);
        $port = (int) ($this->context->rawAttribute($node, 'daemonListen') ?? 8080);
        $token = $this->daemonToken($node);

        if ($host === '' || $token === '') {
            return null;
        }

        $url = sprintf('%s://%s:%d/api/servers/%s%s', $scheme, $host, $port > 0 ? $port : 8080, $uuid, $endpoint);

        try {
            $request = \Illuminate\Support\Facades\Http::withToken($token)
                ->timeout(5)
                ->acceptJson();

            if (strtoupper($method) !== 'POST' && strtoupper($method) !== 'PUT') {
                $response = $request->get($url, $payload);
            } elseif (strtoupper($method) === 'PUT') {
                $response = $request->put($url, $payload);
            } elseif ($body !== null) {
                $query = $payload === [] ? '' : '?' . http_build_query($payload);
                $response = $request->withBody($body, 'text/plain')->post($url . $query);
            } else {
                $response = $request->post($url, $payload);
            }

            if (!$response->successful()) {
                return null;
            }

            return $json ? $response->json() : $response->body();
        } catch (Throwable) {
            return null;
        }
    }

    private function daemonToken(mixed $node): string
    {
        $token = $this->context->attribute($node, ['daemon_token', 'daemonSecret']);

        if ($token === '') {
            return '';
        }

        $id = $this->context->attribute($node, ['daemon_token_id']);

        return $id === '' ? $token : $id . '.' . $token;
    }

    private function cacheKey(string $kind, mixed $server, string $path): string
    {
        $identifier = $this->context->attribute($server, ['uuid', 'uuidShort', 'id']);

        return 'pteromods.dayz.' . $kind . '.' . md5($identifier . '|' . $path);
    }

    private function forget(string $key): void
    {
        if (!class_exists('Illuminate\\Support\\Facades\\Cache')) {
            return;
        }

        try {
            \Illuminate\Support\Facades\Cache::forget($key);
        } catch (Throwable) {
            // Cache invalidation is best-effort only.
        }
    }

}
