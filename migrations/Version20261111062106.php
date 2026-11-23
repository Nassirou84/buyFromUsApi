<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Removes mobile money as a SAVED payment method.
 *
 * Mobile money carries no reusable Paystack charge token, so a stored number
 * could never be charged again: the wallet and phone number are now collected by
 * the Paystack checkout on every purchase. Saved methods are cards only.
 *
 * Mobile money payments themselves are unaffected — `payment.mobile_money` still
 * records what a completed charge used.
 *
 * IRREVERSIBLE: the stored numbers are deleted. `down()` restores the column but
 * cannot restore the data.
 */
final class Version20261111062106 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop saved mobile-money payment methods and the payment_method.mobile_money_number column.';
    }

    public function up(Schema $schema): void
    {
        // Remove the saved mobile-money methods themselves. They are unchargeable
        // now, so leaving them would strand rows that the UI must filter out and
        // that checkout would reject. Done BEFORE the column is dropped.
        $this->addSql("DELETE FROM payment_method WHERE method_type <> 'card'");

        $this->addSql('ALTER TABLE payment_method DROP mobile_money_number');
    }

    public function down(Schema $schema): void
    {
        // Restores the column only. The deleted mobile-money methods are gone for
        // good; customers re-enter a number in the Paystack checkout instead.
        $this->addSql('ALTER TABLE payment_method ADD mobile_money_number VARCHAR(255) DEFAULT NULL');
    }
}
