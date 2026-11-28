<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\ProductReturn;
use App\Entity\Refund;
use App\Entity\User;
use App\Message\RefundNotificationMessage;
use App\Repository\RefundRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use InvalidArgumentException;
use LogicException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Throwable;

use function count;
use function in_array;
use function is_string;
use function sprintf;
use function strlen;

/**
 * Gateway refunds: writes the audit row before calling the gateway, then records
 * what the gateway answered (API response, then webhooks).
 */
final class RefundService
{
    /** Payment statuses meaning the money was actually collected. */
    private const SETTLED_PAYMENT_STATUSES = ['success', Payment::STATUS_COMPLETED];

    /** Outcomes of syncWithGateway(). */
    public const SYNC_UPDATED = 'updated';
    public const SYNC_UNCHANGED = 'unchanged';
    public const SYNC_NOT_FOUND = 'not_found';
    public const SYNC_UNKNOWN_STATUS = 'unknown_status';

    /** Outcomes of reconcile(). */
    public const RECONCILE_RESOLVED_BY_GATEWAY = 'resolved_by_gateway';
    public const RECONCILE_RESOLVED_MANUALLY = 'resolved_manually';
    public const RECONCILE_STILL_UNRESOLVED = 'still_unresolved';

    public const RESOLUTION_SYNC = 'sync';
    public const RESOLUTION_PROCESSED = 'processed';
    public const RESOLUTION_FAILED = 'failed';

    /** How far before our row's createdAt to look for its gateway refund (clock skew). */
    private const SYNC_LOOKBACK = '-1 hour';
    /** Safety cap on the pages of GET /refund scanned per unresolved refund. */
    private const SYNC_MAX_LIST_PAGES = 10;

    private const GATEWAY_STATUS_MAP = [
        'pending' => Refund::STATUS_PENDING,
        'processing' => Refund::STATUS_PROCESSING,
        'processed' => Refund::STATUS_PROCESSED,
        'failed' => Refund::STATUS_FAILED,
        'needs-attention' => Refund::STATUS_NEEDS_ATTENTION,
    ];

    /**
     * GET /refund pages fetched once for a whole sync run (see beginSyncRun()) and
     * shared by every row scanned during it.
     *
     * @var array{from: DateTimeImmutable, items: ?list<array<string, mixed>>, complete: bool}|null
     */
    private ?array $runRefundList = null;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RefundRepository $refundRepository,
        private PaystackService $paystackService,
        private LoggerInterface $logger,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * The successful Paystack payment of an order, or null when there is none.
     */
    public function findRefundablePayment(Order $order): ?Payment
    {
        $settled = null;
        foreach ($order->getPayments() as $payment) {
            if (!in_array($payment->getStatus(), self::SETTLED_PAYMENT_STATUSES, true)) {
                continue;
            }
            if ('paystack' !== $payment->getPaymentProvider()) {
                continue;
            }
            if (null === $settled || $payment->getCreatedAt() > $settled->getCreatedAt()) {
                $settled = $payment;
            }
        }

        return $settled;
    }

    /**
     * What can still be refunded on a payment: amount collected minus every refund
     * that is pending, processing, processed or of unknown outcome.
     */
    public function getRefundableAmount(Payment $payment): int
    {
        $alreadyRefunded = 0;
        foreach ($payment->getRefunds() as $refund) {
            if ($refund->isActive()) {
                $alreadyRefunded += $refund->getAmount();
            }
        }

        return max((int) floor((float) $payment->getAmount()) - $alreadyRefunded, 0);
    }

    public function hasActiveRefund(ProductReturn $return): bool
    {
        foreach ($return->getRefunds() as $refund) {
            if ($refund->isActive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Refunds a product return through the gateway.
     *
     * The Refund row is flushed as `pending` before the gateway is called, so an attempt
     * is always on record even if the request dies. Gateway outcome:
     * - accepted: status taken from the gateway (pending/processing/processed); webhooks finish it.
     * - refused (4xx): row marked `failed`, throws `refund_rejected`. A retry creates a new row.
     * - unknown (timeout/5xx): row marked `needs_attention`, throws `refund_outcome_unknown`.
     *   It counts against the refundable amount and blocks retries until reconciled.
     *
     * @throws LogicException   when the refund is not allowed (nothing written)
     * @throws RuntimeException when the gateway refused it or its outcome is unknown
     */
    public function refundProductReturn(
        ProductReturn $return,
        Payment $payment,
        User $initiatedBy,
        int $itemsAmount,
        int $shippingAmount,
        int $taxAmount,
    ): Refund {
        if ($this->hasActiveRefund($return)) {
            throw new LogicException('refund_already_exists_for_return');
        }

        // Rounding (prices ceiled per component) can overshoot a full refund by one unit:
        // absorb that on the items line, refuse anything larger.
        $overshoot = ($itemsAmount + $shippingAmount + $taxAmount) - $this->getRefundableAmount($payment);
        if ($overshoot > 1) {
            throw new LogicException('refund_exceeds_refundable_amount');
        }
        if ($overshoot > 0) {
            $itemsAmount -= $overshoot;
        }

        $refund = new Refund(
            reference: $this->buildReference($return),
            payment: $payment,
            initiatedBy: $initiatedBy,
            reason: Refund::REASON_PRODUCT_RETURN,
            itemsAmount: $itemsAmount,
            shippingAmount: $shippingAmount,
            taxAmount: $taxAmount,
            productReturn: $return,
            note: $return->getReturnNote(),
        );

        try {
            $this->entityManager->persist($refund);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created the same attempt first.
            throw new LogicException('refund_already_exists_for_return');
        }

        try {
            $data = $this->paystackService->refund(
                $refund->getTransactionReference(),
                $refund->getAmount(),
                $refund->getCurrency(),
                'Return ' . $return->getUid() . ' / refund ' . $refund->getReference(),
            );
        } catch (DomainException $e) {
            $refund->applyGatewayStatus(Refund::STATUS_FAILED, null, ['error' => $e->getMessage()], 'api', $e->getMessage());
            $this->entityManager->flush();
            $this->notifyAdmin($refund, 'api');

            throw new RuntimeException('refund_rejected', 0, $e);
        } catch (TransportExceptionInterface|RuntimeException $e) {
            $refund->applyGatewayStatus(Refund::STATUS_NEEDS_ATTENTION, null, ['error' => $e->getMessage()], 'api', $e->getMessage());
            $this->entityManager->flush();
            $this->notifyAdmin($refund, 'api');
            $this->logger->critical('Refund outcome unknown, reconcile before retrying', [
                'reference' => $refund->getReference(),
                'transaction' => $refund->getTransactionReference(),
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('refund_outcome_unknown', 0, $e);
        }

        $gatewayStatus = is_string($data['status'] ?? null) ? $data['status'] : null;
        $status = $this->mapGatewayStatus($gatewayStatus) ?? Refund::STATUS_PENDING;
        $amountMismatch = $this->gatewayAmountMismatch($refund, $data);
        if (null !== $amountMismatch) {
            $this->logAmountMismatch($refund, $data, 'api');
        }
        $refund->markSubmitted(
            isset($data['id']) ? (string) $data['id'] : null,
            null !== $amountMismatch ? Refund::STATUS_NEEDS_ATTENTION : $status,
            $gatewayStatus,
            $data,
            'api',
            $amountMismatch,
        );
        $this->updatePaymentRefundStatus($refund->getPayment());
        $this->entityManager->flush();
        if (null !== $amountMismatch) {
            $this->notifyAdmin($refund, 'api');
        }

        return $refund;
    }

    /**
     * Applies a Paystack `refund.*` webhook. Returns false when no matching refund exists.
     *
     * @param array<string, mixed> $data
     */
    public function applyGatewayWebhook(string $event, array $data): bool
    {
        $gatewayStatus = is_string($data['status'] ?? null) ? $data['status'] : substr($event, strlen('refund.'));
        $status = $this->mapGatewayStatus($gatewayStatus);
        if (null === $status) {
            return false;
        }

        $gatewayId = isset($data['id']) ? (string) $data['id'] : null;
        $refund = null !== $gatewayId ? $this->refundRepository->findOneBy(['refundTransactionNumber' => $gatewayId]) : null;
        $refund ??= $this->findUnresolvedByTransaction($data);

        if (null === $refund) {
            $this->logger->warning('Refund webhook with no matching refund', ['event' => $event, 'id' => $gatewayId]);

            return false;
        }

        $previousStatus = $refund->getStatus();
        $failureReason = Refund::STATUS_FAILED === $status ? 'gateway reported ' . $gatewayStatus : null;
        $amountMismatch = $this->gatewayAmountMismatch($refund, $data);
        if (null !== $amountMismatch) {
            $status = Refund::STATUS_NEEDS_ATTENTION;
            $failureReason = $amountMismatch;
        }
        if (null === $refund->getRefundTransactionNumber() && null !== $gatewayId) {
            // Request had timed out on our side: the webhook tells us it did reach Paystack.
            $refund->markSubmitted($gatewayId, $status, $gatewayStatus, $data, 'webhook', $failureReason);
        } else {
            $refund->applyGatewayStatus($status, $gatewayStatus, $data, 'webhook', $failureReason);
        }
        $this->updatePaymentRefundStatus($refund->getPayment());
        $this->entityManager->flush();
        if ($refund->getStatus() !== $previousStatus) {
            if (null !== $amountMismatch) {
                $this->logAmountMismatch($refund, $data, 'webhook');
            }
            $this->notifyAdmin($refund, 'webhook');
        }

        return true;
    }

    /**
     * Polls Paystack for a refund whose outcome isn't final and applies the status it
     * reports, as a safety net for missed or delayed webhooks.
     *
     * - With a stored gateway id: GET /refund/{id}.
     * - Without one (the create request timed out): scans GET /refund from the row's
     *   creation date for the single unlinked refund on the same transaction and amount,
     *   and links it.
     *
     * Nothing is written when the gateway reports the status we already have, or in dry run.
     *
     * `status` is the new status (or the would-be one in dry run) when the outcome is SYNC_UPDATED.
     *
     * @return array{outcome: string, status: ?string, gatewayStatus: ?string}
     *
     * @throws RuntimeException|TransportExceptionInterface when Paystack can't be reached or answers an error
     */
    public function syncWithGateway(Refund $refund, bool $dryRun = false): array
    {
        return $this->doSyncWithGateway($refund, $dryRun)['result'];
    }

    /**
     * Shares one GET /refund scan, starting at $earliestCreatedAt, between all the rows
     * synced until endSyncRun(), instead of scanning Paystack again for each row that has
     * no gateway id. Paystack lists newest first, so the shared scan sees the same recent
     * refunds as a per-row one.
     */
    public function beginSyncRun(DateTimeImmutable $earliestCreatedAt): void
    {
        $this->runRefundList = ['from' => $earliestCreatedAt->modify(self::SYNC_LOOKBACK), 'items' => null, 'complete' => false];
    }

    public function endSyncRun(): void
    {
        $this->runRefundList = null;
    }

    /**
     * @return array{result: array{outcome: string, status: ?string, gatewayStatus: ?string}, scan: ?array{candidates: list<array<string, mixed>>, otherAmount: bool, complete: bool}}
     */
    private function doSyncWithGateway(Refund $refund, bool $dryRun): array
    {
        if ($refund->isTerminal()) {
            return ['result' => ['outcome' => self::SYNC_UNCHANGED, 'status' => null, 'gatewayStatus' => $refund->getGatewayStatus()], 'scan' => null];
        }

        $storedGatewayId = $refund->getRefundTransactionNumber();
        $scan = null;
        if (null !== $storedGatewayId) {
            $data = $this->paystackService->fetchRefund($storedGatewayId);
        } else {
            $scan = $this->scanGatewayRefunds($refund);
            $data = $this->pickGatewayRefund($refund, $scan['candidates']);
        }

        if (null === $data) {
            return ['result' => ['outcome' => self::SYNC_NOT_FOUND, 'status' => null, 'gatewayStatus' => null], 'scan' => $scan];
        }

        $gatewayStatus = is_string($data['status'] ?? null) ? $data['status'] : null;
        $status = $this->mapGatewayStatus($gatewayStatus);
        if (null === $status) {
            $this->logger->warning('Refund sync: unknown gateway status', [
                'reference' => $refund->getReference(),
                'gatewayStatus' => $gatewayStatus,
            ]);

            return ['result' => ['outcome' => self::SYNC_UNKNOWN_STATUS, 'status' => null, 'gatewayStatus' => $gatewayStatus], 'scan' => $scan];
        }

        $amountMismatch = $this->gatewayAmountMismatch($refund, $data);
        if (null !== $amountMismatch) {
            $status = Refund::STATUS_NEEDS_ATTENTION;
        }

        $foundGatewayId = isset($data['id']) ? (string) $data['id'] : null;
        $linksGatewayId = null === $storedGatewayId && null !== $foundGatewayId;
        if ($status === $refund->getStatus() && !$linksGatewayId) {
            return ['result' => ['outcome' => self::SYNC_UNCHANGED, 'status' => null, 'gatewayStatus' => $gatewayStatus], 'scan' => $scan];
        }

        if (!$dryRun) {
            $previousStatus = $refund->getStatus();
            $failureReason = $amountMismatch ?? (Refund::STATUS_FAILED === $status ? 'gateway reported ' . $gatewayStatus : null);
            if ($linksGatewayId) {
                // The create request timed out on our side, but it did reach Paystack.
                $refund->markSubmitted($foundGatewayId, $status, $gatewayStatus, $data, 'sync', $failureReason);
            } else {
                $refund->applyGatewayStatus($status, $gatewayStatus, $data, 'sync', $failureReason);
            }
            $this->updatePaymentRefundStatus($refund->getPayment());
            $this->entityManager->flush();
            // Linking the gateway id alone isn't news for the admin.
            if ($refund->getStatus() !== $previousStatus) {
                if (null !== $amountMismatch) {
                    $this->logAmountMismatch($refund, $data, 'sync');
                }
                $this->notifyAdmin($refund, 'sync');
            }
        }

        return ['result' => ['outcome' => self::SYNC_UPDATED, 'status' => $status, 'gatewayStatus' => $gatewayStatus], 'scan' => $scan];
    }

    /**
     * Starts a new gateway refund for a product return whose last attempt failed
     * (refused at creation, or reported failed later by a webhook or the sync).
     * Same payment and amounts; the failed row stays as it is.
     *
     * @throws LogicException   when the refund can't be retried (nothing written)
     * @throws RuntimeException when the gateway refused the retry or its outcome is unknown
     */
    public function retryRefund(Refund $failed, User $initiatedBy): Refund
    {
        if (Refund::STATUS_FAILED !== $failed->getStatus()) {
            throw new LogicException('refund_not_failed');
        }
        $return = $failed->getProductReturn();
        if (null === $return) {
            throw new LogicException('refund_retry_not_supported');
        }

        return $this->refundProductReturn(
            $return,
            $failed->getPayment(),
            $initiatedBy,
            $failed->getItemsAmount(),
            $failed->getShippingAmount(),
            $failed->getTaxAmount(),
        );
    }

    /**
     * Resolves a `needs_attention` refund (the gateway call timed out, or Paystack
     * flagged it). Paystack is always asked first:
     * - `sync`: only apply what Paystack reports.
     * - `processed`: if Paystack still doesn't settle it, record it as processed
     *   (the admin confirmed the money reached the customer).
     * - `failed`: allowed only when Paystack has no refund at all on the transaction
     *   (any amount) and the whole date range could be scanned, so the amount is
     *   released and a retry can't pay the customer twice.
     *
     * @return array{outcome: string, refund: Refund}
     *
     * @throws LogicException                               when the refund isn't awaiting reconciliation, or Paystack has a refund for it
     * @throws InvalidArgumentException                     on an unknown resolution or a missing note
     * @throws RuntimeException|TransportExceptionInterface when Paystack can't be reached
     */
    public function reconcile(Refund $refund, string $resolution, User $resolvedBy, ?string $note = null): array
    {
        if (Refund::STATUS_NEEDS_ATTENTION !== $refund->getStatus()) {
            throw new LogicException('refund_not_awaiting_reconciliation');
        }
        if (!in_array($resolution, [self::RESOLUTION_SYNC, self::RESOLUTION_PROCESSED, self::RESOLUTION_FAILED], true)) {
            throw new InvalidArgumentException('refund_resolution_invalid');
        }
        $note = null !== $note ? trim($note) : '';
        if (self::RESOLUTION_SYNC !== $resolution && '' === $note) {
            throw new InvalidArgumentException('refund_resolution_note_required');
        }

        ['result' => $result, 'scan' => $scan] = $this->doSyncWithGateway($refund, false);
        if (self::SYNC_UPDATED === $result['outcome'] && Refund::STATUS_NEEDS_ATTENTION !== $result['status']) {
            return ['outcome' => self::RECONCILE_RESOLVED_BY_GATEWAY, 'refund' => $refund];
        }
        if (self::RESOLUTION_SYNC === $resolution) {
            return ['outcome' => self::RECONCILE_STILL_UNRESOLVED, 'refund' => $refund];
        }

        if (self::RESOLUTION_FAILED === $resolution) {
            // An incomplete scan (page cap reached) proves nothing: refuse rather than risk a double refund.
            $onGateway = null !== $refund->getRefundTransactionNumber()
                || self::SYNC_NOT_FOUND !== $result['outcome']
                || null === $scan
                || [] !== $scan['candidates']
                || $scan['otherAmount']
                || !$scan['complete'];
            if ($onGateway) {
                throw new LogicException('refund_exists_on_gateway');
            }
        }

        $refund->resolveManually(
            self::RESOLUTION_PROCESSED === $resolution ? Refund::STATUS_PROCESSED : Refund::STATUS_FAILED,
            $resolvedBy,
            $note,
        );
        $this->updatePaymentRefundStatus($refund->getPayment());
        $this->entityManager->flush();
        $this->notifyAdmin($refund, 'manual');

        return ['outcome' => self::RECONCILE_RESOLVED_MANUALLY, 'refund' => $refund];
    }

    /**
     * The single candidate from scanGatewayRefunds(), or null when none or ambiguous.
     *
     * @param list<array<string, mixed>> $candidates
     *
     * @return array<string, mixed>|null
     */
    private function pickGatewayRefund(Refund $refund, array $candidates): ?array
    {
        if (count($candidates) > 1) {
            $this->logger->warning('Refund sync: several gateway refunds match, reconcile manually', [
                'reference' => $refund->getReference(),
                'candidates' => array_map(static fn (array $c): mixed => $c['id'], $candidates),
            ]);
        }

        return 1 === count($candidates) ? $candidates[0] : null;
    }

    /**
     * Unlinked Paystack refunds created since the row (minus clock skew) on the same
     * transaction: `candidates` have the same amount, `otherAmount` tells whether one
     * with a different amount exists, `complete` whether the whole date range was read
     * (false when the page cap was hit).
     *
     * @return array{candidates: list<array<string, mixed>>, otherAmount: bool, complete: bool}
     */
    private function scanGatewayRefunds(Refund $refund): array
    {
        $from = $refund->getCreatedAt()->modify(self::SYNC_LOOKBACK);
        ['items' => $items, 'complete' => $complete] = $this->listGatewayRefunds($from);

        $candidates = [];
        $otherAmount = false;
        foreach ($items as $item) {
            $reference = $item['transaction_reference'] ?? ($item['transaction']['reference'] ?? null);
            if ($reference !== $refund->getTransactionReference() || !isset($item['id'])) {
                continue;
            }
            if (null !== $this->refundRepository->findOneBy(['refundTransactionNumber' => (string) $item['id']])) {
                continue;
            }
            if (PaystackService::fromSubunit($item['amount'] ?? null) === $refund->getAmount()) {
                $candidates[] = $item;
            } else {
                $otherAmount = true;
            }
        }

        return ['candidates' => $candidates, 'otherAmount' => $otherAmount, 'complete' => $complete];
    }

    /**
     * Paystack refunds created since $from, newest first, up to SYNC_MAX_LIST_PAGES pages.
     * Reuses the sync run's shared list when it covers $from.
     *
     * @return array{items: list<array<string, mixed>>, complete: bool}
     */
    private function listGatewayRefunds(DateTimeImmutable $from): array
    {
        if (null === $this->runRefundList || $this->runRefundList['from'] > $from) {
            return $this->fetchGatewayRefundPages($from);
        }

        if (null === $this->runRefundList['items']) {
            $fetched = $this->fetchGatewayRefundPages($this->runRefundList['from']);
            $this->runRefundList['items'] = $fetched['items'];
            $this->runRefundList['complete'] = $fetched['complete'];
        }

        $items = array_values(array_filter(
            $this->runRefundList['items'],
            static function (array $item) use ($from): bool {
                $createdAt = $item['createdAt'] ?? ($item['created_at'] ?? null);
                if (!is_string($createdAt)) {
                    return true;
                }
                try {
                    return new DateTimeImmutable($createdAt) >= $from;
                } catch (Throwable) {
                    return true;
                }
            },
        ));

        return ['items' => $items, 'complete' => $this->runRefundList['complete']];
    }

    /**
     * @return array{items: list<array<string, mixed>>, complete: bool}
     */
    private function fetchGatewayRefundPages(DateTimeImmutable $from): array
    {
        $items = [];
        for ($page = 1; $page <= self::SYNC_MAX_LIST_PAGES; ++$page) {
            $result = $this->paystackService->listRefunds($from, null, $page);
            foreach ($result['data'] as $item) {
                $items[] = $item;
            }
            if ($page >= $result['pageCount']) {
                return ['items' => $items, 'complete' => true];
            }
        }

        return ['items' => $items, 'complete' => false];
    }

    /**
     * Fallback match for a webhook whose refund id we never stored (timed-out request):
     * the single unresolved refund on that transaction with the same amount.
     *
     * @param array<string, mixed> $data
     */
    private function findUnresolvedByTransaction(array $data): ?Refund
    {
        $transactionReference = $data['transaction_reference'] ?? ($data['transaction']['reference'] ?? null);
        if (!is_string($transactionReference) || !is_numeric($data['amount'] ?? null)) {
            return null;
        }
        $amount = PaystackService::fromSubunit($data['amount']);

        $candidates = array_values(array_filter(
            $this->refundRepository->findBy(['transactionReference' => $transactionReference]),
            static fn (Refund $refund): bool => !$refund->isTerminal()
                && null === $refund->getRefundTransactionNumber()
                && $refund->getAmount() === $amount,
        ));

        return 1 === count($candidates) ? $candidates[0] : null;
    }

    /**
     * Paystack reports amounts in the currency subunit (57285 XOF comes back as 5728500).
     * Returns why the amount it reports differs from ours, or null when it matches or
     * isn't reported. A mismatch sends the refund to `needs_attention` for an admin.
     *
     * @param array<string, mixed> $data
     */
    private function gatewayAmountMismatch(Refund $refund, array $data): ?string
    {
        $gatewayAmount = PaystackService::fromSubunit($data['amount'] ?? null);
        if (null === $gatewayAmount || $gatewayAmount === $refund->getAmount()) {
            return null;
        }

        return sprintf(
            'gateway amount %d %s differs from refund amount %d %s',
            $gatewayAmount,
            is_string($data['currency'] ?? null) ? $data['currency'] : $refund->getCurrency(),
            $refund->getAmount(),
            $refund->getCurrency(),
        );
    }

    /**
     * Critical alert for a mismatch, logged only when it moves the refund to
     * needs_attention, not on every webhook redelivery or sync.
     *
     * @param array<string, mixed> $data
     */
    private function logAmountMismatch(Refund $refund, array $data, string $source): void
    {
        $this->logger->critical('Refund amount mismatch with gateway', [
            'reference' => $refund->getReference(),
            'source' => $source,
            'gatewayAmountSubunit' => $data['amount'] ?? null,
            'gatewayAmount' => PaystackService::fromSubunit($data['amount'] ?? null),
            'refundAmount' => $refund->getAmount(),
        ]);
    }

    /**
     * Marks the payment `refunded` once its processed refunds cover the whole amount
     * collected. A partial refund leaves the payment as it is.
     */
    private function updatePaymentRefundStatus(Payment $payment): void
    {
        $collected = null !== $payment->getAmount() ? (int) floor((float) $payment->getAmount()) : 0;
        if ($collected <= 0) {
            // No recorded amount: never infer a full refund.
            return;
        }

        $processed = 0;
        foreach ($payment->getRefunds() as $refund) {
            if (Refund::STATUS_PROCESSED === $refund->getStatus()) {
                $processed += $refund->getAmount();
            }
        }

        if ($processed >= $collected) {
            $payment->setStatus(Payment::STATUS_REFUNDED);
        }
    }

    /**
     * Queues the admin email about a refund's new status (only on a change, or when the
     * gateway refused or timed out at creation). Called after flush so the worker reads
     * the committed row. A Messenger failure is logged and never breaks the refund flow:
     * the row is already saved.
     */
    private function notifyAdmin(Refund $refund, string $source): void
    {
        $refundId = $refund->getId();
        if (null === $refundId) {
            return;
        }

        try {
            $this->bus->dispatch(new RefundNotificationMessage($refundId, $refund->getStatus(), $source));
        } catch (Throwable $e) {
            $this->logger->error('Refund notification could not be queued', [
                'reference' => $refund->getReference(),
                'status' => $refund->getStatus(),
                'source' => $source,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function mapGatewayStatus(?string $gatewayStatus): ?string
    {
        return null === $gatewayStatus ? null : (self::GATEWAY_STATUS_MAP[strtolower($gatewayStatus)] ?? null);
    }

    /**
     * Deterministic per attempt (RF-<return uid>-<n>) so two concurrent requests collide on
     * the unique index instead of both reaching the gateway.
     */
    private function buildReference(ProductReturn $return): string
    {
        $reference = 'RF-' . $return->getUid() . '-' . ($return->getRefunds()->count() + 1);

        return strlen($reference) <= 64 ? $reference : 'RF-' . substr(hash('sha256', $reference), 0, 40);
    }
}
