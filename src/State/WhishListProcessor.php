<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Wishlist;

class WhishListProcessor implements ProcessorInterface
{
    public function __construct(
        private \App\Service\WhishlistService $whishlistService,
    ) {
    }

    public function process(
        mixed $wishlist,
        Operation $operation,
        array $uriVariables = [],
        array $context = []
    ): Wishlist {
        $wishlist = $this->whishlistService->createWishlist($wishlist);
        return $wishlist;
    }
}