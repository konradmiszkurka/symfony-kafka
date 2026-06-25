<?php

declare(strict_types=1);

namespace App\Tests\Functional\Progress;

use App\Catalog\Application\AddLesson\AddLessonCommand;
use App\Catalog\Application\AddSection\AddSectionCommand;
use App\Catalog\Application\CreateCourse\CreateCourseCommand;
use App\Catalog\Application\FindPublishedCourse\FindPublishedCourseQuery;
use App\Catalog\Application\PublishCourse\PublishCourseCommand;
use App\Enrollment\Domain\Event\UserEnrolled;
use App\Identity\Application\RegisterUser\RegisterUserCommand;
use App\Identity\Domain\Role;
use App\Identity\Domain\UserRepository;
use App\Progress\Application\InitProgress\InitProgressOnUserEnrolledHandler;
use App\Progress\Application\MarkLessonCompleted\MarkLessonCompletedCommand;
use App\Shared\Application\Bus\CommandBusInterface;
use App\Shared\Application\Bus\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class CourseProgressDisplayTest extends WebTestCase
{
    public function testProgressDisplayedOnCourseDetail(): void
    {
        $client = static::createClient();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $queryBus = self::getContainer()->get(QueryBusInterface::class);

        $courseId = Uuid::v4();
        $sectionId = Uuid::v4();
        $lessonId = Uuid::v4();
        $instructor = Uuid::v4();

        $bus->dispatch(new CreateCourseCommand($courseId, $instructor, 'Kurs testowy', 'Opis kursu'));
        $bus->dispatch(new AddSectionCommand($courseId, $sectionId, $instructor, 'Sekcja 1'));
        $bus->dispatch(new AddLessonCommand($courseId, $sectionId, $lessonId, $instructor, 'Lekcja 1', 'tresc'));
        $bus->dispatch(new PublishCourseCommand($courseId, $instructor));

        $bus->dispatch(new RegisterUserCommand('progress-display@example.com', 'secret123', Role::Student));
        $user = self::getContainer()->get(UserRepository::class)->ofEmail('progress-display@example.com');

        (self::getContainer()->get(InitProgressOnUserEnrolledHandler::class))(
            new UserEnrolled((string) $user->getId(), (string) $courseId, '2026-06-24T10:00:00+00:00')
        );

        $client->loginUser($user);

        $client->request('GET', '/courses/' . $courseId);
        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();
        self::assertStringContainsString('0%', $content);
        self::assertStringContainsString('0/1', $content);

        $course = $queryBus->ask(new FindPublishedCourseQuery($courseId));
        $actualLessonId = $course->getSections()[0]->getLessons()[0]->getId();

        $bus->dispatch(new MarkLessonCompletedCommand($user->getId(), $courseId, $actualLessonId));

        $client->request('GET', '/courses/' . $courseId);
        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();
        self::assertStringContainsString('100%', $content);
    }

    public function testAnonymousUserSeesPageWithoutCrash(): void
    {
        $anonymousClient = static::createClient();
        $bus = self::getContainer()->get(CommandBusInterface::class);

        $courseId = Uuid::v4();
        $sectionId = Uuid::v4();
        $lessonId = Uuid::v4();
        $instructor = Uuid::v4();

        $bus->dispatch(new CreateCourseCommand($courseId, $instructor, 'Kurs anonimowy', 'Opis'));
        $bus->dispatch(new AddSectionCommand($courseId, $sectionId, $instructor, 'Sekcja'));
        $bus->dispatch(new AddLessonCommand($courseId, $sectionId, $lessonId, $instructor, 'L1', 't'));
        $bus->dispatch(new PublishCourseCommand($courseId, $instructor));

        $anonymousClient->request('GET', '/courses/' . $courseId);
        self::assertResponseIsSuccessful();
    }
}
