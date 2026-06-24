<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'course_sections')]
class Section
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column]
    private int $position;

    #[ORM\ManyToOne(inversedBy: 'sections')]
    #[ORM\JoinColumn(nullable: false)]
    private Course $course;

    /** @var Collection<int, Lesson> */
    #[ORM\OneToMany(targetEntity: Lesson::class, mappedBy: 'section', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lessons;

    public function __construct(Course $course, Uuid $id, string $title, int $position)
    {
        $this->course = $course;
        $this->id = $id;
        $this->title = $title;
        $this->position = $position;
        $this->lessons = new ArrayCollection();
    }

    /**
     * @internal Część agregatu Course — wołaj przez Course::addLessonToSection().
     */
    public function addLesson(Uuid $lessonId, string $title, string $content): void
    {
        $this->lessons->add(new Lesson($this, $lessonId, $title, $content, $this->lessons->count() + 1));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    /** @return list<Lesson> */
    public function getLessons(): array
    {
        return array_values($this->lessons->toArray());
    }

    public function lessonCount(): int
    {
        return $this->lessons->count();
    }
}
