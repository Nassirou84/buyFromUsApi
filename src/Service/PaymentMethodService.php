<?php

namespace App\Service;

use App\Entity\PaymentMethod;
use App\Entity\User;
use App\Repository\PaymentMethodRepository;
use Doctrine\ORM\EntityManagerInterface;


final class PaymentMethodService
{
  // Secret id (in Google Secret Manager) of the pepper keyed into the blind-index
  // HMAC. Reuses the existing `encryption_key` secret, so no new secret has to be
  // provisioned. It must stay STABLE: changing it makes every stored fingerprint
  // unmatchable and silently breaks dedupe for existing cards.
  private const FINGERPRINT_PEPPER_SECRET = 'encryption_key';

  public function __construct(
    private readonly EntityManagerInterface $entityManagerInterface,
    private readonly GoogleKmsService $googleKmsService,
    private readonly SecretManagerService $secretManager,
    private PaymentMethodRepository $paymentMethodRepository
  ) {
  }

  /**
   * Deterministic, non-reversible fingerprint of a Paystack authorization token,
   * used as a blind index: KMS ciphertext changes every call and cannot be matched
   * in a query, so dedupe looks cards up by this HMAC instead. The pepper is a
   * secret, so the index cannot be recomputed by a database-only attacker.
   */
  private function fingerprint(string $authorizationCode): string
  {
    return hash_hmac('sha256', $authorizationCode, $this->secretManager->getSecret(self::FINGERPRINT_PEPPER_SECRET));
  }

  /**
   * KMS encryption context (AAD) for a stored token: sha1 of the owning user's id
   * (User has no separate uid). KMS binds the ciphertext to this value, so a token
   * cannot be moved to another user's row and decrypted/charged there — the same
   * context must be supplied to decrypt or KMS refuses.
   */
  private function encryptionContext(User $user): string
  {
    return sha1((string) $user->getId());
  }

  /**
   * Decrypts a saved card's Paystack authorization token, supplying the owner-bound
   * encryption context so a substituted ciphertext (moved from another user's row)
   * cannot be decrypted. Callers must not reach into GoogleKmsService directly.
   */
  public function decryptAuthorizationCode(PaymentMethod $paymentMethod): string
  {
    $cipher = $paymentMethod->getAuthorizationCode();
    if (null === $cipher || '' === $cipher) {
      throw new \LogicException('Payment method has no stored authorization code to decrypt.');
    }

    $customer = $paymentMethod->getCustomer();
    if (null === $customer) {
      throw new \LogicException('Payment method has no owner to derive the encryption context.');
    }

    return $this->googleKmsService->decrypt($cipher, $this->encryptionContext($customer));
  }

  public function save(PaymentMethod $paymentMethod): void
  {
    $this->entityManagerInterface->persist($paymentMethod);
    $this->entityManagerInterface->flush();
  }

  /**
   * One-off migration for cards saved before the blind index and the encryption
   * context existed. For each legacy row it decrypts the token (originally stored
   * with NO context), then both (a) sets the blind-index fingerprint and
   * (b) re-encrypts the token bound to the owner's encryption context.
   *
   * Idempotent: a missing fingerprint is the "legacy, not yet migrated" marker —
   * `createFromAuthorization` always sets the fingerprint together with a
   * context-bound ciphertext, and so does this method, so once migrated a row is
   * skipped. Returns the number of rows updated.
   */
  public function backfillMissingFingerprints(): int
  {
    $rows = $this->paymentMethodRepository->findBy(['authorizationFingerprint' => null]);

    $updated = 0;
    foreach ($rows as $paymentMethod) {
      $cipher = $paymentMethod->getAuthorizationCode();
      if (null === $cipher || '' === $cipher) {
        // Mobile-money (or otherwise tokenless) rows have nothing to migrate.
        continue;
      }

      $customer = $paymentMethod->getCustomer();
      if (null === $customer) {
        continue;
      }

      // Legacy ciphertext was produced without AAD, so decrypt with none...
      $plaintext = $this->googleKmsService->decrypt($cipher);
      // ...then re-encrypt bound to the owner and record the blind index.
      $paymentMethod->setAuthorizationCode($this->googleKmsService->encrypt($plaintext, $this->encryptionContext($customer)));
      $paymentMethod->setAuthorizationFingerprint($this->fingerprint($plaintext));
      ++$updated;
    }

    if ($updated > 0) {
      $this->entityManagerInterface->flush();
    }

    return $updated;
  }

  /**
   * Finds the customer's already-saved row for this card, if any.
   *
   * Primary key is Paystack's per-card `signature`. The authorization-token blind
   * index is only a fallback, for rows saved before the signature was stored and
   * for the (unlikely) authorization that carries no signature: it matches just the
   * one transaction's token, which is why it alone let duplicates through.
   */
  private function findExistingCard(User $user, ?string $signature, string $fingerprint): ?PaymentMethod
  {
    if (null !== $signature) {
      $bySignature = $this->paymentMethodRepository->findOneBy([
        'cardSignature' => $signature,
        'customer' => $user,
      ]);

      if (null !== $bySignature) {
        return $bySignature;
      }
    }

    return $this->paymentMethodRepository->findOneBy([
      'authorizationFingerprint' => $fingerprint,
      'customer' => $user,
    ]);
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

    // Card identity is Paystack's `signature`, NOT the authorization token: Paystack
    // issues a fresh authorization_code per transaction, so matching on the token's
    // blind index saved the same card again on every purchase.
    $signature = $authorization['signature'] ?? null;
    $signature = \is_string($signature) && '' !== $signature ? $signature : null;

    $fingerprint = $this->fingerprint($authorizationCode);

    $existingPaymentCard = $this->findExistingCard($user, $signature, $fingerprint);
    if ($existingPaymentCard) {
      // Same card, newer authorization token: keep the stored token current (and
      // stamp the signature on a row saved before this column existed) so the next
      // card-not-present charge uses the freshest authorization.
      $existingPaymentCard->setAuthorizationCode($this->googleKmsService->encrypt($authorizationCode, $this->encryptionContext($user)));
      $existingPaymentCard->setAuthorizationFingerprint($fingerprint);
      if (null !== $signature) {
        $existingPaymentCard->setCardSignature($signature);
      }
      $this->save($existingPaymentCard);

      return $existingPaymentCard;
    }

    $paymentMethod = new PaymentMethod();
    $paymentMethod->setMethodType('card');
    $paymentMethod->setIsDefault(0 === count($user->getPaymentMethods()));
    $paymentMethod->setCustomer($user);
    $paymentMethod->setAuthorizationCode($this->googleKmsService->encrypt($authorizationCode, $this->encryptionContext($user)));
    $paymentMethod->setAuthorizationFingerprint($fingerprint);
    $paymentMethod->setCardSignature($signature);
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