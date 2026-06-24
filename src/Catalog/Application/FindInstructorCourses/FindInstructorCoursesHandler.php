<?php

declare(strict_types=1);

namespace App\Catalog\Application\FindInstructorCourses;

use App\Catalog\Domain\CourseRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class FindInstructorCoursesHandler
{
    public function __construct(private CourseRepository $courses)
    {
    }

    /** @return list<\App\Catalog\Domain\Course> */
    public function __invoke(FindInstructorCoursesQuery $query): array
    {
        return $this->courses->ofInstructor($query->instructorId);
    }
}
