<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Product;
use App\Service\TranslatorService;
use App\Service\GcsUploaderService;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use App\Service\PhotoService;
use App\Service\UniqUidGenerator;
use App\Repository\CategoryRepository;
use App\Repository\SubcategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('api/product')]
final class ProductController extends AbstractController
{
    #[Route('/publish-product', name: 'app_product_scrape', methods: ['POST'])]
    public function publishProduct(
        Request $request,
        TranslatorService $translatorService,
        EntityManagerInterface $entityManager,
        GcsUploaderService $gcsUploaderService,
        UniqUidGenerator $uniqUidGenerator,
        CategoryRepository $categoryRepository,
        SubcategoryRepository $subcategoryRepository,
        PhotoService $photoService,
        NormalizerInterface $normalizer,
        SluggerInterface $slugger,
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
        $product->setLatestPrice(isset($data['latestPrice']) ? (float) $data['latestPrice'] : null);
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

        $entityManager->persist($product);
        $entityManager->flush();

        if (!empty($images)) {
            foreach ($images as $image) {
                $file = $gcsUploaderService->upload($image);
                $photoService->createPhotoForProduct($product, $file);
            }
        }
        $normalizedProduct = $normalizer->normalize($product, null, ['groups' => 'product:read']);

        return new JsonResponse($normalizedProduct, 201);
    }

}