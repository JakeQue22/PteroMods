<?php

declare(strict_types=1);

namespace PteroMods\Services\DayZ;

use Throwable;

/**
 * Browses the DayZ Steam Workshop (appid 221100) so operators can discover
 * mods by name instead of having to already know a Workshop ID.
 *
 * `IPublishedFileService/QueryFiles` requires a Steam Web API key (unlike the
 * single-item `GetPublishedFileDetails` lookup used elsewhere), so the caller
 * supplies one; when none is configured the client returns an empty result
 * instead of failing the page.
 */
final class WorkshopBrowseClient
{
    private const ENDPOINT = 'https://api.steampowered.com/IPublishedFileService/QueryFiles/v1/';

    /** DayZ's Steam application ID. */
    private const DAYZ_APP_ID = 221100;

    /** Steam query type IDs from EUGCQuery. */
    private const QUERY_TYPE_RANKED_BY_VOTE = 0;
    private const QUERY_TYPE_RANKED_BY_PUBLICATION_DATE = 1;
    private const QUERY_TYPE_RANKED_BY_TEXT_SEARCH = 11;
    private const QUERY_TYPE_RANKED_BY_TOTAL_UNIQUE_SUBSCRIPTIONS = 12;
    private const QUERY_TYPE_RANKED_BY_LAST_UPDATED_DATE = 21;

    private const PER_PAGE = 24;

    /**
     * @return list<array{value: string, label: string}>
     */
    public function availableSorts(): array
    {
        return [
            ['value' => 'most_popular', 'label' => 'Most Popular'],
            ['value' => 'most_subscribed', 'label' => 'Most Subscribed'],
            ['value' => 'last_updated', 'label' => 'Last Updated'],
            ['value' => 'new', 'label' => 'New'],
        ];
    }

    /**
     * @return array{
     *   type: list<array{value: string, label: string, tag: string, count: string}>,
     *   mod_type: list<array{value: string, label: string, tag: string, count: string}>,
     *   required_dlc: list<array{value: string, label: string, tag: string, count: string}>
     * }
     */
    public function availableFilters(): array
    {
        return [
            'type' => [
                ['value' => 'mod', 'label' => 'Mod', 'tag' => 'Mod', 'count' => '104,602'],
                ['value' => 'server', 'label' => 'Server', 'tag' => 'Server', 'count' => '7,979'],
            ],
            'mod_type' => [
                ['value' => 'animation', 'label' => 'Animation', 'tag' => 'Animation', 'count' => '4,449'],
                ['value' => 'character', 'label' => 'Character', 'tag' => 'Character', 'count' => '7,259'],
                ['value' => 'economy', 'label' => 'Economy', 'tag' => 'Economy', 'count' => '5,048'],
                ['value' => 'environment', 'label' => 'Environment', 'tag' => 'Environment', 'count' => '6,184'],
                ['value' => 'equipment', 'label' => 'Equipment', 'tag' => 'Equipment', 'count' => '8,552'],
                ['value' => 'mechanics', 'label' => 'Mechanics', 'tag' => 'Mechanics', 'count' => '7,941'],
                ['value' => 'sound', 'label' => 'Sound', 'tag' => 'Sound', 'count' => '4,010'],
                ['value' => 'props', 'label' => 'Props', 'tag' => 'Props', 'count' => '4,887'],
                ['value' => 'terrain', 'label' => 'Terrain', 'tag' => 'Terrain', 'count' => '3,849'],
                ['value' => 'vehicle', 'label' => 'Vehicle', 'tag' => 'Vehicle', 'count' => '4,993'],
                ['value' => 'weapon', 'label' => 'Weapon', 'tag' => 'Weapon', 'count' => '4,513'],
            ],
            'required_dlc' => [
                ['value' => 'frostline', 'label' => 'Frostline', 'tag' => 'Frostline', 'count' => ''],
            ],
        ];
    }

    /**
     * @param array{sort?: string, type?: string, mod_type?: string, required_dlc?: string} $options
     * @return array{
     *   items: list<array{
     *     workshop_id: string,
     *     title: string,
     *     thumbnail: string,
     *     file_size: int,
     *     description: string,
     *     time_created: int,
     *     time_updated: int,
     *     subscriptions: int,
     *     votes_up: int,
     *     score: float,
     *     view_url: string,
     *     tags: list<string>
     *   }>,
     *   page: int,
     *   has_more: bool,
     *   per_page: int,
     *   sort: string,
     *   filters: array{type: string, mod_type: string, required_dlc: string},
     *   available_sorts: list<array{value: string, label: string}>,
     *   available_filters: array{
     *     type: list<array{value: string, label: string, tag: string, count: string}>,
     *     mod_type: list<array{value: string, label: string, tag: string, count: string}>,
     *     required_dlc: list<array{value: string, label: string, tag: string, count: string}>
     *   }
     * }
     */
    public function search(string $term, int $page = 1, string $apiKey = '', array $options = []): array
    {
        $page = max(1, $page);
        $filters = $this->normalizeFilters($options);
        $sort = $this->normalizeSort((string) ($options['sort'] ?? ''));
        $empty = [
            'items' => [],
            'page' => $page,
            'has_more' => false,
            'per_page' => self::PER_PAGE,
            'sort' => $sort,
            'filters' => $filters,
            'available_sorts' => $this->availableSorts(),
            'available_filters' => $this->availableFilters(),
        ];

        if ($apiKey === '' || !class_exists('Illuminate\\Support\\Facades\\Http')) {
            return $empty;
        }

        $term = trim($term);
        $queryType = $term === ''
            ? $this->queryTypeForSort($sort)
            : self::QUERY_TYPE_RANKED_BY_TEXT_SEARCH;

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(6)->get(self::ENDPOINT, [
                'key'                  => $apiKey,
                'appid'                => self::DAYZ_APP_ID,
                'creator_appid'        => self::DAYZ_APP_ID,
                'consumer_appid'       => self::DAYZ_APP_ID,
                'numperpage'           => self::PER_PAGE,
                'page'                 => $page,
                'query_type'           => $queryType,
                'search_text'          => $term,
                'requiredtags'         => $this->requiredTagsFromFilters($filters),
                'return_vote_data'     => true,
                'return_tags'          => true,
                'return_previews'      => true,
                'return_short_description' => true,
                'strip_description_bbcode' => true,
            ]);

            if (!$response->successful()) {
                return $empty;
            }

            $details = $response->json('response.publishedfiledetails');
        } catch (Throwable) {
            return $empty;
        }

        if (!is_array($details)) {
            return $empty;
        }

        $items = [];

        foreach ($details as $item) {
            if (!is_array($item) || (int) ($item['result'] ?? 0) !== 1) {
                continue;
            }

            $workshopId = (string) ($item['publishedfileid'] ?? '');

            if ($workshopId === '') {
                continue;
            }

            $tags = [];

            foreach ((array) ($item['tags'] ?? []) as $tag) {
                if (!is_array($tag)) {
                    continue;
                }

                $value = trim((string) ($tag['tag'] ?? ''));

                if ($value !== '') {
                    $tags[] = $value;
                }
            }

            $items[] = [
                'workshop_id' => $workshopId,
                'title'       => (string) ($item['title'] ?? 'Untitled mod'),
                'thumbnail'   => (string) ($item['preview_url'] ?? ''),
                'file_size'   => (int) ($item['file_size'] ?? 0),
                'description' => (string) ($item['short_description'] ?? ''),
                'time_created' => (int) ($item['time_created'] ?? 0),
                'time_updated' => (int) ($item['time_updated'] ?? 0),
                'subscriptions' => (int) ($item['subscriptions'] ?? 0),
                'votes_up' => (int) ($item['votes_up'] ?? 0),
                'score' => (float) ($item['score'] ?? 0),
                'view_url' => sprintf('https://steamcommunity.com/sharedfiles/filedetails/?id=%s', $workshopId),
                'tags' => array_values(array_unique($tags)),
            ];
        }

        if ($term !== '') {
            $items = $this->sortItems($items, $sort);
        }

        return [
            'items'    => $items,
            'page'     => $page,
            'has_more' => count($items) >= self::PER_PAGE,
            'per_page' => self::PER_PAGE,
            'sort' => $sort,
            'filters' => $filters,
            'available_sorts' => $this->availableSorts(),
            'available_filters' => $this->availableFilters(),
        ];
    }

    private function normalizeSort(string $sort): string
    {
        $allowed = array_column($this->availableSorts(), 'value');

        return in_array($sort, $allowed, true) ? $sort : 'most_popular';
    }

    /**
     * @param array{sort?: string, type?: string, mod_type?: string, required_dlc?: string} $options
     * @return array{type: string, mod_type: string, required_dlc: string}
     */
    private function normalizeFilters(array $options): array
    {
        $normalized = [
            'type' => '',
            'mod_type' => '',
            'required_dlc' => '',
        ];

        foreach ($normalized as $group => $_) {
            $value = trim((string) ($options[$group] ?? ''));
            $allowed = array_column($this->availableFilters()[$group], 'value');
            $normalized[$group] = in_array($value, $allowed, true) ? $value : '';
        }

        return $normalized;
    }

    private function queryTypeForSort(string $sort): int
    {
        return match ($sort) {
            'most_subscribed' => self::QUERY_TYPE_RANKED_BY_TOTAL_UNIQUE_SUBSCRIPTIONS,
            'last_updated' => self::QUERY_TYPE_RANKED_BY_LAST_UPDATED_DATE,
            'new' => self::QUERY_TYPE_RANKED_BY_PUBLICATION_DATE,
            default => self::QUERY_TYPE_RANKED_BY_VOTE,
        };
    }

    /**
     * @param array{type: string, mod_type: string, required_dlc: string} $filters
     * @return list<string>
     */
    private function requiredTagsFromFilters(array $filters): array
    {
        $tags = [];

        foreach ($this->availableFilters() as $group => $definitions) {
            $active = $filters[$group] ?? '';

            if ($active === '') {
                continue;
            }

            foreach ($definitions as $definition) {
                if (($definition['value'] ?? '') === $active && ($definition['tag'] ?? '') !== '') {
                    $tags[] = (string) $definition['tag'];
                    break;
                }
            }
        }

        return $tags;
    }

    /**
     * @param list<array{
     *   workshop_id: string,
     *   title: string,
     *   thumbnail: string,
     *   file_size: int,
     *   description: string,
     *   time_created: int,
     *   time_updated: int,
     *   subscriptions: int,
     *   votes_up: int,
     *   score: float,
     *   view_url: string,
     *   tags: list<string>
     * }> $items
     * @return list<array{
     *   workshop_id: string,
     *   title: string,
     *   thumbnail: string,
     *   file_size: int,
     *   description: string,
     *   time_created: int,
     *   time_updated: int,
     *   subscriptions: int,
     *   votes_up: int,
     *   score: float,
     *   view_url: string,
     *   tags: list<string>
     * }>
     */
    private function sortItems(array $items, string $sort): array
    {
        usort($items, static function (array $left, array $right) use ($sort): int {
            $metric = match ($sort) {
                'most_subscribed' => 'subscriptions',
                'last_updated' => 'time_updated',
                'new' => 'time_created',
                default => 'votes_up',
            };

            $leftValue = (float) ($left[$metric] ?? 0);
            $rightValue = (float) ($right[$metric] ?? 0);

            if ($leftValue === $rightValue) {
                return strcmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
            }

            return $rightValue <=> $leftValue;
        });

        return array_values($items);
    }
}
