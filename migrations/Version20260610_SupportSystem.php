<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260610_SupportSystem extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabelas support_ticket e support_message para sistema de suporte bidirecional';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE support_ticket (
                id          INT AUTO_INCREMENT NOT NULL,
                user_id     INT NOT NULL,
                subject     VARCHAR(180) NOT NULL,
                status      VARCHAR(20)  NOT NULL DEFAULT 'open',
                priority    VARCHAR(20)  NOT NULL DEFAULT 'normal',
                closed_at   DATETIME     DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at  DATETIME     NOT NULL  COMMENT '(DC2Type:datetime_immutable)',
                updated_at  DATETIME     DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_SUPPORT_TICKET_USER (user_id),
                INDEX IDX_SUPPORT_TICKET_STATUS (status),
                PRIMARY KEY(id),
                CONSTRAINT FK_SUPPORT_TICKET_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE support_message (
                id              INT AUTO_INCREMENT NOT NULL,
                ticket_id       INT NOT NULL,
                sender          VARCHAR(20)  NOT NULL,
                body            LONGTEXT     NOT NULL,
                read_by_admin   TINYINT(1)   NOT NULL DEFAULT 0,
                read_by_user    TINYINT(1)   NOT NULL DEFAULT 0,
                created_at      DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_SUPPORT_MESSAGE_TICKET (ticket_id),
                INDEX IDX_SUPPORT_MESSAGE_UNREAD_ADMIN (ticket_id, sender, read_by_admin),
                PRIMARY KEY(id),
                CONSTRAINT FK_SUPPORT_MESSAGE_TICKET FOREIGN KEY (ticket_id) REFERENCES support_ticket (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE support_message');
        $this->addSql('DROP TABLE support_ticket');
    }
}
