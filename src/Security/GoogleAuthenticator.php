<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\BasketService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Google\Client;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
        private RefreshTokenGeneratorInterface $refreshTokenGenerator,
        private RefreshTokenManagerInterface $refreshTokenManager,
        private NormalizerInterface $objectNormalizer,
        private BasketService $basketService,
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
        } catch (Exception $e) {
            throw new AuthenticationException('Google authentication failed: ' . $e->getMessage());
        }
    }

    private function findOrCreateUser(array $payload): User
    {
        $email = $payload['email'];
        $googleId = $payload['sub'];
        $firstName = $payload['given_name'] ?? '';
        $lastName = $payload['family_name'] ?? '';
        $avatarUrl = $payload['picture'] ?? null;

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
        $user->setPassword(uniqid('google_', true));
        $user->setRoles(['ROLE_USER']);

        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->basketService->createBasketForUser($user);

        return $user;
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
            } catch (Exception $e) {
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
        $refreshTokenString = $this->generateRefreshToken($user);

        $normalizedUser = $this->objectNormalizer->normalize($user, null, ['groups' => ['user:read', 'user:login:read']]);

        // Prepare response with both tokens
        return new JsonResponse([
            'success' => true,
            'token' => $accessToken,
            'refresh_token' => $refreshTokenString,
            'token_type' => 'Bearer',
            'expires_in' => 3600, // 1 hour in seconds
            'user' => $normalizedUser,
        ]);
    }

    private function generateRefreshToken(User $user): string
    {
        $refreshTokenString = (string) $this->refreshTokenGenerator->createForUserWithTtl($user, 2592000);

        // Save refresh token to database
        $this->saveRefreshToken($user, $refreshTokenString);

        return (string) $refreshTokenString;
    }

    private function saveRefreshToken(User $user, string $refreshTokenString): void
    {
        try {
            // Method 1: Use manager (check if create method exists)
            if (method_exists($this->refreshTokenManager, 'create')) {
                $refreshToken = $this->refreshTokenManager->create();
            }
            // Method 2: Use createEntity method
            elseif (method_exists($this->refreshTokenManager, 'createEntity')) {
                $refreshToken = $this->refreshTokenManager->createEntity();
            }
            // Method 3: Manual instantiation
            else {
                $refreshToken = new RefreshToken();
            }

            $refreshToken->setRefreshToken($refreshTokenString);
            $refreshToken->setUsername($user->getEmail());
            $refreshToken->setValid((new DateTime())->modify('+30 days'));

            // RefreshTokenManagerInterface guarantees that save() is available.
            $this->refreshTokenManager->save($refreshToken);
        } catch (Exception $e) {
            // Fallback: Use EntityManager directly
            $refreshToken = new RefreshToken();
            $refreshToken->setRefreshToken($refreshTokenString);
            $refreshToken->setUsername($user->getEmail());
            $refreshToken->setValid((new DateTime())->modify('+30 days'));

            $this->entityManager->persist($refreshToken);
            $this->entityManager->flush();
        }
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
