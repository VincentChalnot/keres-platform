<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AI ladder: game.undo_used, user.highest_ai_level_beaten (backfilled from the finished AI games)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game ADD undo_used BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD highest_ai_level_beaten SMALLINT DEFAULT NULL');

        // Older games carry no undo information: every finished, won AI game counts (draws and aborted games never).
        $this->addSql(<<<'SQL'
            UPDATE "user" u SET highest_ai_level_beaten = s.level
              FROM (
                SELECT gp.user_id, MAX(g.ai_level) AS level
                  FROM game g
                  JOIN game_player gp ON gp.game_id = g.id
                 WHERE g.opponent_type_value = 0
                   AND g.ai_level IS NOT NULL
                   AND g.game_over_at IS NOT NULL
                   AND g.deleted_at IS NULL
                   AND g."draw" = false
                   AND g.end_reason_value <> 6
                   AND gp.user_id IS NOT NULL
                   AND ((gp.color_value = 0 AND g.white_wins) OR (gp.color_value = 1 AND NOT g.white_wins))
                 GROUP BY gp.user_id
              ) s
             WHERE u.id = s.user_id
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game DROP undo_used');
        $this->addSql('ALTER TABLE "user" DROP highest_ai_level_beaten');
    }
}
