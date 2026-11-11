<?php

namespace App\Service;

use App\Entity\PaymentMethod;
use App\Entity\User;
use App\Repository\PaymentMethodRepository;
use Doctrine\ORM\EntityManagerInterface;


final class PaymentMethodService
{
  public function __construct(
    private readonly EntityManagerInterface $entityManagerInterface,
    private readonly SecretManagerService $secretManager,
    private PaymentMethodRepository $paymentMethodRepository
  ) {
  }

  public function save(PaymentMethod $paymentMethod): void
  {
    $this->entityManagerInterface->persist($paymentMethod);
    $this->entityManagerInterface->flush();
  }

  /**
   * Builds and persists a reusable PaymentMethod from a Paystack `authorization`
   * object so the customer's next checkout can be prefilled.
   *
   * CARDS ONLY. Mobile money (Wave, Orange Money, MTN, ... all reported under the
   * single `mobile_money` channel) carries no reusable charge token, so it can
   * never be charged again from storage: it is entered in the Paystack checkout on
   * every purchase and deliberately not saved here.
   *
   * Returns null when the authorization carries no reusable charge token or is not
   * a card.
   *
   * @param array<string, mixed> $authorization
   */
  public function createFromAuthorization(array $authorization, User $user): ?PaymentMethod
  {
    $authorizationCode = $authorization['authorization_code'] ?? null;
    if (empty($authorizationCode)) {
      return null;
    }

    // Only cards are saveable. Anything else (mobile money, bank transfer, ...)
    // is never stored, whatever the metadata asked for.
    $channel = $authorization['channel'] ?? null;
    if ('card' !== $channel) {
      return null;
    }

    $existingPaymentCard = $this->paymentMethodRepository->findOneBy([
      'authorizationCode' => $this->secretManager->encryptElement($authorizationCode),
      'customer' => $user,
    ]);
    if ($existingPaymentCard) {
      return $existingPaymentCard;
    }

    $paymentMethod = new PaymentMethod();
    $paymentMethod->setMethodType('card');
    $paymentMethod->setIsDefault(0 === count($user->getPaymentMethods()));
    $paymentMethod->setCustomer($user);
    $paymentMethod->setAuthorizationCode($this->secretManager->encryptElement($authorizationCode));
    $paymentMethod->setCardLast4($authorization['last4'] ?? null);
    $paymentMethod->setCardBrand($authorization['brand'] ?? null);
    $paymentMethod->setExpiryMonth($authorization['exp_month'] ?? null);
    $paymentMethod->setExpiryYear($authorization['exp_year'] ?? null);

    $this->save($paymentMethod);

    return $paymentMethod;
  }

  /**
   * Marks the given payment method as the customer's default, clearing the flag
   * on any other method they own so that at most one default exists per customer.
   */
  public function setAsDefault(PaymentMethod $paymentMethod): void
  {
    $customer = $paymentMethod->getCustomer();

    $currentDefaults = $this->entityManagerInterface
      ->getRepository(PaymentMethod::class)
      ->findBy(['customer' => $customer, 'isDefault' => true]);

    foreach ($currentDefaults as $existingDefault) {
      if ($existingDefault !== $paymentMethod) {
        $existingDefault->setIsDefault(false);
        $this->entityManagerInterface->persist($existingDefault);
      }
    }

    $paymentMethod->setIsDefault(true);
    $this->entityManagerInterface->persist($paymentMethod);
    $this->entityManagerInterface->flush();
  }

  /**
   * Removes the given payment method. If it was the customer's default, the most
   * recently created remaining method is promoted so the customer keeps a default
   * whenever they still have at least one method left.
   */
  public function delete(PaymentMethod $paymentMethod): void
  {
    $wasDefault = true === $paymentMethod->isDefault();
    $customer = $paymentMethod->getCustomer();

    $this->entityManagerInterface->remove($paymentMethod);
    $this->entityManagerInterface->flush();

    if (!$wasDefault || null === $customer) {
      return;
    }

    $replacement = $this->paymentMethodRepository->findOneBy(
      ['customer' => $customer],
      ['createdAt' => 'DESC'],
    );

    if (null !== $replacement) {
      $this->setAsDefault($replacement);
    }
  }
}