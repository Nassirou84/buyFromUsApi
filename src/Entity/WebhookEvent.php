<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WebhookEventRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only log of Paystack webhook events we have already accepted, keyed by a
 * stable per-event key. It makes webhook delivery idempotent for ANY event type:
 * a replay (Paystack retries, or a captured payload re-sent) is recognised and
 * skipped, independently of the payment-reference dedupe used when finalizing a
 * charge. Internal only — never exposed through the API.
 */
#[ORM\Entity(repositoryClass: WebhookEventRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_WEBHOOK_EVENT_KEY', fields: ['eventKey'])]
class WebhookEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $eventKey = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEventKey(): ?string
    {
        return $this->eventKey;
    }

    public function setEventKey(string $eventKey): static
    {
        $this->eventKey = $eventKey;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
