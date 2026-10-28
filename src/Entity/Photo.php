<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\PhotoRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    normalizationContext: ['groups' => ['photo:read', 'product:read', 'product:read:details', 'wishlist:read']],
    types: ['https://schema.org/MediaObject'],
    operations: [
        new \ApiPlatform\Metadata\Get(),
        new \ApiPlatform\Metadata\Post(
            security: "is_granted('ROLE_ADMIN')",
            controller: \App\Controller\CreateMediaObjectController::class,
            deserialize: false,
            inputFormats: [
                'multipart' => ['multipart/form-data'],
            ],
        ),
    ]
)]
#[ORM\Entity(repositoryClass: PhotoRepository::class)]
class Photo
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['photo:read', 'wishlist:read'])]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\Column]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(length: 255)]
    #[Groups(['photo:read', 'product:read', 'product:read:details', 'basket:read', 'wishlist:read'])]
    private ?string $url = null;

    #[ORM\ManyToOne(inversedBy: 'photos')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Product $product = null;

    #[Assert\File(
        maxSize: '5M',
        mimeTypes: [
            'image/*',
            'video/*',
            'audio/*',
            'application/pdf',
        ],
    )]
    public ?UploadedFile $file = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['photo:read', 'product:read', 'product:read:details', 'basket:read', 'wishlist:read'])]
    private ?bool $isCover = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->isCover = false;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): static
    {
        $this->product = $product;

        return $this;
    }

    #[Groups(['photo:read', 'product:read', 'product:read:details', 'basket:read'])]
    #[SerializedName('isCover')]
    public function isCover(): ?bool
    {
        return $this->isCover;
    }

    public function setIsCover(?bool $isCover): static
    {
        $this->isCover = $isCover;

        return $this;
    }
}