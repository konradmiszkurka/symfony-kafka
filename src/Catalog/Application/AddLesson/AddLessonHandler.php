<?php

declare(strict_types=1);

namespace App\Catalog\Application\AddLesson;

use App\Catalog\Domain\CourseRepository;
use App\Catalog\Domain\Exception\CourseNotFoundException;
use App\Catalog\Domain\Exception\NotCourseOwnerException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class AddLessonHandler
{
    public function __construct(private CourseRepository $courses)
    {
    }

    public function __invoke(AddLessonCommand $command): void
    {
        $course = $this->courses->ofId($command->courseId);
        if (null === $course) {
            throw CourseNotFoundException::withId((string) $command->courseId);
        }
        if (!$course->belongsTo($command->instructorId)) {
            throw NotCourseOwnerException::create();
        }

        $course->addLessonToSection($command->sectionId, $command->lessonId, $command->title, $command->content);
        $this->courses->save($course);
    }
}
