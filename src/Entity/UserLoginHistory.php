<?php

namespace App\Entity;

use App\Repository\UserLoginHistoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserLoginHistoryRepository::class)]
class UserLoginHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'userLoginHistories')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 255)]
    private ?string $userAgent = null;

    #[ORM\Column(length: 255)]
    private ?string $userAgentHash = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column]
    private ?\DateTime $lastUsedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTime $firstSeenAt = null;

    #[ORM\Column]
    private ?bool $isTrusted = true;

    public function __construct()
    {
        $this->lastUsedAt = new \DateTime();
        $this->isTrusted = true;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(string $userAgent): static
    {
        $this->userAgent = $userAgent;
        $this->userAgentHash = hash('sha256', $userAgent);
        return $this;
    }

    public function getUserAgentHash(): ?string
    {
        return $this->userAgentHash;
    }

    public function setUserAgentHash(string $userAgentHash): static
    {
        $this->userAgentHash = $userAgentHash;

        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;

        return $this;
    }

    public function getLastUsedAt(): ?\DateTime
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(\DateTime $lastUsedAt): static
    {
        $this->lastUsedAt = $lastUsedAt;

        return $this;
    }

    public function getFirstSeenAt(): ?\DateTime
    {
        return $this->firstSeenAt;
    }

    public function setFirstSeenAt(?\DateTime $firstSeenAt): static
    {
        $this->firstSeenAt = $firstSeenAt;

        return $this;
    }

    public function isTrusted(): ?bool
    {
        return $this->isTrusted;
    }

    public function setIsTrusted(bool $isTrusted): static
    {
        $this->isTrusted = $isTrusted;

        return $this;
    }
}