<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Category;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Enumeration\OrderStatusEnum;
use App\Service\OrderService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Covers OrderService::abortPendingOrder(), the cleanup used when a customer
 * aborts a Paystack checkout: the abandoned PENDING order and its items must be
 * removed, while anything already paid is left untouched.
 */
final class OrderServiceAbortTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private OrderService $orderService;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->orderService = static::getContainer()->get(OrderService::class);

        $tool = new SchemaTool($this->em);
        $tool->dropDatabase();
        $tool->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    public function testAbortRemovesPendingOrderAndItsItems(): void
    {
        $order = $this->persistOrder('O-abort1', OrderStatusEnum::PENDING->value, false);
        $itemId = $this->persistItem($order)->getId();
        // The real abort is a separate request, so the service loads the order fresh;
        // clear the unit of work to reproduce that and let the items lazy-load.
        $this->em->clear();

        $removed = $this->orderService->abortPendingOrder('O-abort1');

        self::assertTrue($removed);
        $this->em->clear();
        self::assertNull($this->em->getRepository(Order::class)->findOneBy(['uid' => 'O-abort1']));
        self::assertNull($this->em->getRepository(OrderItem::class)->find($itemId), 'The order item must be removed too.');
    }

    public function testAbortLeavesPaidOrderUntouched(): void
    {
        $order = $this->persistOrder('O-paid01', OrderStatusEnum::PLACED->value, true);
        $itemId = $this->persistItem($order)->getId();
        $this->em->clear();

        $removed = $this->orderService->abortPendingOrder('O-paid01');

        self::assertFalse($removed);
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Order::class)->findOneBy(['uid' => 'O-paid01']));
        self::assertNotNull($this->em->getRepository(OrderItem::class)->find($itemId));
    }

    public function testAbortOnUnknownUidIsANoop(): void
    {
        self::assertFalse($this->orderService->abortPendingOrder('O-nope00'));
    }

    private function persistOrder(string $uid, string $status, bool $paid): Order
    {
        $order = new Order();
        $order->setUid($uid);
        $order->setStatus($status);
        $order->setIsPaidSuccessfully($paid);
        $order->setOrderPrice(1000.0);
        $order->setFullName('Test Buyer');
        $order->setStreet('1 Rue du Test');
        $order->setCity('Abidjan');
        $order->setState('Lagunes');
        $order->setAccessToken(bin2hex(random_bytes(8)));
        $this->em->persist($order);
        $this->em->flush();

        return $order;
    }

    private function persistItem(Order $order): OrderItem
    {
        $category = (new Category())->setTitle('Test Category');
        $this->em->persist($category);

        $product = (new Product())
            ->setTitle('Test Product')
            ->setSeller('amazon')
            ->setBrand('TestBrand')
            ->setUsdPrice(10.0)
            ->setFullPrice(15.0)
            ->setIsAvailable(true)
            ->setProductCondition('new')
            ->setCategory($category)
            ->setUid('P-test01');
        $this->em->persist($product);

        $item = new OrderItem();
        $item->setCurrentOrder($order);
        $item->setProduct($product);
        $item->setQuantity(1);
        $item->setUnitPrice(15.0);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }
}
