<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ProductRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

use function count;
use function in_array;

/**
 * Predicts what a shopper is typing in the product search box. Suggestions come from products only and are of two
 * types: "key" (a brand, just the search keyword) and "product" (a title match, with what the UI needs to render
 * and link a product card: title, slug, condition and cover image). Each normalized query is cached in the "product_search.cache" pool, empty
 * results included, so repeated keystrokes never hit the database twice within the TTL.
 */
class ProductSearchSuggestionService
{
    public const MIN_LENGTH = 2;
    public const MAX_LENGTH = 100;
    public const LIMIT = 8;
    public const CACHE_TTL = 600;

    private const BRAND_LIMIT = 3;

    public function __construct(
        private ProductRepository $productRepository,
        private CacheInterface $productSearchCache,
    ) {
    }

    /**
     * @return list<array{type: 'key', key: string}|array{type: 'product', title: string, slug: ?string, productCondition: ?string, coverImage: ?string}>
     */
    public function suggest(string $query): array
    {
        $normalized = self::normalize($query);

        if (mb_strlen($normalized) < self::MIN_LENGTH) {
            return [];
        }

        return $this->productSearchCache->get(
            'suggest_' . hash('sha256', $normalized),
            function (ItemInterface $item) use ($normalized): array {
                $item->expiresAfter(self::CACHE_TTL);

                return $this->search($normalized);
            },
        );
    }

    public static function normalize(string $query): string
    {
        $query = preg_replace('/\s+/u', ' ', trim($query)) ?? '';

        return mb_strtolower(mb_substr($query, 0, self::MAX_LENGTH));
    }

    /**
     * @return list<array{type: 'key', key: string}|array{type: 'product', title: string, slug: ?string, productCondition: ?string, coverImage: ?string}>
     */
    private function search(string $text): array
    {
        $suggestions = [];
        $seen = [];

        foreach ($this->productRepository->findBrandsStartingWith($text, self::BRAND_LIMIT) as $brand) {
            $suggestions[] = ['type' => 'key', 'key' => $brand];
            $seen[] = mb_strtolower($brand);
        }

        // Titles repeat a lot (same product from several sellers), so over-fetch before de-duplicating.
        $products = [];
        foreach ($this->productRepository->findTitlesMatching($text, self::LIMIT * 3) as $row) {
            if (count($suggestions) + count($products) >= self::LIMIT) {
                break;
            }
            if (in_array($key = mb_strtolower($row['title']), $seen, true)) {
                continue;
            }
            $products[] = $row;
            $seen[] = $key;
        }

        $covers = $this->productRepository->findCoverImageUrls(array_column($products, 'id'));

        foreach ($products as $row) {
            $suggestions[] = [
                'type' => 'product',
                'title' => $row['title'],
                'slug' => $row['slug'],
                'productCondition' => $row['productCondition'],
                'coverImage' => $covers[$row['id']] ?? null,
            ];
        }

        return $suggestions;
    }
}
