<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ProductSearchSuggestionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ProductSearchSuggestionController extends AbstractController
{
    /**
     * Public (no login needed): search-as-you-type suggestions drawn from products only.
     * GET ?q=iph => {"query": "iph", "suggestions": [
     *   {"type": "key", "key": "Apple"},
     *   {"type": "product", "title": "iPhone 15 ...", "slug": "iphone-15", "productCondition": "new", "coverImage": "https://..."}
     * ]}.
     * "key" entries are plain search keywords (brands); "product" entries are title matches (coverImage is null without photos).
     * Fewer than 2 characters gives an empty list. Responses are cached server side (Redis) and for 5 minutes by clients.
     */
    #[Route('/api/product/search-suggestions', name: 'app_product_search_suggestions', methods: ['GET'])]
    public function __invoke(Request $request, ProductSearchSuggestionService $suggestionService): JsonResponse
    {
        $query = ProductSearchSuggestionService::normalize($request->query->getString('q'));

        $response = $this->json([
            'query' => $query,
            'suggestions' => $suggestionService->suggest($query),
        ]);
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }
}
