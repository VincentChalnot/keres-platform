<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260724120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user_preferences table for player settings (profile, notification and privacy toggles)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_preferences (
            id UUID NOT NULL,
            first_name VARCHAR(255) DEFAULT NULL,
            last_name VARCHAR(255) DEFAULT NULL,
            locale VARCHAR(8) DEFAULT NULL,
            country VARCHAR(2) DEFAULT NULL,
            newsletter_opt_in BOOLEAN NOT NULL,
            show_board_coordinates BOOLEAN NOT NULL,
            show_opponent_threats_on_hover BOOLEAN NOT NULL,
            allow_contact_by_email BOOLEAN NOT NULL,
            searchable_by_other_users BOOLEAN NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            user_id UUID NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_402A6F60A76ED395 ON user_preferences (user_id)');
        $this->addSql('ALTER TABLE user_preferences ADD CONSTRAINT FK_402A6F60A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_preferences');
    }
}
