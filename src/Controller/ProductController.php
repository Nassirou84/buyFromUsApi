<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Product;
use App\Entity\User;
use App\Exception\ProductEditException;
use App\Message\NewProductAddedMessage;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use App\Repository\SubcategoryRepository;
use App\Service\GcsUploaderService;
use App\Service\PhotoService;
use App\Service\ProductService;
use App\Service\UniqUidGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

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
        if (in_array(User::ROLE_ADMIN, $user->getRoles()) === false) {
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
     * Edits a product; see ProductService::editProduct() for the accepted payload.
     * Accepts a JSON body or multipart/form-data (array fields may then be JSON strings),
     * with new photo files under "newImages".
     */
    #[Route('/{uid}/edit', name: 'app_product_edit', methods: ['POST'])]
    public function editProduct(
        string $uid,
        Request $request,
        ProductRepository $productRepository,
        ProductService $productService,
        NormalizerInterface $normalizer,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }
        if (in_array(User::ROLE_ADMIN, $user->getRoles()) === false) {
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

        $newImages = $request->files->get('newImages') ?? [];
        try {
            $productService->editProduct($product, $data, is_array($newImages) ? $newImages : [$newImages]);
        } catch (ProductEditException $e) {
            $body = ['error' => $e->getMessage()];
            if ($e->getViolations()) {
                $body['violations'] = $e->getViolations();
            }

            return new JsonResponse($body, $e->getStatusCode());
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
        if (in_array(User::ROLE_ADMIN, $user->getRoles()) === false) {
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
        if (in_array(User::ROLE_ADMIN, $user->getRoles()) === false) {
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
        if (in_array(User::ROLE_ADMIN, $user->getRoles()) === false) {
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
        if (in_array(User::ROLE_ADMIN, $user->getRoles()) === false) {
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