<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P2: time control, clock, offers on game; messenger_messages composite poll index';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE game ALTER COLUMN created_at   TYPE TIMESTAMP(0) WITH TIME ZONE USING created_at   AT TIME ZONE 'UTC'");
        $this->addSql("ALTER TABLE game ALTER COLUMN game_over_at TYPE TIMESTAMP(0) WITH TIME ZONE USING game_over_at AT TIME ZONE 'UTC'");
        $this->addSql("ALTER TABLE game ALTER COLUMN deleted_at   TYPE TIMESTAMP(0) WITH TIME ZONE USING deleted_at   AT TIME ZONE 'UTC'");

        $this->addSql('ALTER TABLE game ADD time_control_kind SMALLINT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE game ADD initial_seconds INT DEFAULT NULL');
        $this->addSql('ALTER TABLE game ADD increment_seconds INT DEFAULT NULL');
        $this->addSql('ALTER TABLE game ADD days_per_move INT DEFAULT NULL');
        $this->addSql('ALTER TABLE game ADD speed_category_value SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE game ADD rated BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE game ADD end_reason_value SMALLINT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE game ADD started_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE game ADD clock_turn_started_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE game ADD move_deadline_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE game ADD draw_offered_by_color_value SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE game ADD rematch_offered_by_color_value SMALLINT DEFAULT NULL');

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_game_move_deadline ON game (move_deadline_at)
                WHERE move_deadline_at IS NOT NULL AND game_over_at IS NULL
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE game ADD CONSTRAINT chk_game_rated_needs_clock
                CHECK (rated = false OR time_control_kind <> 0)
            SQL);

        $this->write('Backfilling started_at and end_reason for existing games...');

        $this->addSql(<<<'SQL'
            UPDATE game SET started_at = created_at
             WHERE EXISTS (SELECT 1 FROM game_move gm WHERE gm.game_id = game.id)
            SQL);
        // Knowingly approximate: ResignGameAction leaves no marker behind, so
        // every historical finished game is indistinguishable from an engine
        // termination without replaying it through the Rust engine
        // (01-domain-model.md sec 6.4).
        $this->addSql('UPDATE game SET end_reason_value = 1 WHERE game_over_at IS NOT NULL'); // ENGINE

        // 03-time-control.md sec 9.2: the transport's own schema builder
        // wants this composite index; the hand-written migration that
        // created messenger_messages only has three single-column ones.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_messenger_messages_poll
                ON messenger_messages (queue_name, available_at, delivered_at, id)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_messenger_messages_poll');

        $this->addSql('ALTER TABLE game DROP CONSTRAINT chk_game_rated_needs_clock');
        $this->addSql('DROP INDEX idx_game_move_deadline');

        $this->addSql('ALTER TABLE game DROP COLUMN time_control_kind');
        $this->addSql('ALTER TABLE game DROP COLUMN initial_seconds');
        $this->addSql('ALTER TABLE game DROP COLUMN increment_seconds');
        $this->addSql('ALTER TABLE game DROP COLUMN days_per_move');
        $this->addSql('ALTER TABLE game DROP COLUMN speed_category_value');
        $this->addSql('ALTER TABLE game DROP COLUMN rated');
        $this->addSql('ALTER TABLE game DROP COLUMN end_reason_value');
        $this->addSql('ALTER TABLE game DROP COLUMN started_at');
        $this->addSql('ALTER TABLE game DROP COLUMN clock_turn_started_at');
        $this->addSql('ALTER TABLE game DROP COLUMN move_deadline_at');
        $this->addSql('ALTER TABLE game DROP COLUMN draw_offered_by_color_value');
        $this->addSql('ALTER TABLE game DROP COLUMN rematch_offered_by_color_value');

        $this->addSql("ALTER TABLE game ALTER COLUMN created_at   TYPE TIMESTAMP(0) WITHOUT TIME ZONE USING created_at   AT TIME ZONE 'UTC'");
        $this->addSql("ALTER TABLE game ALTER COLUMN game_over_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE USING game_over_at AT TIME ZONE 'UTC'");
        $this->addSql("ALTER TABLE game ALTER COLUMN deleted_at   TYPE TIMESTAMP(0) WITHOUT TIME ZONE USING deleted_at   AT TIME ZONE 'UTC'");
    }
}
