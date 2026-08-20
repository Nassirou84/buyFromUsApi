<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EmailQueueRepository;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

use function in_array;
use function sprintf;

use const FILTER_VALIDATE_EMAIL;

#[ORM\Entity(repositoryClass: EmailQueueRepository::class)]
#[ORM\Table(indexes: [
    new ORM\Index(name: 'idx_email_queue_status', columns: ['status']),
    new ORM\Index(name: 'idx_email_queue_priority', columns: ['priority']),
    new ORM\Index(name: 'idx_email_queue_send_after', columns: ['send_after']),
    new ORM\Index(name: 'idx_email_queue_status_priority', columns: ['status', 'priority']),
    new ORM\Index(name: 'idx_email_queue_status_send_after', columns: ['status', 'send_after']),
])]
class EmailQueue
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_RETRYING = 'retrying';

    public const PRIORITY_LOW = 1;
    public const PRIORITY_DEFAULT = 5;
    public const PRIORITY_HIGH = 10;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.onlyRead
    private $id;

    #[ORM\Column(length: 255)]
    private ?string $recipientEmail = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $recipientName = null;

    #[ORM\Column(length: 255)]
    private ?string $subject = null;

    #[ORM\Column]
    private ?int $templateId = null;

    #[ORM\Column]
    private array $templateParams = [];

    #[ORM\Column(length: 40)]
    private ?string $status = null;

    #[ORM\Column]
    private ?int $priority = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTimeInterface $sendAfter = null;

    #[ORM\Column(nullable: true)]
    private ?int $attemps = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxAttemps = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $messageId = null;

    #[ORM\Column]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTimeInterface $updatedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTimeInterface $lastAttemptAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->status = self::STATUS_PENDING;
        $this->attemps = 0;
        $this->maxAttemps = 3;
        $this->sendAfter = new DateTime();
        $this->priority = self::PRIORITY_DEFAULT;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecipientEmail(): ?string
    {
        return $this->recipientEmail;
    }

    public function setRecipientEmail(string $recipientEmail): static
    {
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email format: ' . $recipientEmail);
        }

        $this->recipientEmail = $recipientEmail;

        return $this;
    }

    public function getRecipientName(): ?string
    {
        return $this->recipientName;
    }

    public function setRecipientName(?string $recipientName): static
    {
        $this->recipientName = $recipientName;

        return $this;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    public function getTemplateId(): ?int
    {
        return $this->templateId;
    }

    public function setTemplateId(int $templateId): static
    {
        $this->templateId = $templateId;

        return $this;
    }

    public function getTemplateParams(): array
    {
        return $this->templateParams;
    }

    public function setTemplateParams(array $templateParams): static
    {
        $this->templateParams = $templateParams;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $validStatuses = [
            self::STATUS_PENDING,
            self::STATUS_SENT,
            self::STATUS_FAILED,
            self::STATUS_PROCESSING,
            self::STATUS_SKIPPED,
            self::STATUS_RETRYING,
        ];

        if (!in_array($status, $validStatuses, true)) {
            throw new InvalidArgumentException(sprintf('Invalid status "%s". Must be one of: %s', $status, implode(', ', $validStatuses)));
        }

        $this->status = $status;

        return $this;
    }

    public function getPriority(): ?int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function getSendAfter(): ?DateTimeInterface
    {
        return $this->sendAfter;
    }

    public function setSendAfter(?DateTimeInterface $sendAfter): static
    {
        $this->sendAfter = $sendAfter;

        return $this;
    }

    public function getAttemps(): ?int
    {
        return $this->attemps;
    }

    public function setAttemps(?int $attemps): static
    {
        $this->attemps = $attemps;

        return $this;
    }

    public function getMaxAttemps(): ?int
    {
        return $this->maxAttemps;
    }

    public function setMaxAttemps(?int $maxAttemps): static
    {
        $this->maxAttemps = $maxAttemps;

        return $this;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $lastError): static
    {
        $this->lastError = $lastError;

        return $this;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function setMessageId(?string $messageId): static
    {
        $this->messageId = $messageId;

        return $this;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getLastAttemptAt(): ?DateTimeInterface
    {
        return $this->lastAttemptAt;
    }

    public function setLastAttemptAt(?DateTimeInterface $lastAttemptAt): static
    {
        $this->lastAttemptAt = $lastAttemptAt;

        return $this;
    }
}
