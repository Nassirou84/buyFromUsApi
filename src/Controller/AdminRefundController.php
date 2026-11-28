<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Refund;
use App\Entity\User;
use App\Repository\RefundRepository;
use App\Service\PaystackService;
use App\Service\RefundService;
use DateTimeInterface;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

use function is_array;
use function is_string;

final class AdminRefundController extends AbstractController
{
    /**
     * Access: ROLE_ADMIN only. Sends a new gateway refund for a product return whose
     * last refund failed. The failed refund stays on record.
     */
    #[Route('api/admin/refunds/{id}/retry', name: 'app_admin_refund_retry', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function retry(int $id, RefundRepository $refundRepository, RefundService $refundService): JsonResponse
    {
        $refund = $refundRepository->find($id);
        if (!$refund) {
            return new JsonResponse(['error' => 'refund_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        try {
            $newRefund = $refundService->retryRefund($refund, $user);
        } catch (LogicException $e) {
            // Not retryable in its current state; nothing was sent.
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_CONFLICT);
        } catch (RuntimeException $e) {
            // refund_rejected / refund_outcome_unknown: the new attempt is recorded.
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse(['success' => true, 'refund' => $this->describe($newRefund)], JsonResponse::HTTP_CREATED);
    }

    /**
     * Access: ROLE_ADMIN only. Resolves a `needs_attention` refund.
     *
     * Body: {"resolution": "sync" | "processed" | "failed", "note": "..."}.
     * `note` is required for `processed` and `failed`. Paystack is checked first
     * every time; see RefundService::reconcile().
     */
    #[Route('api/admin/refunds/{id}/reconcile', name: 'app_admin_refund_reconcile', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function reconcile(int $id, Request $request, RefundRepository $refundRepository, RefundService $refundService): JsonResponse
    {
        $refund = $refundRepository->find($id);
        if (!$refund) {
            return new JsonResponse(['error' => 'refund_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body) || !is_string($body['resolution'] ?? null)) {
            return new JsonResponse(['error' => 'refund_resolution_invalid'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $note = is_string($body['note'] ?? null) ? $body['note'] : null;

        try {
            $result = $refundService->reconcile($refund, $body['resolution'], $user, $note);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        } catch (LogicException $e) {
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_CONFLICT);
        } catch (RuntimeException|TransportExceptionInterface) {
            // Paystack couldn't be reached: nothing was changed.
            return new JsonResponse(['error' => 'gateway_unavailable'], JsonResponse::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse([
            'success' => true,
            'outcome' => $result['outcome'],
            'refund' => $this->describe($result['refund']),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Refund $refund): array
    {
        return [
            'id' => $refund->getId(),
            'reference' => $refund->getReference(),
            'status' => $refund->getStatus(),
            'gatewayStatus' => $refund->getGatewayStatus(),
            'amount' => $refund->getAmount(),
            'currency' => $refund->getCurrency(),
            // Paystack reports the subunit (x100); this is converted back to the main unit.
            'gatewayAmount' => PaystackService::fromSubunit($refund->getGatewayResponse()['amount'] ?? null),
            'failureReason' => $refund->getFailureReason(),
            'paymentStatus' => $refund->getPayment()->getStatus(),
            'createdAt' => $refund->getCreatedAt()->format(DateTimeInterface::ATOM),
            'statusHistory' => $refund->getStatusHistory(),
        ];
    }
}
