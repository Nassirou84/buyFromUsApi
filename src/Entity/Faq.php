<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FaqRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FaqRepository::class)]
class Faq
{
    public const ELEMENT_TYPES = ['title', 'paragraph', 'list', 'image', 'video'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?FaqCategory $category = null;

    /** Position inside the category ("order" is a reserved SQL word). */
    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $question = null;

    /**
     * The answer, as an ordered list of content blocks (title, paragraph, list, image, video).
     *
     * @var list<array<string, mixed>>
     */
    #[ORM\Column(type: 'json')]
    private array $elements = [];

    #[ORM\Column]
    private bool $published = true;

    #[ORM\Column]
    private bool $highlight = false;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $tags = [];

    /**
     * Optional call to action shown under the answer.
     *
     * @var array{label: string, url: string}|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $cta = null;

    /** Share of positive votes, between 0 and 1. */
    #[ORM\Column]
    private float $satisfactionRate = 0.0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): ?FaqCategory
    {
        return $this->category;
    }

    public function setCategory(?FaqCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function getQuestion(): ?string
    {
        return $this->question;
    }

    public function setQuestion(string $question): static
    {
        $this->question = $question;

        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getElements(): array
    {
        return $this->elements;
    }

    /**
     * @param list<array<string, mixed>> $elements
     */
    public function setElements(array $elements): static
    {
        $this->elements = $elements;

        return $this;
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function setPublished(bool $published): static
    {
        $this->published = $published;

        return $this;
    }

    public function isHighlight(): bool
    {
        return $this->highlight;
    }

    public function setHighlight(bool $highlight): static
    {
        $this->highlight = $highlight;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * @param list<string> $tags
     */
    public function setTags(array $tags): static
    {
        $this->tags = $tags;

        return $this;
    }

    /**
     * @return array{label: string, url: string}|null
     */
    public function getCta(): ?array
    {
        return $this->cta;
    }

    /**
     * @param array{label: string, url: string}|null $cta
     */
    public function setCta(?array $cta): static
    {
        $this->cta = $cta;

        return $this;
    }

    public function getSatisfactionRate(): float
    {
        return $this->satisfactionRate;
    }

    public function setSatisfactionRate(float $satisfactionRate): static
    {
        $this->satisfactionRate = $satisfactionRate;

        return $this;
    }
}
