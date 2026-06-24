<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260624170427 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create course_progress table with unique constraint on (user_id, course_id)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE course_progress (id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, course_id BINARY(16) NOT NULL, total_lessons INT NOT NULL, completed_lesson_ids JSON NOT NULL, completed_at DATETIME DEFAULT NULL, UNIQUE INDEX uniq_progress_user_course (user_id, course_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE course_progress');
    }
}
