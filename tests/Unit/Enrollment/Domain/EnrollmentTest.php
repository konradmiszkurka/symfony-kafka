<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enrollment\Domain;

use App\Enrollment\Domain\Enrollment;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class EnrollmentTest extends TestCase
{
    public function testEnrollCapturesUserCourseAndTime(): void
    {
        $userId = Uuid::v4();
        $courseId = Uuid::v4();
        $at = new \DateTimeImmutable('2026-06-24T10:00:00+00:00');

        $enrollment = Enrollment::enroll(Uuid::v4(), $userId, $courseId, $at);

        self::assertTrue($enrollment->getUserId()->equals($userId));
        self::assertTrue($enrollment->getCourseId()->equals($courseId));
        self::assertSame($at, $enrollment->getEnrolledAt());
    }
}
