<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the initial SMM panel schema.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE users (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, roles JSON NOT NULL, balance NUMERIC(12, 2) DEFAULT 0.00 NOT NULL, asaas_customer_id VARCHAR(64) DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_user_email (email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE services (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, category VARCHAR(40) NOT NULL, price_per1000 NUMERIC(10, 4) NOT NULL, min_quantity INT NOT NULL, max_quantity INT NOT NULL, provider_service_id VARCHAR(64) DEFAULT NULL, active TINYINT(1) DEFAULT 1 NOT NULL, refill TINYINT(1) DEFAULT 0 NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE orders (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, service_id INT NOT NULL, link VARCHAR(500) NOT NULL, quantity INT NOT NULL, charge NUMERIC(12, 4) NOT NULL, status VARCHAR(20) NOT NULL, provider_order_id VARCHAR(64) DEFAULT NULL, start_count INT DEFAULT NULL, remains INT DEFAULT NULL, last_error LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX IDX_orders_status (status), INDEX IDX_orders_user (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE transactions (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, type VARCHAR(30) NOT NULL, amount NUMERIC(12, 2) NOT NULL, balance_after NUMERIC(12, 2) NOT NULL, reference VARCHAR(64) DEFAULT NULL, description VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, INDEX IDX_transactions_user (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE pix_charges (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, reference VARCHAR(64) NOT NULL, asaas_payment_id VARCHAR(64) DEFAULT NULL, amount NUMERIC(12, 2) NOT NULL, status VARCHAR(20) NOT NULL, qr_code LONGTEXT DEFAULT NULL, copy_paste_code LONGTEXT DEFAULT NULL, invoice_url LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, confirmed_at DATETIME DEFAULT NULL, UNIQUE INDEX uniq_pix_charge_reference (reference), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE webhook_events (id INT AUTO_INCREMENT NOT NULL, provider VARCHAR(30) NOT NULL, external_id VARCHAR(120) NOT NULL, event VARCHAR(80) NOT NULL, payload JSON NOT NULL, processed TINYINT(1) DEFAULT 0 NOT NULL, received_at DATETIME NOT NULL, UNIQUE INDEX uniq_webhook_event_external (provider, external_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('ALTER TABLE orders ADD CONSTRAINT FK_orders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE orders ADD CONSTRAINT FK_orders_service FOREIGN KEY (service_id) REFERENCES services (id)');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_transactions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pix_charges ADD CONSTRAINT FK_pix_charges_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pix_charges');
        $this->addSql('DROP TABLE transactions');
        $this->addSql('DROP TABLE orders');
        $this->addSql('DROP TABLE services');
        $this->addSql('DROP TABLE webhook_events');
        $this->addSql('DROP TABLE users');
    }
}
