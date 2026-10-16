<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Onboarding: user.chess_experience (answer to "Do you play chess?")';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD chess_experience VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP chess_experience');
    }
}
