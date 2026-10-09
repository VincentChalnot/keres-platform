<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `sessions`: storage for Symfony's PdoSessionHandler (framework.session.handler_id),
 * so logins survive container restarts and redeployments instead of living in
 * the php container's filesystem. Column layout is the one PdoSessionHandler
 * expects on PostgreSQL (see its createTable()). The table is not an ORM
 * entity: doctrine.dbal.schema_filter keeps it out of schema:validate and
 * migrations:diff.
 */
final class Version20261009190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'sessions table for PdoSessionHandler.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sessions (sess_id VARCHAR(128) NOT NULL, sess_data BYTEA NOT NULL, sess_lifetime INT NOT NULL, sess_time INT NOT NULL, PRIMARY KEY (sess_id))');
        $this->addSql('CREATE INDEX sessions_sess_lifetime_idx ON sessions (sess_lifetime)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sessions');
    }
}
