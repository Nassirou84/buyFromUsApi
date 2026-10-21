<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FaqCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FaqCategoryRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_FAQ_CATEGORY_SLUG', fields: ['slug'])]
class FaqCategory
{
    public const ACCORDION_MULTI_OPEN = 'multi-open';
    public const ACCORDION_STRICT = 'strict';
    public const ACCORDION_EXPANDED = 'expanded';
    public const ACCORDION_BEHAVIORS = [
        self::ACCORDION_MULTI_OPEN,
        self::ACCORDION_STRICT,
        self::ACCORDION_EXPANDED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $slug = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    /** Icon name from the admin icon set, e.g. "local_shipping". */
    #[ORM\Column(length: 100)]
    private string $icon = 'help_outline';

    #[ORM\Column(length: 20)]
    private string $accordionBehavior = self::ACCORDION_MULTI_OPEN;

    /**
     * Storefront pages where this block is shown (e.g. "all_pages", "checkout", "pdp").
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $targetPages = ['all_pages'];

    #[ORM\Column]
    private int $displayOrder = 0;

    /** @var Collection<int, Faq> */
    #[ORM\OneToMany(targetEntity: Faq::class, mappedBy: 'category', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
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

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function setIcon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function getAccordionBehavior(): string
    {
        return $this->accordionBehavior;
    }

    public function setAccordionBehavior(string $accordionBehavior): static
    {
        $this->accordionBehavior = $accordionBehavior;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getTargetPages(): array
    {
        return $this->targetPages;
    }

    /**
     * @param list<string> $targetPages
     */
    public function setTargetPages(array $targetPages): static
    {
        $this->targetPages = $targetPages;

        return $this;
    }

    public function getDisplayOrder(): int
    {
        return $this->displayOrder;
    }

    public function setDisplayOrder(int $displayOrder): static
    {
        $this->displayOrder = $displayOrder;

        return $this;
    }

    /**
     * @return Collection<int, Faq>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(Faq $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setCategory($this);
        }

        return $this;
    }

    public function removeItem(Faq $item): static
    {
        $this->items->removeElement($item);

        return $this;
    }
}
