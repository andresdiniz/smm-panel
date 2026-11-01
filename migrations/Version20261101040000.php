<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds per-user browser push subscriptions and notification categories';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('browser_push_subscriptions')) {
            return;
        }

        $this->addSql(<<<'SQL'
            CREATE TABLE browser_push_subscriptions (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                endpoint LONGTEXT NOT NULL,
                endpoint_hash CHAR(64) NOT NULL,
                public_key VARCHAR(255) NOT NULL,
                auth_token VARCHAR(255) NOT NULL,
                content_encoding VARCHAR(12) NOT NULL DEFAULT 'aes128gcm',
                categories JSON NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_BROWSER_PUSH_ENDPOINT_HASH (endpoint_hash),
                INDEX idx_browser_push_user_updated (user_id, updated_at),
                PRIMARY KEY(id),
                CONSTRAINT FK_BROWSER_PUSH_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('browser_push_subscriptions')) {
            $this->addSql('DROP TABLE browser_push_subscriptions');
        }
    }
}
