<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Repository\UserRepository;
use App\Service\ProductService;

class ProductStateProcessor implements ProcessorInterface
{

  public function __construct(
    private ProductService $productService,
    private UserRepository $userRepository
  ) {
  }

  public function process(
    mixed $product,
    Operation $operation,
    array $uriVariables = [],
    array $context = []
  ): mixed {
    $product = $this->productService->createProduct($product);
    return $product;
  }
}