<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260722201821 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add feedback.reviewed and user password/reset-token columns for the admin dashboard';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feedback ADD reviewed BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE "user" ADD password VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD reset_token VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD reset_token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feedback DROP reviewed');
        $this->addSql('ALTER TABLE "user" DROP password');
        $this->addSql('ALTER TABLE "user" DROP reset_token');
        $this->addSql('ALTER TABLE "user" DROP reset_token_expires_at');
    }
}
