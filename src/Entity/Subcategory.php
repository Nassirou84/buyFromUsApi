<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;

use App\Repository\SubcategoryRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    operations: [
        new \ApiPlatform\Metadata\Get(),
        new \ApiPlatform\Metadata\GetCollection(),
        new \ApiPlatform\Metadata\Post(
            denormalizationContext: ['groups' => ['subcategory:write']],
            security: "is_granted('ROLE_ADMIN')",
        ),
        new \ApiPlatform\Metadata\Put(
            security: "is_granted('ROLE_ADMIN')",
        ),
        new \ApiPlatform\Metadata\Delete(
            security: "is_granted('ROLE_ADMIN')",
        ),
        new \ApiPlatform\Metadata\Patch(
            denormalizationContext: ['groups' => ['subcategory:write']],
            security: "is_granted('ROLE_ADMIN')",
        ),
    ],
    normalizationContext: ['groups' => ['subcategory:read', 'product:read:details', 'category:read:admin']],
)]
#[ORM\Entity(repositoryClass: SubcategoryRepository::class)]
class Subcategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['subcategory:read', 'product:read:details'])]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['subcategory:read', 'subcategory:write', 'product:read:details', 'subcategory:write', 'subcategory:write'])]
    private ?string $title = null;

    #[ORM\ManyToOne(inversedBy: 'subcategories')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['subcategory:write'])]
    private ?Category $category = null;

    #[ORM\Column]
    #[Groups(['subcategory:read', 'product:read:details', 'category:read:admin'])]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    #[Groups(['subcategory:read', 'product:read:details', 'category:read:admin', 'subcategory:write'])]
    private ?bool $isActive = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['subcategory:read', 'subcategory:write', 'product:read:details', 'category:read:admin', 'subcategory:write'])]
    private ?string $description = null;

    /**
     * @var Collection<int, Product>
     */
    #[ORM\OneToMany(targetEntity: Product::class, mappedBy: 'subcategory')]
    private Collection $products;

    #[ORM\Column]
    #[Groups(['category:read:admin', 'subcategory:write'])]
    private ?int $markup = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->isActive = true;
        $this->products = new ArrayCollection();
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

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;

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

    #[Groups(['category:read:admin'])]
    public function isActive(): ?bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return Collection<int, Product>
     */
    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function addProduct(Product $product): static
    {
        if (!$this->products->contains($product)) {
            $this->products->add($product);
            $product->setSubcategory($this);
        }

        return $this;
    }

    #[Groups(['subcategory:read', 'category:read:admin'])]
    public function getProductLength(): int
    {
        return $this->products->count();
    }

    public function removeProduct(Product $product): static
    {
        if ($this->products->removeElement($product)) {
            // set the owning side to null (unless already changed)
            if ($product->getSubcategory() === $this) {
                $product->setSubcategory(null);
            }
        }

        return $this;
    }

    public function getMarkup(): ?int
    {
        return $this->markup;
    }

    public function setMarkup(int $markup): static
    {
        $this->markup = $markup;

        return $this;
    }
}