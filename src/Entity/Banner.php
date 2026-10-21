<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\BannerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;

#[ApiResource(
    operations: [
        new \ApiPlatform\Metadata\Get(),
        new \ApiPlatform\Metadata\GetCollection(
            order: ['createdAt' => 'asc']
        ),
        new \ApiPlatform\Metadata\Post(security: "is_granted('ROLE_ADMIN')"),
        new \ApiPlatform\Metadata\Patch(security: "is_granted('ROLE_ADMIN')"),
        new \ApiPlatform\Metadata\Delete(security: "is_granted('ROLE_ADMIN')")
    ],
    denormalizationContext: ['groups' => ['banner:write']],
    normalizationContext: ['groups' => ['banner:read']]
)]
#[ORM\Entity(repositoryClass: BannerRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_BANNER_NAME', fields: ['name'])]
class Banner
{
    const WELCOME_EMAIL_BANNER_NAME = 'welcome_email_banner';
    const HERO_BANNER_NAME = 'hero_banner';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['banner:read'])]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['banner:read', 'banner:write'])]
    private ?string $title = null;

    #[ORM\Column(length: 255)]
    #[Groups(['banner:read', 'banner:write'])]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['banner:read', 'banner:write'])]
    private ?string $content = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['banner:read', 'banner:write'])]
    private ?string $imageUrl = null;

    #[ORM\Column]
    #[Groups(['banner:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    #[Groups(['banner:read'])]
    private ?\DateTime $updatedAt = null;

    #[ORM\Column]
    #[Groups(['banner:write'])]
    private ?bool $isActive = null;

    #[ORM\ManyToOne]
    #[Groups(['banner:read'])]
    private ?User $updatedBy = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTime();
        $this->isActive = true;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(?string $imageUrl): static
    {
        $this->imageUrl = $imageUrl;

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

    public function getUpdatedAt(): ?\DateTime
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTime $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    #[Groups(['banner:read'])]
    #[SerializedName('isActive')]
    public function isActive(): ?bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?User $updatedBy): static
    {
        $this->updatedBy = $updatedBy;

        return $this;
    }
}