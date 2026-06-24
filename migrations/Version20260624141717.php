<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260624141717 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tworzy tabele Catalog (courses, sections, lessons).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE course_lessons (id BINARY(16) NOT NULL, title VARCHAR(255) NOT NULL, content LONGTEXT NOT NULL, position INT NOT NULL, section_id BINARY(16) NOT NULL, INDEX IDX_37811D35D823E37A (section_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE course_sections (id BINARY(16) NOT NULL, title VARCHAR(255) NOT NULL, position INT NOT NULL, course_id BINARY(16) NOT NULL, INDEX IDX_82222ECE591CC992 (course_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE courses (id BINARY(16) NOT NULL, instructor_id BINARY(16) NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT NOT NULL, status VARCHAR(255) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE course_lessons ADD CONSTRAINT FK_37811D35D823E37A FOREIGN KEY (section_id) REFERENCES course_sections (id)');
        $this->addSql('ALTER TABLE course_sections ADD CONSTRAINT FK_82222ECE591CC992 FOREIGN KEY (course_id) REFERENCES courses (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE course_lessons DROP FOREIGN KEY FK_37811D35D823E37A');
        $this->addSql('ALTER TABLE course_sections DROP FOREIGN KEY FK_82222ECE591CC992');
        $this->addSql('DROP TABLE course_lessons');
        $this->addSql('DROP TABLE course_sections');
        $this->addSql('DROP TABLE courses');
    }
}
