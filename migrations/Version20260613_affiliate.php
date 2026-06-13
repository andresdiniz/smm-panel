<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260613_affiliate extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sistema de afiliados: campos em users + tabela affiliate_commission';
    }

    public function up(Schema $schema): void
    {
        // Campos no users
        $this->addSql("ALTER TABLE users
            ADD affiliate_code VARCHAR(16) DEFAULT NULL,
            ADD affiliate_commission_rate DECIMAL(5,4) DEFAULT NULL,
            ADD referred_by_id INT DEFAULT NULL
        ");

        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E99F75D7B0 ON users (affiliate_code)');

        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_users_referred_by
            FOREIGN KEY (referred_by_id) REFERENCES users (id) ON DELETE SET NULL');

        // Tabela de comissões
        $this->addSql('
            CREATE TABLE affiliate_commission (
                id              INT AUTO_INCREMENT NOT NULL,
                affiliate_id    INT NOT NULL,
                order_id        INT NOT NULL,
                customer_id     INT NOT NULL,
                amount          DECIMAL(10,2) NOT NULL,
                rate            DECIMAL(5,4) NOT NULL,
                status          VARCHAR(20) NOT NULL DEFAULT \'pending\',
                created_at      DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                paid_at         DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                PRIMARY KEY(id),
                INDEX IDX_aff_affiliate (affiliate_id),
                INDEX IDX_aff_order (order_id),
                INDEX IDX_aff_customer (customer_id),
                INDEX IDX_aff_status (status),
                CONSTRAINT FK_aff_affiliate FOREIGN KEY (affiliate_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT FK_aff_order     FOREIGN KEY (order_id)     REFERENCES `orders` (id) ON DELETE CASCADE,
                CONSTRAINT FK_aff_customer  FOREIGN KEY (customer_id)  REFERENCES users (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE affiliate_commission');
        $this->addSql('ALTER TABLE users DROP FOREIGN KEY FK_users_referred_by');
        $this->addSql('DROP INDEX UNIQ_1483A5E99F75D7B0 ON users');
        $this->addSql('ALTER TABLE users DROP affiliate_code, DROP affiliate_commission_rate, DROP referred_by_id');
    }
}
