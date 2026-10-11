<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011153435 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_preferences.piece_animation_speed (instant|fast|slow, how fast pieces slide on the game board; existing users get fast).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_preferences ADD piece_animation_speed VARCHAR(16) DEFAULT \'fast\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_preferences DROP piece_animation_speed');
    }
}
