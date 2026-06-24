<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260624153544 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create enrollments table with unique constraint on (user_id, course_id)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE enrollments (id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, course_id BINARY(16) NOT NULL, enrolled_at DATETIME NOT NULL, UNIQUE INDEX uniq_user_course (user_id, course_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE enrollments');
    }
}
