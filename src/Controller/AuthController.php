<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Message\TwoFactorCodeMessage;
use App\Repository\UserRepository;
use App\Security\GoogleAuthenticator;
use App\Service\AuthCodeService;
use App\Service\RateLimiterService;
use App\Service\RefreshTokenCookieFactory;
use App\Service\TrustedDeviceService;
use Doctrine\ORM\EntityManagerInterface;
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
    private RefreshTokenManagerInterface $refreshTokenManager,
    private NormalizerInterface $objectNormalizer,
    private JWTTokenManagerInterface $jwtManager,
    private EntityManagerInterface $entityManager,
    private RateLimiterService $rateLimiter,
    private RefreshTokenCookieFactory $refreshTokenCookieFactory,
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
    $fingerprint = $data['fingerprint'] ?? '';

    if ($this->rateLimiter->tooManyAttempts('resend_2fa_' . $email, 3)) {
      return $this->json(['message' => 'Too many requests. Please try again later.'], 429);
    }
    $this->rateLimiter->hit('resend_2fa_' . $email, 600);

    $user = $userRepository->findOneBy(['email' => $email]);

    if (!$user) {
      return $this->json([
        'message' => 'User not found.',
      ], 404);
    }
    $authCodeService->removeAuthCode($user->getId());
    $code = $authCodeService->generateAndStoreAuthCode($user->getId());
    $sendMethod = $user->getTwoFactorContactMethod();

    if ('email' === $sendMethod) {
      $messageBusInterface->dispatch(
        new TwoFactorCodeMessage(
          $user->getEmail(),
          $code,
          $user->getFullName(),
          15,
          $fingerprint['userAgent'] ?? null
        ),
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

    if ($this->rateLimiter->tooManyAttempts('login_' . $email, 10)) {
      return $this->json(['message' => 'Too many login attempts. Please try again later.'], 429);
    }

    $user = $userRepository->findOneBy(['email' => $email]);

    if (!$user || !$passwordHasher->isPasswordValid($user, $password)) {
      $this->rateLimiter->hit('login_' . $email, 900);

      return $this->json(['message' => 'Invalid credentials'], 401);
    }

    if ($user->isTwoFactor()) {
      if (!$trustedDeviceService->isDeviceTrusted($fingerprint['visitorId'] ?? '', $user)) {
        $code = $authCodeService->generateAndStoreAuthCode($user->getId());
        $sendMethod = $user->getTwoFactorContactMethod();

        if ('email' === $sendMethod) {
          $messageBusInterface->dispatch(
            new TwoFactorCodeMessage(
              $user->getEmail(),
              $code,
              $user->getFullName(),
              15,
              $fingerprint['userAgent'] ?? null
            ),
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
    $refreshTokenString = $this->refreshTokenCookieFactory->createForUser($user);
    $normalizedUser = $this->objectNormalizer->normalize($user, null, ['groups' => ['user:read', 'user:login:read']]);

    $response = new JsonResponse([
      'success' => true,
      'message' => 'Authentication successful',
      'access_token' => $accessToken,
      'user' => $normalizedUser,
    ]);

    $response->headers->setCookie($this->refreshTokenCookieFactory->createCookie($refreshTokenString));

    return $response;
  }

  #[Route('/api/admin_login', name: 'api_admin_login', methods: ['POST', 'OPTIONS'])]
  public function adminLogin(
    Request $request,
    UserRepository $userRepository,
    UserPasswordHasherInterface $passwordHasher,
    AuthCodeService $authCodeService,
    MessageBusInterface $messageBusInterface,
  ): JsonResponse {
    $data = json_decode($request->getContent(), true);
    $email = $data['email'] ?? '';
    $password = $data['password'] ?? '';
    $fingerprint = $data['fingerprint'] ?? '';

    if ($this->rateLimiter->tooManyAttempts('login_' . $email, 10)) {
      return $this->json(['message' => 'Too many login attempts. Please try again later.'], 429);
    }

    $user = $userRepository->findOneBy(['email' => $email]);

    if (!$user || !$passwordHasher->isPasswordValid($user, $password)) {
      $this->rateLimiter->hit('login_' . $email, 900);

      return $this->json(['message' => 'Invalid credentials'], 401);
    }

    $roles = $user->getRoles();

    if (!in_array('ROLE_ADMIN', $roles, true)) {
      return $this->json(['message' => 'Access denied. Admins only.'], 403);
    }

    $code = $authCodeService->generateAndStoreAuthCode($user->getId());

    $messageBusInterface->dispatch(
      new TwoFactorCodeMessage(
        $user->getEmail(),
        $code,
        $user->getFullName(),
        15,
        $fingerprint['userAgent'] ?? null
      ),
    );

    return $this->json([
      'message' => 'A code has been sent to your email for verification.',
      'TFARequired' => true,
      'email' => $user->getEmail(),
    ], 403);

    // $accessToken = $this->jwtManager->create($user);
    // $refreshTokenString = $this->refreshTokenCookieFactory->createForUser($user);
    // $normalizedUser = $this->objectNormalizer->normalize($user, null, ['groups' => ['user:read', 'user:login:read']]);

    // $response = new JsonResponse([
    //   'success' => true,
    //   'message' => 'Authentication successful',
    //   'access_token' => $accessToken,
    //   'user' => $normalizedUser,
    // ]);

    // $response->headers->setCookie($this->refreshTokenCookieFactory->createCookie($refreshTokenString));

    // return $response;
  }

  #[Route('/api/2fa/verify', name: 'api_2fa_verify', methods: ['POST', 'OPTIONS'])]
  public function verify2FA(Request $request, AuthCodeService $authCodeService, TrustedDeviceService $trustedDeviceService): JsonResponse
  {
    $data = json_decode($request->getContent(), true);
    $email = $data['email'] ?? '';
    $authCode = $data['code'] ?? '';
    $fingerprint = $data['fingerprint'] ?? '';

    if ($this->rateLimiter->tooManyAttempts('2fa_verify_' . $email, 10)) {
      return $this->json(['message' => 'Too many attempts. Please try again later.'], 429);
    }

    $user = $this->userRepository->findOneBy(['email' => $email]);

    if (!$user instanceof User) {
      $this->rateLimiter->hit('2fa_verify_' . $email, 900);

      return $this->json(['message' => 'User not found'], 404);
    }

    if (!$authCodeService->isAuthCodeValid($user->getId(), $authCode)) {
      $this->rateLimiter->hit('2fa_verify_' . $email, 900);

      return $this->json(['message' => 'Invalid or expired auth code'], 403);
    }

    $trustedDeviceService->createTrustedDeviceEntry($fingerprint, $user);
    $authCodeService->removeAuthCode($user->getId());

    $accessToken = $this->jwtManager->create($user);
    $refreshTokenString = $this->refreshTokenCookieFactory->createForUser($user);
    $normalizedUser = $this->objectNormalizer->normalize($user, null, ['groups' => ['user:read', 'user:login:read']]);

    $response = new JsonResponse([
      'success' => true,
      'access_token' => $accessToken,
      'user' => $normalizedUser,
    ]);

    $response->headers->setCookie($this->refreshTokenCookieFactory->createCookie($refreshTokenString));

    return $response;
  }

  #[Route('/api/token/refresh', name: 'app_refresh_token', methods: ['POST', 'OPTIONS'])]
  public function refreshToken(
    Request $request,
    RefreshTokenManagerInterface $refreshTokenManager,
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

    // 2. Generate and save the NEW token
    $newRefreshTokenString = $this->refreshTokenCookieFactory->createForUser($user);

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

    $response->headers->setCookie($this->refreshTokenCookieFactory->createCookie($newRefreshTokenString));

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

}