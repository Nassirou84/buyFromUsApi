<?php

declare(strict_types=1);

namespace App\Service;

use Google\Cloud\Storage\StorageClient;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

use function sprintf;

use const PATHINFO_FILENAME;

class GcsUploaderService
{
    private $storageBucket;

    public function __construct(
        string $keyFilePath,
        string $bucketName,
        private readonly SluggerInterface $sluggerInterface,
    ) {
        $storage = new StorageClient([
            'keyFilePath' => $keyFilePath,
        ]);
        $this->storageBucket = $storage->bucket($bucketName);
    }

    public function upload(UploadedFile $file): string
    {
        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = $this->sluggerInterface->slug($originalFilename);
        $fileName = sprintf('%s-%s.%s', $safeFilename, uniqid(), $file->guessExtension() ?? 'png');

        $this->storageBucket->upload(
            fopen($file->getPathname(), 'r'),
            [
                'name' => $fileName,
                'metadata' => [
                    'contentType' => $file->getMimeType(),
                ],
            ],
        );

        return sprintf('https://storage.googleapis.com/%s/%s', $this->storageBucket->name(), $fileName);
    }

    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /**
     * Uploads a base64 data URI (e.g. "data:image/jpeg;base64,...") and returns its public URL.
     */
    public function uploadDataUri(string $dataUri, string $baseName = 'image'): string
    {
        if (!preg_match('#^data:(image/[a-z0-9.+-]+);base64,(.+)$#is', $dataUri, $matches)) {
            throw new \InvalidArgumentException('Invalid image data URI.');
        }

        $mimeType = strtolower($matches[1]);
        $content = base64_decode($matches[2], true);
        if ($content === false) {
            throw new \InvalidArgumentException('Invalid base64 image content.');
        }

        $extension = self::MIME_EXTENSIONS[$mimeType] ?? 'png';
        $safeName = $this->sluggerInterface->slug($baseName);
        $fileName = sprintf('%s-%s.%s', $safeName, uniqid(), $extension);

        $this->storageBucket->upload($content, [
            'name' => $fileName,
            'metadata' => [
                'contentType' => $mimeType,
            ],
        ]);

        return sprintf('https://storage.googleapis.com/%s/%s', $this->storageBucket->name(), $fileName);
    }
}
