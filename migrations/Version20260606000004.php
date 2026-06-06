<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * V4 — schema definitivo, 100% alinhado com as entidades PHP.
 *
 * Situação após V1-V3:
 *  - Existem: wallets, wallet_transactions, orders, payments, contacts, crm_contact, messenger_messages
 *  - Ainda incorretas/ausentes: 'user' (singular), service_category, service,
 *    provider_credential — todas precisam ser recriadas com nomes e colunas corretos.
 */
final class Version20260606000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schema definitivo: users, service_categories, services, provider_credentials';
    }

    public function up(Schema $schema): void
    {
        // ── Remove FKs que dependem de 'user' (singular) ──────────────────────
        $this->addSql('ALTER TABLE wallets       DROP FOREIGN KEY IF EXISTS FK_wallets_user');
        $this->addSql('ALTER TABLE orders        DROP FOREIGN KEY IF EXISTS FK_orders_user');
        $this->addSql('ALTER TABLE payments      DROP FOREIGN KEY IF EXISTS FK_payments_user');
        $this->addSql('ALTER TABLE crm_contact   DROP FOREIGN KEY IF EXISTS FK_crm_user');
        // V1 criou estas — podem existir ainda
        $this->addSql('ALTER TABLE contacts      DROP FOREIGN KEY IF EXISTS FK_c_user');

        // ── Dropa tabelas com schema errado ───────────────────────────────────
        $this->addSql('DROP TABLE IF EXISTS `user`');             // singular
        $this->addSql('DROP TABLE IF EXISTS service_category');   // singular
        $this->addSql('DROP TABLE IF EXISTS service');            // singular + colunas erradas
        $this->addSql('DROP TABLE IF EXISTS provider_credential');// singular + colunas erradas

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
        // Nota: category é VARCHAR (nome da categoria), não FK
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

        // ── Restaura FKs das tabelas dependentes ──────────────────────────────
        $this->addSql('ALTER TABLE wallets     ADD CONSTRAINT FK_wallets_user     FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE orders      ADD CONSTRAINT FK_orders_user      FOREIGN KEY (user_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE payments    ADD CONSTRAINT FK_payments_user    FOREIGN KEY (user_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE crm_contact ADD CONSTRAINT FK_crm_user         FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contacts    ADD CONSTRAINT FK_contacts_user    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');

        // ── wallet_transactions: recria com schema correto da entidade ─────────
        // Entidade tem: type (enum string), balance_after_cents, description — sem reference_id
        $this->addSql('ALTER TABLE wallet_transactions DROP FOREIGN KEY IF EXISTS FK_wts_wallet');
        $this->addSql('DROP TABLE IF EXISTS wallet_transactions');
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
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wallets DROP FOREIGN KEY FK_wallets_user');
        $this->addSql('ALTER TABLE orders DROP FOREIGN KEY FK_orders_user');
        $this->addSql('ALTER TABLE payments DROP FOREIGN KEY FK_payments_user');
        $this->addSql('ALTER TABLE crm_contact DROP FOREIGN KEY FK_crm_user');
        $this->addSql('ALTER TABLE contacts DROP FOREIGN KEY FK_contacts_user');
        $this->addSql('ALTER TABLE wallet_transactions DROP FOREIGN KEY FK_wts_wallet');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE service_categories');
        $this->addSql('DROP TABLE services');
        $this->addSql('DROP TABLE provider_credentials');
        $this->addSql('DROP TABLE wallet_transactions');
    }
}
