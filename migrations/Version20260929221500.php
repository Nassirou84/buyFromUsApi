<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops payment_method.cvv: CVVs must never be persisted after authorization
 * (PCI-DSS). The entity no longer maps this column; run this migration
 * whenever you're ready to permanently delete any values already stored in it.
 */
final class Version20260929221500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop payment_method.cvv (never persist CVVs, PCI-DSS)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment_method DROP cvv');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment_method ADD cvv VARCHAR(10) DEFAULT NULL');
    }
}
