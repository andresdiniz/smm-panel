<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration V3: recria schema completo alinhado com as entidades.
 *
 * Histórico:
 *  V1 criou tabelas no singular (order, payment, wallet, wallet_transaction)
 *  V2 renomeou contact -> contacts
 *  V3 (esta) corrige o restante e recria orders/payments/wallets/wallet_transactions
 *      com colunas exatas das entidades PHP.
 */
final class Version20260606000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recria tabelas order→orders, payment→payments, wallet→wallets, wallet_transaction→wallet_transactions com schema correto';
    }

    public function up(Schema $schema): void
    {
        // 1. Remove FKs que dependem das tabelas antigas
        $this->addSql('ALTER TABLE `order` DROP FOREIGN KEY IF EXISTS FK_o_user');
        $this->addSql('ALTER TABLE `order` DROP FOREIGN KEY IF EXISTS FK_o_service');
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY IF EXISTS FK_p_user');
        $this->addSql('ALTER TABLE wallet_transaction DROP FOREIGN KEY IF EXISTS FK_wt_wallet');
        $this->addSql('ALTER TABLE wallet DROP FOREIGN KEY IF EXISTS FK_wallet_user');

        // 2. Dropa tabelas antigas (singular)
        $this->addSql('DROP TABLE IF EXISTS `order`');
        $this->addSql('DROP TABLE IF EXISTS payment');
        $this->addSql('DROP TABLE IF EXISTS wallet_transaction');
        $this->addSql('DROP TABLE IF EXISTS wallet');

        // 3. Cria wallets
        $this->addSql(<<<'SQL'
            CREATE TABLE wallets (
                id         INT AUTO_INCREMENT NOT NULL,
                user_id    INT NOT NULL,
                balance_cents BIGINT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_wallets_user (user_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_wallets_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // 4. Cria wallet_transactions
        $this->addSql(<<<'SQL'
            CREATE TABLE wallet_transactions (
                id            INT AUTO_INCREMENT NOT NULL,
                wallet_id     INT NOT NULL,
                amount_cents  BIGINT NOT NULL,
                type          VARCHAR(30) NOT NULL,
                description   VARCHAR(255) DEFAULT NULL,
                reference_id  VARCHAR(100) DEFAULT NULL,
                created_at    DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_wts_wallet (wallet_id),
                INDEX IDX_wts_type (type),
                PRIMARY KEY(id),
                CONSTRAINT FK_wts_wallet FOREIGN KEY (wallet_id) REFERENCES wallets (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // 5. Cria orders
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
                CONSTRAINT FK_orders_user    FOREIGN KEY (user_id)    REFERENCES `user` (id),
                CONSTRAINT FK_orders_service FOREIGN KEY (service_id) REFERENCES service (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // 6. Cria payments
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
                CONSTRAINT FK_payments_user FOREIGN KEY (user_id) REFERENCES `user` (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wallet_transactions DROP FOREIGN KEY FK_wts_wallet');
        $this->addSql('ALTER TABLE orders DROP FOREIGN KEY FK_orders_user');
        $this->addSql('ALTER TABLE orders DROP FOREIGN KEY FK_orders_service');
        $this->addSql('ALTER TABLE payments DROP FOREIGN KEY FK_payments_user');
        $this->addSql('DROP TABLE wallet_transactions');
        $this->addSql('DROP TABLE wallets');
        $this->addSql('DROP TABLE orders');
        $this->addSql('DROP TABLE payments');
    }
}
