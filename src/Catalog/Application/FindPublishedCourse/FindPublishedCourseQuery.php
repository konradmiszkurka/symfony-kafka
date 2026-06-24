<?php

declare(strict_types=1);

namespace App\Catalog\Application\FindPublishedCourse;

use Symfony\Component\Uid\Uuid;

final readonly class FindPublishedCourseQuery
{
    public function __construct(public Uuid $courseId)
    {
    }
}
