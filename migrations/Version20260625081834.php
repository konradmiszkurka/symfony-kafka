<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260625081834 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create outbox table for transactional outbox pattern';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE outbox (id BINARY(16) NOT NULL, message_type VARCHAR(255) NOT NULL, payload LONGTEXT NOT NULL, created_at DATETIME NOT NULL, sent_at DATETIME DEFAULT NULL, INDEX idx_outbox_sent_at (sent_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE outbox');
    }
}
