<?php

declare(strict_types=1);

namespace App\Enrollment\Infrastructure\Catalog;

use App\Catalog\Application\FindPublishedCourse\FindPublishedCourseQuery;
use App\Catalog\Domain\Course;
use App\Enrollment\Application\CourseAvailabilityChecker;
use App\Shared\Application\Bus\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class CatalogCourseAvailabilityChecker implements CourseAvailabilityChecker
{
    public function __construct(private QueryBusInterface $queryBus)
    {
    }

    public function isEnrollable(Uuid $courseId): bool
    {
        return $this->queryBus->ask(new FindPublishedCourseQuery($courseId)) instanceof Course;
    }
}
