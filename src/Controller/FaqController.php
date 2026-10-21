<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Faq;
use App\Entity\FaqCategory;
use App\Repository\FaqCategoryRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * FAQ content is edited as one tree in the admin builder (categories holding questions),
 * so the admin saves it in a single call that creates, updates and removes rows to match.
 */
final class FaqController extends AbstractController
{
    private const MAX_CATEGORIES = 50;
    private const MAX_ITEMS_PER_CATEGORY = 200;
    private const MAX_ELEMENTS = 100;
    private const MAX_ELEMENTS_JSON_BYTES = 200_000;

    /**
     * Public: what the storefront renders. Unpublished questions and empty categories are left out.
     */
    #[Route('api/faq', name: 'app_faq_public', methods: ['GET'])]
    public function publicList(FaqCategoryRepository $categoryRepository): JsonResponse
    {
        $categories = $this->serializeTree($categoryRepository, true);

        return $this->json(array_values(array_filter(
            $categories,
            static fn (array $category): bool => [] !== $category['items'],
        )));
    }

    /**
     * Admin: the full tree, drafts included.
     */
    #[Route('api/admin/faq', name: 'app_admin_faq_list', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminList(FaqCategoryRepository $categoryRepository): JsonResponse
    {
        return $this->json($this->serializeTree($categoryRepository, false));
    }

    /**
     * Admin: replaces the stored tree with the one sent. Ids that match a stored row update it,
     * any other id creates a row, and stored rows that are not sent are deleted.
     * Responds with the saved tree so the client can pick up the real ids.
     */
    #[Route('api/admin/faq', name: 'app_admin_faq_sync', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function sync(
        Request $request,
        EntityManagerInterface $entityManager,
        FaqCategoryRepository $categoryRepository,
    ): JsonResponse {
        $body = json_decode($request->getContent(), true);
        $categoriesPayload = \is_array($body) ? ($body['categories'] ?? null) : null;
        if (!\is_array($categoriesPayload) || !array_is_list($categoriesPayload)) {
            return $this->json(['error' => 'Expected a "categories" list.'], Response::HTTP_BAD_REQUEST);
        }

        $errors = [];
        $parsed = $this->parseCategories($categoriesPayload, $errors);
        if ([] !== $errors) {
            return $this->json(['error' => 'Invalid FAQ content.', 'details' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $entityManager->wrapInTransaction(function () use ($entityManager, $categoryRepository, $parsed): void {
                /** @var array<string, FaqCategory> $existing */
                $existing = [];
                foreach ($categoryRepository->findAll() as $category) {
                    $existing[(string) $category->getId()] = $category;
                }

                $keptIds = array_filter(array_column($parsed, 'id'), static fn (?string $id): bool => null !== $id && isset($existing[$id]));
                foreach ($existing as $id => $category) {
                    if (!\in_array((string) $id, $keptIds, true)) {
                        $entityManager->remove($category);
                    }
                }
                // Deletes first so a new category can reuse the slug of a removed one.
                $entityManager->flush();

                foreach ($parsed as $position => $data) {
                    $category = null !== $data['id'] ? ($existing[$data['id']] ?? null) : null;
                    if (null === $category) {
                        $category = new FaqCategory();
                        $entityManager->persist($category);
                    }
                    $this->applyCategory($category, $data, $position + 1, $entityManager);
                }
                $entityManager->flush();
            });
        } catch (UniqueConstraintViolationException) {
            return $this->json(['error' => 'Another category already uses one of these slugs.'], Response::HTTP_CONFLICT);
        }

        $entityManager->clear();

        return $this->json($this->serializeTree($categoryRepository, false));
    }

    /**
     * Admin: creates an empty category at the end of the list. Questions are added with the tree save.
     */
    #[Route('api/admin/faq/categories', name: 'app_admin_faq_category_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function createCategory(
        Request $request,
        EntityManagerInterface $entityManager,
        FaqCategoryRepository $categoryRepository,
    ): JsonResponse {
        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            return $this->json(['error' => 'Expected a JSON object.'], Response::HTTP_BAD_REQUEST);
        }
        if ($categoryRepository->count([]) >= self::MAX_CATEGORIES) {
            return $this->json(['error' => sprintf('At most %d categories are allowed.', self::MAX_CATEGORIES)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $errors = [];
        $fields = $this->parseCategoryFields($body, 'category', $errors);
        if ([] !== $errors) {
            return $this->json(['error' => 'Invalid FAQ category.', 'details' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (null !== $categoryRepository->findOneBy(['slug' => $fields['slug']])) {
            return $this->json(['error' => 'Another category already uses this slug.'], Response::HTTP_CONFLICT);
        }

        $category = new FaqCategory();
        $this->applyCategoryFields($category, $fields);
        $category->setDisplayOrder($categoryRepository->count([]) + 1);

        try {
            $entityManager->persist($category);
            $entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->json(['error' => 'Another category already uses this slug.'], Response::HTTP_CONFLICT);
        }

        return $this->json($this->serializeCategory($category, false), Response::HTTP_CREATED);
    }

    /**
     * Admin: updates the settings of one category. Fields that are not sent keep their value,
     * and its questions are left untouched.
     */
    #[Route('api/admin/faq/categories/{id}', name: 'app_admin_faq_category_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateCategory(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        FaqCategoryRepository $categoryRepository,
    ): JsonResponse {
        $category = $categoryRepository->find($id);
        if (null === $category) {
            return $this->json(['error' => 'Category not found.'], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            return $this->json(['error' => 'Expected a JSON object.'], Response::HTTP_BAD_REQUEST);
        }

        $errors = [];
        $fields = $this->parseCategoryFields([
            'slug' => $category->getSlug(),
            'title' => $category->getTitle(),
            'icon' => $category->getIcon(),
            'accordionBehavior' => $category->getAccordionBehavior(),
            'targetPages' => $category->getTargetPages(),
            ...array_intersect_key($body, array_flip(['slug', 'title', 'icon', 'accordionBehavior', 'targetPages'])),
        ], 'category', $errors);
        if ([] !== $errors) {
            return $this->json(['error' => 'Invalid FAQ category.', 'details' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $sameSlug = $categoryRepository->findOneBy(['slug' => $fields['slug']]);
        if (null !== $sameSlug && $sameSlug->getId() !== $category->getId()) {
            return $this->json(['error' => 'Another category already uses this slug.'], Response::HTTP_CONFLICT);
        }

        $this->applyCategoryFields($category, $fields);
        $entityManager->flush();

        return $this->json($this->serializeCategory($category, false));
    }

    /**
     * @param array{slug: ?string, title: ?string, icon: ?string, accordionBehavior: mixed, targetPages: list<string>} $fields already validated
     */
    private function applyCategoryFields(FaqCategory $category, array $fields): void
    {
        $category
            ->setSlug((string) $fields['slug'])
            ->setTitle((string) $fields['title'])
            ->setIcon((string) $fields['icon'])
            ->setAccordionBehavior((string) $fields['accordionBehavior'])
            ->setTargetPages($fields['targetPages']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyCategory(FaqCategory $category, array $data, int $displayOrder, EntityManagerInterface $entityManager): void
    {
        $this->applyCategoryFields($category, $data);
        $category->setDisplayOrder($displayOrder);

        /** @var array<string, Faq> $existingItems */
        $existingItems = [];
        foreach ($category->getItems() as $item) {
            $existingItems[(string) $item->getId()] = $item;
        }

        $keptIds = array_filter(array_column($data['items'], 'id'), static fn (?string $id): bool => null !== $id && isset($existingItems[$id]));
        foreach ($existingItems as $id => $item) {
            if (!\in_array((string) $id, $keptIds, true)) {
                $category->removeItem($item);
            }
        }

        foreach ($data['items'] as $position => $itemData) {
            $item = null !== $itemData['id'] ? ($existingItems[$itemData['id']] ?? null) : null;
            if (null === $item) {
                $item = new Faq();
                $category->addItem($item);
                $entityManager->persist($item);
            }
            $item
                ->setSortOrder($position + 1)
                ->setQuestion($itemData['question'])
                ->setElements($itemData['elements'])
                ->setPublished($itemData['published'])
                ->setHighlight($itemData['highlight'])
                ->setTags($itemData['tags'])
                ->setCta($itemData['cta'])
                ->setSatisfactionRate($itemData['satisfactionRate']);
        }
    }

    /**
     * Validates the settings of one category (everything but its questions).
     *
     * @param array<mixed> $raw
     * @param list<string> $errors
     *
     * @return array{slug: ?string, title: ?string, icon: ?string, accordionBehavior: mixed, targetPages: list<string>}
     */
    private function parseCategoryFields(array $raw, string $where, array &$errors): array
    {
        $slug = $this->string($raw['slug'] ?? null, 255);
        if (null === $slug || 1 !== preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug)) {
            $errors[] = "$where.slug must be lowercase letters, digits and single dashes.";
            $slug = null;
        }

        $title = $this->string($raw['title'] ?? null, 255);
        if (null === $title) {
            $errors[] = "$where.title is required (255 characters max).";
        }

        $icon = $this->string($raw['icon'] ?? 'help_outline', 100);
        if (null === $icon) {
            $errors[] = "$where.icon is invalid.";
        }

        $accordion = $raw['accordionBehavior'] ?? FaqCategory::ACCORDION_MULTI_OPEN;
        if (!\in_array($accordion, FaqCategory::ACCORDION_BEHAVIORS, true)) {
            $errors[] = "$where.accordionBehavior must be one of: ".implode(', ', FaqCategory::ACCORDION_BEHAVIORS).'.';
        }

        $targetPages = $this->stringList($raw['targetPages'] ?? ['all_pages'], 20, 50);
        if (null === $targetPages) {
            $errors[] = "$where.targetPages must be a list of short strings.";
        }

        return [
            'slug' => $slug,
            'title' => $title,
            'icon' => $icon,
            'accordionBehavior' => $accordion,
            'targetPages' => $targetPages ?? ['all_pages'],
        ];
    }

    /**
     * @param array<mixed>    $payload
     * @param list<string>    $errors
     *
     * @return list<array<string, mixed>>
     */
    private function parseCategories(array $payload, array &$errors): array
    {
        if (\count($payload) > self::MAX_CATEGORIES) {
            $errors[] = sprintf('At most %d categories are allowed.', self::MAX_CATEGORIES);

            return [];
        }

        $parsed = [];
        $slugs = [];
        foreach ($payload as $i => $raw) {
            $where = sprintf('categories[%d]', $i);
            if (!\is_array($raw)) {
                $errors[] = "$where must be an object.";
                continue;
            }

            $fields = $this->parseCategoryFields($raw, $where, $errors);
            $slug = $fields['slug'];
            if (null !== $slug) {
                if (isset($slugs[$slug])) {
                    $errors[] = "$where.slug \"$slug\" is used twice.";
                }
                $slugs[$slug] = true;
            }

            $itemsPayload = $raw['items'] ?? [];
            $items = [];
            if (!\is_array($itemsPayload) || !array_is_list($itemsPayload) || \count($itemsPayload) > self::MAX_ITEMS_PER_CATEGORY) {
                $errors[] = sprintf('%s.items must be a list of at most %d questions.', $where, self::MAX_ITEMS_PER_CATEGORY);
            } else {
                foreach ($itemsPayload as $j => $rawItem) {
                    $items[] = $this->parseItem($rawItem, sprintf('%s.items[%d]', $where, $j), $errors);
                }
            }

            $parsed[] = ['id' => $this->id($raw['id'] ?? null), ...$fields, 'items' => $items];
        }

        return $parsed;
    }

    /**
     * @param list<string> $errors
     *
     * @return array<string, mixed>
     */
    private function parseItem(mixed $raw, string $where, array &$errors): array
    {
        if (!\is_array($raw)) {
            $errors[] = "$where must be an object.";

            return [];
        }

        $question = $this->string($raw['question'] ?? null, 2000);
        if (null === $question) {
            $errors[] = "$where.question is required (2000 characters max).";
        }

        $elements = $raw['elements'] ?? [];
        $validElements = \is_array($elements) && array_is_list($elements) && \count($elements) <= self::MAX_ELEMENTS;
        if ($validElements) {
            foreach ($elements as $element) {
                if (!\is_array($element) || !\in_array($element['type'] ?? null, Faq::ELEMENT_TYPES, true)) {
                    $validElements = false;
                    break;
                }
            }
        }
        if (!$validElements || \strlen((string) json_encode($elements)) > self::MAX_ELEMENTS_JSON_BYTES) {
            $errors[] = sprintf('%s.elements must be a list of at most %d blocks of type %s.', $where, self::MAX_ELEMENTS, implode('/', Faq::ELEMENT_TYPES));
        }

        $tags = $this->stringList($raw['tags'] ?? [], 30, 100);
        if (null === $tags) {
            $errors[] = "$where.tags must be a list of short strings.";
        }

        $cta = $raw['cta'] ?? null;
        if (null !== $cta) {
            $label = \is_array($cta) ? $this->string($cta['label'] ?? null, 255) : null;
            $url = \is_array($cta) ? $this->string($cta['url'] ?? null, 2048) : null;
            // Relative paths or http(s) only, so a link can never be a javascript: URL.
            if (null === $label || null === $url || 1 !== preg_match('#^(/|https?://)#i', $url)) {
                $errors[] = "$where.cta needs a label and a url starting with / or http(s)://.";
                $cta = null;
            } else {
                $cta = ['label' => $label, 'url' => $url];
            }
        }

        $rate = $raw['satisfactionRate'] ?? 0;
        if (!\is_int($rate) && !\is_float($rate) || $rate < 0 || $rate > 1) {
            $errors[] = "$where.satisfactionRate must be a number between 0 and 1.";
            $rate = 0;
        }

        return [
            'id' => $this->id($raw['id'] ?? null),
            'question' => $question,
            'elements' => $validElements ? $elements : [],
            'published' => (bool) ($raw['published'] ?? true),
            'highlight' => (bool) ($raw['highlight'] ?? false),
            'tags' => $tags ?? [],
            'cta' => $cta,
            'satisfactionRate' => (float) $rate,
        ];
    }

    /**
     * Ids sent by the client are strings; only numeric ones can match a stored row.
     */
    private function id(mixed $value): ?string
    {
        if (\is_int($value)) {
            return (string) $value;
        }

        return \is_string($value) && ctype_digit($value) ? $value : null;
    }

    private function string(mixed $value, int $maxLength): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $value = trim($value);

        return '' !== $value && mb_strlen($value) <= $maxLength ? $value : null;
    }

    /**
     * @return list<string>|null
     */
    private function stringList(mixed $value, int $maxItems, int $maxLength): ?array
    {
        if (!\is_array($value) || !array_is_list($value) || \count($value) > $maxItems) {
            return null;
        }
        $result = [];
        foreach ($value as $entry) {
            $string = $this->string($entry, $maxLength);
            if (null === $string) {
                return null;
            }
            $result[] = $string;
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeTree(FaqCategoryRepository $categoryRepository, bool $publishedOnly): array
    {
        return array_map(
            fn (FaqCategory $category): array => $this->serializeCategory($category, $publishedOnly),
            $categoryRepository->findBy([], ['displayOrder' => 'ASC']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCategory(FaqCategory $category, bool $publishedOnly): array
    {
        $items = [];
        foreach ($category->getItems() as $item) {
            if ($publishedOnly && !$item->isPublished()) {
                continue;
            }
            $items[] = [
                'id' => (string) $item->getId(),
                'order' => $item->getSortOrder(),
                'question' => $item->getQuestion(),
                'elements' => $item->getElements(),
                'published' => $item->isPublished(),
                'highlight' => $item->isHighlight(),
                'tags' => $item->getTags(),
                'cta' => $item->getCta(),
                'satisfactionRate' => $item->getSatisfactionRate(),
            ];
        }

        return [
            'id' => (string) $category->getId(),
            'slug' => $category->getSlug(),
            'title' => $category->getTitle(),
            'icon' => $category->getIcon(),
            'accordionBehavior' => $category->getAccordionBehavior(),
            'targetPages' => $category->getTargetPages(),
            'displayOrder' => $category->getDisplayOrder(),
            'faqsCount' => \count($items),
            'items' => $items,
        ];
    }
}
