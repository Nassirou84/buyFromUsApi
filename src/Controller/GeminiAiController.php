<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use App\Service\GeminiService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('api/gemini', name: 'app_gemini_ai')]
final class GeminiAiController extends AbstractController
{
    #[Route('/generate-product-content', name: 'app_gemini_generate_product_content', methods: ['POST'])]
    public function generateProductContent(
        Request $request,
        GeminiService $geminiService
    ): Response {
        $data = json_decode($request->getContent(), true);

        if (empty($data)) {
            return new JsonResponse(['error' => 'No data provided'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $product = $geminiService->generateProductContentFor($data);
        } catch (\JsonException $e) {
            return new JsonResponse(['error' => 'Invalid JSON returned by the AI'], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse($product);
    }

    #[Route('/generate-product-content-from-title', name: 'app_gemini_generate_product_content_from_title', methods: ['POST'])]
    public function generateProductContentFromTitle(
        Request $request,
        GeminiService $geminiService
    ): Response {
        $data = json_decode($request->getContent(), true);

        if (empty($data['title'])) {
            return new JsonResponse(['error' => 'No title provided'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $product = $geminiService->generateProductContentFromTitle($data['title']);
        } catch (\JsonException $e) {
            return new JsonResponse(['error' => 'Invalid JSON returned by the AI'], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse($product);
    }
}