<?php

declare(strict_types=1);

namespace App\Tests\Filter;

use App\Entity\Category;
use App\Entity\Product;
use App\Service\CurrencyProvider;
use App\Service\PriceCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProductListingFiltersTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Category $category;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        // Expected prices must use the same rate/tax as the filter, not PriceCalculator's fallback defaults.
        PriceCalculator::useCurrencyProvider(static::getContainer()->get(CurrencyProvider::class));
        $tool = new SchemaTool($this->em);
        $tool->dropDatabase();
        $tool->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $this->category = (new Category())->setTitle('Gadgets')->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($this->category);
    }

    /**
     * @param array<string, string> $query
     *
     * @return list<string> product slugs, in response order
     */
    private function listing(array $query): array
    {
        $this->em->flush();
        $this->client->request('GET', '/api/products?' . http_build_query($query), [], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();

        return array_map(static fn (array $product): string => $product['slug'], json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    private function addProduct(string $slug, float $usdPrice, bool $topPick = false, bool $fastDelivery = false, string $addedAgo = '-1 day'): Product
    {
        $product = (new Product())
            ->setTitle($slug)
            ->setSlug($slug)
            ->setUid(substr(md5($slug), 0, 10))
            ->setSeller('amazon')
            ->setBrand('acme')
            ->setUsdPrice($usdPrice)
            ->setFullPrice($usdPrice)
            ->setIsAvailable(true)
            ->setProductCondition(Product::PRODUCT_CONDITION_NEW)
            ->setCategory($this->category)
            ->setSellerChoice($topPick)
            ->setPrimeDelivery($fastDelivery)
            ->setCreatedAt(new \DateTime($addedAgo));
        $this->em->persist($product);

        return $product;
    }

    public function testPriceRangeMatchesTheDisplayedPriceAcrossMarkupTiers(): void
    {
        // 49.99 and 50 sit on both sides of a markup tier boundary, so the displayed price is not monotonic in USD.
        $usdPrices = ['cheap' => 10.0, 'edge-below' => 49.99, 'edge-at' => 50.0, 'mid' => 120.0, 'high' => 400.0, 'top' => 900.0];
        foreach ($usdPrices as $slug => $usd) {
            $this->addProduct($slug, $usd);
        }
        $this->em->flush();

        $displayed = array_map(static fn (float $usd): float => PriceCalculator::calculate($usd), $usdPrices);
        $min = $displayed['mid'];
        $max = $displayed['high'];

        $expected = array_keys(array_filter($displayed, static fn (float $price): bool => $price >= $min && $price <= $max));
        $actual = $this->listing(['minPrice' => (string) $min, 'maxPrice' => (string) $max]);
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);

        $expectedMin = array_keys(array_filter($displayed, static fn (float $price): bool => $price >= $displayed['top']));
        self::assertSame($expectedMin, $this->listing(['minPrice' => (string) $displayed['top']]));

        $expectedMax = array_keys(array_filter($displayed, static fn (float $price): bool => $price <= $displayed['cheap']));
        self::assertSame($expectedMax, $this->listing(['maxPrice' => (string) $displayed['cheap']]));
    }

    public function testInvalidPriceBoundsAreIgnored(): void
    {
        $this->addProduct('a', 10.0);
        $this->addProduct('b', 900.0);

        self::assertCount(2, $this->listing(['minPrice' => 'abc', 'maxPrice' => '-5']));
    }

    public function testTopPickAndFastDeliveryFilters(): void
    {
        $this->addProduct('plain', 10.0);
        $this->addProduct('top', 10.0, topPick: true);
        $this->addProduct('fast', 10.0, fastDelivery: true);
        $this->addProduct('both', 10.0, topPick: true, fastDelivery: true);

        $topPicks = $this->listing(['sellerChoice' => 'true']);
        sort($topPicks);
        self::assertSame(['both', 'top'], $topPicks);

        $fast = $this->listing(['primeDelivery' => 'true']);
        sort($fast);
        self::assertSame(['both', 'fast'], $fast);

        self::assertSame(['both'], $this->listing(['sellerChoice' => 'true', 'primeDelivery' => 'true']));
    }

    public function testNewArrivalsOnlyShowsRecentProductsNewestFirst(): void
    {
        $this->addProduct('old', 10.0, addedAgo: '-90 days');
        $this->addProduct('week', 10.0, addedAgo: '-7 days');
        $this->addProduct('today', 10.0, addedAgo: '-1 hour');

        self::assertSame(['today', 'week'], $this->listing(['newArrivals' => 'true']));
        self::assertCount(3, $this->listing(['newArrivals' => 'false']));
    }
}
