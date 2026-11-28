<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Payment;
use App\Entity\ProductReturn;
use App\Entity\Refund;
use App\Entity\User;
use App\Message\RefundNotificationMessage;
use App\Repository\RefundRepository;
use InvalidArgumentException;
use App\Service\PaystackService;
use App\Service\RefundService;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use LogicException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Covers RefundService::refundProductReturn() (gateway accepted / refused / unknown) and
 * RefundService::applyGatewayWebhook() (status moves, timed-out request matching), plus
 * which of them queue the admin RefundNotificationMessage.
 */
#[AllowMockObjectsWithoutExpectations]
final class RefundServiceGatewayTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private RefundRepository&MockObject $repository;
    private PaystackService&MockObject $paystack;
    private MessageBusInterface&MockObject $bus;
    private RefundService $service;

    /** @var list<RefundNotificationMessage> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->dispatched = [];
        $this->em = $this->createMock(EntityManagerInterface::class);
        // Mimic Doctrine assigning the id on persist, so notifications can be dispatched.
        $this->em->method('persist')->willReturnCallback(static function (object $entity): void {
            if ($entity instanceof Refund && null === $entity->getId()) {
                (new ReflectionProperty(Refund::class, 'id'))->setValue($entity, 42);
            }
        });
        $this->repository = $this->createMock(RefundRepository::class);
        $this->paystack = $this->createMock(PaystackService::class);
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            if ($message instanceof RefundNotificationMessage) {
                $this->dispatched[] = $message;
            }

            return new Envelope($message);
        });
        $this->service = new RefundService($this->em, $this->repository, $this->paystack, new NullLogger(), $this->bus);
    }

    // --- refundProductReturn() ---

    public function testAcceptedRefundIsSubmittedWithoutNotification(): void
    {
        $payment = $this->payment();
        $this->paystack->expects(self::once())->method('refund')
            ->with('TRX-1', 5000, 'XOF', self::anything())
            ->willReturn(['id' => 'rfd_9', 'status' => 'pending']);

        $refund = $this->service->refundProductReturn($this->productReturn(), $payment, new User(), 5000, 0, 0);

        self::assertSame(Refund::STATUS_PENDING, $refund->getStatus());
        self::assertSame('rfd_9', $refund->getRefundTransactionNumber());
        self::assertNotNull($refund->getSubmittedAt());
        self::assertSame([], $this->dispatched);
    }

    public function testRefusedRefundIsMarkedFailedAndNotifies(): void
    {
        $this->paystack->method('refund')->willThrowException(new DomainException('Paystack refused the refund: nope'));
        $return = $this->productReturn();

        try {
            $this->service->refundProductReturn($return, $this->payment(), new User(), 5000, 0, 0);
            self::fail('Expected refund_rejected');
        } catch (RuntimeException $e) {
            self::assertSame('refund_rejected', $e->getMessage());
        }

        $refund = $return->getRefunds()->first();
        self::assertInstanceOf(Refund::class, $refund);
        self::assertSame(Refund::STATUS_FAILED, $refund->getStatus());
        self::assertNotNull($refund->getFailedAt());
        $this->assertNotified(Refund::STATUS_FAILED, 'api');
    }

    public function testUnknownOutcomeNeedsAttentionAndNotifies(): void
    {
        $this->paystack->method('refund')->willThrowException(new RuntimeException('Paystack refund outcome unknown (HTTP 504)'));
        $return = $this->productReturn();

        try {
            $this->service->refundProductReturn($return, $this->payment(), new User(), 5000, 0, 0);
            self::fail('Expected refund_outcome_unknown');
        } catch (RuntimeException $e) {
            self::assertSame('refund_outcome_unknown', $e->getMessage());
        }

        $refund = $return->getRefunds()->first();
        self::assertInstanceOf(Refund::class, $refund);
        self::assertSame(Refund::STATUS_NEEDS_ATTENTION, $refund->getStatus());
        self::assertTrue($refund->isActive(), 'An unknown outcome must keep holding the amount');
        $this->assertNotified(Refund::STATUS_NEEDS_ATTENTION, 'api');
    }

    public function testRefundAboveRefundableAmountIsRefusedBeforeTheGateway(): void
    {
        $this->paystack->expects(self::never())->method('refund');
        $this->em->expects(self::never())->method('flush');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('refund_exceeds_refundable_amount');

        $this->service->refundProductReturn($this->productReturn(), $this->payment(), new User(), 10002, 0, 0);
    }

    public function testOneUnitRoundingOvershootIsAbsorbedOnItems(): void
    {
        $this->paystack->method('refund')->willReturn(['id' => 'rfd_10', 'status' => 'processing']);

        $refund = $this->service->refundProductReturn($this->productReturn(), $this->payment(), new User(), 9001, 1000, 0);

        self::assertSame(10000, $refund->getAmount());
        self::assertSame(9000, $refund->getItemsAmount());
    }

    public function testSecondRefundForReturnWithActiveRefundIsRefused(): void
    {
        $return = $this->productReturn();
        $this->paystack->method('refund')->willReturn(['id' => 'rfd_11', 'status' => 'pending']);
        $this->service->refundProductReturn($return, $this->payment(), new User(), 1000, 0, 0);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('refund_already_exists_for_return');

        $this->service->refundProductReturn($return, $this->payment(), new User(), 1000, 0, 0);
    }

    // --- applyGatewayWebhook() ---

    public function testWebhookMovesStatusAndNotifies(): void
    {
        $refund = $this->submittedRefund('rfd_1', Refund::STATUS_PENDING);
        $this->repository->expects(self::once())->method('findOneBy')->with(['refundTransactionNumber' => 'rfd_1'])->willReturn($refund);
        $this->em->expects(self::once())->method('flush');

        self::assertTrue($this->service->applyGatewayWebhook('refund.processed', ['id' => 'rfd_1', 'status' => 'processed']));

        self::assertSame(Refund::STATUS_PROCESSED, $refund->getStatus());
        self::assertSame('webhook', $refund->getStatusHistory()[array_key_last($refund->getStatusHistory())]['source']);
        $this->assertNotified(Refund::STATUS_PROCESSED, 'webhook');
    }

    public function testWebhookWithSameStatusDoesNotNotify(): void
    {
        $refund = $this->submittedRefund('rfd_2', Refund::STATUS_PENDING);
        $this->repository->method('findOneBy')->willReturn($refund);

        self::assertTrue($this->service->applyGatewayWebhook('refund.pending', ['id' => 'rfd_2', 'status' => 'pending']));

        self::assertSame(Refund::STATUS_PENDING, $refund->getStatus());
        self::assertSame([], $this->dispatched);
    }

    public function testWebhookOnTerminalRefundChangesNothingAndDoesNotNotify(): void
    {
        $refund = $this->submittedRefund('rfd_3', Refund::STATUS_PROCESSED);
        $this->repository->method('findOneBy')->willReturn($refund);

        self::assertTrue($this->service->applyGatewayWebhook('refund.failed', ['id' => 'rfd_3', 'status' => 'failed']));

        self::assertSame(Refund::STATUS_PROCESSED, $refund->getStatus());
        self::assertNull($refund->getFailedAt());
        self::assertSame([], $this->dispatched);
    }

    public function testWebhookFailedRecordsReason(): void
    {
        $refund = $this->submittedRefund('rfd_4', Refund::STATUS_PROCESSING);
        $this->repository->method('findOneBy')->willReturn($refund);

        $this->service->applyGatewayWebhook('refund.failed', ['id' => 'rfd_4', 'status' => 'failed']);

        self::assertSame(Refund::STATUS_FAILED, $refund->getStatus());
        self::assertSame('gateway reported failed', $refund->getFailureReason());
        $this->assertNotified(Refund::STATUS_FAILED, 'webhook');
    }

    public function testWebhookStatusFallsBackToEventName(): void
    {
        $refund = $this->submittedRefund('rfd_5', Refund::STATUS_PENDING);
        $this->repository->method('findOneBy')->willReturn($refund);

        $this->service->applyGatewayWebhook('refund.processing', ['id' => 'rfd_5']);

        self::assertSame(Refund::STATUS_PROCESSING, $refund->getStatus());
    }

    public function testWebhookLinksTimedOutRefundByTransactionAndAmount(): void
    {
        $refund = $this->newRefund();
        $refund->applyGatewayStatus(Refund::STATUS_NEEDS_ATTENTION, null, ['error' => 'timeout'], 'api', 'timeout');
        $this->repository->method('findOneBy')->willReturn(null);
        $this->repository->expects(self::once())->method('findBy')->with(['transactionReference' => 'TRX-1'])->willReturn([$refund]);

        $handled = $this->service->applyGatewayWebhook('refund.processed', [
            'id' => 'rfd_6',
            'status' => 'processed',
            'transaction_reference' => 'TRX-1',
            'amount' => 500000, // subunit
        ]);

        self::assertTrue($handled);
        self::assertSame('rfd_6', $refund->getRefundTransactionNumber());
        self::assertSame(Refund::STATUS_PROCESSED, $refund->getStatus());
    }

    public function testWebhookDoesNotGuessBetweenSeveralTimedOutRefunds(): void
    {
        $first = $this->newRefund();
        $second = $this->newRefund();
        $this->repository->method('findOneBy')->willReturn(null);
        $this->repository->method('findBy')->willReturn([$first, $second]);
        $this->em->expects(self::never())->method('flush');

        $handled = $this->service->applyGatewayWebhook('refund.processed', [
            'id' => 'rfd_7',
            'status' => 'processed',
            'transaction_reference' => 'TRX-1',
            'amount' => 500000,
        ]);

        self::assertFalse($handled);
        self::assertNull($first->getRefundTransactionNumber());
        self::assertNull($second->getRefundTransactionNumber());
    }

    public function testWebhookForUnknownRefundIsIgnored(): void
    {
        $this->repository->method('findOneBy')->willReturn(null);
        $this->repository->method('findBy')->willReturn([]);
        $this->em->expects(self::never())->method('flush');

        self::assertFalse($this->service->applyGatewayWebhook('refund.processed', ['id' => 'rfd_x', 'status' => 'processed']));
        self::assertSame([], $this->dispatched);
    }

    public function testWebhookWithUnmappedStatusIsIgnored(): void
    {
        $this->repository->expects(self::never())->method('findOneBy');

        self::assertFalse($this->service->applyGatewayWebhook('refund.reversed', ['id' => 'rfd_y', 'status' => 'reversed']));
    }

    // --- payment status ---

    public function testFullRefundProcessedByWebhookMarksPaymentRefunded(): void
    {
        $payment = $this->payment();
        $this->paystack->method('refund')->willReturn(['id' => 'rfd_20', 'status' => 'pending']);
        $refund = $this->service->refundProductReturn($this->productReturn(), $payment, new User(), 10000, 0, 0);
        self::assertSame(Payment::STATUS_COMPLETED, $payment->getStatus());
        $this->repository->method('findOneBy')->willReturn($refund);

        $this->service->applyGatewayWebhook('refund.processed', ['id' => 'rfd_20', 'status' => 'processed']);

        self::assertSame(Payment::STATUS_REFUNDED, $payment->getStatus());
    }

    public function testPartialRefundLeavesPaymentCompleted(): void
    {
        $payment = $this->payment();
        $this->paystack->method('refund')->willReturn(['id' => 'rfd_21', 'status' => 'processed']);

        $this->service->refundProductReturn($this->productReturn(), $payment, new User(), 4000, 0, 0);

        self::assertSame(Payment::STATUS_COMPLETED, $payment->getStatus());
    }

    public function testFullRefundProcessedImmediatelyMarksPaymentRefunded(): void
    {
        $payment = $this->payment();
        $this->paystack->method('refund')->willReturn(['id' => 'rfd_22', 'status' => 'processed']);

        $this->service->refundProductReturn($this->productReturn(), $payment, new User(), 10000, 0, 0);

        self::assertSame(Payment::STATUS_REFUNDED, $payment->getStatus());
    }

    // --- retryRefund() ---

    public function testRetryCreatesNewRefundWithSameAmounts(): void
    {
        $failed = $this->failedReturnRefund();
        $this->paystack->expects(self::once())->method('refund')->willReturn(['id' => 'rfd_30', 'status' => 'pending']);

        $retry = $this->service->retryRefund($failed, new User());

        self::assertNotSame($failed, $retry);
        self::assertSame(Refund::STATUS_FAILED, $failed->getStatus());
        self::assertSame(Refund::STATUS_PENDING, $retry->getStatus());
        self::assertSame($failed->getAmount(), $retry->getAmount());
        self::assertSame($failed->getPayment(), $retry->getPayment());
        self::assertNotSame($failed->getReference(), $retry->getReference());
    }

    public function testRetryOfRefundThatIsNotFailedIsRefused(): void
    {
        $this->paystack->expects(self::never())->method('refund');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('refund_not_failed');

        $this->service->retryRefund($this->submittedRefund('rfd_31', Refund::STATUS_PROCESSING), new User());
    }

    public function testRetryIsRefusedWhileAnotherRefundIsActive(): void
    {
        $failed = $this->failedReturnRefund();
        $this->paystack->method('refund')->willReturn(['id' => 'rfd_32', 'status' => 'pending']);
        $this->service->retryRefund($failed, new User());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('refund_already_exists_for_return');

        $this->service->retryRefund($failed, new User());
    }

    // --- reconcile() ---

    public function testReconcileAppliesWhatPaystackReports(): void
    {
        $refund = $this->needsAttentionRefund('rfd_40');
        $this->paystack->expects(self::once())->method('fetchRefund')->with('rfd_40')->willReturn(['id' => 'rfd_40', 'status' => 'processed']);

        $result = $this->service->reconcile($refund, RefundService::RESOLUTION_FAILED, new User(), 'checked dashboard');

        self::assertSame(RefundService::RECONCILE_RESOLVED_BY_GATEWAY, $result['outcome']);
        self::assertSame(Refund::STATUS_PROCESSED, $refund->getStatus(), 'Paystack wins over the manual resolution');
    }

    public function testReconcileSyncLeavesRefundWhenPaystackStillUnresolved(): void
    {
        $refund = $this->needsAttentionRefund('rfd_41');
        $this->paystack->method('fetchRefund')->willReturn(['id' => 'rfd_41', 'status' => 'needs-attention']);

        $result = $this->service->reconcile($refund, RefundService::RESOLUTION_SYNC, new User());

        self::assertSame(RefundService::RECONCILE_STILL_UNRESOLVED, $result['outcome']);
        self::assertSame(Refund::STATUS_NEEDS_ATTENTION, $refund->getStatus());
    }

    public function testReconcileAsProcessedWhenPaystackStillUnresolved(): void
    {
        $refund = $this->needsAttentionRefund('rfd_42');
        $this->paystack->method('fetchRefund')->willReturn(['id' => 'rfd_42', 'status' => 'needs-attention']);

        $result = $this->service->reconcile($refund, RefundService::RESOLUTION_PROCESSED, new User(), 'customer confirmed receipt');

        self::assertSame(RefundService::RECONCILE_RESOLVED_MANUALLY, $result['outcome']);
        self::assertSame(Refund::STATUS_PROCESSED, $refund->getStatus());
        self::assertNotNull($refund->getProcessedAt());
        $last = $refund->getStatusHistory()[array_key_last($refund->getStatusHistory())];
        self::assertSame('admin', $last['source']);
        self::assertSame('customer confirmed receipt', $last['note'] ?? null);
        self::assertSame(['error' => 'timeout'], $refund->getGatewayResponse(), 'Gateway response is kept');
    }

    public function testReconcileAsFailedWhenPaystackHasNoRefund(): void
    {
        $refund = $this->needsAttentionRefund(null);
        $this->paystack->method('listRefunds')->willReturn(['data' => [], 'pageCount' => 1]);

        $result = $this->service->reconcile($refund, RefundService::RESOLUTION_FAILED, new User(), 'not on Paystack dashboard');

        self::assertSame(RefundService::RECONCILE_RESOLVED_MANUALLY, $result['outcome']);
        self::assertSame(Refund::STATUS_FAILED, $refund->getStatus());
        self::assertFalse($refund->isActive(), 'The amount is released for a retry');
        self::assertSame('resolved by admin: not on Paystack dashboard', $refund->getFailureReason());
    }

    public function testReconcileAsFailedIsRefusedWhenPaystackHasTheRefund(): void
    {
        $refund = $this->needsAttentionRefund(null);
        $this->paystack->method('listRefunds')->willReturn(['data' => [[
            'id' => 'rfd_43',
            'status' => 'needs-attention',
            'transaction_reference' => 'TRX-1',
            'amount' => 500000,
        ]], 'pageCount' => 1]);
        $this->repository->method('findOneBy')->willReturn(null);

        try {
            $this->service->reconcile($refund, RefundService::RESOLUTION_FAILED, new User(), 'looks failed');
            self::fail('Expected refund_exists_on_gateway');
        } catch (LogicException $e) {
            self::assertSame('refund_exists_on_gateway', $e->getMessage());
        }

        self::assertSame(Refund::STATUS_NEEDS_ATTENTION, $refund->getStatus());
        self::assertSame('rfd_43', $refund->getRefundTransactionNumber(), 'The sync still links the gateway refund');
    }

    public function testReconcileAsFailedIsRefusedWhenSeveralPaystackRefundsMatch(): void
    {
        $refund = $this->needsAttentionRefund(null);
        $item = ['status' => 'processed', 'transaction_reference' => 'TRX-1', 'amount' => 500000];
        $this->paystack->method('listRefunds')->willReturn([
            'data' => [['id' => 'rfd_44'] + $item, ['id' => 'rfd_45'] + $item],
            'pageCount' => 1,
        ]);
        $this->repository->method('findOneBy')->willReturn(null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('refund_exists_on_gateway');

        $this->service->reconcile($refund, RefundService::RESOLUTION_FAILED, new User(), 'looks failed');
    }

    public function testReconcileAsFailedIsRefusedWhenScanHitsThePageCap(): void
    {
        $refund = $this->needsAttentionRefund(null);
        // Every page holds unrelated refunds and more pages remain than the scan reads.
        $this->paystack->expects(self::exactly(10))->method('listRefunds')->willReturn([
            'data' => [['id' => 'rfd_x', 'transaction_reference' => 'OTHER', 'amount' => 100]],
            'pageCount' => 50,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('refund_exists_on_gateway');

        $this->service->reconcile($refund, RefundService::RESOLUTION_FAILED, new User(), 'looks failed');
    }

    public function testReconcileAsFailedIsRefusedWhenPaystackHasARefundOfAnotherAmount(): void
    {
        $refund = $this->needsAttentionRefund(null);
        $this->paystack->method('listRefunds')->willReturn(['data' => [[
            'id' => 'rfd_46',
            'status' => 'processed',
            'transaction_reference' => 'TRX-1',
            'amount' => 450000,
        ]], 'pageCount' => 1]);
        $this->repository->method('findOneBy')->willReturn(null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('refund_exists_on_gateway');

        $this->service->reconcile($refund, RefundService::RESOLUTION_FAILED, new User(), 'looks failed');
    }

    public function testReconcileAsFailedScansPaystackOnlyOnce(): void
    {
        $refund = $this->needsAttentionRefund(null);
        $this->paystack->expects(self::once())->method('listRefunds')->willReturn(['data' => [], 'pageCount' => 1]);

        $this->service->reconcile($refund, RefundService::RESOLUTION_FAILED, new User(), 'not on Paystack dashboard');

        self::assertSame(Refund::STATUS_FAILED, $refund->getStatus());
    }

    public function testManualReconcileNotifiesAdmin(): void
    {
        $refund = $this->needsAttentionRefund('rfd_47');
        $this->paystack->method('fetchRefund')->willReturn(['id' => 'rfd_47', 'status' => 'needs-attention']);

        $this->service->reconcile($refund, RefundService::RESOLUTION_PROCESSED, new User(), 'customer confirmed receipt');

        $this->assertNotified(Refund::STATUS_PROCESSED, 'manual');
    }

    public function testSyncThatOnlyLinksGatewayIdDoesNotNotify(): void
    {
        $refund = $this->needsAttentionRefund(null);
        $this->paystack->method('listRefunds')->willReturn(['data' => [[
            'id' => 'rfd_48',
            'status' => 'needs-attention',
            'transaction_reference' => 'TRX-1',
            'amount' => 500000,
        ]], 'pageCount' => 1]);
        $this->repository->method('findOneBy')->willReturn(null);

        $result = $this->service->syncWithGateway($refund);

        self::assertSame(RefundService::SYNC_UPDATED, $result['outcome']);
        self::assertSame('rfd_48', $refund->getRefundTransactionNumber());
        self::assertSame([], $this->dispatched);
    }

    public function testSyncRunSharesOnePaystackScan(): void
    {
        $first = $this->needsAttentionRefund(null);
        $second = $this->needsAttentionRefund(null);
        $this->paystack->expects(self::once())->method('listRefunds')->willReturn(['data' => [], 'pageCount' => 1]);

        $this->service->beginSyncRun($first->getCreatedAt());
        try {
            self::assertSame(RefundService::SYNC_NOT_FOUND, $this->service->syncWithGateway($first)['outcome']);
            self::assertSame(RefundService::SYNC_NOT_FOUND, $this->service->syncWithGateway($second)['outcome']);
        } finally {
            $this->service->endSyncRun();
        }
    }

    public function testBrokerFailureDoesNotChangeTheRefundError(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new TransportException('broker down'));
        $service = new RefundService($this->em, $this->repository, $this->paystack, new NullLogger(), $bus);
        $this->paystack->method('refund')->willThrowException(new DomainException('Transaction has been fully reversed'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refund_rejected');

        $service->refundProductReturn($this->productReturn(), $this->payment(), new User(), 5000, 0, 0);
    }

    public function testPaymentWithoutAmountIsNeverMarkedRefunded(): void
    {
        $payment = $this->payment();
        (new ReflectionProperty(Payment::class, 'amount'))->setValue($payment, null);
        $refund = new Refund(
            reference: 'RF-NOAMT',
            payment: $payment,
            initiatedBy: new User(),
            reason: Refund::REASON_PRODUCT_RETURN,
            itemsAmount: 5000,
            shippingAmount: 0,
            taxAmount: 0,
        );
        $refund->markSubmitted('rfd_49', Refund::STATUS_PROCESSING, 'processing', []);
        $this->repository->method('findOneBy')->willReturn($refund);

        $this->service->applyGatewayWebhook('refund.processed', ['id' => 'rfd_49', 'status' => 'processed']);

        self::assertSame(Refund::STATUS_PROCESSED, $refund->getStatus());
        self::assertSame(Payment::STATUS_COMPLETED, $payment->getStatus());
    }

    public function testManualReconcileRequiresNote(): void
    {
        $this->paystack->expects(self::never())->method('fetchRefund');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('refund_resolution_note_required');

        $this->service->reconcile($this->needsAttentionRefund('rfd_46'), RefundService::RESOLUTION_PROCESSED, new User(), '  ');
    }

    public function testReconcileOfRefundNotAwaitingReconciliationIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('refund_not_awaiting_reconciliation');

        $this->service->reconcile($this->submittedRefund('rfd_47', Refund::STATUS_PENDING), RefundService::RESOLUTION_SYNC, new User());
    }

    // --- gateway amount (Paystack reports the subunit, x100) ---

    public function testSubunitConversion(): void
    {
        self::assertSame(57285, PaystackService::fromSubunit(5728500));
        self::assertSame(57285, PaystackService::fromSubunit('5728500'));
        self::assertNull(PaystackService::fromSubunit(null));
        self::assertNull(PaystackService::fromSubunit('abc'));
    }

    public function testMatchingSubunitAmountIsAccepted(): void
    {
        $this->paystack->method('refund')->willReturn(['id' => 'rfd_50', 'status' => 'pending', 'amount' => 500000, 'currency' => 'XOF']);

        $refund = $this->service->refundProductReturn($this->productReturn(), $this->payment(), new User(), 5000, 0, 0);

        self::assertSame(Refund::STATUS_PENDING, $refund->getStatus());
        self::assertSame([], $this->dispatched);
    }

    public function testDifferentAmountAtCreationNeedsAttentionAndNotifies(): void
    {
        $this->paystack->method('refund')->willReturn(['id' => 'rfd_51', 'status' => 'pending', 'amount' => 50000, 'currency' => 'XOF']);

        $refund = $this->service->refundProductReturn($this->productReturn(), $this->payment(), new User(), 5000, 0, 0);

        self::assertSame(Refund::STATUS_NEEDS_ATTENTION, $refund->getStatus());
        self::assertSame('rfd_51', $refund->getRefundTransactionNumber());
        self::assertSame('gateway amount 500 XOF differs from refund amount 5000 XOF', $refund->getFailureReason());
        $this->assertNotified(Refund::STATUS_NEEDS_ATTENTION, 'api');
    }

    public function testDifferentAmountInWebhookIsNotMarkedProcessed(): void
    {
        $payment = $this->payment();
        $this->paystack->method('refund')->willReturn(['id' => 'rfd_52', 'status' => 'pending', 'amount' => 1000000]);
        $refund = $this->service->refundProductReturn($this->productReturn(), $payment, new User(), 10000, 0, 0);
        $this->repository->method('findOneBy')->willReturn($refund);

        $this->service->applyGatewayWebhook('refund.processed', ['id' => 'rfd_52', 'status' => 'processed', 'amount' => 900000]);

        self::assertSame(Refund::STATUS_NEEDS_ATTENTION, $refund->getStatus());
        self::assertNull($refund->getProcessedAt());
        self::assertSame(Payment::STATUS_COMPLETED, $payment->getStatus(), 'Payment is not marked refunded on a mismatch');
        $this->assertNotified(Refund::STATUS_NEEDS_ATTENTION, 'webhook');
    }

    public function testDifferentAmountInSyncNeedsAttention(): void
    {
        $refund = $this->submittedRefund('rfd_53', Refund::STATUS_PROCESSING);
        $this->paystack->method('fetchRefund')->willReturn(['id' => 'rfd_53', 'status' => 'processed', 'amount' => 400000]);

        $result = $this->service->syncWithGateway($refund);

        self::assertSame(RefundService::SYNC_UPDATED, $result['outcome']);
        self::assertSame(Refund::STATUS_NEEDS_ATTENTION, $refund->getStatus());
        self::assertSame('gateway amount 4000 XOF differs from refund amount 5000 XOF', $refund->getFailureReason());
    }

    public function testMismatchedRefundIsNotSyncedAgainAsAChange(): void
    {
        $refund = $this->submittedRefund('rfd_54', Refund::STATUS_PROCESSING);
        $this->paystack->method('fetchRefund')->willReturn(['id' => 'rfd_54', 'status' => 'processed', 'amount' => 400000]);
        $this->service->syncWithGateway($refund);
        $this->dispatched = [];

        $result = $this->service->syncWithGateway($refund);

        self::assertSame(RefundService::SYNC_UNCHANGED, $result['outcome']);
        self::assertSame([], $this->dispatched);
    }

    // --- helpers ---

    private function failedReturnRefund(): Refund
    {
        $refund = new Refund(
            reference: 'RF-RET-1',
            payment: $this->payment(),
            initiatedBy: new User(),
            reason: Refund::REASON_PRODUCT_RETURN,
            itemsAmount: 4000,
            shippingAmount: 1000,
            taxAmount: 0,
            productReturn: $this->productReturn(),
        );
        $refund->markSubmitted('rfd_old', Refund::STATUS_PENDING, 'pending', []);
        $refund->applyGatewayStatus(Refund::STATUS_FAILED, 'failed', [], 'webhook', 'gateway reported failed');

        return $refund;
    }

    private function needsAttentionRefund(?string $gatewayId): Refund
    {
        $refund = $this->newRefund();
        if (null !== $gatewayId) {
            $refund->markSubmitted($gatewayId, Refund::STATUS_NEEDS_ATTENTION, 'needs-attention', ['error' => 'timeout']);
        } else {
            $refund->applyGatewayStatus(Refund::STATUS_NEEDS_ATTENTION, null, ['error' => 'timeout'], 'api', 'timeout');
        }

        return $refund;
    }

    // --- shared helpers ---

    private function assertNotified(string $status, string $source): void
    {
        self::assertCount(1, $this->dispatched);
        self::assertSame(42, $this->dispatched[0]->refundId);
        self::assertSame($status, $this->dispatched[0]->status);
        self::assertSame($source, $this->dispatched[0]->source);
    }

    private function payment(): Payment
    {
        return (new Payment())
            ->setTransactionId('TRX-1')
            ->setAmount(10000)
            ->setCurrencyCode('XOF')
            ->setPaymentMethod('card')
            ->setPaymentProvider('paystack')
            ->setTaxRate(0)
            ->setStatus(Payment::STATUS_COMPLETED);
    }

    private function productReturn(): ProductReturn
    {
        return (new ProductReturn())->setUid('ret-' . bin2hex(random_bytes(3)));
    }

    private function newRefund(): Refund
    {
        $refund = new Refund(
            reference: 'RF-TEST-' . bin2hex(random_bytes(3)),
            payment: $this->payment(),
            initiatedBy: new User(),
            reason: Refund::REASON_PRODUCT_RETURN,
            itemsAmount: 5000,
            shippingAmount: 0,
            taxAmount: 0,
        );
        (new ReflectionProperty(Refund::class, 'id'))->setValue($refund, 42);

        return $refund;
    }

    private function submittedRefund(string $gatewayId, string $status): Refund
    {
        $refund = $this->newRefund();
        $refund->markSubmitted($gatewayId, $status, $status, []);

        return $refund;
    }
}
