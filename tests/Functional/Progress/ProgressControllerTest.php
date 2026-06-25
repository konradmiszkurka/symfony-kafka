<?php

declare(strict_types=1);

namespace App\Tests\Functional\Progress;

use App\Catalog\Application\AddLesson\AddLessonCommand;
use App\Catalog\Application\AddSection\AddSectionCommand;
use App\Catalog\Application\CreateCourse\CreateCourseCommand;
use App\Catalog\Application\PublishCourse\PublishCourseCommand;
use App\Enrollment\Domain\Event\UserEnrolled;
use App\Identity\Application\RegisterUser\RegisterUserCommand;
use App\Identity\Domain\Role;
use App\Identity\Domain\UserRepository;
use App\Progress\Application\InitProgress\InitProgressOnUserEnrolledHandler;
use App\Progress\Domain\ProgressRepository;
use App\Shared\Application\Bus\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class ProgressControllerTest extends WebTestCase
{
    public function testStudentMarksLessonCompleted(): void
    {
        $client = static::createClient();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $courseId = Uuid::v4();
        $sectionId = Uuid::v4();
        $instructor = Uuid::v4();
        $bus->dispatch(new CreateCourseCommand($courseId, $instructor, 'Course', 'Description'));
        $bus->dispatch(new AddSectionCommand($courseId, $sectionId, $instructor, 'Section'));
        $bus->dispatch(new AddLessonCommand($courseId, $sectionId, Uuid::v4(), $instructor, 'L1', 'content'));
        $bus->dispatch(new PublishCourseCommand($courseId, $instructor));

        $bus->dispatch(new RegisterUserCommand('student@example.com', 'secret123', Role::Student));
        $user = self::getContainer()->get(UserRepository::class)->ofEmail('student@example.com');
        // initialise progress (normally done by the Kafka consumer)
        (self::getContainer()->get(InitProgressOnUserEnrolledHandler::class))(
            new UserEnrolled((string) $user->getId(), (string) $courseId, '2026-06-24T10:00:00+00:00')
        );
        $client->loginUser($user);

        $client->request('GET', '/courses/'.$courseId);
        $client->submitForm('Complete');

        self::assertResponseRedirects('/courses/'.$courseId);
        $progress = self::getContainer()->get(ProgressRepository::class)->ofUserAndCourse($user->getId(), $courseId);
        self::assertNotNull($progress);
        self::assertTrue($progress->isCompleted());
    }
}
