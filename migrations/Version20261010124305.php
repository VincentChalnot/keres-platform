<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010124305 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_preferences.rotate_opponent_pieces (turn the opponent\'s pieces upside down on the game page).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_preferences ADD rotate_opponent_pieces BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_preferences DROP rotate_opponent_pieces');
    }
}
