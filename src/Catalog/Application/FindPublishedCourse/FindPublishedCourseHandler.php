<?php

declare(strict_types=1);

namespace App\Catalog\Application\FindPublishedCourse;

use App\Catalog\Domain\Course;
use App\Catalog\Domain\CourseRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class FindPublishedCourseHandler
{
    public function __construct(private CourseRepository $courses)
    {
    }

    public function __invoke(FindPublishedCourseQuery $query): ?Course
    {
        $course = $this->courses->ofId($query->courseId);

        return $course?->isPublished() ? $course : null;
    }
}
