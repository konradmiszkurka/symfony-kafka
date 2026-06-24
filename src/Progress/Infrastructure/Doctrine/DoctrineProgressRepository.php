<?php

declare(strict_types=1);

namespace App\Progress\Infrastructure\Doctrine;

use App\Progress\Domain\CourseProgress;
use App\Progress\Domain\ProgressRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineProgressRepository implements ProgressRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function save(CourseProgress $progress): void
    {
        $this->em->persist($progress);
        $this->em->flush();
    }

    public function ofUserAndCourse(Uuid $userId, Uuid $courseId): ?CourseProgress
    {
        return $this->em->getRepository(CourseProgress::class)
            ->findOneBy(['userId' => $userId, 'courseId' => $courseId]);
    }

    public function exists(Uuid $userId, Uuid $courseId): bool
    {
        return $this->em->getRepository(CourseProgress::class)
            ->count(['userId' => $userId, 'courseId' => $courseId]) > 0;
    }
}
