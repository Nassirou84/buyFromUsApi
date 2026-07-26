<?php

namespace App\Service;
use App\Repository\ShoppingRequestRepository;

class ShoppingRequestService
{
  public function __construct(
    private ShoppingRequestRepository $shoppingRequestRepository
  ) {
  }

}