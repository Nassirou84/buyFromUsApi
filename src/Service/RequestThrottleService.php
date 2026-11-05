<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Lightweight fixed-window request throttle backed by the application cache.
 *
 * This is deliberately not a replacement for symfony/rate-limiter (which is not
 * installed here); it is a dependency-free guard that blunts enumeration and
 * spam abuse on a handful of public endpoints.
 */
final class RequestThrottleService
{
    public function __construct(private CacheItemPoolInterface $cache)
    {
    }

    /**
     * Records one hit for $key and returns whether it is still within $limit
     * hits per $windowSeconds. Returns false once the limit is exceeded.
     */
    public function allow(string $key, int $limit, int $windowSeconds): bool
    {
        $item = $this->cache->getItem('throttle_' . hash('xxh128', $key));
        $count = $item->isHit() ? (int) $item->get() : 0;

        if ($count >= $limit) {
            return false;
        }

        if (0 === $count) {
            // Only the first hit opens the window; later hits keep the original
            // expiry so the window is fixed rather than sliding forever.
            $item->expiresAfter($windowSeconds);
        }
        $item->set($count + 1);
        $this->cache->save($item);

        return true;
    }
}
