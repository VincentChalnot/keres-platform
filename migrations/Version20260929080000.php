<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T7: physical-edition waitlist, double opt-in. `waitlist_signup` holds
 * unconfirmed/pending signups; a `feedback` row (category `waitlist`,
 * FeedbackCategory::WAITLIST - no new table for that, it's the existing
 * generic Message/Feedback model) is only created once the recipient
 * clicks the confirmation link.
 */
final class Version20260929080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'T7 waitlist double opt-in: the waitlist_signup table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE waitlist_signup (
                id UUID NOT NULL,
                email VARCHAR(255) NOT NULL,
                name VARCHAR(255) DEFAULT NULL,
                note TEXT DEFAULT NULL,
                token_hash VARCHAR(255) NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                confirmed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_waitlist_signup_token_hash ON waitlist_signup (token_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE waitlist_signup');
    }
}
