<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Scaleway mailer integration: the mailer_error table, logging every failed
 * outgoing email (Symfony Mailer's FailedMessageEvent, see
 * MailerFailureListener), surfaced read-only in the admin panel
 * (config/admin/MailerError.yaml).
 */
final class Version20260928121013 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scaleway mailer integration: the mailer_error table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE mailer_error (
                id UUID NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                error_class VARCHAR(255) NOT NULL,
                error_message TEXT NOT NULL,
                subject VARCHAR(255) DEFAULT NULL,
                recipients TEXT DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_mailer_error_created_at ON mailer_error (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mailer_error');
    }
}
