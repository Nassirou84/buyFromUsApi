<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RefundNotificationMessage;
use App\Repository\RefundRepository;
use App\Service\BrevoEmailService;
use App\Service\PaystackService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function sprintf;

use const JSON_PRETTY_PRINT;

#[AsMessageHandler]
final class RefundNotificationMessageHandler
{
    public function __construct(
        private RefundRepository $refundRepository,
        private BrevoEmailService $brevoMailerService,
        private LoggerInterface $logger,
        private string $adminEmail,
    ) {
    }

    public function __invoke(RefundNotificationMessage $message): void
    {
        $refund = $this->refundRepository->find($message->refundId);
        if (null === $refund) {
            $this->logger->warning('Refund notification for unknown refund', ['refundId' => $message->refundId]);

            return;
        }

        $gatewayAmount = PaystackService::fromSubunit($refund->getGatewayResponse()['amount'] ?? null);
        $body = sprintf(
            "Refund #%d (%s) is now %s (via %s).\nAmount: %d %s\nAmount reported by the gateway: %s\nGateway status: %s\nFailure reason: %s\nGateway response (amounts in Paystack subunit, x100):\n%s",
            $refund->getId(),
            $refund->getReference(),
            $message->status,
            $message->source,
            $refund->getAmount(),
            $refund->getCurrency(),
            null !== $gatewayAmount ? $gatewayAmount . ' ' . $refund->getCurrency() : 'n/a',
            $refund->getGatewayStatus() ?? 'n/a',
            $refund->getFailureReason() ?? 'n/a',
            json_encode($refund->getGatewayResponse(), JSON_PRETTY_PRINT) ?: '{}',
        );

        $this->brevoMailerService->sendTextEmail($this->adminEmail, 'Refund notification', $body);
    }
}
