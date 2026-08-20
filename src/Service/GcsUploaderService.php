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
}
