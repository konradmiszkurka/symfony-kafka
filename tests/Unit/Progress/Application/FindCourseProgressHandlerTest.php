<?php

declare(strict_types=1);

namespace App\Tests\Unit\Progress\Application;

use App\Progress\Application\FindCourseProgress\CourseProgressView;
use App\Progress\Application\FindCourseProgress\FindCourseProgressHandler;
use App\Progress\Application\FindCourseProgress\FindCourseProgressQuery;
use App\Progress\Domain\CourseProgress;
use App\Progress\Domain\ProgressRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class FindCourseProgressHandlerTest extends TestCase
{
    private function makeRepo(?CourseProgress $returnValue): ProgressRepository
    {
        return new class($returnValue) implements ProgressRepository {
            public function __construct(private readonly ?CourseProgress $progress) {}

            public function save(CourseProgress $progress): void {}

            public function ofUserAndCourse(Uuid $userId, Uuid $courseId): ?CourseProgress
            {
                return $this->progress;
            }

            public function exists(Uuid $userId, Uuid $courseId): bool
            {
                return false;
            }
        };
    }

    public function testReturnsCourseProgressViewWhenProgressExists(): void
    {
        $progress = CourseProgress::start(Uuid::v4(), Uuid::v4(), Uuid::v4(), 2);
        $progress->markLessonCompleted(Uuid::v4());

        $handler = new FindCourseProgressHandler($this->makeRepo($progress));
        $query = new FindCourseProgressQuery(Uuid::v4(), Uuid::v4());

        $result = $handler($query);

        self::assertInstanceOf(CourseProgressView::class, $result);
        self::assertSame(50, $result->percentage);
        self::assertSame(1, $result->completedLessons);
        self::assertSame(2, $result->totalLessons);
        self::assertFalse($result->completed);
    }

    public function testReturnsNullWhenNoProgressFound(): void
    {
        $handler = new FindCourseProgressHandler($this->makeRepo(null));
        $query = new FindCourseProgressQuery(Uuid::v4(), Uuid::v4());

        $result = $handler($query);

        self::assertNull($result);
    }
}
