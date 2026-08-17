<?php

declare(strict_types=1);

namespace GamePanelMods\DayZManager\Services;

use Throwable;

/**
 * Resolves DayZ inventory items to verified DayZ wiki pages and images.
 *
 * The resolver prefers deterministic identifiers (class names, internal ids and
 * canonical names) and only falls back to a tightly-scoped fuzzy match when it
 * can still verify the candidate page through item metadata and page content.
 */
final class DayZInventoryItemResolverService
{
    private const WIKI_SOURCES = [
        ['name' => 'DayZ Wiki', 'api_url' => 'https://dayz.wiki.gg/api.php'],
        ['name' => 'DayZ Fandom', 'api_url' => 'https://dayz.fandom.com/api.php'],
        ['name' => 'DayZ Archive', 'api_url' => 'https://dayz-archive.fandom.com/api.php'],
    ];
    private const CACHE_REFRESH_SECONDS = 604800; // 7 days
    private const NEGATIVE_CACHE_TTL = 86400;     // 1 day — re-try failed lookups after this
    private const SEARCH_LIMIT = 6;
    private const PAGE_IMAGE_WIDTH = 320;

    /** @var array<string, mixed> */
    private array $requestCache = [];

    /** @var array{name:string,api_url:string} */
    private array $wikiSource = self::WIKI_SOURCES[0];

    private ?bool $tableExists = null;

    /** @var array<string, string> */
    private const VARIANT_ALIAS_MAP = [
        'RBStripes' => 'Red Black Stripes',
        'LBStripes' => 'Light Blue Stripes',
        'YStripes' => 'Yellow Stripes',
        'WGStripes' => 'White Green Stripes',
        'OliveGreen' => 'Olive Green',
        'DarkBlue' => 'Dark Blue',
        'WhiteBlue' => 'White Blue',
        'BlackRed' => 'Black Red',
        'BlueCamo' => 'Blue Camo',
        'MVPCamo' => 'MVP Camo',
        'NBCGreen' => 'NBC Green',
        'NBCBlue' => 'NBC Blue',
        'PoliceCamo' => 'Police Camo',
        'GorkaFlora' => 'Gorka Flora',
        'TTsKO' => 'TTsKO',
        'PautRev' => 'Pautrev',
        'ButterflyRev' => 'Butterfly Reversed',
    ];

    /** @var array<string, string> */
    private const ITEM_TITLE_ALIASES = [
        'tactical bacon can' => 'Canned Bacon',
        'tactical bacon' => 'Canned Bacon',
        'cmn bacon can' => 'Canned Bacon',
    ];

    /** @var list<string> */
    private const GENERIC_IMAGE_TERMS = [
        'logo',
        'wiki',
        'favicon',
        'icon',
        'navbox',
        'background',
        'banner',
        'header',
        'screenshot',
        'loading',
        'disambiguation',
        'map',
    ];

    /** @var list<string> */
    private const ITEM_CATEGORY_TERMS = [
        'item',
        'weapon',
        'clothing',
        'apparel',
        'headgear',
        'vest',
        'backpack',
        'container',
        'equipment',
        'attachment',
        'tool',
        'consumable',
        'food',
        'drink',
        'medical',
        'ammunition',
        'ammo',
        'magazine',
        'grenade',
        'explosive',
        'rifle',
        'pistol',
        'shotgun',
        'carbine',
        'helmet',
        'gloves',
        'boots',
        'pants',
        'jacket',
        'shirt',
        'coat',
        'bag',
        'mask',
        'optic',
    ];

    public function __construct(
        private readonly DayZStaleCacheService $staleCache = new DayZStaleCacheService(),
    ) {
    }

    /**
     * @param list<mixed> $items
     * @return array<string, array<string, mixed>>
     */
    public function resolveBatch(array $items, bool $forceRefresh = false): array
    {
        $resolved = [];

        foreach ($items as $item) {
            $normalized = $this->normalizeInventoryItem($item);

            if ($normalized === null) {
                continue;
            }

            $lookupKey = $normalized['lookup_key'];

            if (isset($resolved[$lookupKey])) {
                continue;
            }

            $cached = $this->lookupCachedResolution($normalized, $forceRefresh);

            // Return cached positive hit (resolved + has image, not stale).
            if ($cached !== null
                && ($cached['resolved'] ?? false)
                && trim((string) ($cached['image_url'] ?? '')) !== ''
                && !$this->isStaleResolution($cached)
            ) {
                $resolved[$lookupKey] = $cached;
                continue;
            }

            // Return cached negative hit — skip the wiki fetch until the short TTL expires.
            if ($cached !== null
                && !($cached['resolved'] ?? false)
                && !$this->isStaleResolution($cached, self::NEGATIVE_CACHE_TTL)
            ) {
                $resolved[$lookupKey] = $cached;
                continue;
            }

            $fresh = $this->resolveFresh($normalized);

            if (($fresh['resolved'] ?? false) === true) {
                $this->persistResolution($normalized, $fresh);
                $resolved[$lookupKey] = $fresh;
                continue;
            }

            // Persist negative result so repeated requests do not re-hit the wiki.
            $this->persistResolution($normalized, $fresh);

            $resolved[$lookupKey] = $cached !== null ? $this->mergeFallbackResolution($normalized, $cached) : $fresh;
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    private function lookupCachedResolution(array $item, bool $forceRefresh): ?array
    {
        if ($forceRefresh || !$this->resolutionTableExists()) {
            return null;
        }

        foreach ($this->lookupProbes($item) as $probe) {
            try {
                $row = \Illuminate\Support\Facades\DB::table('dayz_inventory_item_resolutions')
                    ->where('lookup_type', $probe['type'])
                    ->where('lookup_normalized', $probe['normalized'])
                    ->first();
            } catch (Throwable) {
                return null;
            }

            if ($row === null) {
                continue;
            }

            return $this->hydrateResolutionRow((array) $row, $item);
        }

        return null;
    }

    private function isStaleResolution(array $resolution, int $ttl = self::CACHE_REFRESH_SECONDS): bool
    {
        $refreshedAt = trim((string) ($resolution['refreshed_at'] ?? ''));

        if ($refreshedAt === '') {
            return true;
        }

        $timestamp = strtotime($refreshedAt);

        return $timestamp === false || (time() - $timestamp) >= $ttl;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function resolveFresh(array $item): array
    {
        $best = null;

        foreach (self::WIKI_SOURCES as $source) {
            $this->wikiSource = $source;
            $this->requestCache = [];
            $candidate = $this->resolveFreshFromSource($item);

            if (($candidate['resolved'] ?? false) && trim((string) ($candidate['image_url'] ?? '')) !== '') {
                return $candidate;
            }

            if (($candidate['resolved'] ?? false)
                && ($best === null || (int) ($candidate['confidence'] ?? 0) > (int) ($best['confidence'] ?? 0))
            ) {
                $best = $candidate;
            }
        }

        return $best ?? $this->unresolved($item, 'No supported DayZ wiki matched this item.');
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function resolveFreshFromSource(array $item): array
    {
        $context = $this->buildContext($item);
        $candidateTitles = $this->discoverCandidateTitles($context);

        if ($candidateTitles === []) {
            return $this->unresolved($item, 'No wiki candidates found.');
        }

        $pages = $this->loadPageDetails($candidateTitles);

        if ($pages === []) {
            return $this->unresolved($item, 'No wiki pages could be loaded.');
        }

        $scored = [];

        foreach ($pages as $page) {
            $candidate = $this->scorePageCandidate($page, $context);

            if ($candidate !== null) {
                $scored[] = $candidate;
            }
        }

        if ($scored === []) {
            return $this->unresolved($item, 'No page passed verification.');
        }

        usort($scored, static function (array $left, array $right): int {
            $score = ((int) ($right['score'] ?? 0)) <=> ((int) ($left['score'] ?? 0));

            if ($score !== 0) {
                return $score;
            }

            return strcmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
        });

        $best = $scored[0];
        $runnerUp = $scored[1] ?? null;

        $bestScore = (int) ($best['score'] ?? 0);
        $runnerScore = (int) ($runnerUp['score'] ?? 0);
        $margin = $bestScore - $runnerScore;

        if ($bestScore < 70 || ($bestScore < 100 && $margin < 12)) {
            return $this->unresolved($item, 'Candidate confidence was insufficient.');
        }

        $image = $this->resolveImageForPage($best, $context);
        $aliases = $this->dedupeStrings(array_merge(
            [$best['display_name'] ?? '', $best['title'] ?? '', $item['name'] ?? ''],
            $best['aliases'] ?? [],
            $best['class_names'] ?? [],
            $best['internal_ids'] ?? []
        ));
        $category = $this->firstNonEmpty([
            $best['type_field'] ?? null,
            $best['category_field'] ?? null,
            $this->pickCategoryLabel($best['categories'] ?? []),
        ]);
        $type = $this->firstNonEmpty([
            $best['type_field'] ?? null,
            $this->pickTypeLabel($best['categories'] ?? []),
        ]);

        return [
            'lookup_key' => $item['lookup_key'],
            'resolved' => true,
            'display_name' => $best['display_name'] ?? $context['fallback_name'],
            'canonical_name' => $best['display_name'] ?? $context['fallback_name'],
            'wiki_title' => (string) ($best['title'] ?? ''),
            'wiki_url' => (string) ($best['fullurl'] ?? ''),
            'image_url' => (string) ($image['url'] ?? ''),
            'image_name' => (string) ($image['name'] ?? ''),
            'variant' => (string) ($best['resolved_variant'] ?? ($context['variant_label'] ?? '')),
            'classname' => (string) ($item['class_name'] ?? ''),
            'internal_id' => (string) ($item['internal_id'] ?? ''),
            'category' => $category !== null ? $category : '',
            'type' => $type !== null ? $type : '',
            'aliases' => $aliases,
            'matched_by' => (string) ($best['matched_by'] ?? 'unknown'),
            'confidence' => $bestScore,
            'verification' => [
                'wiki_source' => $this->wikiSource['name'],
                'score' => $bestScore,
                'runner_up_score' => $runnerScore,
                'title_match' => $best['title_match'] ?? '',
                'class_names' => $best['class_names'] ?? [],
                'internal_ids' => $best['internal_ids'] ?? [],
                'categories' => $best['categories'] ?? [],
                'image_name' => (string) ($image['name'] ?? ''),
            ],
            'refreshed_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function unresolved(array $item, string $reason): array
    {
        return [
            'lookup_key' => $item['lookup_key'],
            'resolved' => false,
            'display_name' => $item['fallback_name'],
            'canonical_name' => '',
            'wiki_title' => '',
            'wiki_url' => '',
            'image_url' => '',
            'image_name' => '',
            'variant' => (string) ($item['variant_label'] ?? ''),
            'classname' => (string) ($item['class_name'] ?? ''),
            'internal_id' => (string) ($item['internal_id'] ?? ''),
            'category' => '',
            'type' => '',
            'aliases' => [],
            'matched_by' => 'unresolved',
            'confidence' => 0,
            'verification' => ['reason' => $reason],
            'refreshed_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param mixed $item
     * @return array<string, mixed>|null
     */
    private function normalizeInventoryItem(mixed $item): ?array
    {
        $source = null;

        if (is_string($item)) {
            $source = ['className' => $item];
        } elseif (is_object($item)) {
            $source = (array) $item;
        } elseif (is_array($item)) {
            $source = $item;
        }

        if ($source === null) {
            return null;
        }

        $className = $this->firstString($source, ['className', 'classname', 'class', 'type', 'item', 'item_class', 'itemClass']);
        $internalId = $this->firstString($source, ['itemId', 'item_id', 'internalId', 'internal_id', 'identifier', 'id']);
        $name = $this->firstString($source, ['name', 'itemName', 'item_name', 'displayName', 'display_name', 'label', 'title']);

        if ($className === '' && $internalId === '' && $name === '') {
            return null;
        }

        [$baseClass, $variantKey] = $this->splitClassName($className);
        $variantLabel = $variantKey !== '' ? $this->bestVariantLabel($variantKey) : '';
        $fallbackName = $name !== '' ? $name : ($className !== '' ? $this->humanizeClassName($className) : $internalId);

        return [
            'lookup_key' => $this->lookupKey($className, $internalId, $name),
            'class_name' => $className,
            'base_class_name' => $baseClass,
            'variant_key' => $variantKey,
            'variant_label' => $variantLabel,
            'internal_id' => $internalId,
            'name' => $name,
            'fallback_name' => $fallbackName !== '' ? $fallbackName : 'Unknown item',
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return list<array{type:string, normalized:string}>
     */
    private function lookupProbes(array $item): array
    {
        $probes = [];

        $append = function (string $type, string $value) use (&$probes): void {
            $normalized = $this->normalizeLookupValue($value);

            if ($normalized === '') {
                return;
            }

            foreach ($probes as $probe) {
                if ($probe['type'] === $type && $probe['normalized'] === $normalized) {
                    return;
                }
            }

            $probes[] = ['type' => $type, 'normalized' => $normalized];
        };

        $append('classname', (string) ($item['class_name'] ?? ''));
        $append('internal_id', (string) ($item['internal_id'] ?? ''));
        $append('name', (string) ($item['name'] ?? ''));
        $append('alias', (string) ($item['fallback_name'] ?? ''));

        if (($item['base_class_name'] ?? '') !== '' && ($item['base_class_name'] ?? '') !== ($item['class_name'] ?? '')) {
            $append('alias', $this->humanizeClassName((string) $item['base_class_name']));
        }

        return $probes;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function buildContext(array $item): array
    {
        $className = (string) ($item['class_name'] ?? '');
        $baseClass = (string) ($item['base_class_name'] ?? '');
        $name = (string) ($item['name'] ?? '');
        $internalId = (string) ($item['internal_id'] ?? '');
        $classLabel = $className !== '' ? $this->humanizeClassName($className) : '';
        $baseLabel = $baseClass !== '' ? $this->humanizeClassName($baseClass) : '';
        $variantKey = (string) ($item['variant_key'] ?? '');
        $variantAliases = $variantKey !== '' ? $this->variantSearchTerms($variantKey) : [];
        $knownTitle = self::ITEM_TITLE_ALIASES[$this->normalizeForCompare($classLabel)]
            ?? self::ITEM_TITLE_ALIASES[$this->normalizeForCompare($name)]
            ?? '';

        $searchTerms = $this->dedupeStrings(array_merge(
            [$knownTitle, $className, str_replace('_', ' ', $className), $classLabel, $baseClass, $baseLabel, $name, $internalId],
            $variantAliases === [] || $baseLabel === ''
                ? []
                : array_merge(
                    array_map(static fn (string $variant): string => trim($baseLabel . ' ' . $variant), $variantAliases),
                    array_map(static fn (string $variant): string => trim($baseLabel . ' (' . $variant . ')'), $variantAliases),
                )
        ));

        $directTitles = $this->dedupeStrings(array_merge(
            [$knownTitle, $name, $classLabel, $baseLabel],
            $variantAliases === [] || $baseLabel === ''
                ? []
                : array_merge(
                    array_map(static fn (string $variant): string => trim($baseLabel . ' ' . $variant), $variantAliases),
                    array_map(static fn (string $variant): string => trim($baseLabel . ' (' . $variant . ')'), $variantAliases),
                )
        ));

        return [
            'item' => $item,
            'class_name' => $className,
            'class_norm' => $this->normalizeForCompare($className),
            'class_label' => $classLabel,
            'class_label_norm' => $this->normalizeForCompare($classLabel),
            'base_class_name' => $baseClass,
            'base_label' => $baseLabel,
            'base_label_norm' => $this->normalizeForCompare($baseLabel),
            'variant_key' => $variantKey,
            'variant_label' => (string) ($item['variant_label'] ?? ''),
            'variant_norms' => array_values(array_filter(array_map(fn (string $variant): string => $this->normalizeForCompare($variant), $variantAliases))),
            'name' => $name,
            'name_norm' => $this->normalizeForCompare($name),
            'internal_id' => $internalId,
            'internal_norm' => $this->normalizeForCompare($internalId),
            'fallback_name' => (string) ($item['fallback_name'] ?? 'Unknown item'),
            'known_title_norm' => $this->normalizeForCompare($knownTitle),
            'search_terms' => $searchTerms,
            'direct_titles' => $directTitles,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return list<string>
     */
    private function discoverCandidateTitles(array $context): array
    {
        $titles = [];

        foreach ($context['direct_titles'] as $title) {
            $page = $this->queryTitles([$title], false);

            foreach ($page as $candidate) {
                $titles[] = (string) ($candidate['title'] ?? '');
            }
        }

        foreach ($context['search_terms'] as $query) {
            foreach ($this->searchTitles($query) as $title) {
                $titles[] = $title;
            }
        }

        return $this->dedupeStrings(array_values(array_filter($titles, static fn (string $title): bool => $title !== '')));
    }

    /**
     * @param list<string> $titles
     * @return list<array<string, mixed>>
     */
    private function loadPageDetails(array $titles): array
    {
        return $this->queryTitles($titles, true);
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    private function scorePageCandidate(array $page, array $context): ?array
    {
        $title = trim((string) ($page['title'] ?? ''));

        if ($title === '' || str_contains($title, ':')) {
            return null;
        }

        $titleNorm = $this->normalizeForCompare($title);
        $displayName = $this->displayTitle($title);
        $displayNorm = $this->normalizeForCompare($displayName);
        $categories = is_array($page['categories'] ?? null) ? $page['categories'] : [];
        $imageNames = is_array($page['images'] ?? null) ? $page['images'] : [];
        $wikitext = (string) ($page['wikitext'] ?? '');
        $parsed = $this->parsePageMetadata($wikitext);
        $classNames = $this->dedupeStrings(array_merge(
            $parsed['class_names'] ?? [],
            $this->extractExactClassNamesFromText($wikitext)
        ));
        $internalIds = $parsed['internal_ids'] ?? [];
        $aliases = $this->dedupeStrings(array_merge(
            $parsed['aliases'] ?? [],
            [$displayName, $title]
        ));
        $aliasNorms = array_values(array_filter(array_map(fn (string $alias): string => $this->normalizeForCompare($alias), $aliases)));
        $classNorms = array_values(array_filter(array_map(fn (string $className): string => $this->normalizeForCompare($className), $classNames)));
        $internalNorms = array_values(array_filter(array_map(fn (string $value): string => $this->normalizeForCompare($value), $internalIds)));
        $itemLike = $this->isLikelyItemPage($title, $categories, $parsed, $wikitext);

        if (!$itemLike) {
            return null;
        }

        $score = 0;
        $matchedBy = '';
        $titleMatch = '';

        if ($context['class_norm'] !== '' && in_array($context['class_norm'], $classNorms, true)) {
            $score = 120;
            $matchedBy = 'classname';
            $titleMatch = 'wikitext-classname';
        } elseif ($context['internal_norm'] !== '' && in_array($context['internal_norm'], $internalNorms, true)) {
            $score = 112;
            $matchedBy = 'internal_id';
            $titleMatch = 'wikitext-internal-id';
        } elseif ($context['known_title_norm'] !== '' && ($titleNorm === $context['known_title_norm'] || $displayNorm === $context['known_title_norm'])) {
            $score = 104;
            $matchedBy = 'known_alias';
            $titleMatch = 'known-item-alias';
        } elseif ($context['name_norm'] !== '' && ($titleNorm === $context['name_norm'] || $displayNorm === $context['name_norm'] || in_array($context['name_norm'], $aliasNorms, true))) {
            $score = 96;
            $matchedBy = 'canonical_name';
            $titleMatch = 'canonical-name';
        } elseif ($context['class_label_norm'] !== '' && ($titleNorm === $context['class_label_norm'] || $displayNorm === $context['class_label_norm'])) {
            $score = 84;
            $matchedBy = 'normalized_name';
            $titleMatch = 'humanized-classname';
        } elseif ($context['base_label_norm'] !== '' && ($titleNorm === $context['base_label_norm'] || $displayNorm === $context['base_label_norm'])) {
            $score = 74;
            $matchedBy = 'variant_base';
            $titleMatch = 'base-item';
        } else {
            $similarity = max(
                $this->similarity($context['class_label_norm'], $displayNorm),
                $this->similarity($context['name_norm'], $displayNorm),
                $this->bestAliasSimilarity($context['class_label_norm'], $aliasNorms),
                $this->bestAliasSimilarity($context['name_norm'], $aliasNorms)
            );

            if ($similarity < 0.90) {
                return null;
            }

            $score = (int) round(64 + (($similarity - 0.90) * 100));
            $matchedBy = 'fuzzy';
            $titleMatch = 'high-similarity';
        }

        if ($context['variant_norms'] !== [] && $this->pageMentionsVariant($context['variant_norms'], $title, $aliases, $wikitext, $imageNames)) {
            $score += 9;
        } elseif ($context['variant_key'] !== '') {
            $score -= 4;
        }

        if ($this->containsItemCategory($categories)) {
            $score += 4;
        }

        return [
            'title' => $title,
            'fullurl' => (string) ($page['fullurl'] ?? ''),
            'display_name' => $displayName,
            'title_match' => $titleMatch,
            'matched_by' => $matchedBy,
            'score' => $score,
            'aliases' => $aliases,
            'class_names' => $classNames,
            'internal_ids' => $internalIds,
            'categories' => $categories,
            'images' => $imageNames,
            'pageimage' => (string) ($page['pageimage'] ?? ''),
            'thumbnail' => (string) ($page['thumbnail'] ?? ''),
            'original' => (string) ($page['original'] ?? ''),
            'wikitext' => $wikitext,
            'infobox_images' => $parsed['images'] ?? [],
            'type_field' => $parsed['type'] ?? '',
            'category_field' => $parsed['category'] ?? '',
            'resolved_variant' => $this->resolveVariantLabel($context['variant_norms'], $aliases, $imageNames, $context['variant_label']),
        ];
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed> $context
     * @return array{name:string,url:string}|array{}
     */
    private function resolveImageForPage(array $page, array $context): array
    {
        $candidates = [];
        $seen = [];
        $pageimage = trim((string) ($page['pageimage'] ?? ''));
        $infoboxImages = is_array($page['infobox_images'] ?? null) ? $page['infobox_images'] : [];
        $pageImages = is_array($page['images'] ?? null) ? $page['images'] : [];

        foreach (array_merge($infoboxImages, $pageimage !== '' ? [$pageimage] : [], $pageImages) as $name) {
            $clean = $this->cleanImageName((string) $name);

            if ($clean === '' || isset($seen[$clean]) || $this->isGenericImage($clean)) {
                continue;
            }

            $seen[$clean] = true;
            $candidates[] = $clean;
        }

        // Helper: return URL for the page thumbnail or original.
        $pageThumbnailUrl = static function () use ($page): string {
            return trim((string) (($page['thumbnail'] ?? '') ?: ($page['original'] ?? '')));
        };

        // Helper: fetch a direct URL for a named image file via the API.
        $fetchImageUrl = function (string $name): string {
            $info = $this->queryImageInfo([$name]);
            $img = $info[$name] ?? null;

            if (!is_array($img)) {
                return '';
            }

            return trim((string) (($img['thumburl'] ?? '') ?: ($img['url'] ?? '')));
        };

        if ($candidates === []) {
            // No usable named image candidates — use the page thumbnail when available,
            // or query image info for the pageimage as a last resort.
            $url = $pageThumbnailUrl();

            if ($url !== '') {
                return ['name' => $pageimage, 'url' => $url];
            }

            if ($pageimage !== '') {
                $url = $fetchImageUrl($pageimage);

                return $url !== '' ? ['name' => $pageimage, 'url' => $url] : [];
            }

            return [];
        }

        // Score all candidates; sort best-first.
        $scored = [];

        foreach ($candidates as $name) {
            $score = $this->scoreImageCandidate($name, $page, $context);
            $scored[] = ['name' => $name, 'score' => $score];
        }

        usort($scored, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        // Accept any candidate that clears the confidence bar.
        $confident = array_filter($scored, static fn (array $c): bool => $c['score'] >= 45);
        $best = !empty($confident) ? reset($confident) : null;

        // No confident image: fall back to the page thumbnail (fast path),
        // then try the highest-scored candidate via the API,
        // then try the pageimage as a last resort.
        if ($best === null) {
            $url = $pageThumbnailUrl();

            if ($pageimage !== '' && $url !== '') {
                return ['name' => $pageimage, 'url' => $url];
            }

            // Try the top candidate even without high confidence.
            $topCandidate = reset($scored);

            if ($topCandidate !== false && $topCandidate['score'] >= 10) {
                $url = $fetchImageUrl($topCandidate['name']);

                if ($url !== '') {
                    return ['name' => $topCandidate['name'], 'url' => $url];
                }
            }

            if ($pageimage !== '') {
                $url = $pageThumbnailUrl() ?: $fetchImageUrl($pageimage);

                return $url !== '' ? ['name' => $pageimage, 'url' => $url] : [];
            }

            return [];
        }

        // Confident best candidate — prefer the page thumbnail when the file is the pageimage.
        if ($pageimage !== '' && strcasecmp($this->cleanImageName($pageimage), $best['name']) === 0) {
            $url = $pageThumbnailUrl();

            if ($url !== '') {
                return ['name' => $best['name'], 'url' => $url];
            }
        }

        $url = $fetchImageUrl($best['name']);

        if ($url !== '') {
            return ['name' => $best['name'], 'url' => $url];
        }

        // queryImageInfo failed; last resort: page thumbnail mapped to the best name.
        $url = $pageThumbnailUrl();

        return $url !== '' ? ['name' => $best['name'], 'url' => $url] : [];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $resolution
     */
    private function persistResolution(array $item, array $resolution): void
    {
        if (!$this->resolutionTableExists()) {
            return;
        }

        $aliases = is_array($resolution['aliases'] ?? null) ? $resolution['aliases'] : [];
        $now = date('Y-m-d H:i:s');
        $records = [];

        $append = function (string $type, string $value) use (&$records): void {
            $normalized = $this->normalizeLookupValue($value);

            if ($normalized === '') {
                return;
            }

            $records[$type . ':' . $normalized] = [
                'lookup_type' => $type,
                'lookup_key' => $value,
                'lookup_normalized' => $normalized,
            ];
        };

        $append('classname', (string) ($item['class_name'] ?? ''));
        $append('internal_id', (string) ($item['internal_id'] ?? ''));
        $append('name', (string) ($resolution['canonical_name'] ?? ''));

        foreach ($aliases as $alias) {
            $append('alias', (string) $alias);
        }

        $payload = [
            'classname' => (string) ($resolution['classname'] ?? ''),
            'internal_id' => (string) ($resolution['internal_id'] ?? ''),
            'canonical_name' => (string) ($resolution['canonical_name'] ?? ''),
            'wiki_title' => (string) ($resolution['wiki_title'] ?? ''),
            'wiki_url' => (string) ($resolution['wiki_url'] ?? ''),
            'image_url' => (string) ($resolution['image_url'] ?? ''),
            'image_name' => (string) ($resolution['image_name'] ?? ''),
            'variant' => (string) ($resolution['variant'] ?? ''),
            'category' => (string) ($resolution['category'] ?? ''),
            'type' => (string) ($resolution['type'] ?? ''),
            'aliases_json' => $this->encodeJson($aliases),
            'verification_json' => $this->encodeJson($resolution['verification'] ?? []),
            'confidence' => (int) ($resolution['confidence'] ?? 0),
            'refreshed_at' => $now,
            'updated_at' => $now,
        ];

        foreach ($records as $record) {
            try {
                \Illuminate\Support\Facades\DB::table('dayz_inventory_item_resolutions')->updateOrInsert(
                    ['lookup_type' => $record['lookup_type'], 'lookup_normalized' => $record['lookup_normalized']],
                    $record + $payload + ['created_at' => $now]
                );
            } catch (Throwable) {
                return;
            }
        }
    }

    private function resolutionTableExists(): bool
    {
        if ($this->tableExists !== null) {
            return $this->tableExists;
        }

        if (!class_exists('Illuminate\\Support\\Facades\\Schema') || !class_exists('Illuminate\\Support\\Facades\\DB')) {
            return $this->tableExists = false;
        }

        try {
            return $this->tableExists = \Illuminate\Support\Facades\Schema::hasTable('dayz_inventory_item_resolutions');
        } catch (Throwable) {
            return $this->tableExists = false;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function hydrateResolutionRow(array $row, array $item): array
    {
        $aliases = $this->decodeJsonArray($row['aliases_json'] ?? null);
        $verification = $this->decodeJsonAssoc($row['verification_json'] ?? null);

        return [
            'lookup_key' => $item['lookup_key'],
            'resolved' => trim((string) ($row['wiki_url'] ?? '')) !== '' || trim((string) ($row['wiki_title'] ?? '')) !== '',
            'display_name' => trim((string) ($row['canonical_name'] ?? '')) !== '' ? (string) $row['canonical_name'] : (string) ($item['fallback_name'] ?? 'Unknown item'),
            'canonical_name' => (string) ($row['canonical_name'] ?? ''),
            'wiki_title' => (string) ($row['wiki_title'] ?? ''),
            'wiki_url' => (string) ($row['wiki_url'] ?? ''),
            'image_url' => (string) ($row['image_url'] ?? ''),
            'image_name' => (string) ($row['image_name'] ?? ''),
            'variant' => (string) (($row['variant'] ?? '') !== '' ? $row['variant'] : ($item['variant_label'] ?? '')),
            'classname' => (string) (($row['classname'] ?? '') !== '' ? $row['classname'] : ($item['class_name'] ?? '')),
            'internal_id' => (string) (($row['internal_id'] ?? '') !== '' ? $row['internal_id'] : ($item['internal_id'] ?? '')),
            'category' => (string) ($row['category'] ?? ''),
            'type' => (string) ($row['type'] ?? ''),
            'aliases' => $aliases,
            'matched_by' => (string) (($verification['matched_by'] ?? '') ?: 'cache'),
            'confidence' => (int) ($row['confidence'] ?? 0),
            'verification' => $verification,
            'refreshed_at' => (string) ($row['refreshed_at'] ?? ''),
        ];
    }

    /**
     * @param list<string> $titles
     * @return list<array<string, mixed>>
     */
    private function queryTitles(array $titles, bool $full): array
    {
        $titles = $this->dedupeStrings($titles);

        if ($titles === []) {
            return [];
        }

        $cacheKey = 'titles:' . md5(($full ? 'full:' : 'lite:') . implode('|', $titles));

        if (isset($this->requestCache[$cacheKey])) {
            /** @var list<array<string, mixed>> $cached */
            $cached = $this->requestCache[$cacheKey];

            return $cached;
        }

        $response = $this->requestApi($full ? [
            'action' => 'query',
            'redirects' => '1',
            'titles' => implode('|', $titles),
            'prop' => 'info|categories|pageimages|images|revisions',
            'inprop' => 'url',
            'cllimit' => 'max',
            'imlimit' => 'max',
            'piprop' => 'thumbnail|name|original',
            'pithumbsize' => (string) self::PAGE_IMAGE_WIDTH,
            'rvslots' => 'main',
            'rvprop' => 'content',
        ] : [
            'action' => 'query',
            'redirects' => '1',
            'titles' => implode('|', $titles),
            'prop' => 'info',
            'inprop' => 'url',
        ]);

        if (!is_array($response['query']['pages'] ?? null)) {
            return $this->requestCache[$cacheKey] = [];
        }

        $pages = [];

        foreach ($response['query']['pages'] as $page) {
            if (!is_array($page) || array_key_exists('missing', $page)) {
                continue;
            }

            $images = [];

            foreach ($page['images'] ?? [] as $image) {
                if (!is_array($image)) {
                    continue;
                }

                $title = trim((string) ($image['title'] ?? ''));

                if ($title !== '') {
                    $images[] = $title;
                }
            }

            $categories = [];

            foreach ($page['categories'] ?? [] as $category) {
                if (!is_array($category)) {
                    continue;
                }

                $title = trim((string) ($category['title'] ?? ''));

                if ($title !== '') {
                    $categories[] = $title;
                }
            }

            $thumbnail = '';
            $original = '';
            $pageimage = '';

            if (is_array($page['thumbnail'] ?? null)) {
                $thumbnail = trim((string) ($page['thumbnail']['source'] ?? ''));
            }

            if (is_array($page['original'] ?? null)) {
                $original = trim((string) ($page['original']['source'] ?? ''));
            }

            if (isset($page['pageimage'])) {
                $pageimage = $this->cleanImageName((string) $page['pageimage']);
            }

            $revisions = is_array($page['revisions'] ?? null) ? $page['revisions'] : [];
            $revision = $revisions[0] ?? [];
            $wikitext = '';

            if (is_array($revision['slots']['main'] ?? null)) {
                $wikitext = (string) ($revision['slots']['main']['*'] ?? '');
            } elseif (isset($revision['*'])) {
                $wikitext = (string) $revision['*'];
            }

            $pages[] = [
                'title' => (string) ($page['title'] ?? ''),
                'fullurl' => (string) ($page['fullurl'] ?? ''),
                'categories' => $categories,
                'images' => array_map([$this, 'cleanImageName'], $images),
                'pageimage' => $pageimage,
                'thumbnail' => $thumbnail,
                'original' => $original,
                'wikitext' => $wikitext,
            ];
        }

        return $this->requestCache[$cacheKey] = $pages;
    }

    /**
     * @return list<string>
     */
    private function searchTitles(string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $cacheKey = 'search:' . md5($query);

        if (isset($this->requestCache[$cacheKey])) {
            /** @var list<string> $cached */
            $cached = $this->requestCache[$cacheKey];

            return $cached;
        }

        $response = $this->requestApi([
            'action' => 'query',
            'list' => 'search',
            'srsearch' => $query,
            'srlimit' => (string) self::SEARCH_LIMIT,
            'srnamespace' => '0',
        ]);

        $titles = [];

        foreach ($response['query']['search'] ?? [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $title = trim((string) ($entry['title'] ?? ''));

            if ($title !== '' && !str_contains($title, ':')) {
                $titles[] = $title;
            }
        }

        return $this->requestCache[$cacheKey] = $this->dedupeStrings($titles);
    }

    /**
     * @param list<string> $imageNames
     * @return array<string, array<string, mixed>>
     */
    private function queryImageInfo(array $imageNames): array
    {
        $imageNames = array_values(array_unique(array_map([$this, 'cleanImageName'], $imageNames)));

        if ($imageNames === []) {
            return [];
        }

        $cacheKey = 'images:' . md5(implode('|', $imageNames));

        if (isset($this->requestCache[$cacheKey])) {
            /** @var array<string, array<string, mixed>> $cached */
            $cached = $this->requestCache[$cacheKey];

            return $cached;
        }

        $response = $this->requestApi([
            'action' => 'query',
            'prop' => 'imageinfo',
            'titles' => implode('|', array_map(static fn (string $name): string => 'File:' . $name, $imageNames)),
            'iiprop' => 'url',
            'iiurlwidth' => (string) self::PAGE_IMAGE_WIDTH,
        ]);

        $resolved = [];

        foreach ($response['query']['pages'] ?? [] as $page) {
            if (!is_array($page) || array_key_exists('missing', $page)) {
                continue;
            }

            $name = $this->cleanImageName((string) ($page['title'] ?? ''));
            $info = is_array($page['imageinfo'] ?? null) ? ($page['imageinfo'][0] ?? null) : null;

            if ($name === '' || !is_array($info)) {
                continue;
            }

            $resolved[$name] = $info;
        }

        return $this->requestCache[$cacheKey] = $resolved;
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>|null
     */
    private function requestApi(array $params): ?array
    {
        $cacheKey = 'api:' . md5(
            $this->wikiSource['api_url'] . '|'
            . json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        if (isset($this->requestCache[$cacheKey])) {
            /** @var array<string, mixed>|null $cached */
            $cached = $this->requestCache[$cacheKey];

            return $cached;
        }

        $resolver = function () use ($params): ?array {
            $query = http_build_query($params + ['format' => 'json', 'origin' => '*'], '', '&', PHP_QUERY_RFC3986);
            $url = $this->wikiSource['api_url'] . '?' . $query;

            try {
                if (class_exists('Illuminate\\Support\\Facades\\Http')) {
                    $response = \Illuminate\Support\Facades\Http::timeout(12)
                        ->acceptJson()
                        ->withHeaders(['User-Agent' => 'PteroMods-DayZManager/1.0'])
                        ->get($url);

                    if ($response->successful()) {
                        $json = $response->json();

                        return is_array($json) ? $json : null;
                    }
                }
            } catch (Throwable) {
                // fall through
            }

            try {
                $context = stream_context_create([
                    'http' => [
                        'timeout' => 12,
                        'ignore_errors' => true,
                        'header' => "Accept: application/json\r\nUser-Agent: PteroMods-DayZManager/1.0\r\n",
                    ],
                ]);
                $body = @file_get_contents($url, false, $context);

                if (!is_string($body) || trim($body) === '') {
                    return null;
                }

                $decoded = json_decode($body, true);

                return is_array($decoded) ? $decoded : null;
            } catch (Throwable) {
                return null;
            }
        };

        $result = $this->staleCache->remember($cacheKey, 3600, 86400, $resolver);

        return $this->requestCache[$cacheKey] = (is_array($result) ? $result : null);
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed> $context
     */
    private function scoreImageCandidate(string $name, array $page, array $context): int
    {
        $clean = $this->normalizeForCompare(pathinfo($name, PATHINFO_FILENAME));
        $score = 0;
        $pageTitleNorm = $this->normalizeForCompare((string) ($page['title'] ?? ''));

        if ($clean === '') {
            return 0;
        }

        if ($context['class_label_norm'] !== '' && str_contains($clean, $context['class_label_norm'])) {
            $score += 62;
        }

        if ($context['base_label_norm'] !== '' && str_contains($clean, $context['base_label_norm'])) {
            $score += 22;
        }

        if ($pageTitleNorm !== '' && str_contains($clean, $pageTitleNorm)) {
            $score += 18;
        }

        if ($context['variant_norms'] !== []) {
            $variantHit = false;

            foreach ($context['variant_norms'] as $variantNorm) {
                if ($variantNorm !== '' && str_contains($clean, $variantNorm)) {
                    $score += 34;
                    $variantHit = true;
                    break;
                }
            }

            if (!$variantHit) {
                $score -= 10;
            }
        }

        $pageImageName = $this->cleanImageName((string) ($page['pageimage'] ?? ''));

        if ($pageImageName !== '' && strcasecmp($pageImageName, $name) === 0) {
            $score += 18;
        }

        $infoboxImages = is_array($page['infobox_images'] ?? null) ? $page['infobox_images'] : [];

        foreach ($infoboxImages as $image) {
            if (strcasecmp($this->cleanImageName((string) $image), $name) === 0) {
                $score += 24;
                break;
            }
        }

        return $score;
    }

    /**
     * @return array{class_names:list<string>,internal_ids:list<string>,aliases:list<string>,images:list<string>,type:?string,category:?string}
     */
    private function parsePageMetadata(string $wikitext): array
    {
        $metadata = [
            'class_names' => [],
            'internal_ids' => [],
            'aliases' => [],
            'images' => [],
            'type' => null,
            'category' => null,
        ];

        if ($wikitext === '') {
            return $metadata;
        }

        if (preg_match_all('/^\|\s*([^=\n]+?)\s*=\s*(.+)$/m', $wikitext, $matches, PREG_SET_ORDER) === 0) {
            return $metadata;
        }

        foreach ($matches as $match) {
            $field = strtolower(trim((string) ($match[1] ?? '')));
            $value = trim((string) ($match[2] ?? ''));

            if ($field === '' || $value === '') {
                continue;
            }

            if (preg_match('/image\d*$/', $field) === 1) {
                $image = $this->extractImageFileName($value);

                if ($image !== '') {
                    $metadata['images'][] = $image;
                }
                continue;
            }

            if (preg_match('/(class|cfg|typeid|typename)/', $field) === 1) {
                $metadata['class_names'] = array_merge($metadata['class_names'], $this->extractIdentifierList($value));
                continue;
            }

            if (preg_match('/(^|_)(id|identifier)(_|$)/', $field) === 1) {
                $metadata['internal_ids'] = array_merge($metadata['internal_ids'], $this->extractIdentifierList($value));
                continue;
            }

            if ($metadata['type'] === null && preg_match('/(^|_)(type)(_|$)/', $field) === 1) {
                $metadata['type'] = $this->stripWikiMarkup($value);
                continue;
            }

            if ($metadata['category'] === null && preg_match('/(^|_)(category)(_|$)/', $field) === 1) {
                $metadata['category'] = $this->stripWikiMarkup($value);
                continue;
            }

            if (preg_match('/(name|alias|aka|variant)/', $field) === 1 && preg_match('/image|filename/', $field) !== 1) {
                $metadata['aliases'] = array_merge($metadata['aliases'], $this->extractAliasList($value));
            }
        }

        $metadata['class_names'] = $this->dedupeStrings($metadata['class_names']);
        $metadata['internal_ids'] = $this->dedupeStrings($metadata['internal_ids']);
        $metadata['aliases'] = $this->dedupeStrings($metadata['aliases']);
        $metadata['images'] = $this->dedupeStrings($metadata['images']);

        return $metadata;
    }

    /**
     * @return list<string>
     */
    private function extractIdentifierList(string $value): array
    {
        $items = preg_split('/(?:<br\s*\/?>|,|\/|\n|;)/i', $this->stripWikiMarkup($value)) ?: [];
        $out = [];

        foreach ($items as $item) {
            $candidate = trim($item);

            if ($candidate !== '' && preg_match('/^[A-Za-z0-9_(). -]{2,}$/', $candidate) === 1) {
                $out[] = $candidate;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function extractAliasList(string $value): array
    {
        $items = preg_split('/(?:<br\s*\/?>|,|\/|\n|;)/i', $this->stripWikiMarkup($value)) ?: [];
        $out = [];

        foreach ($items as $item) {
            $candidate = trim($item);

            if ($candidate !== '' && strlen($candidate) <= 100) {
                $out[] = $candidate;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function extractExactClassNamesFromText(string $wikitext): array
    {
        if ($wikitext === '') {
            return [];
        }

        preg_match_all('/(?<![A-Za-z0-9_])([A-Z][A-Za-z0-9]+(?:_[A-Za-z0-9]+)+)(?![A-Za-z0-9_])/', $wikitext, $matches);

        return $this->dedupeStrings($matches[1] ?? []);
    }

    private function pageMentionsVariant(array $variantNorms, string $title, array $aliases, string $wikitext, array $imageNames): bool
    {
        $haystacks = array_merge([$title, $wikitext], $aliases, $imageNames);

        foreach ($variantNorms as $variantNorm) {
            if ($variantNorm === '') {
                continue;
            }

            foreach ($haystacks as $haystack) {
                if (str_contains($this->normalizeForCompare((string) $haystack), $variantNorm)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function containsItemCategory(array $categories): bool
    {
        foreach ($categories as $category) {
            $normalized = $this->normalizeForCompare((string) $category);

            foreach (self::ITEM_CATEGORY_TERMS as $term) {
                if (str_contains($normalized, $term)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isLikelyItemPage(string $title, array $categories, array $parsed, string $wikitext): bool
    {
        $titleNorm = $this->normalizeForCompare($title);

        if ($titleNorm === '') {
            return false;
        }

        if (str_contains($titleNorm, 'disambiguation') || str_contains($titleNorm, 'patch notes')) {
            return false;
        }

        if ($this->containsItemCategory($categories)) {
            return true;
        }

        if (($parsed['class_names'] ?? []) !== [] || ($parsed['images'] ?? []) !== []) {
            return true;
        }

        $textNorm = $this->normalizeForCompare($wikitext);

        return str_contains($textNorm, 'infobox') && (str_contains($textNorm, 'class name') || str_contains($textNorm, 'attachment') || str_contains($textNorm, 'inventory'));
    }

    private function resolveVariantLabel(array $variantNorms, array $aliases, array $imageNames, string $fallback): string
    {
        if ($variantNorms === []) {
            return $fallback;
        }

        foreach (array_merge($aliases, $imageNames) as $value) {
            $normalized = $this->normalizeForCompare((string) $value);

            foreach ($variantNorms as $variantNorm) {
                if ($variantNorm !== '' && str_contains($normalized, $variantNorm)) {
                    return $this->displayTitle((string) $value);
                }
            }
        }

        return $fallback;
    }

    private function pickCategoryLabel(array $categories): ?string
    {
        foreach ($categories as $category) {
            $label = preg_replace('/^Category:/i', '', (string) $category);

            if ($label !== null && $this->containsItemCategory([$label])) {
                return $this->displayTitle($label);
            }
        }

        return null;
    }

    private function pickTypeLabel(array $categories): ?string
    {
        foreach ($categories as $category) {
            $label = preg_replace('/^Category:/i', '', (string) $category);
            $normalized = $this->normalizeForCompare((string) $label);

            foreach (['headgear', 'vest', 'backpack', 'container', 'attachment', 'weapon', 'tool', 'medical', 'food', 'ammunition', 'magazine'] as $term) {
                if (str_contains($normalized, $term)) {
                    return $this->displayTitle((string) $label);
                }
            }
        }

        return null;
    }

    private function extractImageFileName(string $value): string
    {
        if (preg_match('/File:([^|\]\n]+?\.(?:png|jpe?g|webp|gif))/i', $value, $matches) === 1) {
            return $this->cleanImageName((string) $matches[1]);
        }

        if (preg_match('/(?:^|[|=\s])([^|=\[\]\n]+?\.(?:png|jpe?g|webp|gif))(?:$|[|\]\s])/i', $value, $matches) === 1) {
            return $this->cleanImageName((string) $matches[1]);
        }

        return '';
    }

    private function cleanImageName(string $value): string
    {
        $value = trim(preg_replace('/^File:/i', '', $value) ?? '');

        return $value === '' ? '' : str_replace(' ', '_', $value);
    }

    private function isGenericImage(string $name): bool
    {
        $normalized = $this->normalizeForCompare(pathinfo($name, PATHINFO_FILENAME));

        if ($normalized === '') {
            return true;
        }

        foreach (self::GENERIC_IMAGE_TERMS as $term) {
            if (str_contains($normalized, $term)) {
                return true;
            }
        }

        return false;
    }

    private function bestAliasSimilarity(string $needle, array $aliases): float
    {
        $best = 0.0;

        foreach ($aliases as $alias) {
            $best = max($best, $this->similarity($needle, (string) $alias));
        }

        return $best;
    }

    private function similarity(string $left, string $right): float
    {
        $left = trim($left);
        $right = trim($right);

        if ($left === '' || $right === '') {
            return 0.0;
        }

        similar_text($left, $right, $percent);

        return $percent / 100;
    }

    /**
     * @return list<string>
     */
    private function variantSearchTerms(string $variantKey): array
    {
        $terms = [$variantKey, $this->humanizeClassName($variantKey)];

        if (isset(self::VARIANT_ALIAS_MAP[$variantKey])) {
            $terms[] = self::VARIANT_ALIAS_MAP[$variantKey];
        }

        return $this->dedupeStrings($terms);
    }

    private function bestVariantLabel(string $variantKey): string
    {
        $terms = $this->variantSearchTerms($variantKey);

        return $terms[1] ?? $terms[0] ?? '';
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitClassName(string $className): array
    {
        $className = trim($className);

        if ($className === '' || !str_contains($className, '_')) {
            return [$className, ''];
        }

        $parts = explode('_', $className, 2);

        return [trim($parts[0]), trim($parts[1] ?? '')];
    }

    private function humanizeClassName(string $value): string
    {
        $value = trim(str_replace('_', ' ', $value));

        if ($value === '') {
            return '';
        }

        $value = preg_replace('/([a-z])([A-Z])/', '$1 $2', $value) ?? $value;
        $value = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $value) ?? $value;
        $value = preg_replace('/([A-Za-z])(\d)/', '$1 $2', $value) ?? $value;
        $value = preg_replace('/(\d)([A-Za-z])/', '$1 $2', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    private function displayTitle(string $title): string
    {
        return trim(str_replace('_', ' ', preg_replace('/^Category:/i', '', $title) ?? $title));
    }

    private function lookupKey(string $className, string $internalId, string $name): string
    {
        return implode('|', [$className, $internalId, $name]);
    }

    private function firstString(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $source[$key] ?? null;

            if (is_scalar($value)) {
                $text = trim((string) $value);

                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }

    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            $text = trim((string) ($value ?? ''));

            if ($text !== '') {
                return $this->displayTitle($text);
            }
        }

        return null;
    }

    private function normalizeLookupValue(string $value): string
    {
        return $this->normalizeForCompare($value);
    }

    private function normalizeForCompare(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = strtolower(trim($value));
        $value = str_replace(['_', '-'], ' ', $value);
        $value = preg_replace("/[’'`]/u", '', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    private function stripWikiMarkup(string $value): string
    {
        $value = preg_replace('/\[\[(?:[^|\]]+\|)?([^\]]+)\]\]/', '$1', $value) ?? $value;
        $value = preg_replace('/{{[^{}]+}}/', ' ', $value) ?? $value;
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function dedupeStrings(array $values): array
    {
        $out = [];
        $seen = [];

        foreach ($values as $value) {
            $text = trim((string) $value);

            if ($text === '') {
                continue;
            }

            if (isset($seen[$text])) {
                continue;
            }

            $seen[$text] = true;
            $out[] = $text;
        }

        return $out;
    }

    private function encodeJson(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '[]';
    }

    /**
     * @return list<string>
     */
    private function decodeJsonArray(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded), static fn (string $entry): bool => trim($entry) !== '')) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonAssoc(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $cached
     * @return array<string, mixed>
     */
    private function mergeFallbackResolution(array $item, array $cached): array
    {
        $cached['lookup_key'] = $item['lookup_key'];
        $cached['display_name'] = trim((string) ($cached['display_name'] ?? '')) !== '' ? (string) $cached['display_name'] : (string) ($item['fallback_name'] ?? 'Unknown item');
        $cached['classname'] = trim((string) ($cached['classname'] ?? '')) !== '' ? (string) $cached['classname'] : (string) ($item['class_name'] ?? '');
        $cached['internal_id'] = trim((string) ($cached['internal_id'] ?? '')) !== '' ? (string) $cached['internal_id'] : (string) ($item['internal_id'] ?? '');
        $cached['variant'] = trim((string) ($cached['variant'] ?? '')) !== '' ? (string) $cached['variant'] : (string) ($item['variant_label'] ?? '');

        return $cached;
    }
}
