<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `user.locale`: the interface language (one of `framework.enabled_locales`),
 * NULL until the user chooses one or is created from a request that carried a
 * language (cookie / Accept-Language).
 *
 * It replaces `user_preferences.locale`, a free-form "Language" setting that
 * nothing read: values that name a supported language are carried over, the
 * rest (any other language) are dropped with the column.
 */
final class Version20261009200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user.locale (interface language); drops the unused user_preferences.locale.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD locale VARCHAR(8) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE "user" SET locale = lower(split_part(p.locale, '_', 1))
            FROM user_preferences p
            WHERE p.user_id = "user".id AND lower(split_part(p.locale, '_', 1)) IN ('en', 'fr')
            SQL);
        $this->addSql('ALTER TABLE user_preferences DROP COLUMN locale');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_preferences ADD locale VARCHAR(8) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE user_preferences SET locale = u.locale
            FROM "user" u
            WHERE user_preferences.user_id = u.id AND u.locale IS NOT NULL
            SQL);
        $this->addSql('ALTER TABLE "user" DROP COLUMN locale');
    }
}
