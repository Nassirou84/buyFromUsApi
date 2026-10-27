<?php

declare(strict_types=1);

namespace App\Tests\Security;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use App\Entity\Basket;
use App\Entity\Product;
use App\Entity\Setting;
use App\Entity\User;
use App\Service\CurrencyProvider;
use App\Service\SettingService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Regression tests for the privilege-escalation / missing-authorization
 * findings from the security review.
 */
final class AuthorizationRegressionTest extends WebTestCase
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

    private function json(string $method, string $uri, array $body): void
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode($body));
    }

    public function testRegistrationIgnoresClientSuppliedRoles(): void
    {
        $this->json('POST', '/api/users', [
            'email' => 'evil@test.com',
            'password' => 'Passw0rd!x',
            'firstName' => 'a',
            'lastName' => 'b',
            'roles' => ['ROLE_ADMIN'],
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertNotContains('ROLE_ADMIN', $data['roles']);
    }

    public function testProductWriteOperationsRequireAdmin(): void
    {
        $factory = static::getContainer()->get(ResourceMetadataCollectionFactoryInterface::class);
        $operations = $factory->create(Product::class)[0]->getOperations();

        foreach ($operations as $name => $operation) {
            if (!$operation instanceof HttpOperation || !in_array($operation->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                continue;
            }
            self::assertStringContainsString(
                'ROLE_ADMIN',
                (string) $operation->getSecurity(),
                sprintf('Product operation "%s" (%s) must require ROLE_ADMIN', $name, $operation->getMethod()),
            );
        }
    }

    public function testAnonymousCannotCancelOrder(): void
    {
        $this->json('POST', '/api/orders/anything/cancel', []);

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnonymousCannotReadOrWriteAdminFaq(): void
    {
        $this->json('GET', '/api/admin/faq', []);
        self::assertResponseStatusCodeSame(401);

        $this->json('PUT', '/api/admin/faq', ['categories' => []]);
        self::assertResponseStatusCodeSame(401);

        $this->json('POST', '/api/admin/faq/categories', ['title' => 'x', 'slug' => 'x']);
        self::assertResponseStatusCodeSame(401);

        $this->json('PATCH', '/api/admin/faq/categories/1', ['title' => 'x']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testAnonymousCannotUploadBannerImage(): void
    {
        $this->client->request('POST', '/api/admin/banner/upload-image');

        self::assertResponseStatusCodeSame(401);
    }

    public function testPublicFaqIsReadableWithoutLogin(): void
    {
        $this->json('GET', '/api/faq', []);

        self::assertResponseIsSuccessful();
    }

    public function testPublicSettingsExposeOnlyGlobalAndFrontEndScopes(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ([
            ['a_global', Setting::SCOPE_GLOBAL],
            ['b_front', Setting::SCOPE_FRONT_END],
            ['c_user', Setting::SCOPE_USER],
            ['d_admin', Setting::SCOPE_ADMIN],
        ] as [$name, $scope]) {
            $em->persist((new Setting())->setName($name)->setValue('v')->setScope($scope));
        }
        $em->flush();

        $this->json('GET', '/api/public-settings', []);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(['global' => ['a_global' => 'v'], 'front_end' => ['b_front' => 'v']], $data);
    }

    public function testAnonymousCannotReadUserScopedSettings(): void
    {
        $this->json('GET', '/api/user-settings', []);

        self::assertResponseStatusCodeSame(401);
    }

    public function testSettingsCacheIsInvalidatedWhenASettingChanges(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $service = static::getContainer()->get(SettingService::class);

        $setting = (new Setting())->setName('theme')->setValue('light')->setScope(Setting::SCOPE_FRONT_END);
        $em->persist($setting);
        $em->flush();
        self::assertSame(['front_end' => ['theme' => 'light']], $service->getByScopes([Setting::SCOPE_FRONT_END]));

        $setting->setValue('dark');
        $em->flush();
        self::assertSame(['front_end' => ['theme' => 'dark']], $service->getByScopes([Setting::SCOPE_FRONT_END]));
        self::assertSame('dark', $service->getSetting('theme'));

        $em->remove($setting);
        $em->flush();
        self::assertSame(['front_end' => []], $service->getByScopes([Setting::SCOPE_FRONT_END]));
    }

    public function testCurrencyProviderFollowsSettingChangesThroughTheCache(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $provider = static::getContainer()->get(CurrencyProvider::class);

        $currency = (new Setting())->setName(Setting::BASE_CURRENCY)->setValue('usd')->setScope(Setting::SCOPE_GLOBAL);
        $em->persist($currency);
        $em->flush();
        self::assertSame('usd', $provider->getCurrency());

        $currency->setValue('gnf');
        $em->flush();
        $provider->reset();
        self::assertSame('gnf', $provider->getCurrency());
    }

    private function createUser(string $email): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail($email)->setPassword('x')->setFirstName('a')->setLastName('b');
        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function testCustomerBasketRequiresLoginAndOwnership(): void
    {
        $owner = $this->createUser('owner@test.com');
        $other = $this->createUser('other@test.com');
        $basket = (new Basket())->setUid('owner-basket');
        $basket->setUser($owner);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($basket);
        $em->flush();

        $this->json('GET', '/api/customer/'.$owner->getId().'/baskets', []);
        self::assertResponseStatusCodeSame(401);

        $this->client->loginUser($other);
        $this->json('GET', '/api/customer/'.$owner->getId().'/baskets', []);
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($owner);
        $this->json('GET', '/api/customer/'.$owner->getId().'/baskets', []);
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('owner-basket', $data[0]['uid']);
        self::assertSame(0.0, (float) $data[0]['total']);
    }

    public function testBasketByUidIsPublicOnlyForGuestBaskets(): void
    {
        $owner = $this->createUser('owner@test.com');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $userBasket = (new Basket())->setUid('user-basket');
        $userBasket->setUser($owner);
        $em->persist($userBasket);
        $em->persist((new Basket())->setUid('guest-basket'));
        $em->flush();

        $this->json('GET', '/api/basket/guest-basket', []);
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('guest-basket', $data[0]['uid']);
        self::assertArrayHasKey('total', $data[0]);

        $this->json('GET', '/api/basket/user-basket', []);
        self::assertResponseStatusCodeSame(401);

        $this->client->loginUser($this->createUser('other@test.com'));
        $this->json('GET', '/api/basket/user-basket', []);
        self::assertResponseStatusCodeSame(403);
    }
}
