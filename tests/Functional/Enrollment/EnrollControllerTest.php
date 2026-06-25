<?php

declare(strict_types=1);

namespace App\Tests\Functional\Enrollment;

use App\Catalog\Application\AddLesson\AddLessonCommand;
use App\Catalog\Application\AddSection\AddSectionCommand;
use App\Catalog\Application\CreateCourse\CreateCourseCommand;
use App\Catalog\Application\PublishCourse\PublishCourseCommand;
use App\Identity\Application\RegisterUser\RegisterUserCommand;
use App\Identity\Domain\Role;
use App\Identity\Domain\UserRepository;
use App\Shared\Application\Bus\CommandBusInterface;
use App\Shared\Infrastructure\Outbox\OutboxRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class EnrollControllerTest extends WebTestCase
{
    private function publishCourse(CommandBusInterface $bus): Uuid
    {
        $courseId = Uuid::v4();
        $sectionId = Uuid::v4();
        $instructor = Uuid::v4();
        $bus->dispatch(new CreateCourseCommand($courseId, $instructor, 'Course', 'Description'));
        $bus->dispatch(new AddSectionCommand($courseId, $sectionId, $instructor, 'Section'));
        $bus->dispatch(new AddLessonCommand($courseId, $sectionId, Uuid::v4(), $instructor, 'Lesson', 'content'));
        $bus->dispatch(new PublishCourseCommand($courseId, $instructor));

        return $courseId;
    }

    public function testAnonymousCannotEnroll(): void
    {
        $client = static::createClient();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $courseId = $this->publishCourse($bus);

        $client->request('POST', '/courses/'.$courseId.'/enroll');

        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('/login', $client->getResponse()->headers->get('Location'));
    }

    public function testStudentCanEnrollAndEventIsSent(): void
    {
        $client = static::createClient();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $courseId = $this->publishCourse($bus);

        $bus->dispatch(new RegisterUserCommand('student@example.com', 'secret123', Role::Student));
        $user = self::getContainer()->get(UserRepository::class)->ofEmail('student@example.com');
        $client->loginUser($user);

        $client->request('GET', '/courses/'.$courseId);
        $client->submitForm('Enroll');
        self::assertResponseRedirects('/courses/'.$courseId, 302);

        $unsent = self::getContainer()->get(OutboxRepository::class)->unsent(100);
        $types = array_map(static fn ($m) => $m->getMessageType(), $unsent);
        self::assertContains(\App\Enrollment\Domain\Event\UserEnrolled::class, $types);
    }
}
