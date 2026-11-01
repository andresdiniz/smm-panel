<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds explicit marketing consent and reviewed payment refund requests';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('users')) {
            $users = $schema->getTable('users');
            if (!$users->hasColumn('marketing_consent')) {
                $this->addSql('ALTER TABLE users ADD marketing_consent TINYINT(1) NOT NULL DEFAULT 0');
            }
            if (!$users->hasColumn('marketing_consent_at')) {
                $this->addSql('ALTER TABLE users ADD marketing_consent_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
            }
        }

        if (!$schema->hasTable('payment_refund_requests')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE payment_refund_requests (
                    id INT AUTO_INCREMENT NOT NULL,
                    payment_id INT NOT NULL,
                    user_id INT NOT NULL,
                    reason LONGTEXT NOT NULL,
                    status VARCHAR(20) NOT NULL,
                    admin_note LONGTEXT DEFAULT NULL,
                    requested_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    reviewed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                    UNIQUE INDEX UNIQ_PAYMENT_REFUND_PAYMENT (payment_id),
                    INDEX idx_payment_refund_status (status),
                    INDEX idx_payment_refund_requested_at (requested_at),
                    INDEX IDX_PAYMENT_REFUND_USER (user_id),
                    PRIMARY KEY(id),
                    CONSTRAINT FK_PAYMENT_REFUND_PAYMENT FOREIGN KEY (payment_id) REFERENCES payments (id) ON DELETE CASCADE,
                    CONSTRAINT FK_PAYMENT_REFUND_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('payment_refund_requests')) {
            $this->addSql('DROP TABLE payment_refund_requests');
        }
        if ($schema->hasTable('users')) {
            $users = $schema->getTable('users');
            if ($users->hasColumn('marketing_consent_at')) {
                $this->addSql('ALTER TABLE users DROP marketing_consent_at');
            }
            if ($users->hasColumn('marketing_consent')) {
                $this->addSql('ALTER TABLE users DROP marketing_consent');
            }
        }
    }
}
