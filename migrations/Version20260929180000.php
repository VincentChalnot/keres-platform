<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T11: "Invite a friend". `seek.invite_only` marks a seek created via the
 * new `/lobby/invites` endpoint - excluded from the public lobby listing
 * and from the anonymous pairing pool scan, matchable only via the exact
 * `/invite/{uuid}` accept-by-uuid path. No separate token column: the
 * seek's own unguessable v4 `uuid` doubles as the shareable link's token
 * (see `Seek`'s docblock / DECISIONS.md for why this differs from T7's
 * hashed-token pattern).
 */
final class Version20260929180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'T11 invite a friend: seek.invite_only.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE seek ADD invite_only BOOLEAN NOT NULL DEFAULT false');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE seek DROP COLUMN invite_only');
    }
}
