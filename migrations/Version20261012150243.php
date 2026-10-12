<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012150243 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rematches: game.parent_game_id (unique: one rematch per game) and seek.rematch_of_game_id.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game ADD parent_game_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE game ADD CONSTRAINT FK_232B318C96C7AD43 FOREIGN KEY (parent_game_id) REFERENCES game (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE UNIQUE INDEX uniq_game_parent ON game (parent_game_id)');
        $this->addSql('ALTER TABLE seek ADD rematch_of_game_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE seek ADD CONSTRAINT FK_D438FE9798B023C9 FOREIGN KEY (rematch_of_game_id) REFERENCES game (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_D438FE9798B023C9 ON seek (rematch_of_game_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE seek DROP rematch_of_game_id');
        $this->addSql('ALTER TABLE game DROP parent_game_id');
    }
}
