<?php

namespace App\Service;

use App\Entity\Inventory;
use App\Entity\ProductReturnItem;
use Doctrine\ORM\EntityManagerInterface;

final class InventoryService
{
  public function __construct(
    private EntityManagerInterface $entityManagerInterface
  ) {
  }

  public function updateInventoryForReturnItem(ProductReturnItem $returnItem): Inventory
  {
    $orderItem = $returnItem->getOrderItem();
    $inventory = new Inventory();
    $inventory->setProduct($orderItem->getProduct());
    $inventory->setProductReturnItem($returnItem);
    $inventory->setQuantity($returnItem->getQuantity());
    $inventory->setType(Inventory::TYPE_RETURN);

    $itemCondition = $returnItem->getItemCondition();

    if ($itemCondition == ProductReturnItem::CONDITION_GRADE_A || $itemCondition == ProductReturnItem::CONDITION_GRADE_B) {
      $inventory->setCanBeResold(true);
    }
    $inventory->setIsAvailable(true);

    $this->entityManagerInterface->persist($inventory);
    $this->entityManagerInterface->flush();

    return $inventory;
  }
}