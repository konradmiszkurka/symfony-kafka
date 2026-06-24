<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

use Symfony\Component\Uid\Uuid;

interface CourseRepository
{
    public function save(Course $course): void;

    public function ofId(Uuid $id): ?Course;

    /** @return list<Course> */
    public function allPublished(): array;

    /** @return list<Course> */
    public function ofInstructor(Uuid $instructorId): array;
}
