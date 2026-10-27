<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MobileAppAuthTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    /**
     * @return array<string, mixed>
     */
    private function post(string $uri, array $body): array
    {
        $this->client->request('POST', $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CLIENT' => 'mobile',
        ], json_encode($body));

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function register(string $email = 'mobile@test.com'): array
    {
        return $this->post('/api/mobile/auth/register', [
            'email' => $email,
            'password' => 'Passw0rd!x',
            'firstName' => 'Awa',
            'lastName' => 'Kone',
            'roles' => ['ROLE_ADMIN'],
        ]);
    }

    public function testRegisterReturnsTokensAndIgnoresRoles(): void
    {
        $data = $this->register();

        self::assertResponseStatusCodeSame(201);
        self::assertNotEmpty($data['access_token']);
        self::assertNotEmpty($data['refresh_token']);
        self::assertNotContains('ROLE_ADMIN', $data['user']['roles']);

        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'mobile@test.com']);
        self::assertNotContains('ROLE_ADMIN', $user->getRoles());
    }

    public function testWithoutMobileHeaderTheRefreshTokenIsACookie(): void
    {
        $this->client->request('POST', '/api/mobile/auth/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => 'web@test.com', 'password' => 'Passw0rd!x', 'firstName' => 'A', 'lastName' => 'B']));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayNotHasKey('refresh_token', $data);
        self::assertResponseHasCookie('refresh_token');
    }

    public function testRegisterRejectsDuplicateEmailAndInvalidInput(): void
    {
        $this->register();
        $this->register();
        self::assertResponseStatusCodeSame(409);

        $data = $this->post('/api/mobile/auth/register', ['email' => 'nope', 'password' => 'short']);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('email', $data['errors']);
        self::assertArrayHasKey('password', $data['errors']);
        self::assertArrayHasKey('firstName', $data['errors']);
    }

    public function testLoginWithRightAndWrongPassword(): void
    {
        $this->register();

        $this->post('/api/mobile/auth/login', ['email' => 'mobile@test.com', 'password' => 'wrong-password']);
        self::assertResponseStatusCodeSame(401);

        $data = $this->post('/api/mobile/auth/login', ['email' => 'mobile@test.com', 'password' => 'Passw0rd!x']);
        self::assertResponseIsSuccessful();
        self::assertNotEmpty($data['access_token']);
        self::assertNotEmpty($data['refresh_token']);
    }

    public function testRefreshRotatesTheToken(): void
    {
        $refresh = $this->register()['refresh_token'];

        $data = $this->post('/api/mobile/auth/refresh', ['refresh_token' => $refresh]);
        self::assertResponseIsSuccessful();
        self::assertNotSame($refresh, $data['refresh_token']);

        $this->post('/api/mobile/auth/refresh', ['refresh_token' => $refresh]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testLogoutRevokesTheRefreshToken(): void
    {
        $refresh = $this->register()['refresh_token'];

        $this->post('/api/mobile/auth/logout', ['refresh_token' => $refresh]);
        self::assertResponseIsSuccessful();

        $this->post('/api/mobile/auth/refresh', ['refresh_token' => $refresh]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testWebRefreshAndLogoutRoutesAcceptTheTokenInTheBody(): void
    {
        $refresh = $this->register()['refresh_token'];

        $data = $this->post('/api/token/refresh', ['refresh_token' => $refresh]);
        self::assertResponseIsSuccessful();
        self::assertNotSame($refresh, $data['refresh_token']);

        $this->post('/api/auth/logout', ['refresh_token' => $data['refresh_token']]);
        self::assertResponseIsSuccessful();

        $this->post('/api/token/refresh', ['refresh_token' => $data['refresh_token']]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testGoogleLoginRequiresAnIdToken(): void
    {
        $this->post('/api/mobile/auth/google', []);

        self::assertResponseStatusCodeSame(401);
    }
}
