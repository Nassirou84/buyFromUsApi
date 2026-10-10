<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\ProductRepository;
use App\Repository\SubcategoryRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class AdminProductController extends AbstractController
{
    private const MARKUP_CACHE_TTL = 604800; // 1 week

    public function adminCheck(): bool
    {
        $user = $this->getUser();
        if (!$user) {
            return false;
        }

        if (!in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return false;
        }
        return true;
    }

    #[Route('api/admin/product/summary', name: 'app_admin_product', methods: ['GET'])]
    public function summary(
        ProductRepository $productRepository,
        SubcategoryRepository $subcategoryRepository,
        CacheInterface $cache,
    ): JsonResponse {
        if (!$this->adminCheck()) {
            return $this->json([
                'error' => 'Forbidden',
            ], 403);
        }

        $productCount = $productRepository->count([]);
        $amazonCount = $productRepository->count(['seller' => 'amazon']);
        $ebayCount = $productRepository->count(['seller' => 'ebay']);

        $recentlyUpdatedCount = $cache->get('admin_recently_updated_count', function (ItemInterface $item) use ($productRepository) {
            $item->expiresAfter(self::MARKUP_CACHE_TTL);
            return $productRepository->countRecentlyUpdatedInDays(7);
        });
        $unavailableCount = $productRepository->count(['isAvailable' => false]);

        // Cache markup values to avoid recalculating them on every request
        $averageMarkup = $cache->get('admin_markup_average', function (ItemInterface $item) use ($subcategoryRepository) {
            $item->expiresAfter(self::MARKUP_CACHE_TTL);
            return $subcategoryRepository->getAverageMarkup();
        });
        $lowestMarkup = $cache->get('admin_markup_lowest', function (ItemInterface $item) use ($subcategoryRepository) {
            $item->expiresAfter(self::MARKUP_CACHE_TTL);
            return $subcategoryRepository->getLowestMarkup();
        });
        $highestMarkup = $cache->get('admin_markup_highest', function (ItemInterface $item) use ($subcategoryRepository) {
            $item->expiresAfter(self::MARKUP_CACHE_TTL);
            return $subcategoryRepository->getHighestMarkup();
        });

        return $this->json([
            'product' => [
                'count' => $productCount,
                'amazon' => $amazonCount,
                'ebay' => $ebayCount,
                'other' => $productCount - $amazonCount - $ebayCount,
                'unavailable' => $unavailableCount,
                'recently_updated' => $recentlyUpdatedCount,
            ],
            'markup' => [
                'average' => $averageMarkup,
                'lowest' => $lowestMarkup,
                'highest' => $highestMarkup
            ]
        ]);
    }
}