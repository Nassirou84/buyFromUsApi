<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Category;
use App\Entity\Subcategory;
use App\Repository\CategoryRepository;
use App\Repository\SubcategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_array;
use function sprintf;

use const JSON_THROW_ON_ERROR;

#[AsCommand(
    name: 'app:seed-categories',
    description: 'Insert the catalog categories and subcategories from config/data/categories.json (existing ones are left untouched)',
)]
class SeedCategoriesCommand extends Command
{
    public function __construct(
        private readonly CategoryRepository $categoryRepository,
        private readonly SubcategoryRepository $subcategoryRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $dataFile,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be inserted without writing to the database')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $data = $this->loadData();
        } catch (JsonException|RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $createdCategories = $createdSubcategories = $skipped = 0;

        foreach ($data as $categoryData) {
            // Match on title, not id: ids differ between environments, titles are the stable key.
            $category = $this->categoryRepository->findOneBy(['title' => $categoryData['title']]);

            if (null === $category) {
                $category = (new Category())
                    ->setTitle($categoryData['title'])
                    ->setIcon($categoryData['icon'] ?? null)
                    ->setDescription($categoryData['description'] ?? null)
                ;
                // The category must be managed before its subcategories point at it (non-nullable FK).
                $this->entityManager->persist($category);
                ++$createdCategories;
                $io->writeln(sprintf('+ category    %s', $categoryData['title']));
            } else {
                ++$skipped;
            }

            foreach ($categoryData['subcategories'] ?? [] as $subcategoryData) {
                $exists = null !== $category->getId()
                    && null !== $this->subcategoryRepository->findOneBy([
                        'category' => $category,
                        'title' => $subcategoryData['title'],
                    ]);

                if ($exists) {
                    ++$skipped;

                    continue;
                }

                $subcategory = (new Subcategory())
                    ->setTitle($subcategoryData['title'])
                    ->setCategory($category)
                    ->setMarkup((int) $subcategoryData['markup'])
                    ->setIsActive((bool) ($subcategoryData['isActive'] ?? true))
                    ->setDescription($subcategoryData['description'] ?? null)
                ;
                $this->entityManager->persist($subcategory);
                ++$createdSubcategories;
                $io->writeln(sprintf('+ subcategory %s > %s', $categoryData['title'], $subcategoryData['title']));
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->success(sprintf(
            '%s%d categories and %d subcategories inserted, %d already present.',
            $dryRun ? '[dry-run] ' : '',
            $createdCategories,
            $createdSubcategories,
            $skipped,
        ));

        return Command::SUCCESS;
    }

    /**
     * @return list<array{title: string, icon?: ?string, description?: ?string, subcategories?: list<array{title: string, markup: int, isActive?: bool, description?: ?string}>}>
     */
    private function loadData(): array
    {
        if (!is_file($this->dataFile)) {
            throw new RuntimeException(sprintf('Data file not found: %s', $this->dataFile));
        }

        $data = json_decode((string) file_get_contents($this->dataFile), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('The data file must contain a list of categories.');
        }

        /* @var list<array{title: string, icon?: ?string, description?: ?string, subcategories?: list<array{title: string, markup: int, isActive?: bool, description?: ?string}>}> $data */
        return $data;
    }
}
