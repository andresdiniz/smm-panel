<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031190800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria armazenamento de tokens de recuperação de senha';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE password_reset_requests (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                selector VARCHAR(32) NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                requested_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                expires_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                used_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_password_reset_selector (selector),
                UNIQUE INDEX UNIQ_password_reset_token_hash (token_hash),
                INDEX IDX_password_reset_user (user_id),
                INDEX IDX_password_reset_expiry (expires_at),
                PRIMARY KEY(id),
                CONSTRAINT FK_password_reset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE password_reset_requests');
    }
}
