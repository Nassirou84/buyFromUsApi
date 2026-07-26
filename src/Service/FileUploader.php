<?php
namespace App\Service;

use Error;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

class FileUploader
{

    public function __construct(
        private SluggerInterface $slugger,
        private Filesystem $filesystem
    ) {
    }

    public function uploadIcons(UploadedFile $file, string $basePath, string $fileDir, string $siteUrl)
    {
        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = $this->slugger->slug($originalFilename . uniqid());
        $extension = $file->guessExtension();
        if (in_array($extension, ['png', 'svg', 'ico'])) {
            $fileName = $safeFilename . '-' . uniqid() . '.' . $extension;
            $target = $basePath . $fileDir;
            if (!$this->filesystem->exists($target)) {
                $this->filesystem->mkdir($target, 0775);
            }
            $file->move($target, $fileName);
            return [
                'name' => $originalFilename,
                'file' => $siteUrl . $fileDir . '/' . $fileName
            ];
        }
        throw new Error('Envoyez une icône au format png, svg, ico');
    }

    public function uploadDocuments(UploadedFile $file, string $basePath, string $fileDir, string $siteUrl)
    {
        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = $this->slugger->slug($originalFilename . uniqid());
        $extension = $file->guessExtension();
        if (in_array($extension, ['pdf', 'csv', 'doc', 'docx'])) {
            $fileName = $safeFilename . '-' . uniqid() . '.' . $extension;
            $target = $basePath . $fileDir;
            if (!$this->filesystem->exists($target)) {
                $this->filesystem->mkdir($target, 0775);
            }
            $file->move($target, $fileName);
            return [
                'name' => $originalFilename,
                'file' => $siteUrl . $fileDir . '/' . $fileName
            ];
        }
        throw new Error('Envoyez un document au format pdf, csv, docx');
    }

    public function uploadVideo(UploadedFile $file, string $basePath, string $fileDir, string $siteUrl)
    {
        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = $this->slugger->slug($originalFilename . uniqid());
        $extension = $file->guessExtension();
        if (in_array($extension, ['mp4', 'avi'])) {
            $fileName = $safeFilename . '-' . uniqid() . '.' . $extension;
            $target = $basePath . $fileDir;
            if (!$this->filesystem->exists($target)) {
                $this->filesystem->mkdir($target, 0775);
            }
            $file->move($target, $fileName);
            return [
                'name' => $originalFilename,
                'file' => $siteUrl . $fileDir . '/' . $fileName
            ];
        }
        throw new Error('Envoyez une video au format mp4, avi');
    }

    public function uploadPicture(UploadedFile $file, string $basePath, string $fileDir)
    {
        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = $this->slugger->slug($originalFilename . uniqid());
        $extension = $file->guessExtension();
        if (in_array($extension, ['jpeg', 'jpg', 'img', 'png'])) {
            $fileName = $safeFilename . '-' . uniqid() . '.' . $extension;
            $target = $basePath . $fileDir;
            if (!$this->filesystem->exists($target)) {
                $this->filesystem->mkdir($target, 0775);
            }
            $imageTemp = $file->getPathname();
            $mime = $file->getMimeType();
            $thumbnail = $fileName;
            $this->compressImage($imageTemp, $target . '/' . $thumbnail, 55, $mime);
            return [
                'name' => $originalFilename,
                'file' => $fileDir . '/' . $thumbnail
            ];
        }
        throw new Error('Envoyez une image au format jpg, jpeg, img, png');
    }

    public function compressImage($source, $destination, $quality, $mime)
    {
        // Create a new image from file 
        switch ($mime) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($source);
                break;
            case 'image/png':
                $image = imagecreatefrompng($source);
                break;
            case 'image/gif':
                $image = imagecreatefromgif($source);
                break;
            case 'image/webp':
                $image = imagecreatefromgif($source);
                break;
            default:
                $image = imagecreatefromjpeg($source);
        }

        // Save image 
        imagejpeg($image, $destination, $quality);

        // Return compressed image 
        return $destination;
    }

    public function delete(string $file)
    {
        return unlink($file);
    }
}