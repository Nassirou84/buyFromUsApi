<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Repository\CourrierRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use ApiPlatform\Metadata\Post;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_ADMIN')"
        ),
        new Post(
            security: "is_granted('ROLE_ADMIN')"
        ),
        new Patch(
            security: "is_granted('ROLE_ADMIN')"
        )
    ],
    normalizationContext: ['groups' => ['courrier:read', 'order:read:admin']],
    denormalizationContext: ['groups' => ['courrier:write']]
)]
#[ORM\Entity(repositoryClass: CourrierRepository::class)]
class Courrier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['courrier:read', 'order:read:admin'])]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['courrier:read', 'courrier:write', 'order:read:admin'])]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\Column(length: 20)]
    #[Groups(['courrier:read', 'courrier:write', 'order:read:admin'])]
    #[Assert\NotBlank]
    private ?string $contact = null;

    #[ORM\Column(length: 255)]
    #[Groups(['courrier:read', 'courrier:write', 'order:read:admin'])]
    #[Assert\NotBlank]
    private ?string $pickupLocation = null;

    #[ORM\Column(length: 20)]
    #[Groups(['courrier:read', 'courrier:write', 'order:read:admin'])]
    #[Assert\NotBlank]
    private ?string $pickupContact = null;

    #[ORM\Column(length: 255)]
    #[Groups(['courrier:read', 'courrier:write', 'order:read:admin'])]
    #[Assert\NotBlank]
    private ?string $usaAddress = null;

    /**
     * @var Collection<int, OrderProcessed>
     */
    #[ORM\OneToMany(targetEntity: OrderProcessed::class, mappedBy: 'courrier')]
    private Collection $orderProcesseds;

    #[ORM\Column]
    #[Groups(['courrier:read'])]
    private ?\DateTime $createdAt = null;

    public function __construct()
    {
        $this->orderProcesseds = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getContact(): ?string
    {
        return $this->contact;
    }

    public function setContact(string $contact): static
    {
        $this->contact = $contact;

        return $this;
    }

    public function getPickupLocation(): ?string
    {
        return $this->pickupLocation;
    }

    public function setPickupLocation(string $pickupLocation): static
    {
        $this->pickupLocation = $pickupLocation;

        return $this;
    }

    public function getPickupContact(): ?string
    {
        return $this->pickupContact;
    }

    public function setPickupContact(string $pickupContact): static
    {
        $this->pickupContact = $pickupContact;

        return $this;
    }

    public function getUsaAddress(): ?string
    {
        return $this->usaAddress;
    }

    public function setUsaAddress(string $usaAddress): static
    {
        $this->usaAddress = $usaAddress;

        return $this;
    }

    /**
     * @return Collection<int, OrderProcessed>
     */
    public function getOrderProcesseds(): Collection
    {
        return $this->orderProcesseds;
    }

    public function addOrderProcessed(OrderProcessed $orderProcessed): static
    {
        if (!$this->orderProcesseds->contains($orderProcessed)) {
            $this->orderProcesseds->add($orderProcessed);
            $orderProcessed->setCourrier($this);
        }

        return $this;
    }

    public function removeOrderProcessed(OrderProcessed $orderProcessed): static
    {
        if ($this->orderProcesseds->removeElement($orderProcessed)) {
            // set the owning side to null (unless already changed)
            if ($orderProcessed->getCourrier() === $this) {
                $orderProcessed->setCourrier(null);
            }
        }

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
}