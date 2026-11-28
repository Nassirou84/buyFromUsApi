<?php

namespace App\Controller;

use App\Entity\User;
use App\Enumeration\OrderStatusEnum;
use App\Repository\OrderRepository;
use App\Repository\ProductReturnRepository;
use App\Repository\UserRepository;
use App\Service\OrderService;
use App\Service\ProductReturnService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;


#[Route('/api/admin/')]
final class OrderController extends AbstractController
{

    #[Route('orders-summary', name: 'app_order_list', methods: ['GET'])]
    public function ordersSummary(
        OrderService $orderService
    ): JsonResponse {
        $summary = $orderService->getAdminSummary();
        return new JsonResponse($summary);
    }

    #[Route('edit/{uid}/order/details', name: 'app_order', methods: ['POST'])]
    public function editOrderDetails(
        string $uid,
        Request $request,
        OrderRepository $orderRepository,
        OrderService $orderService
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'User not authenticated'], 401);
        }
        if (count(array_intersect([User::ROLE_SUPER_ADMIN, User::ROLE_OPERATOR], $user->getRoles())) === 0) {
            return new JsonResponse(['error' => 'Access denied'], JsonResponse::HTTP_FORBIDDEN);
        }

        $order = $orderRepository->findOneBy(['uid' => $uid]);

        if (!$order) {
            return new JsonResponse(['error' => 'Order not found'], JsonResponse::HTTP_NOT_FOUND);
        }
        try {
            $order = $orderService->editOrderDetails($order, $data);
            return new JsonResponse(['message' => 'Order details updated successfully', 'order' => $order]);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }
    }

    #[Route('orders/{uid}/resend-confirmation', name: 'app_order_resend_confirmation', methods: ['POST'])]
    public function resendConfirmation(
        string $uid,
        OrderRepository $orderRepository,
        OrderService $orderService
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'User not authenticated'], 401);
        }
        if (count(array_intersect([User::ROLE_SUPER_ADMIN, User::ROLE_OPERATOR], $user->getRoles())) === 0) {
            return new JsonResponse(['error' => 'access_denied'], JsonResponse::HTTP_FORBIDDEN);
        }

        $order = $orderRepository->findOneBy(['uid' => $uid]);
        if (!$order) {
            return new JsonResponse(['error' => 'order_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        try {
            $orderService->resendOrderConfirmation($order);
            return new JsonResponse(['message' => 'order_confirmation_resent']);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }
    }

    #[Route('orders/{uid}/cancel', name: 'app_order_cancel', methods: ['POST'])]
    public function cancelOrder(
        string $uid,
        OrderRepository $orderRepository,
        OrderService $orderService
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'user_not_authenticated'], 401);
        }
        if (count(array_intersect([User::ROLE_SUPER_ADMIN, User::ROLE_OPERATOR], $user->getRoles())) === 0) {
            return new JsonResponse(['error' => 'access_denied'], JsonResponse::HTTP_FORBIDDEN);
        }

        $order = $orderRepository->findOneBy(['uid' => $uid]);
        if (!$order) {
            return new JsonResponse(['error' => 'order_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        try {
            $orderService->cancelOrder($order);
            return new JsonResponse(['message' => 'order_cancelled']);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }
    }

    #[Route('orders/{uid}/add-note', name: 'app_order_add_note', methods: ['POST'])]
    public function addNote(
        string $uid,
        Request $request,
        OrderRepository $orderRepository,
        OrderService $orderService
    ): JsonResponse {
        $user = $this->getUser();
        $note = json_decode($request->getContent(), true)['note'] ?? null;
        if (!$user) {
            return new JsonResponse(['error' => 'user_not_authenticated'], 401);
        }
        if (count(array_intersect([User::ROLE_SUPER_ADMIN, User::ROLE_OPERATOR], $user->getRoles())) === 0) {
            return new JsonResponse(['error' => 'access_denied'], JsonResponse::HTTP_FORBIDDEN);
        }

        $order = $orderRepository->findOneBy(['uid' => $uid]);
        if (!$order) {
            return new JsonResponse(['error' => 'order_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        try {
            $orderService->addNoteToOrder($order, $note);
            return new JsonResponse(['message' => 'note_added']);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }
    }

    #[Route('orders/{uid}/status', name: 'app_order_update_status', methods: ['POST'])]
    public function updateStatus(
        string $uid,
        Request $request,
        OrderRepository $orderRepository,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $user = $this->getUser();
        $status = json_decode($request->getContent(), true)['status'] ?? null;
        if (!$user) {
            return new JsonResponse(['error' => 'user_not_authenticated'], 401);
        }
        if (count(array_intersect([User::ROLE_SUPER_ADMIN, User::ROLE_OPERATOR], $user->getRoles())) === 0) {
            return new JsonResponse(['error' => 'access_denied'], JsonResponse::HTTP_FORBIDDEN);
        }

        $statusEnum = is_string($status) ? OrderStatusEnum::tryFrom($status) : null;
        // Cancellation has its own endpoint, so it is not advanceable here.
        if (null === $statusEnum || OrderStatusEnum::CANCELLED === $statusEnum) {
            return new JsonResponse(['error' => 'invalid_status'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $order = $orderRepository->findOneBy(['uid' => $uid]);
        if (!$order) {
            return new JsonResponse(['error' => 'order_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $order->setStatus($statusEnum->value);
        $entityManager->flush();

        return new JsonResponse(['message' => 'status_updated', 'status' => $statusEnum->value]);
    }

    #[Route('orders/{uid}/processed', name: 'app_order_processed', methods: ['POST'])]
    public function markAsProcessed(
        Request $request,
        string $uid,
        OrderService $orderService,
        OrderRepository $orderRepository,
    ): JsonResponse {
        $requestData = $request->request->all();
        $files = $request->files->all();
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'user_not_authenticated'], 401);
        }
        if (count(array_intersect([User::ROLE_SUPER_ADMIN, User::ROLE_OPERATOR], $user->getRoles())) === 0) {
            return new JsonResponse(['error' => 'access_denied'], JsonResponse::HTTP_FORBIDDEN);
        }

        $order = $orderRepository->findOneBy(['uid' => $uid]);
        if (!$order) {
            return new JsonResponse(['error' => 'order_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $processed = $orderService->markAsProcessed($order, $requestData, $files);

        return new JsonResponse(['message' => 'order_marked_as_processed', 'status' => OrderStatusEnum::PROCESSED->value, 'processed' => $processed]);
    }

    #[Route('orders/{uid}/tracking-numbers', name: 'app_order_add_tracking_numbers', methods: ['POST'])]
    public function addTrackingNumbers(
        Request $request,
        string $uid,
        OrderService $orderService,
        OrderRepository $orderRepository,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'user_not_authenticated'], 401);
        }
        if (count(array_intersect([User::ROLE_SUPER_ADMIN, User::ROLE_OPERATOR], $user->getRoles())) === 0) {
            return new JsonResponse(['error' => 'access_denied'], JsonResponse::HTTP_FORBIDDEN);
        }

        $order = $orderRepository->findOneBy(['uid' => $uid]);
        if (!$order) {
            return new JsonResponse(['error' => 'order_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        try {
            $processed = $orderService->addTrackingNumbers($order, is_array($data) ? $data : []);
        } catch (\InvalidArgumentException|\LogicException $e) {
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'message' => 'tracking_numbers_added',
            'trackingNumbers' => $processed->getTrackingNumbers(),
        ]);
    }

    #[Route('orders/{uid}/in-transit', name: 'app_order_in_transit', methods: ['POST'])]
    public function markAsInTransit(
        Request $request,
        string $uid,
        OrderService $orderService,
        OrderRepository $orderRepository,
    ): JsonResponse {
        $requestData = $request->request->all();
        $files = $request->files->all();
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'user_not_authenticated'], 401);
        }
        if (count(array_intersect([User::ROLE_SUPER_ADMIN, User::ROLE_OPERATOR], $user->getRoles())) === 0) {
            return new JsonResponse(['error' => 'access_denied'], JsonResponse::HTTP_FORBIDDEN);
        }

        $order = $orderRepository->findOneBy(['uid' => $uid]);
        if (!$order) {
            return new JsonResponse(['error' => 'order_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $processed = $orderService->markAsInTransit($order, $requestData, $files);

        return new JsonResponse(['message' => 'order_marked_as_in_transit', 'status' => OrderStatusEnum::IN_TRANSIT->value, 'processed' => $processed]);
    }

    #[Route('orders/{uid}/arrived_in_facility', name: 'app_order_delivered', methods: ['POST'])]
    public function markAsArrivedInFacility(
        Request $request,
        string $uid,
        OrderService $orderService,
        OrderRepository $orderRepository,
    ): JsonResponse {
        $requestData = $request->request->all();
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'user_not_authenticated'], 401);
        }
        if (count(array_intersect([User::ROLE_SUPER_ADMIN, User::ROLE_OPERATOR], $user->getRoles())) === 0) {
            return new JsonResponse(['error' => 'access_denied'], JsonResponse::HTTP_FORBIDDEN);
        }

        $order = $orderRepository->findOneBy(['uid' => $uid]);
        if (!$order) {
            return new JsonResponse(['error' => 'order_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        try {
            $processed = $orderService->markAsArrivedInFacility($order, $requestData);
        } catch (\InvalidArgumentException|\LogicException $e) {
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }

        $trackingNumbers = $processed->getTrackingNumbers() ?? [];
        $arrivedCount = count(array_filter($trackingNumbers, static fn (array $entry): bool => !empty($entry['arrivedAt'])));
        $allArrived = OrderStatusEnum::ARRIVED_IN_FACILITY->value === $order->getStatus();

        return new JsonResponse([
            'message' => $allArrived ? 'order_marked_as_arrived_in_facility' : 'tracking_number_confirmed',
            'status' => $order->getStatus(),
            'allArrived' => $allArrived,
            'arrivedCount' => $arrivedCount,
            'trackingNumbersCount' => count($trackingNumbers),
            'processed' => $processed,
        ]);
    }

    #[Route('orders/{uid}/out_for_delivery', name: 'app_order_shipped', methods: ['POST'])]
    public function markAsOutForDelivery(
        string $uid,
        OrderService $orderService,
        OrderRepository $orderRepository,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'user_not_authenticated'], 401);
        }
        if (count(array_intersect([User::ROLE_SUPER_ADMIN, User::ROLE_OPERATOR], $user->getRoles())) === 0) {
            return new JsonResponse(['error' => 'access_denied'], JsonResponse::HTTP_FORBIDDEN);
        }

        $order = $orderRepository->findOneBy(['uid' => $uid]);
        if (!$order) {
            return new JsonResponse(['error' => 'order_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $processed = $orderService->markAsOutForDelivery($order);

        return new JsonResponse(['message' => 'order_marked_as_out_for_delivery', 'status' => OrderStatusEnum::OUT_FOR_DELIVERY->value, 'processed' => $processed]);
    }

    #[Route('orders-awaiting-delivery', name: 'app_orders_pending_deliveries', methods: ['GET'])]
    public function getOrdersAwaitingDelivery(
        Request $request,
        OrderRepository $orderRepository,
        ProductReturnRepository $productReturnRepository,
        NormalizerInterface $normalizer
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'user_not_authenticated'], 401);
        }
        if (count(array_intersect([User::ROLE_SUPER_ADMIN, User::ROLE_OPERATOR], $user->getRoles())) === 0) {
            return new JsonResponse(['error' => 'access_denied'], JsonResponse::HTTP_FORBIDDEN);
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $offset = ($page - 1) * 10;
        $orders = $orderRepository->findAwaitingDelivery(10, $offset);
        $totalOrders = $orderRepository->countAwaitingDelivery();
        $returns = $productReturnRepository->findAwaitingCollection(10, $offset);
        $totalReturns = $productReturnRepository->countAwaitingCollection();

        return new JsonResponse([
            'orders' => $normalizer->normalize($orders, 'json', ['groups' => ['order:read']]),
            'returns' => $normalizer->normalize($returns, 'json', ['groups' => ['product_return:read']]),
            'page' => $page,
            'total' => $totalOrders,
            'totalReturns' => $totalReturns,
        ]);
    }

    #[Route('batch-assign-delivery', name: 'app_batch_assign_delivery', methods: ['POST'])]
    public function batchAssignDelivery(
        Request $request,
        OrderService $orderService,
        UserRepository $userRepository
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'user_not_authenticated'], 401);
        }
        if (count(array_intersect([User::ROLE_SUPER_ADMIN, User::ROLE_OPERATOR], $user->getRoles())) === 0) {
            return new JsonResponse(['error' => 'access_denied'], JsonResponse::HTTP_FORBIDDEN);
        }
        $requestData = json_decode($request->getContent(), true);
        $driverId = $requestData['driver_id'] ?? null;
        if (!$requestData['orders']) {
            return new JsonResponse(['error' => 'no_orders_provided'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $driver = $userRepository->find($driverId);
        if (!$driver) {
            return new JsonResponse(['error' => 'driver_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }
        if (!array_intersect([User::ROLE_DRIVER], $driver->getRoles())) {
            return new JsonResponse(['error' => 'user_is_not_driver'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $processed = $orderService->batchAssignDelivery($requestData['orders'], $driver);

        return new JsonResponse(['message' => 'batch_delivery_assigned', 'processed' => $processed]);
    }

    #[Route('batch-assign-return-collection', name: 'app_batch_assign_return_collection', methods: ['POST'])]
    public function batchAssignReturnCollection(
        Request $request,
        ProductReturnService $productReturnService,
        UserRepository $userRepository
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'user_not_authenticated'], 401);
        }
        if (count(array_intersect([User::ROLE_SUPER_ADMIN, User::ROLE_OPERATOR], $user->getRoles())) === 0) {
            return new JsonResponse(['error' => 'access_denied'], JsonResponse::HTTP_FORBIDDEN);
        }
        $requestData = json_decode($request->getContent(), true);
        if (!is_array($requestData) || empty($requestData['returns']) || !is_array($requestData['returns'])) {
            return new JsonResponse(['error' => 'no_returns_provided'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $driverId = $requestData['driver_id'] ?? null;
        $driver = $driverId ? $userRepository->find($driverId) : null;
        if (!$driver) {
            return new JsonResponse(['error' => 'driver_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }
        if (!in_array(User::ROLE_DRIVER, $driver->getRoles(), true)) {
            return new JsonResponse(['error' => 'user_is_not_driver'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $processed = $productReturnService->batchAssignReturnCollection($requestData['returns'], $driver);

        return new JsonResponse(['message' => 'batch_return_collection_assigned', 'processed' => $processed]);
    }
}
