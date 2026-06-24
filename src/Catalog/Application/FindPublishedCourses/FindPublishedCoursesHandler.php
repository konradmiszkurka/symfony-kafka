<?php

declare(strict_types=1);

namespace App\Catalog\Application\FindPublishedCourses;

use App\Catalog\Domain\CourseRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class FindPublishedCoursesHandler
{
    public function __construct(private CourseRepository $courses)
    {
    }

    /** @return list<\App\Catalog\Domain\Course> */
    public function __invoke(FindPublishedCoursesQuery $query): array
    {
        return $this->courses->allPublished();
    }
}
