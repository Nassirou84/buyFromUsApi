<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\Photo;
use App\Entity\Product;
use App\Entity\Subcategory;
use App\Entity\User;
use App\Service\GcsUploaderService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use const JSON_THROW_ON_ERROR;

final class ProductEditTest extends WebTestCase
{
    private const UID = 'P-d3f13f';
    private const BUCKET = 'https://storage.googleapis.com/flotboutique/';
    private const KEPT_PHOTO = self::BUCKET . '81lSBCqFGbL-AC-SX466-6ad3a22f04dd6.jpg';
    private const REMOVED_PHOTO = self::BUCKET . 'old-photo-removed.jpg';
    private const BUCKET_VARIANT_IMAGE = self::BUCKET . 'old-variant-black.jpg';
    private const EXTERNAL_VARIANT_IMAGE = 'https://m.media-amazon.com/images/I/variant-red.jpg';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var GcsUploaderService&Stub */
    private GcsUploaderService $gcs;

    /** @var list<string> */
    private array $deletedUrls = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $tool->dropDatabase();
        $tool->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $this->deletedUrls = [];
        $this->gcs = $this->createStub(GcsUploaderService::class);
        $this->gcs->method('deleteByUrl')->willReturnCallback(function (string $url): bool {
            $this->deletedUrls[] = $url;

            return true;
        });
        $this->gcs->method('uploadDataUri')->willReturn(self::BUCKET . 'uploaded-from-data-uri.png');
        $this->gcs->method('upload')->willReturn(self::BUCKET . 'uploaded-file.png');
        static::getContainer()->set(GcsUploaderService::class, $this->gcs);

        $this->seedCatalog();
    }

    private function seedCatalog(): void
    {
        $category = (new Category())->setTitle('Electronics');
        $this->em->persist($category);
        // Subcategory ids 1..7 so the payload's "subCategory": 7 exists.
        for ($i = 1; $i <= 7; ++$i) {
            $subcategory = (new Subcategory())
                ->setTitle('Sub ' . $i)
                ->setCategory($category)
                ->setIsActive(true)
                ->setMarkup(10);
            $this->em->persist($subcategory);
        }

        $product = (new Product())
            ->setUid(self::UID)
            ->setTitle('Old title')
            ->setBrand('OLDBRAND')
            ->setSeller('walmart')
            ->setSlug('old-title-abc123')
            ->setUsdPrice(50.0)
            ->setFullPrice(60.0)
            ->setLatestPrice(63.0)
            ->setIsAvailable(false)
            ->setProductCondition(Product::PRODUCT_CONDITION_NEW)
            ->setCategory($category)
            ->setVariants([
                ['title' => 'Black', 'image' => self::BUCKET_VARIANT_IMAGE],
                ['title' => 'Red', 'image' => self::EXTERNAL_VARIANT_IMAGE],
            ]);
        $this->em->persist($product);
        foreach ([self::KEPT_PHOTO, self::REMOVED_PHOTO] as $url) {
            $photo = (new Photo())->setUrl($url);
            $product->addPhoto($photo);
            $this->em->persist($photo);
        }

        $this->em->flush();
        $this->em->clear();
    }

    private function loginAs(array $roles): void
    {
        $user = (new User())
            ->setEmail('tester@test.com')
            ->setFirstName('Test')
            ->setLastName('User')
            ->setRoles($roles)
            ->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user, 'api');
    }

    private function edit(array $body, string $uid = self::UID): array
    {
        $this->client->request('POST', '/api/product/' . $uid . '/edit', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode($body, JSON_THROW_ON_ERROR));

        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    private function payload(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/../Fixtures/product_edit_payload.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function product(): Product
    {
        $this->em->clear();

        return $this->em->getRepository(Product::class)->findOneBy(['uid' => self::UID]);
    }

    /** @return list<string> */
    private function photoUrls(Product $product): array
    {
        $urls = $product->getPhotos()->map(static fn (Photo $p): string => $p->getUrl())->toArray();
        sort($urls);

        return $urls;
    }

    /** @return list<string> */
    private function coverUrls(Product $product): array
    {
        return array_values($product->getPhotos()
            ->filter(static fn (Photo $p): bool => true === $p->isCover())
            ->map(static fn (Photo $p): string => $p->getUrl())
            ->toArray());
    }

    public function testAnonymousIsRejected(): void
    {
        $this->edit(['title' => 'x']);

        self::assertResponseStatusCodeSame(401);
        self::assertSame('Old title', $this->product()->getTitle());
    }

    public function testNonAdminIsForbidden(): void
    {
        $this->loginAs(['ROLE_USER']);
        $this->edit(['title' => 'x']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Old title', $this->product()->getTitle());
    }

    public function testUnknownProductReturns404(): void
    {
        $this->loginAs(['ROLE_ADMIN']);
        $this->edit(['title' => 'x'], 'P-nope');

        self::assertResponseStatusCodeSame(404);
    }

    public function testProvidedPayloadUpdatesTheProduct(): void
    {
        $this->loginAs(['ROLE_ADMIN']);
        $payload = $this->payload();

        $response = $this->edit($payload);

        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());

        $product = $this->product();
        self::assertSame($payload['title'], $product->getTitle());
        self::assertSame('SOONBOX', $product->getBrand());
        self::assertSame('used', $product->getProductCondition());
        self::assertSame('amazon', $product->getSeller());
        self::assertSame($payload['scrappingUrl'], $product->getScrappingUrl());
        self::assertSame(100.0, $product->getUsdPrice());
        self::assertSame(145.0, $product->getFullPrice());
        self::assertSame($payload['description'], $product->getDescription());
        self::assertSame($payload['returnPolicy'], $product->getReturnPolicy());
        self::assertTrue($product->isAvailable());
        self::assertTrue($product->isAcceptReturn());
        self::assertTrue($product->isSellerChoice());
        self::assertTrue($product->isPrimeDelivery());
        self::assertSame(4.6, $product->getOverallRating());
        self::assertSame(0, $product->getOverallRatingsCounts());
        self::assertSame(1, $product->getCategory()->getId());
        self::assertSame(7, $product->getSubcategory()->getId());
        self::assertSame($payload['details'], $product->getDetails());
        self::assertSame($payload['features'], $product->getFeatures());
        self::assertSame('old-title-abc123', $product->getSlug(), 'The slug must stay stable.');

        // Full price changed 60 -> 145, so the latest price is recomputed (3-6% tier for 50-250).
        self::assertGreaterThanOrEqual(149.35, $product->getLatestPrice());
        self::assertLessThanOrEqual(153.70, $product->getLatestPrice());

        // Photos: only the kept one remains and the removed one is deleted from the bucket.
        self::assertSame([self::KEPT_PHOTO], $this->photoUrls($product));
        // Variants were emptied: the bucket variant image goes, the external one is not ours to delete
        // (the real service ignores it; the mock records every call so assert on what was requested).
        self::assertEqualsCanonicalizing(
            [self::REMOVED_PHOTO, self::BUCKET_VARIANT_IMAGE, self::EXTERNAL_VARIANT_IMAGE],
            $this->deletedUrls,
        );
        self::assertNotContains(self::KEPT_PHOTO, $this->deletedUrls);
        self::assertSame([], $product->getVariants());

        // Response is the refreshed product with admin fields.
        self::assertSame(self::UID, $response['uid']);
        self::assertSame($payload['title'], $response['title']);
        self::assertSame(100, $response['usdPrice']);
        self::assertSame([self::KEPT_PHOTO], array_column($response['photos'], 'url'));
    }

    public function testOmittedKeysLeaveEverythingElseUntouched(): void
    {
        $this->loginAs(['ROLE_ADMIN']);

        $this->edit(['title' => 'Only the title changed']);

        self::assertResponseStatusCodeSame(200);
        $product = $this->product();
        self::assertSame('Only the title changed', $product->getTitle());
        self::assertSame('OLDBRAND', $product->getBrand());
        self::assertSame(60.0, $product->getFullPrice());
        self::assertSame(63.0, $product->getLatestPrice());
        self::assertSame([self::KEPT_PHOTO, self::REMOVED_PHOTO], $this->photoUrls($product));
        self::assertCount(2, $product->getVariants());
        self::assertSame([], $this->deletedUrls);
    }

    public function testFirstImageOfTheListBecomesTheCover(): void
    {
        $this->loginAs(['ROLE_ADMIN']);

        // No new photo: the list is only reordered so that another photo comes first.
        $this->edit(['images' => [['url' => self::REMOVED_PHOTO], ['url' => self::KEPT_PHOTO]]]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([self::REMOVED_PHOTO], $this->coverUrls($this->product()));
    }

    public function testCoverMovesWhenTheCurrentCoverIsNoLongerFirst(): void
    {
        $this->loginAs(['ROLE_ADMIN']);
        $photos = $this->em->getRepository(Product::class)->findOneBy(['uid' => self::UID])->getPhotos();
        foreach ($photos as $photo) {
            $photo->setIsCover(self::REMOVED_PHOTO === $photo->getUrl());
        }
        $this->em->flush();

        $this->edit(['images' => [['url' => self::KEPT_PHOTO], ['url' => self::REMOVED_PHOTO]]]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([self::KEPT_PHOTO], $this->coverUrls($this->product()));
    }

    public function testNewImagesAreUploadedAndVariantImagesReplaced(): void
    {
        $this->loginAs(['ROLE_ADMIN']);

        $this->edit([
            'images' => [
                ['url' => self::KEPT_PHOTO],
                ['url' => 'data:image/png;base64,iVBORw0KGgo='],
            ],
            'variants' => [
                ['title' => 'Black', 'image' => 'data:image/png;base64,iVBORw0KGgo='],
                ['title' => 'Red', 'image' => self::EXTERNAL_VARIANT_IMAGE],
            ],
        ]);

        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());
        $product = $this->product();
        self::assertSame(
            [self::KEPT_PHOTO, self::BUCKET . 'uploaded-from-data-uri.png'],
            $this->photoUrls($product),
        );
        self::assertSame(self::BUCKET . 'uploaded-from-data-uri.png', $product->getVariants()[0]['image']);
        self::assertSame(self::EXTERNAL_VARIANT_IMAGE, $product->getVariants()[1]['image']);
        // The replaced photo and the replaced variant image are gone from the bucket; the kept
        // external variant image is still referenced so it is never deleted.
        self::assertEqualsCanonicalizing([self::REMOVED_PHOTO, self::BUCKET_VARIANT_IMAGE], $this->deletedUrls);
    }

    public function testMultipartUploadAddsNewImageFiles(): void
    {
        $this->loginAs(['ROLE_ADMIN']);
        $tmp = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true));

        $this->client->request('POST', '/api/product/' . self::UID . '/edit', [
            'title' => 'Multipart title',
            'available' => 'true',
            'images' => json_encode([['url' => self::KEPT_PHOTO], ['url' => self::REMOVED_PHOTO]]),
        ], [
            'newImages' => [new UploadedFile($tmp, 'photo.png', 'image/png', null, true)],
        ], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());
        $product = $this->product();
        self::assertSame('Multipart title', $product->getTitle());
        self::assertTrue($product->isAvailable());
        self::assertSame(
            [self::KEPT_PHOTO, self::REMOVED_PHOTO, self::BUCKET . 'uploaded-file.png'],
            $this->photoUrls($product),
        );
        self::assertSame([], $this->deletedUrls);
    }

    public function testInvalidDataIsRejectedWithoutSideEffects(): void
    {
        $this->loginAs(['ROLE_ADMIN']);

        $response = $this->edit([
            'title' => '',
            'images' => [],
            'variants' => [],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('title', $response['violations']);
        $product = $this->product();
        self::assertSame('Old title', $product->getTitle());
        self::assertCount(2, $product->getPhotos());
        self::assertCount(2, $product->getVariants());
        self::assertSame([], $this->deletedUrls);
    }

    public function testUnknownCategoryReturns404(): void
    {
        $this->loginAs(['ROLE_ADMIN']);

        $this->edit(['category' => 999]);

        self::assertResponseStatusCodeSame(404);
    }
}
