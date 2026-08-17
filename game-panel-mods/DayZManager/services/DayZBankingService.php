<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use Throwable;

/**
 * Reads and writes LBmaster LB Banking player balances.
 *
 * Each player has a JSON file at
 * `/profiles/LBmaster/Data/LBBanking/Players/<steamid64>.json` holding the
 * current bank balance in a `currentMoney` field. Balances are read in bulk
 * (one directory listing plus one read per known player file) and cached so the
 * player manager can show a Money column without slowing the page down.
 */
final class DayZBankingService
{
    public const PLAYER_DIRECTORY = '/profiles/LBmaster/Data/LBBanking/Players';

    private const CACHE_SECONDS = 60;
    private const MAX_PLAYER_FILES = 300;
    private const MONEY_KEY = 'currentMoney';

    public function __construct(
        private readonly DayZPanelGateway $gateway = new DayZPanelGateway(),
        private readonly DayZStaleCacheService $staleCache = new DayZStaleCacheService(),
    ) {
    }

    /**
     * Bank balances keyed by Steam64 ID.
     *
     * @return array<string, float>
     */
    public function balances(mixed $server): array
    {
        $cacheKey = $this->cacheKey($server);

        if ($cacheKey === '') {
            return $this->readBalances($server);
        }

        /** @var array<string, float>|null $balances */
        $balances = $this->staleCache->remember(
            $cacheKey,
            self::CACHE_SECONDS,
            self::CACHE_SECONDS * 20,
            fn (): array => $this->readBalances($server),
            [],
        );

        return is_array($balances) ? $balances : [];
    }

    /**
     * Current balance of a single player, or null when they have no bank file.
     */
    public function balance(mixed $server, string $steam64): ?float
    {
        $steam64 = $this->normalizeSteam64($steam64);

        if ($steam64 === '') {
            return null;
        }

        $data = $this->readPlayerFile($server, $steam64);

        return $data === null ? null : $this->extractMoney($data);
    }

    /**
     * Sets a player's bank balance to the given amount.
     *
     * @return array<string, mixed>
     */
    public function setBalance(mixed $server, string $steam64, float $amount): array
    {
        $steam64 = $this->normalizeSteam64($steam64);

        if ($steam64 === '') {
            return ['status' => 'error', 'message' => 'A valid Steam64 ID is required to alter bank money.'];
        }

        if ($amount < 0) {
            return ['status' => 'error', 'message' => 'Bank money cannot be negative.'];
        }

        $data = $this->readPlayerFile($server, $steam64);

        if ($data === null) {
            return ['status' => 'error', 'message' => 'No LB Banking file was found for ' . $steam64 . '.'];
        }

        $previous = $this->extractMoney($data);
        $amount = round($amount, 2);

        if (!$this->applyMoney($data, $amount)) {
            return ['status' => 'error', 'message' => 'The LB Banking file does not contain a currentMoney value.'];
        }

        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($encoded)) {
            return ['status' => 'error', 'message' => 'The LB Banking file could not be re-encoded.'];
        }

        if (!$this->gateway->writeFile($server, $this->playerPath($steam64), $encoded)) {
            return ['status' => 'error', 'message' => 'Could not write the LB Banking file (server may be offline).'];
        }

        $this->forgetCache($server);

        return [
            'status' => 'saved',
            'steam64' => $steam64,
            'previous_money' => $previous,
            'current_money' => $amount,
            'message' => 'Bank money updated to ' . $amount . '.',
        ];
    }

    /**
     * @return array<string, float>
     */
    private function readBalances(mixed $server): array
    {
        $balances = [];

        try {
            $entries = $this->gateway->listDirectory($server, self::PLAYER_DIRECTORY);
        } catch (Throwable) {
            return [];
        }

        $read = 0;

        foreach ($entries as $entry) {
            if ($read >= self::MAX_PLAYER_FILES) {
                break;
            }

            $name = trim((string) ($entry['name'] ?? ''));

            if (!($entry['file'] ?? false) || strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'json') {
                continue;
            }

            $steam64 = $this->normalizeSteam64((string) pathinfo($name, PATHINFO_FILENAME));

            if ($steam64 === '') {
                continue;
            }

            $read++;
            $data = $this->readPlayerFile($server, $steam64);
            $money = $data === null ? null : $this->extractMoney($data);

            if ($money !== null) {
                $balances[$steam64] = $money;
            }
        }

        return $balances;
    }

    /**
     * @return array<mixed>|null
     */
    private function readPlayerFile(mixed $server, string $steam64): ?array
    {
        try {
            $raw = $this->gateway->readFileFresh($server, $this->playerPath($steam64));
        } catch (Throwable) {
            return null;
        }

        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        if (str_starts_with($raw, "\xef\xbb\xbf")) {
            $raw = substr($raw, 3);
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<mixed> $data
     */
    private function extractMoney(array $data): ?float
    {
        foreach ($data as $key => $value) {
            if ($key === self::MONEY_KEY && is_numeric($value)) {
                return (float) $value;
            }

            if (is_array($value)) {
                $nested = $this->extractMoney($value);

                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $data
     */
    private function applyMoney(array &$data, float $amount): bool
    {
        foreach ($data as $key => &$value) {
            if ($key === self::MONEY_KEY && is_numeric($value)) {
                $value = $this->matchesIntegerStyle($value) ? (int) round($amount) : $amount;

                return true;
            }

            if (is_array($value) && $this->applyMoney($value, $amount)) {
                return true;
            }
        }

        return false;
    }

    private function matchesIntegerStyle(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && floor($value) === $value);
    }

    private function playerPath(string $steam64): string
    {
        return self::PLAYER_DIRECTORY . '/' . $steam64 . '.json';
    }

    private function normalizeSteam64(string $steam64): string
    {
        $steam64 = trim($steam64);

        return preg_match('/^\d{15,20}$/', $steam64) === 1 ? $steam64 : '';
    }

    private function cacheKey(mixed $server): string
    {
        $id = '';

        if (is_array($server)) {
            $id = (string) ($server['uuid'] ?? $server['uuidShort'] ?? $server['id'] ?? '');
        } elseif (is_object($server)) {
            try {
                $id = (string) ($server->uuid ?? $server->uuidShort ?? $server->id ?? '');
            } catch (Throwable) {
                $id = '';
            }
        }

        return $id !== '' ? 'pteromods.dayz.banking.balances.' . md5($id) : '';
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
        } catch (Throwable) {
            // Best-effort cache invalidation only.
        }
    }
}
