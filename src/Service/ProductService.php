<?php
namespace App\Service;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\TokenService;
use Symfony\Component\String\Slugger\SluggerInterface;
use App\Entity\Product;
class ProductService
{
  public function __construct(
    private EntityManagerInterface $entityManagerInterface,
    private TokenService $tokenService,
    private SluggerInterface $sluggerInterface
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