<?php
namespace App\Service;

use App\Entity\CreditTrail;
use App\Entity\ProductReturn;
use App\Entity\User;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

class StoreCreditService
{

  public function __construct(
    private EntityManagerInterface $entityManagerInterface
  ) {
  }

  /**
   * Adds credit under a row lock on the user, so two concurrent credits can't both read
   * the same balance and overwrite each other. The user is reloaded from the database:
   * unflushed changes to that User are discarded. Joins the caller's transaction if any.
   */
  public function addStoreCredit(User $user, int $amount, ?User $creditedBy = null, ?ProductReturn $productReturn = null): CreditTrail
  {
    return $this->entityManagerInterface->wrapInTransaction(function () use ($user, $amount, $creditedBy, $productReturn): CreditTrail {
      $this->entityManagerInterface->refresh($user, LockMode::PESSIMISTIC_WRITE);

      $balanceAfter = ($user->getCreditBalance() ?? 0) + $amount;
      $user->setCreditBalance($balanceAfter);

      $creditTrail = new CreditTrail();
      $creditTrail->setCustomer($user);
      $creditTrail->setAmount($amount);
      $creditTrail->setCreatedAt(new \DateTime());
      $creditTrail->setAction(CreditTrail::ACTION_CREDIT);
      $creditTrail->setCreditedBy($creditedBy);
      $creditTrail->setProductReturn($productReturn);
      $creditTrail->setBalanceAfter($balanceAfter);

      $this->entityManagerInterface->persist($creditTrail);
      $this->entityManagerInterface->persist($user);

      return $creditTrail;
    });
  }
}
