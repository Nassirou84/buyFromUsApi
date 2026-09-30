<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\PromoCode;
use App\Entity\Setting;
use App\Entity\User;
use App\Message\WelcomeMessage;
use App\Repository\UserRepository;
use App\Service\BasketService;
use App\Service\PromoCodeService;
use App\Service\RefreshTokenCookieFactory;
use App\Service\SettingService;
use Doctrine\ORM\EntityManagerInterface;
use Google\Client;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class GoogleAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    private Client $googleClient;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private JWTTokenManagerInterface $jwtManager,
        private RefreshTokenCookieFactory $refreshTokenCookieFactory,
        private NormalizerInterface $objectNormalizer,
        private BasketService $basketService,
        private SettingService $settingService,
        private PromoCodeService $promoCodeService,
        private MessageBusInterface $messageBusInterface,
        private UserPasswordHasherInterface $passwordHasher,
        string $googleClientId,
    ) {
        $this->googleClient = new Client(['client_id' => $googleClientId]);
    }

    public function supports(Request $request): ?bool
    {
        return 'api_google_login' === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): Passport
    {
        $idToken = $this->extractIdToken($request);

        if (!$idToken) {
            throw new AuthenticationException('Missing Google ID token');
        }

        if (!str_starts_with($idToken, 'eyJ')) {
            throw new AuthenticationException('Invalid token format. Expected JWT token starting with "eyJ"');
        }

        try {
            $payload = $this->googleClient->verifyIdToken($idToken);

            if (!$payload) {
                throw new AuthenticationException('Invalid Google ID token - verification failed');
            }

            if (isset($payload['exp']) && $payload['exp'] < time()) {
                throw new AuthenticationException('Google ID token has expired');
            }

            if (!isset($payload['email']) || !isset($payload['sub'])) {
                throw new AuthenticationException('Missing required user data from Google');
            }

            $user = $this->findOrCreateUser($payload);

            return new SelfValidatingPassport(
                new UserBadge($user->getEmail(), static function () use ($user) {
                    return $user;
                }),
            );
        } catch (AuthenticationException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new AuthenticationException('Google authentication failed: ' . $e->getMessage());
        }
    }

    private function findOrCreateUser(array $payload): User
    {
        $email = $payload['email'];
        $googleId = $payload['sub'];
        $firstName = $payload['given_name'] ?? '';
        $lastName = $payload['family_name'] ?? '';

        $existingUser = $this->userRepository->findOneBy(['email' => $email]);

        if ($existingUser) {
            if (!$existingUser->getGoogleId()) {
                $existingUser->setGoogleId($googleId);
                $this->entityManager->flush();
            }

            return $existingUser;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setGoogleId($googleId);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($this->passwordHasher->hashPassword($user, bin2hex(random_bytes(32))));

        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->basketService->createBasketForUser($user);

        $welcomeCodeSetting = $this->settingService->getSetting(Setting::WELCOME_PROMO_CODE);
        $promoCode = null;
        if ($welcomeCodeSetting == Setting::TRUE) {
            $promoCode = $this->promoCodeService->createPromoCodeForWelcomeUser($user);
        }
        $this->sendWelcomeMessage($user, $promoCode);

        return $user;
    }

    public function sendWelcomeMessage(User $user, ?PromoCode $promoCode): void
    {
        $welcomeMessage = new WelcomeMessage(
            $user->getEmail(),
            $user->getFullName(),
            $promoCode?->getCode(),
            $promoCode?->getDiscount()
        );
        $this->messageBusInterface->dispatch($welcomeMessage);
    }

    private function extractIdToken(Request $request): ?string
    {
        $content = json_decode($request->getContent(), true);
        if ($content) {
            foreach (['id_token', 'idToken', 'token'] as $field) {
                if (isset($content[$field])) {
                    return $content[$field];
                }
            }
        }

        $headerToken = $request->headers->get('X-Google-ID-Token');
        if ($headerToken) {
            return $headerToken;
        }

        $authHeader = $request->headers->get('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
            try {
                $this->googleClient->verifyIdToken($token);

                return $token;
            } catch (\Exception $e) {
                // Not a valid Google ID token
            }
        }

        return null;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        /**
         * @var User $user
         */
        $user = $token->getUser();

        // Generate access token
        $accessToken = $this->jwtManager->create($user);

        // Generate refresh token
        $refreshTokenString = $this->refreshTokenCookieFactory->createForUser($user);

        $normalizedUser = $this->objectNormalizer->normalize($user, null, ['groups' => ['user:read', 'user:login:read']]);

        // Prepare response with both tokens
        $response = new JsonResponse([
            'success' => true,
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600, // 1 hour in seconds
            'user' => $normalizedUser,
        ]);

        $response->headers->setCookie($this->refreshTokenCookieFactory->createCookie($refreshTokenString));

        return $response;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse([
            'success' => false,
            'error' => $exception->getMessage(),
            'code' => $exception->getCode(),
        ], Response::HTTP_UNAUTHORIZED);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse([
            'error' => 'Authentication required',
            'message' => 'Please provide a valid Google ID token',
        ], Response::HTTP_UNAUTHORIZED);
    }
}