<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260929220955 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE UNIQUE INDEX UNIQ_BANNER_NAME ON banner (name)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_BASKET_UID ON basket (uid)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ORDER_UID ON `order` (uid)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ORDER_ACCESS_TOKEN ON `order` (access_token)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PAYMENT_TRANSACTION_ID ON payment (transaction_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PROMO_CODE_CODE ON promo_code (code)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX UNIQ_PROMO_CODE_USAGE_USER_PROMO ON promo_code_usage (user_id, promo_code_id)
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_SETTING_NAME ON setting (name)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_SHOPPING_REQUEST_UID ON shopping_request (uid)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_BANNER_NAME ON banner');
        $this->addSql('DROP INDEX UNIQ_BASKET_UID ON basket');
        $this->addSql('DROP INDEX UNIQ_ORDER_UID ON `order`');
        $this->addSql('DROP INDEX UNIQ_ORDER_ACCESS_TOKEN ON `order`');
        $this->addSql('DROP INDEX UNIQ_PAYMENT_TRANSACTION_ID ON payment');
        $this->addSql('DROP INDEX UNIQ_PROMO_CODE_CODE ON promo_code');
        $this->addSql('DROP INDEX UNIQ_PROMO_CODE_USAGE_USER_PROMO ON promo_code_usage');
        $this->addSql('DROP INDEX UNIQ_SETTING_NAME ON setting');
        $this->addSql('DROP INDEX UNIQ_SHOPPING_REQUEST_UID ON shopping_request');
    }
}
