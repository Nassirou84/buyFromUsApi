<?php

declare(strict_types=1);

namespace App\Command;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;


#[AsCommand(
    name: 'app:fill-uid',
    description: 'Add a short description for your command',
)]
class FillProductsUidCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Fill products UID');
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $products = $this->entityManager->getRepository(\App\Entity\Product::class)->findAll();
        foreach ($products as $product) {
            if (!$product->getFullPrice()) {
                $subcategory = $product->getSubcategory();
                $margin = $subcategory ? $subcategory->getMarkup() : 0;
                $product->setFullPrice($product->getUsdPrice() * (1 + $margin / 100));
                $product->setUpdatedAt(new \DateTime());
                $this->entityManager->persist($product);
            }
            // if (!$product->getUid()) {
            //     $product->setUid($this->uniqUidGenerator->generateUniqueUid(\App\Entity\Product::class));
            // }
        }
        $this->entityManager->flush();

        return Command::SUCCESS;
    }
}