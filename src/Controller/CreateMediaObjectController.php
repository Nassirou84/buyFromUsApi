<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Photo;
use App\Service\GcsUploaderService;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
final class CreateMediaObjectController extends AbstractController
{
    public function __invoke(
        Request $request,
        GcsUploaderService $gcsUploaderService,
        EntityManagerInterface $entityManager,
    ): Photo {
        $uploadedFile = $request->files->get('file');

        if (!$uploadedFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            throw new RuntimeException('No file uploaded');
        }

        $gscUrl = $gcsUploaderService->upload($uploadedFile);

        $mediaObject = new Photo();
        $mediaObject->setUrl($gscUrl);

        $entityManager->persist($mediaObject);
        $entityManager->flush();

        return $mediaObject;
    }
}
