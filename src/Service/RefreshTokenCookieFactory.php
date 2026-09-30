<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Creates and persists a refresh token for a user and builds the cookie that
 * carries it, so the JWT/GoogleAuthenticator login paths don't each
 * reimplement the same TTL and cookie settings.
 */
class RefreshTokenCookieFactory
{
    private const TTL = 2592000; // 30 days

    public function __construct(
        private RefreshTokenGeneratorInterface $refreshTokenGenerator,
        private RefreshTokenManagerInterface $refreshTokenManager,
    ) {
    }

    public function createForUser(UserInterface $user): string
    {
        $refreshToken = $this->refreshTokenGenerator->createForUserWithTtl($user, self::TTL);
        $this->refreshTokenManager->save($refreshToken);

        return $refreshToken->getRefreshToken();
    }

    public function createCookie(string $refreshTokenString): Cookie
    {
        return Cookie::create('refresh_token')
            ->withValue($refreshTokenString)
            ->withExpires(new DateTimeImmutable('+30 days'))
            ->withPath('/')
            ->withHttpOnly(true)
            ->withSecure(true)
            ->withSameSite(Cookie::SAMESITE_NONE);
    }
}
