<?php

declare(strict_types=1);

namespace PteroMods\Services\DayZ;

/**
 * Computes dependency-aware workshop installation plans.
 */
final class WorkshopDependencyPlanner
{
    public const COMMUNITY_FRAMEWORK_ID = '1559212036';

    /**
     * @param array<string, array{dependencies?: list<string>, requires_cf?: bool}> $metadata
     * @return list<string>
     */
    public function buildPlan(string $rootWorkshopId, array $metadata): array
    {
        $ordered = [];
        $visited = [];

        $visit = function (string $workshopId) use (&$visit, &$ordered, &$visited, $metadata): void {
            if (isset($visited[$workshopId])) {
                return;
            }

            $visited[$workshopId] = true;
            $dependencies = $metadata[$workshopId]['dependencies'] ?? [];

            foreach ($dependencies as $dependency) {
                $visit($dependency);
            }

            if (($metadata[$workshopId]['requires_cf'] ?? false) === true && !isset($visited[self::COMMUNITY_FRAMEWORK_ID])) {
                $visit(self::COMMUNITY_FRAMEWORK_ID);
            }

            $ordered[] = $workshopId;
        };

        $visit($rootWorkshopId);

        return array_values(array_unique($ordered));
    }
}
