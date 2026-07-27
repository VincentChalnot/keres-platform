<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20260728091000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P0.2: Purge ownerless pre-2026-03-27 games (owner_id IS NULL)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DELETE FROM game WHERE owner_id IS NULL');
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Ownerless games are deleted; restore from backup.');
    }
}
