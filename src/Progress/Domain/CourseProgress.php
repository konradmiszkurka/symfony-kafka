<?php

declare(strict_types=1);

namespace App\Progress\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'course_progress')]
#[ORM\UniqueConstraint(name: 'uniq_progress_user_course', columns: ['user_id', 'course_id'])]
class CourseProgress
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $courseId;

    #[ORM\Column]
    private int $totalLessons;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $completedLessonIds;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt;

    private function __construct(Uuid $id, Uuid $userId, Uuid $courseId, int $totalLessons)
    {
        $this->id = $id;
        $this->userId = $userId;
        $this->courseId = $courseId;
        $this->totalLessons = $totalLessons;
        $this->completedLessonIds = [];
        $this->completedAt = null;
    }

    public static function start(Uuid $id, Uuid $userId, Uuid $courseId, int $totalLessons): self
    {
        return new self($id, $userId, $courseId, $totalLessons);
    }

    public function markLessonCompleted(Uuid $lessonId): bool
    {
        $key = (string) $lessonId;
        if (\in_array($key, $this->completedLessonIds, true)) {
            return false;
        }

        $this->completedLessonIds[] = $key;
        if ($this->isCompleted() && null === $this->completedAt) {
            $this->completedAt = new \DateTimeImmutable();
        }

        return true;
    }

    public function isCompleted(): bool
    {
        return $this->totalLessons > 0 && $this->completedCount() >= $this->totalLessons;
    }

    public function completionPercentage(): int
    {
        if (0 === $this->totalLessons) {
            return 0;
        }

        return (int) floor($this->completedCount() / $this->totalLessons * 100);
    }

    public function completedCount(): int
    {
        return \count($this->completedLessonIds);
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getCourseId(): Uuid
    {
        return $this->courseId;
    }

    public function getTotalLessons(): int
    {
        return $this->totalLessons;
    }
}
