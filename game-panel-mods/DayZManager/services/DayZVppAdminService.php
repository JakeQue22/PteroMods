<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

/**
 * Manages the VPP Admin Tools SuperAdmins list.
 *
 * The file lives at /profiles/VPPAdminTools/Permissions/SuperAdmins/SuperAdmins.txt
 * on the DayZ server.  Each line contains a Steam64 ID, optionally followed by
 * whitespace and a comment beginning with //.
 *
 * The Steam64 ID 76561197992590837 is protected: it may never be removed.
 */
final class DayZVppAdminService
{
    public const PROTECTED_STEAM64 = '76561197992590837';

    private const FILE_PATH  = '/profiles/VPPAdminTools/Permissions/SuperAdmins/SuperAdmins.txt';
    private const CACHE_SECONDS = 120;

    public function __construct(
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
        private readonly DayZStaleCacheService $staleCache = new DayZStaleCacheService(),
    ) {
    }

    /**
     * Returns every Steam64 ID currently in the SuperAdmins file.
     *
     * @return list<string>
     */
    public function list(mixed $server): array
    {
        $cacheKey = $this->cacheKey($server);

        if ($cacheKey === '') {
            return [];
        }

        /** @var list<string>|null $ids */
        $ids = $this->staleCache->remember(
            $cacheKey,
            self::CACHE_SECONDS,
            self::CACHE_SECONDS * 20,
            fn (): array => $this->readIds($server),
            [],
        );

        return is_array($ids) ? $ids : [];
    }

    /**
     * Returns true when the given Steam64 ID is in the SuperAdmins file.
     */
    public function isAdmin(mixed $server, string $steam64): bool
    {
        return in_array(trim($steam64), $this->list($server), true);
    }

    /**
     * Appends a Steam64 ID to the SuperAdmins file.
     *
     * @return array<string, mixed>
     */
    public function add(mixed $server, string $steam64, string $nickname = ''): array
    {
        $steam64 = trim($steam64);

        if ($steam64 === '') {
            return ['status' => 'error', 'message' => 'Steam64 ID is required.'];
        }

        $content = $this->gateway->readFile($server, self::FILE_PATH) ?? '';
        $ids     = $this->parseIds($content);

        if (in_array($steam64, $ids, true)) {
            return ['status' => 'already_admin', 'message' => 'Player is already a SuperAdmin.'];
        }

        $comment = $nickname !== '' ? '        // ' . $nickname : '';
        $line    = $steam64 . $comment;

        $content = rtrim($content);
        $updated = $content !== '' ? $content . "\n" . $line . "\n" : $line . "\n";

        if (!$this->gateway->writeFile($server, self::FILE_PATH, $updated)) {
            return ['status' => 'error', 'message' => 'Could not write SuperAdmins.txt (server may be offline).'];
        }

        $this->forgetCache($server);

        return [
            'status'   => 'added',
            'steam64'  => $steam64,
            'nickname' => $nickname,
            'message'  => 'Player added as SuperAdmin. Changes take effect after the next server restart.',
        ];
    }

    /**
     * Removes a Steam64 ID from the SuperAdmins file.
     *
     * The protected ID (76561197992590837) can never be removed.
     *
     * @return array<string, mixed>
     */
    public function remove(mixed $server, string $steam64): array
    {
        $steam64 = trim($steam64);

        if ($steam64 === '') {
            return ['status' => 'error', 'message' => 'Steam64 ID is required.'];
        }

        if ($steam64 === self::PROTECTED_STEAM64) {
            return ['status' => 'error', 'message' => 'This SuperAdmin cannot be removed.'];
        }

        $content = $this->gateway->readFile($server, self::FILE_PATH);

        if ($content === null) {
            return ['status' => 'error', 'message' => 'SuperAdmins.txt could not be read.'];
        }

        $lines   = explode("\n", str_replace("\r\n", "\n", $content));
        $updated = [];

        foreach ($lines as $line) {
            // A line belongs to this ID when its first non-whitespace token is the Steam64.
            $token = strtok(trim($line), " \t");

            if ($token === $steam64) {
                continue;
            }

            $updated[] = $line;
        }

        $newContent = implode("\n", $updated);

        // Remove any trailing blank lines that were produced by the deletion,
        // while preserving a single trailing newline for Unix convention.
        $newContent = rtrim($newContent) . "\n";

        if (!$this->gateway->writeFile($server, self::FILE_PATH, $newContent)) {
            return ['status' => 'error', 'message' => 'Could not write SuperAdmins.txt (server may be offline).'];
        }

        $this->forgetCache($server);

        return [
            'status'  => 'removed',
            'steam64' => $steam64,
            'message' => 'Player removed from SuperAdmins. Changes take effect after the next server restart.',
        ];
    }

    /**
     * Parses all Steam64 IDs from the file content.
     *
     * @return list<string>
     */
    private function parseIds(string $content): array
    {
        $ids = [];

        foreach (explode("\n", str_replace("\r\n", "\n", $content)) as $line) {
            $line = trim($line);

            // Skip blank lines and comment-only lines.
            if ($line === '' || str_starts_with($line, '//')) {
                continue;
            }

            // The first whitespace-delimited token is the Steam64 ID.
            $token = strtok($line, " \t");

            if ($token !== false && $token !== '' && ctype_digit($token) && strlen($token) >= 15) {
                $ids[] = $token;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<string>
     */
    private function readIds(mixed $server): array
    {
        $content = $this->gateway->readFile($server, self::FILE_PATH);

        return $content === null ? [] : $this->parseIds($content);
    }

    private function cacheKey(mixed $server): string
    {
        if (is_array($server)) {
            $id = (string) ($server['uuid'] ?? $server['uuidShort'] ?? $server['id'] ?? '');

            return $id !== '' ? 'pteromods.dayz.vpp_admins.' . md5($id) : '';
        }

        if (is_object($server)) {
            try {
                if (method_exists($server, 'getAttribute')) {
                    $id = (string) ($server->getAttribute('uuid')
                        ?? $server->getAttribute('uuidShort')
                        ?? $server->getAttribute('id')
                        ?? '');

                    if ($id !== '') {
                        return 'pteromods.dayz.vpp_admins.' . md5($id);
                    }
                }

                $id = (string) ($server->uuid ?? $server->uuidShort ?? $server->id ?? '');

                return $id !== '' ? 'pteromods.dayz.vpp_admins.' . md5($id) : '';
            } catch (\Throwable) {
                return '';
            }
        }

        return '';
    }

    private function forgetCache(mixed $server): void
    {
        if (!class_exists('Illuminate\\Support\\Facades\\Cache')) {
            return;
        }

        $key = $this->cacheKey($server);

        if ($key === '') {
            return;
        }

        try {
            \Illuminate\Support\Facades\Cache::forget($key);
            \Illuminate\Support\Facades\Cache::forget($key . '.lock');
        } catch (\Throwable) {
            // Best-effort cache invalidation only.
        }
    }
}
