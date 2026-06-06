<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260606000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schema inicial completo do SMM Panel';
    }

    public function up(Schema $schema): void
    {
        // user
        $this->addSql(<<<'SQL'
            CREATE TABLE `user` (
                id INT AUTO_INCREMENT NOT NULL,
                email VARCHAR(180) NOT NULL,
                name VARCHAR(120) NOT NULL,
                roles JSON NOT NULL COMMENT '(DC2Type:json)',
                password VARCHAR(255) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_8D93D649E7927C74 (email),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // wallet
        $this->addSql(<<<'SQL'
            CREATE TABLE wallet (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                balance_cents BIGINT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_7C68921FA76ED395 (user_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_wallet_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // wallet_transaction
        $this->addSql(<<<'SQL'
            CREATE TABLE wallet_transaction (
                id INT AUTO_INCREMENT NOT NULL,
                wallet_id INT NOT NULL,
                amount_cents BIGINT NOT NULL,
                type VARCHAR(30) NOT NULL,
                description VARCHAR(255) DEFAULT NULL,
                reference_id VARCHAR(100) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_wt_wallet (wallet_id),
                INDEX IDX_wt_type (type),
                PRIMARY KEY(id),
                CONSTRAINT FK_wt_wallet FOREIGN KEY (wallet_id) REFERENCES wallet (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // service_category
        $this->addSql(<<<'SQL'
            CREATE TABLE service_category (
                id INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(100) NOT NULL,
                slug VARCHAR(100) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                UNIQUE INDEX UNIQ_sc_slug (slug),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // service
        $this->addSql(<<<'SQL'
            CREATE TABLE service (
                id INT AUTO_INCREMENT NOT NULL,
                category_id INT NOT NULL,
                name VARCHAR(200) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                provider_slug VARCHAR(80) NOT NULL,
                external_service_id VARCHAR(80) NOT NULL,
                price_cents BIGINT NOT NULL,
                min_quantity INT NOT NULL DEFAULT 10,
                max_quantity INT NOT NULL DEFAULT 10000,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_s_category (category_id),
                INDEX IDX_s_provider (provider_slug),
                PRIMARY KEY(id),
                CONSTRAINT FK_s_category FOREIGN KEY (category_id) REFERENCES service_category (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // provider_credential
        $this->addSql(<<<'SQL'
            CREATE TABLE provider_credential (
                id INT AUTO_INCREMENT NOT NULL,
                provider_slug VARCHAR(80) NOT NULL,
                label VARCHAR(120) NOT NULL,
                api_key VARCHAR(500) NOT NULL,
                base_url VARCHAR(255) DEFAULT NULL,
                balance_cents BIGINT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                last_checked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_pc_slug (provider_slug),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // order
        $this->addSql(<<<'SQL'
            CREATE TABLE `order` (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                service_id INT NOT NULL,
                link VARCHAR(500) NOT NULL,
                quantity INT NOT NULL,
                amount_cents BIGINT NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'pending',
                external_order_id VARCHAR(100) DEFAULT NULL,
                provider_slug VARCHAR(80) DEFAULT NULL,
                start_count INT DEFAULT NULL,
                remains INT DEFAULT NULL,
                notes LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_o_user (user_id),
                INDEX IDX_o_service (service_id),
                INDEX IDX_o_status (status),
                PRIMARY KEY(id),
                CONSTRAINT FK_o_user FOREIGN KEY (user_id) REFERENCES `user` (id),
                CONSTRAINT FK_o_service FOREIGN KEY (service_id) REFERENCES service (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // payment
        $this->addSql(<<<'SQL'
            CREATE TABLE payment (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                gateway VARCHAR(50) NOT NULL,
                method VARCHAR(30) NOT NULL,
                amount_cents BIGINT NOT NULL,
                fee_cents BIGINT NOT NULL DEFAULT 0,
                status VARCHAR(30) NOT NULL DEFAULT 'pending',
                external_id VARCHAR(200) DEFAULT NULL,
                pix_qr_code LONGTEXT DEFAULT NULL,
                pix_expiration DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                paid_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                metadata JSON DEFAULT NULL COMMENT '(DC2Type:json)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_p_user (user_id),
                INDEX IDX_p_status (status),
                INDEX IDX_p_gateway (gateway),
                PRIMARY KEY(id),
                CONSTRAINT FK_p_user FOREIGN KEY (user_id) REFERENCES `user` (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // crm_contact
        $this->addSql(<<<'SQL'
            CREATE TABLE crm_contact (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                tags JSON NOT NULL COMMENT '(DC2Type:json)',
                timeline JSON NOT NULL COMMENT '(DC2Type:json)',
                utm_source VARCHAR(100) DEFAULT NULL,
                utm_campaign VARCHAR(100) DEFAULT NULL,
                utm_medium VARCHAR(100) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_crm_user (user_id),
                INDEX idx_crm_user (user_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_crm_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // contact (suporte/tickets)
        $this->addSql(<<<'SQL'
            CREATE TABLE contact (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT DEFAULT NULL,
                name VARCHAR(120) NOT NULL,
                email VARCHAR(180) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                message LONGTEXT NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'open',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_c_user (user_id),
                INDEX IDX_c_status (status),
                PRIMARY KEY(id),
                CONSTRAINT FK_c_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // messenger_messages (fila Doctrine)
        $this->addSql(<<<'SQL'
            CREATE TABLE messenger_messages (
                id BIGINT AUTO_INCREMENT NOT NULL,
                body LONGTEXT NOT NULL,
                headers LONGTEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                available_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                delivered_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_75EA56E0FB7336F0 (queue_name),
                INDEX IDX_75EA56E0E3BD61CE (available_at),
                INDEX IDX_75EA56E016BA31DB (delivered_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wallet DROP FOREIGN KEY FK_wallet_user');
        $this->addSql('ALTER TABLE wallet_transaction DROP FOREIGN KEY FK_wt_wallet');
        $this->addSql('ALTER TABLE service DROP FOREIGN KEY FK_s_category');
        $this->addSql('ALTER TABLE `order` DROP FOREIGN KEY FK_o_user');
        $this->addSql('ALTER TABLE `order` DROP FOREIGN KEY FK_o_service');
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_p_user');
        $this->addSql('ALTER TABLE crm_contact DROP FOREIGN KEY FK_crm_user');
        $this->addSql('ALTER TABLE contact DROP FOREIGN KEY FK_c_user');
        $this->addSql('DROP TABLE contact');
        $this->addSql('DROP TABLE crm_contact');
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('DROP TABLE `order`');
        $this->addSql('DROP TABLE payment');
        $this->addSql('DROP TABLE provider_credential');
        $this->addSql('DROP TABLE service');
        $this->addSql('DROP TABLE service_category');
        $this->addSql('DROP TABLE `user`');
        $this->addSql('DROP TABLE wallet');
        $this->addSql('DROP TABLE wallet_transaction');
    }
}
