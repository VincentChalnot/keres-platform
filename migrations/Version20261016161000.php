<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016161000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Coach: user_preferences.coach (NULL = follow the answer to "Do you play chess?")';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_preferences ADD coach BOOLEAN DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_preferences DROP coach');
    }
}
