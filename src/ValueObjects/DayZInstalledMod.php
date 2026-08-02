<?php

declare(strict_types=1);

namespace PteroMods\ValueObjects;

/**
 * DayZ installed mod card payload.
 */
final class DayZInstalledMod
{
    /**
     * @param list<string> $dependencies
     */
    public function __construct(
        public readonly string $workshopId,
        public readonly string $title,
        public readonly string $folderName,
        public readonly string $author,
        public readonly string $thumbnail,
        public readonly string $currentVersion,
        public readonly string $latestVersion,
        public readonly string $fileSize,
        public readonly bool $enabled,
        public readonly array $dependencies,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'workshop_id' => $this->workshopId,
            'title' => $this->title,
            'folder_name' => $this->folderName,
            'author' => $this->author,
            'thumbnail' => $this->thumbnail,
            'current_version' => $this->currentVersion,
            'latest_version' => $this->latestVersion,
            'file_size' => $this->fileSize,
            'enabled' => $this->enabled,
            'dependencies' => $this->dependencies,
        ];
    }
}
