<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

use App\Catalog\Domain\Exception\CannotPublishCourseWithoutLessonsException;
use App\Catalog\Domain\Exception\SectionNotFoundException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'courses')]
class Course
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $instructorId;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column(enumType: CourseStatus::class)]
    private CourseStatus $status;

    /** @var Collection<int, Section> */
    #[ORM\OneToMany(targetEntity: Section::class, mappedBy: 'course', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $sections;

    private function __construct(Uuid $id, Uuid $instructorId, string $title, string $description)
    {
        $this->id = $id;
        $this->instructorId = $instructorId;
        $this->title = $title;
        $this->description = $description;
        $this->status = CourseStatus::Draft;
        $this->sections = new ArrayCollection();
    }

    public static function create(Uuid $id, Uuid $instructorId, string $title, string $description): self
    {
        return new self($id, $instructorId, $title, $description);
    }

    public function addSection(Uuid $sectionId, string $title): void
    {
        $this->sections->add(new Section($this, $sectionId, $title, $this->sections->count() + 1));
    }

    public function addLessonToSection(Uuid $sectionId, Uuid $lessonId, string $title, string $content): void
    {
        foreach ($this->sections as $section) {
            if ($section->getId()->equals($sectionId)) {
                $section->addLesson($lessonId, $title, $content);

                return;
            }
        }

        throw SectionNotFoundException::withId((string) $sectionId);
    }

    public function publish(): void
    {
        if (0 === $this->totalLessons()) {
            throw CannotPublishCourseWithoutLessonsException::create();
        }

        $this->status = CourseStatus::Published;
    }

    public function isPublished(): bool
    {
        return CourseStatus::Published === $this->status;
    }

    public function belongsTo(Uuid $instructorId): bool
    {
        return $this->instructorId->equals($instructorId);
    }

    public function totalLessons(): int
    {
        $total = 0;
        foreach ($this->sections as $section) {
            $total += $section->lessonCount();
        }

        return $total;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getInstructorId(): Uuid
    {
        return $this->instructorId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getStatus(): CourseStatus
    {
        return $this->status;
    }

    /** @return list<Section> */
    public function getSections(): array
    {
        return array_values($this->sections->toArray());
    }
}
