<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\RequestThrottleService;
use App\Service\ShoppingRequestService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class CreateShoppingRequestController extends AbstractController
{
    private const REQUIRED_FIELDS = ['title', 'description', 'quantity', 'fullName', 'email', 'phone', 'address', 'preferredContact'];
    private const MAX_FILES = 5;
    private const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5 MB
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __invoke(
        Request $request,
        ShoppingRequestService $shoppingRequestService,
        RequestThrottleService $throttle,
    ): JsonResponse {
        // Public endpoint that accepts file uploads: throttle per client IP.
        if (!$throttle->allow('shopping-request:' . $request->getClientIp(), 5, 60)) {
            return $this->json(['message' => 'too_many_requests'], 429);
        }

        foreach (self::REQUIRED_FIELDS as $field) {
            if (!$request->request->has($field) || '' === trim((string) $request->request->get($field))) {
                return $this->json(['message' => 'Veuillez remplir tous les champs requis.'], 400);
            }
        }

        $files = $request->files->all();
        if (0 === count($files)) {
            return $this->json(['message' => 'Veuillez envoyer au moins une image.'], 400);
        }
        if (count($files) > self::MAX_FILES) {
            return $this->json(['message' => 'Trop d\'images (maximum ' . self::MAX_FILES . ').'], 400);
        }

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                return $this->json(['message' => 'Fichier invalide.'], 400);
            }
            if ($file->getSize() > self::MAX_FILE_SIZE) {
                return $this->json(['message' => 'Image trop volumineuse (maximum 5 Mo).'], 400);
            }
            if (!in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
                return $this->json(['message' => 'Type de fichier non autorisé.'], 400);
            }
        }

        $shoppingRequest = $shoppingRequestService->createShoppingRequest($request);

        return $this->json($shoppingRequest, 201, [], ['groups' => ['shopping_request:read']]);
    }
}
