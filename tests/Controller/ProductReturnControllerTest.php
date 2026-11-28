<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProductReturnControllerTest extends WebTestCase
{
    public function testGetByUidRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/product-returns/uid/fake-uid');

        self::assertResponseStatusCodeSame(401);
    }
}