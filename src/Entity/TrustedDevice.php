<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use App\Repository\TrustedDeviceRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    operations: [
        new Delete(
            security: "is_granted('ROLE_USER')"
        )
    ],
    denormalizationContext: ['groups' => ['trusted_device:read', 'user:login:read']],
)]
#[ORM\Entity(repositoryClass: TrustedDeviceRepository::class)]
class TrustedDevice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['trusted_device:read', 'user:login:read'])]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $visitorId = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['trusted_device:read', 'user:login:read'])]
    private ?string $language = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['trusted_device:read', 'user:login:read'])]
    private ?string $platform = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['trusted_device:read', 'user:login:read'])]
    private ?string $timeZone = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['trusted_device:read', 'user:login:read'])]
    private ?string $userAgent = null;

    #[ORM\Column]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\ManyToOne(inversedBy: 'trustedDevices')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVisitorId(): ?string
    {
        return $this->visitorId;
    }

    public function setVisitorId(string $visitorId): static
    {
        $this->visitorId = $visitorId;

        return $this;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(?string $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function getPlatform(): ?string
    {
        return $this->platform;
    }

    public function setPlatform(?string $platform): static
    {
        $this->platform = $platform;

        return $this;
    }

    public function getTimeZone(): ?string
    {
        return $this->timeZone;
    }

    public function setTimeZone(?string $timeZone): static
    {
        $this->timeZone = $timeZone;

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent;

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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }
}