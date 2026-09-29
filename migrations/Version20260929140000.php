<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T9: notification emails. `game.last_your_turn_email_at` backs the
 * YOUR_TURN email's one-per-game-per-hour rate limit
 * (`NotificationMailer::sendYourTurn()`).
 */
final class Version20260929140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'T9 notification emails: game.last_your_turn_email_at rate-limit column.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game ADD last_your_turn_email_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game DROP COLUMN last_your_turn_email_at');
    }
}
