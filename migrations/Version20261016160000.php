<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Coach: game.undo_used becomes game.assisted (an undo or a hint)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game RENAME COLUMN undo_used TO assisted');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game RENAME COLUMN assisted TO undo_used');
    }
}
