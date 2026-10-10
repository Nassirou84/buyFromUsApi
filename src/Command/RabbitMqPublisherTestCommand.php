<?php

declare(strict_types=1);

namespace App\Command;
use App\Repository\ProductRepository;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use App\Service\RabbitMqMessengerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:rabbit-mq-test',
    description: 'Add a short description for your command',
)]
class RabbitMqPublisherTestCommand extends Command
{
    public function __construct(
        private RabbitMqMessengerService $rabbitMqMessengerService,
        private readonly ProductRepository $productRepository,
        private readonly NormalizerInterface $normalizerInterface
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Sending a message');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $exchangeName = 'product_exchange';
        $queueName = 'add_new_product_queue';
        $routingKey = 'product.routing';

        $this->rabbitMqMessengerService->setupExchangeAndQueue($exchangeName, 'direct', $queueName, $routingKey);

        $product = $this->productRepository->findAll()[2];

        $normalizedProduct = $this->normalizerInterface->normalize($product, null, ['groups' => ['product:read', 'product:read:details']]);

        $this->rabbitMqMessengerService->publishMessage($exchangeName, $routingKey, $normalizedProduct);

        $io->success('Message sent successfully.');

        return Command::SUCCESS;
    }
}