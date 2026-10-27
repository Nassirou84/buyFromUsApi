<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\Photo;
use App\Entity\Product;
use App\Service\ProductSearchSuggestionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProductSearchSuggestionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $tool->dropDatabase();
        $tool->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $category = (new Category())->setTitle('Electronics');
        $this->em->persist($category);
        foreach ([
            ['iPhone 15 Pro 256GB', 'Apple'],
            ['iPhone 15 Pro 256GB', 'Apple'], // same product from another seller
            ['Samsung Galaxy S24', 'Samsung'],
            ['Case for iPhone 15', 'Spigen'],
        ] as $i => [$title, $brand]) {
            $this->em->persist($product = (new Product())
                ->setUid('P-' . $i)
                ->setTitle($title)
                ->setBrand($brand)
                ->setSeller('amazon')
                ->setSlug('product-' . $i)
                ->setUsdPrice(10.0)
                ->setFullPrice(12.0)
                ->setIsAvailable(true)
                ->setProductCondition(Product::PRODUCT_CONDITION_NEW)
                ->setCategory($category));
            if (0 === $i) {
                $this->em->persist((new Photo())->setUrl('https://cdn.test/p0-extra.jpg')->setProduct($product));
                $this->em->persist((new Photo())->setUrl('https://cdn.test/p0-cover.jpg')->setProduct($product)->setIsCover(true));
            }
            if (3 === $i) {
                $this->em->persist((new Photo())->setUrl('https://cdn.test/p3-first.jpg')->setProduct($product));
            }
        }
        $this->em->flush();
        $this->em->clear();
    }

    private function suggest(string $query): array
    {
        $this->client->request('GET', '/api/product/search-suggestions', ['q' => $query]);
        self::assertResponseIsSuccessful();

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    public function testSuggestsKeysThenProductsWithoutDuplicates(): void
    {
        $data = $this->suggest('iph');

        self::assertSame('iph', $data['query']);
        self::assertSame([
            ['type' => 'product', 'title' => 'iPhone 15 Pro 256GB', 'slug' => 'product-0', 'productCondition' => 'new', 'coverImage' => 'https://cdn.test/p0-cover.jpg'],
            ['type' => 'product', 'title' => 'Case for iPhone 15', 'slug' => 'product-3', 'productCondition' => 'new', 'coverImage' => 'https://cdn.test/p3-first.jpg'],
        ], $data['suggestions']);

        self::assertSame([['type' => 'key', 'key' => 'Apple']], $this->suggest('APP')['suggestions']);
    }

    public function testShortOrUnknownQueriesReturnNothingAndTheEndpointIsPublic(): void
    {
        self::assertSame([], $this->suggest('i')['suggestions']);
        self::assertSame([], $this->suggest('zzzzzz')['suggestions']);
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('public'));
    }

    public function testResultsAreServedFromCache(): void
    {
        self::assertCount(2, $this->suggest('iphone')['suggestions']);

        $this->em->createQuery('DELETE FROM ' . Product::class . ' p')->execute();

        self::assertCount(2, static::getContainer()->get(ProductSearchSuggestionService::class)->suggest('IPhone'));
    }
}
