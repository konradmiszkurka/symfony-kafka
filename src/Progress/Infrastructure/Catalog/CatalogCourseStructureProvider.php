<?php

declare(strict_types=1);

namespace App\Progress\Infrastructure\Catalog;

use App\Catalog\Application\FindPublishedCourse\FindPublishedCourseQuery;
use App\Catalog\Domain\Course;
use App\Progress\Application\CourseStructureProvider;
use App\Shared\Application\Bus\QueryBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class CatalogCourseStructureProvider implements CourseStructureProvider
{
    public function __construct(private QueryBusInterface $queryBus)
    {
    }

    public function lessonIds(Uuid $courseId): array
    {
        $course = $this->queryBus->ask(new FindPublishedCourseQuery($courseId));
        if (!$course instanceof Course) {
            return [];
        }

        $ids = [];
        foreach ($course->getSections() as $section) {
            foreach ($section->getLessons() as $lesson) {
                $ids[] = (string) $lesson->getId();
            }
        }

        return $ids;
    }
}
