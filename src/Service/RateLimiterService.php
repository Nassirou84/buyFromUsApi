<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Simple fixed-window attempt counter used to throttle sensitive,
 * unauthenticated endpoints (login, 2FA verify/resend) against brute-force
 * and spam abuse, without pulling in the symfony/rate-limiter component.
 */
class RateLimiterService
{
    public function __construct(
        private CacheItemPoolInterface $cacheInterface,
    ) {
    }

    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        $item = $this->cacheInterface->getItem($this->cacheKey($key));

        return $item->isHit() && $item->get() >= $maxAttempts;
    }

    public function hit(string $key, int $windowSeconds): void
    {
        $item = $this->cacheInterface->getItem($this->cacheKey($key));
        $attempts = ($item->isHit() ? $item->get() : 0) + 1;
        $item->set($attempts);
        $item->expiresAfter($windowSeconds);
        $this->cacheInterface->save($item);
    }

    private function cacheKey(string $key): string
    {
        return 'rate_limit_' . hash('sha256', $key);
    }
}
