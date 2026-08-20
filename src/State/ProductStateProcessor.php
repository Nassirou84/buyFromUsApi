<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Service\ProductService;

class ProductStateProcessor implements ProcessorInterface
{
    public function __construct(
        private ProductService $productService,
    ) {
    }

    public function process(
        mixed $product,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): mixed {
        $product = $this->productService->createProduct($product);

        return $product;
    }
}
