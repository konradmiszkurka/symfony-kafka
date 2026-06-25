<?php

declare(strict_types=1);

namespace App\Tests\Unit\Progress\Domain;

use App\Progress\Domain\CourseProgress;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CourseProgressTest extends TestCase
{
    public function testMarkingLessonsTracksPercentageAndCompletion(): void
    {
        $progress = CourseProgress::start(Uuid::v4(), Uuid::v4(), Uuid::v4(), 2);
        self::assertSame(0, $progress->completionPercentage());
        self::assertFalse($progress->isCompleted());

        $l1 = Uuid::v4();
        self::assertTrue($progress->markLessonCompleted($l1));
        self::assertSame(50, $progress->completionPercentage());
        // idempotency: the same lesson does not count a second time
        self::assertFalse($progress->markLessonCompleted($l1));
        self::assertSame(50, $progress->completionPercentage());

        self::assertTrue($progress->markLessonCompleted(Uuid::v4()));
        self::assertSame(100, $progress->completionPercentage());
        self::assertTrue($progress->isCompleted());
    }
}
