<?php

namespace App\Controller;

use App\Entity\User;
use App\Enumeration\OrderStatusEnum;
use App\Repository\OrderRepository;
use App\Repository\UserRepository;
use App\Service\OrderService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;


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

        $processed = $orderService->markAsArrivedInFacility($order, $requestData);

        return new JsonResponse(['message' => 'order_marked_as_arrived_in_facility', 'status' => OrderStatusEnum::ARRIVED_IN_FACILITY->value, 'processed' => $processed]);
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
        SerializerInterface $serializer
    ): JsonResponse {
        $page = max(1, (int) $request->query->get('page', 1));
        $orders = $orderRepository->findAwaitingDelivery(10, ($page - 1) * 10);
        $totalOrders = $orderRepository->countAwaitingDelivery();

        $json = $serializer->serialize([
            'orders' => $orders,
            'page' => $page,
            'total' => $totalOrders
        ], 'json', ['groups' => ['order:read']]);

        return JsonResponse::fromJsonString($json);
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
}