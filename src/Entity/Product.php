<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Repository\ProductRepository;
use App\Service\PriceCalculator;
use App\State\ProductStateProcessor;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    operations: [
        new \ApiPlatform\Metadata\Get(),
        new GetCollection(
            normalizationContext: ['groups' => ['product:read', 'product:read:details']],
            paginationItemsPerPage: 12,
            filters: ['products.search_filter', 'products.order_filter', 'products.range_filter'],
        ),
        new \ApiPlatform\Metadata\Post(
            processor: ProductStateProcessor::class,
            security: "is_granted('ROLE_ADMIN')",
        ),
        new \ApiPlatform\Metadata\Put(),
        new GetCollection(
            security: "is_granted('ROLE_ADMIN')",
            routeName: 'app_product_scrape',
            name: 'app_product_scrape',
            paginationEnabled: false,
            normalizationContext: ['groups' => ['product:read', 'product:read:details']],
        ),
        new Patch(
            security: "is_granted('ROLE_ADMIN')",
        ),
        new Delete(
            security: "is_granted('ROLE_ADMIN')",
        ),
    ],
    normalizationContext: ['groups' => ['product:read', 'product:read:details', 'order:read', 'wishlist:read', 'basket:read']],
    filters: ['products.search_filter', 'products.order_filter'],
)]
#[ORM\Entity(repositoryClass: ProductRepository::class)]
class Product
{
    public const SALES_TAX_RATES = 0.18;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['product:read', 'order:read', 'wishlist:read', 'basket:read'])]
    // @phpstan-ignore property.onlyRead
    private $id;

    #[ORM\Column(length: 255)]
    #[Groups(['product:read', 'order:read', 'wishlist:read', 'basket:read'])]
    private ?string $title = null;

    #[ORM\Column]
    #[Groups(['product:read', 'wishlist:read', 'basket:read'])]
    private ?DateTime $createdAt = null;

    /**
     * @var Collection<int, Photo>
     */
    #[ORM\OneToMany(targetEntity: Photo::class, mappedBy: 'product', orphanRemoval: true)]
    #[Groups(['product:read', 'wishlist:read', 'order:read', 'basket:read'])]
    private Collection $photos;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['product:read'])]
    private ?string $scrappingUrl = null;

    #[ORM\Column(length: 255)]
    #[Groups(['product:read', 'order:read', 'wishlist:read', 'basket:read'])]
    private ?string $seller = null;

    #[ORM\Column(length: 255)]
    #[Groups(['product:read', 'order:read', 'wishlist:read', 'basket:read'])]
    private ?string $brand = null;

    #[ORM\Column]
    #[Groups(['product:read'])]
    private ?float $usdPrice = null;

    #[ORM\Column]
    #[Groups(['product:read', 'wishlist:read', 'basket:read'])]
    private ?bool $isAvailable = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['product:read:details'])]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['product:read:details'])]
    private ?string $customerSays = null;

    #[ORM\ManyToOne(inversedBy: 'products')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['product:read:details'])]
    private ?Category $category = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['product:read:details'])]
    private ?array $features = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['product:read:details'])]
    private ?array $variants = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['product:read'])]
    private ?DateTime $lastScrappingAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['product:read:details'])]
    private ?array $details = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['product:read', 'order:read', 'wishlist:read', 'basket:read'])]
    private ?string $slug = null;

    #[ORM\ManyToOne(inversedBy: 'products')]
    private ?Subcategory $subcategory = null;

    public function __construct(
    ) {
        $this->createdAt = new DateTime();
        $this->photos = new ArrayCollection();
        $this->lastScrappingAt = null;
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

    public function getCreatedAt(): ?DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTime $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /**
     * @return Collection<int, Photo>
     */
    public function getPhotos(): Collection
    {
        return $this->photos;
    }

    public function addPhoto(Photo $photo): static
    {
        if (!$this->photos->contains($photo)) {
            $this->photos->add($photo);
            $photo->setProduct($this);
        }

        return $this;
    }

    public function removePhoto(Photo $photo): static
    {
        if ($this->photos->removeElement($photo)) {
            // set the owning side to null (unless already changed)
            if ($photo->getProduct() === $this) {
                $photo->setProduct(null);
            }
        }

        return $this;
    }

    public function getScrappingUrl(): ?string
    {
        return $this->scrappingUrl;
    }

    public function setScrappingUrl(?string $scrappingUrl): static
    {
        $this->scrappingUrl = $scrappingUrl;

        return $this;
    }

    public function getSeller(): ?string
    {
        return $this->seller;
    }

    public function setSeller(string $seller): static
    {
        $this->seller = $seller;

        return $this;
    }

    public function getBrand(): ?string
    {
        return $this->brand;
    }

    public function setBrand(string $brand): static
    {
        $this->brand = $brand;

        return $this;
    }

    public function getUsdPrice(): ?float
    {
        return $this->usdPrice;
    }

    public function setUsdPrice(float $usdPrice): static
    {
        $this->usdPrice = $usdPrice;

        return $this;
    }

    public function isAvailable(): ?bool
    {
        return $this->isAvailable;
    }

    public function setIsAvailable(bool $isAvailable): static
    {
        $this->isAvailable = $isAvailable;

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

    public function getCustomerSays(): ?string
    {
        return $this->customerSays;
    }

    public function setCustomerSays(?string $customerSays): static
    {
        $this->customerSays = $customerSays;

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

    public function getFeatures(): ?array
    {
        return $this->features;
    }

    public function setFeatures(?array $features): static
    {
        $this->features = $features;

        return $this;
    }

    public function getVariants(): ?array
    {
        return $this->variants;
    }

    public function setVariants(?array $variants): static
    {
        $this->variants = $variants;

        return $this;
    }

    public function getLastScrappingAt(): ?DateTime
    {
        return $this->lastScrappingAt;
    }

    public function setLastScrappingAt(?DateTime $lastScrappingAt): static
    {
        $this->lastScrappingAt = $lastScrappingAt;

        return $this;
    }

    public function getDetails(): ?array
    {
        return $this->details;
    }

    public function setDetails(?array $details): static
    {
        $this->details = $details;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    #[Groups(['product:read', 'product:read:details', 'order:read', 'basket:read', 'wishlist:read'])]
    public function getActualPrice(): ?float
    {
        return PriceCalculator::calculate($this->usdPrice);
    }

    public function getSubcategory(): ?Subcategory
    {
        return $this->subcategory;
    }

    public function setSubcategory(?Subcategory $subcategory): static
    {
        $this->subcategory = $subcategory;

        return $this;
    }
}
