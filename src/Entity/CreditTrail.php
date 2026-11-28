<?php

namespace App\Entity;

use App\Repository\CreditTrailRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CreditTrailRepository::class)]
class CreditTrail
{
    const ACTION_CREDIT = 'refund';
    const ACTION_DEBIT = 'purchase';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $customer = null;

    #[ORM\Column]
    private ?int $amount = null;

    #[ORM\Column]
    private ?\DateTime $createdAt = null;

    #[ORM\Column(length: 20)]
    private ?string $action = null;

    #[ORM\ManyToOne]
    private ?User $creditedBy = null;

    /** Return that produced this credit (null for purchases or manual adjustments). */
    #[ORM\ManyToOne]
    private ?ProductReturn $productReturn = null;

    /** Customer credit balance right after this movement. */
    #[ORM\Column(nullable: true)]
    private ?int $balanceAfter = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): ?User
    {
        return $this->customer;
    }

    public function setCustomer(?User $customer): static
    {
        $this->customer = $customer;

        return $this;
    }

    public function getAmount(): ?int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): static
    {
        $this->amount = $amount;

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

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;

        return $this;
    }

    public function getCreditedBy(): ?User
    {
        return $this->creditedBy;
    }

    public function setCreditedBy(?User $creditedBy): static
    {
        $this->creditedBy = $creditedBy;

        return $this;
    }

    public function getProductReturn(): ?ProductReturn
    {
        return $this->productReturn;
    }

    public function setProductReturn(?ProductReturn $productReturn): static
    {
        $this->productReturn = $productReturn;

        return $this;
    }

    public function getBalanceAfter(): ?int
    {
        return $this->balanceAfter;
    }

    public function setBalanceAfter(?int $balanceAfter): static
    {
        $this->balanceAfter = $balanceAfter;

        return $this;
    }
}
