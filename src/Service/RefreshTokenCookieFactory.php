<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_array;
use function is_string;

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

    /**
     * Clients that cannot keep a cookie (the mobile app) announce themselves with `X-Client: mobile`
     * and get the refresh token in the JSON body. Everyone else keeps getting the HttpOnly cookie.
     */
    public function isMobileClient(Request $request): bool
    {
        return 'mobile' === strtolower(trim((string) $request->headers->get('X-Client')));
    }

    /**
     * The refresh token a client sent: the JSON body's `refresh_token` first (mobile), then the cookie (web).
     */
    public function extractRefreshToken(Request $request): ?string
    {
        $body = json_decode($request->getContent(), true);
        $token = is_array($body) ? ($body['refresh_token'] ?? null) : null;

        if (!is_string($token) || '' === $token) {
            $token = $request->cookies->get('refresh_token');
        }

        return is_string($token) && '' !== $token ? $token : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function respond(Request $request, array $payload, string $refreshTokenString, int $status = 200): JsonResponse
    {
        if ($this->isMobileClient($request)) {
            return new JsonResponse($payload + ['refresh_token' => $refreshTokenString], $status);
        }

        $response = new JsonResponse($payload, $status);
        $response->headers->setCookie($this->createCookie($refreshTokenString));

        return $response;
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
