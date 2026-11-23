<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\WebhookEvent;
use App\Repository\WebhookEventRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Idempotency ledger for incoming webhooks: records the events we have already
 * accepted so a replay of any event type is a no-op, independently of the
 * per-payment dedupe. Keys are opaque, stable per event (see WebhookController).
 */
final class WebhookEventService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WebhookEventRepository $repository,
    ) {
    }

    public function alreadyProcessed(string $eventKey): bool
    {
        return null !== $this->repository->findOneBy(['eventKey' => $eventKey]);
    }

    /**
     * Records the event as processed. Tolerant of the concurrent-delivery race:
     * if another request recorded the same key first, the unique index rejects
     * this insert and we simply treat it as already done.
     */
    public function markProcessed(string $eventKey): void
    {
        $event = new WebhookEvent();
        $event->setEventKey($eventKey);

        try {
            $this->entityManager->persist($event);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Another delivery of the same event won the race; nothing to do.
        }
    }
}
