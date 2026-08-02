<?php

declare(strict_types=1);

namespace PteroMods\Services\DayZ;

/**
 * Picks the address players (and query tools) can actually reach.
 *
 * Pterodactyl allocations frequently store the node's *internal* address (for
 * example `10.2.2.105`), which is unreachable from the panel when the panel and
 * the node run on different machines. The public alias configured on the
 * allocation, or the node FQDN, is used instead whenever the raw allocation IP
 * is a private, loopback, or wildcard address.
 */
final class HostAddressResolver
{
    private const WILDCARD = ['0.0.0.0', '::', '[::]', '*'];

    /**
     * Returns the first routable candidate, falling back to the first non-empty
     * candidate when every candidate is private.
     *
     * @param list<string|null> $candidates Ordered by preference.
     */
    public function resolve(array $candidates): string
    {
        $usable = [];

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate === '' || $this->isWildcard($candidate)) {
                continue;
            }

            if ($this->isPublic($candidate)) {
                return $candidate;
            }

            $usable[] = $candidate;
        }

        return $usable[0] ?? '';
    }

    /**
     * True when the value is a hostname or a globally routable IP address.
     */
    public function isPublic(string $host): bool
    {
        $host = trim($host);

        if ($host === '' || $this->isWildcard($host)) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            // Hostnames (node FQDNs) are assumed to resolve publicly.
            return true;
        }

        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /**
     * True when the value is a private, loopback, or wildcard address.
     */
    public function isPrivate(string $host): bool
    {
        return $host !== '' && !$this->isPublic($host);
    }

    private function isWildcard(string $host): bool
    {
        return in_array($host, self::WILDCARD, true);
    }
}
