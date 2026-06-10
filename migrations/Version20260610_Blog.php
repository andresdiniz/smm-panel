<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260610_Blog extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabelas blog_category, blog_tag, blog_post e blog_post_tag';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE blog_category (
                id          INT AUTO_INCREMENT NOT NULL,
                name        VARCHAR(100)  NOT NULL,
                slug        VARCHAR(120)  NOT NULL,
                description LONGTEXT      DEFAULT NULL,
                color       VARCHAR(255)  DEFAULT NULL,
                created_at  DATETIME(6)   NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_BLOG_CAT_SLUG (slug),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE blog_tag (
                id   INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(80)  NOT NULL,
                slug VARCHAR(100) NOT NULL,
                UNIQUE INDEX UNIQ_BLOG_TAG_SLUG (slug),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE blog_post (
                id               INT AUTO_INCREMENT NOT NULL,
                category_id      INT               DEFAULT NULL,
                title            VARCHAR(200)  NOT NULL,
                slug             VARCHAR(220)  NOT NULL,
                excerpt          LONGTEXT      DEFAULT NULL,
                content          LONGTEXT      NOT NULL,
                thumbnail        VARCHAR(255)  DEFAULT NULL,
                meta_title       VARCHAR(200)  DEFAULT NULL,
                meta_description VARCHAR(300)  DEFAULT NULL,
                status           VARCHAR(20)   NOT NULL DEFAULT 'draft',
                views            INT           NOT NULL DEFAULT 0,
                published_at     DATETIME(6)   DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at       DATETIME(6)   NOT NULL   COMMENT '(DC2Type:datetime_immutable)',
                updated_at       DATETIME(6)   NOT NULL   COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_BLOG_POST_SLUG (slug),
                INDEX IDX_BLOG_POST_CATEGORY (category_id),
                INDEX IDX_BLOG_POST_STATUS_DATE (status, published_at),
                CONSTRAINT FK_BLOG_POST_CATEGORY FOREIGN KEY (category_id)
                    REFERENCES blog_category (id) ON DELETE SET NULL,
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE blog_post_tag (
                blog_post_id INT NOT NULL,
                blog_tag_id  INT NOT NULL,
                INDEX IDX_BLOG_POST_TAG_POST (blog_post_id),
                INDEX IDX_BLOG_POST_TAG_TAG  (blog_tag_id),
                CONSTRAINT FK_BPT_POST FOREIGN KEY (blog_post_id) REFERENCES blog_post (id) ON DELETE CASCADE,
                CONSTRAINT FK_BPT_TAG  FOREIGN KEY (blog_tag_id)  REFERENCES blog_tag  (id) ON DELETE CASCADE,
                PRIMARY KEY (blog_post_id, blog_tag_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS blog_post_tag');
        $this->addSql('DROP TABLE IF EXISTS blog_post');
        $this->addSql('DROP TABLE IF EXISTS blog_tag');
        $this->addSql('DROP TABLE IF EXISTS blog_category');
    }
}
