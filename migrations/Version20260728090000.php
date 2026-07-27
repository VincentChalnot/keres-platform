<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P0.1: Add user.username, username_changed_at, last_seen_at, notification_preferences';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD username VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD username_changed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD last_seen_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD notification_preferences JSON NOT NULL DEFAULT \'{}\'');

        $this->addSql(<<<'SQL'
            UPDATE "user" u SET username = c.candidate
            FROM (
              SELECT id,
                     CASE WHEN length(stripped) < 3 THEN 'player' ELSE stripped END AS candidate
              FROM (
                SELECT id,
                       substring(regexp_replace(
                         coalesce(nullif(display_name, ''), split_part(email, '@', 1)),
                         '[^a-zA-Z0-9_-]', '', 'g') from 1 for 32) AS stripped
                FROM "user"
              ) s
            ) c
            WHERE c.id = u.id
        SQL);

        $this->addSql(<<<'SQL'
            DO $$
            DECLARE r RECORD; n INT; cand TEXT;
            BEGIN
              FOR r IN SELECT id, username FROM "user" u1
                        WHERE EXISTS (SELECT 1 FROM "user" u2
                                      WHERE lower(u2.username) = lower(u1.username) AND u2.id < u1.id)
                        ORDER BY id
              LOOP
                n := 2;
                LOOP
                  cand := substring(r.username from 1 for 32 - length(n::text)) || n::text;
                  EXIT WHEN NOT EXISTS (SELECT 1 FROM "user" WHERE lower(username) = lower(cand));
                  n := n + 1;
                END LOOP;
                UPDATE "user" SET username = cand WHERE id = r.id;
              END LOOP;
            END $$
        SQL);

        $this->addSql('ALTER TABLE "user" ALTER COLUMN username SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_username_lower ON "user" (LOWER(username))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_user_username_lower');
        $this->addSql('ALTER TABLE "user" DROP COLUMN username');
        $this->addSql('ALTER TABLE "user" DROP COLUMN username_changed_at');
        $this->addSql('ALTER TABLE "user" DROP COLUMN last_seen_at');
        $this->addSql('ALTER TABLE "user" DROP COLUMN notification_preferences');
    }
}
