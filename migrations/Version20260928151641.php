<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The marketing site's /api/contact form now stores its submissions as
 * Feedback rows (category CONTACT, see App\Model\FeedbackCategory) instead
 * of sending an email. Those submissions have no Keres account behind them,
 * so feedback.user_id must become nullable.
 */
final class Version20260928151641 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make feedback.user_id nullable for anonymous contact-form submissions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feedback ALTER COLUMN user_id DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feedback ALTER COLUMN user_id SET NOT NULL');
    }
}
