<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `game.engine_end_code`: the engine's own code for *why* it ended a game
 * (`POST /game-over-reason`), opaque to PHP. Written once, when the game
 * ends with `end_reason_value = 1` (ENGINE); NULL for every other ending and
 * for engine endings that predate this column (`app:games:backfill-engine-end-code`).
 */
final class Version20261009170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'game.engine_end_code.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game ADD engine_end_code SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game DROP COLUMN engine_end_code');
    }
}
