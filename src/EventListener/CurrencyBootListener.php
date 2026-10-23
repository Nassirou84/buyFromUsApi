<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\CurrencyProvider;
use App\Service\PriceCalculator;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * Product::getActualPrice() calls PriceCalculator statically, so hand it the provider
 * before any request, console command or Messenger message is processed.
 */
#[AsEventListener(event: RequestEvent::class, priority: 256)]
#[AsEventListener(event: ConsoleCommandEvent::class)]
#[AsEventListener(event: WorkerMessageReceivedEvent::class)]
final class CurrencyBootListener
{
    public function __construct(private readonly CurrencyProvider $currencyProvider)
    {
    }

    public function __invoke(object $event): void
    {
        PriceCalculator::useCurrencyProvider($this->currencyProvider);
    }
}
