<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * V4 — schema definitivo, 100% alinhado com as entidades PHP.
 *
 * Usa FOREIGN_KEY_CHECKS=0 para poder dropar/recriar tabelas sem conflito
 * de FK, restaurando as constraints no final.
 */
final class Version20260606000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schema definitivo: users, service_categories, services, provider_credentials, wallet_transactions';
    }

    public function up(Schema $schema): void
    {
        // ── Desativa checagem de FK para poder dropar/recriar livremente ───────
        $this->addSql('SET FOREIGN_KEY_CHECKS = 0');

        // ── Dropa todas as tabelas com nome/schema errado ─────────────────────
        $this->addSql('DROP TABLE IF EXISTS wallet_transactions');
        $this->addSql('DROP TABLE IF EXISTS wallets');
        $this->addSql('DROP TABLE IF EXISTS orders');
        $this->addSql('DROP TABLE IF EXISTS payments');
        $this->addSql('DROP TABLE IF EXISTS crm_contact');
        $this->addSql('DROP TABLE IF EXISTS contacts');
        $this->addSql('DROP TABLE IF EXISTS `user`');
        $this->addSql('DROP TABLE IF EXISTS service_category');
        $this->addSql('DROP TABLE IF EXISTS service');
        $this->addSql('DROP TABLE IF EXISTS provider_credential');

        // ── users ─────────────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE users (
                id         INT AUTO_INCREMENT NOT NULL,
                email      VARCHAR(180) NOT NULL,
                name       VARCHAR(120) NOT NULL,
                roles      JSON NOT NULL COMMENT '(DC2Type:json)',
                password   VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_users_email (email),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // ── service_categories ────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE service_categories (
                id         INT AUTO_INCREMENT NOT NULL,
                name       VARCHAR(100) NOT NULL,
                slug       VARCHAR(120) NOT NULL,
                is_active  TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                UNIQUE INDEX UNIQ_sc_slug (slug),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // ── services ──────────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE services (
                id                       INT AUTO_INCREMENT NOT NULL,
                category                 VARCHAR(100) NOT NULL,
                name                     VARCHAR(200) NOT NULL,
                description              LONGTEXT DEFAULT NULL,
                price_per_thousand_cents BIGINT NOT NULL,
                min_qty                  INT NOT NULL,
                max_qty                  INT NOT NULL,
                active                   TINYINT(1) NOT NULL DEFAULT 1,
                external_service_id      VARCHAR(100) DEFAULT NULL,
                provider_slug            VARCHAR(60) DEFAULT NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // ── provider_credentials ──────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE provider_credentials (
                id           INT AUTO_INCREMENT NOT NULL,
                type         VARCHAR(40) NOT NULL,
                slug         VARCHAR(60) NOT NULL,
                base_url     VARCHAR(512) NOT NULL,
                api_key      VARCHAR(512) NOT NULL,
                secret_token VARCHAR(512) DEFAULT NULL,
                active       TINYINT(1) NOT NULL DEFAULT 1,
                created_at   DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at   DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_pc_type_slug (type, slug),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // ── wallets ───────────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE wallets (
                id            INT AUTO_INCREMENT NOT NULL,
                user_id       INT NOT NULL,
                balance_cents BIGINT NOT NULL DEFAULT 0,
                created_at    DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at    DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_wallets_user (user_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_wallets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // ── wallet_transactions ───────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE wallet_transactions (
                id                  INT AUTO_INCREMENT NOT NULL,
                wallet_id           INT NOT NULL,
                type                VARCHAR(20) NOT NULL,
                amount_cents        INT NOT NULL,
                balance_after_cents INT NOT NULL,
                description         VARCHAR(200) NOT NULL,
                created_at          DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_wts_wallet (wallet_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_wts_wallet FOREIGN KEY (wallet_id) REFERENCES wallets (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // ── orders ────────────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE orders (
                id                INT AUTO_INCREMENT NOT NULL,
                user_id           INT NOT NULL,
                service_id        INT NOT NULL,
                status            VARCHAR(30) NOT NULL DEFAULT 'pending',
                amount_cents      BIGINT NOT NULL,
                quantity          INT NOT NULL,
                target_url        VARCHAR(512) NOT NULL,
                external_order_id VARCHAR(120) DEFAULT NULL,
                start_count       INT DEFAULT NULL,
                remains           INT DEFAULT NULL,
                created_at        DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at        DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_orders_user (user_id),
                INDEX IDX_orders_service (service_id),
                INDEX IDX_orders_status (status),
                PRIMARY KEY(id),
                CONSTRAINT FK_orders_user    FOREIGN KEY (user_id)    REFERENCES users (id),
                CONSTRAINT FK_orders_service FOREIGN KEY (service_id) REFERENCES services (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // ── payments ──────────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE payments (
                id               INT AUTO_INCREMENT NOT NULL,
                user_id          INT NOT NULL,
                type             VARCHAR(20) NOT NULL,
                status           VARCHAR(20) NOT NULL DEFAULT 'pending',
                method           VARCHAR(20) NOT NULL,
                amount_cents     BIGINT NOT NULL,
                fee_cents        BIGINT NOT NULL DEFAULT 0,
                pix_code         VARCHAR(512) DEFAULT NULL,
                qr_code_base64   LONGTEXT DEFAULT NULL,
                external_id      VARCHAR(255) DEFAULT NULL,
                gateway_response LONGTEXT DEFAULT NULL,
                created_at       DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                paid_at          DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_payments_user   (user_id),
                INDEX IDX_payments_status (status),
                PRIMARY KEY(id),
                CONSTRAINT FK_payments_user FOREIGN KEY (user_id) REFERENCES users (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // ── crm_contact ───────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE crm_contact (
                id           INT AUTO_INCREMENT NOT NULL,
                user_id      INT NOT NULL,
                tags         JSON NOT NULL COMMENT '(DC2Type:json)',
                timeline     JSON NOT NULL COMMENT '(DC2Type:json)',
                utm_source   VARCHAR(100) DEFAULT NULL,
                utm_campaign VARCHAR(100) DEFAULT NULL,
                utm_medium   VARCHAR(100) DEFAULT NULL,
                created_at   DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at   DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_crm_user (user_id),
                INDEX idx_crm_user (user_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_crm_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // ── contacts ──────────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE contacts (
                id              INT AUTO_INCREMENT NOT NULL,
                user_id         INT DEFAULT NULL,
                name            VARCHAR(150) NOT NULL,
                email           VARCHAR(180) DEFAULT NULL,
                phone           VARCHAR(30) DEFAULT NULL,
                source          VARCHAR(80) DEFAULT NULL,
                status          VARCHAR(30) NOT NULL DEFAULT 'new',
                tags            VARCHAR(512) DEFAULT NULL,
                notes           LONGTEXT DEFAULT NULL,
                last_contact_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at      DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at      DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_c_user (user_id),
                INDEX IDX_c_status (status),
                PRIMARY KEY(id),
                CONSTRAINT FK_contacts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // ── Reativa checagem de FK ────────────────────────────────────────────
        $this->addSql('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('SET FOREIGN_KEY_CHECKS = 0');
        $this->addSql('DROP TABLE IF EXISTS wallet_transactions');
        $this->addSql('DROP TABLE IF EXISTS wallets');
        $this->addSql('DROP TABLE IF EXISTS orders');
        $this->addSql('DROP TABLE IF EXISTS payments');
        $this->addSql('DROP TABLE IF EXISTS crm_contact');
        $this->addSql('DROP TABLE IF EXISTS contacts');
        $this->addSql('DROP TABLE IF EXISTS services');
        $this->addSql('DROP TABLE IF EXISTS service_categories');
        $this->addSql('DROP TABLE IF EXISTS provider_credentials');
        $this->addSql('DROP TABLE IF EXISTS users');
        $this->addSql('SET FOREIGN_KEY_CHECKS = 1');
    }
}
