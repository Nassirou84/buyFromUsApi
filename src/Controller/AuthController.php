<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Message\TwoFactorCodeMessage;
use App\Repository\UserRepository;
use App\Security\GoogleAuthenticator;
use App\Service\AuthCodeService;
use App\Service\TrustedDeviceService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class AuthController extends AbstractController
{
  public function __construct(
    private UserRepository $userRepository,
    private RefreshTokenGeneratorInterface $refreshTokenGenerator,
    private RefreshTokenManagerInterface $refreshTokenManager,
    private NormalizerInterface $objectNormalizer,
    private JWTTokenManagerInterface $jwtManager,
    private EntityManagerInterface $entityManager,
  ) {
  }

  #[Route('/api/auth/google', name: 'api_google_login', methods: ['POST'])]
  public function googleLogin(Request $request, GoogleAuthenticator $authenticator): Response
  {
    return new Response('', 200);
  }

  // Resend 2FA code endpoint
  #[Route('/api/resend-2fa-code', name: 'api_resend_2fa_code', methods: ['POST', 'OPTIONS'])]
  public function resend2FACode(
    Request $request,
    AuthCodeService $authCodeService,
    MessageBusInterface $messageBusInterface,
    UserRepository $userRepository,
  ): JsonResponse {
    $data = json_decode($request->getContent(), true);
    $email = $data['email'] ?? '';

    $user = $userRepository->findOneBy(['email' => $email]);

    if (!$user) {
      return $this->json(['message' => 'User not found'], 404);
    }
    $authCodeService->removeAuthCode($user->getId());
    $code = $authCodeService->generateAndStoreAuthCode($user->getId());
    $sendMethod = $user->getTwoFactorContactMethod();

    if ('email' === $sendMethod) {
      $messageBusInterface->dispatch(
        new TwoFactorCodeMessage($user->getEmail(), $code, $user->getFullName()),
      );
    }

    return $this->json([
      'message' => 'A new 2FA code has been sent to your ' . $sendMethod . '.',
      'TFARequired' => true,
      'email' => $user->getEmail(),
      'success' => true,
    ], 200);
  }

  #[Route('/api/user_login', name: 'api_login', methods: ['POST', 'OPTIONS'])]
  public function login(
    Request $request,
    UserRepository $userRepository,
    UserPasswordHasherInterface $passwordHasher,
    TrustedDeviceService $trustedDeviceService,
    AuthCodeService $authCodeService,
    MessageBusInterface $messageBusInterface,
  ): JsonResponse {
    $data = json_decode($request->getContent(), true);
    $email = $data['email'] ?? '';
    $password = $data['password'] ?? '';
    $fingerprint = $data['fingerprint'] ?? '';

    $user = $userRepository->findOneBy(['email' => $email]);

    if (!$user || !$passwordHasher->isPasswordValid($user, $password)) {
      return $this->json(['message' => 'Invalid credentials'], 401);
    }

    if ($user->isTwoFactor()) {
      if (!$trustedDeviceService->isDeviceTrusted($fingerprint['visitorId'] ?? '', $user)) {
        $code = $authCodeService->generateAndStoreAuthCode($user->getId());
        $sendMethod = $user->getTwoFactorContactMethod();

        if ('email' === $sendMethod) {
          $messageBusInterface->dispatch(
            new TwoFactorCodeMessage($user->getEmail(), $code, $user->getFullName()),
          );
        }

        return $this->json([
          'message' => 'New device detected. Please verify with the auth code sent to your email.',
          'TFARequired' => true,
          'email' => $user->getEmail(),
        ], 403);
      }
    }

    $accessToken = $this->jwtManager->create($user);
    $refreshTokenString = $this->generateRefreshTokenString($user);
    $normalizedUser = $this->objectNormalizer->normalize($user, null, ['groups' => ['user:read', 'user:login:read']]);

    $response = new JsonResponse([
      'success' => true,
      'message' => 'Authentication successful',
      'access_token' => $accessToken,
      'user' => $normalizedUser,
    ]);

    $response->headers->setCookie($this->createRefreshCookie($refreshTokenString));

    return $response;
  }

  #[Route('/api/2fa/verify', name: 'api_2fa_verify', methods: ['POST', 'OPTIONS'])]
  public function verify2FA(Request $request, AuthCodeService $authCodeService, TrustedDeviceService $trustedDeviceService): JsonResponse
  {
    $data = json_decode($request->getContent(), true);
    $email = $data['email'] ?? '';
    $authCode = $data['code'] ?? '';
    $fingerprint = $data['fingerprint'] ?? '';

    $user = $this->userRepository->findOneBy(['email' => $email]);

    if (!$user instanceof User) {
      return $this->json(['message' => 'User not found'], 404);
    }

    if (!$authCodeService->isAuthCodeValid($user->getId(), $authCode)) {
      return $this->json(['message' => 'Invalid or expired auth code'], 403);
    }

    $trustedDeviceService->createTrustedDeviceEntry($fingerprint, $user);
    $authCodeService->removeAuthCode($user->getId());

    $accessToken = $this->jwtManager->create($user);
    $refreshTokenString = $this->generateRefreshTokenString($user);
    $normalizedUser = $this->objectNormalizer->normalize($user, null, ['groups' => ['user:read', 'user:login:read']]);

    $response = new JsonResponse([
      'success' => true,
      'access_token' => $accessToken,
      'user' => $normalizedUser,
    ]);

    $response->headers->setCookie($this->createRefreshCookie($refreshTokenString));

    return $response;
  }

  #[Route('/api/token/refresh', name: 'app_refresh_token', methods: ['POST', 'OPTIONS'])]
  public function refreshToken(
    Request $request,
    RefreshTokenManagerInterface $refreshTokenManager,
    RefreshTokenGeneratorInterface $refreshTokenGenerator,
    JWTTokenManagerInterface $jwtManager,
    UserProviderInterface $userProvider,
  ): JsonResponse {
    if ($request->isMethod('OPTIONS')) {
      return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    $refreshTokenString = $request->cookies->get('refresh_token')
      ?? json_decode($request->getContent(), true)['refresh_token'] ?? null;

    if (!$refreshTokenString) {
      return new JsonResponse(['error' => 'Refresh token missing'], Response::HTTP_UNAUTHORIZED);
    }

    $refreshToken = $refreshTokenManager->get($refreshTokenString);

    if (!$refreshToken || !$refreshToken->isValid()) {
      return new JsonResponse(['error' => 'Invalid or expired refresh token'], Response::HTTP_UNAUTHORIZED);
    }

    $user = $userProvider->loadUserByIdentifier($refreshToken->getUsername());
    $newAccessToken = $jwtManager->create($user);

    // 1. Capture the old token string before generating the new one
    $oldTokenString = $refreshToken->getRefreshToken();

    // 2. Generate and save the NEW token (createForUserWithTtl persists it automatically)
    $ttl = 2592000; // 30 days
    $newRefreshToken = $refreshTokenGenerator->createForUserWithTtl($user, $ttl);
    $refreshTokenManager->save($newRefreshToken);

    // 3. Delete the OLD token from the database using DQL
    $this->entityManager->createQuery(
      'DELETE FROM App\Entity\RefreshToken r WHERE r.refreshToken = :oldToken',
    )
      ->setParameter('oldToken', $oldTokenString)
      ->execute();

    $response = new JsonResponse([
      'success' => true,
      'access_token' => $newAccessToken,
      'user' => $this->objectNormalizer->normalize($user, null, ['groups' => ['user:read', 'user:login:read']]),
      'message' => 'Token refreshed successfully',
    ]);

    $response->headers->setCookie($this->createRefreshCookie($newRefreshToken->getRefreshToken()));

    return $response;
  }

  #[Route('/api/auth/logout', name: 'app_logout', methods: ['POST', 'OPTIONS'])]
  public function logout(Request $request): JsonResponse
  {
    if ($request->isMethod('OPTIONS')) {
      return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
    $refreshTokenString = $request->cookies->get('refresh_token')
      ?? json_decode($request->getContent(), true)['refresh_token'] ?? null;

    if ($refreshTokenString) {
      $refreshToken = $this->refreshTokenManager->get($refreshTokenString);
      if ($refreshToken) {
        $this->refreshTokenManager->delete($refreshToken);
        $this->entityManager->flush();
      }
    }

    $response = new JsonResponse(['message' => 'Logged out successfully']);
    $response->headers->clearCookie(
      name: 'refresh_token',
      path: '/',
      domain: null,
      secure: true,
      sameSite: Cookie::SAMESITE_NONE
    );

    return $response;
  }

  private function generateRefreshTokenString(User $user): string
  {
    $ttl = 2592000; // 30 days
    $refreshToken = $this->refreshTokenGenerator->createForUserWithTtl($user, $ttl);
    $this->refreshTokenManager->save($refreshToken);

    return $refreshToken->getRefreshToken();
  }

  private function createRefreshCookie(string $refreshTokenString): Cookie
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