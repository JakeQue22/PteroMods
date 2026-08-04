<?php

declare(strict_types=1);

namespace PteroMods\Services\DayZ;

use InvalidArgumentException;

/**
 * Parses Workshop IDs from raw IDs or Steam Workshop URLs.
 */
final class WorkshopReferenceParser
{
    public function parse(string $reference): string
    {
        $reference = trim($reference);

        if (preg_match('/^\d+$/', $reference) === 1) {
            return $reference;
        }

        if (preg_match('/[?&]id=(\d+)/', $reference, $matches) === 1) {
            return $matches[1];
        }

        throw new InvalidArgumentException(sprintf('Unsupported workshop reference: %s', $reference));
    }
}
