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

    /** Ranked by text relevance when searching, by trend when browsing. */
    private const QUERY_TYPE_TEXT_SEARCH = 1;

    private const QUERY_TYPE_TRENDING = 9;

    private const PER_PAGE = 24;

    /**
     * @return array{items: list<array{workshop_id: string, title: string, thumbnail: string, file_size: int}>, page: int, has_more: bool}
     */
    public function search(string $term, int $page = 1, string $apiKey = ''): array
    {
        $page = max(1, $page);
        $empty = ['items' => [], 'page' => $page, 'has_more' => false];

        if ($apiKey === '' || !class_exists('Illuminate\\Support\\Facades\\Http')) {
            return $empty;
        }

        $term = trim($term);

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(6)->get(self::ENDPOINT, [
                'key'                  => $apiKey,
                'appid'                => self::DAYZ_APP_ID,
                'numperpage'           => self::PER_PAGE,
                'page'                 => $page,
                'query_type'           => $term === '' ? self::QUERY_TYPE_TRENDING : self::QUERY_TYPE_TEXT_SEARCH,
                'search_text'          => $term,
                'return_vote_data'     => false,
                'return_tags'          => false,
                'return_previews'      => true,
                'return_short_description' => false,
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

            $items[] = [
                'workshop_id' => (string) ($item['publishedfileid'] ?? ''),
                'title'       => (string) ($item['title'] ?? 'Untitled mod'),
                'thumbnail'   => (string) ($item['preview_url'] ?? ''),
                'file_size'   => (int) ($item['file_size'] ?? 0),
            ];
        }

        return [
            'items'    => array_values(array_filter($items, static fn (array $item): bool => $item['workshop_id'] !== '')),
            'page'     => $page,
            'has_more' => count($items) >= self::PER_PAGE,
        ];
    }
}
