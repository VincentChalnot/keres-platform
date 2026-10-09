<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Engine evaluation (level 10) of the position reached by a `move`, from
 * White's point of view, in engine score units (+/-1000 = decided game).
 * Stored on the move edge and not on `board_position` on purpose: a board
 * is shared by every line that reaches it, but the engine's verdict also
 * depends on the line (repetition history, the no-capture counter). NULL =
 * not evaluated yet (see `app:moves:evaluate`).
 *
 * `game.live_evaluation`: the player asked for the live evaluation bar when
 * creating this (always unrated) game.
 */
final class Version20261008100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'move.evaluation and game.live_evaluation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE move ADD evaluation INT DEFAULT NULL');
        $this->addSql('ALTER TABLE game ADD live_evaluation BOOLEAN NOT NULL DEFAULT false');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE move DROP COLUMN evaluation');
        $this->addSql('ALTER TABLE game DROP COLUMN live_evaluation');
    }
}
