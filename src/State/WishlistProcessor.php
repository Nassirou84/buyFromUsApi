<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Wishlist;

class WishlistProcessor implements ProcessorInterface
{
    public function __construct(
        private \App\Service\WishlistService $wishlistService,
    ) {
    }

    public function process(
        mixed $wishlist,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): Wishlist {
        $wishlist = $this->wishlistService->createWishlist($wishlist);

        return $wishlist;
    }
}
