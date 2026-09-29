<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T8: correspondence time control gets hour-granularity presets (6h/12h/
 * 24h/48h/72h), not the existing whole-day-only 1/3/7 presets - renamed
 * in place rather than adding a second, competing column. Pre-launch, so a
 * straight rename + unit conversion of any existing rows is safe (no real
 * user data). `game.deadline_warning_sent_at` backs the new correspondence
 * deadline sweep's idempotent "warned once per move" check, compared
 * against the already-existing `clock_turn_started_at` anchor rather than
 * a new "last move at" column.
 */
final class Version20260929120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'T8 correspondence sweep: days_per_move -> hours_per_move, game.deadline_warning_sent_at.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game RENAME COLUMN days_per_move TO hours_per_move');
        $this->addSql('UPDATE game SET hours_per_move = hours_per_move * 24 WHERE hours_per_move IS NOT NULL');
        $this->addSql('ALTER TABLE seek RENAME COLUMN days_per_move TO hours_per_move');
        $this->addSql('UPDATE seek SET hours_per_move = hours_per_move * 24 WHERE hours_per_move IS NOT NULL');
        $this->addSql('ALTER TABLE game ADD deadline_warning_sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game DROP COLUMN deadline_warning_sent_at');
        $this->addSql('UPDATE seek SET hours_per_move = hours_per_move / 24 WHERE hours_per_move IS NOT NULL');
        $this->addSql('ALTER TABLE seek RENAME COLUMN hours_per_move TO days_per_move');
        $this->addSql('UPDATE game SET hours_per_move = hours_per_move / 24 WHERE hours_per_move IS NOT NULL');
        $this->addSql('ALTER TABLE game RENAME COLUMN hours_per_move TO days_per_move');
    }
}
