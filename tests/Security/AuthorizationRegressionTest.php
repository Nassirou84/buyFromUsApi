<?php

declare(strict_types=1);

namespace App\Tests\Security;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use App\Entity\Product;
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
}
