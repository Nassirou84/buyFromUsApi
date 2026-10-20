<?php
declare(strict_types=1);
namespace App\MessageHandler;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use App\Repository\ProductRepository;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use App\Service\RabbitMqMessengerService;
use App\Service\BrevoEmailService;

use App\Message\NewProductAddedMessage;

#[AsMessageHandler]
class NewProductAddedMessageHandler
{

  public function __construct(
    private ProductRepository $productRepository,
    private RabbitMqMessengerService $rabbitMqMessengerService,
    private NormalizerInterface $normalizerInterface,
    private BrevoEmailService $brevoMailerService,
    private string $adminEmail,
  ) {
  }

  public function __invoke(NewProductAddedMessage $message): void
  {
    $product = $this->productRepository->findOneBy(['uid' => $message->uid]);

    if (!$product) {
      throw new \RuntimeException('Product not found');
    }

    $exchangeName = 'product_exchange';
    $queueName = 'add_new_product_queue';
    $routingKey = 'product.routing';

    $this->rabbitMqMessengerService->setupExchangeAndQueue($exchangeName, 'direct', $queueName, $routingKey);

    $normalizedProduct = $this->normalizerInterface->normalize($product, null, ['groups' => ['product:read', 'product:read:details', 'product:read:admin']]);

    $this->rabbitMqMessengerService->publishMessage($exchangeName, $routingKey, $normalizedProduct);

    $this->brevoMailerService->sendTextEmail(
      $this->adminEmail,
      'A new product has been added to RabbitMQ',
      'A new product has been added: ' . $product->getTitle()
    );
  }
}