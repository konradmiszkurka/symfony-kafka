<?php

declare(strict_types=1);

namespace App\Progress\Application\MarkLessonCompleted;

use App\Progress\Application\CourseStructureProvider;
use App\Progress\Domain\Event\CourseCompleted;
use App\Progress\Domain\Event\LessonCompleted;
use App\Progress\Domain\Exception\LessonNotInCourseException;
use App\Progress\Domain\Exception\ProgressNotFoundException;
use App\Progress\Domain\ProgressRepository;
use App\Shared\Application\Bus\EventBusInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class MarkLessonCompletedHandler
{
    public function __construct(
        private ProgressRepository $progress,
        private CourseStructureProvider $courses,
        private EventBusInterface $eventBus,
    ) {
    }

    public function __invoke(MarkLessonCompletedCommand $command): void
    {
        $progress = $this->progress->ofUserAndCourse($command->userId, $command->courseId);
        if (null === $progress) {
            throw ProgressNotFoundException::create();
        }
        if (!\in_array((string) $command->lessonId, $this->courses->lessonIds($command->courseId), true)) {
            throw LessonNotInCourseException::create();
        }

        $newlyCompleted = $progress->markLessonCompleted($command->lessonId);
        $this->progress->save($progress);

        if (!$newlyCompleted) {
            return; // idempotentnie — lekcja już była ukończona, brak eventów
        }

        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $this->eventBus->publish(new LessonCompleted(
            (string) $command->userId, (string) $command->courseId, (string) $command->lessonId, $now
        ));

        if ($progress->isCompleted()) {
            $this->eventBus->publish(new CourseCompleted(
                (string) $command->userId, (string) $command->courseId, $now
            ));
        }
    }
}
