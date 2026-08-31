<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Service\WishlistService;

final class RemoveProductFromWhistlistController extends AbstractController
{
    public function __invoke(
        Request $request,
        WishlistService $whishlistService,
    ): Response {
        $data = json_decode($request->getContent(), true);
        $productId = $data['productId'] ?? null;
        if ($productId !== null) {
            $whishlistService->removeProduct($productId);
            return new Response(json_encode([
                'success' => true,
                'message' => 'Produit retiré de la liste de souhaits avec succès.'
            ]), Response::HTTP_NO_CONTENT);
        }
        return new Response(json_encode([
            'success' => false,
            'message' => 'Produit non trouvé dans la liste de souhaits.'
        ]), Response::HTTP_NOT_FOUND);
    }
}