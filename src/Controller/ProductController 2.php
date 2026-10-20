<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Photo;
use App\Entity\Product;
use App\Message\NewProductAddedMessage;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use App\Repository\SubcategoryRepository;
use App\Service\GcsUploaderService;
use App\Service\PhotoService;
use App\Service\ProductService;
use App\Service\UniqUidGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('api/product')]
final class ProductController extends AbstractController
{
    #[Route('/publish-product', name: 'app_product_scrape', methods: ['POST'])]
    public function publishProduct(
        Request $request,
        EntityManagerInterface $entityManager,
        GcsUploaderService $gcsUploaderService,
        UniqUidGenerator $uniqUidGenerator,
        CategoryRepository $categoryRepository,
        SubcategoryRepository $subcategoryRepository,
        PhotoService $photoService,
        NormalizerInterface $normalizer,
        SluggerInterface $slugger,
        MessageBusInterface $messageBus,
        ProductService $productService,
    ): JsonResponse {
        // Implementation goes here
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }
        if (in_array('ROLE_ADMIN', $user->getRoles()) === false) {
            return new JsonResponse(['error' => 'Forbidden'], JsonResponse::HTTP_FORBIDDEN);
        }

        // data is being retrieved from a form submission
        $data = $request->request->all();

        $images = $request->files->get('images');

        $variants = json_decode($data['variants'] ?? '[]', true) ?: [];
        foreach ($variants as &$variant) {
            if (!empty($variant['image']) && str_starts_with($variant['image'], 'data:')) {
                try {
                    $variant['image'] = $gcsUploaderService->uploadDataUri(
                        $variant['image'],
                        $variant['title'] ?? 'variant'
                    );
                } catch (\InvalidArgumentException $e) {
                    return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
                }
            }
        }
        unset($variant);

        $productUid = $uniqUidGenerator->generateUniqueUid(Product::class);

        $category = $categoryRepository->find($data['category']);
        $subcategory = $subcategoryRepository->find($data['subCategory']);

        $product = new Product();
        $product->setUid($productUid);
        $product->setTitle($data['title'] ?? null);
        $product->setDescription($data['description'] ?? null);
        $product->setUsdPrice(isset($data['usdPrice']) ? (float) $data['usdPrice'] : null);
        $product->setSeller($data['supplierInfo'] ?? null);
        $product->setFullPrice(isset($data['retailPrice']) ? (float) $data['retailPrice'] : null);

        $product->setScrappingUrl($data['scrappingUrl'] ?? null);
        $product->setProductCondition($data['productCondition'] ?? null);
        $product->setCategory($category);
        $product->setSubcategory($subcategory);
        $product->setBrand($data['brand'] ?? null);
        $product->setUpdatedAt(new \DateTime());
        $product->setReturnPolicy($data['returnPolicy'] ?? null);
        $product->setAcceptReturn(isset($data['acceptReturn']) ? $data['acceptReturn'] == 'true' : false);
        $product->setPrimeDelivery(isset($data['primeDelivery']) ? $data['primeDelivery'] == 'true' : false);
        $product->setSellerChoice(isset($data['sellerChoice']) ? $data['sellerChoice'] == 'true' : false);
        $product->setIsAvailable(isset($data['available']) ? $data['available'] == 'true' : false);
        $product->setCustomerSays($data['customerSays'] ?? null);
        $product->setOverallRating(isset($data['overallRating']) ? (float) $data['overallRating'] : null);
        $product->setOverallRatingsCounts(isset($data['overallRatingCount']) ? (int) $data['overallRatingCount'] : null);
        $product->setDetails(json_decode($data['details'] ?? null, true));
        $product->setFeatures(json_decode($data['features'] ?? null, true));
        $product->setVariants($variants);
        $product->setSlug($slugger->slug($product->getTitle())->lower() . '-' . uniqid());
        $productService->updateLatestPrice($product);

        $entityManager->persist($product);
        $entityManager->flush();

        if (!empty($images)) {
            foreach ($images as $image) {
                $file = $gcsUploaderService->upload($image);
                $photoService->createPhotoForProduct($product, $file);
            }
        }
        $normalizedProduct = $normalizer->normalize($product, null, ['groups' => 'product:read']);

        if ($product->getScrappingUrl()) {
            $newProductAddedMessage = new NewProductAddedMessage($product->getUid());
            $messageBus->dispatch($newProductAddedMessage);
        }

        return new JsonResponse($normalizedProduct, 201);
    }

    /**
     * Edits a product. Every field is optional: only the keys present in the request are updated.
     *
     * Accepts a JSON body or multipart/form-data (array fields may then be JSON strings).
     *  - images:    the photos to keep, as [{url}]. Existing photos missing from the list are deleted
     *               (database and bucket). A "data:" URI entry is uploaded as a new photo. Omit the key
     *               to leave the photos untouched.
     *  - newImages: files (multipart) uploaded as new photos.
     *  - variants:   the full variants list. An "image" holding a "data:" URI is uploaded; bucket images of
     *               variants that were removed or whose image changed are deleted.
     */
    #[Route('/{uid}/edit', name: 'app_product_edit', methods: ['POST'])]
    public function editProduct(
        string $uid,
        Request $request,
        ProductRepository $productRepository,
        CategoryRepository $categoryRepository,
        SubcategoryRepository $subcategoryRepository,
        EntityManagerInterface $entityManager,
        GcsUploaderService $gcsUploaderService,
        ProductService $productService,
        PhotoService $photoService,
        NormalizerInterface $normalizer,
        ValidatorInterface $validator,
        LoggerInterface $logger,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }
        if (in_array('ROLE_ADMIN', $user->getRoles()) === false) {
            return new JsonResponse(['error' => 'Forbidden'], JsonResponse::HTTP_FORBIDDEN);
        }

        $product = $productRepository->findOneBy(['uid' => $uid]);
        if (!$product) {
            return new JsonResponse(['error' => 'Product not found'], 404);
        }

        try {
            $data = $this->parseEditPayload($request);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'Invalid JSON'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $has = static fn (string $key): bool => array_key_exists($key, $data);

        if ($has('category')) {
            $category = $categoryRepository->find($data['category']);
            if (!$category) {
                return new JsonResponse(['error' => 'Category not found'], 404);
            }
            $product->setCategory($category);
        }
        if ($has('subCategory')) {
            $subcategory = empty($data['subCategory']) ? null : $subcategoryRepository->find($data['subCategory']);
            if (!empty($data['subCategory']) && !$subcategory) {
                return new JsonResponse(['error' => 'Subcategory not found'], 404);
            }
            $product->setSubcategory($subcategory);
        }

        if ($has('title')) {
            $product->setTitle(trim((string) $data['title']));
        }
        if ($has('brand')) {
            $product->setBrand(trim((string) $data['brand']));
        }
        if ($has('productCondition')) {
            $product->setProductCondition((string) $data['productCondition']);
        }
        // "seller" is the stored value; "supplierInfo" is what the publish form calls it.
        $seller = $data['seller'] ?? null;
        if ($seller === null || $seller === '') {
            $seller = $data['supplierInfo'] ?? null;
        }
        if ($seller !== null && $seller !== '') {
            $product->setSeller((string) $seller);
        }
        if ($has('scrappingUrl')) {
            $product->setScrappingUrl($data['scrappingUrl'] ?: null);
        }
        if ($has('description')) {
            $product->setDescription($data['description']);
        }
        if ($has('customerSays')) {
            $product->setCustomerSays($data['customerSays']);
        }
        if ($has('returnPolicy')) {
            $product->setReturnPolicy($data['returnPolicy']);
        }
        if ($has('usdPrice') && $data['usdPrice'] !== null && $data['usdPrice'] !== '') {
            $product->setUsdPrice((float) $data['usdPrice']);
        }
        if ($has('retailPrice') && $data['retailPrice'] !== null && $data['retailPrice'] !== '') {
            $newFullPrice = (float) $data['retailPrice'];
            if ($newFullPrice !== $product->getFullPrice()) {
                $product->setFullPrice($newFullPrice);
                $productService->updateLatestPrice($product);
            }
        }
        if ($has('available')) {
            $product->setIsAvailable(filter_var($data['available'], FILTER_VALIDATE_BOOLEAN));
        }
        if ($has('acceptReturn')) {
            $product->setAcceptReturn(filter_var($data['acceptReturn'], FILTER_VALIDATE_BOOLEAN));
        }
        if ($has('primeDelivery')) {
            $product->setPrimeDelivery(filter_var($data['primeDelivery'], FILTER_VALIDATE_BOOLEAN));
        }
        if ($has('sellerChoice')) {
            $product->setSellerChoice(filter_var($data['sellerChoice'], FILTER_VALIDATE_BOOLEAN));
        }
        if ($has('overallRating')) {
            $product->setOverallRating($data['overallRating'] === null || $data['overallRating'] === '' ? null : (float) $data['overallRating']);
        }
        $ratingsCount = $data['overallRatingsCounts'] ?? $data['overallRatingCount'] ?? null;
        if ($ratingsCount !== null && $ratingsCount !== '') {
            $product->setOverallRatingsCounts((int) $ratingsCount);
        }
        if ($has('details')) {
            $product->setDetails(is_array($data['details']) ? array_values($data['details']) : null);
        }
        if ($has('features')) {
            $product->setFeatures(is_array($data['features']) ? array_values($data['features']) : null);
        }
        $product->setUpdatedAt(new \DateTime());

        $violations = $validator->validate($product);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }

            return new JsonResponse(['error' => 'Validation failed', 'violations' => $errors], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Bucket files are only deleted once the database changes are saved.
        $candidatesForDeletion = [];

        if ($has('variants')) {
            $variants = is_array($data['variants']) ? array_values($data['variants']) : [];
            foreach ($product->getVariants() ?? [] as $oldVariant) {
                if (!empty($oldVariant['image'])) {
                    $candidatesForDeletion[] = $oldVariant['image'];
                }
            }
            foreach ($variants as &$variant) {
                if (!is_array($variant)) {
                    return new JsonResponse(['error' => 'Invalid variants'], JsonResponse::HTTP_BAD_REQUEST);
                }
                if (!empty($variant['image']) && str_starts_with($variant['image'], 'data:')) {
                    try {
                        $variant['image'] = $gcsUploaderService->uploadDataUri($variant['image'], $variant['title'] ?? 'variant');
                    } catch (\InvalidArgumentException $e) {
                        return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
                    }
                }
            }
            unset($variant);
            $product->setVariants($variants);
        }

        if ($has('images')) {
            $keptPhotos = [];
            $newUrls = [];
            $photosByUrl = [];
            foreach ($product->getPhotos() as $photo) {
                $photosByUrl[$photo->getUrl()] = $photo;
            }

            foreach (is_array($data['images']) ? $data['images'] : [] as $image) {
                $url = is_array($image) ? ($image['url'] ?? null) : $image;
                if (!is_string($url) || $url === '') {
                    continue;
                }
                if (str_starts_with($url, 'data:')) {
                    try {
                        $newUrls[] = $gcsUploaderService->uploadDataUri($url, $product->getSlug() ?? 'product');
                    } catch (\InvalidArgumentException $e) {
                        return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
                    }
                } elseif (isset($photosByUrl[$url])) {
                    $keptPhotos[$url] = true;
                } elseif (filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $url)) {
                    $newUrls[] = $url;
                } else {
                    return new JsonResponse(['error' => 'Invalid image URL'], JsonResponse::HTTP_BAD_REQUEST);
                }
            }

            foreach ($photosByUrl as $url => $photo) {
                if (!isset($keptPhotos[$url])) {
                    $product->removePhoto($photo);
                    $entityManager->remove($photo);
                    $candidatesForDeletion[] = $url;
                }
            }
            foreach ($newUrls as $url) {
                $photoService->createPhotoForProduct($product, $url);
            }
        }

        $uploadedFiles = $request->files->get('newImages') ?? [];
        foreach (is_array($uploadedFiles) ? $uploadedFiles : [$uploadedFiles] as $file) {
            $photoService->createPhotoForProduct($product, $gcsUploaderService->upload($file));
        }

        $entityManager->flush();

        $stillUsed = [];
        foreach ($product->getPhotos() as $photo) {
            $stillUsed[] = $photo->getUrl();
        }
        foreach ($product->getVariants() ?? [] as $variant) {
            if (!empty($variant['image'])) {
                $stillUsed[] = $variant['image'];
            }
        }
        foreach (array_unique(array_diff($candidatesForDeletion, $stillUsed)) as $url) {
            try {
                $gcsUploaderService->deleteByUrl($url);
            } catch (\Throwable $e) {
                $logger->error('Could not delete product image from the bucket.', ['url' => $url, 'exception' => $e]);
            }
        }

        return new JsonResponse($normalizer->normalize($product, null, [
            'groups' => ['product:read', 'product:read:details', 'product:read:admin'],
        ]), 200);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \JsonException
     */
    private function parseEditPayload(Request $request): array
    {
        if (str_contains((string) $request->headers->get('Content-Type'), 'json')) {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $data = is_array($data) ? $data : [];
        } else {
            $data = $request->request->all();
        }

        foreach (['images', 'details', 'features', 'variants'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = json_decode($data[$key], true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return $data;
    }

    #[Route('/change-price', name: 'create_product', methods: ['POST'])]
    public function changePrice(Request $request, ProductRepository $productRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }
        if (in_array('ROLE_ADMIN', $user->getRoles()) === false) {
            return new JsonResponse(['error' => 'Forbidden'], JsonResponse::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $productUid = $data['uid'] ?? null;
        $newPrice = isset($data['newPrice']) ? (float) $data['newPrice'] : null;

        if (!$productUid || $newPrice === null) {
            return new JsonResponse(['error' => 'Invalid data'], 400);
        }

        $product = $productRepository->findOneBy(['uid' => $productUid]);
        if (!$product) {
            return new JsonResponse(['error' => 'Product not found'], 404);
        }

        $actualPrice = $product->getFullPrice();
        if ($newPrice > $actualPrice) {
            $product->setLatestPrice($newPrice);
        }
        $product->setFullPrice($newPrice);
        $entityManager->flush();

        return new JsonResponse(['success' => true], 200);
    }

    #[Route('/bulk-apply-margin', name: 'bulk_apply_margin', methods: ['POST'])]
    public function bulkApplyMargin(Request $request, ProductRepository $productRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }
        if (in_array('ROLE_ADMIN', $user->getRoles()) === false) {
            return new JsonResponse(['error' => 'Forbidden'], JsonResponse::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $margin = isset($data['margin']) ? (int) $data['margin'] : null;
        $selectedProducts = $data['productUids'] ?? null;

        foreach ($selectedProducts as $productUid) {
            $product = $productRepository->findOneBy(['uid' => $productUid]);
            if ($product) {
                // margin = (fullPrice - usdPrice) / usdPrice * 100, so fullPrice = usdPrice * (1 + margin / 100)
                $newPrice = ceil($product->getUsdPrice() * (1 + $margin / 100));
                $product->setFullPrice($newPrice);
                if ($newPrice > $product->getLatestPrice()) {
                    $product->setLatestPrice($newPrice);
                }
            }
        }
        $entityManager->flush();

        return new JsonResponse(['success' => true], 200);
    }


    #[Route('/bulk-mark-as-available', name: 'bulk_mark_as_available', methods: ['POST'])]
    public function bulkMarkAsAvailable(Request $request, ProductRepository $productRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }
        if (in_array('ROLE_ADMIN', $user->getRoles()) === false) {
            return new JsonResponse(['error' => 'Forbidden'], JsonResponse::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $selectedProducts = $data['productUids'] ?? null;

        foreach ($selectedProducts as $productUid) {
            $product = $productRepository->findOneBy(['uid' => $productUid]);
            if ($product) {
                $product->setIsAvailable(true);
            }
        }
        $entityManager->flush();

        return new JsonResponse(['success' => true], 200);
    }

    #[Route('/bulk-mark-as-unavailable', name: 'bulk_mark_as_unavailable', methods: ['POST'])]
    public function bulkMarkAsUnavailable(Request $request, ProductRepository $productRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }
        if (in_array('ROLE_ADMIN', $user->getRoles()) === false) {
            return new JsonResponse(['error' => 'Forbidden'], JsonResponse::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $selectedProducts = $data['productUids'] ?? null;

        foreach ($selectedProducts as $productUid) {
            $product = $productRepository->findOneBy(['uid' => $productUid]);
            if ($product) {
                $product->setIsAvailable(false);
            }
        }
        $entityManager->flush();

        return new JsonResponse(['success' => true], 200);
    }
}