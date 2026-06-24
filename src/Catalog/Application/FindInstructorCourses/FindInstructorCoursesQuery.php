<?php

declare(strict_types=1);

namespace App\Catalog\Application\FindInstructorCourses;

use Symfony\Component\Uid\Uuid;

final readonly class FindInstructorCoursesQuery
{
    public function __construct(public Uuid $instructorId)
    {
    }
}
