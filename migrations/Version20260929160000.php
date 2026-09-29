<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T10: AI difficulty level piping. `game.ai_level` (1-10, engine's
 * `/engine-move-game/:level` scale) is null for HOTSEAT/MULTIPLAYER games -
 * range enforced at the form layer (`LocalGameType`'s `ChoiceType`), same
 * convention as the other range-limited fields in this schema
 * (`hours_per_move`, `initial_seconds`) which have no DB CHECK constraint
 * either.
 */
final class Version20260929160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'T10 AI level piping: game.ai_level.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game ADD ai_level SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game DROP COLUMN ai_level');
    }
}
