<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Payment;
use App\Entity\Refund;
use App\Entity\User;
use App\Repository\RefundRepository;
use App\Service\PaystackService;
use App\Service\RefundService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Covers RefundService::syncWithGateway(), used by app:refund:sync-status to catch up
 * on refund statuses when a Paystack webhook was missed.
 *
 * The collaborators are shared mocks; each test only sets expectations on the ones it checks.
 */
#[AllowMockObjectsWithoutExpectations]
final class RefundServiceSyncTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private RefundRepository&MockObject $repository;
    private PaystackService&MockObject $paystack;
    private MessageBusInterface&MockObject $bus;
    private RefundService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(RefundRepository::class);
        $this->paystack = $this->createMock(PaystackService::class);
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->bus->method('dispatch')->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));
        $this->service = new RefundService($this->em, $this->repository, $this->paystack, new NullLogger(), $this->bus);
    }

    public function testAppliesNewStatusFetchedById(): void
    {
        $refund = $this->submittedRefund('rfd_1', 'pending');
        $this->paystack->expects(self::once())->method('fetchRefund')->with('rfd_1')
            ->willReturn(['id' => 'rfd_1', 'status' => 'processed', 'amount' => 500000]);
        $this->em->expects(self::once())->method('flush');

        $result = $this->service->syncWithGateway($refund);

        self::assertSame(RefundService::SYNC_UPDATED, $result['outcome']);
        self::assertSame(Refund::STATUS_PROCESSED, $refund->getStatus());
        self::assertNotNull($refund->getProcessedAt());
        self::assertSame('sync', $refund->getStatusHistory()[array_key_last($refund->getStatusHistory())]['source']);
    }

    public function testFailedStatusRecordsFailureReason(): void
    {
        $refund = $this->submittedRefund('rfd_2', 'processing');
        $this->paystack->method('fetchRefund')->willReturn(['id' => 'rfd_2', 'status' => 'failed']);

        $result = $this->service->syncWithGateway($refund);

        self::assertSame(RefundService::SYNC_UPDATED, $result['outcome']);
        self::assertSame(Refund::STATUS_FAILED, $refund->getStatus());
        self::assertSame('gateway reported failed', $refund->getFailureReason());
    }

    public function testSameStatusWritesNothing(): void
    {
        $refund = $this->submittedRefund('rfd_3', 'processing');
        $this->paystack->method('fetchRefund')->willReturn(['id' => 'rfd_3', 'status' => 'processing']);
        $this->em->expects(self::never())->method('flush');
        $historyLength = count($refund->getStatusHistory());

        $result = $this->service->syncWithGateway($refund);

        self::assertSame(RefundService::SYNC_UNCHANGED, $result['outcome']);
        self::assertCount($historyLength, $refund->getStatusHistory());
    }

    public function testDryRunReportsChangeWithoutApplyingIt(): void
    {
        $refund = $this->submittedRefund('rfd_4', 'pending');
        $this->paystack->method('fetchRefund')->willReturn(['id' => 'rfd_4', 'status' => 'processed']);
        $this->em->expects(self::never())->method('flush');

        $result = $this->service->syncWithGateway($refund, true);

        self::assertSame(RefundService::SYNC_UPDATED, $result['outcome']);
        self::assertSame(Refund::STATUS_PROCESSED, $result['status']);
        self::assertSame(Refund::STATUS_PENDING, $refund->getStatus());
    }

    public function testUnknownGatewayStatusIsReportedNotApplied(): void
    {
        $refund = $this->submittedRefund('rfd_5', 'pending');
        $this->paystack->method('fetchRefund')->willReturn(['id' => 'rfd_5', 'status' => 'reversed']);
        $this->em->expects(self::never())->method('flush');

        $result = $this->service->syncWithGateway($refund);

        self::assertSame(RefundService::SYNC_UNKNOWN_STATUS, $result['outcome']);
        self::assertSame(Refund::STATUS_PENDING, $refund->getStatus());
    }

    public function testTimedOutRefundIsMatchedInListAndLinked(): void
    {
        $refund = $this->newRefund(); // never got a gateway id
        $refund->applyGatewayStatus(Refund::STATUS_NEEDS_ATTENTION, null, ['error' => 'timeout'], 'api', 'timeout');
        $this->paystack->expects(self::never())->method('fetchRefund');
        $this->paystack->method('listRefunds')->willReturn([
            'data' => [
                ['id' => 900, 'status' => 'processed', 'transaction_reference' => 'OTHER', 'amount' => 500000],
                ['id' => 901, 'status' => 'processed', 'transaction_reference' => 'TRX-1', 'amount' => 100],
                ['id' => 902, 'status' => 'processed', 'transaction_reference' => 'TRX-1', 'amount' => 500000],
            ],
            'pageCount' => 1,
        ]);
        $this->repository->method('findOneBy')->willReturn(null);

        $result = $this->service->syncWithGateway($refund);

        self::assertSame(RefundService::SYNC_UPDATED, $result['outcome']);
        self::assertSame('902', $refund->getRefundTransactionNumber());
        self::assertSame(Refund::STATUS_PROCESSED, $refund->getStatus());
    }

    public function testTimedOutRefundWithAmbiguousMatchIsNotLinked(): void
    {
        $refund = $this->newRefund();
        $this->paystack->method('listRefunds')->willReturn([
            'data' => [
                ['id' => 910, 'status' => 'processed', 'transaction_reference' => 'TRX-1', 'amount' => 500000],
                ['id' => 911, 'status' => 'processed', 'transaction_reference' => 'TRX-1', 'amount' => 500000],
            ],
            'pageCount' => 1,
        ]);
        $this->repository->method('findOneBy')->willReturn(null);
        $this->em->expects(self::never())->method('flush');

        $result = $this->service->syncWithGateway($refund);

        self::assertSame(RefundService::SYNC_NOT_FOUND, $result['outcome']);
        self::assertNull($refund->getRefundTransactionNumber());
    }

    public function testTimedOutRefundSkipsGatewayRefundLinkedToAnotherRow(): void
    {
        $refund = $this->newRefund();
        $this->paystack->method('listRefunds')->willReturn([
            'data' => [['id' => 920, 'status' => 'processed', 'transaction_reference' => 'TRX-1', 'amount' => 500000]],
            'pageCount' => 1,
        ]);
        $this->repository->method('findOneBy')->willReturn($this->submittedRefund('920', 'processed'));

        $result = $this->service->syncWithGateway($refund);

        self::assertSame(RefundService::SYNC_NOT_FOUND, $result['outcome']);
    }

    public function testTerminalRefundIsNotPolled(): void
    {
        $refund = $this->submittedRefund('rfd_6', 'processed');
        $this->paystack->expects(self::never())->method('fetchRefund');

        $result = $this->service->syncWithGateway($refund);

        self::assertSame(RefundService::SYNC_UNCHANGED, $result['outcome']);
    }

    private function newRefund(): Refund
    {
        $payment = (new Payment())
            ->setTransactionId('TRX-1')
            ->setAmount(10000)
            ->setCurrencyCode('XOF')
            ->setPaymentMethod('card')
            ->setPaymentProvider('paystack')
            ->setTaxRate(0);

        return new Refund(
            reference: 'RF-TEST-' . bin2hex(random_bytes(3)),
            payment: $payment,
            initiatedBy: new User(),
            reason: Refund::REASON_PRODUCT_RETURN,
            itemsAmount: 5000,
            shippingAmount: 0,
            taxAmount: 0,
        );
    }

    private function submittedRefund(string $gatewayId, string $gatewayStatus): Refund
    {
        $refund = $this->newRefund();
        $status = ['pending' => Refund::STATUS_PENDING, 'processing' => Refund::STATUS_PROCESSING, 'processed' => Refund::STATUS_PROCESSED][$gatewayStatus];
        $refund->markSubmitted($gatewayId, $status, $gatewayStatus, []);

        return $refund;
    }
}
