<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Product;
use App\Exception\ProductEditException;
use App\Repository\CategoryRepository;
use App\Repository\SubcategoryRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Throwable;

use function array_key_exists;
use function count;
use function is_array;
use function is_string;

use const FILTER_VALIDATE_BOOLEAN;
use const FILTER_VALIDATE_URL;

class ProductService
{
    /**
     * Markup range (in percent) applied on the full price, by full price threshold.
     * The higher the full price, the closer the markup is to 1%.
     */
    private const LATEST_PRICE_MARKUP_TIERS = [
        50 => [5.0, 10.0],
        250 => [3.0, 6.0],
        750 => [2.0, 3.0],
    ];
    private const LATEST_PRICE_MARKUP_HIGH_PRICE = [1.0, 1.5];

    public function __construct(
        private EntityManagerInterface $entityManagerInterface,
        private SluggerInterface $sluggerInterface,
        private CategoryRepository $categoryRepository,
        private SubcategoryRepository $subcategoryRepository,
        private GcsUploaderService $gcsUploaderService,
        private PhotoService $photoService,
        private ValidatorInterface $validator,
        private LoggerInterface $logger,
    ) {
    }

    public function createProduct($product): Product
    {
        $product->setSlug($this->sluggerInterface->slug($product->getTitle()) . '-' . uniqid());

        $this->updateLatestPrice($product);

        $this->entityManagerInterface->persist($product);
        $this->entityManagerInterface->flush();

        return $product;
    }

    /**
     * Sets the latest price to a random 1% to 10% increase over the full price:
     * 5-10% for low prices, getting closer to 1% as the full price grows.
     */
    public function updateLatestPrice(Product $product): Product
    {
        $fullPrice = $product->getFullPrice();
        if (null === $fullPrice) {
            return $product;
        }

        [$minPercent, $maxPercent] = self::LATEST_PRICE_MARKUP_HIGH_PRICE;
        foreach (self::LATEST_PRICE_MARKUP_TIERS as $threshold => $range) {
            if ($fullPrice < $threshold) {
                [$minPercent, $maxPercent] = $range;
                break;
            }
        }

        $percent = random_int((int) ($minPercent * 100), (int) ($maxPercent * 100)) / 100;
        $product->setLatestPrice(round($fullPrice * (1 + $percent / 100), 2));

        return $product;
    }

    /**
     * Edits a product. Every field is optional: only the keys present in $data are updated.
     *
     *  - images:   the photos to keep, as [{url}]. Existing photos missing from the list are deleted
     *              (database and bucket). A "data:" URI entry is uploaded as a new photo and a plain
     *              http(s) URL is saved as is. Omit the key to leave the photos untouched.
     *  - variants: the full variants list. An "image" holding a "data:" URI is uploaded; bucket images of
     *              variants that were removed or whose image changed are deleted.
     *
     * @param array<string, mixed> $data
     * @param UploadedFile[]       $newImageFiles files uploaded as new photos
     *
     * @throws ProductEditException when the data is invalid; nothing is saved in that case
     */
    public function editProduct(Product $product, array $data, array $newImageFiles = []): Product
    {
        $this->applyEditableFields($product, $data);
        $this->assertValid($product);

        // Bucket files are only deleted once the database changes are saved.
        $candidatesForDeletion = [];

        if (array_key_exists('variants', $data)) {
            $candidatesForDeletion = $this->syncVariants($product, $data['variants']);
        }
        if (array_key_exists('images', $data)) {
            $candidatesForDeletion = array_merge($candidatesForDeletion, $this->syncPhotos($product, $data['images']));
        }
        foreach ($newImageFiles as $file) {
            $this->photoService->createPhotoForProduct($product, $this->gcsUploaderService->upload($file));
        }

        $this->entityManagerInterface->flush();

        $this->deleteUnusedBucketFiles($product, $candidatesForDeletion);

        return $product;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyEditableFields(Product $product, array $data): void
    {
        if (array_key_exists('category', $data)) {
            $category = $this->categoryRepository->find($data['category']);
            if (!$category) {
                throw new ProductEditException('Category not found', 404);
            }
            $product->setCategory($category);
        }
        if (array_key_exists('subCategory', $data)) {
            $subcategory = empty($data['subCategory']) ? null : $this->subcategoryRepository->find($data['subCategory']);
            if (!empty($data['subCategory']) && !$subcategory) {
                throw new ProductEditException('Subcategory not found', 404);
            }
            $product->setSubcategory($subcategory);
        }

        if (array_key_exists('title', $data)) {
            $product->setTitle(trim((string) $data['title']));
        }
        if (array_key_exists('brand', $data)) {
            $product->setBrand(trim((string) $data['brand']));
        }
        if (array_key_exists('productCondition', $data)) {
            $product->setProductCondition((string) $data['productCondition']);
        }
        // "seller" is the stored value; "supplierInfo" is what the publish form calls it.
        $seller = $data['seller'] ?? null;
        if (null === $seller || '' === $seller) {
            $seller = $data['supplierInfo'] ?? null;
        }
        if (null !== $seller && '' !== $seller) {
            $product->setSeller((string) $seller);
        }
        if (array_key_exists('scrappingUrl', $data)) {
            $product->setScrappingUrl($data['scrappingUrl'] ?: null);
        }
        if (array_key_exists('description', $data)) {
            $product->setDescription($data['description']);
        }
        if (array_key_exists('customerSays', $data)) {
            $product->setCustomerSays($data['customerSays']);
        }
        if (array_key_exists('returnPolicy', $data)) {
            $product->setReturnPolicy($data['returnPolicy']);
        }
        if (null !== ($data['usdPrice'] ?? null) && '' !== $data['usdPrice']) {
            $product->setUsdPrice((float) $data['usdPrice']);
        }
        if (null !== ($data['retailPrice'] ?? null) && '' !== $data['retailPrice']) {
            $newFullPrice = (float) $data['retailPrice'];
            if ($newFullPrice !== $product->getFullPrice()) {
                $product->setFullPrice($newFullPrice);
                $this->updateLatestPrice($product);
            }
        }
        if (array_key_exists('available', $data)) {
            $product->setIsAvailable(filter_var($data['available'], FILTER_VALIDATE_BOOLEAN));
        }
        if (array_key_exists('acceptReturn', $data)) {
            $product->setAcceptReturn(filter_var($data['acceptReturn'], FILTER_VALIDATE_BOOLEAN));
        }
        if (array_key_exists('primeDelivery', $data)) {
            $product->setPrimeDelivery(filter_var($data['primeDelivery'], FILTER_VALIDATE_BOOLEAN));
        }
        if (array_key_exists('sellerChoice', $data)) {
            $product->setSellerChoice(filter_var($data['sellerChoice'], FILTER_VALIDATE_BOOLEAN));
        }
        if (array_key_exists('overallRating', $data)) {
            $product->setOverallRating(null === $data['overallRating'] || '' === $data['overallRating'] ? null : (float) $data['overallRating']);
        }
        $ratingsCount = $data['overallRatingsCounts'] ?? $data['overallRatingCount'] ?? null;
        if (null !== $ratingsCount && '' !== $ratingsCount) {
            $product->setOverallRatingsCounts((int) $ratingsCount);
        }
        if (array_key_exists('details', $data)) {
            $product->setDetails(is_array($data['details']) ? array_values($data['details']) : null);
        }
        if (array_key_exists('features', $data)) {
            $product->setFeatures(is_array($data['features']) ? array_values($data['features']) : null);
        }
        $product->setUpdatedAt(new DateTime());
    }

    private function assertValid(Product $product): void
    {
        $violations = $this->validator->validate($product);
        if (0 === count($violations)) {
            return;
        }

        $errors = [];
        foreach ($violations as $violation) {
            $errors[$violation->getPropertyPath()] = (string) $violation->getMessage();
        }

        throw new ProductEditException('Validation failed', 422, $errors);
    }

    /**
     * Replaces the variants and returns the old bucket images that may no longer be needed.
     *
     * @return string[]
     */
    private function syncVariants(Product $product, mixed $newVariants): array
    {
        $candidates = [];
        foreach ($product->getVariants() ?? [] as $oldVariant) {
            if (!empty($oldVariant['image'])) {
                $candidates[] = $oldVariant['image'];
            }
        }

        $variants = is_array($newVariants) ? array_values($newVariants) : [];
        foreach ($variants as &$variant) {
            if (!is_array($variant)) {
                throw new ProductEditException('Invalid variants', 400);
            }
            if (!empty($variant['image']) && str_starts_with($variant['image'], 'data:')) {
                $variant['image'] = $this->uploadDataUri($variant['image'], $variant['title'] ?? 'variant');
            }
        }
        unset($variant);
        $product->setVariants($variants);

        return $candidates;
    }

    /**
     * Makes the product photos match the given list and returns the removed photo URLs.
     *
     * @return string[]
     */
    private function syncPhotos(Product $product, mixed $images): array
    {
        $photosByUrl = [];
        foreach ($product->getPhotos() as $photo) {
            $photosByUrl[$photo->getUrl()] = $photo;
        }

        $kept = [];
        $newUrls = [];
        $coverUrl = null;
        foreach (is_array($images) ? $images : [] as $image) {
            $url = is_array($image) ? ($image['url'] ?? null) : $image;
            if (!is_string($url) || '' === $url) {
                continue;
            }
            if (str_starts_with($url, 'data:')) {
                $url = $this->uploadDataUri($url, $product->getSlug() ?? 'product');
                $newUrls[] = $url;
            } elseif (isset($photosByUrl[$url])) {
                $kept[$url] = true;
            } elseif (filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $url)) {
                $newUrls[] = $url;
            } else {
                throw new ProductEditException('Invalid image URL', 400);
            }
            // The first image of the list is the cover.
            $coverUrl ??= $url;
        }

        $removed = [];
        foreach ($photosByUrl as $url => $photo) {
            if (!isset($kept[$url])) {
                $product->removePhoto($photo);
                $this->entityManagerInterface->remove($photo);
                $removed[] = $url;
            }
        }
        foreach ($newUrls as $url) {
            $this->photoService->createPhotoForProduct($product, $url);
        }
        if (null !== $coverUrl) {
            foreach ($product->getPhotos() as $photo) {
                $photo->setIsCover($photo->getUrl() === $coverUrl);
            }
        }

        return $removed;
    }

    private function uploadDataUri(string $dataUri, string $baseName): string
    {
        try {
            return $this->gcsUploaderService->uploadDataUri($dataUri, $baseName);
        } catch (InvalidArgumentException $e) {
            throw new ProductEditException($e->getMessage(), 400, previous: $e);
        }
    }

    /**
     * Deletes from the bucket the given URLs, unless the product still uses them.
     * A failed delete is logged: an orphan file is better than failing a saved edit.
     *
     * @param string[] $candidates
     */
    private function deleteUnusedBucketFiles(Product $product, array $candidates): void
    {
        $stillUsed = [];
        foreach ($product->getPhotos() as $photo) {
            $stillUsed[] = $photo->getUrl();
        }
        foreach ($product->getVariants() ?? [] as $variant) {
            if (!empty($variant['image'])) {
                $stillUsed[] = $variant['image'];
            }
        }

        foreach (array_unique(array_diff($candidates, $stillUsed)) as $url) {
            try {
                $this->gcsUploaderService->deleteByUrl($url);
            } catch (Throwable $e) {
                $this->logger->error('Could not delete product image from the bucket.', ['url' => $url, 'exception' => $e]);
            }
        }
    }
}
