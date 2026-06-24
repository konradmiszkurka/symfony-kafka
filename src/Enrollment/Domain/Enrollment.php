<?php

declare(strict_types=1);

namespace App\Enrollment\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'enrollments')]
#[ORM\UniqueConstraint(name: 'uniq_user_course', columns: ['user_id', 'course_id'])]
class Enrollment
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
    private \DateTimeImmutable $enrolledAt;

    private function __construct(Uuid $id, Uuid $userId, Uuid $courseId, \DateTimeImmutable $enrolledAt)
    {
        $this->id = $id;
        $this->userId = $userId;
        $this->courseId = $courseId;
        $this->enrolledAt = $enrolledAt;
    }

    public static function enroll(Uuid $id, Uuid $userId, Uuid $courseId, \DateTimeImmutable $at): self
    {
        return new self($id, $userId, $courseId, $at);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getCourseId(): Uuid
    {
        return $this->courseId;
    }

    public function getEnrolledAt(): \DateTimeImmutable
    {
        return $this->enrolledAt;
    }
}
