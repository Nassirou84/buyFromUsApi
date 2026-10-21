<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\GcsUploaderService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class UploadBannerImageController extends AbstractController
{
    private const MAX_SIZE = 5 * 1024 * 1024; // 5 MB
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * Stores a banner image in the bucket and returns its public URL. The URL is then
     * saved on the banner through the regular /api/banners endpoints.
     */
    #[Route('api/admin/banner/upload-image', name: 'app_admin_banner_upload_image', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(Request $request, GcsUploaderService $gcsUploaderService): JsonResponse
    {
        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->json(['error' => 'No valid file uploaded.'], 400);
        }

        if ($file->getSize() > self::MAX_SIZE) {
            return $this->json(['error' => 'The image must be 5 MB or smaller.'], 413);
        }

        if (!in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            return $this->json(['error' => 'The file must be a JPEG, PNG, WebP or GIF image.'], 415);
        }

        return $this->json(['imageUrl' => $gcsUploaderService->upload($file)], 201);
    }
}
