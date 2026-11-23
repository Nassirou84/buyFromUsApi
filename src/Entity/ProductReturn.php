<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Filter\ProductReturnKeywordFilter;
use App\Repository\ProductReturnRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new Get(),
        new GetCollection(
            security: "is_granted('ROLE_ADMIN')",
            filters: ['return_request.search_filter', ProductReturnKeywordFilter::class],
        ),
        new Post(
            routeName: 'app_product_return',
            uriTemplate: '/api/product-return/initiate',
        )
    ],
    normalizationContext: ['groups' => ['product_return:read']]
)]
#[ORM\Entity(repositoryClass: ProductReturnRepository::class)]
class ProductReturn
{
    const STORE_CREDIT = 'credit';
    const REFUND = 'refund';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['product_return:read'])]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'productReturns')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['product_return:read'])]
    #[Assert\NotNull]
    private ?Order $returnOrder = null;

    #[ORM\Column(length: 255)]
    #[Groups(['product_return:read'])]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column]
    #[Assert\NotNull]
    #[Groups(['product_return:read'])]
    private ?\DateTime $createdAt = null;

    #[ORM\Column(length: 255)]
    #[Groups(['product_return:read'])]
    #[Assert\NotNull]
    private ?string $refundtype = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['product_return:read'])]
    private ?string $additionalDetails = null;

    #[ORM\Column(length: 255)]
    #[Groups(['product_return:read'])]
    #[Assert\NotNull]
    private ?string $uid = null;

    #[ORM\Column(length: 20)]
    #[Groups(['product_return:read'])]
    #[Assert\NotNull]
    private ?string $status = null;

    /**
     * @var Collection<int, ProductReturnItem>
     */
    #[ORM\OneToMany(targetEntity: ProductReturnItem::class, mappedBy: 'productReturn', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['product_return:read'])]
    private Collection $returnItems;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->returnItems = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReturnOrder(): ?Order
    {
        return $this->returnOrder;
    }

    public function setReturnOrder(?Order $returnOrder): static
    {
        $this->returnOrder = $returnOrder;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

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

    public function getRefundtype(): ?string
    {
        return $this->refundtype;
    }

    public function setRefundtype(string $refundtype): static
    {
        $this->refundtype = $refundtype;

        return $this;
    }

    public function getAdditionalDetails(): ?string
    {
        return $this->additionalDetails;
    }

    public function setAdditionalDetails(?string $additionalDetails): static
    {
        $this->additionalDetails = $additionalDetails;

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

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    /**
     * @return Collection<int, ProductReturnItem>
     */
    public function getReturnItems(): Collection
    {
        return $this->returnItems;
    }

    public function addReturnItem(ProductReturnItem $returnItem): static
    {
        if (!$this->returnItems->contains($returnItem)) {
            $this->returnItems->add($returnItem);
            $returnItem->setProductReturn($this);
        }

        return $this;
    }

    public function removeReturnItem(ProductReturnItem $returnItem): static
    {
        if ($this->returnItems->removeElement($returnItem)) {
            // set the owning side to null (unless already changed)
            if ($returnItem->getProductReturn() === $this) {
                $returnItem->setProductReturn(null);
            }
        }

        return $this;
    }
}