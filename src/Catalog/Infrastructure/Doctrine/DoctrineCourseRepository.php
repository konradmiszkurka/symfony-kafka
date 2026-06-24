<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine;

use App\Catalog\Domain\Course;
use App\Catalog\Domain\CourseRepository;
use App\Catalog\Domain\CourseStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineCourseRepository implements CourseRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function save(Course $course): void
    {
        $this->em->persist($course);
        $this->em->flush();
    }

    public function ofId(Uuid $id): ?Course
    {
        return $this->em->find(Course::class, $id);
    }

    public function allPublished(): array
    {
        return array_values(
            $this->em->getRepository(Course::class)->findBy(['status' => CourseStatus::Published], ['title' => 'ASC'])
        );
    }

    public function ofInstructor(Uuid $instructorId): array
    {
        return array_values(
            $this->em->getRepository(Course::class)->findBy(['instructorId' => $instructorId], ['title' => 'ASC'])
        );
    }
}
