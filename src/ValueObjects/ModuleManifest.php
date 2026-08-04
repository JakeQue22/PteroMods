<?php

declare(strict_types=1);

namespace PteroMods\ValueObjects;

use InvalidArgumentException;

/**
 * Immutable module manifest value object.
 */
final class ModuleManifest
{
    /**
     * @param list<string> $supports
     * @param list<string> $tabs
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly string $name,
        public readonly string $slug,
        public readonly string $version,
        public readonly string $author,
        public readonly string $description,
        public readonly string $path,
        public readonly array $supports,
        public readonly array $tabs,
        public readonly array $extra = [],
    ) {
        if ($this->name === '' || $this->slug === '' || $this->version === '') {
            throw new InvalidArgumentException('Manifest name, slug, and version are required.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload, string $path): self
    {
        return new self(
            name: (string) ($payload['name'] ?? ''),
            slug: (string) ($payload['slug'] ?? ''),
            version: (string) ($payload['version'] ?? ''),
            author: (string) ($payload['author'] ?? 'Unknown'),
            description: (string) ($payload['description'] ?? ''),
            path: $path,
            supports: array_values(array_map('strval', $payload['supports'] ?? [])),
            tabs: array_values(array_map('strval', $payload['tabs'] ?? [])),
            extra: $payload,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'version' => $this->version,
            'author' => $this->author,
            'description' => $this->description,
            'path' => $this->path,
            'supports' => $this->supports,
            'tabs' => $this->tabs,
        ] + $this->extra;
    }
}
