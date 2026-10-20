<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

use App\Entity\Photo;
use App\Entity\Product;

class PhotoService
{
  public function __construct(
    private EntityManagerInterface $entityManagerInterface
  ) {
  }

  public function createPhotoForProduct(Product $product, string $url): void
  {
    $photo = new Photo();
    // The first photo added to a product without a cover becomes its cover.
    $photo->setIsCover(!$this->hasCover($product));
    $product->addPhoto($photo);
    $photo->setUrl($url);
    $this->entityManagerInterface->persist($photo);
    $this->entityManagerInterface->flush();
  }

  private function hasCover(Product $product): bool
  {
    foreach ($product->getPhotos() as $photo) {
      if ($photo->isCover()) {
        return true;
      }
    }

    return false;
  }
}
