<?php

namespace App\Service;

use App\Entity\Payment;
use Doctrine\ORM\EntityManagerInterface;
class PaymentService
{

  public function __construct(
    private EntityManagerInterface $entityManager
  ) {
  }

  public function createPayment(Payment $payment): void
  {
    $this->entityManager->persist($payment);
    $this->entityManager->flush();
  }

  public function existingPayment(string $transactionId): ?Payment
  {
    return $this->entityManager->getRepository(Payment::class)->findOneBy(['transactionId' => $transactionId]);
  }
}