<?php

declare(strict_types=1);

namespace App\Catalog\Application\PublishCourse;

use App\Catalog\Domain\CourseRepository;
use App\Catalog\Domain\Exception\CourseNotFoundException;
use App\Catalog\Domain\Exception\NotCourseOwnerException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class PublishCourseHandler
{
    public function __construct(private CourseRepository $courses)
    {
    }

    public function __invoke(PublishCourseCommand $command): void
    {
        $course = $this->courses->ofId($command->courseId);
        if (null === $course) {
            throw CourseNotFoundException::withId((string) $command->courseId);
        }
        if (!$course->belongsTo($command->instructorId)) {
            throw NotCourseOwnerException::create();
        }

        $course->publish();
        $this->courses->save($course);
    }
}
