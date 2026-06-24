<?php

declare(strict_types=1);

namespace App\Enrollment\Infrastructure\Doctrine;

use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineEnrollmentRepository implements EnrollmentRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function save(Enrollment $enrollment): void
    {
        $this->em->persist($enrollment);
        $this->em->flush();
    }

    public function exists(Uuid $userId, Uuid $courseId): bool
    {
        return $this->em->getRepository(Enrollment::class)
            ->count(['userId' => $userId, 'courseId' => $courseId]) > 0;
    }
}
