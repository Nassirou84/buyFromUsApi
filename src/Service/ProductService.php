<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

class ProductService
{
    public function __construct(
        private EntityManagerInterface $entityManagerInterface,
        private SluggerInterface $sluggerInterface,
    ) {
    }

    public function createProduct($product): Product
    {
        $product->setSlug($this->sluggerInterface->slug($product->getTitle()) . '-' . uniqid());

        $this->entityManagerInterface->persist($product);
        $this->entityManagerInterface->flush();

        return $product;
    }
}
