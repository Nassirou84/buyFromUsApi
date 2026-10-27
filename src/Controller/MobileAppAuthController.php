<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Message\TwoFactorCodeMessage;
use App\Repository\UserRepository;
use App\Service\AuthCodeService;
use App\Service\RateLimiterService;
use App\Service\RefreshTokenCookieFactory;
use App\Service\TrustedDeviceService;
use App\Service\UserService;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function count;
use function is_array;
use function is_string;

/**
 * Auth endpoints for the mobile app. Same rules as the web flow in AuthController. Send
 * `X-Client: mobile` so the refresh token comes back in the JSON body; without it the refresh token
 * is set as the HttpOnly cookie, exactly like the web endpoints. Refresh and logout read the token
 * from the body (or the cookie on the web endpoints).
 *
 * Every route here is public by design (they are how a client gets a session). Do not send an
 * Authorization header to them: the firewall's JWT authenticator would reject an expired token
 * before the controller runs. Password reset is handled on the web.
 */
#[Route('/api/mobile/auth', name: 'api_mobile_auth_')]
final class MobileAppAuthController extends AbstractController
{
    private const ACCESS_TOKEN_TTL = 3600; // keep in sync with lexik_jwt_authentication.token_ttl

    public function __construct(
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private JWTTokenManagerInterface $jwtManager,
        private RefreshTokenManagerInterface $refreshTokenManager,
        private RefreshTokenCookieFactory $refreshTokenFactory,
        private NormalizerInterface $objectNormalizer,
        private RateLimiterService $rateLimiter,
        private TrustedDeviceService $trustedDeviceService,
        private AuthCodeService $authCodeService,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * Body: email, password, firstName, lastName. Signs the new user in straight away.
     */
    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(Request $request, UserService $userService, ValidatorInterface $validator): JsonResponse
    {
        $data = $this->body($request);
        $data['email'] = trim((string) ($data['email'] ?? ''));

        $rateKey = 'mobile_register_' . $request->getClientIp();
        if ($this->rateLimiter->tooManyAttempts($rateKey, 10)) {
            return $this->json(['message' => 'Too many registration attempts. Please try again later.'], 429);
        }
        $this->rateLimiter->hit($rateKey, 3600);

        $violations = $validator->validate($data, new Assert\Collection(
            fields: [
                'email' => [new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 180)],
                'password' => [new Assert\Type('string'), new Assert\Length(min: 8, max: 128)],
                'firstName' => [new Assert\Type('string'), new Assert\NotBlank(), new Assert\Length(max: 255)],
                'lastName' => [new Assert\Type('string'), new Assert\NotBlank(), new Assert\Length(max: 255)],
            ],
            allowExtraFields: true,
        ));

        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[trim($violation->getPropertyPath(), '[]')] ??= (string) $violation->getMessage();
            }

            return $this->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
        }

        if ($this->userRepository->findOneBy(['email' => $data['email']])) {
            return $this->json(['message' => 'existing_email'], 409);
        }

        $user = (new User())
            ->setEmail($data['email'])
            ->setPassword($data['password'])
            ->setFirstName(trim($data['firstName']))
            ->setLastName(trim($data['lastName']));

        // Roles are forced to ROLE_USER inside createUser(); client-supplied roles are never read.
        $user = $userService->createUser($user);

        return $this->authenticated($request, $user, 'Registration successful', 201);
    }

    /**
     * Body: email, password, fingerprint {visitorId, userAgent, platform, language, timeZone}.
     * Returns 403 with TFARequired when the user has 2FA on and this device is not trusted yet.
     */
    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $data = $this->body($request);
        $email = trim((string) ($data['email'] ?? ''));
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';
        $fingerprint = is_array($data['fingerprint'] ?? null) ? $data['fingerprint'] : [];

        $rateKey = 'login_' . $email; // shared with the web login so both entry points count together
        if ($this->rateLimiter->tooManyAttempts($rateKey, 10)) {
            return $this->json(['message' => 'Too many login attempts. Please try again later.'], 429);
        }

        $user = $this->userRepository->findOneBy(['email' => $email]);

        if (!$user || '' === $password || !$this->passwordHasher->isPasswordValid($user, $password)) {
            $this->rateLimiter->hit($rateKey, 900);

            return $this->json(['message' => 'Invalid credentials'], 401);
        }

        if ($user->isTwoFactor() && !$this->trustedDeviceService->isDeviceTrusted((string) ($fingerprint['visitorId'] ?? ''), $user)) {
            $this->sendTwoFactorCode($user, $fingerprint);

            return $this->json([
                'message' => 'New device detected. Please verify with the auth code sent to your email.',
                'TFARequired' => true,
                'email' => $user->getEmail(),
            ], 403);
        }

        return $this->authenticated($request, $user, 'Authentication successful');
    }

    /**
     * Body: email, code, fingerprint. Finishes a login that answered TFARequired and trusts the device.
     */
    #[Route('/2fa/verify', name: 'two_factor_verify', methods: ['POST'])]
    public function verifyTwoFactor(Request $request): JsonResponse
    {
        $data = $this->body($request);
        $email = trim((string) ($data['email'] ?? ''));
        $code = is_string($data['code'] ?? null) ? $data['code'] : '';
        $fingerprint = is_array($data['fingerprint'] ?? null) ? $data['fingerprint'] : [];

        $rateKey = '2fa_verify_' . $email;
        if ($this->rateLimiter->tooManyAttempts($rateKey, 10)) {
            return $this->json(['message' => 'Too many attempts. Please try again later.'], 429);
        }

        $user = $this->userRepository->findOneBy(['email' => $email]);

        if (!$user instanceof User) {
            $this->rateLimiter->hit($rateKey, 900);

            return $this->json(['message' => 'User not found'], 404);
        }

        if ('' === $code || !$this->authCodeService->isAuthCodeValid($user->getId(), $code)) {
            $this->rateLimiter->hit($rateKey, 900);

            return $this->json(['message' => 'Invalid or expired auth code'], 403);
        }

        $visitorId = (string) ($fingerprint['visitorId'] ?? '');
        if ('' !== $visitorId && !$this->trustedDeviceService->isDeviceTrusted($visitorId, $user)) {
            $this->trustedDeviceService->createTrustedDeviceEntry($fingerprint, $user);
        }
        $this->authCodeService->removeAuthCode($user->getId());

        return $this->authenticated($request, $user, 'Authentication successful');
    }

    /**
     * Body: email, fingerprint. Sends a fresh 2FA code (max 3 per 10 minutes).
     */
    #[Route('/2fa/resend', name: 'two_factor_resend', methods: ['POST'])]
    public function resendTwoFactor(Request $request): JsonResponse
    {
        $data = $this->body($request);
        $email = trim((string) ($data['email'] ?? ''));
        $fingerprint = is_array($data['fingerprint'] ?? null) ? $data['fingerprint'] : [];

        $rateKey = 'resend_2fa_' . $email;
        if ($this->rateLimiter->tooManyAttempts($rateKey, 3)) {
            return $this->json(['message' => 'Too many requests. Please try again later.'], 429);
        }
        $this->rateLimiter->hit($rateKey, 600);

        $user = $this->userRepository->findOneBy(['email' => $email]);

        if (!$user instanceof User) {
            return $this->json(['message' => 'User not found.'], 404);
        }

        $this->authCodeService->removeAuthCode($user->getId());
        $this->sendTwoFactorCode($user, $fingerprint);

        return $this->json([
            'success' => true,
            'message' => 'A new 2FA code has been sent to your ' . $user->getTwoFactorContactMethod() . '.',
            'TFARequired' => true,
            'email' => $user->getEmail(),
        ]);
    }

    /**
     * Body: refresh_token. Rotates it: the old one stops working and a new pair is returned.
     */
    #[Route('/refresh', name: 'refresh', methods: ['POST'])]
    public function refresh(Request $request, UserProviderInterface $userProvider): JsonResponse
    {
        $refreshTokenString = $this->refreshTokenFactory->extractRefreshToken($request);

        if (null === $refreshTokenString) {
            return $this->json(['message' => 'Refresh token missing'], 401);
        }

        $refreshToken = $this->refreshTokenManager->get($refreshTokenString);

        if (!$refreshToken || !$refreshToken->isValid()) {
            return $this->json(['message' => 'Invalid or expired refresh token'], 401);
        }

        $user = $userProvider->loadUserByIdentifier($refreshToken->getUsername());
        if (!$user instanceof User) {
            return $this->json(['message' => 'Invalid or expired refresh token'], 401);
        }

        $this->refreshTokenManager->delete($refreshToken);

        return $this->authenticated($request, $user, 'Token refreshed successfully');
    }

    /**
     * Body: refresh_token. Revokes it. Always answers 200 so the app can clear its session regardless.
     */
    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $refreshTokenString = $this->refreshTokenFactory->extractRefreshToken($request);

        if (null !== $refreshTokenString) {
            $refreshToken = $this->refreshTokenManager->get($refreshTokenString);
            if ($refreshToken) {
                $this->refreshTokenManager->delete($refreshToken);
            }
        }

        return $this->json(['message' => 'Logged out successfully']);
    }

    /**
     * Google sign-in: POST id_token to this route. Handled entirely by GoogleAuthenticator (the
     * firewall intercepts it), which creates the account on first use and returns the same payload
     * as login. This body is never reached on success.
     */
    #[Route('/google', name: 'google', methods: ['POST'])]
    public function google(): JsonResponse
    {
        return $this->json(['message' => 'Missing Google ID token'], 401);
    }

    private function authenticated(Request $request, User $user, string $message, int $status = 200): JsonResponse
    {
        return $this->refreshTokenFactory->respond($request, [
            'success' => true,
            'message' => $message,
            'access_token' => $this->jwtManager->create($user),
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TOKEN_TTL,
            'user' => $this->objectNormalizer->normalize($user, null, ['groups' => ['user:read', 'user:login:read']]),
        ], $this->refreshTokenFactory->createForUser($user), $status);
    }

    private function sendTwoFactorCode(User $user, array $fingerprint): void
    {
        $code = $this->authCodeService->generateAndStoreAuthCode($user->getId());

        if ('email' === $user->getTwoFactorContactMethod()) {
            $this->messageBus->dispatch(new TwoFactorCodeMessage(
                $user->getEmail(),
                $code,
                $user->getFullName(),
                15,
                $fingerprint['userAgent'] ?? null,
            ));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        $data = json_decode($request->getContent(), true);

        return is_array($data) ? $data : [];
    }
}
