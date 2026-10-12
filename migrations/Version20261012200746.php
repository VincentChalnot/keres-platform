<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012200746 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User: accepted version of the terms of use and onboarding date';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD terms_accepted_version VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD terms_accepted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD onboarded_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP terms_accepted_version');
        $this->addSql('ALTER TABLE "user" DROP terms_accepted_at');
        $this->addSql('ALTER TABLE "user" DROP onboarded_at');
    }
}
