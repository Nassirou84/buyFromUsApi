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
    $photo->setProduct($product);
    $photo->setUrl($url);
    $this->entityManagerInterface->persist($photo);
    $this->entityManagerInterface->flush();
  }
}