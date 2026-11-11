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

    public function testProductPurchasePricesAreNotExposedToTheStorefront(): void
    {
        $factory = static::getContainer()->get(ResourceMetadataCollectionFactoryInterface::class);
        $resource = $factory->create(Product::class)[0];

        $contexts = [['resource', $resource->getNormalizationContext()]];
        foreach ($resource->getOperations() as $name => $operation) {
            $contexts[] = [$name, $operation->getNormalizationContext()];
        }

        foreach ($contexts as [$name, $context]) {
            $groups = $context['groups'] ?? [];
            if (!in_array('product:read:admin', $groups, true)) {
                continue;
            }
            $operation = $resource->getOperations()[$name] ?? null;
            self::assertStringContainsString(
                'ROLE_ADMIN',
                (string) $operation?->getSecurity(),
                sprintf('Operation "%s" exposes product:read:admin (usdPrice, fullPrice, latestPrice) and must require ROLE_ADMIN', $name),
            );
        }

        $storefrontGroups = ['product:read', 'product:read:details', 'order:read', 'wishlist:read', 'basket:read'];
        $product = (new Product())->setUsdPrice(100.0)->setFullPrice(130.0)->setLatestPrice(138.0);
        $data = static::getContainer()->get('serializer')->normalize($product, null, ['groups' => $storefrontGroups]);

        foreach (['usdPrice', 'fullPrice', 'latestPrice'] as $field) {
            self::assertArrayNotHasKey($field, $data, sprintf('"%s" must not be readable from the storefront', $field));
        }
        self::assertArrayHasKey('lastPrice', $data);
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

    public function testAnonymousCannotTriggerGeminiContentGeneration(): void
    {
        // These endpoints each trigger a paid Gemini AI call, so they must be
        // ROLE_ADMIN only (admin product-publishing tooling), never public.
        $this->json('POST', '/api/gemini/generate-product-content', ['title' => 'x']);
        self::assertResponseStatusCodeSame(401);

        $this->json('POST', '/api/gemini/generate-product-content-from-title', ['title' => 'x']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testAnonymousCannotListPaymentMethods(): void
    {
        $this->json('GET', '/api/payment-methods', []);
        self::assertResponseStatusCodeSame(401);

        $this->json('GET', '/api/payment-methods/1', []);
        self::assertResponseStatusCodeSame(401);
    }

    public function testAnonymousCannotSetDefaultPaymentMethod(): void
    {
        // Making a payment method the default must require authentication. Use a real
        // id so the route is reached (a missing id is rejected by the entity resolver
        // before the security check), proving the 401 comes from the access rule.
        $owner = $this->createUser('owner@test.com');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $method = (new \App\Entity\PaymentMethod())->setMethodType('card')->setCardLast4('4081');
        $method->setCustomer($owner);
        $em->persist($method);
        $em->flush();

        $this->json('PATCH', '/api/payment-methods/'.$method->getId().'/default', []);
        self::assertResponseStatusCodeSame(401);
    }

    public function testAnonymousCannotDeletePaymentMethod(): void
    {
        // Deleting a payment method must require authentication. Use a real id so the
        // route is reached (a missing id is rejected by the entity resolver before the
        // security check), proving the 401 comes from the access rule.
        $owner = $this->createUser('owner@test.com');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $method = (new \App\Entity\PaymentMethod())->setMethodType('card')->setCardLast4('4081');
        $method->setCustomer($owner);
        $em->persist($method);
        $em->flush();

        $this->json('DELETE', '/api/payment-methods/'.$method->getId(), []);
        self::assertResponseStatusCodeSame(401);
    }

    public function testAnonymousCannotEditPaymentMethodMobileMoneyNumber(): void
    {
        // Editing the mobile money number must require authentication. Use a real id
        // so the route is reached (a missing id is rejected by the entity resolver
        // before the security check), proving the 401 comes from the access rule.
        $owner = $this->createUser('owner@test.com');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $method = (new \App\Entity\PaymentMethod())->setMethodType('mobile_money')->setMobileMoneyNumber('620000000');
        $method->setCustomer($owner);
        $em->persist($method);
        $em->flush();

        $this->json('PATCH', '/api/payment-methods/'.$method->getId().'/mobile-money-number', ['mobileMoneyNumber' => '610000000']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testCustomerCannotEditAnotherCustomersMobileMoneyNumber(): void
    {
        $owner = $this->createUser('owner@test.com');
        $other = $this->createUser('other@test.com');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $method = (new \App\Entity\PaymentMethod())->setMethodType('mobile_money')->setMobileMoneyNumber('620000000');
        $method->setCustomer($owner);
        $em->persist($method);
        $em->flush();

        // A foreign owner is reported as 404 (not 403) so ids are not leaked, and the
        // number must be unchanged.
        $this->client->loginUser($other);
        $this->json('PATCH', '/api/payment-methods/'.$method->getId().'/mobile-money-number', ['mobileMoneyNumber' => '610000000']);
        self::assertResponseStatusCodeSame(404);

        $em->refresh($method);
        self::assertSame('620000000', $method->getMobileMoneyNumber());
    }

    public function testOwnerEditsOnlyTheMobileMoneyNumber(): void
    {
        $owner = $this->createUser('owner@test.com');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $method = (new \App\Entity\PaymentMethod())->setMethodType('mobile_money')->setMobileMoneyNumber('620000000')->setIsDefault(true);
        $method->setCustomer($owner);
        $em->persist($method);
        $em->flush();

        $this->client->loginUser($owner);
        $this->json('PATCH', '/api/payment-methods/'.$method->getId().'/mobile-money-number', ['mobileMoneyNumber' => '+224610000000']);
        self::assertResponseIsSuccessful();

        $em->refresh($method);
        self::assertSame('+224610000000', $method->getMobileMoneyNumber());
        self::assertSame('mobile_money', $method->getMethodType(), 'The method type must not change.');
        self::assertTrue($method->isDefault(), 'The default flag must not change.');
        self::assertSame($owner, $method->getCustomer(), 'The owner must not change.');
    }

    public function testEditRejectsInvalidMobileMoneyNumber(): void
    {
        $owner = $this->createUser('owner@test.com');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $method = (new \App\Entity\PaymentMethod())->setMethodType('mobile_money')->setMobileMoneyNumber('620000000');
        $method->setCustomer($owner);
        $em->persist($method);
        $em->flush();

        $this->client->loginUser($owner);
        $this->json('PATCH', '/api/payment-methods/'.$method->getId().'/mobile-money-number', ['mobileMoneyNumber' => 'not-a-number']);
        self::assertResponseStatusCodeSame(422);

        $em->refresh($method);
        self::assertSame('620000000', $method->getMobileMoneyNumber(), 'An invalid number must leave the stored value untouched.');
    }

    public function testEditRejectsNonMobileMoneyMethod(): void
    {
        $owner = $this->createUser('owner@test.com');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        // A card has no mobile money number, so there is nothing to edit here.
        $method = (new \App\Entity\PaymentMethod())->setMethodType('card')->setCardLast4('4081');
        $method->setCustomer($owner);
        $em->persist($method);
        $em->flush();

        $this->client->loginUser($owner);
        $this->json('PATCH', '/api/payment-methods/'.$method->getId().'/mobile-money-number', ['mobileMoneyNumber' => '610000000']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testDeletePromotesAnotherMethodToDefault(): void
    {
        $owner = $this->createUser('owner@test.com');
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $default = (new \App\Entity\PaymentMethod())->setMethodType('card')->setCardLast4('1111')->setIsDefault(true);
        $default->setCustomer($owner);
        $other = (new \App\Entity\PaymentMethod())->setMethodType('card')->setCardLast4('2222');
        $other->setCustomer($owner);
        $em->persist($default);
        $em->persist($other);
        $em->flush();

        static::getContainer()->get(\App\Service\PaymentMethodService::class)->delete($default);

        $em->refresh($other);
        self::assertTrue($other->isDefault(), 'Deleting the default must promote a remaining method to default.');
        self::assertCount(1, $em->getRepository(\App\Entity\PaymentMethod::class)->findBy(['customer' => $owner]));
    }

    public function testSetAsDefaultMovesTheDefaultFlagToOneMethodPerCustomer(): void
    {
        $owner = $this->createUser('owner@test.com');
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $first = (new \App\Entity\PaymentMethod())->setMethodType('card')->setCardLast4('1111')->setIsDefault(true);
        $first->setCustomer($owner);
        $second = (new \App\Entity\PaymentMethod())->setMethodType('card')->setCardLast4('2222');
        $second->setCustomer($owner);
        $em->persist($first);
        $em->persist($second);
        $em->flush();

        static::getContainer()->get(\App\Service\PaymentMethodService::class)->setAsDefault($second);

        $em->refresh($first);
        $em->refresh($second);
        self::assertTrue($second->isDefault());
        self::assertFalse($first->isDefault(), 'The previous default must be cleared so only one default remains.');
    }

    public function testPaymentMethodReadGroupHidesSensitiveData(): void
    {
        // The stored Paystack authorization code is a reusable charge token and
        // must never be serialized to the API, in any payment_method read context.
        $method = (new \App\Entity\PaymentMethod())
            ->setAuthorizationCode('AUTH_secret_token')
            ->setCardLast4('4081')
            ->setCardBrand('visa')
            ->setMethodType('card');

        // Give the entity an id so API Platform can generate its IRI during normalization.
        $idProperty = new \ReflectionProperty(\App\Entity\PaymentMethod::class, 'id');
        $idProperty->setValue($method, 1);

        $data = static::getContainer()->get('serializer')->normalize($method, null, ['groups' => ['payment_method:read']]);

        self::assertArrayNotHasKey('authorizationCode', $data);
        self::assertArrayNotHasKey('customer', $data);
        self::assertArrayHasKey('cardLast4', $data);
        self::assertArrayHasKey('cardBrand', $data);
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
