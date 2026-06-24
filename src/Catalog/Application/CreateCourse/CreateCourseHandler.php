<?php

declare(strict_types=1);

namespace App\Catalog\Application\CreateCourse;

use App\Catalog\Domain\Course;
use App\Catalog\Domain\CourseRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class CreateCourseHandler
{
    public function __construct(private CourseRepository $courses)
    {
    }

    public function __invoke(CreateCourseCommand $command): void
    {
        $this->courses->save(
            Course::create($command->courseId, $command->instructorId, $command->title, $command->description)
        );
    }
}
