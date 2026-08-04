<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

/**
 * Decides whether a Pterodactyl server is a DayZ server.
 *
 * The panel has no canonical "game" field, so the egg name, its docker image,
 * and its startup command are inspected for the identifiers listed in the
 * module manifest. This keeps custom eggs working as long as they mention DayZ
 * somewhere in their definition.
 */
final class DayZEggDetector
{
    private const NEEDLES = ['dayz', 'dayzserver', 'dz_server', 'dayzsa'];

    public function __construct(
        private readonly DayZServerContext $context = new DayZServerContext(),
    ) {
    }

    public function supports(mixed $server): bool
    {
        if ($server === null) {
            return false;
        }

        $haystacks = [
            $this->context->attribute($server, ['startup']),
            $this->context->attribute($server, ['image', 'docker_image']),
            $this->context->attribute($server, ['name']),
        ];

        $egg = $this->context->rawAttribute($server, 'egg');

        if ($egg !== null) {
            $haystacks[] = $this->context->attribute($egg, ['name']);
            $haystacks[] = $this->context->attribute($egg, ['description']);
            $haystacks[] = $this->context->attribute($egg, ['docker_image']);
            $haystacks[] = $this->context->attribute($egg, ['startup']);
        }

        foreach ($haystacks as $haystack) {
            $haystack = strtolower($haystack);

            if ($haystack === '') {
                continue;
            }

            foreach (self::NEEDLES as $needle) {
                if (str_contains($haystack, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }
}
