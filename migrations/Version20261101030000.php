<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds campaign email delivery, open, and click tracking';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('marketing_email_logs')) {
            return;
        }

        $this->addSql(<<<'SQL'
            CREATE TABLE marketing_email_logs (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                campaign_id CHAR(36) NOT NULL,
                token CHAR(64) NOT NULL,
                subject VARCHAR(180) NOT NULL,
                status VARCHAR(12) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                sent_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                opened_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                open_count INT NOT NULL DEFAULT 0,
                click_count INT NOT NULL DEFAULT 0,
                links JSON NOT NULL,
                clicked_links JSON NOT NULL,
                UNIQUE INDEX UNIQ_MARKETING_EMAIL_TOKEN (token),
                INDEX idx_marketing_email_campaign (campaign_id, created_at),
                INDEX idx_marketing_email_user (user_id, created_at),
                PRIMARY KEY(id),
                CONSTRAINT FK_MARKETING_EMAIL_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('marketing_email_logs')) {
            $this->addSql('DROP TABLE marketing_email_logs');
        }
    }
}
