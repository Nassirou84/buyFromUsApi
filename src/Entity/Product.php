<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Filter\ProductKeywordFilter;
use App\Filter\ProductNewArrivalsFilter;
use App\Filter\ProductPriceRangeFilter;
use App\Repository\ProductRepository;
use App\Service\PriceCalculator;
use App\State\ProductStateProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/products/get-one-product',
            paginationEnabled: false,
            normalizationContext: ['groups' => ['product:read', 'product:read:details']],
            filters: ['products.search_filter'],
        ),
        new \ApiPlatform\Metadata\Get(),
        new GetCollection(
            normalizationContext: ['groups' => ['product:read']],
            paginationItemsPerPage: 12,
            filters: ['products.search_filter', 'products.boolean_filter', 'products.order_filter', 'products.date_filter', ProductKeywordFilter::class, ProductPriceRangeFilter::class, ProductNewArrivalsFilter::class],
        ),
        new GetCollection(
            uriTemplate: '/admin/get-products',
            normalizationContext: ['groups' => ['product:read', 'product:read:admin', 'product:read:details']],
            paginationItemsPerPage: 9,
            filters: ['products.search_filter', 'products.boolean_filter', 'products.order_filter', 'products.date_filter', ProductKeywordFilter::class, ProductPriceRangeFilter::class, ProductNewArrivalsFilter::class],
        ),
        new \ApiPlatform\Metadata\Post(
            processor: ProductStateProcessor::class,
            security: "is_granted('ROLE_ADMIN')",
        ),
        new \ApiPlatform\Metadata\Put(
            security: "is_granted('ROLE_ADMIN')",
        ),
        new Patch(
            security: "is_granted('ROLE_ADMIN')",
        ),
        new Delete(
            security: "is_granted('ROLE_ADMIN')",
        ),
    ],
    normalizationContext: ['groups' => ['product:read', 'product:read:details', 'order:read', 'wishlist:read', 'basket:read']],
)]
#[ORM\Entity(repositoryClass: ProductRepository::class)]
class Product
{
    public const SALES_TAX_RATES = 0.18;

    public const PRODUCT_CONDITION_NEW = 'new';
    public const PRODUCT_CONDITION_USED = 'used';
    public const PRODUCT_CONDITION_REFURBISHED = 'refurbished';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['product:read', 'order:read', 'wishlist:read', 'basket:read'])]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['product:read', 'order:read', 'wishlist:read', 'basket:read'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $title = null;

    #[ORM\Column]
    #[Groups(['product:read', 'wishlist:read', 'basket:read'])]
    private ?\DateTime $createdAt = null;

    /**
     * @var Collection<int, Photo>
     */
    #[ORM\OneToMany(targetEntity: Photo::class, mappedBy: 'product', orphanRemoval: true)]
    #[Groups(['product:read', 'wishlist:read', 'order:read', 'basket:read'])]
    private Collection $photos;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['product:read:admin'])]
    #[Assert\Url]
    private ?string $scrappingUrl = null;

    #[ORM\Column(length: 255)]
    #[Groups(['product:read', 'order:read', 'wishlist:read', 'basket:read'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $seller = null;

    #[ORM\Column(length: 255)]
    #[Groups(['product:read', 'order:read', 'wishlist:read', 'basket:read'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $brand = null;

    #[ORM\Column]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    #[Groups(['product:read:admin'])]
    private ?float $usdPrice = null;

    #[ORM\Column]
    #[Assert\NotNull]
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
    #[Assert\NotNull]
    private ?Category $category = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['product:read:details'])]
    private ?array $features = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['product:read:details'])]
    private ?array $variants = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['product:read:admin'])]
    private ?\DateTime $lastScrappingAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['product:read:details'])]
    private ?array $details = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['product:read', 'order:read', 'wishlist:read', 'basket:read'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'The slug may only contain lowercase letters, digits and hyphens.')]
    private ?string $slug = null;

    #[ORM\ManyToOne(inversedBy: 'products')]
    #[Groups(['product:read:details'])]
    private ?Subcategory $subcategory = null;

    #[ORM\Column(length: 10)]
    #[Assert\Length(max: 10)]
    #[Groups(['product:read:details'])]
    private ?string $uid = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['product:read:admin'])]
    private ?\DateTime $updatedAt = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    #[Groups(['product:read:details', 'product:read'])]
    private ?float $latestPrice = null;

    #[ORM\Column]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    #[Groups(['product:read:details', 'product:read'])]
    private ?float $fullPrice = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 0, max: 5)]
    #[Groups(['product:read:details', 'product:read'])]
    private ?float $overallRating = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    #[Groups(['product:read:details', 'product:read'])]
    private ?int $overallRatingsCounts = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Groups(['product:read:admin'])]
    private ?string $returnPolicy = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: [self::PRODUCT_CONDITION_NEW, self::PRODUCT_CONDITION_USED, self::PRODUCT_CONDITION_REFURBISHED])]
    #[Groups(['product:read:details', 'product:read'])]
    private ?string $productCondition = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['product:read:details', 'product:read'])]
    private ?bool $acceptReturn = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['product:read:details', 'product:read'])]
    private ?bool $primeDelivery = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['product:read:details', 'product:read'])]
    private ?bool $sellerChoice = null;

    public function __construct(
    ) {
        $this->createdAt = new \DateTime();
        $this->photos = new ArrayCollection();
        $this->lastScrappingAt = null;
        $this->updatedAt = new \DateTime();
        $this->latestPrice = null;
        $this->acceptReturn = false;
        $this->primeDelivery = false;
        $this->sellerChoice = false;
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

    public function getCreatedAt(): ?\DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTime $createdAt): static
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

    #[Groups(['product:read:admin'])]
    #[SerializedName('isAvailable')]
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

    public function getLastScrappingAt(): ?\DateTime
    {
        return $this->lastScrappingAt;
    }

    public function setLastScrappingAt(?\DateTime $lastScrappingAt): static
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

    /**
     * Variants with their retail "price" converted with the same effective multiplier as the product's own
     * retail price (getActualPrice() / fullPrice), so a variant priced at fullPrice shows exactly getActualPrice()
     * and no extra margin or tax is stacked on top. Falls back to the getActualPrice() formula when fullPrice is unusable.
     * Kept separate from "variants" so the raw values can be edited and saved back untouched.
     *
     * @return array<int, array<string, mixed>>|null
     */
    #[Groups(['product:read:details'])]
    public function getActualVariants(): ?array
    {
        if (null === $this->variants) {
            return null;
        }

        $actualPrice = $this->getActualPrice();
        $multiplier = null !== $this->fullPrice && $this->fullPrice > 0 && null !== $this->usdPrice
            ? $actualPrice / $this->fullPrice
            : null;

        return array_map(static function (mixed $variant) use ($multiplier): mixed {
            if (!is_array($variant)) {
                return $variant;
            }

            $price = $variant['price'] ?? null;
            if (is_string($price)) {
                // Take the first amount only ("$1,299.99", "USD 19.99 - 29.99"), never glue several numbers together.
                $price = preg_match('/\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?|\.\d+/', $price, $matches) ? str_replace(',', '', $matches[0]) : null;
            }
            if (!is_numeric($price) || (float) $price <= 0) {
                $variant['actualPrice'] = null;
            } else {
                $variant['actualPrice'] = null !== $multiplier
                    ? round((float) $price * $multiplier)
                    : PriceCalculator::calculate((float) $price);
            }

            return $variant;
        }, $this->variants);
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

    public function getUid(): ?string
    {
        return $this->uid;
    }

    public function setUid(string $uid): static
    {
        $this->uid = $uid;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTime
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTime $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getLatestPrice(): ?float
    {
        return $this->latestPrice;
    }

    public function setLatestPrice(?float $latestPrice): static
    {
        $this->latestPrice = $latestPrice;

        return $this;
    }

    public function getFullPrice(): ?float
    {
        return $this->fullPrice;
    }

    public function setFullPrice(float $fullPrice): static
    {
        $this->fullPrice = $fullPrice;

        return $this;
    }

    public function getOverallRating(): ?float
    {
        return $this->overallRating;
    }

    public function setOverallRating(?float $overallRating): static
    {
        $this->overallRating = $overallRating;

        return $this;
    }

    public function getOverallRatingsCounts(): ?int
    {
        return $this->overallRatingsCounts;
    }

    public function setOverallRatingsCounts(?int $overallRatingsCounts): static
    {
        $this->overallRatingsCounts = $overallRatingsCounts;

        return $this;
    }

    public function getReturnPolicy(): ?string
    {
        return $this->returnPolicy;
    }

    public function setReturnPolicy(?string $returnPolicy): static
    {
        $this->returnPolicy = $returnPolicy;

        return $this;
    }

    public function getProductCondition(): ?string
    {
        return $this->productCondition;
    }

    public function setProductCondition(string $productCondition): static
    {
        $this->productCondition = $productCondition;

        return $this;
    }

    public function isAcceptReturn(): ?bool
    {
        return $this->acceptReturn;
    }

    public function setAcceptReturn(?bool $acceptReturn): static
    {
        $this->acceptReturn = $acceptReturn;

        return $this;
    }

    #[Groups(['product:read:details', 'product:read'])]
    public function isPrimeDelivery(): ?bool
    {
        return $this->primeDelivery;
    }

    public function setPrimeDelivery(?bool $primeDelivery): static
    {
        $this->primeDelivery = $primeDelivery;

        return $this;
    }


    #[Groups(['product:read:details', 'product:read'])]
    public function isSellerChoice(): ?bool
    {
        return $this->sellerChoice;
    }

    public function setSellerChoice(?bool $sellerChoice): static
    {
        $this->sellerChoice = $sellerChoice;

        return $this;
    }
}